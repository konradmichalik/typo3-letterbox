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
use KonradMichalik\Typo3Letterbox\External\{AuthenticationThrottle, EnvironmentVariables, ExternalAccessGuard, ExternalResponses, RateLimitStorage};
use KonradMichalik\Typo3Letterbox\External\Tester\{IssuedLink, TesterInboxMiddleware, TesterInboxView, TesterLinkRepository, TesterSession};
use KonradMichalik\Typo3Letterbox\Html\MailHtmlDocument;
use KonradMichalik\Typo3Letterbox\Http\DownloadResponseFactory;
use KonradMichalik\Typo3Letterbox\Mail\{LetterboxTransport, RecipientPattern};
use KonradMichalik\Typo3Letterbox\Mbox\{MailboxLock, MailboxWriter, MboxFormat};
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\{ConfigurationManager, ExtensionConfiguration};
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\{Environment, SystemEnvironmentBuilder};
use TYPO3\CMS\Core\Database\ConnectionPool;
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

    private const ENV_NAMES = ['LETTERBOX_EXTERNAL_ACCESS', 'LETTERBOX_API_TOKEN', 'LETTERBOX_EXTERNAL_ALLOWED_IPS'];

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
        $cacheManager = $this->get(CacheManager::class);
        $cacheManager->getCache('typo3_letterbox_index')->flush();
        $cacheManager->getCache('typo3_letterbox_ratelimit')->flush();
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
        $issued = $this->issue();
        putenv('LETTERBOX_EXTERNAL_ACCESS=Testing');

        $response = $this->executeFrontendSubRequest(new InternalRequest('https://stage.example.org/_letterbox/inbox?t='.$issued->token.'&p=2&q=x'));

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/_letterbox/inbox', $response->getHeaderLine('Location'));
        self::assertStringStartsWith('letterbox_inbox='.$issued->token.';', $response->getHeaderLine('Set-Cookie'));
        self::assertSame('no-store, private', $response->getHeaderLine('Cache-Control'));
        self::assertSame(self::PAGE_CSP, $response->getHeaderLine('Content-Security-Policy'));
    }

    #[Test]
    public function cookieAttributesAreStrict(): void
    {
        $issued = $this->issue();

        $response = $this->send($this->middleware(), '/_letterbox/inbox?t='.$issued->token);

        self::assertSame(303, $response->getStatusCode());
        $attributes = $this->cookieAttributes($response);
        self::assertSame($issued->token, $attributes['letterbox_inbox']);
        self::assertSame('/_letterbox/inbox', $attributes['path']);
        self::assertArrayHasKey('secure', $attributes);
        self::assertArrayHasKey('httponly', $attributes);
        self::assertSame('strict', strtolower($attributes['samesite']));
        self::assertArrayNotHasKey('domain', $attributes);
        self::assertSame($issued->link->expires, strtotime($attributes['expires']));
    }

    #[Test]
    public function noCookieIs404(): void
    {
        $this->append('In scope', 'anna@example.org');
        putenv('LETTERBOX_EXTERNAL_ACCESS=Testing');

        $response = $this->executeFrontendSubRequest(new InternalRequest('https://stage.example.org/_letterbox/inbox'));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(self::PAGE_CSP, $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertStringNotContainsString('In scope', (string) $response->getBody());

        $middleware = $this->middleware();
        $cookie = ['letterbox_inbox' => $this->issue()->token];
        self::assertSame(404, $this->send($middleware, '/_letterbox/inbox/', $cookie)->getStatusCode(), 'no trailing slash');
        self::assertSame(404, $this->send($middleware, '/_letterbox/inbox', $cookie, 'POST')->getStatusCode());
        self::assertSame(404, $this->send($middleware, '/_letterbox/inbox?t=nothex')->getStatusCode());
        self::assertSame('1', $this->send($middleware, '/_letterbox/inboxes')->getHeaderLine('X-Passed-Through'));
        self::assertSame('1', $this->send($middleware, '/_letterbox')->getHeaderLine('X-Passed-Through'));
    }

    #[Test]
    public function revokedLinkClearsCookie(): void
    {
        $this->append('In scope', 'anna@example.org');
        $issued = $this->issue();
        $this->repository()->revoke($issued->link->uid, time());

        $response = $this->send($this->middleware(), '/_letterbox/inbox', ['letterbox_inbox' => $issued->token]);

        $this->assertClearedCookie($response);
    }

    #[Test]
    public function expiredLinkClearsCookie(): void
    {
        $this->append('In scope', 'anna@example.org');
        $issued = $this->repository()->issue('Old', [RecipientPattern::fromString('*@example.org')], false, false, false, 1, 1, time() - 2 * 86400);

        $middleware = $this->middleware();
        $this->assertClearedCookie($this->send($middleware, '/_letterbox/inbox', ['letterbox_inbox' => $issued->token]));
        self::assertSame(404, $this->send($middleware, '/_letterbox/inbox?t='.$issued->token)->getStatusCode());
    }

    #[Test]
    public function listShowsOnlyScopedMailsWithMaskedRecipients(): void
    {
        $this->append('Welcome Anna', 'anna@example.org, "anna@example.org" <bob@other.test>');
        $this->append('For Carl', 'carl@other.test');
        $this->append('Display trick', '"anna@example.org" <eve@other.test>');
        $issued = $this->issue('Login QA');

        $response = $this->send($this->middleware(), '/_letterbox/inbox', ['letterbox_inbox' => $issued->token]);
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
        self::assertSame(self::PAGE_CSP, $response->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString('<html lang="en"', $body);
        self::assertStringContainsString('<main', $body);
        self::assertStringContainsString('Login QA', $body);
        self::assertStringContainsString('*@example.org', $body);
        self::assertStringContainsString('Welcome Anna', $body);
        self::assertStringContainsString('anna@example.org', $body);
        self::assertStringContainsString('and 1 more', $body);
        self::assertStringNotContainsString('bob@other.test', $body);
        self::assertStringNotContainsString('For Carl', $body);
        self::assertStringNotContainsString('carl@other.test', $body);
        self::assertStringNotContainsString('Display trick', $body);
        self::assertStringNotContainsString('eve@other.test', $body);
        self::assertStringNotContainsString($issued->token, $body);
        self::assertSame(1, preg_match_all('#href="/_letterbox/inbox/[0-9a-f]{32}"#', $body));
        self::assertStringContainsString('typo3_letterbox/Resources/Public/Css/tester.css', $body);
    }

    #[Test]
    public function searchAndPagingStayInScope(): void
    {
        for ($i = 1; $i <= 25; ++$i) {
            $this->append('Scoped '.$i, 'anna@example.org, bob@other.test');
        }
        $this->append('Hidden match', 'carl@other.test');
        $cookie = ['letterbox_inbox' => $this->issue()->token];
        $middleware = $this->middleware();

        self::assertSame(20, $this->rows($this->send($middleware, '/_letterbox/inbox', $cookie)));
        self::assertSame(5, $this->rows($this->send($middleware, '/_letterbox/inbox?p=2', $cookie)));
        self::assertSame(20, $this->rows($this->send($middleware, '/_letterbox/inbox?p=abc', $cookie)));
        self::assertSame(20, $this->rows($this->send($middleware, '/_letterbox/inbox?p[]=2', $cookie)));
        self::assertSame(0, $this->rows($this->send($middleware, '/_letterbox/inbox?q=Hidden', $cookie)));
        self::assertSame(0, $this->rows($this->send($middleware, '/_letterbox/inbox?q=bob%40other', $cookie)), 'a masked address is not searchable');
        self::assertSame(1, $this->rows($this->send($middleware, '/_letterbox/inbox?q=Scoped+25', $cookie)));
        self::assertSame(20, $this->rows($this->send($middleware, '/_letterbox/inbox?q[]=Hidden', $cookie)));
        self::assertSame(0, $this->rows($this->send($middleware, '/_letterbox/inbox?q=Scoped+2'.str_repeat('x', 200), $cookie)), 'the query is capped, not dropped');
        $paged = (string) $this->send($middleware, '/_letterbox/inbox?q=Scoped&p=1', $cookie)->getBody();
        self::assertStringContainsString('href="/_letterbox/inbox?q=Scoped&amp;p=2"', $paged);
    }

    #[Test]
    public function lastUsedIsTouched(): void
    {
        $issued = $this->issue();
        self::assertSame(0, $issued->link->lastUsed);

        $this->send($this->middleware(), '/_letterbox/inbox', ['letterbox_inbox' => $issued->token]);

        $link = $this->repository()->findByToken($issued->token);
        self::assertNotNull($link);
        self::assertGreaterThan(0, $link->lastUsed);
    }

    #[Test]
    public function subDirectoryCookiePath(): void
    {
        $issued = $this->issue();
        $middleware = $this->middleware();
        $server = ['SCRIPT_NAME' => '/sub/index.php'];

        $response = $this->send($middleware, '/sub/_letterbox/inbox?t='.$issued->token, [], 'GET', $server);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/sub/_letterbox/inbox', $response->getHeaderLine('Location'));
        self::assertSame('/sub/_letterbox/inbox', $this->cookieAttributes($response)['path']);
        self::assertSame('1', $this->send($middleware, '/_letterbox/inbox?t='.$issued->token, [], 'GET', $server)->getHeaderLine('X-Passed-Through'));
        $list = $this->send($middleware, '/sub/_letterbox/inbox', ['letterbox_inbox' => $issued->token], 'GET', $server);
        self::assertSame(200, $list->getStatusCode());
        self::assertStringContainsString('action="/sub/_letterbox/inbox"', (string) $list->getBody());
    }

    #[Test]
    public function inactiveSurfaceIs404EvenWithValidCookie(): void
    {
        $this->append('In scope', 'anna@example.org');
        $issued = $this->issue();

        $response = $this->executeFrontendSubRequest(
            (new InternalRequest('https://stage.example.org/_letterbox/inbox'))->withCookieParams(['letterbox_inbox' => $issued->token]),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(self::PAGE_CSP, $response->getHeaderLine('Content-Security-Policy'));
        self::assertStringNotContainsString('In scope', (string) $response->getBody());

        putenv('LETTERBOX_EXTERNAL_ACCESS=Testing');
        $active = $this->executeFrontendSubRequest(
            (new InternalRequest('https://stage.example.org/_letterbox/inbox'))->withCookieParams(['letterbox_inbox' => $issued->token]),
        );
        self::assertSame(200, $active->getStatusCode());
        self::assertStringContainsString('In scope', (string) $active->getBody());
    }

    #[Test]
    public function throttledIpIs404(): void
    {
        $this->append('In scope', 'anna@example.org');
        $issued = $this->issue();
        $middleware = $this->middleware();
        $cookie = ['letterbox_inbox' => $issued->token];

        self::assertSame(200, $this->send($middleware, '/_letterbox/inbox', $cookie)->getStatusCode());
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(404, $this->send($middleware, '/_letterbox/inbox?t='.str_repeat('0', 64))->getStatusCode());
            self::assertSame(404, $this->send($middleware, '/_letterbox/inbox', ['letterbox_inbox' => str_repeat('a', 64)])->getStatusCode());
        }

        self::assertSame(404, $this->send($middleware, '/_letterbox/inbox?t='.$issued->token)->getStatusCode());
        $blocked = $this->send($middleware, '/_letterbox/inbox', $cookie);
        self::assertSame(404, $blocked->getStatusCode());
        self::assertStringNotContainsString('In scope', (string) $blocked->getBody());
        self::assertSame(200, $this->send($middleware, '/_letterbox/inbox', $cookie, 'GET', ['REMOTE_ADDR' => '192.0.2.99'])->getStatusCode());
    }

    #[Test]
    public function detailOutsideScopeIs404(): void
    {
        $inScope = $this->append('In scope', 'anna@example.org');
        $outside = $this->append('For Carl', 'carl@other.test');
        $trick = $this->append('Display trick', '"anna@example.org" <eve@other.test>');
        $cookie = ['letterbox_inbox' => $this->issue()->token];
        $middleware = $this->middleware();

        $detail = $this->send($middleware, '/_letterbox/inbox/'.$inScope, $cookie);
        self::assertSame(200, $detail->getStatusCode());
        self::assertSame(self::PAGE_CSP, $detail->getHeaderLine('Content-Security-Policy'));
        self::assertStringContainsString('In scope', (string) $detail->getBody());

        foreach ([$outside, $trick, str_repeat('0', 32), strtoupper($inScope), $inScope.'/', $inScope.'/extra', $inScope.'/attachments', $inScope.'/delete'] as $path) {
            $response = $this->send($middleware, '/_letterbox/inbox/'.$path, $cookie);
            self::assertSame(404, $response->getStatusCode(), $path);
            self::assertStringContainsString('Page not found', (string) $response->getBody());
            self::assertStringNotContainsString('For Carl', (string) $response->getBody());
            self::assertStringNotContainsString('Display trick', (string) $response->getBody());
        }
        self::assertSame(404, $this->send($middleware, '/_letterbox/inbox/'.$inScope, [])->getStatusCode());
        self::assertSame(404, $this->send($middleware, '/_letterbox/inbox/'.$inScope, $cookie, 'POST')->getStatusCode());
        self::assertSame(404, $this->send($middleware, '/_letterbox/inbox/'.$inScope, $cookie, 'DELETE')->getStatusCode());
    }

    #[Test]
    public function resetLinkIsOfferedFirst(): void
    {
        $id = $this->appendRaw(
            "From: Example Site <no-reply@example.org>\nTo: anna@example.org\nSubject: Reset your password\nMessage-ID: <reset@example.org>\n"
            ."MIME-Version: 1.0\nContent-Type: text/plain; charset=utf-8\n\n"
            ."See https://example.org/about first.\nSet a new password: https://example.org/login?tx_felogin_login%5Baction%5D=showChangePassword&tx_felogin_login%5Bhash%5D=abc123\n",
        );

        $body = (string) $this->send($this->middleware(), '/_letterbox/inbox/'.$id, ['letterbox_inbox' => $this->issue()->token])->getBody();

        $reset = 'href="https://example.org/login?tx_felogin_login%5Baction%5D=showChangePassword&amp;tx_felogin_login%5Bhash%5D=abc123"';
        self::assertStringContainsString($reset.' target="_blank" rel="noopener noreferrer"', $body);
        self::assertStringContainsString('Open reset link', $body);
        self::assertLessThan(strpos($body, 'https://example.org/about'), strpos($body, $reset), 'the reset link comes before the content');
        self::assertStringContainsString('><code>https://example.org/login?tx_felogin_login%5Baction%5D=showChangePassword&amp;tx_felogin_login%5Bhash%5D=abc123</code><', $body, 'the URL is visible as text');
        self::assertSame(0, preg_match('/<a\b(?![^>]*rel="noopener noreferrer")[^>]*href="https?:/', $body), 'every external link is opened without opener and referrer');
        self::assertStringNotContainsString('<script', $body);
    }

    #[Test]
    public function previewIsSandboxedWithoutRemoteImages(): void
    {
        $id = $this->appendRaw(
            "From: s@example.org\nTo: anna@example.org\nSubject: Html mail\nMessage-ID: <html@example.org>\nMIME-Version: 1.0\n"
            ."Content-Type: multipart/alternative; boundary=\"b\"\n\n--b\nContent-Type: text/plain; charset=utf-8\n\nPlain part https://example.org/plain\n"
            ."--b\nContent-Type: text/html; charset=utf-8\n\n<p style=\"color:red\">Rich part</p><img src=\"https://tracker.example/pixel.png\"><script>alert(1)</script>\n--b--\n",
        );
        $cookie = ['letterbox_inbox' => $this->issue()->token];
        $middleware = $this->middleware();

        $body = (string) $this->send($middleware, '/_letterbox/inbox/'.$id, $cookie)->getBody();

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

        $text = (string) $this->send($middleware, '/_letterbox/inbox/'.$id.'?view=text', $cookie)->getBody();
        self::assertStringNotContainsString('<iframe', $text);
        self::assertStringContainsString('Plain part <a href="https://example.org/plain" target="_blank" rel="noopener noreferrer">https://example.org/plain</a>', $text);

        $plainOnly = $this->append('Plain only', 'anna@example.org');
        $plain = (string) $this->send($middleware, '/_letterbox/inbox/'.$plainOnly, $cookie)->getBody();
        self::assertStringNotContainsString('<iframe', $plain);
        self::assertStringContainsString('body', $plain);
    }

    #[Test]
    public function noHeadersOrSourceAreExposed(): void
    {
        $id = $this->appendRaw(
            "X-Letterbox-Envelope-To: anna@example.org, hidden@other.test\nFrom: Sender <s@example.org>\n"
            ."To: anna@example.org, bob@other.test\nCc: \"anna@example.org\" <cc@other.test>\nBcc: secret@other.test\nReply-To: reply@other.test\n"
            ."Subject: Private parts\nMessage-ID: <unique-message-id@example.org>\nX-Custom-Header: custom-value\nMIME-Version: 1.0\n"
            ."Content-Type: text/plain; charset=utf-8\n\nbody\n",
        );

        $response = $this->send($this->middleware(), '/_letterbox/inbox/'.$id, ['letterbox_inbox' => $this->issue()->token]);
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Private parts', $body);
        self::assertStringContainsString('s@example.org', $body);
        self::assertStringContainsString('anna@example.org', $body);
        self::assertStringContainsString('and 1 more', $body);
        foreach (['X-Letterbox-Envelope-To', 'hidden@other.test', 'bob@other.test', 'cc@other.test', 'secret@other.test', 'reply@other.test', 'unique-message-id', 'X-Custom-Header', 'custom-value', '.eml', '/source'] as $needle) {
            self::assertStringNotContainsString($needle, $body, $needle);
        }
    }

    #[Test]
    public function attachmentNeedsPermission(): void
    {
        $id = $this->appendRaw(
            "From: s@example.org\nTo: anna@example.org\nSubject: With file\nMessage-ID: <file@example.org>\nMIME-Version: 1.0\n"
            ."Content-Type: multipart/mixed; boundary=\"b\"\n\n--b\nContent-Type: text/plain; charset=utf-8\n\nSee attached\n"
            ."--b\nContent-Type: text/plain; name=\"notes.txt\"\nContent-Disposition: attachment; filename=\"notes.txt\"\n\nattached content\n--b--\n",
        );
        $outside = $this->appendRaw(
            "From: s@example.org\nTo: carl@other.test\nSubject: Other file\nMessage-ID: <other@example.org>\nMIME-Version: 1.0\n"
            ."Content-Type: multipart/mixed; boundary=\"b\"\n\n--b\nContent-Type: text/plain; charset=utf-8\n\nSee attached\n"
            ."--b\nContent-Type: text/plain; name=\"secret.txt\"\nContent-Disposition: attachment; filename=\"secret.txt\"\n\nsecret content\n--b--\n",
        );
        $middleware = $this->middleware();
        $denied = ['letterbox_inbox' => $this->issue()->token];
        $allowed = ['letterbox_inbox' => $this->issue('Files', true)->token];

        self::assertStringNotContainsString('/attachments/', (string) $this->send($middleware, '/_letterbox/inbox/'.$id, $denied)->getBody());
        self::assertSame(404, $this->send($middleware, '/_letterbox/inbox/'.$id.'/attachments/0', $denied)->getStatusCode());

        $detail = (string) $this->send($middleware, '/_letterbox/inbox/'.$id, $allowed)->getBody();
        self::assertStringContainsString('href="/_letterbox/inbox/'.$id.'/attachments/0"', $detail);
        self::assertStringContainsString('notes.txt', $detail);
        $download = $this->send($middleware, '/_letterbox/inbox/'.$id.'/attachments/0', $allowed);
        self::assertSame(200, $download->getStatusCode());
        self::assertSame('application/octet-stream', $download->getHeaderLine('Content-Type'));
        self::assertStringStartsWith('attachment; filename="notes.txt"', $download->getHeaderLine('Content-Disposition'));
        self::assertSame(self::PAGE_CSP, $download->getHeaderLine('Content-Security-Policy'));
        self::assertSame('no-store, private', $download->getHeaderLine('Cache-Control'));
        self::assertStringContainsString('attached content', (string) $download->getBody());

        foreach (['/'.$id.'/attachments/9', '/'.$id.'/attachments/00', '/'.$id.'/attachments/-1', '/'.$id.'/attachments/x', '/'.$id.'/attachments/0/x', '/'.$outside.'/attachments/0'] as $path) {
            $response = $this->send($middleware, '/_letterbox/inbox'.$path, $allowed);
            self::assertSame(404, $response->getStatusCode(), $path);
            self::assertStringNotContainsString('secret content', (string) $response->getBody());
        }
        self::assertSame(404, $this->send($middleware, '/_letterbox/inbox/'.$id.'/attachments/0', $allowed, 'POST')->getStatusCode());
    }

    #[Test]
    public function deleteNeedsPermissionPostAndCsrf(): void
    {
        $id = $this->append('Delete me', 'anna@example.org');
        $middleware = $this->middleware();
        $denied = $this->issue();
        $allowed = $this->issue('Cleaner', false, true);
        $deniedCookie = ['letterbox_inbox' => $denied->token];
        $allowedCookie = ['letterbox_inbox' => $allowed->token];
        $path = '/_letterbox/inbox/'.$id.'/delete';

        self::assertStringNotContainsString('/delete', (string) $this->send($middleware, '/_letterbox/inbox/'.$id, $deniedCookie)->getBody());
        self::assertSame(404, $this->send($middleware, $path, $deniedCookie, 'POST', [], ['letterboxCsrf' => $this->csrf($denied->link->uid, $id)])->getStatusCode());
        self::assertSame(404, $this->send($middleware, $path, $allowedCookie)->getStatusCode(), 'GET');
        self::assertSame(404, $this->send($middleware, $path, $allowedCookie, 'POST')->getStatusCode(), 'missing token');
        self::assertSame(404, $this->send($middleware, $path, $allowedCookie, 'POST', [], ['letterboxCsrf' => 'nope'])->getStatusCode(), 'wrong token');
        self::assertSame(404, $this->send($middleware, $path, $allowedCookie, 'POST', [], ['letterboxCsrf' => ['x']])->getStatusCode(), 'array token');
        self::assertSame(404, $this->send($middleware, $path, $allowedCookie, 'POST', [], ['letterboxCsrf' => $this->csrf($denied->link->uid, $id)])->getStatusCode(), 'token of another link');
        self::assertSame(404, $this->send($middleware, $path, [], 'POST', [], ['letterboxCsrf' => $this->csrf($allowed->link->uid, $id)])->getStatusCode(), 'no cookie');
        self::assertNotNull($this->get(MailRepository::class)->findById($id, MailFilter::all()));

        $detail = (string) $this->send($middleware, '/_letterbox/inbox/'.$id, $allowedCookie)->getBody();
        self::assertStringContainsString('action="'.$path.'"', $detail);
        self::assertStringContainsString('method="post"', $detail);
        self::assertStringContainsString('name="letterboxCsrf" value="'.$this->csrf($allowed->link->uid, $id).'"', $detail);

        $response = $this->send($middleware, $path, $allowedCookie, 'POST', [], ['letterboxCsrf' => $this->csrf($allowed->link->uid, $id)]);
        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/_letterbox/inbox', $response->getHeaderLine('Location'));
        self::assertSame(self::PAGE_CSP, $response->getHeaderLine('Content-Security-Policy'));
        self::assertNull($this->get(MailRepository::class)->findById($id, MailFilter::all()));
        self::assertSame(404, $this->send($middleware, $path, $allowedCookie, 'POST', [], ['letterboxCsrf' => $this->csrf($allowed->link->uid, $id)])->getStatusCode(), 'already gone');
    }

    #[Test]
    public function deleteCannotReachMailOutsideScope(): void
    {
        $outside = $this->append('For Carl', 'carl@other.test');
        $allowed = $this->issue('Cleaner', false, true);

        $response = $this->send($this->middleware(), '/_letterbox/inbox/'.$outside.'/delete', ['letterbox_inbox' => $allowed->token], 'POST', [], ['letterboxCsrf' => $this->csrf($allowed->link->uid, $outside)]);

        self::assertSame(404, $response->getStatusCode());
        self::assertNotNull($this->get(MailRepository::class)->findById($outside, MailFilter::all()));
        self::assertSame(1, $this->get(MailRepository::class)->count(MailFilter::all()));
    }

    private function assertClearedCookie(ResponseInterface $response): void
    {
        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('In scope', (string) $response->getBody());
        self::assertSame(self::PAGE_CSP, $response->getHeaderLine('Content-Security-Policy'));
        $attributes = $this->cookieAttributes($response);
        self::assertArrayHasKey('letterbox_inbox', $attributes);
        self::assertNotSame('', $attributes['expires'] ?? '');
        self::assertLessThan(time(), strtotime($attributes['expires']));
        self::assertSame('/_letterbox/inbox', $attributes['path']);
        self::assertArrayHasKey('secure', $attributes);
        self::assertArrayHasKey('httponly', $attributes);
    }

    private function issue(string $label = 'QA', bool $canDownload = false, bool $canDelete = false): IssuedLink
    {
        return $this->repository()->issue($label, [RecipientPattern::fromString('*@example.org')], false, $canDownload, $canDelete, 7, 1, time());
    }

    private function csrf(int $uid, string $id): string
    {
        return hash_hmac('sha256', $uid.'|'.$id, $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey']);
    }

    private function repository(): TesterLinkRepository
    {
        return new TesterLinkRepository(GeneralUtility::makeInstance(ConnectionPool::class));
    }

    private function middleware(): TesterInboxMiddleware
    {
        $variables = new EnvironmentVariables(['LETTERBOX_EXTERNAL_ACCESS' => 'Testing']);
        $responseFactory = new ResponseFactory();
        $streamFactory = new StreamFactory();
        $links = $this->repository();

        return new TesterInboxMiddleware(
            new ExternalAccessGuard($variables, $this->get(ExtensionConfiguration::class)),
            new AuthenticationThrottle(new RateLimitStorage($this->get(CacheManager::class)->getCache('typo3_letterbox_ratelimit'))),
            new ExternalResponses($responseFactory, $streamFactory),
            new TesterSession($links, GeneralUtility::makeInstance(Context::class)),
            $links,
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
