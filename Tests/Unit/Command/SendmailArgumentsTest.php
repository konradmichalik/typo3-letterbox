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

namespace KonradMichalik\Typo3Letterbox\Tests\Unit\Command;

use KonradMichalik\Typo3Letterbox\Command\SendmailArguments;
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use PHPUnit\Framework\TestCase;

/**
 * SendmailArgumentsTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class SendmailArgumentsTest extends TestCase
{
    /**
     * @param list<string> $argv
     * @param list<string> $recipients
     */
    #[Test]
    #[DataProvider('argumentTable')]
    public function parsesSendmailArguments(array $argv, ?string $sender, bool $fromHeaders, array $recipients): void
    {
        $arguments = SendmailArguments::fromArgv($argv);

        self::assertSame($sender, $arguments->sender);
        self::assertSame($fromHeaders, $arguments->recipientsFromHeaders);
        self::assertSame($recipients, $arguments->recipients);
    }

    /**
     * @return array<string, array{list<string>, ?string, bool, list<string>}>
     */
    public static function argumentTable(): array
    {
        return [
            'nothing' => [[], null, false, []],
            'php default -t -i' => [['-t', '-i'], null, true, []],
            'combined -ti' => [['-ti'], null, true, []],
            'combined -it' => [['-it'], null, true, []],
            '-oi alone' => [['-oi'], null, false, []],
            '-oi with recipient' => [['-oi', 'a@example.org'], null, false, ['a@example.org']],
            '-o with separate value' => [['-o', 'i', 'a@example.org'], null, false, ['a@example.org']],
            '-oX style options' => [['-oQ/var/spool', '-oem', '-t'], null, true, []],
            '-bm default mode' => [['-bm', 'a@example.org'], null, false, ['a@example.org']],
            '-f with separate value' => [['-f', 'sender@example.org', '-t'], 'sender@example.org', true, []],
            '-f attached' => [['-fsender@example.org', '-t'], 'sender@example.org', true, []],
            '-f in angle brackets' => [['-f', '<sender@example.org>'], 'sender@example.org', false, []],
            '-r is an alias of -f' => [['-r', 'sender@example.org'], 'sender@example.org', false, []],
            '-r attached' => [['-rsender@example.org'], 'sender@example.org', false, []],
            '-f without value' => [['-f'], null, false, []],
            '-f empty value' => [['-f', '', 'a@example.org'], null, false, ['a@example.org']],
            '-F name is ignored' => [['-F', 'Shop Name', 'a@example.org'], null, false, ['a@example.org']],
            '-F attached is ignored' => [['-FShop', 'a@example.org'], null, false, ['a@example.org']],
            'unknown flags are ignored' => [['-v', '-U', '--unknown', '--long=value', 'a@example.org'], null, false, ['a@example.org']],
            'several positional recipients' => [['a@example.org', 'b@example.org'], null, false, ['a@example.org', 'b@example.org']],
            'comma separated recipients' => [['a@example.org, b@example.org,,c@example.org'], null, false, ['a@example.org', 'b@example.org', 'c@example.org']],
            'recipient in angle brackets' => [['<a@example.org>'], null, false, ['a@example.org']],
            'flags after recipients' => [['a@example.org', '-t', '-i'], null, true, ['a@example.org']],
            'double dash ends the options' => [['-t', '--', '-odd@example.org', 'b@example.org'], null, true, ['-odd@example.org', 'b@example.org']],
            'full php command line' => [['-t', '-i', '-f', 'sender@example.org', 'a@example.org'], 'sender@example.org', true, ['a@example.org']],
        ];
    }

    #[Test]
    public function afterCommandDropsEverythingUpToTheCommandName(): void
    {
        self::assertSame(
            ['-t', '-i'],
            SendmailArguments::afterCommand(['/abs/vendor/bin/typo3', '-q', 'letterbox:receive', '-t', '-i'], 'letterbox:receive'),
        );
    }

    #[Test]
    public function afterCommandSkipsALeadingDoubleDash(): void
    {
        $tokens = SendmailArguments::afterCommand(['vendor/bin/typo3', 'letterbox:receive', '--', '-t', '-i', '-V', 'envid', '--', '-odd@example.org'], 'letterbox:receive');

        self::assertSame(['-t', '-i', '-V', 'envid', '--', '-odd@example.org'], $tokens);
        $arguments = SendmailArguments::fromArgv($tokens);
        self::assertTrue($arguments->recipientsFromHeaders);
        self::assertSame(['-odd@example.org'], $arguments->recipients);
    }

    #[Test]
    public function afterCommandIsEmptyWithoutTheCommandName(): void
    {
        self::assertSame([], SendmailArguments::afterCommand(['vendor/bin/phpunit', '-t'], 'letterbox:receive'));
    }

    #[Test]
    public function afterCommandSkipsNonStringTokens(): void
    {
        self::assertSame(['-t'], SendmailArguments::afterCommand(['typo3', 'letterbox:receive', 5, '-t'], 'letterbox:receive'));
    }
}
