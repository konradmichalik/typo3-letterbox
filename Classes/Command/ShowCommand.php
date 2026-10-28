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

use KonradMichalik\Typo3Letterbox\Domain\Model\{Mail};
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputArgument, InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function in_array;
use function is_string;

/**
 * ShowCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[AsCommand(name: 'letterbox:show', description: 'Print one part of a caught mail')]
final class ShowCommand extends AbstractMailboxCommand
{
    private const PARTS = ['text', 'html', 'source', 'headers'];

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Mail id as shown by letterbox:list')
            ->addOption('part', 'p', InputOption::VALUE_REQUIRED, 'Part to print: '.implode(', ', self::PARTS), 'text')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Print control characters unchanged, also on a terminal');
    }

    protected function handle(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        $part = $input->getOption('part');
        if (!is_string($part) || !in_array($part, self::PARTS, true)) {
            return $this->fail($io, 'The option --part must be one of: '.implode(', ', self::PARTS).'.');
        }

        $id = $input->getArgument('id');
        $mail = is_string($id) && 1 === preg_match('/^[0-9a-f]{32}$/D', $id) ? $this->mails->findById($id) : null;
        if (!$mail instanceof Mail) {
            return $this->fail($io, 'Mail not found.');
        }

        $content = self::content($mail, $part);
        // Piped output stays byte for byte. On a terminal a mail must not send escape sequences, the source included.
        if (true !== $input->getOption('raw') && self::isTerminal($output)) {
            $content = self::printable($content);
        }
        $io->write($content, false, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }

    private static function content(Mail $mail, string $part): string
    {
        return match ($part) {
            'html' => $mail->html,
            'source' => $mail->source,
            'headers' => self::headers($mail),
            default => $mail->text,
        };
    }

    private static function headers(Mail $mail): string
    {
        $lines = '';
        foreach ($mail->headers as $name => $values) {
            foreach ($values as $value) {
                $lines .= $name.': '.$value.\PHP_EOL;
            }
        }

        return $lines;
    }
}
