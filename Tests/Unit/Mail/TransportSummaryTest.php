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

namespace KonradMichalik\Typo3Letterbox\Tests\Unit\Mail;

use KonradMichalik\Typo3Letterbox\Mail\{LetterboxTransport, TransportSummary};
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Transport\NullTransport;

/**
 * TransportSummaryTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class TransportSummaryTest extends TestCase
{
    /**
     * @param array<string, mixed> $mail
     */
    #[Test]
    #[DataProvider('transports')]
    public function namesTheTransportWithoutCredentials(array $mail, string $expected): void
    {
        $summary = TransportSummary::of($mail);

        self::assertSame($expected, $summary);
        self::assertStringNotContainsString('secret', $summary);
        self::assertStringNotContainsString('user', $summary);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function transports(): array
    {
        return [
            'dsn with credentials and query' => [['transport' => 'dsn', 'dsn' => 'smtp://user:secret@mail.example.org:587?verify_peer=0&secret=1'], 'SMTP (mail.example.org:587)'],
            'dsn wins over a class name' => [['transport' => 'Vendor\\Transport', 'dsn' => 'smtps://user:secret@mail.example.org'], 'SMTPS (mail.example.org)'],
            'dsn with a password that breaks parse_url' => [['transport' => 'dsn', 'dsn' => 'smtp://user:sec/ret@mail.example.org:25'], 'SMTP'],
            'dsn with a slash in the user name' => [['transport' => 'dsn', 'dsn' => 'smtp://us/er@mail.example.org'], 'SMTP'],
            'dsn without credentials' => [['transport' => 'dsn', 'dsn' => 'sendmail://default'], 'SENDMAIL (default)'],
            'smtp with server' => [['transport' => 'smtp', 'dsn' => 'smtp://ignored', 'transport_smtp_server' => 'mail.example.org:25', 'transport_smtp_password' => 'secret'], 'SMTP (mail.example.org:25)'],
            'smtp without server' => [['transport' => 'smtp'], 'SMTP'],
            'sendmail ignores the dsn like core' => [['transport' => 'sendmail', 'dsn' => 'smtp://user:secret@mail.example.org'], 'sendmail'],
            'mbox' => [['transport' => 'mbox'], 'mbox'],
            'null' => [['transport' => 'null'], 'null'],
            'null class' => [['transport' => NullTransport::class], 'null'],
            'letterbox' => [['transport' => LetterboxTransport::class], 'Letterbox'],
            'custom class' => [['transport' => 'Vendor\\Mail\\Transport'], 'Vendor\\Mail\\Transport'],
            'nothing' => [[], 'sendmail'],
        ];
    }
}
