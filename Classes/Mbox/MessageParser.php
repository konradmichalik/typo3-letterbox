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

namespace KonradMichalik\Typo3Letterbox\Mbox;

use DateTimeImmutable;
use KonradMichalik\Typo3Letterbox\Domain\Model\{Address, Attachment, Mail, MailLink};
use ZBateson\MailMimeParser\Header\{AddressHeader, DateHeader};
use ZBateson\MailMimeParser\{IMessage, MailMimeParser};
use ZBateson\MailMimeParser\Message\IMessagePart;

use function strlen;

/**
 * MessageParser.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MessageParser
{
    public const ENVELOPE_HEADER = 'X-Letterbox-Envelope-To';
    public const URL_PATTERN = '#https?://[^\s<>"\']+#i';
    public const URL_TRAILING_PUNCTUATION = '.,;:!?)]}';
    private const READ_CHUNK_BYTES = 65536;

    private MailMimeParser $parser;

    public function __construct()
    {
        $this->parser = new MailMimeParser();
    }

    /**
     * Attachment content stays in the parsed message until it is asked for, so views never hold attachment bodies.
     */
    public function parse(string $raw): Mail
    {
        $message = $this->message($raw);
        $text = $message->getTextContent() ?? '';
        $html = $message->getHtmlContent() ?? '';

        return new Mail(
            self::idOf($raw),
            $message->getHeaderValue('Message-ID') ?? '',
            $message->getSubject() ?? '',
            $this->date($message),
            $this->addresses($message, 'From')[0] ?? null,
            $this->addresses($message, 'To'),
            $this->addresses($message, 'Cc'),
            $this->addresses($message, 'Bcc'),
            $this->addresses($message, 'Reply-To'),
            $text,
            $html,
            $this->attachments($message),
            $this->headers($message),
            strlen($raw),
            $raw,
            $this->linksIn($text, $html),
        );
    }

    /**
     * The links of the text and HTML part, without touching attachments.
     *
     * @return list<MailLink>
     */
    public function links(string $raw): array
    {
        $message = $this->message($raw);

        return $this->linksIn($message->getTextContent() ?? '', $message->getHtmlContent() ?? '');
    }

    public function summarize(string $raw, MessageSpan $span, ?DateTimeImmutable $received = null): MessageIndexEntry
    {
        // The index needs only headers, so the parser gets the header block and never copies a large body.
        $headerEnd = strpos($raw, "\n\n");
        $message = $this->message(false === $headerEnd ? $raw : substr($raw, 0, $headerEnd + 2));

        // Lowercase address => display name, the first non-empty name wins.
        $names = [];
        $scope = [];
        $listed = ['To' => $this->addresses($message, 'To'), 'Cc' => $this->addresses($message, 'Cc'), 'Bcc' => $this->addresses($message, 'Bcc')];
        foreach ($listed as $header => $addresses) {
            foreach ($addresses as $address) {
                $key = strtolower($address->address);
                $names[$key] = ($names[$key] ?? '') ?: trim($address->name);
                if ('Bcc' !== $header) {
                    $scope[$key] = true;
                }
            }
        }
        foreach (explode(',', $message->getHeaderValue(self::ENVELOPE_HEADER) ?? '') as $address) {
            $key = strtolower(trim($address));
            $names[$key] ??= '';
            $scope[$key] = true;
        }
        unset($names[''], $scope['']);
        $recipients = array_map(strval(...), array_keys($names));

        $from = $this->addresses($message, 'From')[0] ?? new Address('');

        return new MessageIndexEntry(
            $span,
            self::idOf($raw),
            $this->date($message),
            $from->address,
            $from->name,
            $recipients,
            array_map(static fn (string $address, string $name): string => '' !== $name ? $name : $address, $recipients, array_values($names)),
            array_map(strval(...), array_keys($scope)),
            $message->getSubject() ?? '',
            AttachmentCounter::count($raw) ?? $this->message($raw)->getAttachmentCount(),
            $received,
            $message->getHeaderValue('Message-ID') ?? '',
            $listed['To'],
            $listed['Cc'],
        );
    }

    public static function idOf(string $raw): string
    {
        return substr(hash('sha256', $raw), 0, 32);
    }

    private function message(string $raw): IMessage
    {
        return $this->parser->parse($raw, true);
    }

    private function date(IMessage $message): ?DateTimeImmutable
    {
        $header = $message->getHeader('Date');

        return $header instanceof DateHeader ? $header->getDateTimeImmutable() : null;
    }

    /**
     * @return list<Address>
     */
    private function addresses(IMessage $message, string $name): array
    {
        $header = $message->getHeader($name);
        if (!$header instanceof AddressHeader) {
            return [];
        }

        $addresses = [];
        foreach ($header->getAddresses() as $part) {
            $addresses[] = new Address($part->getEmail(), $part->getName());
        }

        return $addresses;
    }

    /**
     * @return list<Attachment>
     */
    private function attachments(IMessage $message): array
    {
        $attachments = [];
        foreach ($message->getAllAttachmentParts() as $index => $part) {
            $attachments[] = new Attachment(
                $index,
                $part->getFilename() ?? '',
                trim($part->getContentId() ?? '', '<>'),
                $part->getContentType(),
                self::decodedSize($part),
                static fn (): string => $part->getBinaryContentStream()?->getContents() ?? '',
            );
        }

        return $attachments;
    }

    /**
     * Decodes in chunks to count the bytes, so the content itself is never held.
     */
    private static function decodedSize(IMessagePart $part): int
    {
        $stream = $part->getBinaryContentStream();
        $size = 0;
        while (null !== $stream && !$stream->eof()) {
            $size += strlen($stream->read(self::READ_CHUNK_BYTES));
        }

        return $size;
    }

    /**
     * @return array<string, list<string>>
     */
    private function headers(IMessage $message): array
    {
        $headers = [];
        foreach ($message->getAllHeaders() as $header) {
            $headers[$header->getName()][] = $header->getDecodedValue();
        }

        return $headers;
    }

    /**
     * @return list<MailLink>
     */
    private function linksIn(string $text, string $html): array
    {
        $links = [];
        if (0 < (int) preg_match_all(self::URL_PATTERN, $text, $matches)) {
            foreach ($matches[0] as $url) {
                $links[] = rtrim($url, self::URL_TRAILING_PUNCTUATION);
            }
        }
        if (0 < (int) preg_match_all('#\bhref\s*=\s*(["\'])(.*?)\1#is', $html, $matches)) {
            foreach ($matches[2] as $href) {
                $url = trim(html_entity_decode($href, \ENT_QUOTES | \ENT_HTML5));
                if (1 === preg_match('#^https?://#i', $url)) {
                    $links[] = $url;
                }
            }
        }

        return array_map(
            static fn (string $url): MailLink => new MailLink($url, self::isPasswordReset($url)),
            array_values(array_unique($links)),
        );
    }

    /**
     * felogin: tx_felogin_login[action]=showChangePassword or tx_felogin_login[hash] (RecoveryService::prepareMail).
     * Backend: route password_reset_validate, path /typo3/login/password-reset/validate (backend Routes.php).
     */
    private static function isPasswordReset(string $url): bool
    {
        $path = (string) parse_url($url, \PHP_URL_PATH);
        if (str_contains($path, '/typo3/login/password-reset/')) {
            return true;
        }
        $query = rawurldecode((string) parse_url($url, \PHP_URL_QUERY));

        return str_contains($query, 'tx_felogin_login[action]=showChangePassword') || str_contains($query, 'tx_felogin_login[hash]=');
    }
}
