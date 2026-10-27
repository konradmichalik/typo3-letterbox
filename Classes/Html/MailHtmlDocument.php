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

namespace KonradMichalik\Typo3Letterbox\Html;

use DOMDocument;
use DOMElement;
use KonradMichalik\Typo3Letterbox\Domain\Model\Mail;
use Masterminds\HTML5;

use function in_array;

/**
 * MailHtmlDocument.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MailHtmlDocument
{
    private const CSP = "default-src 'none'; img-src data:; style-src 'unsafe-inline'";
    private const INLINE_IMAGE_PATTERN = '#^image/(png|jpe?g|gif|webp|svg\+xml)$#i';

    public function build(Mail $mail): string
    {
        if ('' === trim($mail->html)) {
            return '';
        }

        $html5 = new HTML5();
        $document = $html5->loadHTML($mail->html);

        foreach ($this->elements($document, ['base', 'meta']) as $element) {
            if ('base' === $element->tagName || $this->isHttpEquiv($element, ['refresh', 'content-security-policy'])) {
                $element->parentNode?->removeChild($element);
            }
        }

        $this->inlineCidImages($document, $mail);

        $head = $document->getElementsByTagName('head')->item(0);
        if (null === $head) {
            $head = $document->createElement('head');
            $document->documentElement?->insertBefore($head, $document->documentElement->firstChild);
        }
        $csp = $document->createElement('meta');
        $csp->setAttribute('http-equiv', 'Content-Security-Policy');
        $csp->setAttribute('content', self::CSP);
        $head->insertBefore($csp, $head->firstChild);

        return $html5->saveHTML($document);
    }

    private function inlineCidImages(DOMDocument $document, Mail $mail): void
    {
        $images = [];
        foreach ($mail->attachments as $attachment) {
            $id = trim($attachment->contentId, '<>');
            if ('' !== $id && 1 === preg_match(self::INLINE_IMAGE_PATTERN, $attachment->mimeType)) {
                $images[$id] = 'data:'.$attachment->mimeType.';base64,'.base64_encode($attachment->content);
            }
        }
        if ([] === $images) {
            return;
        }

        foreach ($this->elements($document, ['*']) as $element) {
            $src = $element->getAttribute('src');
            if (!str_starts_with(strtolower($src), 'cid:')) {
                continue;
            }
            $id = trim(rawurldecode(substr($src, 4)), '<>');
            if (isset($images[$id])) {
                $element->setAttribute('src', $images[$id]);
            }
        }
    }

    /**
     * @param list<string> $tags
     *
     * @return list<DOMElement>
     */
    private function elements(DOMDocument $document, array $tags): array
    {
        $found = [];
        foreach ($tags as $tag) {
            foreach ($document->getElementsByTagName($tag) as $element) {
                $found[] = $element;
            }
        }

        return $found;
    }

    /**
     * @param list<string> $values
     */
    private function isHttpEquiv(DOMElement $element, array $values): bool
    {
        return in_array(strtolower(trim($element->getAttribute('http-equiv'))), $values, true);
    }
}
