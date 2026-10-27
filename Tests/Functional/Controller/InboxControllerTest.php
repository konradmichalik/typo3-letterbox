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
use DateTimeZone;
use KonradMichalik\Typo3Letterbox\Backend\ReadState;
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
use TYPO3\CMS\Core\Context\{Context, DateTimeAspect};
use TYPO3\CMS\Core\Core\{Environment, SystemEnvironmentBuilder};
use TYPO3\CMS\Core\FormProtection\FormProtectionFactory;
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function dirname;
use function function_exists;

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
        self::assertStringContainsString('No mails caught yet', $body);
        self::assertStringContainsString('aria-hidden="true" focusable="false"', $body);
        self::assertStringNotContainsString('letterbox:testmails:send', $body);
    }

    #[Test]
    public function emptyStateShowsTestMailCommandInDebugMode(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_letterbox']['debugMode'] = '1';

        $body = (string) $this->controller()->indexAction($this->request('system_letterbox'))->getBody();

        self::assertStringContainsString('letterbox:testmails:send', $body);
    }

    #[Test]
    public function indexShowsEmptyStateForEmptyFile(): void
    {
        file_put_contents($this->path, '');

        $response = $this->controller()->indexAction($this->request('system_letterbox'));

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('No mails caught yet', (string) $response->getBody());
    }

    #[Test]
    public function mailWithoutMessageIdFromAndSubjectIsListedAndOpens(): void
    {
        $this->appendRaw("To: r@example.org\nContent-Type: text/plain; charset=utf-8\n\nJust a body\n");

        $index = $this->controller()->indexAction($this->request('system_letterbox'));
        self::assertSame(200, $index->getStatusCode());
        self::assertStringNotContainsString('No mails caught yet', (string) $index->getBody());

        $show = $this->controller()->showAction($this->request('system_letterbox.show', ['id' => $this->firstId()]));
        self::assertSame(200, $show->getStatusCode());
        self::assertStringContainsString('Just a body', (string) $show->getBody());
    }

    #[Test]
    public function indexExplainsConfigurationWhenNotConfigured(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_mbox_file'] = '';

        $response = $this->controller()->indexAction($this->request('system_letterbox'));

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('Letterbox is not configured', $body);
        self::assertStringNotContainsString('0 mails', $body);
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
        self::assertSame(1, substr_count($body, 'class="letterbox-row text-reset text-decoration-none" aria-current="true"'));
    }

    #[Test]
    public function showEscapesHostileMetadata(): void
    {
        $subject = '"><img src=x onerror=alert(1)>';
        $raw = "From: \"Evil\\\"<>Name\" <s@example.org>\nTo: r@example.org\nSubject: {$subject}\nMessage-ID: <hostile@example.org>\n"
            ."MIME-Version: 1.0\nContent-Type: multipart/mixed; boundary=\"b\"\n\n--b\nContent-Type: text/html; charset=utf-8\n\n<p>hi</p>\n--b\n"
            ."Content-Type: text/plain\nContent-Disposition: attachment; filename*=utf-8''a%22%3C%3E.txt\n\nattached\n--b--\n";
        $this->writer()->append('s@example.org', new DateTimeImmutable('2026-10-27 10:00:00'), $raw);
        $mail = $this->get(MailRepository::class)->find(MailFilter::all(), 0, 1)[0];
        self::assertSame($subject, $mail->subject);
        self::assertSame('Evil"<>Name', $mail->from?->name);
        self::assertSame('a"<>.txt', $mail->attachments[0]->filename);

        $body = (string) $this->controller()->showAction($this->request('system_letterbox.show', ['id' => $mail->id]))->getBody();

        $escapedSubject = '&quot;&gt;&lt;img src=x onerror=alert(1)&gt;';
        self::assertStringNotContainsString('<img src=x', $body);
        self::assertStringNotContainsString('Evil"<>Name', $body);
        self::assertStringNotContainsString('a"<>.txt', $body);
        self::assertStringContainsString('title="Mail preview: '.$escapedSubject.'"', $body);
        self::assertStringContainsString('aria-label="Delete mail: '.$escapedSubject.'"', $body);
        self::assertStringContainsString('title="Download .eml: '.$escapedSubject.'"', $body);
        self::assertStringContainsString('Evil&quot;&lt;&gt;Name', $body);
        self::assertStringContainsString('a&quot;&lt;&gt;.txt', $body);
    }

    #[Test]
    public function showListsHeadersAsDefinitionList(): void
    {
        $this->appendRaw("From: Sender <s@example.org>\nTo: Anna <a@example.org>, b@example.org\nCc: c@example.org\nSubject: Headers\nDate: Tue, 27 Oct 2026 10:00:00 +0000\n\nbody\n");

        $body = (string) $this->controller()->showAction($this->request('system_letterbox.show', ['id' => $this->firstId()]))->getBody();

        self::assertMatchesRegularExpression('/<dt class="text-variant">From<\/dt>\s*<dd><span class="fw-semibold">Sender<\/span> <span class="text-variant">&lt;s@example.org&gt;<\/span><\/dd>/', $body);
        self::assertMatchesRegularExpression('/<dt class="text-variant">To<\/dt>\s*<dd>\s*<span class="fw-semibold">Anna<\/span> <span class="text-variant">&lt;a@example.org&gt;<\/span>, b@example.org\s*<\/dd>/', $body);
        self::assertMatchesRegularExpression('/<dt class="text-variant">Cc<\/dt>\s*<dd>\s*c@example.org\s*<\/dd>/', $body);
        self::assertMatchesRegularExpression('/<dt class="text-variant">Date<\/dt>\s*<dd>\s*2026-10-27/', $body);
    }

    #[Test]
    public function metaLineUsesSingularForOneMail(): void
    {
        $this->append('Only one');

        $body = (string) $this->controller()->indexAction($this->request('system_letterbox'))->getBody();

        self::assertStringContainsString('1 mail ', $body);
        self::assertStringNotContainsString('1 mails', $body);
    }

    #[Test]
    public function textOnlyMailHasNoHtmlTab(): void
    {
        $this->appendRaw("From: s@example.org\nTo: r@example.org\nSubject: Plain\n\nJust text.\n");

        $body = (string) $this->controller()->showAction($this->request('system_letterbox.show', ['id' => $this->firstId()]))->getBody();

        self::assertStringNotContainsString('letterbox-tab-html', $body);
        self::assertStringNotContainsString('No HTML preview available', $body);
        self::assertMatchesRegularExpression('/id="letterbox-tab-text"[^>]*aria-selected="true"/', $body);
    }

    #[Test]
    public function listShowsTimeTodayYesterdayOrDate(): void
    {
        $timezone = new DateTimeZone(date_default_timezone_get());
        $now = new DateTimeImmutable('2026-10-27 12:00:00', $timezone);
        $this->get(Context::class)->setAspect('date', new DateTimeAspect($now));
        foreach (['older' => '2026-10-01 08:00:00', 'yesterday' => '2026-10-26 20:15:00', 'today' => '2026-10-27 09:30:00'] as $subject => $date) {
            $header = (new DateTimeImmutable($date, $timezone))->format(\DATE_RFC2822);
            $this->appendRaw("From: s@example.org\nTo: r@example.org\nSubject: {$subject}\nDate: {$header}\n\nbody\n");
        }

        $body = (string) $this->controller()->indexAction($this->request('system_letterbox'))->getBody();

        self::assertMatchesRegularExpression('/[> ]today<.*?<small class="text-nowrap">09:30<\/small>/s', $body);
        self::assertMatchesRegularExpression('/[> ]yesterday<.*?<small class="text-nowrap">Yesterday<\/small>/s', $body);
        self::assertMatchesRegularExpression('/[> ]older<.*?<small class="text-nowrap">2026-10-01<\/small>/s', $body);
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
    public function clearOnReadOnlyFileRedirectsWithErrorFlashMessage(): void
    {
        $this->skipWhenRoot();
        $this->append('Stays');
        chmod($this->path, 0o444);
        $request = $this->request('system_letterbox.clear', [], 'POST');
        $request = $request->withParsedBody(['letterboxToken' => $this->token($request)]);

        try {
            $response = $this->controller()->clearAction($request);
        } finally {
            chmod($this->path, 0o644);
        }

        self::assertSame(303, $response->getStatusCode());
        $this->assertErrorFlashMessage('could not be cleared');
        self::assertSame(1, $this->get(MailRepository::class)->count(MailFilter::all()));
    }

    #[Test]
    public function deleteInReadOnlyDirectoryRedirectsWithErrorFlashMessage(): void
    {
        $this->skipWhenRoot();
        $this->append('Stays');
        $id = $this->firstId();
        $request = $this->request('system_letterbox.delete', [], 'POST');
        $request = $request->withParsedBody(['id' => $id, 'letterboxToken' => $this->token($request)]);
        chmod(dirname($this->path), 0o555);

        try {
            $response = $this->controller()->deleteAction($request);
        } finally {
            chmod(dirname($this->path), 0o755);
        }

        self::assertSame(303, $response->getStatusCode());
        $this->assertErrorFlashMessage('could not be deleted');
        self::assertSame(1, $this->get(MailRepository::class)->count(MailFilter::all()));
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

    #[Test]
    public function searchNarrowsTheList(): void
    {
        $this->append('Password reset');
        $this->append('Newsletter');

        $body = (string) $this->controller()->indexAction($this->request('system_letterbox', ['q' => 'password']))->getBody();

        self::assertStringContainsString('Password reset', $body);
        self::assertStringNotContainsString('Newsletter', $body);
        self::assertStringContainsString('aria-describedby="letterbox-search-hint"', $body);
        self::assertStringContainsString('Searches sender, recipient and subject', $body);
    }

    #[Test]
    public function searchTermIsEscapedInInputCountAndLinks(): void
    {
        $term = '"><svg onload=alert(1)>';
        for ($i = 1; $i <= 11; ++$i) {
            $this->append("Hit {$term} {$i}");
        }

        $body = (string) $this->controller()->indexAction($this->request('system_letterbox', ['q' => $term]))->getBody();

        $escaped = '&quot;&gt;&lt;svg onload=alert(1)&gt;';
        self::assertStringNotContainsString('<svg onload', $body);
        self::assertStringContainsString('name="q" value="'.$escaped.'"', $body);
        self::assertStringContainsString('11 mails match “'.$escaped.'”', $body);
        self::assertMatchesRegularExpression('/class="page-link" href="[^"]*q=%22%3E%3Csvg%20onload%3Dalert%281%29%3E/', $body);
    }

    #[Test]
    public function quickFilterUnreadHidesReadMails(): void
    {
        $this->append('Already read');
        $readId = $this->firstId();
        $this->append('Still unread');
        $this->get(ReadState::class)->markRead($readId);

        $body = (string) $this->controller()->indexAction($this->request('system_letterbox', ['filter' => 'unread']))->getBody();

        self::assertStringContainsString('Still unread', $body);
        self::assertStringNotContainsString('Already read', $body);
        self::assertMatchesRegularExpression('/aria-current="true"[^>]*>\s*Unread\s*<small[^>]*>1<\/small>/', $body);
        self::assertSame(1, substr_count($body, '<span class="visually-hidden">Unread</span>'));
    }

    #[Test]
    public function openingAMailMarksItRead(): void
    {
        $this->append('Open me');
        $id = $this->firstId();
        self::assertFalse($this->get(ReadState::class)->isRead($id));

        $response = $this->controller()->showAction($this->request('system_letterbox.show', ['id' => $id]));

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->get(ReadState::class)->isRead($id));
        self::assertStringNotContainsString('<span class="visually-hidden">Unread</span>', (string) $response->getBody());
    }

    #[Test]
    public function paginationKeepsQueryAndFilter(): void
    {
        for ($i = 1; $i <= 12; ++$i) {
            $this->append("Match {$i}");
        }
        $this->appendRaw("From: s@example.org\nTo: r@example.org\nSubject: Match plain\n\nbody\n");

        $body = (string) $this->controller()->indexAction($this->request('system_letterbox', ['q' => 'Match', 'filter' => 'attachments', 'page' => '2']))->getBody();

        preg_match_all('/class="page-link" href="([^"]+)"/', $body, $pageLinks);
        preg_match_all('/href="([^"]+)" class="letterbox-row/', $body, $rowLinks);
        self::assertNotEmpty($pageLinks[1]);
        self::assertCount(2, $rowLinks[1]);
        foreach ([...$pageLinks[1], ...$rowLinks[1]] as $href) {
            self::assertStringContainsString('q=Match', $href);
            self::assertStringContainsString('filter=attachments', $href);
        }
        foreach ($rowLinks[1] as $href) {
            self::assertStringContainsString('page=2', $href);
        }
        self::assertStringNotContainsString('Match plain', $body);
    }

    #[Test]
    public function selectedMailOutsideFilterStillOpens(): void
    {
        $this->appendRaw("From: s@example.org\nTo: r@example.org\nSubject: No attachment here\n\nbody\n");
        $id = $this->firstId();
        $this->append('With attachment');

        $response = $this->controller()->showAction($this->request('system_letterbox.show', ['id' => $id, 'filter' => 'attachments', 'q' => 'With']));

        self::assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        self::assertStringContainsString('>No attachment here</h2>', $body);
        self::assertStringContainsString('With attachment', $body);
    }

    #[Test]
    public function noResultsShowsCalloutWithResetLink(): void
    {
        $this->append('Something');

        $body = (string) $this->controller()->indexAction($this->request('system_letterbox', ['q' => 'nothing-matches', 'filter' => 'unread']))->getBody();

        self::assertStringContainsString('No mails match this search.', $body);
        self::assertStringNotContainsString('No mails caught yet', $body);
        self::assertSame(1, preg_match('/<a href="([^"]+)"[^>]*>Reset search<\/a>/', $body, $reset));
        self::assertStringNotContainsString('q=', $reset[1] ?? '');
        self::assertStringNotContainsString('filter=', $reset[1] ?? '');
    }

    #[Test]
    public function searchFormCarriesTheRouteToken(): void
    {
        $this->append('Something');

        $body = (string) $this->controller()->indexAction($this->request('system_letterbox', ['filter' => 'unread']))->getBody();

        $uri = (string) $this->get(UriBuilder::class)->buildUriFromRoute('system_letterbox');
        parse_str((string) parse_url($uri, \PHP_URL_QUERY), $routeQuery);
        self::assertMatchesRegularExpression('/<form[^>]*method="get"[^>]*role="search"/', $body);
        $token = $routeQuery['token'] ?? null;
        self::assertIsString($token);
        self::assertStringContainsString('<input type="hidden" name="token" value="'.$token.'"', $body);
        self::assertStringContainsString('<input type="hidden" name="filter" value="unread"', $body);
    }

    private function skipWhenRoot(): void
    {
        if (function_exists('posix_geteuid') && 0 === posix_geteuid()) {
            self::markTestSkipped('File permissions are not enforced for root.');
        }
    }

    private function assertErrorFlashMessage(string $fragment): void
    {
        $messages = $this->get(FlashMessageService::class)->getMessageQueueByIdentifier()->getAllMessages(ContextualFeedbackSeverity::ERROR);

        self::assertCount(1, $messages);
        self::assertStringContainsString($fragment, $messages[0]->getMessage());
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
        $this->writer()->append('s@example.org', new DateTimeImmutable('2026-10-27 10:00:00'), $raw);
    }

    private function writer(): MailboxWriter
    {
        return new MailboxWriter($this->path, new MboxFormat(), new MailboxLock($this->get(LockFactory::class)));
    }

    private function appendRaw(string $raw): void
    {
        $this->writer()->append('s@example.org', new DateTimeImmutable('2026-10-27 10:00:00'), $raw);
    }
}
