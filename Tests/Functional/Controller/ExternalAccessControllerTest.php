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

namespace KonradMichalik\Typo3Letterbox\Tests\Functional\Controller;

use KonradMichalik\Typo3Letterbox\Controller\ExternalAccessController;
use KonradMichalik\Typo3Letterbox\External\{EnvironmentVariables, ExternalAccessGuard};
use KonradMichalik\Typo3Letterbox\External\Tester\TesterLinkRepository;
use KonradMichalik\Typo3Letterbox\Mail\LetterboxTransport;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, ServerRequestInterface};
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\{Router, UriBuilder};
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Core\{Environment, SystemEnvironmentBuilder};
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function is_string;
use function strlen;

/**
 * ExternalAccessControllerTest.
 *
 * Like InboxControllerTest, the controller is called directly with a hand-built backend request.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ExternalAccessControllerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3_letterbox',
    ];

    /** @var array<string, string> */
    private array $environment = [];

    private BackendUserAuthentication $backendUser;

    protected function setUp(): void
    {
        parent::setUp();

        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = LetterboxTransport::class;
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_mbox_file'] = Environment::getVarPath().'/letterbox-test/access.mbox';

        $this->importCSVDataSet(__DIR__.'/../Fixtures/be_users.csv');
        $this->backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($this->backendUser);
    }

    #[Test]
    public function statusListsReasonsWhenInactive(): void
    {
        $body = (string) $this->controller()->indexAction($this->request('system_letterbox.access'))->getBody();

        self::assertStringContainsString('External access is off', $body);
        self::assertStringContainsString('Set the environment variable LETTERBOX_EXTERNAL_ACCESS to Testing', $body);
        self::assertStringContainsString('API token: not configured', $body);
    }

    #[Test]
    public function statusIsActiveWhenGuardPasses(): void
    {
        $this->environment = ['LETTERBOX_EXTERNAL_ACCESS' => 'Testing', 'LETTERBOX_API_TOKEN' => str_repeat('a', 40)];

        $body = (string) $this->controller()->indexAction($this->request('system_letterbox.access'))->getBody();

        self::assertStringContainsString('External access is on', $body);
        self::assertStringNotContainsString('Set the environment variable', $body);
        self::assertStringContainsString('API token: configured', $body);
        self::assertStringNotContainsString(str_repeat('a', 40), $body);
        self::assertStringNotContainsString('40 characters', $body);
    }

    #[Test]
    public function shortApiTokenCountsAsNotConfigured(): void
    {
        $this->environment = ['LETTERBOX_API_TOKEN' => 'short-secret'];

        $body = (string) $this->controller()->indexAction($this->request('system_letterbox.access'))->getBody();

        self::assertStringContainsString('API token: not configured', $body);
        self::assertStringNotContainsString('short-secret', $body);
    }

    #[Test]
    public function createShowsUrlOnceAndStoresHash(): void
    {
        $request = $this->post('system_letterbox.access_create', ['label' => 'Test link', 'scope' => '*@example.org', 'days' => '7', 'canDownload' => '1']);

        $response = $this->controller()->createAction($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $token = $this->tokenFrom((string) $response->getBody());
        self::assertSame(64, strlen($token));
        self::assertStringContainsString('typo3-copy-to-clipboard', (string) $response->getBody());
        $links = $this->repository()->findAll();
        self::assertCount(1, $links);
        self::assertSame('Test link', $links[0]->label);
        self::assertTrue($links[0]->canDownload);
        self::assertFalse($links[0]->canDelete);
        self::assertSame($links[0]->uid, $this->repository()->findByToken($token)?->uid);
        self::assertStringNotContainsString($token, json_encode($this->rows(), \JSON_THROW_ON_ERROR));

        $second = (string) $this->controller()->indexAction($this->request('system_letterbox.access'))->getBody();
        self::assertStringNotContainsString($token, $second);
        self::assertStringContainsString('Test link', $second);
        self::assertStringContainsString('admin', $second);
    }

    #[Test]
    public function issuedUrlUsesSitePath(): void
    {
        $request = $this->post('system_letterbox.access_create', ['label' => 'Sub', 'scope' => '*@example.org', 'days' => '1'], ['SCRIPT_NAME' => '/sub/index.php']);

        $body = (string) $this->controller()->createAction($request)->getBody();

        self::assertMatchesRegularExpression('#https://typo3-testing\.local/sub/_letterbox/inbox\?t=[0-9a-f]{64}#', $body);
    }

    #[Test]
    public function createRejectsInvalidPatternWithMessage(): void
    {
        $request = $this->post('system_letterbox.access_create', ['label' => 'Bad', 'scope' => "ok@example.org\nnot an address", 'days' => '7']);

        $response = $this->controller()->createAction($request);

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('Line 2 is not a valid address or pattern', $body);
        self::assertStringContainsString('aria-describedby="letterbox-access-scope-help letterbox-access-errors"', $body);
        self::assertStringContainsString('not an address', $body);
        self::assertStringContainsString('value="Bad"', $body);
        self::assertSame([], $this->repository()->findAll());
        self::assertDoesNotMatchRegularExpression('/\?t=[0-9a-f]{64}/', $body);
    }

    #[Test]
    public function createRejectsEmptyScopeWithoutUnrestricted(): void
    {
        $body = (string) $this->controller()->createAction($this->post('system_letterbox.access_create', ['label' => 'None', 'scope' => '  ', 'days' => '7']))->getBody();

        self::assertStringContainsString('Enter at least one address or pattern', $body);
        self::assertSame([], $this->repository()->findAll());
    }

    #[Test]
    public function createRejectsMissingLabelAndUnrestrictedWithPatterns(): void
    {
        $missing = (string) $this->controller()->createAction($this->post('system_letterbox.access_create', ['label' => ' ', 'scope' => 'a@example.org', 'days' => '7']))->getBody();
        $mixed = (string) $this->controller()->createAction($this->post('system_letterbox.access_create', ['label' => 'Mix', 'scope' => 'a@example.org', 'unrestricted' => '1', 'days' => '7']))->getBody();

        self::assertStringContainsString('Enter a label', $missing);
        self::assertStringContainsString('Remove the patterns or clear', $mixed);
        self::assertSame([], $this->repository()->findAll());
    }

    #[Test]
    public function createAcceptsUnrestrictedWithoutPatterns(): void
    {
        $response = $this->controller()->createAction($this->post('system_letterbox.access_create', ['label' => 'All', 'scope' => '', 'unrestricted' => '1', 'days' => '30']));

        self::assertSame(64, strlen($this->tokenFrom((string) $response->getBody())));
        self::assertTrue($this->repository()->findAll()[0]->unrestricted);
    }

    #[Test]
    public function createAndRevokeRequirePostAndFormToken(): void
    {
        $controller = $this->controller();
        $body = ['label' => 'X', 'scope' => 'a@example.org', 'days' => '7', 'uid' => '1'];

        self::assertSame(405, $controller->createAction($this->request('system_letterbox.access_create'))->getStatusCode());
        self::assertSame(405, $controller->revokeAction($this->request('system_letterbox.access_revoke'))->getStatusCode());
        self::assertSame(403, $controller->createAction($this->request('system_letterbox.access_create', 'POST', $body))->getStatusCode());
        self::assertSame(403, $controller->revokeAction($this->request('system_letterbox.access_revoke', 'POST', $body + ['letterboxToken' => 'wrong']))->getStatusCode());
        self::assertSame([], $this->repository()->findAll());
    }

    #[Test]
    public function revokeMarksLinkRevoked(): void
    {
        $repository = $this->repository();
        $issued = $repository->issue('Gone', [], true, false, false, 7, 1, time());

        $response = $this->controller()->revokeAction($this->post('system_letterbox.access_revoke', ['uid' => (string) $issued->link->uid]));

        self::assertSame(303, $response->getStatusCode());
        self::assertGreaterThan(0, $repository->findAll()[0]->revoked);
        self::assertNull($this->tokenOrNull($this->controller()->indexAction($this->request('system_letterbox.access'))));
        self::assertSame(404, $this->controller()->revokeAction($this->post('system_letterbox.access_revoke', ['uid' => '9999']))->getStatusCode());
    }

    #[Test]
    public function tableShowsStateScopeAndPermissions(): void
    {
        $repository = $this->repository();
        $repository->issue('Active one', [], true, true, true, 7, 1, time());
        $revoked = $repository->issue('Revoked one', [], true, false, false, 7, 1, time());
        $repository->revoke($revoked->link->uid, time());
        $repository->issue('Expired one', [], true, false, false, 1, 1, time() - 3 * 86400);

        $body = (string) $this->controller()->indexAction($this->request('system_letterbox.access'))->getBody();

        self::assertStringContainsString('Active one', $body);
        self::assertSame(1, preg_match_all('/<th scope="row"[^>]*>\s*Active one/', $body));
        self::assertStringContainsString('All mails', $body);
        self::assertStringContainsString('Revoked', $body);
        self::assertStringContainsString('Expired', $body);
        self::assertSame(2, substr_count($body, 'name="uid"'));
    }

    private function repository(): TesterLinkRepository
    {
        return new TesterLinkRepository($this->get(ConnectionPool::class));
    }

    private function controller(): ExternalAccessController
    {
        $environment = new EnvironmentVariables($this->environment);

        return new ExternalAccessController(
            $this->get(ModuleTemplateFactory::class),
            new ExternalAccessGuard($environment, $this->get(ExtensionConfiguration::class)),
            $this->repository(),
            $environment,
            $this->get(FormProtectionFactory::class),
            $this->get(UriBuilder::class),
            $this->get(ResponseFactoryInterface::class),
            $this->get(PageRenderer::class),
            $this->get(Context::class),
            $this->get(FlashMessageService::class),
            $this->get(ConnectionPool::class),
            $this->get(IconFactory::class),
        );
    }

    /**
     * @param array<string, string> $body
     * @param array<string, string> $server
     */
    private function post(string $route, array $body, array $server = []): ServerRequestInterface
    {
        $unsigned = $this->request($route, 'POST', [], $server);

        return $this->request($route, 'POST', $body + ['letterboxToken' => $this->get(FormProtectionFactory::class)->createFromRequest($unsigned)->generateToken('letterbox')], $server);
    }

    /**
     * @param array<string, string> $body
     * @param array<string, string> $server
     */
    private function request(string $route, string $method = 'GET', array $body = [], array $server = []): ServerRequestInterface
    {
        $uri = (string) $this->get(UriBuilder::class)->buildUriFromRoute($route);
        $server += ['HTTPS' => 'on', 'HTTP_HOST' => 'typo3-testing.local', 'SERVER_NAME' => 'typo3-testing.local', 'SCRIPT_NAME' => '/index.php'];
        $request = (new ServerRequest('https://typo3-testing.local'.$uri, $method, 'php://temp', [], $server))
            ->withParsedBody($body)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $this->get(Router::class)->getRoute($route))
            ->withAttribute('module', $this->get(ModuleProvider::class)->getModule('system_letterbox', $this->backendUser));
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }

    private function tokenFrom(string $body): string
    {
        $token = $this->tokenOrNull($body);
        self::assertNotNull($token, 'No tester URL in the response.');

        return $token;
    }

    private function tokenOrNull(ResponseInterface|string $body): ?string
    {
        $html = is_string($body) ? $body : (string) $body->getBody();

        return 1 === preg_match('/\?t=([0-9a-f]{64})/', $html, $matches) ? $matches[1] : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(): array
    {
        return $this->getConnectionPool()->getConnectionForTable(TesterLinkRepository::TABLE)->select(['*'], TesterLinkRepository::TABLE)->fetchAllAssociative();
    }
}
