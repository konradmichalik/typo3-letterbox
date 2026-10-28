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

namespace KonradMichalik\Typo3Letterbox\Tests\Unit\Check;

use KonradMichalik\Typo3Letterbox\Check\{Finding, MailChecker, Severity};
use KonradMichalik\Typo3Letterbox\Domain\Model\{Address, Mail, MailLink};
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use PHPUnit\Framework\TestCase;

use function count;

/**
 * MailCheckerTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MailCheckerTest extends TestCase
{
    private const FULL_HTML = '<!DOCTYPE html><html lang="en"><head><title>t</title></head><body><p>Hi</p></body></html>';

    #[Test]
    public function cleanMailHasNoFindings(): void
    {
        self::assertSame([], $this->check($this->htmlMail(self::FULL_HTML)));
    }

    #[Test]
    public function htmlSizeWarnsOverTheGmailLimit(): void
    {
        $large = '<!DOCTYPE html><html lang="en"><body>'.str_repeat('a', 102 * 1024).'</body></html>';

        $finding = $this->only($this->check($this->htmlMail($large)), 'html_size');

        self::assertSame(Severity::Warning, $finding->severity);
        self::assertSame('check.html_size', $finding->message);
        self::assertNull($this->find($this->check($this->htmlMail(self::FULL_HTML)), 'html_size'));
    }

    #[Test]
    public function missingTextPartWarns(): void
    {
        $finding = $this->only($this->check($this->mail('  ', self::FULL_HTML, [], 'sender@example.org')), 'missing_text');

        self::assertSame(Severity::Warning, $finding->severity);
        self::assertNull($this->find($this->check($this->htmlMail(self::FULL_HTML)), 'missing_text'));
    }

    #[Test]
    public function imagesWithoutAltAreCountedWithFirstExcerpt(): void
    {
        $html = '<html lang="en"><body><img src="a.png"><img src="b.png" alt=""><img src="c.png"><img src="d.png" alt="D"></body></html>';

        $finding = $this->only($this->check($this->htmlMail($html)), 'img_alt');

        self::assertSame(Severity::Warning, $finding->severity);
        self::assertSame('check.img_alt.other', $finding->message);
        self::assertSame([2], $finding->arguments);
        self::assertSame('<img src="a.png">', $finding->excerpt);
        self::assertNull($this->find($this->check($this->htmlMail('<html lang="en"><body><img src="a.png" alt=""></body></html>')), 'img_alt'));
    }

    #[Test]
    public function oneImageWithoutAltUsesTheSingularKey(): void
    {
        $finding = $this->only($this->check($this->htmlMail('<html lang="en"><body><img src="a.png"></body></html>')), 'img_alt');

        self::assertSame('check.img_alt.one', $finding->message);
    }

    #[Test]
    public function missingLangOnHtmlElementIsInfo(): void
    {
        $finding = $this->only($this->check($this->htmlMail('<html><body><p>x</p></body></html>')), 'missing_lang');

        self::assertSame(Severity::Info, $finding->severity);
        self::assertNull($this->find($this->check($this->htmlMail(self::FULL_HTML)), 'missing_lang'));
        self::assertNull($this->find($this->check($this->htmlMail('<p>fragment</p>')), 'missing_lang'));
    }

    #[Test]
    public function httpLinksWarn(): void
    {
        $links = [new MailLink('http://example.org/a', false), new MailLink('https://example.org/b', false), new MailLink('HTTP://example.org/c', false)];

        $finding = $this->only($this->check($this->mail('Hello', '', $links, 'sender@example.org')), 'http_link');

        self::assertSame(Severity::Warning, $finding->severity);
        self::assertSame('check.http_link.other', $finding->message);
        self::assertSame([2], $finding->arguments);
        self::assertSame('http://example.org/a', $finding->excerpt);
        self::assertNull($this->find($this->check($this->mail('Hello', '', [new MailLink('https://example.org/b', false)], 'sender@example.org')), 'http_link'));
    }

    #[Test]
    public function remoteImagesAreInfoAndFlagTrackingPixels(): void
    {
        $html = '<html lang="en"><body><img src="https://cdn.example.net/a.png" alt=""><img src="http://t.example.net/p.gif" width="1" height="1" alt=""><img src="data:image/gif;base64,AAAA" alt=""><img src="cid:logo" alt=""></body></html>';

        $finding = $this->only($this->check($this->htmlMail($html)), 'remote_image');

        self::assertSame(Severity::Info, $finding->severity);
        self::assertSame('check.remote_image.other.tracking_one', $finding->message);
        self::assertSame([2], $finding->arguments);
        self::assertSame('https://cdn.example.net/a.png', $finding->excerpt);
    }

    #[Test]
    #[DataProvider('trackingPixels')]
    public function trackingPixelDetection(string $attributes, bool $tracking): void
    {
        $html = '<html lang="en"><body><img src="https://t.example.net/p.gif" alt="" '.$attributes.'></body></html>';

        $finding = $this->only($this->check($this->htmlMail($html)), 'remote_image');

        self::assertSame($tracking ? 'check.remote_image.one.tracking' : 'check.remote_image.one', $finding->message);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function trackingPixels(): iterable
    {
        yield 'attributes 1x1' => ['width="1" height="1"', true];
        yield 'attributes 0x0' => ['width="0" height="0"', true];
        yield 'attributes with px' => ['width="1px" height="1px"', true];
        yield 'style 1px' => ['style="width:1px;height:1px"', true];
        yield 'style spaced' => ['style="height : 1px; width : 1px;"', true];
        yield 'only width' => ['width="1"', false];
        yield 'banner' => ['width="600" height="1"', false];
        yield 'no size' => ['', false];
    }

    #[Test]
    #[DataProvider('dangerousTags')]
    public function dangerousElementsAreErrors(string $tag, string $markup): void
    {
        $finding = $this->only($this->check($this->htmlMail('<html lang="en"><body>'.$markup.'</body></html>')), 'script_or_form');

        self::assertSame(Severity::Error, $finding->severity);
        self::assertSame('check.script_or_form.'.$tag, $finding->message);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function dangerousTags(): iterable
    {
        yield 'script' => ['script', '<script>alert(1)</script>'];
        yield 'form' => ['form', '<form action="/x"><input></form>'];
        yield 'iframe' => ['iframe', '<iframe src="/x"></iframe>'];
        yield 'object' => ['object', '<object data="/x"></object>'];
        yield 'embed' => ['embed', '<embed src="/x">'];
    }

    #[Test]
    public function fromDomainOutsideTheSiteHostsWarns(): void
    {
        $finding = $this->only($this->check($this->mail('Hello', '', [], 'info@Example.net'), ['example.org']), 'from_not_site_domain');

        self::assertSame(Severity::Warning, $finding->severity);
        self::assertSame('check.from_not_site_domain', $finding->message);
        self::assertSame(['example.net'], $finding->arguments);
    }

    /**
     * @param list<string> $hosts
     */
    #[Test]
    #[DataProvider('matchingFromDomains')]
    public function fromDomainOfASiteIsAccepted(string $from, array $hosts): void
    {
        self::assertNull($this->find($this->check($this->mail('Hello', '', [], $from), $hosts), 'from_not_site_domain'));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function matchingFromDomains(): iterable
    {
        yield 'exact' => ['a@example.org', ['example.org']];
        yield 'case' => ['a@EXAMPLE.org', ['Example.Org']];
        yield 'subdomain of a site' => ['a@mail.example.org', ['example.org']];
        yield 'no sites' => ['a@example.net', []];
        yield 'no from' => ['', ['example.org']];
    }

    #[Test]
    public function lookalikeSuffixIsNotASubdomain(): void
    {
        self::assertNotNull($this->find($this->check($this->mail('Hello', '', [], 'a@notexample.org'), ['example.org']), 'from_not_site_domain'));
    }

    #[Test]
    public function findingsAreSortedBySeverity(): void
    {
        $html = '<html><body><img src="a.png"><form></form></body></html>';

        $severities = array_map(static fn (Finding $f): Severity => $f->severity, $this->check($this->mail('', $html, [], 'sender@example.org')));

        self::assertSame([Severity::Error, Severity::Warning, Severity::Warning, Severity::Info], $severities);
    }

    #[Test]
    public function noHtmlOnlyChecksText(): void
    {
        $findings = $this->check($this->mail('', '', [new MailLink('http://example.org', false)], 'a@example.net'), ['example.org']);

        self::assertEqualsCanonicalizing(['http_link', 'missing_text', 'from_not_site_domain'], array_map(static fn (Finding $f): string => $f->rule, $findings));
    }

    #[Test]
    public function hostileHtmlNeverThrows(): void
    {
        $tooLarge = '<html><body><img src="http://t.example.net/p.gif">'.str_repeat('a', 2 * 1024 * 1024).'</body></html>';
        $tooMany = '<html><body>'.str_repeat('<i></i>', 50001).'<form></form></body></html>';
        $deep = str_repeat('<div>', 20000).'<img src="x.png">'.str_repeat('</div>', 20000);
        $broken = '<html><body><img src="x.png" <<<>>> <form <script></div></table><td>';
        $binary = "<html>\0\xFF\xFE<img src=\"\x80\">";

        foreach ([$tooLarge, $tooMany, $deep, $broken, $binary, ''] as $html) {
            $findings = $this->check($this->htmlMail($html));
            self::assertLessThanOrEqual(9, count($findings));
        }
        $oversized = array_map(static fn (Finding $f): string => $f->rule, $this->check($this->htmlMail($tooLarge)));
        self::assertContains('html_size', $oversized);
        self::assertNotContains('remote_image', $oversized);
        self::assertNotContains('script_or_form', array_map(static fn (Finding $f): string => $f->rule, $this->check($this->htmlMail($tooMany))));
    }

    #[Test]
    public function excerptIsSingleLineAndTruncated(): void
    {
        $html = '<html lang="en"><body><img src="a.png" data-x="'.str_repeat("a\nb ", 100).'"></body></html>';

        $excerpt = $this->only($this->check($this->htmlMail($html)), 'img_alt')->excerpt;

        self::assertLessThanOrEqual(121, mb_strlen($excerpt));
        self::assertStringEndsWith('…', $excerpt);
        self::assertStringNotContainsString("\n", $excerpt);
    }

    /**
     * @param list<string> $siteHosts
     *
     * @return list<Finding>
     */
    private function check(Mail $mail, array $siteHosts = []): array
    {
        return (new MailChecker())->check($mail, $siteHosts);
    }

    /**
     * @param list<Finding> $findings
     */
    private function find(array $findings, string $rule): ?Finding
    {
        foreach ($findings as $finding) {
            if ($rule === $finding->rule) {
                return $finding;
            }
        }

        return null;
    }

    /**
     * @param list<Finding> $findings
     */
    private function only(array $findings, string $rule): Finding
    {
        $matches = array_values(array_filter($findings, static fn (Finding $f): bool => $rule === $f->rule));
        self::assertCount(1, $matches, $rule);

        return $matches[0];
    }

    private function htmlMail(string $html): Mail
    {
        return $this->mail('Hello', $html, [], 'sender@example.org');
    }

    /**
     * @param list<MailLink> $links
     */
    private function mail(string $text, string $html, array $links, string $from): Mail
    {
        return new Mail('id', '<id@example.org>', 'Subject', null, '' === $from ? null : new Address($from), [], [], [], [], $text, $html, [], [], 0, '', $links);
    }
}
