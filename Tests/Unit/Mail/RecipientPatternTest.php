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

namespace KonradMichalik\Typo3Letterbox\Tests\Unit\Mail;

use InvalidArgumentException;
use KonradMichalik\Typo3Letterbox\Mail\RecipientPattern;
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use PHPUnit\Framework\TestCase;

/**
 * RecipientPatternTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class RecipientPatternTest extends TestCase
{
    #[Test]
    public function exactAddressMatchesCaseInsensitive(): void
    {
        self::assertTrue(RecipientPattern::fromString('QA@Example.org')->matches('qa@example.org'));
    }

    #[Test]
    public function valueIsTheNormalizedFormThatParsesBack(): void
    {
        self::assertSame('anna@customer.example', RecipientPattern::fromString(' Anna@Customer.example ')->value());
        self::assertSame('*@customer.example', RecipientPattern::fromString('*@Customer.example')->value());
        self::assertTrue(RecipientPattern::fromString(RecipientPattern::fromString('*@Customer.example')->value())->matches('x@customer.example'));
    }

    #[Test]
    public function exactAddressDoesNotMatchOtherAddress(): void
    {
        self::assertFalse(RecipientPattern::fromString('qa@example.org')->matches('dev@example.org'));
    }

    #[Test]
    public function domainPatternMatchesOnlyThatDomain(): void
    {
        $pattern = RecipientPattern::fromString('*@letterbox.invalid');

        self::assertTrue($pattern->matches('anna@letterbox.invalid'));
        self::assertTrue($pattern->matches('ANNA@Letterbox.Invalid'));
        self::assertFalse($pattern->matches('anna@letterbox.invalid.evil.com'));
        self::assertFalse($pattern->matches('anna@sub.letterbox.invalid'));
    }

    #[Test]
    #[DataProvider('invalidPatterns')]
    public function rejectsInvalidPatterns(string $pattern): void
    {
        $this->expectException(InvalidArgumentException::class);

        RecipientPattern::fromString($pattern);
    }

    /**
     * @return iterable<array{string}>
     */
    public static function invalidPatterns(): iterable
    {
        yield ['*'];
        yield [''];
        yield ['*@'];
        yield ['foo*@bar.de'];
        yield ['/.*/'];
    }
}
