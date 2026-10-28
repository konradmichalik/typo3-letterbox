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

namespace KonradMichalik\Typo3Letterbox\External\Api;

use KonradMichalik\Typo3Letterbox\Domain\Model\MailFilter;
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use KonradMichalik\Typo3Letterbox\External\{ExternalAccessGuard, ExternalResponses};
use KonradMichalik\Typo3Letterbox\Http\DownloadResponseFactory;
use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, ServerRequestInterface};
use Psr\Http\Server\{MiddlewareInterface, RequestHandlerInterface};
use Psr\Log\LoggerInterface;
use RuntimeException;
use TYPO3\CMS\Core\Http\NormalizedParams;

use function array_slice;
use function count;
use function is_string;
use function strlen;

/**
 * ApiMiddleware.
 *
 * The token protected JSON API for E2E tests under `<site path>_letterbox/api/v1/`. It answers before
 * site resolution. Every refusal, whatever the reason, is the same 404.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ApiMiddleware implements MiddlewareInterface
{
    public const PATH_PREFIX = '_letterbox/api/v1';
    private const CSP = "default-src 'none'; frame-ancestors 'none'";
    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;
    private const MAX_OFFSET = 1_000_000;
    private const ID_PATTERN = '/^[0-9a-f]{32}$/';

    public function __construct(
        private ExternalAccessGuard $guard,
        private ExternalResponses $responses,
        private MailRepository $repository,
        private MailJson $mailJson,
        private DownloadResponseFactory $downloads,
        private ResponseFactoryInterface $responseFactory,
        private LoggerInterface $logger,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $segments = $this->segments($request);
        if (null === $segments) {
            return $handler->handle($request);
        }

        return $this->responses->withPolicy($this->answer($request, $segments), self::CSP);
    }

    /**
     * @param list<string> $segments
     */
    private function answer(ServerRequestInterface $request, array $segments): ResponseInterface
    {
        if (!$this->guard->evaluate($request)->active || !$this->authenticated($request)) {
            return $this->responses->notFound(true);
        }

        return $this->route($request, $segments) ?? $this->responses->notFound(true);
    }

    /**
     * The path below the site path split into segments after the prefix, null when the request is not for the API.
     *
     * @return list<string>|null
     */
    private function segments(ServerRequestInterface $request): ?array
    {
        $params = $request->getAttribute('normalizedParams');
        if (!$params instanceof NormalizedParams) {
            return null;
        }
        $sitePath = $params->getSitePath();
        $path = $request->getUri()->getPath();
        if ('' === $sitePath || !str_starts_with($path, $sitePath)) {
            return null;
        }
        $segments = explode('/', substr($path, strlen($sitePath)));
        $prefix = explode('/', self::PATH_PREFIX);

        return array_slice($segments, 0, count($prefix)) === $prefix ? array_slice($segments, count($prefix)) : null;
    }

    /**
     * A token in the query string would end up in logs, so it refuses the request even when it is right.
     */
    private function authenticated(ServerRequestInterface $request): bool
    {
        $configured = $this->guard->token();

        return '' !== $configured && !$this->hasQueryToken($request) && hash_equals(hash('sha256', $configured), hash('sha256', $this->givenToken($request)));
    }

    /**
     * `X-Letterbox-Token` first, because behind server level basic auth `Authorization` carries other credentials.
     */
    private function givenToken(ServerRequestInterface $request): string
    {
        $header = trim($request->getHeaderLine('X-Letterbox-Token'));
        if ('' !== $header) {
            return $header;
        }

        return 1 === preg_match('/^Bearer\s+(\S+)\s*$/i', $request->getHeaderLine('Authorization'), $matches) ? $matches[1] : '';
    }

    private function hasQueryToken(ServerRequestInterface $request): bool
    {
        foreach (array_keys($request->getQueryParams()) as $name) {
            if (str_contains(strtolower((string) $name), 'token')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $segments
     */
    private function route(ServerRequestInterface $request, array $segments): ?ResponseInterface
    {
        $method = $request->getMethod();
        $filter = MailFilter::all();

        return match (true) {
            ['messages'] === $segments && 'GET' === $method => $this->list($request, $filter),
            ['messages'] === $segments && 'DELETE' === $method => $this->deleteAll(),
            ['messages', 'latest'] === $segments && 'GET' === $method => $this->latest($request, $filter),
            'messages' === ($segments[0] ?? null) && 1 === preg_match(self::ID_PATTERN, $segments[1] ?? '') => $this->message($method, $segments[1], array_slice($segments, 2)),
            default => null,
        };
    }

    /**
     * @param list<string> $action the segments after the id
     */
    private function message(string $method, string $id, array $action): ?ResponseInterface
    {
        return match (true) {
            [] === $action && 'GET' === $method => $this->detail($id),
            [] === $action && 'DELETE' === $method => $this->delete($id),
            ['source'] === $action && 'GET' === $method => $this->source($id),
            2 === count($action) && 'attachments' === $action[0] && 'GET' === $method => $this->attachment($id, $action[1]),
            default => null,
        };
    }

    private function list(ServerRequestInterface $request, MailFilter $filter): ?ResponseInterface
    {
        $to = $this->stringParameter($request, 'to');
        $subject = $this->stringParameter($request, 'subject');
        $limit = $this->integerParameter($request, 'limit', self::DEFAULT_LIMIT, 1, self::MAX_LIMIT);
        $offset = $this->integerParameter($request, 'offset', 0, 0, self::MAX_OFFSET);
        if (null === $to || null === $subject || null === $limit || null === $offset) {
            return null;
        }
        $filter = $filter->withRecipient($to)->withSubject($subject);

        return $this->responses->json([
            'total' => $this->repository->count($filter),
            'items' => array_map($this->mailJson->summary(...), $this->repository->find($filter, $offset, $limit)),
        ]);
    }

    private function latest(ServerRequestInterface $request, MailFilter $filter): ?ResponseInterface
    {
        $to = $this->stringParameter($request, 'to');
        $mail = null === $to ? null : $this->repository->latest($filter->withRecipient($to));

        return null === $mail ? null : $this->responses->json($this->mailJson->summary($mail));
    }

    private function detail(string $id): ?ResponseInterface
    {
        $mail = $this->repository->findById($id);

        return null === $mail ? null : $this->responses->json($this->mailJson->detail($mail));
    }

    private function source(string $id): ?ResponseInterface
    {
        $mail = $this->repository->findById($id);

        return null === $mail ? null : $this->downloads->source($mail);
    }

    private function attachment(string $id, string $index): ?ResponseInterface
    {
        if (1 !== preg_match('/^\d{1,4}$/', $index)) {
            return null;
        }
        $attachment = $this->repository->attachment($id, (int) $index);

        return null === $attachment ? null : $this->downloads->attachment($attachment);
    }

    private function delete(string $id): ?ResponseInterface
    {
        try {
            return $this->repository->delete($id) ? $this->noContent() : null;
        } catch (RuntimeException $e) {
            return $this->writeFailed($e);
        }
    }

    private function deleteAll(): ResponseInterface
    {
        try {
            $this->repository->clear();
        } catch (RuntimeException $e) {
            return $this->writeFailed($e);
        }

        return $this->noContent();
    }

    /**
     * The message names the absolute mailbox path, so neither the client nor the log gets it.
     */
    private function writeFailed(RuntimeException $e): ResponseInterface
    {
        $this->logger->error('The API could not change the mailbox: {exception}', ['exception' => $e::class]);

        return $this->responses->json(['error' => 'write_failed'], 500);
    }

    private function noContent(): ResponseInterface
    {
        return $this->responseFactory->createResponse(204);
    }

    /**
     * @return string|null trimmed, empty when absent, null when not a plain string
     */
    private function stringParameter(ServerRequestInterface $request, string $name): ?string
    {
        $value = $request->getQueryParams()[$name] ?? '';

        return is_string($value) ? trim($value) : null;
    }

    /**
     * @return int|null the default when absent, null when not a plain number in range
     */
    private function integerParameter(ServerRequestInterface $request, string $name, int $default, int $min, int $max): ?int
    {
        $value = $request->getQueryParams()[$name] ?? null;
        if (null === $value) {
            return $default;
        }
        if (!is_string($value) || 1 !== preg_match('/^\d{1,7}$/', $value)) {
            return null;
        }
        $number = (int) $value;

        return $number >= $min && $number <= $max ? $number : null;
    }
}
