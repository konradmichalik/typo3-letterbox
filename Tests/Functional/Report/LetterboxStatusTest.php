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

namespace KonradMichalik\Typo3Letterbox\Tests\Functional\Report;

use DateTimeImmutable;
use KonradMichalik\Typo3Letterbox\Configuration\ExtensionSettings;
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use KonradMichalik\Typo3Letterbox\External\{EnvironmentVariables, ExternalAccessGuard};
use KonradMichalik\Typo3Letterbox\Mail\LetterboxTransport;
use KonradMichalik\Typo3Letterbox\Mbox\{MailboxLock, MailboxWriter, MboxFormat};
use KonradMichalik\Typo3Letterbox\Report\LetterboxStatus;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\{ApplicationContext, Environment};
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Reports\Registry\StatusRegistry;
use TYPO3\CMS\Reports\Status;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function dirname;

/**
 * LetterboxStatusTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class LetterboxStatusTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3_letterbox',
    ];

    protected array $coreExtensionsToLoad = [
        'reports',
    ];

    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = Environment::getVarPath().'/letterbox-test/status.mbox';
        GeneralUtility::mkdir_deep(dirname($this->path));
        if (is_file($this->path)) {
            chmod($this->path, 0644);
            unlink($this->path);
        }
        $this->useMailSettings([]);
        $this->get(CacheManager::class)->getCache('typo3_letterbox_index')->flush();

        $this->importCSVDataSet(__DIR__.'/../Fixtures/be_users.csv');
        $backendUser = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            chmod($this->path, 0644);
        }
        parent::tearDown();
    }

    #[Test]
    public function labelIsLetterbox(): void
    {
        self::assertSame('Letterbox', $this->provider()->getLabel());
    }

    #[Test]
    public function otherTransportOnlyReportsThatLetterboxIsNotTheTransport(): void
    {
        $this->useMailSettings(['transport' => 'smtp']);

        $statuses = $this->provider()->getStatus();

        self::assertCount(1, $statuses);
        self::assertSame(ContextualFeedbackSeverity::INFO, $statuses['transport']->getSeverity());
        self::assertSame('Letterbox is installed but not the mail transport.', $statuses['transport']->getMessage());
    }

    #[Test]
    public function allModeInRootProductionWarns(): void
    {
        $this->useContext('Production');
        $this->useMailSettings(['transport_letterbox_mode' => 'all']);

        $statuses = $this->provider()->getStatus();

        self::assertSame(ContextualFeedbackSeverity::WARNING, $statuses['mode']->getSeverity());
    }

    #[Test]
    public function allModeInProductionSubContextWarns(): void
    {
        $this->useContext('Production/Live');
        $this->useMailSettings([]);

        $status = $this->provider()->getStatus()['mode'];

        self::assertSame(ContextualFeedbackSeverity::WARNING, $status->getSeverity(), 'the default mode all applies in every sub context');
        self::assertStringContainsString('Production/Live', $status->getMessage());
        self::assertStringContainsString('only the root context Production defaults to listed', $status->getMessage());
    }

    #[Test]
    public function allModeOutsideProductionIsOk(): void
    {
        $this->useContext('Development');
        $this->useMailSettings(['transport_letterbox_mode' => 'all']);

        self::assertSame(ContextualFeedbackSeverity::OK, $this->provider()->getStatus()['mode']->getSeverity());
    }

    #[Test]
    public function listedModeIsInfoWithCatchCount(): void
    {
        $this->useMailSettings(['transport_letterbox_mode' => 'listed', 'transport_letterbox_catch' => '*@example.org, a@example.com']);

        $status = $this->provider()->getStatus()['mode'];

        self::assertSame(ContextualFeedbackSeverity::INFO, $status->getSeverity());
        self::assertStringContainsString('2', $status->getValue());
        self::assertStringNotContainsString('example.org', $status->getValue());
        self::assertStringContainsString('*@example.org', $status->getMessage());
    }

    #[Test]
    public function unwritableFileWarns(): void
    {
        $this->append('Subject');
        chmod($this->path, 0444);

        $status = $this->provider()->getStatus()['file'];

        self::assertSame(ContextualFeedbackSeverity::WARNING, $status->getSeverity());
    }

    #[Test]
    public function fileInsideThePublicPathIsAnError(): void
    {
        $public = Environment::getPublicPath().'/letterbox-public-test/../mails.mbox';
        $this->useMailSettings(['transport_mbox_file' => $public]);

        $status = $this->provider()->getStatus()['file'];

        self::assertSame(ContextualFeedbackSeverity::ERROR, $status->getSeverity());
        self::assertStringContainsString('web root', $status->getMessage());
        self::assertStringContainsString(htmlspecialchars($public, \ENT_QUOTES), $status->getMessage());

        $this->useMailSettings(['transport_mbox_file' => Environment::getVarPath().'/letterbox-test/status.mbox']);
        self::assertNotSame(ContextualFeedbackSeverity::ERROR, $this->provider()->getStatus()['file']->getSeverity(), 'the var path is protected even when it sits below the public path');
    }

    #[Test]
    public function missingDirectoryWarns(): void
    {
        $this->useMailSettings(['transport_mbox_file' => Environment::getVarPath().'/letterbox-test/no-such-dir/status.mbox']);

        self::assertSame(ContextualFeedbackSeverity::WARNING, $this->provider()->getStatus()['file']->getSeverity());
    }

    #[Test]
    public function writableFileShowsCountAndIsOk(): void
    {
        $this->append('One');
        $this->append('Two');

        $status = $this->provider()->getStatus()['file'];

        self::assertSame(ContextualFeedbackSeverity::OK, $status->getSeverity());
        self::assertStringContainsString('2 mails', $status->getValue());
        self::assertStringContainsString(htmlspecialchars($this->path, \ENT_QUOTES), $status->getMessage());
    }

    #[Test]
    public function invalidMailSettingsAreAnError(): void
    {
        $this->useMailSettings(['transport_letterbox_mode' => 'bogus']);

        $statuses = $this->provider()->getStatus();

        self::assertSame(ContextualFeedbackSeverity::ERROR, $statuses['settings']->getSeverity());
        self::assertSame('Mail settings invalid', $statuses['settings']->getValue());
    }

    #[Test]
    public function deliveryBackIntoLetterboxIsAnError(): void
    {
        $this->useMailSettings(['transport_letterbox_mode' => 'listed', 'transport_letterbox_delivery' => 'mbox']);

        $statuses = $this->provider()->getStatus();

        self::assertSame(ContextualFeedbackSeverity::ERROR, $statuses['delivery']->getSeverity());
        self::assertSame('Delivery loops back', $statuses['delivery']->getValue());
        self::assertStringContainsString('transport_letterbox_delivery', $statuses['delivery']->getMessage());

        $this->useMailSettings(['transport_letterbox_mode' => 'listed', 'transport_letterbox_delivery' => 'smtp']);
        self::assertArrayNotHasKey('delivery', $this->provider()->getStatus());
    }

    #[Test]
    public function retentionNamesTheLimits(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_letterbox']['maxMessages'] = '100';
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_letterbox']['maxAgeDays'] = '7';

        $status = $this->provider()->getStatus()['retention'];

        self::assertSame('keeps 100 mails or 7 days', $status->getValue());
    }

    #[Test]
    public function externalAccessIsOkWhenOff(): void
    {
        self::assertSame(ContextualFeedbackSeverity::OK, $this->provider()->getStatus()['external']->getSeverity());
    }

    #[Test]
    public function externalAccessIsInfoWhenOnOutsideProduction(): void
    {
        $this->useContext('Development');

        $status = $this->provider(['LETTERBOX_EXTERNAL_ACCESS' => 'Development', 'LETTERBOX_ACCESS_TOKEN' => str_repeat('a', 32)])->getStatus()['external'];

        self::assertSame(ContextualFeedbackSeverity::INFO, $status->getSeverity());
    }

    #[Test]
    public function externalAccessIsOffInRootProduction(): void
    {
        $this->useContext('Production');
        $this->useMailSettings(['transport_letterbox_mode' => 'listed', 'transport_letterbox_catch' => '*@example.org']);

        $status = $this->provider(['LETTERBOX_EXTERNAL_ACCESS' => 'Production', 'LETTERBOX_ACCESS_TOKEN' => str_repeat('a', 32)])->getStatus()['external'];

        self::assertSame(ContextualFeedbackSeverity::OK, $status->getSeverity());
        self::assertSame('Off', $status->getValue());
    }

    #[Test]
    public function valuesAreEscaped(): void
    {
        $this->useMailSettings(['transport_mbox_file' => Environment::getVarPath().'/letterbox-test/a&b.mbox', 'transport_letterbox_mode' => 'listed', 'transport_letterbox_catch' => 'o&neil@example.org']);

        $statuses = $this->provider()->getStatus();

        foreach ($statuses as $status) {
            self::assertInstanceOf(Status::class, $status);
            self::assertDoesNotMatchRegularExpression('/&(?!amp;|lt;|gt;|quot;|#)/', $status->getMessage());
        }
        self::assertStringContainsString('a&amp;b.mbox', $statuses['file']->getMessage());
        self::assertStringContainsString('o&amp;neil@example.org', $statuses['mode']->getMessage());
    }

    #[Test]
    public function isRegisteredWhenReportsIsLoaded(): void
    {
        $providers = $this->get(StatusRegistry::class)->getProviders();

        $classes = array_map(static fn (object $provider): string => $provider::class, [...$providers]);
        self::assertContains(LetterboxStatus::class, $classes);
    }

    /**
     * @param array<string, string> $environment
     */
    private function provider(array $environment = []): LetterboxStatus
    {
        return new LetterboxStatus(
            $this->get(MailRepository::class),
            new ExternalAccessGuard(new EnvironmentVariables($environment)),
            $this->get(ExtensionSettings::class),
        );
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
        $this->get(CacheManager::class)->getCache('typo3_letterbox_index')->flush();
    }

    private function useContext(string $context): void
    {
        Environment::initialize(
            new ApplicationContext($context),
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

    private function append(string $subject): void
    {
        $raw = "From: Sender <s@example.org>\nTo: Recipient <r@example.org>\nSubject: {$subject}\nMessage-ID: <".bin2hex(random_bytes(4))."@example.org>\nContent-Type: text/plain\n\nbody\n";
        (new MailboxWriter($this->path, new MboxFormat(), new MailboxLock($this->get(LockFactory::class))))->append('s@example.org', new DateTimeImmutable('2026-10-27 10:00:00'), $raw);
    }
}
