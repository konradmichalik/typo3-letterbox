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

    /**
     * @param list<DOMNode> $nodes
     */
    public function replaceCidSources(array $nodes, Mail $mail): void
    {
        $byId = $this->byId($mail);
        if ([] === $byId) {
            return;
        }

        foreach ($nodes as $element) {
            if (!$element instanceof DOMElement) {
                continue;
            }
            $id = $this->contentId($element->getAttribute('src'));
            $attachment = null === $id ? null : ($byId[$id] ?? null);
            if (null !== $attachment) {
                $element->setAttribute('src', 'data:'.$attachment->mimeType.';base64,'.base64_encode($attachment->content));
            }
        }
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

    private function contentId(string $src): ?string
    {
        return str_starts_with(strtolower($src), 'cid:') ? trim(rawurldecode(substr($src, 4)), '<>') : null;
    }
}
