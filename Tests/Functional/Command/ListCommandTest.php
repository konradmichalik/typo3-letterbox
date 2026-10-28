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

use KonradMichalik\Typo3Letterbox\Command\ListCommand;
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use Symfony\Component\Console\Tester\CommandTester;

/**
 * ListCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ListCommandTest extends MailboxCommandTestCase
{
    #[Test]
    public function listsMailsNewestFirstWithTheirColumns(): void
    {
        $this->addMail('First mail', 'a@example.org', '2026-10-27 10:00:00');
        $this->addMail('Second mail', 'b@example.org, c@example.org', '2026-10-27 11:30:15');

        $tester = $this->tester();
        $tester->execute([], ['interactive' => false]);

        $display = $tester->getDisplay();
        self::assertSame(0, $tester->getStatusCode());
        self::assertLessThan(strpos($display, 'First mail'), strpos($display, 'Second mail'));
        self::assertStringContainsString('2026-10-27 11:30:15', $display);
        self::assertStringContainsString('s@example.org', $display);
        self::assertStringContainsString('b@example.org +1', $display);
        self::assertStringContainsString($this->firstId(), $display);
    }

    #[Test]
    public function limitCapsTheRows(): void
    {
        $this->addMail('Alpha mail');
        $this->addMail('Beta mail');
        $this->addMail('Gamma mail');

        $tester = $this->tester();
        $tester->execute(['--limit' => '2']);

        self::assertStringContainsString('Gamma mail', $tester->getDisplay());
        self::assertStringContainsString('Beta mail', $tester->getDisplay());
        self::assertStringNotContainsString('Alpha mail', $tester->getDisplay());
    }

    #[Test]
    public function filtersByRecipientAndSubject(): void
    {
        $this->addMail('Invoice one', 'a@example.org');
        $this->addMail('Invoice two', 'b@example.org');
        $this->addMail('Newsletter', 'b@example.org');

        $tester = $this->tester();
        $tester->execute(['--to' => 'b@example.org', '--subject' => 'invoice']);

        self::assertStringContainsString('Invoice two', $tester->getDisplay());
        self::assertStringNotContainsString('Invoice one', $tester->getDisplay());
        self::assertStringNotContainsString('Newsletter', $tester->getDisplay());
    }

    #[Test]
    public function truncatesLongSubjectsToSixtyCharacters(): void
    {
        $this->addMail(str_repeat('x', 100));

        $tester = $this->tester();
        $tester->execute([]);

        self::assertStringContainsString(str_repeat('x', 57).'...', $tester->getDisplay());
        self::assertStringNotContainsString(str_repeat('x', 61), $tester->getDisplay());
    }

    #[Test]
    #[DataProvider('invalidLimits')]
    public function rejectsInvalidLimit(string $limit): void
    {
        $tester = $this->tester();
        $tester->execute(['--limit' => $limit]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('--limit', $tester->getDisplay());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidLimits(): array
    {
        return ['zero' => ['0'], 'too large' => ['501'], 'text' => ['many'], 'negative' => ['-1']];
    }

    #[Test]
    public function failsWhenTheMailboxIsNotConfigured(): void
    {
        $this->useMailbox('');

        $tester = $this->tester();
        $tester->execute([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Letterbox is not configured: MAIL/transport_mbox_file is empty.', $tester->getDisplay());
    }

    #[Test]
    public function stripsControlCharactersFromTheColumns(): void
    {
        $this->addMail("Red \e[31malert\e[0m\u{9B}2J", "Eve\e[2J <eve@example.org>");

        $tester = $this->tester();
        $tester->execute([]);

        self::assertStringNotContainsString("\e", $tester->getDisplay());
        self::assertStringNotContainsString("\u{9B}", $tester->getDisplay());
        self::assertStringContainsString('Red [31malert[0m2J', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester($this->get(ListCommand::class));
    }
}
