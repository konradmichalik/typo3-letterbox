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

namespace KonradMichalik\Typo3Letterbox\Command;

use DateTimeZone;
use KonradMichalik\Typo3Letterbox\Domain\Model\MailFilter;
use KonradMichalik\Typo3Letterbox\Mbox\MessageIndexEntry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Style\SymfonyStyle;

use function count;
use function is_string;
use function sprintf;

/**
 * ListCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[AsCommand(name: 'letterbox:list', description: 'List the caught mails, newest first')]
final class ListCommand extends AbstractMailboxCommand
{
    private const MAX_LIMIT = 500;
    private const SUBJECT_WIDTH = 60;

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Number of mails to list', '20')
            ->addOption('to', null, InputOption::VALUE_REQUIRED, 'Only mails to this address')
            ->addOption('subject', null, InputOption::VALUE_REQUIRED, 'Only mails whose subject contains this text');
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $option = $input->getOption('limit');
        if (!is_string($option) || 1 !== preg_match('/^\d{1,9}$/D', $option) || (int) $option < 1 || (int) $option > self::MAX_LIMIT) {
            return $this->fail($io, sprintf('The option --limit must be a whole number between 1 and %d.', self::MAX_LIMIT));
        }

        $filter = MailFilter::all();
        $to = $input->getOption('to');
        if (is_string($to) && '' !== $to) {
            $filter = $filter->withRecipient($to);
        }
        $subject = $input->getOption('subject');
        if (is_string($subject) && '' !== $subject) {
            $filter = $filter->withSubject($subject);
        }

        $timezone = new DateTimeZone(date_default_timezone_get());
        $rows = array_map(
            static fn (MessageIndexEntry $entry): array => [
                $entry->id,
                $entry->received?->setTimezone($timezone)->format('Y-m-d H:i:s') ?? 'unknown',
                OutputFormatter::escape($entry->sender),
                OutputFormatter::escape(self::recipients($entry)),
                OutputFormatter::escape(mb_strimwidth($entry->subject, 0, self::SUBJECT_WIDTH, '...')),
            ],
            $this->mails->findEntries($filter, 0, (int) $option),
        );
        $io->table(['ID', 'Received', 'From', 'To', 'Subject'], $rows);

        return Command::SUCCESS;
    }

    private static function recipients(MessageIndexEntry $entry): string
    {
        $others = count($entry->recipients) - 1;

        return ($entry->recipients[0] ?? '').($others > 0 ? ' +'.$others : '');
    }
}
