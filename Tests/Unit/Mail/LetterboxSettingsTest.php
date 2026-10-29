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
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Core\ApplicationContext;

/**
 * LetterboxSettingsTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class LetterboxSettingsTest extends TestCase
{
    #[Test]
    public function defaultsToListedInRootProduction(): void
    {
        $mail = ['transport_mbox_file' => '/tmp/m.mbox'];

        self::assertSame(DeliveryMode::Listed, LetterboxSettings::fromMailSettings($mail, new ApplicationContext('Production'))->mode);
        self::assertSame(DeliveryMode::All, LetterboxSettings::fromMailSettings($mail, new ApplicationContext('Development'))->mode);
        self::assertSame(DeliveryMode::All, LetterboxSettings::fromMailSettings($mail, new ApplicationContext('Testing'))->mode);
    }

    #[Test]
    public function productionSubcontextDefaultsToListed(): void
    {
        $mail = ['transport_mbox_file' => '/tmp/m.mbox'];

        self::assertSame(DeliveryMode::Listed, LetterboxSettings::fromMailSettings($mail, new ApplicationContext('Production/Staging'))->mode);
        self::assertSame(DeliveryMode::Listed, LetterboxSettings::fromMailSettings($mail, new ApplicationContext('Production/Live'))->mode);
        self::assertSame(DeliveryMode::All, LetterboxSettings::fromMailSettings($mail, new ApplicationContext('Development/Production'))->mode);
    }

    #[Test]
    public function explicitModeOverridesContextDefault(): void
    {
        $mail = ['transport_mbox_file' => '/tmp/m.mbox', 'transport_letterbox_mode' => 'off'];

        self::assertSame(DeliveryMode::Off, LetterboxSettings::fromMailSettings($mail, new ApplicationContext('Production'))->mode);
    }

    #[Test]
    public function rejectsUnknownMode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LetterboxSettings::fromMailSettings(['transport_mbox_file' => '/tmp/m.mbox', 'transport_letterbox_mode' => 'bogus'], new ApplicationContext('Development'));
    }

    #[Test]
    public function rejectsPhpMboxPath(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LetterboxSettings::fromMailSettings(['transport_mbox_file' => '/var/www/html/public/x.php'], new ApplicationContext('Development'));
    }

    #[Test]
    public function rejectsSelfAsDelivery(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LetterboxSettings::fromMailSettings([
            'transport_mbox_file' => '/tmp/m.mbox',
            'transport_letterbox_delivery' => LetterboxTransport::class,
        ], new ApplicationContext('Development'));
    }

    #[Test]
    #[DataProvider('selfReferences')]
    public function rejectsSelfAsDeliveryInAnyNotation(string $delivery): void
    {
        $this->expectException(InvalidArgumentException::class);

        LetterboxSettings::fromMailSettings([
            'transport_mbox_file' => '/tmp/m.mbox',
            'transport_letterbox_delivery' => $delivery,
        ], new ApplicationContext('Development'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function selfReferences(): array
    {
        return [
            'lowercase' => [strtolower(LetterboxTransport::class)],
            'leading backslash' => ['\\'.LetterboxTransport::class],
            'lowercase with leading backslash' => ['\\'.strtolower(LetterboxTransport::class)],
        ];
    }

    #[Test]
    public function parsesCommaSeparatedCatchList(): void
    {
        $settings = LetterboxSettings::fromMailSettings([
            'transport_mbox_file' => '/tmp/m.mbox',
            'transport_letterbox_catch' => 'qa@x.de, *@letterbox.invalid',
        ], new ApplicationContext('Development'));

        self::assertCount(2, $settings->catch);
        self::assertTrue($settings->catch[1]->matches('anyone@letterbox.invalid'));
    }

    #[Test]
    public function parsesCatchListGivenAsArray(): void
    {
        $settings = LetterboxSettings::fromMailSettings([
            'transport_mbox_file' => '/tmp/m.mbox',
            'transport_letterbox_catch' => ['qa@x.de'],
        ], new ApplicationContext('Development'));

        self::assertCount(1, $settings->catch);
    }

    #[Test]
    #[DataProvider('loopingDeliveries')]
    public function deliveryBackIntoLetterboxIsMarked(string $delivery, string $command): void
    {
        $mail = ['transport_mbox_file' => '/tmp/m.mbox', 'transport_letterbox_delivery' => $delivery, 'transport_sendmail_command' => $command];

        self::assertTrue(LetterboxSettings::fromMailSettings($mail, new ApplicationContext('Development'), '/usr/sbin/sendmail -t -i')->deliveryLoops);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function loopingDeliveries(): array
    {
        return [
            'core mbox transport' => ['mbox', ''],
            'core mbox transport, upper case' => ['MBOX', ''],
            'sendmail into letterbox:receive' => ['sendmail', 'php vendor/bin/typo3 letterbox:receive -- -t -i'],
        ];
    }

    #[Test]
    public function sendmailPathIntoReceiveIsMarked(): void
    {
        $mail = ['transport_mbox_file' => '/tmp/m.mbox', 'transport_letterbox_delivery' => 'sendmail'];

        self::assertTrue(LetterboxSettings::fromMailSettings($mail, new ApplicationContext('Development'), '/usr/bin/php vendor/bin/typo3 letterbox:receive -t -i')->deliveryLoops);
        self::assertFalse(LetterboxSettings::fromMailSettings($mail, new ApplicationContext('Development'), '/usr/sbin/sendmail -t -i')->deliveryLoops);
    }

    #[Test]
    public function realDeliveriesAreNotMarked(): void
    {
        foreach (['', 'smtp', 'sendmail'] as $delivery) {
            $mail = ['transport_mbox_file' => '/tmp/m.mbox', 'transport_letterbox_delivery' => $delivery, 'transport_smtp_server' => 'localhost:1025'];

            self::assertFalse(LetterboxSettings::fromMailSettings($mail, new ApplicationContext('Development'), '/usr/sbin/sendmail -t -i')->deliveryLoops, $delivery);
        }
    }

    #[Test]
    public function knowsWhenTheMailboxIsInsideThePublicPath(): void
    {
        $settings = static fn (string $file): LetterboxSettings => LetterboxSettings::fromMailSettings(['transport_mbox_file' => $file], new ApplicationContext('Development'));

        self::assertTrue($settings('/srv/site/public/mails.mbox')->isMailboxPublic('/srv/site/public', '/srv/site/var'));
        self::assertTrue($settings('/srv/site/public/fileadmin/../mails.mbox')->isMailboxPublic('/srv/site/public/', '/srv/site/var'));
        self::assertTrue($settings('/srv/site/public/a/b/mails.mbox')->isMailboxPublic('/srv/site/public', '/srv/site/var'));
        self::assertFalse($settings('/srv/site/var/mails.mbox')->isMailboxPublic('/srv/site/public', '/srv/site/var'));
        self::assertFalse($settings('/srv/site/public-old/mails.mbox')->isMailboxPublic('/srv/site/public', '/srv/site/var'));
        self::assertFalse($settings('/srv/site/public/../mails.mbox')->isMailboxPublic('/srv/site/public', '/srv/site/var'));
        self::assertFalse($settings('/srv/site/typo3temp/var/mails.mbox')->isMailboxPublic('/srv/site', '/srv/site/typo3temp/var'), 'classic mode: the var path below the web root is protected by TYPO3');
        self::assertTrue($settings('/srv/site/mails.mbox')->isMailboxPublic('/srv/site', '/srv/site/typo3temp/var'));
    }
}
