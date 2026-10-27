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

use DateTimeImmutable;
use KonradMichalik\Typo3Letterbox\Controller\InboxController;
use KonradMichalik\Typo3Letterbox\Domain\Model\MailFilter;
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use KonradMichalik\Typo3Letterbox\Mbox\{MailboxLock, MailboxWriter, MboxFormat};
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleProvider;
use TYPO3\CMS\Backend\Routing\{Router, UriBuilder};
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\{Environment, SystemEnvironmentBuilder};
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function dirname;

/**
 * InboxControllerTest.
 *
 * The controller is called directly with a backend request carrying the route and module
 * attributes, because the testing framework offers no backend sub-request.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class InboxControllerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3_letterbox',
    ];

    private string $path = '';

    private BackendUserAuthentication $backendUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = Environment::getVarPath().'/letterbox-test/inbox.mbox';
        GeneralUtility::mkdir_deep(dirname($this->path));
        if (is_file($this->path)) {
            unlink($this->path);
        }
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_mbox_file'] = $this->path;
        $this->get(CacheManager::class)->getCache('typo3_letterbox_index')->flush();

        $this->importCSVDataSet(__DIR__.'/../Fixtures/be_users.csv');
        $this->backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($this->backendUser);
    }

    #[Test]
    public function indexListsMailsNewestFirst(): void
    {
        $this->append('First subject');
        $this->append('Second subject');

        $response = $this->controller()->indexAction($this->request('system_letterbox'));

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('Mail inbox', $body);
        self::assertGreaterThan(0, strpos($body, 'First subject'));
        self::assertLessThan(strpos($body, 'First subject'), strpos($body, 'Second subject'));
    }

    #[Test]
    public function indexShowsEmptyStateWhenFileMissing(): void
    {
        $response = $this->controller()->indexAction($this->request('system_letterbox'));

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('callout', $body);
        self::assertStringContainsString('No mails yet', $body);
        self::assertStringContainsString($this->path, $body);
    }

    #[Test]
    public function indexExplainsConfigurationWhenNotConfigured(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_mbox_file'] = '';

        $response = $this->controller()->indexAction($this->request('system_letterbox'));

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('Letterbox is not configured', $body);
        self::assertStringContainsString('transport_mbox_file', $body);
    }

    #[Test]
    public function showRendersIframeWithSrcdocAndSandbox(): void
    {
        $this->append('Hostile', '<p>Hello</p><script>alert(1)</script>');
        $id = $this->firstId();

        $response = $this->controller()->showAction($this->request('system_letterbox.show', ['id' => $id]));

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('sandbox="allow-popups allow-popups-to-escape-sandbox"', $body);
        self::assertStringContainsString('srcdoc="', $body);
        self::assertStringContainsString('&lt;p&gt;Hello&lt;/p&gt;', $body);
        self::assertStringNotContainsString('<script>alert', $body);
        self::assertStringNotContainsString('<p>Hello</p>', $body);
        self::assertStringContainsString('title="Mail preview: Hostile"', $body);
        self::assertStringContainsString('aria-label="Delete mail: Hostile"', $body);
        self::assertSame(1, substr_count($body, 'aria-current="true"'));
    }

    #[Test]
    public function clearRejectsGet(): void
    {
        $this->append('Keep me');
        $before = (string) file_get_contents($this->path);

        $response = $this->controller()->clearAction($this->request('system_letterbox.clear'));

        self::assertSame(405, $response->getStatusCode());
        self::assertSame($before, file_get_contents($this->path));
    }

    #[Test]
    public function clearRejectsMissingToken(): void
    {
        $this->append('Keep me');
        $before = (string) file_get_contents($this->path);

        $response = $this->controller()->clearAction($this->request('system_letterbox.clear', [], 'POST', ['letterboxToken' => 'invalid']));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame($before, file_get_contents($this->path));
    }

    #[Test]
    public function clearWithValidTokenEmptiesTheMailbox(): void
    {
        $this->append('Gone');
        $request = $this->request('system_letterbox.clear', [], 'POST');
        $request = $request->withParsedBody(['letterboxToken' => $this->token($request)]);

        $response = $this->controller()->clearAction($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(0, $this->get(MailRepository::class)->count(MailFilter::all()));
    }

    #[Test]
    public function deleteWithValidTokenRemovesMail(): void
    {
        $this->append('Stays');
        $this->append('Goes');
        $id = $this->firstId();
        $request = $this->request('system_letterbox.delete', [], 'POST');
        $request = $request->withParsedBody(['id' => $id, 'letterboxToken' => $this->token($request)]);

        $response = $this->controller()->deleteAction($request);

        self::assertSame(303, $response->getStatusCode());
        $mails = $this->get(MailRepository::class)->find(MailFilter::all(), 0, 10);
        self::assertSame(['Stays'], array_map(static fn ($mail): string => $mail->subject, $mails));
    }

    #[Test]
    public function deleteRejectsMissingToken(): void
    {
        $this->append('Keep me');
        $before = (string) file_get_contents($this->path);

        $response = $this->controller()->deleteAction($this->request('system_letterbox.delete', [], 'POST', ['id' => $this->firstId()]));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame($before, file_get_contents($this->path));
    }

    #[Test]
    public function unknownIdAnswers404(): void
    {
        $this->append('Known');
        $controller = $this->controller();

        self::assertSame(404, $controller->showAction($this->request('system_letterbox.show', ['id' => str_repeat('0', 32)]))->getStatusCode());
        self::assertSame(404, $controller->showAction($this->request('system_letterbox.show', ['id' => '../etc/passwd']))->getStatusCode());
        self::assertSame(404, $controller->sourceAction($this->request('system_letterbox.source', ['id' => str_repeat('a', 32)]))->getStatusCode());
        self::assertSame(404, $controller->attachmentAction($this->request('system_letterbox.attachment', ['id' => $this->firstId(), 'index' => '-1']))->getStatusCode());
        self::assertSame(404, $controller->attachmentAction($this->request('system_letterbox.attachment', ['id' => $this->firstId(), 'index' => '7']))->getStatusCode());
    }

    #[Test]
    public function sourceAndAttachmentDownloadAsOctetStream(): void
    {
        $this->append('Download');
        $id = $this->firstId();
        $controller = $this->controller();

        $source = $controller->sourceAction($this->request('system_letterbox.source', ['id' => $id]));
        $attachment = $controller->attachmentAction($this->request('system_letterbox.attachment', ['id' => $id, 'index' => '0']));

        self::assertSame('application/octet-stream', $source->getHeaderLine('Content-Type'));
        self::assertStringContainsString('Subject: Download', (string) $source->getBody());
        self::assertSame('attached', trim((string) $attachment->getBody()));
    }

    #[Test]
    public function mutatingRoutesAcceptPostOnly(): void
    {
        $router = $this->get(Router::class);

        foreach (['system_letterbox.clear', 'system_letterbox.delete'] as $name) {
            $route = $router->getRoute($name);
            self::assertNotNull($route);
            self::assertSame(['POST'], $route->getMethods());
        }
    }

    #[Test]
    public function moduleIsHiddenWhenEnableBackendModuleIsOff(): void
    {
        $modules = static fn (): mixed => require __DIR__.'/../../../Configuration/Backend/Modules.php';

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_letterbox']['enableBackendModule'] = '1';
        self::assertArrayHasKey('system_letterbox', $modules());

        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_letterbox']['enableBackendModule'] = '0';
        self::assertSame([], $modules());
    }

    private function controller(): InboxController
    {
        return $this->get(InboxController::class);
    }

    /**
     * @param array<string, string> $query
     * @param array<string, string> $body
     */
    private function request(string $route, array $query = [], string $method = 'GET', array $body = []): ServerRequestInterface
    {
        $uri = (string) $this->get(UriBuilder::class)->buildUriFromRoute($route, $query);
        $request = (new ServerRequest('https://typo3-testing.local'.$uri, $method))
            ->withQueryParams($query)
            ->withParsedBody($body)
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $this->get(Router::class)->getRoute($route))
            ->withAttribute('module', $this->get(ModuleProvider::class)->getModule('system_letterbox', $this->backendUser));
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }

    private function token(ServerRequestInterface $request): string
    {
        return $this->get(FormProtectionFactory::class)->createFromRequest($request)->generateToken('letterbox');
    }

    private function firstId(): string
    {
        return $this->get(MailRepository::class)->find(MailFilter::all(), 0, 1)[0]->id;
    }

    private function append(string $subject, string $html = '<p>body</p>'): void
    {
        $raw = "From: Sender <s@example.org>\nTo: Recipient <r@example.org>\nSubject: {$subject}\nMessage-ID: <".bin2hex(random_bytes(4))."@example.org>\n"
            ."MIME-Version: 1.0\nContent-Type: multipart/mixed; boundary=\"b\"\n\n--b\nContent-Type: text/html; charset=utf-8\n\n{$html}\n--b\n"
            ."Content-Type: text/plain; name=\"a.txt\"\nContent-Disposition: attachment; filename=\"a.txt\"\n\nattached\n--b--\n";
        $writer = new MailboxWriter($this->path, new MboxFormat(), new MailboxLock($this->get(LockFactory::class)));
        $writer->append('s@example.org', new DateTimeImmutable('2026-10-27 10:00:00'), $raw);
    }
}
