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
use KonradMichalik\Typo3Letterbox\Mail\{DeliveryMode, LetterboxSettings, LetterboxTransport};
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * LetterboxSettingsTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class LetterboxSettingsTest extends TestCase
{
    #[Test]
    public function defaultsToListedOnlyInRootProduction(): void
    {
        $mail = ['transport_mbox_file' => '/tmp/m.mbox'];

        self::assertSame(DeliveryMode::Listed, LetterboxSettings::fromMailSettings($mail, 'Production')->mode);
        self::assertSame(DeliveryMode::All, LetterboxSettings::fromMailSettings($mail, 'Production/Staging')->mode);
        self::assertSame(DeliveryMode::All, LetterboxSettings::fromMailSettings($mail, 'Development')->mode);
    }

    #[Test]
    public function explicitModeOverridesContextDefault(): void
    {
        $mail = ['transport_mbox_file' => '/tmp/m.mbox', 'transport_letterbox_mode' => 'off'];

        self::assertSame(DeliveryMode::Off, LetterboxSettings::fromMailSettings($mail, 'Production')->mode);
    }

    #[Test]
    public function rejectsUnknownMode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LetterboxSettings::fromMailSettings(['transport_mbox_file' => '/tmp/m.mbox', 'transport_letterbox_mode' => 'bogus'], 'Development');
    }

    #[Test]
    public function rejectsPhpMboxPath(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LetterboxSettings::fromMailSettings(['transport_mbox_file' => '/var/www/html/public/x.php'], 'Development');
    }

    #[Test]
    public function rejectsSelfAsDelivery(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LetterboxSettings::fromMailSettings([
            'transport_mbox_file' => '/tmp/m.mbox',
            'transport_letterbox_delivery' => LetterboxTransport::class,
        ], 'Development');
    }

    #[Test]
    public function parsesCommaSeparatedCatchList(): void
    {
        $settings = LetterboxSettings::fromMailSettings([
            'transport_mbox_file' => '/tmp/m.mbox',
            'transport_letterbox_catch' => 'qa@x.de, *@letterbox.invalid',
        ], 'Development');

        self::assertCount(2, $settings->catch);
        self::assertTrue($settings->catch[1]->matches('anyone@letterbox.invalid'));
    }

    #[Test]
    public function parsesCatchListGivenAsArray(): void
    {
        $settings = LetterboxSettings::fromMailSettings([
            'transport_mbox_file' => '/tmp/m.mbox',
            'transport_letterbox_catch' => ['qa@x.de'],
        ], 'Development');

        self::assertCount(1, $settings->catch);
    }
}
