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

use KonradMichalik\Typo3Letterbox\Command\ClearCommand;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Console\CommandRegistry;

/**
 * ClearCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ClearCommandTest extends MailboxCommandTestCase
{
    #[Test]
    public function forceClearsWithoutAsking(): void
    {
        $this->addMail('One');
        $this->addMail('Two');

        $tester = $this->tester();
        $tester->execute(['--force' => true], ['interactive' => false]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(0, $this->mailCount());
        self::assertStringContainsString('Cleared 2 mails.', $tester->getDisplay());
    }

    #[Test]
    public function nonInteractiveWithoutForceFailsAndKeepsTheMails(): void
    {
        $this->addMail('One');

        $tester = $this->tester();
        $tester->execute([], ['interactive' => false]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Use --force to clear the mailbox without confirmation.', $tester->getDisplay());
        self::assertSame(1, $this->mailCount());
    }

    #[Test]
    public function interactiveConfirmationClears(): void
    {
        $this->addMail('One');

        $tester = $this->tester();
        $tester->setInputs(['yes']);
        $tester->execute([], ['interactive' => true]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertSame(0, $this->mailCount());
    }

    #[Test]
    public function interactiveDefaultAnswerKeepsTheMails(): void
    {
        $this->addMail('One');

        $tester = $this->tester();
        $tester->setInputs(['']);
        $tester->execute([], ['interactive' => true]);

        self::assertSame(1, $this->mailCount());
    }

    #[Test]
    public function failsWhenTheMailboxIsNotConfigured(): void
    {
        $this->useMailbox('');

        $tester = $this->tester();
        $tester->execute(['--force' => true]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Letterbox is not configured: MAIL/transport_mbox_file is empty.', $tester->getDisplay());
    }

    #[Test]
    public function isNotSchedulable(): void
    {
        $registry = $this->get(CommandRegistry::class);
        $names = array_keys($registry->getSchedulableCommandsConfiguration());

        self::assertNotContains('letterbox:clear', $names);
        self::assertNotContains('letterbox:list', $names);
        self::assertNotContains('letterbox:show', $names);
        self::assertContains('letterbox:prune', $names);
    }

    private function tester(): CommandTester
    {
        return new CommandTester($this->get(ClearCommand::class));
    }
}
