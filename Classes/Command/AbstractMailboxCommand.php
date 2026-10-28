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

use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * AbstractMailboxCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
abstract class AbstractMailboxCommand extends Command
{
    public function __construct(protected readonly MailRepository $mails)
    {
        parent::__construct();
    }

    abstract protected function handle(InputInterface $input, SymfonyStyle $io): int;

    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$this->mails->isConfigured()) {
            return $this->fail($io, 'Letterbox is not configured: MAIL/transport_mbox_file is empty.');
        }

        return $this->handle($input, $io);
    }

    final protected function fail(SymfonyStyle $io, string $message): int
    {
        $io->getErrorStyle()->error($message);

        return Command::FAILURE;
    }
}
