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
use DOMNode;
use DOMXPath;
use KonradMichalik\Typo3Letterbox\Domain\Model\{Attachment, Mail};

use function array_slice;
use function count;
use function sprintf;
use function strlen;

/**
 * InlineImages.
 *
 * Resolves cid: image references against the inline image parts of a mail. Matching is strict (RFC 2392).
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class InlineImages
{
    private const MAX_UNRESOLVED = 20;
    private const INLINE_IMAGE_PATTERN = '#^image/(png|jpe?g|gif|webp|svg\+xml)$#iD';
    private const CLASS_PREFIX = 'letterbox-cid-';
    private const REPEATED_COPY_BYTES = 5242880;

    /**
     * Points cid: references at their inline image parts. A raster image referenced more than once is encoded once
     * into the returned stylesheet, as the background of a placeholder with the same intrinsic size, so a logo repeated
     * a thousand times does not repeat its bytes. Other repeated images get their data URI on every reference until
     * those copies reach REPEATED_COPY_BYTES, later references stay unresolved.
     *
     * @param list<DOMNode> $nodes
     *
     * @return string the stylesheet for the head, empty when no image needs it
     */
    public function replaceCidSources(array $nodes, Mail $mail): string
    {
        [$sources, $css] = $this->plan($nodes, $mail);
        foreach ($sources as [$element, $src, $class]) {
            if (null === $src) {
                continue;
            }
            $element->setAttribute('src', $src);
            if (null !== $class) {
                $element->setAttribute('class', trim($element->getAttribute('class').' '.$class));
            }
        }

        return $css;
    }

    /**
     * Content IDs without an inline image part, and those left out by the cap on repeated copies.
     * Capped so a hostile mail cannot flood the callout.
     *
     * @param list<DOMNode> $nodes
     *
     * @return list<string>
     */
    public function unresolvedContentIds(array $nodes, Mail $mail): array
    {
        $resolvable = $this->byId($mail);
        $unresolved = [];
        foreach ($nodes as $element) {
            $id = $element instanceof DOMElement ? $this->contentId($element->getAttribute('src')) : null;
            if (null !== $id && !isset($resolvable[$id])) {
                $unresolved[$id] = true;
            }
        }
        foreach ($this->plan($nodes, $mail)[0] as [$element, $src]) {
            if (null === $src) {
                $unresolved[(string) $this->contentId($element->getAttribute('src'))] = true;
            }
        }

        return array_slice(array_map(strval(...), array_keys($unresolved)), 0, self::MAX_UNRESOLVED);
    }

    /**
     * @param list<DOMNode> $nodes
     *
     * @return array{list<array{DOMElement, ?string, ?string}>, string} per reference its source (null: left unresolved) and class, and the stylesheet
     */
    private function plan(array $nodes, Mail $mail): array
    {
        $sources = [];
        $css = '';
        $budget = self::REPEATED_COPY_BYTES;
        foreach ($this->references($nodes, $this->byId($mail)) as [$elements, $attachment]) {
            $uri = 'data:'.$attachment->mimeType.';base64,'.base64_encode($attachment->content());
            if (1 === count($elements)) {
                $sources[] = [$elements[0], $uri, null];
                continue;
            }
            // Every rule holds one opening brace, so this numbers the shared images.
            $class = self::CLASS_PREFIX.substr_count($css, '{');
            [$repeated, $copies] = $this->repeated($elements, $uri, self::placeholder($attachment->content()), $class, $budget);
            $sources = [...$sources, ...$repeated];
            $budget -= $copies;
            $isShared = [] !== array_filter($repeated, static fn (array $source): bool => null !== $source[2]);
            $css .= $isShared ? 'img.'.$class.'{background:url("'.$uri.'") no-repeat 0 0/100% 100% content-box!important}' : '';
        }

        return [$sources, $css];
    }

    /**
     * @param list<DOMNode>             $nodes
     * @param array<string, Attachment> $byId
     *
     * @return list<array{non-empty-list<DOMElement>, Attachment}> per referenced image its elements, in document order
     */
    private function references(array $nodes, array $byId): array
    {
        $references = [];
        foreach ([] === $byId ? [] : $nodes as $element) {
            $id = $element instanceof DOMElement ? $this->contentId($element->getAttribute('src')) : null;
            if (null !== $id && isset($byId[$id])) {
                $references[$id][0][] = $element;
                $references[$id][1] = $byId[$id];
            }
        }

        return array_values($references);
    }

    /**
     * @param non-empty-list<DOMElement> $elements
     *
     * @return array{list<array{DOMElement, ?string, ?string}>, int} the sources and the bytes of data URI copies used
     */
    private function repeated(array $elements, string $uri, ?string $placeholder, string $class, int $budget): array
    {
        $sources = [];
        $copies = 0;
        foreach ($elements as $element) {
            if (null !== $placeholder && self::canShare($element)) {
                $sources[] = [$element, $placeholder, $class];
                continue;
            }
            $fits = $copies + strlen($uri) <= $budget;
            $copies += $fits ? strlen($uri) : 0;
            $sources[] = [$element, $fits ? $uri : null, null];
        }

        return [$sources, $copies];
    }

    /**
     * @return array<string, Attachment>
     */
    private function byId(Mail $mail): array
    {
        $byId = [];
        foreach ($mail->attachments as $attachment) {
            $id = trim($attachment->contentId, '<>');
            if ('' !== $id && 1 === preg_match(self::INLINE_IMAGE_PATTERN, $attachment->mimeType)) {
                $byId[$id] = $attachment;
            }
        }

        return $byId;
    }

    /**
     * An own background would hide the shared one.
     */
    private static function canShare(DOMElement $element): bool
    {
        $style = strtolower($element->getAttribute('style'));

        return 'img' === strtolower($element->tagName) && !str_contains($style, 'background') && !str_contains($style, 'object-') && !self::sheetsUseObjectFit($element);
    }

    /**
     * A stretched background cannot follow object-fit or object-position, so a mail that sets them keeps its data URIs.
     */
    private static function sheetsUseObjectFit(DOMElement $element): bool
    {
        return (new DOMXPath($element->ownerDocument ?? new DOMDocument()))->evaluate('boolean(//*[local-name()="style"][contains(translate(., "OBJECT", "object"), "object-")])');
    }

    /**
     * An empty SVG with the pixel size of a raster image, so width, height and aspect ratio lay out as with the image.
     * Null for SVG and anything PHP cannot read the size of.
     */
    private static function placeholder(string $content): ?string
    {
        // A broken or truncated image raises a notice, it then has no size.
        set_error_handler(static fn (): bool => true);
        try {
            $info = '' === $content ? false : getimagesizefromstring($content);
        } finally {
            restore_error_handler();
        }
        if (false === $info || $info[0] < 1 || $info[1] < 1) {
            return null;
        }

        return 'data:image/svg+xml,'.rawurlencode(sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%d" height="%d"/>', $info[0], $info[1]));
    }

    private function contentId(string $src): ?string
    {
        return str_starts_with(strtolower($src), 'cid:') ? trim(rawurldecode(substr($src, 4)), '<>') : null;
    }
}
