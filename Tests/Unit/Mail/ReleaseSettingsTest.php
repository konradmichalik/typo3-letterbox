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

use KonradMichalik\Typo3Letterbox\Mail\{LetterboxTransport, ReleaseSettings};
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use PHPUnit\Framework\TestCase;

/**
 * ReleaseSettingsTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class ReleaseSettingsTest extends TestCase
{
    #[Test]
    public function releaseIsOffWithoutAllowlist(): void
    {
        $settings = ReleaseSettings::fromMailSettings(['transport_letterbox_delivery' => 'smtp'], '');

        self::assertFalse($settings->isEnabled());
        self::assertSame('release_off', $settings->refusal());
        self::assertFalse($settings->allows('anna@example.org'));
    }

    #[Test]
    public function blankAllowlistKeepsReleaseOff(): void
    {
        $settings = ReleaseSettings::fromMailSettings(['transport_letterbox_release' => ' , ', 'transport_letterbox_delivery' => 'smtp'], '');

        self::assertSame('release_off', $settings->refusal());
    }

    #[Test]
    public function invalidAllowlistEntrySwitchesReleaseOff(): void
    {
        $settings = ReleaseSettings::fromMailSettings(['transport_letterbox_release' => ['*@example.org', 'not an address'], 'transport_letterbox_delivery' => 'smtp'], '');

        self::assertSame('release_invalid', $settings->refusal());
        self::assertSame([], $settings->allowlist);
        self::assertFalse($settings->allows('anna@example.org'));
    }

    #[Test]
    public function releaseNeedsADelivery(): void
    {
        $settings = ReleaseSettings::fromMailSettings(['transport_letterbox_release' => '*@example.org'], '');

        self::assertSame('delivery_missing', $settings->refusal());
        self::assertFalse($settings->isEnabled());
    }

    #[Test]
    public function smtpDeliveryWithAllowlistEnablesRelease(): void
    {
        $settings = ReleaseSettings::fromMailSettings(['transport_letterbox_release' => 'qa@example.com, *@example.org', 'transport_letterbox_delivery' => 'smtp'], '');

        self::assertTrue($settings->isEnabled());
        self::assertNull($settings->refusal());
        self::assertCount(2, $settings->allowlist);
        self::assertTrue($settings->allows('Anna@Example.org'));
        self::assertTrue($settings->allows('qa@example.com'));
        self::assertFalse($settings->allows('anna@example.com'));
    }

    #[Test]
    #[DataProvider('letterboxDeliveries')]
    public function letterboxDeliveryIsRefused(string $delivery): void
    {
        $settings = ReleaseSettings::fromMailSettings(['transport_letterbox_release' => '*@example.org', 'transport_letterbox_delivery' => $delivery], '');

        self::assertSame('delivery_loops', $settings->refusal());
        self::assertFalse($settings->isEnabled());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function letterboxDeliveries(): array
    {
        return [
            'class name' => [LetterboxTransport::class],
            'lowercase with leading backslash' => ['\\'.strtolower(LetterboxTransport::class)],
            // Core's mbox transport writes into transport_mbox_file, which is the Letterbox mailbox itself.
            'core mbox transport' => ['mbox'],
        ];
    }

    #[Test]
    public function sendmailLoopIsRefused(): void
    {
        $mail = ['transport_letterbox_release' => '*@example.org', 'transport_letterbox_delivery' => 'sendmail'];

        self::assertSame('delivery_loops', ReleaseSettings::fromMailSettings($mail, '/usr/bin/php /var/www/html/vendor/bin/typo3 letterbox:receive -t -i')->refusal());
        self::assertNull(ReleaseSettings::fromMailSettings($mail, '/usr/sbin/sendmail -t -i')->refusal());
    }

    #[Test]
    public function sendmailCommandSettingWinsOverSendmailPath(): void
    {
        $mail = [
            'transport_letterbox_release' => '*@example.org',
            'transport_letterbox_delivery' => 'sendmail',
            'transport_sendmail_command' => 'php vendor/bin/typo3 letterbox:receive -t',
        ];

        self::assertSame('delivery_loops', ReleaseSettings::fromMailSettings($mail, '/usr/sbin/sendmail -t -i')->refusal());
    }

    #[Test]
    #[DataProvider('loopingDsns')]
    public function dsnLeadingIntoLetterboxIsRefused(string $dsn): void
    {
        $settings = ReleaseSettings::fromMailSettings([
            'transport_letterbox_release' => '*@example.org',
            'transport_letterbox_delivery' => 'dsn',
            'dsn' => $dsn,
        ], 'php vendor/bin/typo3 letterbox:receive -t -i');

        self::assertSame('delivery_loops', $settings->refusal());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function loopingDsns(): array
    {
        return [
            'sendmail command' => ['sendmail://default?command=php%20vendor/bin/typo3%20letterbox%3Areceive%20-t'],
            'native uses sendmail_path' => ['native://default'],
            'native inside failover' => ['failover(smtp://localhost:25 native://default)'],
        ];
    }

    #[Test]
    public function smtpDsnIsAllowed(): void
    {
        $settings = ReleaseSettings::fromMailSettings([
            'transport_letterbox_release' => '*@example.org',
            'transport_letterbox_delivery' => 'dsn',
            'dsn' => 'smtp://localhost:1025',
        ], 'php vendor/bin/typo3 letterbox:receive -t -i');

        self::assertNull($settings->refusal());
    }
}
