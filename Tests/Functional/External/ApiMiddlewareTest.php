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
use KonradMichalik\Typo3Letterbox\External\Api\{ApiMiddleware, MailJson};
use KonradMichalik\Typo3Letterbox\External\{EnvironmentVariables, ExternalAccessGuard, ExternalResponses};
use KonradMichalik\Typo3Letterbox\Http\DownloadResponseFactory;
use KonradMichalik\Typo3Letterbox\Mail\LetterboxTransport;
use KonradMichalik\Typo3Letterbox\Mbox\{MailboxLock, MailboxWriter, MboxFormat};
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\{ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ConfigurationManager;
use TYPO3\CMS\Core\Core\{ApplicationContext, Environment};
use TYPO3\CMS\Core\Http\{NormalizedParams, Response, ResponseFactory, ServerRequest, StreamFactory};
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\Framework\Frontend\InternalRequest;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function dirname;

/**
 * ApiMiddlewareTest.
 *
 * The stack tests run through the real frontend application. Its sub-request boots a fresh container
 * and reloads TYPO3_CONF_VARS, so they get the mail settings from the instance settings file and the
 * environment through putenv(), restored in tearDown(). All other tests call process() on a
 * middleware built with fixed environment values.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ApiMiddlewareTest extends FunctionalTestCase
{
    private const TOKEN = 'example-api-token-0123456789abcdef-example';

    private const ENV_NAMES = ['LETTERBOX_EXTERNAL_ACCESS', 'LETTERBOX_ACCESS_TOKEN'];

    private const HEADERS = ['X-Letterbox-Token' => self::TOKEN];

    private const API_CSP = "default-src 'none'; frame-ancestors 'none'";

    protected array $testExtensionsToLoad = [
        'typo3_letterbox',
    ];

    /** @var array<string, string|false> */
    private array $previousEnvironment = [];

    private ?ApplicationContext $previousContext = null;

    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ENV_NAMES as $name) {
            $this->previousEnvironment[$name] = getenv($name);
        }
        $this->path = Environment::getVarPath().'/letterbox-test/api.mbox';
        GeneralUtility::mkdir_deep(dirname($this->path));
        if (is_file($this->path)) {
            unlink($this->path);
        }
        $mail = ['MAIL/transport' => LetterboxTransport::class, 'MAIL/transport_mbox_file' => $this->path];
        // The frontend sub-request reloads TYPO3_CONF_VARS from the instance settings, so they are written there too.
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
        if (null !== $this->previousContext) {
            $this->useContext($this->previousContext);
        }
        if (is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    #[Test]
    public function apiAnswersWithoutSiteConfiguration(): void
    {
        $this->append('Hello', 'anna@example.org');
        $this->putEnvironment(['LETTERBOX_EXTERNAL_ACCESS' => 'Testing', 'LETTERBOX_ACCESS_TOKEN' => self::TOKEN]);

        $response = $this->executeFrontendSubRequest(
            (new InternalRequest('https://stage.example.org/_letterbox/api/v1/messages'))->withHeader('X-Letterbox-Token', self::TOKEN),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, $this->json($response)['total']);
        self::assertSame(self::API_CSP, $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('no-store, private', $response->getHeaderLine('Cache-Control'));
        self::assertFalse($response->hasHeader('Set-Cookie'));
        self::assertFalse($response->hasHeader('Access-Control-Allow-Origin'));
    }

    #[Test]
    public function inactiveApiIs404(): void
    {
        $this->append('Hello', 'anna@example.org');
        $this->putEnvironment(['LETTERBOX_ACCESS_TOKEN' => self::TOKEN]);

        $response = $this->executeFrontendSubRequest(
            (new InternalRequest('https://stage.example.org/_letterbox/api/v1/messages'))->withHeader('X-Letterbox-Token', self::TOKEN),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['error' => 'not_found'], $this->json($response));
        self::assertSame(self::API_CSP, $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'));
    }

    #[Test]
    public function shortTokenKeepsApiOff(): void
    {
        $shortToken = substr(self::TOKEN, 0, 31);
        $this->putEnvironment(['LETTERBOX_EXTERNAL_ACCESS' => 'Testing', 'LETTERBOX_ACCESS_TOKEN' => $shortToken]);

        $response = $this->executeFrontendSubRequest(
            (new InternalRequest('https://stage.example.org/_letterbox/api/v1/messages'))->withHeader('X-Letterbox-Token', $shortToken),
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(['error' => 'not_found'], $this->json($response));
    }

    #[Test]
    public function bearerAndHeaderTokensWork(): void
    {
        $api = $this->middleware();

        self::assertSame(200, $this->send($api, '/_letterbox/api/v1/messages', 'GET', ['Authorization' => 'Bearer '.self::TOKEN])->getStatusCode());
        self::assertSame(200, $this->send($api, '/_letterbox/api/v1/messages', 'GET', ['Authorization' => 'bearer '.self::TOKEN])->getStatusCode());
        self::assertSame(200, $this->send($api, '/_letterbox/api/v1/messages', 'GET', ['X-Letterbox-Token' => self::TOKEN])->getStatusCode());
        self::assertSame(200, $this->send($api, '/_letterbox/api/v1/messages', 'GET', ['Authorization' => 'Basic c3RhZ2U6c3RhZ2U=', 'X-Letterbox-Token' => self::TOKEN])->getStatusCode());
        self::assertSame(404, $this->send($api, '/_letterbox/api/v1/messages', 'GET', ['Authorization' => 'Bearer '.self::TOKEN.'x'])->getStatusCode());
        self::assertSame(404, $this->send($api, '/_letterbox/api/v1/messages', 'GET', ['Authorization' => self::TOKEN])->getStatusCode());
        self::assertSame(404, $this->send($api, '/_letterbox/api/v1/messages', 'GET', [])->getStatusCode());
    }

    #[Test]
    public function queryStringTokenIsRefused(): void
    {
        $api = $this->middleware();

        self::assertSame(404, $this->send($api, '/_letterbox/api/v1/messages?token='.self::TOKEN)->getStatusCode());
        self::assertSame(404, $this->send($api, '/_letterbox/api/v1/messages?X-Letterbox-Token='.self::TOKEN, 'GET', [])->getStatusCode());
        self::assertSame(404, $this->send($api, '/_letterbox/api/v1/messages?t='.self::TOKEN, 'GET', [])->getStatusCode());
        self::assertSame(200, $this->send($api, '/_letterbox/api/v1/messages')->getStatusCode());
    }

    #[Test]
    public function wrongMethodIs404(): void
    {
        $this->append('Hello', 'anna@example.org');
        $id = $this->mails()[0]->id;
        $api = $this->middleware();

        self::assertSame(404, $this->send($api, '/_letterbox/api/v1/messages', 'POST')->getStatusCode());
        self::assertSame(404, $this->send($api, '/_letterbox/api/v1/messages/'.$id, 'PUT')->getStatusCode());
        self::assertSame(404, $this->send($api, '/_letterbox/api/v1/messages/'.$id.'/source', 'DELETE')->getStatusCode());
        self::assertSame(404, $this->send($api, '/_letterbox/api/v1/messages/latest', 'DELETE')->getStatusCode());
        self::assertSame(404, $this->send($api, '/_letterbox/api/v1/messages/')->getStatusCode());
        self::assertSame(404, $this->send($api, '/_letterbox/api/v1')->getStatusCode());
        self::assertSame(404, $this->send($api, '/_letterbox/api/v1/unknown')->getStatusCode());
        self::assertCount(1, $this->mails());
    }

    #[Test]
    public function listFiltersByToAndSubjectWithPaging(): void
    {
        $this->append('Reset your password', 'anna@example.org');
        $this->append('Welcome', 'bob@example.org');
        $this->append('Welcome Anna', 'anna@example.org');
        $api = $this->middleware();

        $all = $this->json($this->send($api, '/_letterbox/api/v1/messages'));
        $anna = $this->json($this->send($api, '/_letterbox/api/v1/messages?to=Anna@example.org'));
        $reset = $this->json($this->send($api, '/_letterbox/api/v1/messages?to=anna@example.org&subject=reset'));
        $page = $this->json($this->send($api, '/_letterbox/api/v1/messages?limit=1&offset=1'));

        self::assertSame(3, $all['total']);
        self::assertSame(['Welcome Anna', 'Welcome', 'Reset your password'], array_column($all['items'], 'subject'));
        self::assertSame(2, $anna['total']);
        self::assertSame(['Reset your password'], array_column($reset['items'], 'subject'));
        self::assertSame(3, $page['total']);
        self::assertSame(['Welcome'], array_column($page['items'], 'subject'));
        foreach (['limit=0', 'limit=101', 'limit=abc', 'limit=1.5', 'offset=-1', 'offset=x', 'to[]=anna@example.org', 'subject[a]=b'] as $query) {
            self::assertSame(404, $this->send($api, '/_letterbox/api/v1/messages?'.$query)->getStatusCode(), $query);
        }
    }

    #[Test]
    public function latestReturnsNewestForRecipient(): void
    {
        $this->append('First for Anna', 'anna@example.org');
        $this->append('Second for Anna', 'anna@example.org');
        $this->append('For Bob', 'bob@example.org');
        $api = $this->middleware();

        $latest = $this->json($this->send($api, '/_letterbox/api/v1/messages/latest?to=anna@example.org'));

        self::assertSame('Second for Anna', $latest['subject']);
        self::assertSame('For Bob', $this->json($this->send($api, '/_letterbox/api/v1/messages/latest'))['subject']);
        self::assertSame(404, $this->send($api, '/_letterbox/api/v1/messages/latest?to=nobody@example.org')->getStatusCode());
    }

    #[Test]
    public function detailCarriesLinksWithResetFlag(): void
    {
        $this->append('Reset your password', 'anna@example.org', '<p><a href="https://stage.example.org/login?tx_felogin_login%5Baction%5D=showChangePassword&amp;tx_felogin_login%5Bhash%5D=abc">Reset</a> <a href="https://stage.example.org/imprint">Imprint</a></p>');
        $id = $this->mails()[0]->id;
        $api = $this->middleware();

        $response = $this->send($api, '/_letterbox/api/v1/messages/'.$id);
        $detail = $this->json($response);

        self::assertSame('application/json; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame($id, $detail['id']);
        self::assertSame([
            ['url' => 'https://stage.example.org/login?tx_felogin_login%5Baction%5D=showChangePassword&tx_felogin_login%5Bhash%5D=abc', 'isPasswordReset' => true],
            ['url' => 'https://stage.example.org/imprint', 'isPasswordReset' => false],
        ], $detail['links']);
        self::assertStringContainsString('Imprint</a>', $detail['html']);
        self::assertSame([['index' => 0, 'filename' => 'a.txt', 'mimeType' => 'text/plain', 'size' => 8, 'contentId' => '']], $detail['attachments']);
        self::assertSame([['address' => 'anna@example.org', 'name' => '']], $detail['to']);
        self::assertSame(404, $this->send($api, '/_letterbox/api/v1/messages/'.str_repeat('0', 32))->getStatusCode());
        self::assertSame(404, $this->send($api, '/_letterbox/api/v1/messages/'.strtoupper($id))->getStatusCode());
    }

    #[Test]
    public function invalidUtf8InAMailStillEncodes(): void
    {
        $this->appendRaw("From: s@example.org\nTo: anna@example.org\nSubject: Broken\nMessage-ID: <broken@example.org>\nContent-Type: text/plain; charset=utf-8\n\nbroken \xC3\x28 byte\n");
        $api = $this->middleware();

        $response = $this->send($api, '/_letterbox/api/v1/messages/'.$this->mails()[0]->id);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('broken', $this->json($response)['text']);
    }

    #[Test]
    public function sourceAndAttachmentAreOctetStream(): void
    {
        $this->append('Hello', 'anna@example.org');
        $id = $this->mails()[0]->id;
        $api = $this->middleware();

        $source = $this->send($api, '/_letterbox/api/v1/messages/'.$id.'/source');
        $attachment = $this->send($api, '/_letterbox/api/v1/messages/'.$id.'/attachments/0');

        self::assertSame(200, $source->getStatusCode());
        self::assertSame('application/octet-stream', $source->getHeaderLine('Content-Type'));
        self::assertStringContainsString("Subject: Hello\r\n", (string) $source->getBody());
        self::assertSame(self::API_CSP, $source->getHeaderLine('Content-Security-Policy'));
        self::assertSame('application/octet-stream', $attachment->getHeaderLine('Content-Type'));
        self::assertSame('attached', (string) $attachment->getBody());
        self::assertSame('no-store, private', $attachment->getHeaderLine('Cache-Control'));
        self::assertSame(404, $this->send($api, '/_letterbox/api/v1/messages/'.$id.'/attachments/1')->getStatusCode());
        self::assertSame(404, $this->send($api, '/_letterbox/api/v1/messages/'.$id.'/attachments/x')->getStatusCode());
        self::assertSame(404, $this->send($api, '/_letterbox/api/v1/messages/'.$id.'/attachments/-1')->getStatusCode());
    }

    #[Test]
    public function deleteOneAndAll(): void
    {
        $this->append('One', 'anna@example.org');
        $this->append('Two', 'bob@example.org');
        $this->append('Three', 'carl@example.org');
        $id = $this->mails()[0]->id;
        $api = $this->middleware();

        $deleted = $this->send($api, '/_letterbox/api/v1/messages/'.$id, 'DELETE');

        self::assertSame(204, $deleted->getStatusCode());
        self::assertSame('', (string) $deleted->getBody());
        self::assertSame(self::API_CSP, $deleted->getHeaderLine('Content-Security-Policy'));
        self::assertSame(404, $this->send($api, '/_letterbox/api/v1/messages/'.$id, 'DELETE')->getStatusCode());
        self::assertSame(['Two', 'One'], array_map(static fn (Mail $mail): string => $mail->subject, $this->mails()));

        self::assertSame(204, $this->send($api, '/_letterbox/api/v1/messages', 'DELETE')->getStatusCode());
        self::assertSame([], $this->mails());
        self::assertSame(0, (int) filesize($this->path), 'deleting all clears the file');
    }

    #[Test]
    public function rootProductionApiIs404(): void
    {
        $this->append('Live', 'anna@example.org');
        $this->previousContext = Environment::getContext();
        $this->useContext(new ApplicationContext('Production'));
        $api = $this->middleware(['LETTERBOX_EXTERNAL_ACCESS' => 'Production', 'LETTERBOX_ACCESS_TOKEN' => self::TOKEN]);

        self::assertSame(404, $this->send($api, '/_letterbox/api/v1/messages')->getStatusCode());
        self::assertSame(404, $this->send($api, '/_letterbox/api/v1/messages', 'DELETE')->getStatusCode());
        self::assertCount(1, $this->mails());
    }

    #[Test]
    public function apiShowsAllRecipientsHeadersAndSource(): void
    {
        $this->append('Stage', 'qa@catch.example.org, anna@example.org');
        $id = $this->mails()[0]->id;
        $api = $this->middleware();

        $detail = $this->json($this->send($api, '/_letterbox/api/v1/messages/'.$id));

        self::assertSame(['qa@catch.example.org', 'anna@example.org'], array_column($detail['to'], 'address'));
        self::assertArrayHasKey('headers', $detail);
        self::assertSame(200, $this->send($api, '/_letterbox/api/v1/messages/'.$id.'/source')->getStatusCode());
    }

    #[Test]
    public function subDirectoryInstallUsesSitePath(): void
    {
        $api = $this->middleware();
        $server = ['SCRIPT_NAME' => '/sub/index.php'];

        $response = $this->send($api, '/sub/_letterbox/api/v1/messages', 'GET', self::HEADERS, $server);
        $outside = $this->send($api, '/_letterbox/api/v1/messages', 'GET', self::HEADERS, $server);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['total' => 0, 'items' => []], $this->json($response));
        self::assertTrue($outside->hasHeader('X-Passed-Through'));
    }

    #[Test]
    public function nonApiPathPassesThrough(): void
    {
        $api = $this->middleware();

        foreach (['/', '/news', '/_letterbox/api/v10/messages', '/_letterbox/api/v1x', '/_letterbox/inbox', '/x/_letterbox/api/v1/messages'] as $path) {
            $response = $this->send($api, $path);
            self::assertTrue($response->hasHeader('X-Passed-Through'), $path);
            self::assertFalse($response->hasHeader('Content-Security-Policy'), $path);
        }
    }

    /**
     * @param array<string, string> $environment
     */
    private function middleware(array $environment = ['LETTERBOX_EXTERNAL_ACCESS' => 'Testing', 'LETTERBOX_ACCESS_TOKEN' => self::TOKEN]): ApiMiddleware
    {
        $responseFactory = new ResponseFactory();
        $streamFactory = new StreamFactory();

        return new ApiMiddleware(
            new ExternalAccessGuard(new EnvironmentVariables($environment)),
            new ExternalResponses($responseFactory, $streamFactory),
            $this->get(MailRepository::class),
            new MailJson(),
            new DownloadResponseFactory($responseFactory, $streamFactory, new MboxFormat()),
            $responseFactory,
        );
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $server
     */
    private function send(ApiMiddleware $api, string $pathAndQuery, string $method = 'GET', array $headers = self::HEADERS, array $server = []): ResponseInterface
    {
        return $api->process($this->request($pathAndQuery, $method, $headers, $server), new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return (new Response())->withHeader('X-Passed-Through', '1');
            }
        });
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $server
     */
    private function request(string $pathAndQuery, string $method, array $headers, array $server): ServerRequestInterface
    {
        $server += ['HTTPS' => 'on', 'HTTP_HOST' => 'stage.example.org', 'SERVER_NAME' => 'stage.example.org', 'SCRIPT_NAME' => '/index.php', 'REMOTE_ADDR' => '192.0.2.10'];
        $request = new ServerRequest('https://stage.example.org'.$pathAndQuery, $method, 'php://temp', $headers, $server);
        parse_str($request->getUri()->getQuery(), $query);
        $request = $request->withQueryParams($query);

        return $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
    }

    /**
     * @return array<string, mixed>
     */
    private function json(ResponseInterface $response): array
    {
        $data = json_decode((string) $response->getBody(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @return list<Mail> newest first
     */
    private function mails(): array
    {
        $this->get(CacheManager::class)->getCache('typo3_letterbox_index')->flush();

        return $this->get(MailRepository::class)->find(MailFilter::all(), 0, 100);
    }

    /**
     * @param array<string, string> $values
     */
    private function putEnvironment(array $values): void
    {
        foreach ($values as $name => $value) {
            putenv($name.'='.$value);
        }
    }

    private function useContext(ApplicationContext $context): void
    {
        Environment::initialize(
            $context,
            Environment::isCli(),
            Environment::isComposerMode(),
            Environment::getProjectPath(),
            Environment::getPublicPath(),
            Environment::getVarPath(),
            Environment::getConfigPath(),
            Environment::getCurrentScript(),
            Environment::isWindows() ? 'WINDOWS' : 'UNIX',
        );
    }

    private function append(string $subject, string $to, string $html = '<p>body</p>'): void
    {
        $this->appendRaw("From: Sender <s@example.org>\nTo: {$to}\nSubject: {$subject}\nMessage-ID: <".bin2hex(random_bytes(4))."@example.org>\n"
            ."MIME-Version: 1.0\nContent-Type: multipart/mixed; boundary=\"b\"\n\n--b\nContent-Type: text/html; charset=utf-8\n\n{$html}\n--b\n"
            ."Content-Type: text/plain; name=\"a.txt\"\nContent-Disposition: attachment; filename=\"a.txt\"\n\nattached\n--b--\n");
    }

    private function appendRaw(string $raw): void
    {
        (new MailboxWriter($this->path, new MboxFormat(), new MailboxLock($this->get(LockFactory::class))))
            ->append('s@example.org', new DateTimeImmutable('2026-10-27 10:00:00'), $raw);
    }
}
