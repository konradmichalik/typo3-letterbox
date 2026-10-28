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

use KonradMichalik\Typo3Letterbox\Domain\Model\{Address, Attachment, Mail, MailLink};

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
        return [
            'id' => $mail->id,
            'messageId' => $mail->messageId,
            'date' => $mail->date?->format(\DATE_ATOM),
            'subject' => $mail->subject,
            'from' => null === $mail->from ? null : self::address($mail->from),
            'to' => array_map(self::address(...), $mail->to),
            'cc' => array_map(self::address(...), $mail->cc),
            'attachmentCount' => count($mail->attachments),
            'links' => array_map(
                static fn (MailLink $link): array => ['url' => $link->url, 'isPasswordReset' => $link->isPasswordReset],
                $mail->links,
            ),
        ];
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
     * @return array{address: string, name: string}
     */
    private static function address(Address $address): array
    {
        return ['address' => $address->address, 'name' => $address->name];
    }
}
