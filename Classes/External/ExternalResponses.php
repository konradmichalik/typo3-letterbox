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

use Psr\Http\Message\{ResponseFactoryInterface, ResponseInterface, StreamFactoryInterface};

/**
 * ExternalResponses.
 *
 * Builds the responses of the surfaces outside the backend, each with the same header policy.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class ExternalResponses
{
    public const DEFAULT_POLICY = "default-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'";
    private const FRAME_ANCESTORS = "frame-ancestors 'none'";
    private const POLICY_HEADERS = [
        'Cache-Control' => 'no-store, private',
        'Vary' => 'Authorization, Cookie, X-Letterbox-Token',
        'X-Robots-Tag' => 'noindex',
        'Referrer-Policy' => 'no-referrer',
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * The one answer for inactive, unconfigured, unauthorised, throttled and unknown requests.
     */
    public function notFound(bool $json): ResponseInterface
    {
        return $json ? $this->json(['error' => 'not_found'], 404) : $this->withPolicy($this->responseFactory->createResponse(404), self::DEFAULT_POLICY);
    }

    /**
     * Invalid UTF-8, which mail bodies can carry, is substituted so a broken mail never fails the response.
     *
     * @param array<array-key, mixed> $data
     */
    public function json(array $data, int $status = 200): ResponseInterface
    {
        $body = json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE);

        return $this->withPolicy(
            $this->responseFactory->createResponse($status)
                ->withHeader('Content-Type', 'application/json; charset=utf-8')
                ->withBody($this->streamFactory->createStream($body)),
            self::DEFAULT_POLICY,
        );
    }

    /**
     * Replaces any header of the policy and forces `frame-ancestors 'none'` into the given CSP.
     */
    public function withPolicy(ResponseInterface $response, string $csp): ResponseInterface
    {
        foreach (self::POLICY_HEADERS as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response->withHeader('Content-Security-Policy', $this->policy($csp));
    }

    private function policy(string $csp): string
    {
        $directives = array_filter(
            array_map(trim(...), explode(';', $csp)),
            static fn (string $directive): bool => '' !== $directive && !str_starts_with(strtolower($directive), 'frame-ancestors'),
        );

        return implode('; ', [...$directives, self::FRAME_ANCESTORS]);
    }
}
