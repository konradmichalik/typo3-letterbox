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

    protected function handle(InputInterface $input, SymfonyStyle $io): int
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

        $io->success(sprintf('Removed %d mails.', $this->mails->prune($maxMessages, $maxAgeDays, new DateTimeImmutable())));

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
