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

namespace KonradMichalik\Typo3Letterbox\Command;

use function array_slice;
use function count;
use function is_string;
use function strlen;

/**
 * SendmailArguments.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class SendmailArguments
{
    /**
     * Option letters of sendmail that take a value, attached (-fsender) or as the next argument (-f sender).
     */
    private const VALUE_OPTIONS = 'BbCdeFfhLMNOopRrVX';

    /**
     * @param list<string> $recipients
     */
    private function __construct(
        public ?string $sender,
        public bool $recipientsFromHeaders,
        public array $recipients,
    ) {}

    /**
     * Parses sendmail arguments like getopt does: flags may be combined (-ti), unknown flags are ignored.
     *
     * @param list<string> $argv the arguments after the command name
     */
    public static function fromArgv(array $argv): self
    {
        $sender = null;
        $fromHeaders = false;
        $recipients = [];
        $optionsEnded = false;
        for ($i = 0, $count = count($argv); $i < $count; ++$i) {
            $token = $argv[$i];
            if ($optionsEnded || !str_starts_with($token, '-')) {
                $recipients = [...$recipients, ...self::split($token)];
                continue;
            }
            if ('--' === $token) {
                $optionsEnded = true;
                continue;
            }

            [$flags, $option, $value, $valueIsNext] = self::option($token, $argv[$i + 1] ?? null);
            $fromHeaders = $fromHeaders || str_contains($flags, 't');
            if ('f' === $option || 'r' === $option) {
                $sender = self::address($value);
            }
            $i += $valueIsNext ? 1 : 0;
        }

        return new self('' === $sender ? null : $sender, $fromHeaders, $recipients);
    }

    /**
     * @param array<array-key, mixed> $argv the raw $_SERVER['argv'], whose command name may follow the script path and global options
     *
     * @return list<string> the tokens after the command name, empty when it is missing
     */
    public static function afterCommand(array $argv, string $commandName): array
    {
        $tokens = array_values(array_filter($argv, is_string(...)));
        $position = array_search($commandName, $tokens, true);

        return false === $position ? [] : array_slice($tokens, $position + 1);
    }

    /**
     * @return array{string, ?string, string, bool} the flags without value, the option with a value, that value, whether the value is the next argument
     */
    private static function option(string $token, ?string $next): array
    {
        if (str_starts_with($token, '--')) {
            return ['', null, '', false];
        }

        $letters = substr($token, 1);
        $flagsLength = strcspn($letters, self::VALUE_OPTIONS);
        if (strlen($letters) === $flagsLength) {
            return [$letters, null, '', false];
        }

        $flags = substr($letters, 0, $flagsLength);
        $attached = substr($letters, $flagsLength + 1);
        if ('' !== $attached || null === $next) {
            return [$flags, $letters[$flagsLength], $attached, false];
        }

        return [$flags, $letters[$flagsLength], $next, true];
    }

    /**
     * @return list<string>
     */
    private static function split(string $token): array
    {
        $addresses = [];
        foreach (explode(',', $token) as $part) {
            $address = self::address($part);
            if ('' !== $address) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }

    private static function address(string $value): string
    {
        $address = trim($value);
        if (str_starts_with($address, '<') && str_ends_with($address, '>')) {
            $address = trim(substr($address, 1, -1));
        }

        return $address;
    }
}
