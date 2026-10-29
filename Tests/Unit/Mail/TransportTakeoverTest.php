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

use KonradMichalik\Typo3Letterbox\Mail\{LetterboxTransport, TransportTakeover};
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use PHPUnit\Framework\TestCase;

/**
 * TransportTakeoverTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class TransportTakeoverTest extends TestCase
{
    private const VAR_PATH = '/var/www/html/var';

    #[Test]
    #[DataProvider('switchStates')]
    public function environmentOverridesTheSetting(string $environment, mixed $setting, ?string $expected): void
    {
        self::assertSame($expected, TransportTakeover::source($environment, $setting));
    }

    /**
     * @return array<string, array{string, mixed, string|null}>
     */
    public static function switchStates(): array
    {
        return [
            'setting off' => ['', '0', null],
            'setting missing' => ['', null, null],
            'setting on' => ['', '1', 'setting'],
            'setting on as bool' => ['', true, 'setting'],
            'env 1 over setting off' => ['1', '0', 'env'],
            'env true' => ['true', '0', 'env'],
            'env ON in capitals' => ['ON', '0', 'env'],
            'env 0 over setting on' => ['0', '1', null],
            'env false' => ['false', '1', null],
            'env off' => ['off', '1', null],
            'unknown env value leaves the setting' => ['maybe', '1', 'setting'],
        ];
    }

    #[Test]
    public function offChangesNothing(): void
    {
        $mail = ['transport' => 'smtp', 'dsn' => 'smtp://mail.example.org'];

        self::assertSame($mail, TransportTakeover::apply($mail, false, 'setting', self::VAR_PATH));
    }

    #[Test]
    public function onTakesOverAndRemembersTheOriginal(): void
    {
        $mail = ['transport' => 'sendmail', 'dsn' => 'smtp://user:pass@mail.example.org:587', 'transport_smtp_server' => 'x', 'transport_mbox_file' => '/data/mails.mbox'];

        $taken = TransportTakeover::apply($mail, true, 'env', self::VAR_PATH);

        self::assertSame(LetterboxTransport::class, $taken['transport']);
        self::assertSame('', $taken['dsn'], 'a DSN would bypass the transport');
        self::assertSame(['by' => 'env', 'transport' => 'sendmail', 'dsn' => 'smtp://user:pass@mail.example.org:587'], $taken['transport_letterbox_takeover']);
        self::assertSame(['by' => 'env', 'transport' => 'sendmail', 'dsn' => 'smtp://user:pass@mail.example.org:587'], TransportTakeover::of($taken));
        self::assertSame('x', $taken['transport_smtp_server']);
        self::assertSame(['sendmail', 'smtp://user:pass@mail.example.org:587', 'x'], [TransportTakeover::original($taken)['transport'] ?? '', TransportTakeover::original($taken)['dsn'] ?? '', TransportTakeover::original($taken)['transport_smtp_server'] ?? '']);
    }

    #[Test]
    public function alreadyLetterboxIsLeftAlone(): void
    {
        $mail = ['transport' => '\\'.LetterboxTransport::class, 'dsn' => '', 'transport_mbox_file' => '/var/www/mbox/mails.mbox'];

        self::assertSame($mail, TransportTakeover::apply($mail, true, 'setting', self::VAR_PATH));
        self::assertNull(TransportTakeover::of($mail));
    }

    #[Test]
    public function mailboxDefaultsToTheVarPath(): void
    {
        $taken = TransportTakeover::apply(['transport' => 'smtp'], true, 'setting', self::VAR_PATH.'/');

        self::assertSame('/var/www/html/var/letterbox/mails.mbox', $taken['transport_mbox_file']);
        self::assertSame('/var/www/html/var/letterbox/mails.mbox', TransportTakeover::defaultMailbox($taken));

        $own = TransportTakeover::apply(['transport' => 'smtp', 'transport_mbox_file' => '/data/mails.mbox'], true, 'setting', self::VAR_PATH);
        self::assertSame('/data/mails.mbox', $own['transport_mbox_file']);
        self::assertSame('', TransportTakeover::defaultMailbox($own));
    }

    #[Test]
    public function originalRestoresTheTransportAndDsnBeforeTheTakeover(): void
    {
        $taken = TransportTakeover::apply(['transport' => 'sendmail', 'dsn' => 'smtp://mail.example.org'], true, 'setting', self::VAR_PATH);

        self::assertSame('', $taken['dsn']);
        self::assertSame(['transport' => 'sendmail', 'dsn' => 'smtp://mail.example.org'], array_intersect_key(TransportTakeover::original($taken) ?? [], ['transport' => 1, 'dsn' => 1]));
    }
}
