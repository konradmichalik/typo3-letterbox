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

namespace KonradMichalik\Typo3Letterbox\External\Api;

use DateTimeImmutable;
use KonradMichalik\Typo3Letterbox\Domain\Model\{Address, Attachment, Mail, MailLink};
use KonradMichalik\Typo3Letterbox\Mbox\MessageIndexEntry;

use function count;

/**
 * MailJson.
 *
 * The JSON shape of a mail in the API, a public contract from its first release.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MailJson
{
    /**
     * @return array<string, mixed>
     */
    public function summary(Mail $mail): array
    {
        return self::shape($mail->id, $mail->messageId, $mail->date, $mail->subject, $mail->from, $mail->to, $mail->cc, count($mail->attachments), $mail->links);
    }

    /**
     * The same summary from the index, so a list never parses attachments. Only the links need the mail's bodies.
     *
     * @param list<MailLink> $links
     *
     * @return array<string, mixed>
     */
    public function entrySummary(MessageIndexEntry $entry, array $links): array
    {
        $from = '' === $entry->sender && '' === $entry->fromName ? null : new Address($entry->sender, $entry->fromName);

        return self::shape($entry->id, $entry->messageId, $entry->date, $entry->subject, $from, $entry->to, $entry->cc, $entry->attachmentCount, $links);
    }

    /**
     * The summary plus bodies, headers and attachment metadata. Attachment content is only served as a download.
     *
     * @return array<string, mixed>
     */
    public function detail(Mail $mail): array
    {
        return [
            ...$this->summary($mail),
            'text' => $mail->text,
            'html' => $mail->html,
            // An object even without headers, so clients can rely on the type.
            'headers' => (object) $mail->headers,
            'attachments' => array_map(
                static fn (Attachment $attachment): array => [
                    'index' => $attachment->index,
                    'filename' => $attachment->filename,
                    'mimeType' => $attachment->mimeType,
                    'size' => $attachment->size,
                    'contentId' => $attachment->contentId,
                ],
                $mail->attachments,
            ),
        ];
    }

    /**
     * @param list<Address>  $to
     * @param list<Address>  $cc
     * @param list<MailLink> $links
     *
     * @return array<string, mixed>
     */
    private static function shape(string $id, string $messageId, ?DateTimeImmutable $date, string $subject, ?Address $from, array $to, array $cc, int $attachmentCount, array $links): array
    {
        return [
            'id' => $id,
            'messageId' => $messageId,
            'date' => $date?->format(\DATE_ATOM),
            'subject' => $subject,
            'from' => null === $from ? null : self::address($from),
            'to' => array_map(self::address(...), $to),
            'cc' => array_map(self::address(...), $cc),
            'attachmentCount' => $attachmentCount,
            'links' => array_map(
                static fn (MailLink $link): array => ['url' => $link->url, 'isPasswordReset' => $link->isPasswordReset],
                $links,
            ),
        ];
    }

    /**
     * @return array{address: string, name: string}
     */
    private static function address(Address $address): array
    {
        return ['address' => $address->address, 'name' => $address->name];
    }
}
