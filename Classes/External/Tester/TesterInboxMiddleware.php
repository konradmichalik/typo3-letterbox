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

use KonradMichalik\Typo3Letterbox\Domain\Model\MailFilter;
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use KonradMichalik\Typo3Letterbox\External\{AuthenticationThrottle, ExternalAccessGuard, ExternalResponses};
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use TYPO3\CMS\Core\Http\NormalizedParams;

use function array_slice;
use function count;
use function is_string;
use function strlen;

/**
 * TesterInboxMiddleware.
 *
 * The read-only tester inbox under `<site path>_letterbox/inbox` for people without a backend account.
 * An issued link is exchanged once for a strict cookie; every refusal, whatever the reason, is the same 404.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class TesterInboxMiddleware implements MiddlewareInterface
{
    public const PATH_PREFIX = '_letterbox/inbox';
    private const CSP = "default-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'";
    private const TOKEN_PARAMETER = 't';
    private const PER_PAGE = 20;
    private const MAX_QUERY_LENGTH = 200;

    public function __construct(
        private ExternalAccessGuard $guard,
        private AuthenticationThrottle $throttle,
        private ExternalResponses $responses,
        private TesterSession $session,
        private TesterLinkRepository $links,
        private MailRepository $repository,
        private TesterInboxView $view,
        private ResponseFactoryInterface $responseFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $params = $request->getAttribute('normalizedParams');
        if (!$params instanceof NormalizedParams) {
            return $handler->handle($request);
        }
        $segments = $this->segments($request, $params->getSitePath());
        if (null === $segments) {
            return $handler->handle($request);
        }

        return $this->responses->withPolicy($this->answer($request, $segments, $params->getSitePath().self::PATH_PREFIX), self::CSP);
    }

    /**
     * @param list<string> $segments
     */
    private function answer(ServerRequestInterface $request, array $segments, string $basePath): ResponseInterface
    {
        if ('GET' !== $request->getMethod()) {
            return $this->view->notFound($request);
        }
        $state = $this->guard->evaluate($request);
        if (!$state->active || $this->throttle->isBlocked($request)) {
            return $this->view->notFound($request);
        }
        if ([] === $segments && null !== ($request->getQueryParams()[self::TOKEN_PARAMETER] ?? null)) {
            return $this->exchange($request, $basePath);
        }

        $token = $this->session->token($request);
        if ('' === $token) {
            return $this->view->notFound($request);
        }
        $link = $this->session->link($token);
        if (null === $link) {
            $this->throttle->recordFailure($request);

            return $this->view->notFound($request)->withAddedHeader('Set-Cookie', $this->session->expiredCookie($basePath));
        }
        $this->links->touch($link->uid, $this->session->now());

        return [] === $segments ? $this->list($request, $link, $state->baseFilter, $basePath) : $this->view->notFound($request);
    }

    /**
     * The path below the site path split into segments after the prefix, null when the request is not for the inbox.
     *
     * @return list<string>|null
     */
    private function segments(ServerRequestInterface $request, string $sitePath): ?array
    {
        $path = $request->getUri()->getPath();
        if ('' === $sitePath || !str_starts_with($path, $sitePath)) {
            return null;
        }
        $segments = explode('/', substr($path, strlen($sitePath)));
        $prefix = explode('/', self::PATH_PREFIX);

        return array_slice($segments, 0, count($prefix)) === $prefix ? array_slice($segments, count($prefix)) : null;
    }

    /**
     * The token leaves the address bar right away: the redirect target carries no query at all.
     */
    private function exchange(ServerRequestInterface $request, string $basePath): ResponseInterface
    {
        $token = $request->getQueryParams()[self::TOKEN_PARAMETER];
        $link = is_string($token) ? $this->session->link($token) : null;
        if (!is_string($token) || null === $link) {
            $this->throttle->recordFailure($request);

            return $this->view->notFound($request);
        }
        $this->links->touch($link->uid, $this->session->now());

        return $this->responseFactory->createResponse(303)
            ->withHeader('Location', $basePath)
            ->withAddedHeader('Set-Cookie', $this->session->cookie($token, $link, $basePath));
    }

    private function list(ServerRequestInterface $request, TesterLink $link, MailFilter $baseFilter, string $basePath): ResponseInterface
    {
        $linkFilter = $link->filter($baseFilter);
        $query = $this->query($request);
        $filter = '' === $query ? $linkFilter : $linkFilter->withScopedQuery($query);
        $total = $this->repository->count($filter);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($this->page($request), $pages);

        return $this->view->list(
            $request,
            $link,
            $linkFilter,
            $basePath,
            $query,
            $page,
            $pages,
            $total,
            $this->repository->find($filter, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
        );
    }

    private function query(ServerRequestInterface $request): string
    {
        $value = $request->getQueryParams()['q'] ?? '';

        return is_string($value) ? mb_substr(trim($value), 0, self::MAX_QUERY_LENGTH) : '';
    }

    private function page(ServerRequestInterface $request): int
    {
        $value = $request->getQueryParams()['p'] ?? '';

        return is_string($value) && 1 === preg_match('/^[1-9]\d{0,8}$/D', $value) ? (int) $value : 1;
    }
}
