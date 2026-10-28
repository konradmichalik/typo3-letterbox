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

use KonradMichalik\Typo3Letterbox\Command\PruneCommand;
use KonradMichalik\Typo3Letterbox\Domain\Model\MailFilter;
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use KonradMichalik\Typo3Letterbox\Mbox\MessageIndexEntry;
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Cache\CacheManager;

/**
 * PruneCommandTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class PruneCommandTest extends MailboxCommandTestCase
{
    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => ['typo3_letterbox' => ['maxMessages' => '2', 'maxAgeDays' => '0']],
    ];

    #[Test]
    public function usesTheExtensionConfigurationByDefault(): void
    {
        foreach (['One', 'Two', 'Three', 'Four'] as $subject) {
            $this->addMail($subject);
        }

        $tester = $this->tester();
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Removed 2 mails.', $tester->getDisplay());
        self::assertSame(2, $this->mailCount());
    }

    #[Test]
    public function maxMessagesOptionOverridesTheConfiguration(): void
    {
        foreach (['One', 'Two', 'Three', 'Four'] as $subject) {
            $this->addMail($subject);
        }

        $tester = $this->tester();
        $tester->execute(['--max-messages' => '3']);

        self::assertStringContainsString('Removed 1 mails.', $tester->getDisplay());
        self::assertSame(3, $this->mailCount());
    }

    #[Test]
    public function maxAgeDaysOptionRemovesOldMails(): void
    {
        $this->addMail('Old', 'r@example.org', '2020-01-01 10:00:00');
        $this->addMail('Fresh', 'r@example.org', date('Y-m-d H:i:s'));

        $tester = $this->tester();
        $tester->execute(['--max-messages' => '0', '--max-age-days' => '30']);

        self::assertStringContainsString('Removed 1 mails.', $tester->getDisplay());
        self::assertSame(1, $this->mailCount());
    }

    #[Test]
    public function zeroOverridesDisableTheLimits(): void
    {
        foreach (['One', 'Two', 'Three'] as $subject) {
            $this->addMail($subject);
        }

        $tester = $this->tester();
        $tester->execute(['--max-messages' => '0', '--max-age-days' => '0']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Removed 0 mails.', $tester->getDisplay());
        self::assertSame(3, $this->mailCount());
    }

    #[Test]
    #[DataProvider('invalidOptions')]
    public function rejectsInvalidOverrides(string $option, string $value): void
    {
        $this->addMail('One');

        $tester = $this->tester();
        $tester->execute([$option => $value]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString($option, $tester->getDisplay());
        self::assertSame(1, $this->mailCount());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidOptions(): array
    {
        return [
            'negative messages' => ['--max-messages', '-1'],
            'text messages' => ['--max-messages', 'many'],
            'negative days' => ['--max-age-days', '-5'],
        ];
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
    public function warnsAndFailsWhenTheFileKeepsChangingUnderneath(): void
    {
        foreach (['One', 'Two', 'Three'] as $subject) {
            $this->addMail($subject);
        }
        $this->get(MailRepository::class)->count(MailFilter::all());
        // Index entries whose IDs no longer match the file look like a rewrite by another process on every attempt.
        $cache = $this->get(CacheManager::class)->getCache('typo3_letterbox_index');
        $key = 'v5_'.hash('sha256', $this->path);
        $cached = $cache->get($key);
        self::assertIsArray($cached);
        $cached['entries'] = array_map(
            static fn (MessageIndexEntry $entry): MessageIndexEntry => new MessageIndexEntry(
                $entry->span,
                str_repeat('0', 32),
                $entry->date,
                $entry->sender,
                $entry->fromName,
                $entry->recipients,
                $entry->recipientLabels,
                $entry->scopeRecipients,
                $entry->subject,
                $entry->attachmentCount,
                $entry->received,
            ),
            $cached['entries'],
        );
        $cache->set($key, $cached);

        $tester = $this->tester();
        $tester->execute(['--max-messages' => '1']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('The mbox file changed while pruning', $tester->getDisplay());
        self::assertSame(3, $this->mailCount());
    }

    private function tester(): CommandTester
    {
        return new CommandTester($this->get(PruneCommand::class));
    }
}
