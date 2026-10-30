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

use KonradMichalik\Typo3Letterbox\Domain\Model\{MailFilter, QuickFilter};
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use KonradMichalik\Typo3Letterbox\External\{ExternalAccessGuard, ExternalResponses};
use KonradMichalik\Typo3Letterbox\Http\DownloadResponseFactory;
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
 * The link secret is exchanged once for a session cookie; every refusal, whatever the reason, is the same 404.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class TesterInboxMiddleware implements MiddlewareInterface
{
    public const PATH_PREFIX = '_letterbox/inbox';
    public const TOKEN_PARAMETER = 't';
    public const REMOTE_PARAMETER = 'images';
    private const CSP_HEAD = "default-src 'none'; style-src 'self' 'unsafe-inline'; img-src 'self' data:";
    private const CSP_TAIL = "; form-action 'self'; base-uri 'none'; frame-ancestors 'none'";
    private const CSP = self::CSP_HEAD.self::CSP_TAIL;
    // The detail page of one mail with its remote images: the sandboxed srcdoc frame inherits this policy. The frame
    // document itself still allows images over HTTPS only.
    private const CSP_REMOTE_IMAGES = self::CSP_HEAD.' https:'.self::CSP_TAIL;
    private const PER_PAGE = 20;
    private const MAX_QUERY_LENGTH = 200;
    private const ID_PATTERN = '/^[0-9a-f]{32}$/D';
    private const INDEX_PATTERN = '/^(?:0|[1-9]\d{0,5})$/D';

    public function __construct(
        private ExternalAccessGuard $guard,
        private ExternalResponses $responses,
        private TesterSession $session,
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

        $policy = $this->wantsRemoteImages($request, $segments) ? self::CSP_REMOTE_IMAGES : self::CSP;

        return $this->responses->withPolicy($this->answer($request, $segments, $params->getSitePath().self::PATH_PREFIX), $policy);
    }

    /**
     * @param list<string> $segments
     */
    private function answer(ServerRequestInterface $request, array $segments, string $basePath): ResponseInterface
    {
        if ('GET' !== $request->getMethod()) {
            return $this->view->notFound($request);
        }
        if (!$this->guard->evaluate($request)->active) {
            return $this->view->notFound($request);
        }
        $token = $this->guard->token();
        if ([] === $segments && null !== ($request->getQueryParams()[self::TOKEN_PARAMETER] ?? null)) {
            return $this->exchange($request, $token, $this->guard->testerLinkSecret(), $basePath);
        }
        if (!$this->session->isValid($request, $token)) {
            $notFound = $this->view->notFound($request);

            return $this->session->hasCookie($request) ? $notFound->withAddedHeader('Set-Cookie', $this->session->expiredCookie($basePath)) : $notFound;
        }

        return $this->route($request, $segments, $basePath);
    }

    /**
     * @param list<string> $segments
     */
    private function route(ServerRequestInterface $request, array $segments, string $basePath): ResponseInterface
    {
        if ([] === $segments) {
            return $this->list($request, $basePath);
        }
        $id = $segments[0];
        if (1 !== preg_match(self::ID_PATTERN, $id) || $this->repository->hasBackendReset($id)) {
            return $this->view->notFound($request);
        }

        return match (true) {
            1 === count($segments) => $this->show($request, $id, $basePath),
            3 === count($segments) && 'attachments' === $segments[1] => $this->attachment($request, $id, $segments[2]),
            default => $this->view->notFound($request),
        };
    }

    private function show(ServerRequestInterface $request, string $id, string $basePath): ResponseInterface
    {
        $mail = $this->repository->findById($id);
        if (null === $mail) {
            return $this->view->notFound($request);
        }
        $textView = 'text' === ($request->getQueryParams()['view'] ?? '');

        return $this->view->show($request, $mail, $basePath, $textView, $this->wantsRemoteImages($request, [$id]));
    }

    /**
     * Only the detail page of one mail can load remote images, and never in the text view.
     *
     * @param list<string> $segments
     */
    private function wantsRemoteImages(ServerRequestInterface $request, array $segments): bool
    {
        $query = $request->getQueryParams();

        return 1 === count($segments)
            && 1 === preg_match(self::ID_PATTERN, $segments[0])
            && '1' === ($query[self::REMOTE_PARAMETER] ?? null)
            && 'text' !== ($query['view'] ?? '');
    }

    private function attachment(ServerRequestInterface $request, string $id, string $index): ResponseInterface
    {
        $attachment = 1 === preg_match(self::INDEX_PATTERN, $index) ? $this->repository->attachment($id, (int) $index) : null;

        return null === $attachment ? $this->view->notFound($request) : $this->downloads->attachment($attachment);
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
     * The link carries the derived link secret, never the token. It leaves the address bar right away: the redirect
     * target carries no query at all.
     */
    private function exchange(ServerRequestInterface $request, string $token, string $linkSecret, string $basePath): ResponseInterface
    {
        $given = $request->getQueryParams()[self::TOKEN_PARAMETER];
        if ('' === $linkSecret || !is_string($given) || !hash_equals($linkSecret, $given)) {
            return $this->view->notFound($request);
        }

        return $this->responseFactory->createResponse(303)
            ->withHeader('Location', $basePath)
            ->withAddedHeader('Set-Cookie', $this->session->cookie($token, $basePath));
    }

    private function list(ServerRequestInterface $request, string $basePath): ResponseInterface
    {
        $query = $this->query($request);
        $filter = MailFilter::create($query, QuickFilter::All, [])->withoutBackendReset();
        $total = $this->repository->count($filter);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($this->page($request), $pages);

        return $this->view->list(
            $request,
            $basePath,
            $query,
            $page,
            $pages,
            $total,
            $this->repository->findEntries($filter, ($page - 1) * self::PER_PAGE, self::PER_PAGE),
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
