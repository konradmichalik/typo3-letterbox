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

namespace KonradMichalik\Typo3Letterbox\Tests\Functional\Command;

use KonradMichalik\Typo3Letterbox\Command\ReceiveCommand;
use KonradMichalik\Typo3Letterbox\Domain\Model\MailFilter;
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ReceiveCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ReceiveCommandTest extends MailboxCommandTestCase
{
    private const MESSAGE = "From: Shop <shop@example.org>\r\nTo: anna@example.org\r\nSubject: Receipt\r\n\r\nThanks\r\n";

    /** @var list<string>|null */
    private ?array $argv = null;

    protected function tearDown(): void
    {
        if (null !== $this->argv) {
            $_SERVER['argv'] = $this->argv;
        }
        parent::tearDown();
    }

    #[Test]
    public function storesMailFromStdinWithEnvelope(): void
    {
        $tester = $this->receive(['-i', '-f', 'bounce@example.org', 'Anna@Example.ORG'], self::MESSAGE);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertSame('', $tester->getDisplay());
        $mbox = (string) file_get_contents($this->path);
        self::assertStringStartsWith('From bounce@example.org ', $mbox);
        self::assertStringContainsString("\nX-Letterbox-Envelope-To: Anna@example.org\nFrom: Shop <shop@example.org>\n", $mbox);
        self::assertStringNotContainsString("\r", $mbox);
        $mail = $this->get(MailRepository::class)->findById($this->firstId(), MailFilter::all());
        self::assertNotNull($mail);
        self::assertSame('Receipt', $mail->subject);
    }

    #[Test]
    public function senderFallsBackToTheFromHeader(): void
    {
        $tester = $this->receive(['anna@example.org'], self::MESSAGE);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringStartsWith('From shop@example.org ', (string) file_get_contents($this->path));
    }

    #[Test]
    public function minusTAddsHeaderRecipientsAndStripsBcc(): void
    {
        $message = "From: shop@example.org\nTo: anna@example.org, Ben <ben@Example.org>\nCc: carl@example.org\nbcc: dora@example.org,\n  eve@example.org\nSubject: Hidden copy\n\nBcc: stays in the body\n";

        $tester = $this->receive(['-t', '-i', 'extra@example.org', 'anna@example.org'], $message);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $mbox = (string) file_get_contents($this->path);
        self::assertStringContainsString('X-Letterbox-Envelope-To: extra@example.org, anna@example.org, ben@example.org, carl@example.org, dora@example.org, eve@example.org', $mbox);
        self::assertDoesNotMatchRegularExpression('/^bcc:/im', strstr($mbox, "\n\n", true) ?: '');
        self::assertStringNotContainsString('eve@example.org,', $mbox);
        self::assertStringContainsString("Subject: Hidden copy\n\nBcc: stays in the body", $mbox);
    }

    #[Test]
    public function noRecipientsExitsNonZero(): void
    {
        $tester = $this->receive(['-i'], self::MESSAGE);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('no recipients', $tester->getDisplay());
        self::assertFileDoesNotExist($this->path);
    }

    #[Test]
    public function minusTWithoutAddressHeadersExitsNonZero(): void
    {
        $tester = $this->receive(['-t'], "From: shop@example.org\nSubject: Nobody\n\nbody\n");

        self::assertSame(1, $tester->getStatusCode());
        self::assertFileDoesNotExist($this->path);
    }

    /**
     * @param list<string> $argv
     */
    #[Test]
    #[DataProvider('invalidInput')]
    public function invalidInputExitsNonZero(array $argv, string $message, string $error): void
    {
        $tester = $this->receive($argv, $message);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString($error, $tester->getDisplay());
        self::assertFileDoesNotExist($this->path);
    }

    /**
     * @return array<string, array{list<string>, string, string}>
     */
    public static function invalidInput(): array
    {
        return [
            'empty message' => [['anna@example.org'], '', 'empty'],
            'blank message' => [['anna@example.org'], "\n\n", 'empty'],
            'recipient with a line break' => [["anna@example.org\nX-Injected: yes"], self::MESSAGE, 'invalid'],
            'sender with a space' => [['-f', 'a b@example.org', 'anna@example.org'], self::MESSAGE, 'invalid'],
        ];
    }

    #[Test]
    public function oversizedMessageExitsNonZero(): void
    {
        $tester = $this->receive(['anna@example.org'], self::MESSAGE.str_repeat('x', ReceiveCommand::MAX_BYTES));

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('larger than', $tester->getDisplay());
        self::assertFileDoesNotExist($this->path);
    }

    #[Test]
    public function unconfiguredMailboxExitsNonZero(): void
    {
        $this->useMailbox('');

        $tester = $this->receive(['anna@example.org'], self::MESSAGE);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString('MAIL/transport_mbox_file is empty', $tester->getDisplay());
    }

    #[Test]
    public function unsetMailboxExitsNonZero(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_mbox_file']);

        $tester = $this->receive(['anna@example.org'], self::MESSAGE);

        self::assertSame(2, $tester->getStatusCode());
    }

    #[Test]
    public function unwritableMailboxExitsNonZeroWithoutWritingAnything(): void
    {
        $this->path = '/nonexistent-letterbox-dir/mails.mbox';
        $this->useMailbox($this->path);

        $tester = $this->receive(['anna@example.org'], self::MESSAGE);

        self::assertSame(2, $tester->getStatusCode());
        self::assertStringContainsString($this->path, $tester->getDisplay());
        self::assertFileDoesNotExist($this->path);
    }

    #[Test]
    public function retentionRunsAfterReceive(): void
    {
        // A limit of 1 has a slack of 1: the third mail crosses it and cuts back to one.
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_letterbox']['maxMessages'] = '1';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_letterbox']['maxAgeDays'] = '0';
        $this->addMail('Old one');
        $this->addMail('Old two');

        $tester = $this->receive(['anna@example.org'], self::MESSAGE);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $entries = $this->get(MailRepository::class)->findEntries(MailFilter::all(), 0, 10);
        self::assertSame(['Receipt'], array_map(static fn ($entry): string => $entry->subject, $entries));
    }

    /**
     * @param list<string> $arguments
     */
    private function receive(array $arguments, string $message): CommandTester
    {
        $this->argv ??= $_SERVER['argv'] ?? [];
        $_SERVER['argv'] = ['/abs/vendor/bin/typo3', 'letterbox:receive', ...$arguments];

        $tester = new CommandTester($this->get(ReceiveCommand::class));
        $tester->setInputs([$message]);
        $tester->execute([]);

        return $tester;
    }
}
