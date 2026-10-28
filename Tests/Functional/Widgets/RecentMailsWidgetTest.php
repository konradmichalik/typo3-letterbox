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

namespace KonradMichalik\Typo3Letterbox\Tests\Functional\Widgets;

use DateTimeImmutable;
use KonradMichalik\Typo3Letterbox\Domain\Model\MailFilter;
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use KonradMichalik\Typo3Letterbox\Mail\LetterboxTransport;
use KonradMichalik\Typo3Letterbox\Mbox\{MailboxLock, MailboxWriter, MboxFormat};
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\{Environment, SystemEnvironmentBuilder};
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Dashboard\Widgets\{AdminOnlyWidgetInterface, RequestAwareWidgetInterface, WidgetInterface};
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function dirname;

/**
 * RecentMailsWidgetTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RecentMailsWidgetTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3_letterbox',
    ];

    protected array $coreExtensionsToLoad = [
        'dashboard',
    ];

    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = Environment::getVarPath().'/letterbox-test/widget.mbox';
        GeneralUtility::mkdir_deep(dirname($this->path));
        if (is_file($this->path)) {
            unlink($this->path);
        }
        $this->useMailSettings([]);
        $this->get(CacheManager::class)->getCache('typo3_letterbox_index')->flush();

        $this->importCSVDataSet(__DIR__.'/../Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    #[Test]
    public function adminSeesLatestMails(): void
    {
        foreach (['One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven'] as $subject) {
            $this->append($subject);
        }
        $newest = $this->get(MailRepository::class)->findEntries(MailFilter::all(), 0, 1)[0];

        $html = $this->widget()->renderWidgetContent();

        self::assertStringContainsString('Seven', $html);
        self::assertStringContainsString('Two', $html);
        self::assertStringNotContainsString('One', $html);
        self::assertStringContainsString('Recipient', $html);
        self::assertStringNotContainsString('Sender', $html);
        self::assertStringContainsString('2026-10-27 10:00', $html);
        self::assertSame(1, preg_match('/<a [^>]*href="[^"]*letterbox\/show[^"]*id='.$newest->id.'[^"]*"[^>]*>\s*(?:<[^>]+>\s*)*Seven/', $html));
        self::assertStringContainsString('Open Letterbox', $html);
        self::assertStringNotContainsString('not the active mail transport', $html);
    }

    #[Test]
    public function showsTheFirstRecipientAndHowManyMore(): void
    {
        $this->append('Team mail', 'Anna Example <anna@example.org>, bob@example.org, carl@example.org');
        $this->append('Plain address', 'dora@example.org');

        $html = $this->widget()->renderWidgetContent();

        self::assertStringContainsString('Anna Example +2', $html);
        self::assertStringNotContainsString('bob@example.org', $html);
        self::assertStringContainsString('dora@example.org', $html);
    }

    #[Test]
    public function nonAdminGetsEmptyContent(): void
    {
        $this->append('Secret');
        $user = new BackendUserAuthentication();
        $user->user = ['uid' => 2, 'admin' => 0];
        $GLOBALS['BE_USER'] = $user;

        self::assertSame('', $this->widget()->renderWidgetContent());
    }

    #[Test]
    public function emptyMailboxShowsEmptyState(): void
    {
        self::assertStringContainsString('No mails caught yet.', $this->widget()->renderWidgetContent());
    }

    #[Test]
    public function unconfiguredMailboxShowsHint(): void
    {
        $this->useMailSettings(['transport_mbox_file' => '']);

        $html = $this->widget()->renderWidgetContent();

        self::assertStringContainsString('No mbox file configured.', $html);
        self::assertStringNotContainsString('No mails caught yet.', $html);
    }

    #[Test]
    public function subjectsAreEscapedAndTruncated(): void
    {
        $long = str_repeat('abcdefghij', 10);
        $this->append('<script>alert(1)</script>');
        $this->append($long);

        $html = $this->widget()->renderWidgetContent();

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        self::assertStringNotContainsString($long, $html);
        self::assertStringContainsString(substr($long, 0, 60), $html);
        self::assertStringNotContainsString(substr($long, 0, 61), $html);
    }

    #[Test]
    public function noModuleLinksWhenModuleDisabled(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_letterbox']['enableBackendModule'] = '0';
        $this->append('Visible subject');

        $html = $this->widget()->renderWidgetContent();

        self::assertStringContainsString('Visible subject', $html);
        self::assertStringNotContainsString('<a ', $html);
        self::assertStringNotContainsString('Open Letterbox', $html);
    }

    #[Test]
    public function notesAnotherActiveTransport(): void
    {
        $this->append('Old mail');
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = 'smtp';

        $html = $this->widget()->renderWidgetContent();

        self::assertStringContainsString('Old mail', $html);
        self::assertStringContainsString('Letterbox is not the active mail transport.', $html);
    }

    #[Test]
    public function v14UsesAdminOnlyInterface(): void
    {
        if (!interface_exists(AdminOnlyWidgetInterface::class)) {
            self::markTestSkipped('AdminOnlyWidgetInterface exists in TYPO3 14 only.');
        }

        self::assertInstanceOf(AdminOnlyWidgetInterface::class, $this->widget());
    }

    private function widget(): WidgetInterface
    {
        $widget = $this->get('dashboard.widget.letterboxRecentMails');
        self::assertInstanceOf(WidgetInterface::class, $widget);
        self::assertInstanceOf(RequestAwareWidgetInterface::class, $widget);
        $widget->setRequest($this->request());

        return $widget;
    }

    /**
     * @param array<string, string> $overrides
     */
    private function useMailSettings(array $overrides): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = array_merge([
            'transport' => LetterboxTransport::class,
            'transport_mbox_file' => $this->path,
        ], $overrides);
    }

    private function request(): ServerRequestInterface
    {
        $request = (new ServerRequest('https://typo3-testing.local/typo3/module/dashboard', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $this->get(Router::class)->getRoute('main'));
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }

    private function append(string $subject, string $to = 'Recipient <r@example.org>'): void
    {
        $raw = "From: Sender <s@example.org>\nTo: {$to}\nSubject: {$subject}\nMessage-ID: <".bin2hex(random_bytes(4))."@example.org>\nContent-Type: text/plain\n\nbody\n";
        (new MailboxWriter($this->path, new MboxFormat(), new MailboxLock($this->get(LockFactory::class))))->append('s@example.org', new DateTimeImmutable('2026-10-27 10:00:00'), $raw);
    }
}
