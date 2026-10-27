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

use Closure;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputOption};
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mime\Email;
use TYPO3\CMS\Core\Mail\MailerInterface;

use function count;
use function is_string;
use function sprintf;

/**
 * SendTestMailsCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
#[AsCommand(name: 'letterbox:testmails:send', description: 'Send test mails through the configured mail transport')]
final class SendTestMailsCommand extends Command
{
    private const MAX_COUNT = 100;
    private const INLINE_IMAGE = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function __construct(private readonly MailerInterface $mailer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'Number of test mails to send', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $option = $input->getOption('count');
        if (!is_string($option) || 1 !== preg_match('/^\d{1,9}$/D', $option) || (int) $option < 1 || (int) $option > self::MAX_COUNT) {
            $io->error(sprintf('The option --count must be a whole number between 1 and %d.', self::MAX_COUNT));

            return Command::INVALID;
        }
        $count = (int) $option;
        $builders = $this->builders();

        for ($i = 0; $i < $count; ++$i) {
            $email = $builders[$i % count($builders)]($i + 1);
            $this->mailer->send($email);
        }

        $io->success(sprintf('Sent %d test mail(s).', $count));

        return Command::SUCCESS;
    }

    /**
     * @return list<Closure(int): Email>
     */
    private function builders(): array
    {
        return [
            fn (int $no): Email => $this->base($no, 'Plain text')
                ->text('This is the plain text body of the test mail.'),
            fn (int $no): Email => $this->base($no, 'HTML with inline image')
                ->text('This mail contains an inline image.')
                ->embed((string) base64_decode(self::INLINE_IMAGE, true), 'letterbox-inline', 'image/png')
                ->html('<p>This is the <strong>HTML body</strong> with an inline image: <img src="cid:letterbox-inline" alt="Inline test image" width="32" height="32"></p>'),
            fn (int $no): Email => $this->base($no, 'HTML with attachment')
                ->text('This mail contains an attachment.')
                ->html('<p>This is the <strong>HTML body</strong> of a mail with an attachment.</p>')
                ->attach('Letterbox test attachment', 'letterbox-notes.txt', 'text/plain'),
            fn (int $no): Email => $this->base($no, 'Cc and Bcc')
                ->cc('cc@example.com')
                ->bcc('bcc@example.com')
                ->text('This mail has a Cc and a Bcc recipient.'),
            fn (int $no): Email => $this->base($no, 'From line in body')
                ->text("A line starting with From must survive the mbox escaping:\n\nFrom here on the body continues.\n"),
        ];
    }

    private function base(int $no, string $label): Email
    {
        return (new Email())
            ->from('sender@example.org')
            ->to('recipient@example.com')
            ->subject(sprintf('Letterbox test mail #%d: %s', $no, $label));
    }
}
