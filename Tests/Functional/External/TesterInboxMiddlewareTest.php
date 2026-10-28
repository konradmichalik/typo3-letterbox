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
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use KonradMichalik\Typo3Letterbox\External\{AuthenticationThrottle, EnvironmentVariables, ExternalAccessGuard, ExternalResponses, RateLimitStorage};
use KonradMichalik\Typo3Letterbox\External\Tester\{IssuedLink, TesterInboxMiddleware, TesterInboxView, TesterLinkRepository, TesterSession};
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

    private function issue(string $label = 'QA'): IssuedLink
    {
        return $this->repository()->issue($label, [RecipientPattern::fromString('*@example.org')], false, false, false, 7, 1, time());
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
            new TesterInboxView($this->get(ViewFactoryInterface::class), GeneralUtility::makeInstance(LanguageServiceFactory::class), $responseFactory, $streamFactory),
            $responseFactory,
        );
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, string> $server
     */
    private function send(TesterInboxMiddleware $middleware, string $pathAndQuery, array $cookies = [], string $method = 'GET', array $server = []): ResponseInterface
    {
        $server += ['HTTPS' => 'on', 'HTTP_HOST' => 'stage.example.org', 'SERVER_NAME' => 'stage.example.org', 'SCRIPT_NAME' => '/index.php', 'REMOTE_ADDR' => '192.0.2.10'];
        $request = new ServerRequest('https://stage.example.org'.$pathAndQuery, $method, 'php://temp', [], $server);
        parse_str($request->getUri()->getQuery(), $query);
        $request = $request->withQueryParams($query)->withCookieParams($cookies)->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE);
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

    private function append(string $subject, string $to): void
    {
        $raw = "From: Sender <s@example.org>\nTo: {$to}\nSubject: {$subject}\nMessage-ID: <".bin2hex(random_bytes(4))."@example.org>\n"
            ."MIME-Version: 1.0\nContent-Type: text/plain; charset=utf-8\n\nbody\n";
        (new MailboxWriter($this->path, new MboxFormat(), new MailboxLock($this->get(LockFactory::class))))
            ->append('s@example.org', new DateTimeImmutable('2026-10-27 10:00:00'), $raw);
    }
}
