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
use WeakMap;

use function in_array;
use function strlen;

/**
 * MailHtmlDocument.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MailHtmlDocument
{
    private const MAX_BYTES = 2097152;
    private const MAX_ELEMENTS = 50000;
    private const IMG_SRC_BLOCKED = 'data:';
    // The only widening remote mode allows: images over HTTPS. No script, frame, connect or font sources, ever.
    private const IMG_SRC_REMOTE = 'data: https:';
    // Canvas in a light color scheme is white, so the frame never shows a transparent surface. The fade-in is
    // progressive enhancement: without the media query the content is visible at once.
    private const SHELL_STYLE = 'html{background-color:Canvas;color-scheme:light}@media (prefers-reduced-motion:no-preference){@keyframes letterbox-fade-in{from{opacity:0}}body{animation:letterbox-fade-in .2s ease-out}}';
    private const SHELL_SUFFIX = '</body></html>';
    private const REMOVED_TAGS = ['script', 'xmp', 'plaintext', 'noembed', 'noframes', 'iframe', 'object', 'embed', 'link', 'base', 'meta'];
    private const WRAPPER_TAGS = ['html', 'head', 'body'];

    /** @var WeakMap<Mail, array{HTML5, DOMDocument}|false> one parse per mail, shared by the read-only callers */
    private WeakMap $parsed;

    public function __construct(
        private InlineImages $inlineImages = new InlineImages(),
    ) {
        $this->parsed = new WeakMap();
    }

    /**
     * Changes the parsed document, so call it after the read-only methods: a later one parses the mail again.
     */
    public function build(Mail $mail, bool $allowRemoteImages = false): string
    {
        $loaded = $this->load($mail);
        unset($this->parsed[$mail]);
        if (null === $loaded) {
            return '';
        }
        [$html5, $document] = $loaded;

        try {
            foreach ($this->descendants($document) as $node) {
                $this->sanitize($node);
            }
            $imageStyles = $this->inlineImages->replaceCidSources($this->descendants($document), $mail);

            return $this->shellPrefix($allowRemoteImages, $imageStyles).$this->serializeContent($html5, $document).self::SHELL_SUFFIX;
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Whether build() yields a document, without sanitizing and serializing it.
     */
    public function canBuild(Mail $mail): bool
    {
        return null !== $this->load($mail);
    }

    /**
     * The parsed HTML part for read-only inspection, null under the same limits as build().
     */
    public function parse(Mail $mail): ?DOMDocument
    {
        return $this->load($mail)[1] ?? null;
    }

    /**
     * Content IDs of cid: references in the HTML part that no inline image part answers. Matching is strict
     * (RFC 2392), so the preview shows nothing for them. Capped so a hostile mail cannot flood the callout.
     *
     * @return list<string>
     */
    public function unresolvedContentIds(Mail $mail): array
    {
        $loaded = $this->load($mail);
        if (null === $loaded) {
            return [];
        }

        try {
            return $this->inlineImages->unresolvedContentIds($this->descendants($loaded[1]), $mail);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array{HTML5, DOMDocument}|null
     */
    private function load(Mail $mail): ?array
    {
        $this->parsed[$mail] ??= $this->parseHtml($mail) ?? false;

        return false === $this->parsed[$mail] ? null : $this->parsed[$mail];
    }

    /**
     * Parses the HTML part, or returns null when it is empty, too large, has too many elements or does not parse.
     *
     * @return array{HTML5, DOMDocument}|null
     */
    private function parseHtml(Mail $mail): ?array
    {
        if ('' === trim($mail->html) || strlen($mail->html) > self::MAX_BYTES) {
            return null;
        }

        try {
            $html5 = new HTML5();
            $document = $html5->loadHTML(1 === preg_match('/<(?:html|body)\b/i', $mail->html) ? $mail->html : '<body>'.$mail->html);
        } catch (Throwable) {
            return null;
        }

        return $document->getElementsByTagName('*')->length > self::MAX_ELEMENTS ? null : [$html5, $document];
    }

    private function shellPrefix(bool $allowRemoteImages, string $imageStyles): string
    {
        $imgSrc = $allowRemoteImages ? self::IMG_SRC_REMOTE : self::IMG_SRC_BLOCKED;
        $imageStyles = '' === $imageStyles ? '' : '<style>'.$imageStyles.'</style>';

        return '<!DOCTYPE html><html><head><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; img-src '.$imgSrc.'; style-src \'unsafe-inline\'"><meta name="referrer" content="no-referrer"><style>'.self::SHELL_STYLE.'</style>'.$imageStyles.'</head><body>';
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
        // SVG links carry their target in href or the older xlink:href.
        if (in_array($tag, ['a', 'area'], true) && ($node->hasAttribute('href') || $node->hasAttribute('xlink:href'))) {
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
