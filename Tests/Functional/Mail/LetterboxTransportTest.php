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

namespace KonradMichalik\Typo3Letterbox\Tests\Functional\Mail;

use KonradMichalik\Typo3Letterbox\Mail\LetterboxTransport;
use KonradMichalik\Typo3Letterbox\Tests\Functional\Mail\Fixtures\RecordingTransport;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Mime\Email;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Mail\Mailer;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function dirname;

/**
 * LetterboxTransportTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class LetterboxTransportTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3_letterbox',
    ];

    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = Environment::getVarPath().'/letterbox-test/transport.mbox';
        GeneralUtility::mkdir_deep(dirname($this->path));
        if (is_file($this->path)) {
            unlink($this->path);
        }
        RecordingTransport::reset();
    }

    #[Test]
    public function modeAllCatchesEverythingAndDeliversNothing(): void
    {
        $this->configure(['transport_letterbox_mode' => 'all', 'transport_letterbox_delivery' => RecordingTransport::class]);

        $this->send((new Email())->from('shop@example.org')->to('customer@example.org')->subject('Hello')->text('Body'));

        self::assertStringContainsString('X-Letterbox-Envelope-To: customer@example.org', $this->mbox());
        self::assertSame([], RecordingTransport::$recipients);
    }

    #[Test]
    public function modeListedCatchesBccOnlyTestAddressAndDeliversTheRest(): void
    {
        $this->configure([
            'transport_letterbox_mode' => 'listed',
            'transport_letterbox_catch' => '*@letterbox.invalid',
            'transport_letterbox_delivery' => RecordingTransport::class,
        ]);

        $this->send((new Email())->from('shop@example.org')->to('customer@example.org')->bcc('qa@letterbox.invalid')->subject('Hello')->text('Body'));

        self::assertStringContainsString('X-Letterbox-Envelope-To: qa@letterbox.invalid', $this->mbox());
        self::assertStringNotContainsString('X-Letterbox-Envelope-To: customer@example.org', $this->mbox());
        self::assertSame([['customer@example.org']], RecordingTransport::$recipients);
        self::assertCount(1, RecordingTransport::$messages);
        self::assertStringNotContainsString('qa@letterbox.invalid', RecordingTransport::$messages[0]);
        self::assertStringNotContainsString('X-Letterbox-Envelope-To', RecordingTransport::$messages[0]);
    }

    #[Test]
    public function modeOffDeliversEverything(): void
    {
        $this->configure(['transport_letterbox_mode' => 'off', 'transport_letterbox_delivery' => RecordingTransport::class]);

        $this->send((new Email())->from('shop@example.org')->to('a@example.org', 'b@example.org')->subject('Hello')->text('Body'));

        self::assertFileDoesNotExist($this->path);
        self::assertSame([['a@example.org', 'b@example.org']], RecordingTransport::$recipients);
    }

    #[Test]
    public function missingDeliveryThrowsForListed(): void
    {
        $this->configure(['transport_letterbox_mode' => 'listed', 'transport_letterbox_catch' => 'qa@letterbox.invalid']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('transport_letterbox_delivery');

        $this->send((new Email())->from('shop@example.org')->to('customer@example.org')->subject('Hello')->text('Body'));
    }

    #[Test]
    public function missingDeliveryThrowsBeforeAnythingIsWritten(): void
    {
        $this->configure(['transport_letterbox_mode' => 'listed', 'transport_letterbox_catch' => 'qa@letterbox.invalid']);

        try {
            $this->send((new Email())->from('shop@example.org')->to('customer@example.org', 'qa@letterbox.invalid')->subject('Hello')->text('Body'));
            self::fail('Expected a RuntimeException.');
        } catch (RuntimeException) {
            self::assertFileDoesNotExist($this->path);
        }
    }

    #[Test]
    public function unwritableFileThrowsWithPath(): void
    {
        $this->path = '/nonexistent-letterbox-dir/mails.mbox';
        $this->configure(['transport_letterbox_mode' => 'all']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($this->path);

        $this->send((new Email())->from('shop@example.org')->to('customer@example.org')->subject('Hello')->text('Body'));
    }

    #[Test]
    public function mailWithoutFromIsWritten(): void
    {
        $this->configure(['transport_letterbox_mode' => 'all']);

        $email = (new Email())->to('customer@example.org')->subject('No sender')->text('Body');
        $this->send($email, 'system@example.org');

        self::assertStringContainsString('From system@example.org ', $this->mbox());
    }

    /**
     * @param array<string, string> $settings
     */
    private function configure(array $settings): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = [
            ...$settings,
            'transport' => LetterboxTransport::class,
            'transport_mbox_file' => $this->path,
        ];
    }

    private function send(Email $email, ?string $envelopeSender = null): void
    {
        if (null !== $envelopeSender) {
            $email->sender($envelopeSender);
        }

        GeneralUtility::makeInstance(Mailer::class)->send($email);
    }

    private function mbox(): string
    {
        return (string) file_get_contents($this->path);
    }
}
