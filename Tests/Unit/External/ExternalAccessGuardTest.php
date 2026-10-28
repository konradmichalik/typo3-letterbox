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

use KonradMichalik\Typo3Letterbox\External\{EnvironmentVariables, ExternalAccessGuard, ExternalAccessState};
use KonradMichalik\Typo3Letterbox\Mail\LetterboxTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionClass;
use TYPO3\CMS\Core\Core\{ApplicationContext, Environment};
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequestFactory};

/**
 * ExternalAccessGuardTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ExternalAccessGuardTest extends TestCase
{
    private const TOKEN = 'example-access-token-0123456789abcdef';

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
        $this->useMail(['transport' => LetterboxTransport::class, 'transport_mbox_file' => '/tmp/m.mbox']);
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
    public function activeWithMatchingContextAndToken(): void
    {
        $guard = $this->guard(['LETTERBOX_EXTERNAL_ACCESS' => 'Production/Staging', 'LETTERBOX_ACCESS_TOKEN' => self::TOKEN]);

        self::assertTrue($guard->evaluate($this->request())->active);
        self::assertSame(self::TOKEN, $guard->token());
    }

    #[Test]
    public function inactiveWithoutEnv(): void
    {
        $state = $this->guard(['LETTERBOX_ACCESS_TOKEN' => self::TOKEN])->evaluate($this->request());

        self::assertFalse($state->active);
        self::assertSame([ExternalAccessState::ENV_MISSING], $state->reasons);
    }

    #[Test]
    public function inactiveWhenEnvNamesAnotherContext(): void
    {
        $this->useContext('Production/Staging/Second');

        $state = $this->guard(['LETTERBOX_EXTERNAL_ACCESS' => 'Production/Staging', 'LETTERBOX_ACCESS_TOKEN' => self::TOKEN])->evaluate($this->request());

        self::assertFalse($state->active);
        self::assertSame([ExternalAccessState::ENV_CONTEXT_MISMATCH], $state->reasons);
    }

    #[Test]
    public function inactiveWithoutLetterboxTransport(): void
    {
        $this->useMail(['transport' => 'smtp', 'transport_mbox_file' => '/tmp/m.mbox']);

        $state = $this->guard(['LETTERBOX_EXTERNAL_ACCESS' => 'Production/Staging', 'LETTERBOX_ACCESS_TOKEN' => self::TOKEN])->evaluate($this->request());

        self::assertFalse($state->active);
        self::assertSame([ExternalAccessState::TRANSPORT_NOT_LETTERBOX], $state->reasons);
    }

    #[Test]
    public function rootProductionIsAlwaysOff(): void
    {
        $this->useContext('Production');
        $env = ['LETTERBOX_EXTERNAL_ACCESS' => 'Production', 'LETTERBOX_ACCESS_TOKEN' => self::TOKEN];

        foreach (['all', 'listed', 'off'] as $mode) {
            $this->useMail(['transport' => LetterboxTransport::class, 'transport_mbox_file' => '/tmp/m.mbox', 'transport_letterbox_mode' => $mode, 'transport_letterbox_catch' => '*@example.org']);
            $state = $this->guard($env)->evaluate($this->request());

            self::assertFalse($state->active, $mode);
            self::assertSame([ExternalAccessState::PRODUCTION], $state->reasons, $mode);
        }
    }

    #[Test]
    public function missingOrShortTokenKeepsEverythingOff(): void
    {
        foreach (['', '   ', substr(self::TOKEN, 0, 31)] as $token) {
            $guard = $this->guard(['LETTERBOX_EXTERNAL_ACCESS' => 'Production/Staging', 'LETTERBOX_ACCESS_TOKEN' => $token]);

            self::assertFalse($guard->status()->active, $token);
            self::assertSame([ExternalAccessState::TOKEN_MISSING], $guard->evaluate($this->request())->reasons, $token);
            self::assertSame('', $guard->token(), $token);
        }

        $legacy = $this->guard(['LETTERBOX_EXTERNAL_ACCESS' => 'Production/Staging', 'LETTERBOX_API_TOKEN' => self::TOKEN]);
        self::assertSame([ExternalAccessState::TOKEN_MISSING], $legacy->status()->reasons, 'the old variable name is not read');
    }

    #[Test]
    public function httpIsRefused(): void
    {
        $guard = $this->guard(['LETTERBOX_EXTERNAL_ACCESS' => 'Production/Staging', 'LETTERBOX_ACCESS_TOKEN' => self::TOKEN]);

        self::assertSame([ExternalAccessState::HTTPS_REQUIRED], $guard->evaluate($this->request(false))->reasons);
        self::assertSame([ExternalAccessState::HTTPS_REQUIRED], $guard->evaluate((new ServerRequestFactory())->createServerRequest('GET', 'https://example.org/'))->reasons);
        self::assertTrue($guard->status()->active);
    }

    #[Test]
    public function statusCollectsEveryReason(): void
    {
        $this->useContext('Production');
        $this->useMail(['transport' => 'sendmail', 'transport_mbox_file' => '/tmp/m.mbox']);

        $state = $this->guard([])->status();

        self::assertSame([
            ExternalAccessState::ENV_MISSING,
            ExternalAccessState::PRODUCTION,
            ExternalAccessState::TRANSPORT_NOT_LETTERBOX,
            ExternalAccessState::TOKEN_MISSING,
        ], $state->reasons);
    }

    /**
     * @param array<string, string> $env
     */
    private function guard(array $env): ExternalAccessGuard
    {
        return new ExternalAccessGuard(new EnvironmentVariables($env));
    }

    private function request(bool $https = true): ServerRequestInterface
    {
        $server = ['REMOTE_ADDR' => '10.1.2.3', 'HTTP_HOST' => 'stage.example.org', 'SCRIPT_NAME' => '/index.php'];
        if ($https) {
            $server['HTTPS'] = 'on';
        }
        $params = new NormalizedParams($server, ['reverseProxyIP' => '', 'reverseProxyHeaderMultiValue' => 'none', 'reverseProxyPrefix' => '', 'reverseProxySSL' => '', 'reverseProxyPrefixSSL' => ''], '/project/public/index.php', '/project/public/');

        return (new ServerRequestFactory())->createServerRequest('GET', ($https ? 'https' : 'http').'://stage.example.org/', $server)->withAttribute('normalizedParams', $params);
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
