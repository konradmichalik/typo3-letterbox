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

use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\RateLimiter\{LimiterInterface, RateLimiterFactory};
use TYPO3\CMS\Core\Http\NormalizedParams;

/**
 * AuthenticationThrottle.
 *
 * Failed authentications of both external surfaces share one bucket per client IP. Only failures consume.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class AuthenticationThrottle
{
    private const POLICY = [
        'id' => 'letterbox-external',
        'policy' => 'sliding_window',
        'limit' => 10,
        'interval' => '15 minutes',
    ];

    private RateLimiterFactory $factory;

    public function __construct(RateLimitStorage $storage)
    {
        $this->factory = new RateLimiterFactory(self::POLICY, $storage);
    }

    /**
     * A request without a known client address is always blocked.
     */
    public function isBlocked(ServerRequestInterface $request): bool
    {
        $limiter = $this->limiter($request);

        return null === $limiter || $limiter->consume(0)->getRemainingTokens() < 1;
    }

    public function recordFailure(ServerRequestInterface $request): void
    {
        $this->limiter($request)?->consume(1);
    }

    private function limiter(ServerRequestInterface $request): ?LimiterInterface
    {
        $params = $request->getAttribute('normalizedParams');
        $address = $params instanceof NormalizedParams ? $params->getRemoteAddress() : '';

        return '' === $address ? null : $this->factory->create($address);
    }
}
