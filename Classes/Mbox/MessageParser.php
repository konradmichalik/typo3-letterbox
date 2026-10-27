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
use KonradMichalik\Typo3Letterbox\Domain\Model\{Address, Attachment, Mail};
use ZBateson\MailMimeParser\Header\{AddressHeader, DateHeader};
use ZBateson\MailMimeParser\{IMessage, MailMimeParser};

use function strlen;

/**
 * MessageParser.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MessageParser
{
    public const ENVELOPE_HEADER = 'X-Letterbox-Envelope-To';

    private MailMimeParser $parser;

    public function __construct()
    {
        $this->parser = new MailMimeParser();
    }

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
            $this->links($text, $html),
        );
    }

    public function summarize(string $raw, MessageSpan $span): MessageIndexEntry
    {
        $message = $this->message($raw);

        $recipients = [];
        foreach (['To', 'Cc', 'Bcc'] as $name) {
            foreach ($this->addresses($message, $name) as $address) {
                $recipients[] = strtolower($address->address);
            }
        }
        foreach (explode(',', $message->getHeaderValue(self::ENVELOPE_HEADER) ?? '') as $address) {
            $recipients[] = strtolower(trim($address));
        }

        return new MessageIndexEntry(
            $span,
            self::idOf($raw),
            $this->date($message),
            ($this->addresses($message, 'From')[0] ?? new Address(''))->address,
            array_values(array_unique(array_filter($recipients, static fn (string $a): bool => '' !== $a))),
            $message->getSubject() ?? '',
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
            $content = $part->getBinaryContentStream()?->getContents() ?? '';
            $attachments[] = new Attachment(
                $index,
                $part->getFilename() ?? '',
                trim($part->getContentId() ?? '', '<>'),
                $part->getContentType(),
                strlen($content),
                $content,
            );
        }

        return $attachments;
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
     * @return list<string>
     */
    private function links(string $text, string $html): array
    {
        $links = [];
        if (0 < (int) preg_match_all('#https?://[^\s<>"\']+#i', $text, $matches)) {
            foreach ($matches[0] as $url) {
                $links[] = rtrim($url, '.,;:!?)]}');
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

        return array_values(array_unique($links));
    }
}
