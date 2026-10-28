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

use KonradMichalik\Typo3Letterbox\Domain\Model\MailFilter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function sprintf;

/**
 * ClearCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[AsCommand(name: 'letterbox:clear', description: 'Delete every caught mail')]
final class ClearCommand extends AbstractMailboxCommand
{
    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Clear without asking for confirmation');
    }

    protected function handle(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        if (true !== $input->getOption('force')) {
            if (!$input->isInteractive()) {
                return $this->fail($io, 'Use --force to clear the mailbox without confirmation.');
            }
            if (!$io->confirm('Delete every caught mail?', false)) {
                $io->writeln('Nothing deleted.');

                return Command::SUCCESS;
            }
        }

        $count = $this->mails->count(MailFilter::all());
        $this->mails->clear();
        $io->success(sprintf('Cleared %d mails.', $count));

        return Command::SUCCESS;
    }
}
