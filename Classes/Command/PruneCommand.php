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

use DateTimeImmutable;
use KonradMichalik\Typo3Letterbox\Configuration\ExtensionSettings;
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use KonradMichalik\Typo3Letterbox\Mail\RetentionSettings;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_string;
use function sprintf;

/**
 * PruneCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[AsCommand(name: 'letterbox:prune', description: 'Remove caught mails beyond the retention limits')]
final class PruneCommand extends AbstractMailboxCommand
{
    public function __construct(MailRepository $mails, private readonly ExtensionSettings $settings)
    {
        parent::__construct($mails);
    }

    protected function configure(): void
    {
        $this
            ->addOption('max-messages', null, InputOption::VALUE_REQUIRED, 'Keep at most this many mails, 0 for no limit (default: extension configuration)')
            ->addOption('max-age-days', null, InputOption::VALUE_REQUIRED, 'Remove mails older than this many days, 0 for no limit (default: extension configuration)');
    }

    protected function handle(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $settings = RetentionSettings::fromExtensionSettings($this->settings);
        $maxMessages = self::override($input->getOption('max-messages'), $settings->maxMessages);
        $maxAgeDays = self::override($input->getOption('max-age-days'), $settings->maxAgeDays);
        if (null === $maxMessages) {
            return $this->fail($io, 'The option --max-messages must be a whole number of 0 or more.');
        }
        if (null === $maxAgeDays) {
            return $this->fail($io, 'The option --max-age-days must be a whole number of 0 or more.');
        }

        $now = new DateTimeImmutable();
        $removed = $this->mails->prune($maxMessages, $maxAgeDays, $now);
        // Nothing removed although mails are due: another process rewrote the file in between, try once more.
        if (0 === $removed && $this->mails->needsPruning($maxMessages, $maxAgeDays, $now)) {
            $removed = $this->mails->prune($maxMessages, $maxAgeDays, $now);
            if (0 === $removed) {
                $io->getErrorStyle()->warning('The mbox file changed while pruning, no mails were removed. Run the command again.');

                return Command::FAILURE;
            }
        }

        $io->success(sprintf('Removed %d mails.', $removed));

        return Command::SUCCESS;
    }

    /**
     * @return int|null the override, the default when none is given, null when the override is invalid
     */
    private static function override(mixed $option, int $default): ?int
    {
        if (null === $option) {
            return $default;
        }

        return is_string($option) && 1 === preg_match('/^\d{1,9}$/D', $option) ? (int) $option : null;
    }
}
