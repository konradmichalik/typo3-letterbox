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

namespace KonradMichalik\Typo3Letterbox\Tests\Unit\External;

use KonradMichalik\Typo3Letterbox\Configuration\ExtensionSettings;
use KonradMichalik\Typo3Letterbox\External\{EnvironmentVariables, ExternalAccessGuard, ExternalAccessState};
use KonradMichalik\Typo3Letterbox\Mail\LetterboxTransport;
use KonradMichalik\Typo3Letterbox\Mbox\{MessageIndexEntry, MessageSpan};
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\{ApplicationContext, Environment};
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequestFactory};

/**
 * ExternalAccessGuardTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ExternalAccessGuardTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $mailBackup = [];

    /** @var array<string, mixed> */
    private array $previousEnvironment = [];

    protected function setUp(): void
    {
        $this->mailBackup = $GLOBALS['TYPO3_CONF_VARS']['MAIL'] ?? [];
        // Reflection instead of the public getters: they cannot read, nor restore, the uninitialised state the unit suite starts in.
        $this->previousEnvironment = (new ReflectionClass(Environment::class))->getStaticProperties();
        $this->useContext('Production/Staging');
        $this->useMail([]);
    }

    protected function tearDown(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = $this->mailBackup;
        $environment = new ReflectionClass(Environment::class);
        foreach ($this->previousEnvironment as $name => $value) {
            $environment->setStaticPropertyValue($name, $value);
        }
    }

    #[Test]
    public function inactiveWithoutEnv(): void
    {
        $state = $this->guard([])->evaluate($this->request());

        self::assertFalse($state->active);
        self::assertSame([ExternalAccessState::ENV_MISSING], $state->reasons);
    }

    #[Test]
    public function inactiveWhenEnvNamesAnotherContext(): void
    {
        $this->useContext('Production');

        $state = $this->guard(['LETTERBOX_EXTERNAL_ACCESS' => 'Production/Staging'], 'listed', '*@example.org')->evaluate($this->request());

        self::assertFalse($state->active);
        self::assertSame([ExternalAccessState::ENV_CONTEXT_MISMATCH], $state->reasons);
    }

    #[Test]
    public function inactiveWithoutLetterboxTransport(): void
    {
        $this->useMail(['transport' => 'smtp', 'transport_mbox_file' => '/tmp/m.mbox']);

        $state = (new ExternalAccessGuard(new EnvironmentVariables(['LETTERBOX_EXTERNAL_ACCESS' => 'Production/Staging']), $this->extensionConfiguration('')))->evaluate($this->request());

        self::assertFalse($state->active);
        self::assertSame([ExternalAccessState::TRANSPORT_NOT_LETTERBOX], $state->reasons);
    }

    #[Test]
    public function rootProductionRequiresListedAndScopesToCatchList(): void
    {
        $this->useContext('Production');
        $env = ['LETTERBOX_EXTERNAL_ACCESS' => 'Production'];

        $all = $this->guard($env, 'all')->evaluate($this->request());
        self::assertFalse($all->active);
        self::assertSame([ExternalAccessState::PRODUCTION_REQUIRES_LISTED], $all->reasons);

        $empty = $this->guard($env, 'listed')->evaluate($this->request());
        self::assertFalse($empty->active);
        self::assertSame([ExternalAccessState::CATCH_LIST_EMPTY], $empty->reasons);

        $listed = $this->guard($env, 'listed', '*@example.org')->evaluate($this->request());
        self::assertTrue($listed->active);
        self::assertSame([], $listed->reasons);
        self::assertTrue($listed->baseFilter->matches($this->entry(['tester@example.org'])));
        self::assertFalse($listed->baseFilter->matches($this->entry(['customer@shop.example'])));
    }

    #[Test]
    public function subcontextAllowsAnyMode(): void
    {
        $state = $this->guard(['LETTERBOX_EXTERNAL_ACCESS' => 'Production/Staging'], 'all', '*@example.org')->evaluate($this->request());

        self::assertTrue($state->active);
        self::assertTrue($state->baseFilter->matches($this->entry(['customer@shop.example'])));
    }

    #[Test]
    public function ipAllowlistIsEnforced(): void
    {
        $env = ['LETTERBOX_EXTERNAL_ACCESS' => 'Production/Staging', 'LETTERBOX_EXTERNAL_ALLOWED_IPS' => '10.0.0.0/8, 2001:db8::/32'];

        self::assertTrue($this->guard($env)->evaluate($this->request('10.1.2.3'))->active);
        self::assertTrue($this->guard($env)->evaluate($this->request('2001:db8::1'))->active);
        self::assertSame([ExternalAccessState::IP_NOT_ALLOWED], $this->guard($env)->evaluate($this->request('192.0.2.1'))->reasons);

        self::assertSame([ExternalAccessState::IP_NOT_ALLOWED], $this->guard(['LETTERBOX_EXTERNAL_ALLOWED_IPS' => ' , '] + $env)->evaluate($this->request('10.1.2.3'))->reasons);
    }

    #[Test]
    public function octetWildcardIsAllowed(): void
    {
        $guard = $this->guard(['LETTERBOX_EXTERNAL_ACCESS' => 'Production/Staging', 'LETTERBOX_EXTERNAL_ALLOWED_IPS' => '10.0.*.*']);

        self::assertTrue($guard->status()->active);
        self::assertTrue($guard->evaluate($this->request('10.0.4.2'))->active);
        self::assertSame([ExternalAccessState::IP_NOT_ALLOWED], $guard->evaluate($this->request('10.1.4.2'))->reasons);
    }

    #[Test]
    public function whitespaceInsideAnEntryIsIgnored(): void
    {
        $guard = $this->guard(['LETTERBOX_EXTERNAL_ACCESS' => 'Production/Staging', 'LETTERBOX_EXTERNAL_ALLOWED_IPS' => '10.0.0.0 / 8, 192.0. 2.7']);

        self::assertTrue($guard->evaluate($this->request('10.1.2.3'))->active);
        self::assertTrue($guard->evaluate($this->request('192.0.2.7'))->active);
        self::assertSame([ExternalAccessState::IP_NOT_ALLOWED], $guard->evaluate($this->request('192.0.2.8'))->reasons);
    }

    #[Test]
    public function catchAllEntriesDisableTheSurface(): void
    {
        foreach (['*', '*.*.*.*', '0.0.0.0/0', '::/0', '10.0.0.0/8, *', '*. *', "* .*.\t*.*", '0.0.0.0 /0', ':: / 0'] as $list) {
            $guard = $this->guard(['LETTERBOX_EXTERNAL_ACCESS' => 'Production/Staging', 'LETTERBOX_EXTERNAL_ALLOWED_IPS' => $list]);
            self::assertSame([ExternalAccessState::IP_NOT_ALLOWED], $guard->status()->reasons, $list);
            self::assertSame([ExternalAccessState::IP_NOT_ALLOWED], $guard->evaluate($this->request('10.1.2.3'))->reasons, $list);
            self::assertSame([ExternalAccessState::IP_NOT_ALLOWED], $guard->evaluate($this->request('2001:db8::1'))->reasons, $list);
        }
    }

    #[Test]
    public function httpIsRefused(): void
    {
        $guard = $this->guard(['LETTERBOX_EXTERNAL_ACCESS' => 'Production/Staging']);

        self::assertSame([ExternalAccessState::HTTPS_REQUIRED], $guard->evaluate($this->request('10.1.2.3', false))->reasons);
        self::assertSame([ExternalAccessState::HTTPS_REQUIRED], $guard->evaluate((new ServerRequestFactory())->createServerRequest('GET', 'https://example.org/'))->reasons);
        self::assertTrue($guard->status()->active);
    }

    #[Test]
    public function exclusionsComeFromExtensionConfiguration(): void
    {
        $state = $this->guard(['LETTERBOX_EXTERNAL_ACCESS' => 'Production/Staging'], 'all', '', 'admin@example.org, *@staff.example')->evaluate($this->request());

        self::assertTrue($state->active);
        self::assertTrue($state->baseFilter->matches($this->entry(['tester@example.org'])));
        self::assertFalse($state->baseFilter->matches($this->entry(['tester@example.org', 'admin@example.org'], ['tester@example.org'])));
        self::assertFalse($state->baseFilter->matches($this->entry(['boss@staff.example'])));
    }

    #[Test]
    public function invalidExclusionsDeactivate(): void
    {
        $state = $this->guard(['LETTERBOX_EXTERNAL_ACCESS' => 'Production/Staging'], 'all', '', 'admin@example.org, *')->evaluate($this->request());

        self::assertFalse($state->active);
        self::assertSame([ExternalAccessState::EXCLUSIONS_INVALID], $state->reasons);
    }

    #[Test]
    public function invalidMailSettingsDeactivate(): void
    {
        $state = $this->guard(['LETTERBOX_EXTERNAL_ACCESS' => 'Production/Staging'], 'bogus')->evaluate($this->request());

        self::assertFalse($state->active);
        self::assertSame([ExternalAccessState::MAIL_SETTINGS_INVALID], $state->reasons);
    }

    #[Test]
    public function statusCollectsEveryReason(): void
    {
        $this->useContext('Production');
        $this->useMail(['transport' => 'sendmail', 'transport_mbox_file' => '/tmp/m.mbox', 'transport_letterbox_mode' => 'all']);

        $state = (new ExternalAccessGuard(new EnvironmentVariables([]), $this->extensionConfiguration('*')))->status();

        self::assertSame([
            ExternalAccessState::ENV_MISSING,
            ExternalAccessState::TRANSPORT_NOT_LETTERBOX,
            ExternalAccessState::PRODUCTION_REQUIRES_LISTED,
            ExternalAccessState::EXCLUSIONS_INVALID,
        ], $state->reasons);
    }

    /**
     * @param array<string, string> $env
     */
    private function guard(array $env, string $mode = 'all', string $catch = '', string $exclusions = ''): ExternalAccessGuard
    {
        $this->useMail([
            'transport' => LetterboxTransport::class,
            'transport_mbox_file' => '/tmp/m.mbox',
            'transport_letterbox_mode' => $mode,
            'transport_letterbox_catch' => $catch,
        ]);

        return new ExternalAccessGuard(new EnvironmentVariables($env), $this->extensionConfiguration($exclusions));
    }

    private function extensionConfiguration(string $exclusions): ExtensionSettings
    {
        $configuration = self::createStub(ExtensionConfiguration::class);
        $configuration->method('get')->willReturnMap([['typo3_letterbox', 'externalExcludedRecipients', $exclusions]]);

        return new ExtensionSettings($configuration);
    }

    private function request(string $ip = '10.1.2.3', bool $https = true): ServerRequestInterface
    {
        $server = ['REMOTE_ADDR' => $ip, 'HTTP_HOST' => 'stage.example.org', 'SCRIPT_NAME' => '/index.php'];
        if ($https) {
            $server['HTTPS'] = 'on';
        }
        $params = new NormalizedParams($server, ['reverseProxyIP' => '', 'reverseProxyHeaderMultiValue' => 'none', 'reverseProxyPrefix' => '', 'reverseProxySSL' => '', 'reverseProxyPrefixSSL' => ''], '/project/public/index.php', '/project/public/');

        return (new ServerRequestFactory())->createServerRequest('GET', ($https ? 'https' : 'http').'://stage.example.org/', $server)->withAttribute('normalizedParams', $params);
    }

    /**
     * @param list<string>      $recipients
     * @param list<string>|null $scopeRecipients
     */
    private function entry(array $recipients, ?array $scopeRecipients = null): MessageIndexEntry
    {
        return new MessageIndexEntry(new MessageSpan(0, 10), 'id', null, 'sender@example.org', '', $recipients, $recipients, $scopeRecipients ?? $recipients, 'Subject', 0);
    }

    /**
     * @param array<string, mixed> $mail
     */
    private function useMail(array $mail): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = $mail;
    }

    private function useContext(string $context): void
    {
        Environment::initialize(new ApplicationContext($context), true, true, '/project', '/project/public', '/project/var', '/project/config', '/project/vendor/bin/phpunit', 'UNIX');
    }
}
