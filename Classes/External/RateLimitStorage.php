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

namespace KonradMichalik\Typo3Letterbox\External;

use Symfony\Component\RateLimiter\LimiterStateInterface;
use Symfony\Component\RateLimiter\Policy\SlidingWindow;
use Symfony\Component\RateLimiter\Storage\StorageInterface;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

use function is_string;

/**
 * RateLimitStorage.
 *
 * Rate limiter state in the caching framework, like the internal core storage but restricted to sliding windows.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RateLimitStorage implements StorageInterface
{
    public function __construct(
        private FrontendInterface $cache,
    ) {}

    public function save(LimiterStateInterface $limiterState): void
    {
        $this->cache->set(self::key($limiterState->getId()), serialize($limiterState), [], $limiterState->getExpirationTime());
    }

    public function fetch(string $limiterStateId): ?LimiterStateInterface
    {
        $item = $this->cache->get(self::key($limiterStateId));
        if (!is_string($item)) {
            return null;
        }

        $state = unserialize($item, ['allowed_classes' => [SlidingWindow::class]]);

        return $state instanceof SlidingWindow ? $state : null;
    }

    public function delete(string $limiterStateId): void
    {
        $this->cache->remove(self::key($limiterStateId));
    }

    private static function key(string $limiterStateId): string
    {
        return hash('sha256', $limiterStateId);
    }
}
