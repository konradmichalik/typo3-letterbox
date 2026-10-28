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

namespace KonradMichalik\Typo3Letterbox\Tests\Unit\Backend;

use KonradMichalik\Typo3Letterbox\Backend\InboxQuery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Http\ServerRequest;

use function strlen;

/**
 * InboxQueryTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class InboxQueryTest extends TestCase
{
    #[Test]
    public function queryIsTrimmedAndCappedAt200Characters(): void
    {
        self::assertSame('', $this->query(['q' => '   '])->query);
        self::assertSame('abc', $this->query(['q' => '  abc  '])->query);

        $capped = $this->query(['q' => str_repeat('ä', 250)])->query;
        self::assertSame(200, mb_strlen($capped));
        self::assertGreaterThan(200, strlen($capped));
    }

    #[Test]
    public function idMustBeA32CharacterLowercaseHexString(): void
    {
        self::assertSame(str_repeat('a', 32), $this->query(['id' => str_repeat('a', 32)])->id);
        self::assertNull($this->query(['id' => str_repeat('A', 32)])->id);
        self::assertNull($this->query(['id' => str_repeat('a', 32)."\n"])->id);
        self::assertNull($this->query(['id' => '../etc/passwd'])->id);
        self::assertNull($this->query(['id' => str_repeat('0', 31)])->id);
    }

    /**
     * @param array<string, string> $params
     */
    private function query(array $params): InboxQuery
    {
        return InboxQuery::fromRequest((new ServerRequest('https://example.org/'))->withQueryParams($params));
    }
}
