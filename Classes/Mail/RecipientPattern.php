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

namespace KonradMichalik\Typo3Letterbox\Mail;

use InvalidArgumentException;

use function sprintf;

/**
 * RecipientPattern.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RecipientPattern
{
    private function __construct(
        private string $value,
        private bool $domainWide,
    ) {}

    public static function fromString(string $pattern): self
    {
        $normalized = strtolower(trim($pattern));

        if (substr_count($normalized, '*') > (str_starts_with($normalized, '*@') ? 1 : 0)) {
            throw new InvalidArgumentException(sprintf('Invalid recipient pattern "%s".', $pattern), 1761561602);
        }

        if (str_starts_with($normalized, '*@')) {
            $domain = substr($normalized, 2);
            if (false === filter_var('probe@'.$domain, \FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException(sprintf('Invalid recipient pattern "%s".', $pattern), 1761561600);
            }

            return new self($domain, true);
        }

        if (false === filter_var($normalized, \FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException(sprintf('Invalid recipient pattern "%s".', $pattern), 1761561601);
        }

        return new self($normalized, false);
    }

    /**
     * The normalized form that fromString() parses back to an equal pattern.
     */
    public function value(): string
    {
        return $this->domainWide ? '*@'.$this->value : $this->value;
    }

    public function matches(string $address): bool
    {
        $normalized = strtolower(trim($address));

        if (!$this->domainWide) {
            return $normalized === $this->value;
        }

        $at = strrpos($normalized, '@');

        return false !== $at && substr($normalized, $at + 1) === $this->value;
    }
}
