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
use KonradMichalik\Typo3Letterbox\Mail\LetterboxTransport;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\{Router, UriBuilder};
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\{Environment, SystemEnvironmentBuilder};
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * ExternalAccessControllerTest.
 *
 * Like InboxControllerTest, the controller is called directly with a hand-built backend request.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ExternalAccessControllerTest extends FunctionalTestCase
{
    private const TOKEN = 'example-access-token-0123456789abcdef';

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
        $body = $this->page();

        self::assertStringContainsString('External access is off', $body);
        self::assertStringContainsString('Set the environment variable LETTERBOX_EXTERNAL_ACCESS to Testing', $body);
        self::assertStringContainsString('Set the environment variable LETTERBOX_ACCESS_TOKEN to a secret of at least 32 characters', $body);
        self::assertStringContainsString('Not configured', $body);
    }

    #[Test]
    public function statusIsActiveWhenGuardPasses(): void
    {
        $this->environment = ['LETTERBOX_EXTERNAL_ACCESS' => 'Testing', 'LETTERBOX_ACCESS_TOKEN' => self::TOKEN];

        $body = $this->page();

        self::assertStringContainsString('External access is on', $body);
        self::assertStringNotContainsString('Set the environment variable', $body);
        self::assertStringContainsString('Configured', $body);
        self::assertStringNotContainsString('Not configured', $body);
        self::assertStringNotContainsString(self::TOKEN, $body);
        self::assertStringNotContainsString(hash_hmac('sha256', 'inbox', self::TOKEN), $body);
    }

    #[Test]
    public function shortTokenCountsAsNotConfigured(): void
    {
        $this->environment = ['LETTERBOX_EXTERNAL_ACCESS' => 'Testing', 'LETTERBOX_ACCESS_TOKEN' => 'short-secret'];

        $body = $this->page();

        self::assertStringContainsString('External access is off', $body);
        self::assertStringContainsString('Not configured', $body);
        self::assertStringNotContainsString('short-secret', $body);
    }

    #[Test]
    public function pageShowsInboxAndApiUrlsWithoutToken(): void
    {
        $this->environment = ['LETTERBOX_EXTERNAL_ACCESS' => 'Testing', 'LETTERBOX_ACCESS_TOKEN' => self::TOKEN];

        $body = $this->page(['SCRIPT_NAME' => '/sub/index.php']);

        self::assertStringContainsString('<code class="text-break">https://typo3-testing.local/sub/_letterbox/inbox</code>', $body);
        self::assertStringContainsString('<code class="text-break">https://typo3-testing.local/sub/_letterbox/api/v1</code>', $body);
        self::assertStringContainsString('Share the link https://typo3-testing.local/sub/_letterbox/inbox?t=&lt;token&gt; with your testers. Changing the token revokes access for everyone.', $body);
        self::assertStringContainsString('curl -H "X-Letterbox-Token: &lt;token&gt;" https://typo3-testing.local/sub/_letterbox/api/v1/messages', $body);
        self::assertStringContainsString('Everyone with the inbox link or the token sees every caught mail of this system.', $body);
    }

    #[Test]
    public function linkRoutesAreGone(): void
    {
        $router = $this->get(Router::class);

        self::assertTrue($router->hasRoute('system_letterbox.access'));
        self::assertFalse($router->hasRoute('system_letterbox.access_create'));
        self::assertFalse($router->hasRoute('system_letterbox.access_revoke'));
    }

    /**
     * @param array<string, string> $server
     */
    private function page(array $server = []): string
    {
        $controller = new ExternalAccessController(
            $this->get(ModuleTemplateFactory::class),
            new ExternalAccessGuard(new EnvironmentVariables($this->environment)),
            $this->get(UriBuilder::class),
            $this->get(PageRenderer::class),
            $this->get(IconFactory::class),
        );

        return (string) $controller->indexAction($this->request($server))->getBody();
    }

    /**
     * @param array<string, string> $server
     */
    private function request(array $server): ServerRequestInterface
    {
        $route = 'system_letterbox.access';
        $uri = (string) $this->get(UriBuilder::class)->buildUriFromRoute($route);
        $server += ['HTTPS' => 'on', 'HTTP_HOST' => 'typo3-testing.local', 'SERVER_NAME' => 'typo3-testing.local', 'SCRIPT_NAME' => '/index.php'];
        $request = (new ServerRequest('https://typo3-testing.local'.$uri, 'GET', 'php://temp', [], $server))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $this->get(Router::class)->getRoute($route))
            ->withAttribute('module', $this->get(ModuleProvider::class)->getModule('system_letterbox', $this->backendUser));
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }
}
