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

use KonradMichalik\Typo3Letterbox\External\{AuthenticationThrottle, RateLimitStorage};
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\RateLimiter\Policy\{SlidingWindow, Window};
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Http\{NormalizedParams, ServerRequestFactory};

/**
 * AuthenticationThrottleTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class AuthenticationThrottleTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $cacheEntries = [];

    /** @var array<string, int|null> */
    private array $cacheLifetimes = [];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-encryption-key';
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_CONF_VARS']);
        parent::tearDown();
    }

    #[Test]
    public function storedStateContainsNoClientAddress(): void
    {
        $throttle = $this->throttle();
        $throttle->recordFailure($this->request('192.0.2.10'));
        $throttle->recordFailure($this->request('2001:db8::10'));

        self::assertCount(2, $this->cacheEntries);
        foreach ($this->cacheEntries as $key => $value) {
            foreach (['192.0.2.10', '2001:db8::10'] as $ip) {
                self::assertStringNotContainsString($ip, $key);
                self::assertStringNotContainsString($ip, (string) $value);
            }
        }
    }

    #[Test]
    public function missingEncryptionKeyBlocks(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = '';

        self::assertTrue($this->throttle()->isBlocked($this->request('192.0.2.10')));
    }

    #[Test]
    public function savedStateExpiresAfterTheWindow(): void
    {
        (new RateLimitStorage($this->cache()))->save(new SlidingWindow('letterbox-external-x', 900));

        $lifetime = $this->cacheLifetimes[hash('sha256', 'letterbox-external-x')] ?? null;
        self::assertIsInt($lifetime);
        self::assertGreaterThan(900, $lifetime, 'outlives the window, the previous window still counts');
        self::assertLessThanOrEqual(2 * 900 + 60, $lifetime);
    }

    #[Test]
    public function tenFailuresBlockTheIp(): void
    {
        $throttle = $this->throttle();
        $request = $this->request('192.0.2.10');

        for ($i = 0; $i < 10; ++$i) {
            self::assertFalse($throttle->isBlocked($request), 'attempt '.$i);
            $throttle->recordFailure($request);
        }

        self::assertTrue($throttle->isBlocked($request));
        self::assertTrue($this->throttle()->isBlocked($request), 'state is persisted in the cache');
    }

    #[Test]
    public function successDoesNotConsume(): void
    {
        $throttle = $this->throttle();
        $request = $this->request('192.0.2.10');

        for ($i = 0; $i < 50; ++$i) {
            self::assertFalse($throttle->isBlocked($request));
        }
        for ($i = 0; $i < 9; ++$i) {
            $throttle->recordFailure($request);
        }

        self::assertFalse($throttle->isBlocked($request));
    }

    #[Test]
    public function otherIpIsNotBlocked(): void
    {
        $throttle = $this->throttle();
        for ($i = 0; $i < 11; ++$i) {
            $throttle->recordFailure($this->request('192.0.2.10'));
        }

        self::assertTrue($throttle->isBlocked($this->request('192.0.2.10')));
        self::assertFalse($throttle->isBlocked($this->request('192.0.2.11')));
        self::assertFalse($throttle->isBlocked($this->request('2001:db8::10')));
    }

    #[Test]
    public function requestWithoutNormalizedParamsIsBlocked(): void
    {
        self::assertTrue($this->throttle()->isBlocked((new ServerRequestFactory())->createServerRequest('GET', 'https://stage.example.org/')));
    }

    #[Test]
    public function storageKeysAreHashedAndRestoreOnlySlidingWindows(): void
    {
        $storage = new RateLimitStorage($this->cache());
        $storage->save(new SlidingWindow('letterbox-external-192.0.2.10', 900));

        self::assertSame([hash('sha256', 'letterbox-external-192.0.2.10')], array_keys($this->cacheEntries));
        self::assertInstanceOf(SlidingWindow::class, $storage->fetch('letterbox-external-192.0.2.10'));

        $this->cacheEntries[hash('sha256', 'foreign')] = serialize(new Window('foreign', 900, 10));
        self::assertNull($storage->fetch('foreign'));

        $storage->delete('letterbox-external-192.0.2.10');
        self::assertSame([hash('sha256', 'foreign')], array_keys($this->cacheEntries));
    }

    private function throttle(): AuthenticationThrottle
    {
        return new AuthenticationThrottle(new RateLimitStorage($this->cache()));
    }

    private function cache(): FrontendInterface
    {
        $cache = self::createStub(FrontendInterface::class);
        $cache->method('get')->willReturnCallback(fn (string $id): mixed => $this->cacheEntries[$id] ?? false);
        $cache->method('set')->willReturnCallback(function (string $id, mixed $data, array $tags = [], ?int $lifetime = null): void {
            $this->cacheEntries[$id] = $data;
            $this->cacheLifetimes[$id] = $lifetime;
        });
        $cache->method('remove')->willReturnCallback(function (string $id): bool {
            unset($this->cacheEntries[$id]);

            return true;
        });

        return $cache;
    }

    private function request(string $ip): ServerRequestInterface
    {
        $server = ['REMOTE_ADDR' => $ip, 'HTTP_HOST' => 'stage.example.org', 'SCRIPT_NAME' => '/index.php', 'HTTPS' => 'on'];
        $params = new NormalizedParams($server, ['reverseProxyIP' => '', 'reverseProxyHeaderMultiValue' => 'none', 'reverseProxyPrefix' => '', 'reverseProxySSL' => '', 'reverseProxyPrefixSSL' => ''], '/project/public/index.php', '/project/public/');

        return (new ServerRequestFactory())->createServerRequest('GET', 'https://stage.example.org/', $server)->withAttribute('normalizedParams', $params);
    }
}
