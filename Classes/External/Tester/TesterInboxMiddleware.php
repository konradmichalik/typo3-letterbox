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
use KonradMichalik\Typo3Letterbox\Http\DownloadResponseFactory;
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use TYPO3\CMS\Core\Http\NormalizedParams;

use function array_slice;
use function count;
use function is_array;
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
    private const ID_PATTERN = '/^[0-9a-f]{32}$/D';
    private const INDEX_PATTERN = '/^(?:0|[1-9]\d{0,5})$/D';
    private const CSRF_FIELD = 'letterboxCsrf';

    public function __construct(
        private ExternalAccessGuard $guard,
        private AuthenticationThrottle $throttle,
        private ExternalResponses $responses,
        private TesterSession $session,
        private TesterLinkRepository $links,
        private MailRepository $repository,
        private TesterInboxView $view,
        private ResponseFactoryInterface $responseFactory,
        private DownloadResponseFactory $downloads,
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
        if ($request->getMethod() !== ($this->isDeletePath($segments) ? 'POST' : 'GET')) {
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

        return $this->route($request, $segments, $link, $state->baseFilter, $basePath);
    }

    /**
     * @param list<string> $segments
     */
    private function route(ServerRequestInterface $request, array $segments, TesterLink $link, MailFilter $baseFilter, string $basePath): ResponseInterface
    {
        if ([] === $segments) {
            return $this->list($request, $link, $baseFilter, $basePath);
        }
        $id = $segments[0];
        if (1 !== preg_match(self::ID_PATTERN, $id)) {
            return $this->view->notFound($request);
        }
        $filter = $link->filter($baseFilter);

        return match (true) {
            1 === count($segments) => $this->show($request, $link, $filter, $id, $basePath),
            $this->isDeletePath($segments) => $this->delete($request, $link, $filter, $id, $basePath),
            3 === count($segments) && 'attachments' === $segments[1] => $this->attachment($request, $link, $filter, $id, $segments[2]),
            default => $this->view->notFound($request),
        };
    }

    /**
     * @param list<string> $segments
     */
    private function isDeletePath(array $segments): bool
    {
        return 2 === count($segments) && 'delete' === $segments[1];
    }

    private function show(ServerRequestInterface $request, TesterLink $link, MailFilter $filter, string $id, string $basePath): ResponseInterface
    {
        $mail = $this->repository->findById($id, $filter);
        if (null === $mail) {
            return $this->view->notFound($request);
        }
        $textView = 'text' === ($request->getQueryParams()['view'] ?? '');

        return $this->view->show($request, $link, $filter, $mail, $basePath, $textView, $link->canDelete ? $this->csrf($link, $id) : '');
    }

    private function attachment(ServerRequestInterface $request, TesterLink $link, MailFilter $filter, string $id, string $index): ResponseInterface
    {
        $attachment = $link->canDownload && 1 === preg_match(self::INDEX_PATTERN, $index) ? $this->repository->attachment($id, (int) $index, $filter) : null;

        return null === $attachment ? $this->view->notFound($request) : $this->downloads->attachment($attachment);
    }

    /**
     * An authenticated tester with a wrong form token is no attack on the link, so it is a plain 404 without throttling.
     */
    private function delete(ServerRequestInterface $request, TesterLink $link, MailFilter $filter, string $id, string $basePath): ResponseInterface
    {
        $body = $request->getParsedBody();
        $given = is_array($body) ? ($body[self::CSRF_FIELD] ?? null) : null;
        $expected = $this->csrf($link, $id);
        if (!$link->canDelete || '' === $expected || !is_string($given) || !hash_equals($expected, $given) || !$this->repository->delete($id, $filter)) {
            return $this->view->notFound($request);
        }

        return $this->responseFactory->createResponse(303)->withHeader('Location', $basePath);
    }

    /**
     * Binds the delete form to the link and the mail, empty without an encryption key so no form can pass.
     */
    private function csrf(TesterLink $link, string $id): string
    {
        $key = $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] ?? '';

        return is_string($key) && '' !== $key ? hash_hmac('sha256', $link->uid.'|'.$id, $key) : '';
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
