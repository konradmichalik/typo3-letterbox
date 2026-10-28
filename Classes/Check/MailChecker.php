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

namespace KonradMichalik\Typo3Letterbox\Check;

use DOMDocument;
use DOMElement;
use KonradMichalik\Typo3Letterbox\Domain\Model\Mail;
use KonradMichalik\Typo3Letterbox\Html\MailHtmlDocument;

use function count;
use function strlen;

/**
 * MailChecker.
 *
 * Rule based checks of one mail. Nothing is fetched from the network: links and images are only read.
 * A HTML part over the parser limits yields the size rule and the text based rules, never an exception.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MailChecker
{
    private const GMAIL_CLIP_BYTES = 104448;
    private const EXCERPT_LENGTH = 120;
    private const DANGEROUS_TAGS = ['script', 'form', 'iframe', 'object', 'embed'];

    public function __construct(
        private MailHtmlDocument $htmlDocument = new MailHtmlDocument(),
    ) {}

    /**
     * @param list<string> $siteHosts lowercase host names of the TYPO3 sites, empty skips the From domain rule
     *
     * @return list<Finding> errors first, then warnings, then infos
     */
    public function check(Mail $mail, array $siteHosts): array
    {
        $document = $this->htmlDocument->parse($mail);
        $findings = [
            ...$this->htmlSize($mail),
            ...$this->missingText($mail),
            ...$this->httpLinks($mail),
            ...$this->fromNotSiteDomain($mail, $siteHosts),
            ...(null === $document ? [] : [
                ...$this->imagesWithoutAlt($document),
                ...$this->missingLang($mail, $document),
                ...$this->remoteImages($document),
                ...$this->dangerousElements($document),
            ]),
        ];
        usort($findings, static fn (Finding $a, Finding $b): int => $a->severity->rank() <=> $b->severity->rank());

        return $findings;
    }

    /**
     * @return list<Finding>
     */
    private function htmlSize(Mail $mail): array
    {
        return strlen($mail->html) > self::GMAIL_CLIP_BYTES ? [new Finding('html_size', Severity::Warning, 'check.html_size')] : [];
    }

    /**
     * @return list<Finding>
     */
    private function missingText(Mail $mail): array
    {
        return '' === trim($mail->text) ? [new Finding('missing_text', Severity::Warning, 'check.missing_text')] : [];
    }

    /**
     * @return list<Finding>
     */
    private function httpLinks(Mail $mail): array
    {
        $urls = array_values(array_filter(array_map(static fn ($link): string => $link->url, $mail->links), static fn (string $url): bool => 1 === preg_match('/^http:/i', $url)));

        return [] === $urls ? [] : [$this->counted('http_link', Severity::Warning, count($urls), $urls[0])];
    }

    /**
     * @param list<string> $siteHosts
     *
     * @return list<Finding>
     */
    private function fromNotSiteDomain(Mail $mail, array $siteHosts): array
    {
        $domain = null === $mail->from ? '' : strtolower(substr(strrchr($mail->from->address, '@') ?: '', 1));
        if ('' === $domain || [] === $siteHosts) {
            return [];
        }
        foreach ($siteHosts as $host) {
            $host = strtolower($host);
            if ($domain === $host || str_ends_with($domain, '.'.$host)) {
                return [];
            }
        }

        return [new Finding('from_not_site_domain', Severity::Warning, 'check.from_not_site_domain', '', [$domain])];
    }

    /**
     * @return list<Finding>
     */
    private function imagesWithoutAlt(DOMDocument $document): array
    {
        $images = array_values(array_filter($this->elements($document, 'img'), static fn (DOMElement $img): bool => !$img->hasAttribute('alt')));

        return [] === $images ? [] : [$this->counted('img_alt', Severity::Warning, count($images), $this->openingTag($images[0]))];
    }

    /**
     * @return list<Finding>
     */
    private function missingLang(Mail $mail, DOMDocument $document): array
    {
        // A fragment has no html element of its own, the parser only adds one.
        if (1 !== preg_match('/<html\b/i', $mail->html) || '' !== trim((string) $document->documentElement?->getAttribute('lang'))) {
            return [];
        }

        return [new Finding('missing_lang', Severity::Info, 'check.missing_lang')];
    }

    /**
     * @return list<Finding>
     */
    private function remoteImages(DOMDocument $document): array
    {
        $remote = array_values(array_filter($this->elements($document, 'img'), static fn (DOMElement $img): bool => 1 === preg_match('/^\s*https?:/i', $img->getAttribute('src'))));
        if ([] === $remote) {
            return [];
        }
        $tracking = count(array_filter($remote, $this->isTrackingPixel(...)));
        $total = count($remote);
        $excerpt = $this->excerpt($remote[0]->getAttribute('src'));

        return [match (true) {
            0 === $tracking => $this->counted('remote_image', Severity::Info, $total, $excerpt),
            1 === $total => new Finding('remote_image', Severity::Info, 'check.remote_image.one.tracking', $excerpt),
            default => new Finding('remote_image', Severity::Info, 'check.remote_image.other.tracking_'.(1 === $tracking ? 'one' : 'other'), $excerpt, 1 === $tracking ? [$total] : [$total, $tracking]),
        }];
    }

    /**
     * @return list<Finding>
     */
    private function dangerousElements(DOMDocument $document): array
    {
        $findings = [];
        foreach (self::DANGEROUS_TAGS as $tag) {
            $element = $this->elements($document, $tag)[0] ?? null;
            if (null !== $element) {
                $findings[] = new Finding('script_or_form', Severity::Error, 'check.script_or_form.'.$tag, $this->openingTag($element));
            }
        }

        return $findings;
    }

    private function isTrackingPixel(DOMElement $img): bool
    {
        if ($this->isTiny($img->getAttribute('width')) && $this->isTiny($img->getAttribute('height'))) {
            return true;
        }
        $style = $img->getAttribute('style');

        return 1 === preg_match('/(?:^|;)\s*width\s*:\s*[01](?:px)?\s*(?:;|$)/i', $style) && 1 === preg_match('/(?:^|;)\s*height\s*:\s*[01](?:px)?\s*(?:;|$)/i', $style);
    }

    private function isTiny(string $dimension): bool
    {
        return 1 === preg_match('/^\s*[01](?:px)?\s*$/i', $dimension);
    }

    private function counted(string $rule, Severity $severity, int $count, string $excerpt): Finding
    {
        return new Finding($rule, $severity, 'check.'.$rule.(1 === $count ? '.one' : '.other'), $this->excerpt($excerpt), 1 === $count ? [] : [$count]);
    }

    /**
     * @return list<DOMElement>
     */
    private function elements(DOMDocument $document, string $tag): array
    {
        return iterator_to_array($document->getElementsByTagName($tag), false);
    }

    private function openingTag(DOMElement $element): string
    {
        $attributes = '';
        foreach ($element->attributes ?? [] as $attribute) {
            $attributes .= ' '.$attribute->nodeName.'="'.$attribute->nodeValue.'"';
        }

        return $this->excerpt('<'.strtolower($element->tagName).$attributes.'>');
    }

    private function excerpt(string $value): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', $value));

        return mb_strlen($line) > self::EXCERPT_LENGTH ? mb_substr($line, 0, self::EXCERPT_LENGTH).'…' : $line;
    }
}
