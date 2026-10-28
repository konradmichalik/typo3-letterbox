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
 * The tester inbox session is the raw link token in a strict cookie. Every request looks the link up
 * by the token hash again, so revoking or expiring a link ends every session at once.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class TesterSession
{
    public const COOKIE_NAME = 'letterbox_inbox';

    public function __construct(
        private TesterLinkRepository $repository,
        private Context $context,
    ) {}

    public function fromRequest(ServerRequestInterface $request): ?TesterLink
    {
        return $this->link($this->token($request));
    }

    /**
     * The cookie value as sent, empty when there is none.
     */
    public function token(ServerRequestInterface $request): string
    {
        $value = $request->getCookieParams()[self::COOKIE_NAME] ?? '';

        return is_string($value) ? $value : '';
    }

    public function link(string $token): ?TesterLink
    {
        $link = '' === $token ? null : $this->repository->findByToken($token);

        return null !== $link && $link->isUsable($this->now()) ? $link : null;
    }

    public function cookie(string $token, TesterLink $link, string $path): string
    {
        return (string) new Cookie(self::COOKIE_NAME, $token, $link->expires, $path, null, true, true, false, Cookie::SAMESITE_STRICT);
    }

    /**
     * Same name, path and attributes as the session cookie, with an expiry in the past.
     */
    public function expiredCookie(string $path): string
    {
        return (string) new Cookie(self::COOKIE_NAME, '', 1, $path, null, true, true, false, Cookie::SAMESITE_STRICT);
    }

    public function now(): int
    {
        $now = $this->context->getPropertyFromAspect('date', 'full');

        return $now instanceof DateTimeImmutable ? $now->getTimestamp() : time();
    }
}
