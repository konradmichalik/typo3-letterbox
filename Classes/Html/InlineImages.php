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

use DOMElement;
use DOMNode;
use KonradMichalik\Typo3Letterbox\Domain\Model\{Attachment, Mail};

use function array_slice;
use function count;
use function in_array;
use function sprintf;

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

    /**
     * Points cid: references at their inline image parts. An image referenced more than once is encoded once into the
     * returned stylesheet, as the background of a placeholder with the same intrinsic size, so a logo repeated a
     * thousand times does not repeat its bytes. Images with their own background keep the data URI as source.
     *
     * @param list<DOMNode> $nodes
     *
     * @return string the stylesheet for the head, empty when no image needs it
     */
    public function replaceCidSources(array $nodes, Mail $mail): string
    {
        $byId = $this->byId($mail);
        $references = [];
        foreach ([] === $byId ? [] : $nodes as $element) {
            $id = $element instanceof DOMElement ? $this->contentId($element->getAttribute('src')) : null;
            if (null !== $id && isset($byId[$id])) {
                $references[$id][] = $element;
            }
        }

        $css = '';
        $shared = 0;
        foreach ($references as $id => $elements) {
            $rule = $this->replace($elements, $byId[$id], self::CLASS_PREFIX.$shared);
            if ('' !== $rule) {
                $css .= $rule;
                ++$shared;
            }
        }

        return $css;
    }

    /**
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

        return array_slice(array_map(strval(...), array_keys($unresolved)), 0, self::MAX_UNRESOLVED);
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
     * @param non-empty-list<DOMElement> $elements
     *
     * @return string the rule for $class, empty when the image is not shared
     */
    private function replace(array $elements, Attachment $attachment, string $class): string
    {
        $content = $attachment->content();
        $uri = 'data:'.$attachment->mimeType.';base64,'.base64_encode($content);
        $shared = array_filter($elements, static fn (DOMElement $element): bool => 'img' === strtolower($element->tagName) && !str_contains(strtolower($element->getAttribute('style')), 'background'));
        if (count($elements) < 2 || [] === $shared) {
            foreach ($elements as $element) {
                $element->setAttribute('src', $uri);
            }

            return '';
        }

        $placeholder = self::placeholder($attachment->mimeType, $content);
        foreach ($elements as $element) {
            if (!in_array($element, $shared, true)) {
                $element->setAttribute('src', $uri);
                continue;
            }
            // An image no browser could decode stays broken, without its bytes.
            $element->setAttribute('src', $placeholder ?? 'data:'.$attachment->mimeType.';base64,');
            if (null !== $placeholder) {
                $element->setAttribute('class', trim($element->getAttribute('class').' '.$class));
            }
        }

        return null === $placeholder ? '' : 'img.'.$class.'{background:url("'.$uri.'") no-repeat 0 0/100% 100% content-box!important}';
    }

    /**
     * An empty SVG with the intrinsic size of the image, so width, height and aspect ratio lay out as with the image.
     */
    private static function placeholder(string $mimeType, string $content): ?string
    {
        if (1 === preg_match('#^image/svg\+xml$#iD', $mimeType)) {
            if (1 !== preg_match('/<svg\b([^>]*)>/i', $content, $root)) {
                return null;
            }
            preg_match_all('/\s(width|height|viewBox)\s*=\s*(["\'])([\w.%\s,-]*)\2/i', $root[1], $matches, \PREG_SET_ORDER);
            $attributes = implode('', array_map(static fn (array $match): string => ' '.$match[1].'="'.$match[3].'"', $matches));
        } else {
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
            $attributes = sprintf(' width="%d" height="%d"', $info[0], $info[1]);
        }

        return 'data:image/svg+xml,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg"'.$attributes.'/>');
    }

    private function contentId(string $src): ?string
    {
        return str_starts_with(strtolower($src), 'cid:') ? trim(rawurldecode(substr($src, 4)), '<>') : null;
    }
}
