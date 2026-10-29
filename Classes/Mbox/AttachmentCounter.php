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

use function count;
use function in_array;
use function strlen;

/**
 * AttachmentCounter.
 *
 * Counts attachments like mail-mime-parser's getAttachmentCount() from the part headers alone, without reading
 * part bodies line by line. It only answers for plainly structured mails and returns null for anything else,
 * so the caller can fall back to mail-mime-parser and the count stays the same.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class AttachmentCounter
{
    private const MAX_PARTS = 500;
    private const MAX_DEPTH = 10;
    private const TOKEN = '[A-Za-z0-9!#$%&\'*+.^_`|~-]+';

    private int $parts = 0;

    private function __construct(
        private readonly string $raw,
    ) {}

    /**
     * @return int|null null when the mail is not plain enough to be sure
     */
    public static function count(string $raw): ?int
    {
        // Stored mails always use LF, so CR anywhere means an unusual mail.
        if (str_contains($raw, "\r")) {
            return null;
        }

        return (new self($raw))->countRoot();
    }

    private function countRoot(): ?int
    {
        $bodyStart = $this->bodyStart(0, strlen($this->raw));
        $headers = null === $bodyStart ? null : $this->headers(0, $bodyStart);
        // Without both headers mail-mime-parser reads the mail as non-MIME and looks for uuencoded parts.
        if (null === $bodyStart || null === $headers || (!isset($headers['content-type']) && !isset($headers['mime-version']))) {
            return null;
        }

        return $this->countPart($headers, $bodyStart, strlen($this->raw), [], false, false);
    }

    /**
     * @param array<string, string> $headers    lowercase name => unfolded value of the first occurrence
     * @param list<string>          $boundaries the boundaries of all enclosing multiparts, innermost last
     */
    private function countPart(array $headers, int $bodyStart, int $end, array $boundaries, bool $inAttachedMultipart, bool $isSignature): ?int
    {
        $type = self::mainValue($headers['content-type'] ?? 'text/plain', true);
        $disposition = self::mainValue($headers['content-disposition'] ?? 'inline', false);
        if (null === $type || null === $disposition || ++$this->parts > self::MAX_PARTS || count($boundaries) > self::MAX_DEPTH) {
            return null;
        }
        $isAttachment = 'attachment' === $disposition;
        if (str_starts_with($type, 'multipart/')) {
            return $this->countMultipart($type, $headers['content-type'] ?? '', $bodyStart, $end, $boundaries, $inAttachedMultipart, $isAttachment);
        }
        $isInlineText = in_array($type, ['text/plain', 'text/html'], true) && !$isAttachment;

        return $inAttachedMultipart || $isInlineText || $isSignature ? 0 : 1;
    }

    /**
     * @param list<string> $boundaries
     */
    private function countMultipart(string $type, string $contentType, int $bodyStart, int $end, array $boundaries, bool $inAttachedMultipart, bool $isAttachment): ?int
    {
        $boundary = self::boundary($contentType);
        $children = null === $boundary || in_array($boundary, $boundaries, true) ? null : $this->children($boundary, $bodyStart, $end);
        if (null === $boundary || null === $children) {
            return null;
        }

        // Only the second part of a signed message itself is its signature (IMessage::getSignaturePart()).
        $signatureIndex = [] === $boundaries && 'multipart/signed' === $type ? 1 : null;
        $total = $isAttachment && !$inAttachedMultipart ? 1 : 0;
        foreach ($children as $index => [$childStart, $childEnd]) {
            $count = $this->countChild($childStart, $childEnd, [...$boundaries, $boundary], $inAttachedMultipart || $isAttachment, $index === $signatureIndex);
            if (null === $count) {
                return null;
            }
            $total += $count;
        }

        return $total;
    }

    /**
     * @param list<string> $boundaries
     */
    private function countChild(int $start, int $end, array $boundaries, bool $inAttachedMultipart, bool $isSignature): ?int
    {
        $bodyStart = $this->bodyStart($start, $end);
        $headers = null === $bodyStart ? null : $this->headers($start, $bodyStart);

        return null === $bodyStart || null === $headers ? null : $this->countPart($headers, $bodyStart, $end, $boundaries, $inAttachedMultipart, $isSignature);
    }

    /**
     * The content ranges between the delimiter lines, the line break before a delimiter belongs to the delimiter.
     *
     * @return list<array{int, int}>|null null without a closing delimiter
     */
    private function children(string $boundary, int $start, int $end): ?array
    {
        $delimiter = "\n--".$boundary;
        $children = [];
        $childStart = null;
        // The body starts right after a line break, so a delimiter on its first line is found as well.
        $position = $start - 1;
        while (false !== ($found = strpos($this->raw, $delimiter, $position)) && $found < $end) {
            $lineEnd = strpos($this->raw, "\n", $found + 1);
            $lineEnd = false === $lineEnd || $lineEnd > $end ? $end : $lineEnd;
            // RFC 2046 allows transport padding after a delimiter, and mail-mime-parser ignores it since 4.0.8.
            $rest = rtrim(substr($this->raw, $found + strlen($delimiter), $lineEnd - $found - strlen($delimiter)), " \t");
            $position = $lineEnd;
            if ('' !== $rest && '--' !== $rest) {
                continue;
            }
            if (null !== $childStart) {
                $children[] = [$childStart, $found];
            }
            if ('--' === $rest) {
                return $children;
            }
            $childStart = min($lineEnd + 1, $end);
        }

        return null;
    }

    /**
     * @return int|null the offset after the blank line that ends the header block, null without one in the range
     */
    private function bodyStart(int $start, int $end): ?int
    {
        if ($start < $end && "\n" === $this->raw[$start]) {
            return $start + 1;
        }
        $blank = strpos($this->raw, "\n\n", $start);

        return false === $blank || $blank + 2 > $end ? null : $blank + 2;
    }

    /**
     * @return array<string, string>|null
     */
    private function headers(int $start, int $end): ?array
    {
        $block = preg_replace('/\n[ \t]+/', ' ', substr($this->raw, $start, $end - $start)) ?? '';
        $headers = [];
        foreach (explode("\n", $block) as $line) {
            if ('' === $line) {
                continue;
            }
            if (1 !== preg_match('/^([!-9;-~]+):(.*)$/D', $line, $match)) {
                return null;
            }
            $headers[strtolower($match[1])] ??= trim($match[2]);
        }

        return $headers;
    }

    /**
     * The lowercase value before the parameters, null when it is not a plain token (comments, quotes, encoded words).
     */
    private static function mainValue(string $value, bool $isType): ?string
    {
        $main = trim(explode(';', $value, 2)[0]);
        $pattern = $isType ? '/^'.self::TOKEN.'\/'.self::TOKEN.'$/D' : '/^'.self::TOKEN.'$/D';

        return 1 === preg_match($pattern, $main) ? strtolower($main) : null;
    }

    private static function boundary(string $contentType): ?string
    {
        $parameters = explode(';', $contentType, 2)[1] ?? '';
        if (str_contains($parameters, '(') || 1 === preg_match('/boundary\s*\*/i', $parameters) || 1 !== preg_match_all('/(?:^|;)\s*boundary\s*=/i', $parameters)) {
            return null;
        }
        if (1 === preg_match('/(?:^|;)\s*boundary\s*=\s*"([^"\\\\]+)"\s*(?:;|$)/i', $parameters, $match)) {
            return $match[1];
        }

        return 1 === preg_match('/(?:^|;)\s*boundary\s*=\s*('.self::TOKEN.')\s*(?:;|$)/i', $parameters, $match) ? $match[1] : null;
    }
}
