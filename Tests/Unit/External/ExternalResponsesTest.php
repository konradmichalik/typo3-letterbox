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

use KonradMichalik\Typo3Letterbox\External\ExternalResponses;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\{Response, ResponseFactory, StreamFactory};

/**
 * ExternalResponsesTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ExternalResponsesTest extends TestCase
{
    private const POLICY_HEADERS = [
        'Cache-Control' => 'no-store, private',
        'Vary' => 'Authorization, Cookie, X-Letterbox-Token',
        'X-Robots-Tag' => 'noindex',
        'Referrer-Policy' => 'no-referrer',
        'X-Frame-Options' => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
    ];

    #[Test]
    public function everyResponseCarriesThePolicyHeaders(): void
    {
        $responses = $this->responses();
        $cases = [
            'html 404' => $responses->notFound(false),
            'json 404' => $responses->notFound(true),
            'json' => $responses->json(['ok' => true]),
            'custom' => $responses->withPolicy((new Response())->withHeader('Cache-Control', 'public, max-age=60')->withHeader('Vary', 'Accept'), "default-src 'self'; frame-ancestors *"),
        ];

        foreach ($cases as $label => $response) {
            foreach (self::POLICY_HEADERS as $name => $value) {
                self::assertSame([$value], $response->getHeader($name), $label.': '.$name);
            }
            self::assertStringEndsWith("frame-ancestors 'none'", $response->getHeaderLine('Content-Security-Policy'), $label);
            self::assertSame(1, substr_count($response->getHeaderLine('Content-Security-Policy'), 'frame-ancestors'), $label);
        }

        self::assertSame("default-src 'self'; frame-ancestors 'none'", $cases['custom']->getHeaderLine('Content-Security-Policy'));
        self::assertSame(ExternalResponses::DEFAULT_POLICY, $cases['html 404']->getHeaderLine('Content-Security-Policy'));
        self::assertSame([...array_keys(self::POLICY_HEADERS), 'Content-Security-Policy'], array_keys($cases['html 404']->getHeaders()));
    }

    #[Test]
    public function notFoundCarriesNoRequestData(): void
    {
        $html = $this->responses()->notFound(false);
        $json = $this->responses()->notFound(true);

        self::assertSame(404, $html->getStatusCode());
        self::assertSame('', (string) $html->getBody());
        self::assertSame(404, $json->getStatusCode());
        self::assertSame('{"error":"not_found"}', (string) $json->getBody());
        self::assertSame('application/json; charset=utf-8', $json->getHeaderLine('Content-Type'));
    }

    #[Test]
    public function jsonEncodesWithStatus(): void
    {
        $response = $this->responses()->json(['path' => '/a/b', 'name' => 'Ü'], 201);

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('{"path":"/a/b","name":"Ü"}', (string) $response->getBody());
    }

    private function responses(): ExternalResponses
    {
        return new ExternalResponses(new ResponseFactory(), new StreamFactory());
    }
}
