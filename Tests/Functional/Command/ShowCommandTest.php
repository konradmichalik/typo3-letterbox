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

use KonradMichalik\Typo3Letterbox\Command\ShowCommand;
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ShowCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ShowCommandTest extends MailboxCommandTestCase
{
    #[Test]
    public function printsTheTextPartByDefault(): void
    {
        $this->addMail('Hello', 'r@example.org', '2026-10-27 10:00:00', 'Plain <b>body</b> text');

        $tester = $this->tester();
        $tester->execute(['id' => $this->firstId()]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Plain <b>body</b> text', $tester->getDisplay());
    }

    #[Test]
    public function printsTheSource(): void
    {
        $this->addMail('Hello');

        $tester = $this->tester();
        $tester->execute(['id' => $this->firstId(), '--part' => 'source']);

        self::assertStringContainsString('Subject: Hello', $tester->getDisplay());
    }

    #[Test]
    public function printsHeadersAsNameValueLines(): void
    {
        $this->addMail('Hello', 'r@example.org');

        $tester = $this->tester();
        $tester->execute(['id' => $this->firstId(), '--part' => 'headers']);

        self::assertMatchesRegularExpression('/^Subject: Hello$/mi', $tester->getDisplay());
        self::assertMatchesRegularExpression('/^To: r@example\.org$/mi', $tester->getDisplay());
    }

    #[Test]
    public function printsTheHtmlPartEmptyWhenTheMailHasNone(): void
    {
        $this->addMail('Hello');

        $tester = $this->tester();
        $tester->execute(['id' => $this->firstId(), '--part' => 'html']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('', trim($tester->getDisplay()));
    }

    #[Test]
    #[DataProvider('unknownIds')]
    public function rejectsUnknownAndMalformedIds(string $id): void
    {
        $this->addMail('Hello');

        $tester = $this->tester();
        $tester->execute(['id' => $id]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Mail not found.', $tester->getDisplay());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unknownIds(): array
    {
        return ['unknown' => [self::UNKNOWN_ID], 'malformed' => ['../etc/passwd'], 'uppercase' => [strtoupper(self::UNKNOWN_ID)]];
    }

    #[Test]
    public function rejectsAnUnknownPart(): void
    {
        $this->addMail('Hello');

        $tester = $this->tester();
        $tester->execute(['id' => $this->firstId(), '--part' => 'pdf']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--part', $tester->getDisplay());
    }

    #[Test]
    public function failsWhenTheMailboxIsNotConfigured(): void
    {
        $this->useMailbox('');

        $tester = $this->tester();
        $tester->execute(['id' => self::UNKNOWN_ID]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Letterbox is not configured: MAIL/transport_mbox_file is empty.', $tester->getDisplay());
    }

    #[Test]
    public function stripsControlCharactersOnATerminalButKeepsTheSourceRaw(): void
    {
        $this->addMail("Hello\e]0;pwned\x07", 'r@example.org', '2026-10-27 10:00:00', "Plain \e[31mred\e[0m text\x7F2J");

        $tester = $this->tester();
        $tester->execute(['id' => $this->firstId()], ['decorated' => true]);
        self::assertSame("Plain [31mred[0m text2J\n", $tester->getDisplay());

        $tester->execute(['id' => $this->firstId(), '--part' => 'headers'], ['decorated' => true]);
        self::assertStringNotContainsString("\e", $tester->getDisplay());
        self::assertStringNotContainsString("\x07", $tester->getDisplay());

        $tester->execute(['id' => $this->firstId(), '--part' => 'source'], ['decorated' => true]);
        self::assertStringContainsString("\e[31mred", $tester->getDisplay());

        $tester->execute(['id' => $this->firstId()], ['decorated' => false]);
        self::assertStringContainsString("\e[31mred", $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester($this->get(ShowCommand::class));
    }
}
