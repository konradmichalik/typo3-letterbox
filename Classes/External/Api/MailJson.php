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

use KonradMichalik\Typo3Letterbox\Domain\Model\{Address, Attachment, Mail, MailFilter, MailLink};

use function count;
use function in_array;

/**
 * MailJson.
 *
 * The JSON shape of a mail in the API, a public contract from its first release. Under a scoped filter
 * (root Production) To and Cc list only the recipients in scope and the headers are left out.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MailJson
{
    /**
     * @return array<string, mixed>
     */
    public function summary(Mail $mail, MailFilter $filter): array
    {
        $visible = $filter->visibleRecipients($mail);
        $recipients = static fn (array $addresses): array => array_values(array_map(
            self::address(...),
            array_filter($addresses, static fn (Address $address): bool => in_array($address, $visible, true)),
        ));

        return [
            'id' => $mail->id,
            'messageId' => $mail->messageId,
            'date' => $mail->date?->format(\DATE_ATOM),
            'subject' => $mail->subject,
            'from' => null === $mail->from ? null : self::address($mail->from),
            'to' => $recipients($mail->to),
            'cc' => $recipients($mail->cc),
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
    public function detail(Mail $mail, MailFilter $filter): array
    {
        // An object even without headers, so clients can rely on the type.
        $headers = $filter->isScoped() ? [] : ['headers' => (object) $mail->headers];

        return [
            ...$this->summary($mail, $filter),
            'text' => $mail->text,
            'html' => $mail->html,
            ...$headers,
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
