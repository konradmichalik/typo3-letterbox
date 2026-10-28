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

namespace KonradMichalik\Typo3Letterbox\External\Tester;

use DateTimeImmutable;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\HttpFoundation\Cookie;
use TYPO3\CMS\Core\Context\Context;

use function is_string;

/**
 * TesterSession.
 *
 * The tester inbox session is an HMAC of the access token in a SameSite=Lax cookie (Lax, so a link clicked in
 * webmail or chat survives the 303). The browser never stores the token itself, and a changed token ends every
 * session at once.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class TesterSession
{
    public const COOKIE_NAME = 'letterbox_inbox';
    private const LIFETIME = 7 * 86400;

    public function __construct(
        private Context $context,
    ) {}

    public function hasCookie(ServerRequestInterface $request): bool
    {
        return '' !== $this->cookieValue($request);
    }

    public function isValid(ServerRequestInterface $request, string $token): bool
    {
        $value = $this->cookieValue($request);

        return '' !== $token && '' !== $value && hash_equals(self::sessionValue($token), $value);
    }

    public function cookie(string $token, string $path): string
    {
        return (string) new Cookie(self::COOKIE_NAME, self::sessionValue($token), $this->now() + self::LIFETIME, $path, null, true, true, false, Cookie::SAMESITE_LAX);
    }

    /**
     * Same name, path and attributes as the session cookie, with an expiry in the past.
     */
    public function expiredCookie(string $path): string
    {
        return (string) new Cookie(self::COOKIE_NAME, '', 1, $path, null, true, true, false, Cookie::SAMESITE_LAX);
    }

    private static function sessionValue(string $token): string
    {
        return hash_hmac('sha256', 'inbox', $token);
    }

    private function cookieValue(ServerRequestInterface $request): string
    {
        $value = $request->getCookieParams()[self::COOKIE_NAME] ?? '';

        return is_string($value) ? $value : '';
    }

    private function now(): int
    {
        $now = $this->context->getPropertyFromAspect('date', 'full');

        return $now instanceof DateTimeImmutable ? $now->getTimestamp() : time();
    }
}
