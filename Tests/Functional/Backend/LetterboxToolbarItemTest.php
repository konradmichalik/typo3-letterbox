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

namespace KonradMichalik\Typo3Letterbox\Tests\Functional\Backend;

use DateTimeImmutable;
use KonradMichalik\Typo3Letterbox\Backend\ReadState;
use KonradMichalik\Typo3Letterbox\Backend\ToolbarItem\LetterboxToolbarItem;
use KonradMichalik\Typo3Letterbox\Domain\Model\MailFilter;
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use KonradMichalik\Typo3Letterbox\Mail\LetterboxTransport;
use KonradMichalik\Typo3Letterbox\Mbox\{MailboxLock, MailboxWriter, MboxFormat};
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\{Router, UriBuilder};
use TYPO3\CMS\Backend\View\BackendViewFactory;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\{Environment, SystemEnvironmentBuilder};
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequest};
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function dirname;

/**
 * LetterboxToolbarItemTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class LetterboxToolbarItemTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3_letterbox',
    ];

    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = Environment::getVarPath().'/letterbox-test/toolbar.mbox';
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
    public function adminSeesItemWithCount(): void
    {
        $this->append('First');
        $this->append('Second');
        $item = $this->item();

        self::assertTrue($item->checkAccess());
        self::assertTrue($item->hasDropDown());
        self::assertSame(50, $item->getIndex());
        $html = $item->getItem();
        self::assertStringContainsString('Mail catcher active', $html);
        self::assertMatchesRegularExpression('/<span class="toolbar-item-badge[^"]*"[^>]*>\s*2\s*<\/span>/', $html);
        self::assertStringContainsString('Mail catcher active, 2 unread mails', $html);
    }

    #[Test]
    public function badgeCountsUnreadOnly(): void
    {
        $this->append('First');
        $this->append('Second');
        $this->append('Third');
        $this->markRead('Second');

        $html = $this->item()->getItem();

        self::assertMatchesRegularExpression('/<span class="toolbar-item-badge[^"]*"[^>]*>\s*2\s*<\/span>/', $html);
        self::assertStringContainsString('Mail catcher active, 2 unread mails', $html);
    }

    #[Test]
    public function dropdownShowsTotalAndUnreadWithTheCoreEnvelopeIcon(): void
    {
        $this->append('First');
        $this->append('Second');
        $this->markRead('Second');

        self::assertStringContainsString('2 mails, 1 unread', $this->item()->getDropDown());
        self::assertStringContainsString('actions-envelope', $this->item()->getItem());
    }

    #[Test]
    public function noBadgeWithoutUnread(): void
    {
        $this->append('Only');
        $this->markRead('Only');

        $html = $this->item()->getItem();

        self::assertStringNotContainsString('toolbar-item-badge', $html);
        self::assertStringNotContainsString('unread', $html);
        self::assertStringContainsString('Mail catcher active', $html);
    }

    #[Test]
    public function badgeColourFollowsTheMode(): void
    {
        $this->append('Unread');
        self::assertStringContainsString('badge-warning', $this->item()->getItem());

        $this->useMailSettings(['transport_letterbox_mode' => 'listed', 'transport_letterbox_catch' => 'a@example.org']);
        self::assertStringContainsString('badge-info', $this->item()->getItem());
    }

    #[Test]
    public function dropdownMarksUnreadMailsAndSeparatesSections(): void
    {
        $this->append('Seen');
        $this->append('Fresh');
        $this->markRead('Seen');

        $html = $this->item()->getDropDown();

        self::assertSame(1, substr_count($html, 'Unread</span>'));
        self::assertMatchesRegularExpression('/fw-bold[^>]*>\s*<span class="visually-hidden">Unread<\/span>\s*Fresh/', $html);
        self::assertStringNotContainsString('toolbar.mbox', $html);
        self::assertStringNotContainsString('Mbox file', $html);
        self::assertGreaterThanOrEqual(2, substr_count($html, 'dropdown-divider'));
        self::assertMatchesRegularExpression('/<a class="btn btn-default btn-sm[^"]*" href="[^"]*">Open Letterbox<\/a>/', $html);
    }

    #[Test]
    public function oneMailIsCaughtInTheSingular(): void
    {
        $this->append('Only');

        self::assertStringContainsString('Mail catcher active, 1 unread mail', $this->item()->getItem());
    }

    #[Test]
    public function nonAdminSeesNothing(): void
    {
        $user = new BackendUserAuthentication();
        $user->user = ['uid' => 2, 'admin' => 0];
        $GLOBALS['BE_USER'] = $user;

        self::assertFalse($this->item()->checkAccess());
    }

    #[Test]
    public function listedModeSaysCatchingNAddresses(): void
    {
        $this->useMailSettings(['transport_letterbox_mode' => 'listed', 'transport_letterbox_catch' => 'a@example.org, *@example.com']);
        $this->append('Caught');

        $html = $this->item()->getItem();

        self::assertTrue($this->item()->checkAccess());
        self::assertStringContainsString('Catching 2 addresses', $html);
        self::assertStringNotContainsString('Mail catcher active', $html);
    }

    #[Test]
    public function listedModeWithOneAddressUsesTheSingular(): void
    {
        $this->useMailSettings(['transport_letterbox_mode' => 'listed', 'transport_letterbox_catch' => 'a@example.org']);
        $this->append('Caught');

        self::assertStringContainsString('Catching 1 address,', $this->item()->getItem());
    }

    #[Test]
    public function disabledByExtensionConfiguration(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_letterbox']['showToolbarItem'] = '0';

        self::assertFalse($this->item()->checkAccess());
    }

    #[Test]
    public function absentWithAnotherTransport(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport'] = 'smtp';

        self::assertFalse($this->item()->checkAccess());
    }

    #[Test]
    public function absentInModeOff(): void
    {
        $this->useMailSettings(['transport_letterbox_mode' => 'off', 'transport_letterbox_delivery' => 'smtp']);

        self::assertFalse($this->item()->checkAccess());
    }

    #[Test]
    public function unconfiguredMailboxRendersNotConfigured(): void
    {
        $this->useMailSettings(['transport_mbox_file' => '']);
        $item = $this->item();

        self::assertTrue($item->checkAccess());
        self::assertStringContainsString('Mail catcher active', $item->getItem());
        self::assertStringContainsString('Letterbox is active but no mbox file is configured.', $item->getDropDown());
    }

    #[Test]
    public function invalidSettingsRenderNotConfiguredWithoutException(): void
    {
        $this->useMailSettings(['transport_letterbox_mode' => 'bogus']);
        $item = $this->item();

        self::assertTrue($item->checkAccess());
        self::assertStringContainsString('Letterbox is active but no mbox file is configured.', $item->getDropDown());
        self::assertNotSame('', $item->getItem());
    }

    #[Test]
    public function missingMailboxFileRendersEmptyDropdown(): void
    {
        $html = $this->item()->getDropDown();

        self::assertStringContainsString('No mails caught yet.', $html);
        self::assertStringNotContainsString('no mbox file is configured', $html);
    }

    #[Test]
    public function dropdownListsTheLatestFiveSubjectsWithLinks(): void
    {
        foreach (['One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven'] as $subject) {
            $this->append($subject);
        }
        $first = $this->get(MailRepository::class)->find(MailFilter::all(), 0, 1)[0];

        $html = $this->item()->getDropDown();

        self::assertStringContainsString('Seven', $html);
        self::assertStringContainsString('Three', $html);
        self::assertStringNotContainsString('Two', $html);
        self::assertSame(1, preg_match('/<a [^>]*href="[^"]*letterbox\/show[^"]*id='.$first->id.'[^"]*"[^>]*>\s*(?:<[^>]+>\s*)*(?:Unread(?:<[^>]+>\s*)*)?Seven/', $html));
        self::assertStringContainsString('Open Letterbox', $html);
    }

    #[Test]
    public function subjectsAreEscapedAndTruncated(): void
    {
        $long = str_repeat('abcdefghij', 10);
        $this->append('<script>alert(1)</script>');
        $this->append($long);

        $html = $this->item()->getDropDown();

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

        $html = $this->item()->getDropDown();

        self::assertStringContainsString('Visible subject', $html);
        self::assertStringNotContainsString('<a ', $html);
        self::assertStringNotContainsString('Open Letterbox', $html);
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

    private function item(): LetterboxToolbarItem
    {
        $item = new LetterboxToolbarItem(
            $this->get(BackendViewFactory::class),
            $this->get(MailRepository::class),
            $this->get(ExtensionConfiguration::class),
            $this->get(UriBuilder::class),
            $this->get(ReadState::class),
        );
        $item->setRequest($this->request());

        return $item;
    }

    private function request(): ServerRequestInterface
    {
        $request = (new ServerRequest('https://typo3-testing.local/typo3/main', 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE)
            ->withAttribute('route', $this->get(Router::class)->getRoute('main'));
        $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        $GLOBALS['TYPO3_REQUEST'] = $request;

        return $request;
    }

    private function markRead(string $subject): void
    {
        foreach ($this->get(MailRepository::class)->find(MailFilter::all(), 0, 50) as $entry) {
            if ($subject === $entry->subject) {
                $this->get(ReadState::class)->markRead($entry->id);
            }
        }
    }

    private function append(string $subject): void
    {
        $raw = "From: Sender <s@example.org>\nTo: Recipient <r@example.org>\nSubject: {$subject}\nMessage-ID: <".bin2hex(random_bytes(4))."@example.org>\nContent-Type: text/plain\n\nbody\n";
        (new MailboxWriter($this->path, new MboxFormat(), new MailboxLock($this->get(LockFactory::class))))->append('s@example.org', new DateTimeImmutable('2026-10-27 10:00:00'), $raw);
    }
}
