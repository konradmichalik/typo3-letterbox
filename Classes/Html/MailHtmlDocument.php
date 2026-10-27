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

use DOMCdataSection;
use DOMComment;
use DOMDocument;
use DOMDocumentType;
use DOMElement;
use DOMNode;
use DOMProcessingInstruction;
use DOMXPath;
use KonradMichalik\Typo3Letterbox\Domain\Model\Mail;
use Masterminds\HTML5;
use Throwable;

use function in_array;
use function strlen;

/**
 * MailHtmlDocument.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MailHtmlDocument
{
    private const MAX_BYTES = 2097152;
    private const MAX_ELEMENTS = 50000;
    private const SHELL_PREFIX = '<!DOCTYPE html><html><head><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src data:; style-src \'unsafe-inline\'"><meta name="referrer" content="no-referrer"></head><body>';
    private const SHELL_SUFFIX = '</body></html>';
    private const INLINE_IMAGE_PATTERN = '#^image/(png|jpe?g|gif|webp|svg\+xml)$#iD';
    private const REMOVED_TAGS = ['script', 'xmp', 'plaintext', 'noembed', 'noframes', 'iframe', 'object', 'embed', 'link', 'base', 'meta'];
    private const WRAPPER_TAGS = ['html', 'head', 'body'];

    public function build(Mail $mail): string
    {
        if ('' === trim($mail->html) || strlen($mail->html) > self::MAX_BYTES) {
            return '';
        }

        try {
            $html5 = new HTML5();
            $document = $html5->loadHTML(1 === preg_match('/<(?:html|body)\b/i', $mail->html) ? $mail->html : '<body>'.$mail->html);

            if ($document->getElementsByTagName('*')->length > self::MAX_ELEMENTS) {
                return '';
            }

            foreach ($this->descendants($document) as $node) {
                $this->sanitize($node);
            }
            $this->inlineCidImages($document, $mail);

            return self::SHELL_PREFIX.$this->serializeContent($html5, $document).self::SHELL_SUFFIX;
        } catch (Throwable) {
            return '';
        }
    }

    private function sanitize(DOMNode $node): void
    {
        if ($node instanceof DOMProcessingInstruction || $node instanceof DOMCdataSection || $node instanceof DOMComment) {
            $node->parentNode?->removeChild($node);

            return;
        }
        if (!$node instanceof DOMElement) {
            return;
        }
        $tag = strtolower($node->tagName);
        if (in_array($tag, self::REMOVED_TAGS, true)) {
            $node->parentNode?->removeChild($node);

            return;
        }
        if ('a' === $tag && $node->hasAttribute('href')) {
            $node->setAttribute('target', '_blank');
            $node->setAttribute('rel', 'noopener noreferrer');

            return;
        }
        if ('style' !== $tag) {
            return;
        }
        if ($this->isForeignStyle($node)) {
            $node->parentNode?->removeChild($node);

            return;
        }
        foreach ($node->childNodes as $child) {
            $child->nodeValue = str_replace('<', '\\3C ', (string) $child->nodeValue);
        }
    }

    private function isForeignStyle(DOMElement $style): bool
    {
        if (null !== $style->namespaceURI && 'http://www.w3.org/1999/xhtml' !== $style->namespaceURI) {
            return true;
        }
        for ($ancestor = $style->parentNode; null !== $ancestor; $ancestor = $ancestor->parentNode) {
            if ($ancestor instanceof DOMElement && in_array(strtolower($ancestor->tagName), ['svg', 'math'], true)) {
                return true;
            }
        }

        return false;
    }

    private function serializeContent(HTML5 $html5, DOMDocument $document): string
    {
        [$styles, $content] = $this->collect($html5, $document);

        return $styles.$content;
    }

    /**
     * @return array{string, string} head styles and remaining content
     */
    private function collect(HTML5 $html5, DOMNode $parent): array
    {
        $styles = '';
        $content = '';
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof DOMDocumentType) {
                continue;
            }
            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), self::WRAPPER_TAGS, true)) {
                [$nestedStyles, $nestedContent] = $this->collect($html5, $child);
                $styles .= $nestedStyles;
                $content .= $nestedContent;
                continue;
            }
            if ($child instanceof DOMElement && 'style' === strtolower($child->tagName) && 'head' === strtolower($parent->nodeName)) {
                $styles .= $html5->saveHTML($child);
                continue;
            }
            $content .= $html5->saveHTML($child);
        }

        return [$styles, $content];
    }

    private function inlineCidImages(DOMDocument $document, Mail $mail): void
    {
        $byId = [];
        foreach ($mail->attachments as $attachment) {
            $id = trim($attachment->contentId, '<>');
            if ('' !== $id && 1 === preg_match(self::INLINE_IMAGE_PATTERN, $attachment->mimeType)) {
                $byId[$id] = $attachment;
            }
        }
        if ([] === $byId) {
            return;
        }

        foreach ($this->descendants($document) as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }
            $src = $element->getAttribute('src');
            if (!str_starts_with(strtolower($src), 'cid:')) {
                continue;
            }
            $attachment = $byId[trim(rawurldecode(substr($src, 4)), '<>')] ?? null;
            if (null !== $attachment) {
                $element->setAttribute('src', 'data:'.$attachment->mimeType.';base64,'.base64_encode($attachment->content));
            }
        }
    }

    /**
     * @return list<DOMNode>
     */
    private function descendants(DOMNode $root): array
    {
        $document = $root instanceof DOMDocument ? $root : $root->ownerDocument;
        $nodes = null === $document ? false : (new DOMXPath($document))->query('descendant::node()', $root);

        if (false === $nodes) {
            return [];
        }

        return array_values(array_filter(iterator_to_array($nodes, false), static fn (object $node): bool => $node instanceof DOMNode));
    }
}
