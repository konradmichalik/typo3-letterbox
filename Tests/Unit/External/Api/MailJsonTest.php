<?php

declare(strict_types=1);

/*
 * This file is part of the "typo3_letterbox" TYPO3 CMS extension.
 *
 * (c) 2026 Konrad Michalik <hej@konradmichalik.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KonradMichalik\Typo3Letterbox\Tests\Unit\External\Api;

use DateTimeImmutable;
use KonradMichalik\Typo3Letterbox\Domain\Model\{Address, Attachment, Mail, MailLink};
use KonradMichalik\Typo3Letterbox\External\Api\MailJson;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MailJsonTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MailJsonTest extends TestCase
{
    #[Test]
    public function summaryCarriesAddressesLinksAndIsoDate(): void
    {
        $summary = (new MailJson())->summary($this->mail());

        self::assertSame([
            'id' => '0123456789abcdef0123456789abcdef',
            'messageId' => 'reset-1@example.org',
            'date' => '2026-10-27T10:00:00+01:00',
            'subject' => 'Reset your password',
            'from' => ['address' => 'noreply@example.org', 'name' => 'Example Shop'],
            'to' => [['address' => 'anna@example.org', 'name' => 'Anna Example']],
            'cc' => [['address' => 'team@example.org', 'name' => '']],
            'attachmentCount' => 1,
            'links' => [
                ['url' => 'https://www.example.org/login?tx_felogin_login%5Bhash%5D=abc', 'isPasswordReset' => true],
                ['url' => 'https://www.example.org/imprint', 'isPasswordReset' => false],
            ],
        ], $summary);
    }

    #[Test]
    public function summaryWithoutDateAndSenderUsesNull(): void
    {
        $summary = (new MailJson())->summary(new Mail('id', '', '', null, null, [], [], [], [], '', '', [], [], 0, '', []));

        self::assertNull($summary['date']);
        self::assertNull($summary['from']);
        self::assertSame([], $summary['to']);
    }

    #[Test]
    public function detailAddsBodiesHeadersAndAttachmentMetadataWithoutContent(): void
    {
        $detail = (new MailJson())->detail($this->mail());

        self::assertSame('Hello Anna', $detail['text']);
        self::assertSame('<p>Hello Anna</p>', $detail['html']);
        self::assertSame([['index' => 0, 'filename' => 'terms.pdf', 'mimeType' => 'application/pdf', 'size' => 7, 'contentId' => '']], $detail['attachments']);
        self::assertSame('Reset your password', $detail['subject']);
        self::assertStringContainsString('"headers":{"subject":["Reset your password"]}', json_encode($detail, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        self::assertStringNotContainsString('%PDF-1.', json_encode($detail, \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function emptyHeadersEncodeAsObject(): void
    {
        $detail = (new MailJson())->detail(new Mail('id', '', '', null, null, [], [], [], [], '', '', [], [], 0, '', []));

        self::assertStringContainsString('"headers":{}', json_encode($detail, \JSON_THROW_ON_ERROR));
    }

    private function mail(): Mail
    {
        return new Mail(
            '0123456789abcdef0123456789abcdef',
            'reset-1@example.org',
            'Reset your password',
            new DateTimeImmutable('2026-10-27T10:00:00+01:00'),
            new Address('noreply@example.org', 'Example Shop'),
            [new Address('anna@example.org', 'Anna Example')],
            [new Address('team@example.org')],
            [new Address('hidden@example.org')],
            [],
            'Hello Anna',
            '<p>Hello Anna</p>',
            [new Attachment(0, 'terms.pdf', '', 'application/pdf', 7, '%PDF-1.')],
            ['subject' => ['Reset your password']],
            100,
            'raw source',
            [
                new MailLink('https://www.example.org/login?tx_felogin_login%5Bhash%5D=abc', true),
                new MailLink('https://www.example.org/imprint', false),
            ],
        );
    }
}
