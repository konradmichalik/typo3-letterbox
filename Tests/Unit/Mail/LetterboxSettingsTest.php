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
use KonradMichalik\Typo3Letterbox\Mail\LetterboxSettings;
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
    public function rejectsPhpMboxPath(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LetterboxSettings::fromMailSettings(['transport_mbox_file' => '/var/www/html/public/x.php']);
    }

    #[Test]
    public function knowsWhenTheMailboxIsInsideThePublicPath(): void
    {
        $settings = static fn (string $file): LetterboxSettings => LetterboxSettings::fromMailSettings(['transport_mbox_file' => $file]);

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
