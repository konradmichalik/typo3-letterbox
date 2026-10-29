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
use InvalidArgumentException;
use KonradMichalik\Typo3Letterbox\Domain\Model\Mail;
use KonradMichalik\Typo3Letterbox\Mail\{RetentionService, TransportTakeover};
use KonradMichalik\Typo3Letterbox\Mbox\{MailboxLock, MailboxWriter, MboxFormat, MessageParser};
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, StreamableInputInterface};
use Symfony\Component\Console\Output\{ConsoleOutputInterface, OutputInterface};
use Throwable;
use TYPO3\CMS\Core\Resource\Security\FileNameValidator;

use function is_array;
use function is_string;
use function sprintf;
use function strlen;

/**
 * ReceiveCommand.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ReceiveCommand extends Command
{
    public const NAME = 'letterbox:receive';
    public const MAX_BYTES = 25 * 1024 * 1024;

    private const EXIT_INVALID_INPUT = 1;
    private const EXIT_MAILBOX_UNAVAILABLE = 2;

    public function __construct(
        private readonly MailboxLock $lock,
        private readonly MessageParser $parser,
        private readonly RetentionService $retention,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        // TYPO3 binds the input against the application before the command runs and loses every token after the
        // first unknown sendmail flag, so the command reads $_SERVER['argv'] itself and must not reject the flags.
        $this->ignoreValidationErrors();
        $this->setHelp('Reads one RFC 822 message from stdin and stores it in the mailbox, for sendmail_path = "/usr/bin/php /path/to/vendor/bin/typo3 letterbox:receive -- -t -i". Keep the "--": without it the console reads the sendmail flags -V and -h as --version and --help and drops the mail. Understands -t, -i, -f <sender>, -r <sender> and recipient arguments, ignores other sendmail flags. Exit code 0 when stored, 1 for invalid input, 2 when the mailbox is not configured or not writable.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            // Read stdin before anything else, so the sending process never writes into a closed pipe.
            $this->receive($this->read($input));
        } catch (InvalidArgumentException $e) {
            return self::fail($output, self::EXIT_INVALID_INPUT, $e->getMessage());
        } catch (RuntimeException $e) {
            return self::fail($output, self::EXIT_MAILBOX_UNAVAILABLE, $e->getMessage());
        }

        return Command::SUCCESS;
    }

    /**
     * @throws InvalidArgumentException for invalid input
     * @throws RuntimeException         when the mailbox is not configured or not writable
     */
    private function receive(?string $raw): void
    {
        $path = self::mailboxPath();
        if (null === $raw) {
            throw new InvalidArgumentException(sprintf('The message is larger than %d MiB.', self::MAX_BYTES / 1024 / 1024), 1761700001);
        }
        if ('' === trim($raw)) {
            throw new InvalidArgumentException('The message on stdin is empty.', 1761700002);
        }

        $arguments = SendmailArguments::fromArgv(SendmailArguments::afterCommand(self::argv(), self::NAME));
        $mail = $arguments->recipientsFromHeaders || null === $arguments->sender ? $this->parse($raw) : null;
        $recipients = self::recipients($arguments, $mail);
        $sender = $arguments->sender ?? $mail->from->address ?? '';

        try {
            (new MailboxWriter($path, new MboxFormat(), $this->lock))->append(
                self::isPlainAddress($sender) ? $sender : '',
                new DateTimeImmutable(),
                MessageParser::ENVELOPE_HEADER.': '.implode(', ', $recipients)."\n".self::withoutBcc($raw),
            );
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), 1761700003, $e);
        }

        // After append() released the lock: the lock is not re-entrant, pruning takes it again.
        $this->retention->afterAppend($path);
    }

    private static function mailboxPath(): string
    {
        $path = $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_mbox_file'] ?? '';
        if (!is_string($path) || '' === $path) {
            throw new RuntimeException('Letterbox is not configured: MAIL/transport_mbox_file is empty.', 1761700004);
        }
        if (!(new FileNameValidator())->isValid($path)) {
            throw new RuntimeException(sprintf('The mailbox file "%s" is not allowed by the file deny pattern.', $path), 1761700005);
        }
        TransportTakeover::prepareMailbox((array) $GLOBALS['TYPO3_CONF_VARS']['MAIL']);

        return $path;
    }

    private function parse(string $raw): Mail
    {
        try {
            return $this->parser->parse($raw);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('The message could not be parsed.', 1761700006, $e);
        }
    }

    private static function fail(OutputInterface $output, int $code, string $message): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $errors->writeln(self::NAME.': '.$message, OutputInterface::OUTPUT_RAW);

        return $code;
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function argv(): array
    {
        // The raw command line is the only source of the sendmail flags, see configure().
        $argv = $_SERVER['argv'] ?? [];

        return is_array($argv) ? $argv : [];
    }

    /**
     * @return string|null the message, null when it exceeds MAX_BYTES
     */
    private function read(InputInterface $input): ?string
    {
        $stream = ($input instanceof StreamableInputInterface ? $input->getStream() : null) ?? \STDIN;
        $raw = stream_get_contents($stream, self::MAX_BYTES + 1);
        if (false === $raw) {
            return '';
        }

        return strlen($raw) > self::MAX_BYTES ? null : $raw;
    }

    /**
     * @return non-empty-list<string> positional recipients first, then To, Cc and Bcc with -t; domains lowercased, without duplicates
     *
     * @throws InvalidArgumentException when an address is invalid or there is none
     */
    private static function recipients(SendmailArguments $arguments, ?Mail $mail): array
    {
        $addresses = $arguments->recipients;
        if ($arguments->recipientsFromHeaders && null !== $mail) {
            foreach ([...$mail->to, ...$mail->cc, ...$mail->bcc] as $address) {
                $addresses[] = $address->address;
            }
        }

        $recipients = array_values(array_unique(array_map(self::lowercaseDomain(...), array_filter($addresses, static fn (string $address): bool => '' !== $address))));
        foreach ([...$recipients, ...(null === $arguments->sender ? [] : [$arguments->sender])] as $address) {
            if (!self::isPlainAddress($address)) {
                throw new InvalidArgumentException(sprintf('The address "%s" is invalid.', addcslashes($address, "\0..\37\177")), 1761700007);
            }
        }
        if ([] === $recipients) {
            throw new InvalidArgumentException('The message has no recipients: pass them as arguments or use -t.', 1761700008);
        }

        return $recipients;
    }

    private static function lowercaseDomain(string $address): string
    {
        $at = strrpos($address, '@');

        return false === $at ? $address : substr($address, 0, $at + 1).strtolower(substr($address, $at + 1));
    }

    /**
     * Rejects what would break the envelope header or the separator line: whitespace, control characters and list delimiters.
     */
    private static function isPlainAddress(string $address): bool
    {
        return 1 === preg_match('/^[^\s\x00-\x1F\x7F,<>"]+$/D', $address);
    }

    /**
     * Removes the Bcc header, including folded continuation lines, from the header block of the stored copy.
     */
    private static function withoutBcc(string $raw): string
    {
        $headerEnd = 1 === preg_match('/\r?\n(?=\r?\n)/', $raw, $match, \PREG_OFFSET_CAPTURE) ? $match[0][1] + strlen($match[0][0]) : strlen($raw);
        $headers = substr($raw, 0, $headerEnd);

        return (preg_replace('/^Bcc:.*(?:\r?\n[ \t].*)*(?:\r?\n|$)/im', '', $headers) ?? $headers).substr($raw, $headerEnd);
    }
}
