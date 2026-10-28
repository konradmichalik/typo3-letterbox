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

namespace KonradMichalik\Typo3Letterbox\Tests\Functional\External;

use DateTimeImmutable;
use KonradMichalik\Typo3Letterbox\Domain\Model\{Mail, MailFilter};
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use KonradMichalik\Typo3Letterbox\External\{EnvironmentVariables, ExternalAccessGuard, ExternalResponses};
use KonradMichalik\Typo3Letterbox\External\Tester\{TesterInboxMiddleware, TesterInboxView, TesterSession};
use KonradMichalik\Typo3Letterbox\Html\MailHtmlDocument;
use KonradMichalik\Typo3Letterbox\Http\DownloadResponseFactory;
use KonradMichalik\Typo3Letterbox\Mail\LetterboxTransport;
use KonradMichalik\Typo3Letterbox\Mbox\{MailboxLock, MailboxWriter, MboxFormat};
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\{Environment, SystemEnvironmentBuilder};
use TYPO3\CMS\Core\Http\{NormalizedParams, Response, ResponseFactory, ServerRequest, StreamFactory};
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function dirname;

/**
 * TesterInboxMiddlewareTest.
 *
 * Stack tests run through the real frontend application like the API tests, the others call process()
 * on a middleware built with fixed environment values.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class TesterInboxMiddlewareTest extends FunctionalTestCase
{
    private const PAGE_CSP = "default-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'";

    private const ENV_NAMES = ['LETTERBOX_EXTERNAL_ACCESS', 'LETTERBOX_ACCESS_TOKEN'];

    private const TOKEN = 'example-access-token-0123456789abcdef';

    protected array $testExtensionsToLoad = [
        'typo3_letterbox',
    ];

    /** @var array<string, string|false> */
    private array $previousEnvironment = [];

    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ENV_NAMES as $name) {
            $this->previousEnvironment[$name] = getenv($name);
        }
        $this->path = Environment::getVarPath().'/letterbox-test/tester.mbox';
        GeneralUtility::mkdir_deep(dirname($this->path));
        if (is_file($this->path)) {
            unlink($this->path);
        }
        $mail = ['MAIL/transport' => LetterboxTransport::class, 'MAIL/transport_mbox_file' => $this->path];
        $this->get(ConfigurationManager::class)->setLocalConfigurationValuesByPathValuePairs($mail);
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = LetterboxTransport::class;
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_mbox_file'] = $this->path;
        $this->get(CacheManager::class)->getCache('typo3_letterbox_index')->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->previousEnvironment as $name => $value) {
            putenv(false === $value ? $name : $name.'='.$value);
        }
        if (is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    #[Test]
    public function tokenIsExchangedForCookieWith303(): void
    {
        putenv('LETTERBOX_EXTERNAL_ACCESS=Testing');
        putenv('LETTERBOX_ACCESS_TOKEN='.self::TOKEN);

        $response = $this->executeFrontendSubRequest(new InternalRequest('https://stage.example.org/_letterbox/inbox?t='.$this->linkSecret().'&p=2&q=x'));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/_letterbox/inbox', $response->getHeaderLine('Location'));
        self::assertMatchesRegularExpression('/^letterbox_inbox=\d+\.[0-9a-f]{64};/', $response->getHeaderLine('Set-Cookie'));
        self::assertSame('no-store, private', $response->getHeaderLine('Cache-Control'));
        self::assertSame(self::PAGE_CSP, $response->getHeaderLine('Content-Security-Policy'));
    }

    #[Test]
    public function inboxCookieIsDerivedFromTheToken(): void
    {
        $response = $this->send($this->middleware(), '/_letterbox/inbox?t='.$this->linkSecret());

        self::assertSame(303, $response->getStatusCode());
        $attributes = $this->cookieAttributes($response);
        self::assertMatchesRegularExpression('/^\d+\.[0-9a-f]{64}$/D', $attributes['letterbox_inbox']);
        [$issued, $signature] = explode('.', $attributes['letterbox_inbox'], 2);
        self::assertEqualsWithDelta(time(), (int) $issued, 5, 'the cookie carries its issue time');
        self::assertSame(hash_hmac('sha256', 'inbox|'.$issued, self::TOKEN), $signature);
        self::assertStringNotContainsString(self::TOKEN, $response->getHeaderLine('Set-Cookie'));
        self::assertStringNotContainsString($this->linkSecret(), $response->getHeaderLine('Set-Cookie'));
        self::assertEqualsWithDelta(time() + 7 * 86400, strtotime($attributes['expires']), 5);
        self::assertSame(200, $this->send($this->middleware(), '/_letterbox/inbox', ['letterbox_inbox' => $attributes['letterbox_inbox']])->getStatusCode());
        self::assertSame(404, $this->send($this->middleware(), '/_letterbox/inbox', ['letterbox_inbox' => self::TOKEN])->getStatusCode(), 'the raw token is no cookie');
    }

    #[Test]
    public function rawTokenIsNoTesterLink(): void
    {
        $middleware = $this->middleware();

        $response = $this->send($middleware, '/_letterbox/inbox?t='.self::TOKEN);

        self::assertSame(404, $response->getStatusCode(), 'the API credential must not open the inbox');
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertSame(404, $this->send($middleware, '/_letterbox/inbox?t='.strtoupper($this->linkSecret()))->getStatusCode());
        self::assertSame(404, $this->send($middleware, '/_letterbox/inbox?t='.hash_hmac('sha256', 'tester-link', str_repeat('b', 40)))->getStatusCode());
    }

    #[Test]
    public function expiredOrTamperedCookieIsRefused(): void
    {
        $this->append('In scope', 'anna@example.org');
        $middleware = $this->middleware();
        $old = time() - 7 * 86400 - 60;

        self::assertSame(200, $this->send($middleware, '/_letterbox/inbox', ['letterbox_inbox' => $this->sessionValue(time() - 6 * 86400)])->getStatusCode());
        $this->assertClearedCookie($this->send($middleware, '/_letterbox/inbox', ['letterbox_inbox' => $this->sessionValue($old)]));
        $signature = hash_hmac('sha256', 'inbox|'.$old, self::TOKEN);
        $this->assertClearedCookie($this->send($middleware, '/_letterbox/inbox', ['letterbox_inbox' => time().'.'.$signature]), 'a fresh time with an old signature');
        $this->assertClearedCookie($this->send($middleware, '/_letterbox/inbox', ['letterbox_inbox' => hash_hmac('sha256', 'inbox', self::TOKEN)]), 'the old constant value');
        $this->assertClearedCookie($this->send($middleware, '/_letterbox/inbox', ['letterbox_inbox' => (time() + 3600).'.'.hash_hmac('sha256', 'inbox|'.(time() + 3600), self::TOKEN)]), 'issued in the future');
    }

    #[Test]
    public function changedTokenInvalidatesCookie(): void
    {
        $this->append('In scope', 'anna@example.org');
        $cookie = $this->cookie();
        $changed = $this->middleware(str_repeat('b', 40));

        self::assertSame(200, $this->send($this->middleware(), '/_letterbox/inbox', $cookie)->getStatusCode());
        $this->assertClearedCookie($this->send($changed, '/_letterbox/inbox', $cookie));
        self::assertSame(404, $this->send($changed, '/_letterbox/inbox?t='.$this->linkSecret())->getStatusCode());
    }

    #[Test]
    public function cookieAttributesAreLax(): void
    {
        $response = $this->send($this->middleware(), '/_letterbox/inbox?t='.$this->linkSecret());

        self::assertSame(303, $response->getStatusCode());
        $attributes = $this->cookieAttributes($response);
        self::assertSame('/_letterbox/inbox', $attributes['path']);
        self::assertArrayHasKey('secure', $attributes);
        self::assertArrayHasKey('httponly', $attributes);
        self::assertSame('lax', strtolower($attributes['samesite']), 'Lax, so the cookie survives the 303 after a click from another site');
        self::assertArrayNotHasKey('domain', $attributes);
    }

    #[Test]
    public function noCookieIs404(): void
    {
        $this->append('In scope', 'anna@example.org');
        putenv('LETTERBOX_EXTERNAL_ACCESS=Testing');
        putenv('LETTERBOX_ACCESS_TOKEN='.self::TOKEN);

        $response = $this->executeFrontendSubRequest(new InternalRequest('https://stage.example.org/_letterbox/inbox'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(self::PAGE_CSP, $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertStringNotContainsString('In scope', (string) $response->getBody());

        $middleware = $this->middleware();
        self::assertSame(404, $this->send($middleware, '/_letterbox/inbox/', $this->cookie())->getStatusCode(), 'no trailing slash');
        self::assertSame(404, $this->send($middleware, '/_letterbox/inbox', $this->cookie(), 'POST')->getStatusCode());
        self::assertSame(404, $this->send($middleware, '/_letterbox/inbox?t=wrong')->getStatusCode());
        self::assertSame(404, $this->send($middleware, '/_letterbox/inbox?t[]='.$this->linkSecret())->getStatusCode());
        self::assertSame('1', $this->send($middleware, '/_letterbox/inboxes')->getHeaderLine('X-Passed-Through'));
        self::assertSame('1', $this->send($middleware, '/_letterbox')->getHeaderLine('X-Passed-Through'));
    }

    #[Test]
    public function wrongCookieClearsCookie(): void
    {
        $this->append('In scope', 'anna@example.org');

        $this->assertClearedCookie($this->send($this->middleware(), '/_letterbox/inbox', ['letterbox_inbox' => str_repeat('a', 64)]));
    }

    #[Test]
    public function listShowsAllMailsWithAllRecipients(): void
    {
        $this->append('Welcome Anna', 'anna@example.org, "Bob" <bob@other.test>');
        $this->append('For Carl', 'carl@other.test');

        $response = $this->send($this->middleware(), '/_letterbox/inbox', $this->cookie());
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        self::assertSame(self::PAGE_CSP, $response->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString('<html lang="en"', $body);
        self::assertStringContainsString('<main', $body);
        self::assertStringContainsString('Caught mails', $body);
        self::assertStringContainsString('Welcome Anna', $body);
        self::assertStringContainsString('anna@example.org, bob@other.test', $body);
        self::assertStringContainsString('For Carl', $body);
        self::assertStringContainsString('carl@other.test', $body);
        self::assertStringNotContainsString(self::TOKEN, $body);
        self::assertStringNotContainsString($this->sessionValue(), $body);
        self::assertSame(2, preg_match_all('#href="/_letterbox/inbox/[0-9a-f]{32}"#', $body));
        self::assertStringContainsString('typo3_letterbox/Resources/Public/Css/tester.css', $body);
    }

    #[Test]
    public function searchAndPaging(): void
    {
        for ($i = 1; $i <= 25; ++$i) {
            $this->append('Numbered '.$i, 'anna@example.org, bob@other.test');
        }
        $this->append('Other match', 'carl@other.test');
        $cookie = $this->cookie();
        $middleware = $this->middleware();

        self::assertSame(20, $this->rows($this->send($middleware, '/_letterbox/inbox', $cookie)));
        self::assertSame(6, $this->rows($this->send($middleware, '/_letterbox/inbox?p=2', $cookie)));
        self::assertSame(20, $this->rows($this->send($middleware, '/_letterbox/inbox?p=abc', $cookie)));
        self::assertSame(20, $this->rows($this->send($middleware, '/_letterbox/inbox?p[]=2', $cookie)));
        self::assertSame(1, $this->rows($this->send($middleware, '/_letterbox/inbox?q=match', $cookie)));
        self::assertSame(1, $this->rows($this->send($middleware, '/_letterbox/inbox?q=carl%40other', $cookie)));
        self::assertSame(20, $this->rows($this->send($middleware, '/_letterbox/inbox?q=bob%40other', $cookie)), 'every recipient is searchable');
        self::assertSame(1, $this->rows($this->send($middleware, '/_letterbox/inbox?q=Numbered+25', $cookie)));
        self::assertSame(20, $this->rows($this->send($middleware, '/_letterbox/inbox?q[]=match', $cookie)));
        self::assertSame(0, $this->rows($this->send($middleware, '/_letterbox/inbox?q=Numbered+2'.str_repeat('x', 200), $cookie)), 'the query is capped, not dropped');
        $paged = (string) $this->send($middleware, '/_letterbox/inbox?q=Numbered&p=1', $cookie)->getBody();
        self::assertStringContainsString('href="/_letterbox/inbox?q=Numbered&amp;p=2"', $paged);
    }

    #[Test]
    public function subDirectoryCookiePath(): void
    {
        $middleware = $this->middleware();
        $server = ['SCRIPT_NAME' => '/sub/index.php'];

        $response = $this->send($middleware, '/sub/_letterbox/inbox?t='.$this->linkSecret(), [], 'GET', $server);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/sub/_letterbox/inbox', $response->getHeaderLine('Location'));
        self::assertSame('/sub/_letterbox/inbox', $this->cookieAttributes($response)['path']);
        self::assertSame('1', $this->send($middleware, '/_letterbox/inbox?t='.$this->linkSecret(), [], 'GET', $server)->getHeaderLine('X-Passed-Through'));
        $list = $this->send($middleware, '/sub/_letterbox/inbox', $this->cookie(), 'GET', $server);
        self::assertSame(200, $list->getStatusCode());
        self::assertStringContainsString('action="/sub/_letterbox/inbox"', (string) $list->getBody());
    }

    #[Test]
    public function inactiveSurfaceIs404EvenWithValidCookie(): void
    {
        $this->append('In scope', 'anna@example.org');
        putenv('LETTERBOX_ACCESS_TOKEN='.self::TOKEN);

        $response = $this->executeFrontendSubRequest(
            (new InternalRequest('https://stage.example.org/_letterbox/inbox'))->withCookieParams($this->cookie()),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(self::PAGE_CSP, $response->getHeaderLine('Content-Security-Policy'));
        self::assertStringNotContainsString('In scope', (string) $response->getBody());

        putenv('LETTERBOX_EXTERNAL_ACCESS=Testing');
        $active = $this->executeFrontendSubRequest(
            (new InternalRequest('https://stage.example.org/_letterbox/inbox'))->withCookieParams($this->cookie()),
        );
        self::assertSame(200, $active->getStatusCode());
        self::assertStringContainsString('In scope', (string) $active->getBody());
    }

    #[Test]
    public function invalidDetailPathsAre404(): void
    {
        $first = $this->append('First', 'anna@example.org');
        $other = $this->append('For Carl', 'carl@other.test');
        $middleware = $this->middleware();

        $detail = $this->send($middleware, '/_letterbox/inbox/'.$other, $this->cookie());
        self::assertSame(200, $detail->getStatusCode());
        self::assertSame(self::PAGE_CSP, $detail->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString('For Carl', (string) $detail->getBody());

        foreach ([str_repeat('0', 32), strtoupper($first), $first.'/', $first.'/extra', $first.'/attachments', $first.'/delete'] as $path) {
            $response = $this->send($middleware, '/_letterbox/inbox/'.$path, $this->cookie());
            self::assertSame(404, $response->getStatusCode(), $path);
            self::assertStringContainsString('Page not found', (string) $response->getBody());
        }
        self::assertSame(404, $this->send($middleware, '/_letterbox/inbox/'.$first, [])->getStatusCode());
        self::assertSame(404, $this->send($middleware, '/_letterbox/inbox/'.$first, $this->cookie(), 'POST')->getStatusCode());
        self::assertSame(404, $this->send($middleware, '/_letterbox/inbox/'.$first, $this->cookie(), 'DELETE')->getStatusCode());
    }

    #[Test]
    public function resetLinkIsOfferedFirst(): void
    {
        $id = $this->appendRaw(
            "From: Example Site <no-reply@example.org>\nTo: anna@example.org\nSubject: Reset your password\nMessage-ID: <reset@example.org>\n"
            ."MIME-Version: 1.0\nContent-Type: text/plain; charset=utf-8\n\n"
            ."See https://example.org/about first.\nSet a new password: https://example.org/login?tx_felogin_login%5Baction%5D=showChangePassword&tx_felogin_login%5Bhash%5D=abc123\n",
        );

        $body = (string) $this->send($this->middleware(), '/_letterbox/inbox/'.$id, $this->cookie())->getBody();

        $reset = 'href="https://example.org/login?tx_felogin_login%5Baction%5D=showChangePassword&amp;tx_felogin_login%5Bhash%5D=abc123"';
        self::assertStringContainsString($reset.' target="_blank" rel="noopener noreferrer"', $body);
        self::assertStringContainsString('Open reset link', $body);
        self::assertLessThan(strpos($body, 'https://example.org/about'), strpos($body, $reset), 'the reset link comes before the content');
        self::assertStringContainsString('><code>https://example.org/login?tx_felogin_login%5Baction%5D=showChangePassword&amp;tx_felogin_login%5Bhash%5D=abc123</code><', $body, 'the URL is visible as text');
        self::assertSame(0, preg_match('/<a\b(?![^>]*rel="noopener noreferrer")[^>]*href="https?:/', $body), 'every external link is opened without opener and referrer');
        self::assertStringNotContainsString('<script', $body);
        self::assertGreaterThan(1, preg_match_all('#<a\b([^>]*target="_blank"[^>]*)>(.*?)</a>#s', $body, $newTabLinks));
        foreach ($newTabLinks[2] as $index => $text) {
            $announced = str_contains($text, '<span class="tester-visually-hidden"> (opens in a new tab)</span>') || str_contains($newTabLinks[1][$index], 'aria-describedby="tester-new-tab"');
            self::assertTrue($announced, 'a link to a new tab says so: '.$text);
        }
    }

    #[Test]
    public function previewIsSandboxedWithoutRemoteImages(): void
    {
        $id = $this->appendRaw(
            "From: s@example.org\nTo: anna@example.org\nSubject: Html mail\nMessage-ID: <html@example.org>\nMIME-Version: 1.0\n"
            ."Content-Type: multipart/alternative; boundary=\"b\"\n\n--b\nContent-Type: text/plain; charset=utf-8\n\nPlain part https://example.org/plain\n"
            ."--b\nContent-Type: text/html; charset=utf-8\n\n<p style=\"color:red\">Rich part</p><img src=\"https://tracker.example/pixel.png\"><script>alert(1)</script>\n--b--\n",
        );
        $middleware = $this->middleware();

        $body = (string) $this->send($middleware, '/_letterbox/inbox/'.$id, $this->cookie())->getBody();

        $text = (string) $this->send($middleware, '/_letterbox/inbox/'.$id.'?view=text', $this->cookie())->getBody();
        self::assertStringContainsString('<span class="tester-visually-hidden" id="tester-new-tab">(opens in a new tab)</span>', $text);
        self::assertStringContainsString('href="https://example.org/plain" target="_blank" rel="noopener noreferrer" aria-describedby="tester-new-tab"', $text);

        self::assertSame(1, preg_match('/<iframe\b[^>]*>/', $body, $frame));
        $tag = $frame[0] ?? '';
        self::assertStringContainsString('sandbox="allow-popups allow-popups-to-escape-sandbox"', $tag);
        self::assertStringContainsString('title="', $tag);
        self::assertSame(0, preg_match('/\ssrc="/', $tag), 'only srcdoc, never a URL');
        self::assertSame(1, preg_match('/srcdoc="([^"]*)"/', $tag, $srcdoc));
        $document = html_entity_decode($srcdoc[1] ?? '', \ENT_QUOTES | \ENT_HTML5);
        self::assertStringContainsString("img-src data:; style-src 'unsafe-inline'", $document);
        self::assertStringNotContainsString('https:;', $document);
        self::assertStringContainsString('Rich part', $document);
        self::assertStringNotContainsString('<script', $document);
        self::assertStringNotContainsString('<script', $body);
        self::assertStringNotContainsString('Plain part', $body, 'the HTML view is the default when there is HTML');

        $text = (string) $this->send($middleware, '/_letterbox/inbox/'.$id.'?view=text', $this->cookie())->getBody();
        self::assertStringNotContainsString('<iframe', $text);
        self::assertStringContainsString('Plain part <a href="https://example.org/plain" target="_blank" rel="noopener noreferrer" aria-describedby="tester-new-tab">https://example.org/plain</a>', $text);

        $plainOnly = $this->append('Plain only', 'anna@example.org');
        $plain = (string) $this->send($middleware, '/_letterbox/inbox/'.$plainOnly, $this->cookie())->getBody();
        self::assertStringNotContainsString('<iframe', $plain);
        self::assertStringContainsString('body', $plain);
    }

    #[Test]
    public function noHeadersOrSourceAreExposed(): void
    {
        $id = $this->appendRaw(
            "X-Letterbox-Envelope-To: anna@example.org, hidden@other.test\nFrom: Sender <s@example.org>\n"
            ."To: anna@example.org, bob@other.test\nCc: \"Carbon\" <cc@other.test>\nBcc: secret@other.test\nReply-To: reply@other.test\n"
            ."Subject: Private parts\nMessage-ID: <unique-message-id@example.org>\nX-Custom-Header: custom-value\nMIME-Version: 1.0\n"
            ."Content-Type: text/plain; charset=utf-8\n\nbody\n",
        );

        $response = $this->send($this->middleware(), '/_letterbox/inbox/'.$id, $this->cookie());
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Private parts', $body);
        self::assertStringContainsString('s@example.org', $body);
        self::assertStringContainsString('anna@example.org, bob@other.test', $body);
        self::assertStringContainsString('cc@other.test', $body);
        foreach (['X-Letterbox-Envelope-To', 'hidden@other.test', 'secret@other.test', 'reply@other.test', 'unique-message-id', 'X-Custom-Header', 'custom-value', '.eml', '/source'] as $needle) {
            self::assertStringNotContainsString($needle, $body, $needle);
        }
    }

    #[Test]
    public function attachmentIsAvailable(): void
    {
        $id = $this->appendRaw(
            "From: s@example.org\nTo: anna@example.org\nSubject: With file\nMessage-ID: <file@example.org>\nMIME-Version: 1.0\n"
            ."Content-Type: multipart/mixed; boundary=\"b\"\n\n--b\nContent-Type: text/plain; charset=utf-8\n\nSee attached\n"
            ."--b\nContent-Type: text/plain; name=\"notes.txt\"\nContent-Disposition: attachment; filename=\"notes.txt\"\n\nattached content\n--b--\n",
        );
        $middleware = $this->middleware();

        $detail = (string) $this->send($middleware, '/_letterbox/inbox/'.$id, $this->cookie())->getBody();
        self::assertStringContainsString('href="/_letterbox/inbox/'.$id.'/attachments/0"', $detail);
        self::assertStringContainsString('notes.txt', $detail);
        $download = $this->send($middleware, '/_letterbox/inbox/'.$id.'/attachments/0', $this->cookie());
        self::assertSame(200, $download->getStatusCode());
        self::assertSame('application/octet-stream', $download->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('attachment; filename="notes.txt"', $download->getHeaderLine('Content-Disposition'));
        self::assertSame(self::PAGE_CSP, $download->getHeaderLine('Content-Security-Policy'));
        self::assertSame('no-store, private', $download->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('attached content', (string) $download->getBody());

        foreach (['/'.$id.'/attachments/9', '/'.$id.'/attachments/00', '/'.$id.'/attachments/-1', '/'.$id.'/attachments/x', '/'.$id.'/attachments/0/x'] as $path) {
            self::assertSame(404, $this->send($middleware, '/_letterbox/inbox'.$path, $this->cookie())->getStatusCode(), $path);
        }
        self::assertSame(404, $this->send($middleware, '/_letterbox/inbox/'.$id.'/attachments/0', $this->cookie(), 'POST')->getStatusCode());
        self::assertSame(404, $this->send($middleware, '/_letterbox/inbox/'.$id.'/attachments/0')->getStatusCode(), 'no cookie, no download');
    }

    #[Test]
    public function deleteRouteIsGone(): void
    {
        $id = $this->append('Keep me', 'anna@example.org');
        $middleware = $this->middleware();

        foreach (['POST', 'GET'] as $method) {
            $response = $this->send($middleware, '/_letterbox/inbox/'.$id.'/delete', $this->cookie(), $method, [], ['letterboxCsrf' => 'x']);
            self::assertSame(404, $response->getStatusCode(), $method);
            self::assertSame(self::PAGE_CSP, $response->getHeaderLine('Content-Security-Policy'));
        }
        self::assertNotNull($this->get(MailRepository::class)->findById($id));
        self::assertStringNotContainsString('/delete', (string) $this->send($middleware, '/_letterbox/inbox/'.$id, $this->cookie())->getBody());
        self::assertStringNotContainsString('<form method="post"', (string) $this->send($middleware, '/_letterbox/inbox/'.$id, $this->cookie())->getBody());
    }

    private function assertClearedCookie(ResponseInterface $response, string $message = ''): void
    {
        self::assertSame(404, $response->getStatusCode(), $message);
        self::assertStringNotContainsString('In scope', (string) $response->getBody());
        self::assertSame(self::PAGE_CSP, $response->getHeaderLine('Content-Security-Policy'));
        $attributes = $this->cookieAttributes($response);
        self::assertArrayHasKey('letterbox_inbox', $attributes);
        self::assertNotSame('', $attributes['expires'] ?? '');
        self::assertLessThan(time(), strtotime($attributes['expires']));
        self::assertSame('/_letterbox/inbox', $attributes['path']);
        self::assertArrayHasKey('secure', $attributes);
        self::assertArrayHasKey('httponly', $attributes);
        self::assertSame('lax', strtolower($attributes['samesite']));
    }

    private function sessionValue(?int $issued = null): string
    {
        $issued ??= time();

        return $issued.'.'.hash_hmac('sha256', 'inbox|'.$issued, self::TOKEN);
    }

    private function linkSecret(): string
    {
        return hash_hmac('sha256', 'tester-link', self::TOKEN);
    }

    /**
     * @return array<string, string>
     */
    private function cookie(): array
    {
        return ['letterbox_inbox' => $this->sessionValue()];
    }

    private function middleware(string $token = self::TOKEN): TesterInboxMiddleware
    {
        $variables = new EnvironmentVariables(['LETTERBOX_EXTERNAL_ACCESS' => 'Testing', 'LETTERBOX_ACCESS_TOKEN' => $token]);
        $responseFactory = new ResponseFactory();
        $streamFactory = new StreamFactory();

        return new TesterInboxMiddleware(
            new ExternalAccessGuard($variables),
            new ExternalResponses($responseFactory, $streamFactory),
            new TesterSession(GeneralUtility::makeInstance(Context::class)),
            $this->get(MailRepository::class),
            new TesterInboxView($this->get(ViewFactoryInterface::class), GeneralUtility::makeInstance(LanguageServiceFactory::class), $responseFactory, $streamFactory, new MailHtmlDocument()),
            $responseFactory,
            new DownloadResponseFactory($responseFactory, $streamFactory, new MboxFormat()),
        );
    }

    /**
     * @param array<string, string>     $cookies
     * @param array<string, string>     $server
     * @param array<string, mixed>|null $body
     */
    private function send(TesterInboxMiddleware $middleware, string $pathAndQuery, array $cookies = [], string $method = 'GET', array $server = [], ?array $body = null): ResponseInterface
    {
        $server += ['HTTPS' => 'on', 'HTTP_HOST' => 'stage.example.org', 'SERVER_NAME' => 'stage.example.org', 'SCRIPT_NAME' => '/index.php', 'REMOTE_ADDR' => '192.0.2.10'];
        $request = new ServerRequest('https://stage.example.org'.$pathAndQuery, $method, 'php://temp', [], $server);
        parse_str($request->getUri()->getQuery(), $query);
        $request = $request->withQueryParams($query)->withCookieParams($cookies)->withParsedBody($body)->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));

        return $middleware->process($request, new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withHeader('X-Passed-Through', '1');
            }
        });
    }

    /**
     * @return array<string, string> lowercase attribute names, the cookie itself under its name
     */
    private function cookieAttributes(ResponseInterface $response): array
    {
        $attributes = [];
        foreach (explode(';', $response->getHeaderLine('Set-Cookie')) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $attributes['letterbox_inbox' === $name ? $name : strtolower($name)] = urldecode($value);
        }

        return $attributes;
    }

    private function rows(ResponseInterface $response): int
    {
        self::assertSame(200, $response->getStatusCode());

        return (int) preg_match_all('#href="/_letterbox/inbox/[0-9a-f]{32}"#', (string) $response->getBody());
    }

    /**
     * @return string the id of the appended mail
     */
    private function append(string $subject, string $to): string
    {
        return $this->appendRaw("From: Sender <s@example.org>\nTo: {$to}\nSubject: {$subject}\nMessage-ID: <".bin2hex(random_bytes(4))."@example.org>\n"
            ."MIME-Version: 1.0\nContent-Type: text/plain; charset=utf-8\n\nbody\n");
    }

    /**
     * @return string the id of the appended mail
     */
    private function appendRaw(string $raw): string
    {
        $repository = $this->get(MailRepository::class);
        $before = array_map(static fn (Mail $mail): string => $mail->id, $repository->find(MailFilter::all(), 0, 1000));
        (new MailboxWriter($this->path, new MboxFormat(), new MailboxLock($this->get(LockFactory::class))))
            ->append('s@example.org', new DateTimeImmutable('2026-10-27 10:00:00'), $raw);
        $added = array_values(array_diff(array_map(static fn (Mail $mail): string => $mail->id, $repository->find(MailFilter::all(), 0, 1000)), $before));
        self::assertCount(1, $added);

        return $added[0];
    }
}
