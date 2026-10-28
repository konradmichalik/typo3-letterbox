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

use DateTimeImmutable;
use KonradMichalik\Typo3Letterbox\Domain\Model\MailFilter;
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use KonradMichalik\Typo3Letterbox\Mail\LetterboxTransport;
use KonradMichalik\Typo3Letterbox\Mbox\{MailboxLock, MailboxWriter, MboxFormat};
use KonradMichalik\Typo3Letterbox\Tests\Functional\Fixtures\RecordingLogger;
use KonradMichalik\Typo3Letterbox\Tests\Functional\Mail\Fixtures\RecordingTransport;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\Mime\Email;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Locking\LockFactory;
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
        $this->get(CacheManager::class)->getCache('typo3_letterbox_index')->flush();
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
    public function loopingDeliveryThrowsForListedAndOffBeforeAnythingIsWritten(): void
    {
        $deliveries = [
            ['transport_letterbox_delivery' => 'mbox'],
            ['transport_letterbox_delivery' => 'sendmail', 'transport_sendmail_command' => 'php vendor/bin/typo3 letterbox:receive -- -t -i'],
        ];
        foreach (['listed', 'off'] as $mode) {
            foreach ($deliveries as $delivery) {
                $this->configure([...$delivery, 'transport_letterbox_mode' => $mode, 'transport_letterbox_catch' => 'qa@letterbox.invalid']);
                try {
                    $this->send((new Email())->from('shop@example.org')->to('customer@example.org', 'qa@letterbox.invalid')->subject('Hello')->text('Body'));
                    self::fail('Expected a RuntimeException for '.$mode.' and '.$delivery['transport_letterbox_delivery']);
                } catch (RuntimeException $exception) {
                    self::assertStringContainsString('hands mails back to Letterbox', $exception->getMessage());
                    self::assertFileDoesNotExist($this->path);
                }
            }
        }
    }

    #[Test]
    public function loopingDeliveryIsUnusedInModeAll(): void
    {
        $this->configure(['transport_letterbox_mode' => 'all', 'transport_letterbox_delivery' => 'mbox']);

        $this->send((new Email())->from('shop@example.org')->to('customer@example.org')->subject('Caught anyway')->text('Body'));

        self::assertSame(['Caught anyway'], $this->subjects());
    }

    #[Test]
    public function mailboxInsideThePublicPathIsCaughtWithAWarning(): void
    {
        $public = Environment::getPublicPath().'/letterbox-transport-test.mbox';
        $logger = new RecordingLogger();
        $transport = new LetterboxTransport(['transport_mbox_file' => $public, 'transport_letterbox_mode' => 'all'], null, $logger);

        try {
            $transport->send((new Email())->from('shop@example.org')->to('customer@example.org')->subject('Public')->text('Body'));
            self::assertFileExists($public);
        } finally {
            if (is_file($public)) {
                unlink($public);
            }
        }

        self::assertContains('warning The Letterbox mailbox is inside the public web path, everyone can download it. Move MAIL/transport_mbox_file out of the web root.', $logger->lines);
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

    #[Test]
    public function transportPrunesAfterAppendWhenThresholdCrossed(): void
    {
        $this->configure(['transport_letterbox_mode' => 'all']);
        $this->retention(2, 30);
        $this->appendReceived('Expired', new DateTimeImmutable('-31 days'));

        $this->send((new Email())->from('shop@example.org')->to('customer@example.org')->subject('One')->text('Body'));
        self::assertSame(['One'], $this->subjects());
    }

    #[Test]
    public function transportPrunesOnlyAfterTheSlackIsExceeded(): void
    {
        $this->configure(['transport_letterbox_mode' => 'all']);
        // A limit of 2 has a slack of 1: three mails stay, the fourth cuts back to two.
        $this->retention(2, 0);

        $this->send((new Email())->from('shop@example.org')->to('customer@example.org')->subject('One')->text('Body'));
        $this->send((new Email())->from('shop@example.org')->to('customer@example.org')->subject('Two')->text('Body'));
        $this->send((new Email())->from('shop@example.org')->to('customer@example.org')->subject('Three')->text('Body'));
        self::assertSame(['Three', 'Two', 'One'], $this->subjects());

        $this->send((new Email())->from('shop@example.org')->to('customer@example.org')->subject('Four')->text('Body'));
        self::assertSame(['Four', 'Three'], $this->subjects());
    }

    #[Test]
    public function transportCutsBackToMaxMessages(): void
    {
        $this->configure(['transport_letterbox_mode' => 'all']);
        // A limit of 20 has a slack of 2: the 23rd mail triggers the cut.
        $this->retention(20, 0);
        for ($i = 1; $i <= 22; ++$i) {
            $this->appendReceived('Old '.$i, new DateTimeImmutable());
        }

        $this->send((new Email())->from('shop@example.org')->to('customer@example.org')->subject('New')->text('Body'));

        $subjects = $this->subjects();
        self::assertCount(20, $subjects);
        self::assertSame('New', $subjects[0]);
        self::assertNotContains('Old 3', $subjects);
        self::assertContains('Old 4', $subjects);
    }

    #[Test]
    public function transportDoesNotPruneBelowThresholds(): void
    {
        $this->configure(['transport_letterbox_mode' => 'all']);
        $this->retention(3, 30);
        $this->appendReceived('Recent', new DateTimeImmutable('-29 days'));
        $inode = fileinode($this->path);

        $this->send((new Email())->from('shop@example.org')->to('customer@example.org')->subject('One')->text('Body'));
        $this->send((new Email())->from('shop@example.org')->to('customer@example.org')->subject('Two')->text('Body'));

        self::assertSame(['Two', 'One', 'Recent'], $this->subjects());
        clearstatcache(true, $this->path);
        self::assertSame($inode, fileinode($this->path));
    }

    #[Test]
    public function transportDoesNotPruneWhenNothingWasCaught(): void
    {
        $this->configure(['transport_letterbox_mode' => 'off', 'transport_letterbox_delivery' => RecordingTransport::class]);
        $this->retention(1, 30);
        $this->appendReceived('Expired', new DateTimeImmutable('-31 days'));
        $this->appendReceived('Recent', new DateTimeImmutable());

        $this->send((new Email())->from('shop@example.org')->to('customer@example.org')->subject('Delivered')->text('Body'));

        self::assertSame(['Recent', 'Expired'], $this->subjects());
    }

    #[Test]
    public function failedPruneDoesNotFailTheSend(): void
    {
        $this->configure(['transport_letterbox_mode' => 'all']);
        $this->retention(1, 0);
        $this->appendReceived('Old', new DateTimeImmutable());
        // Appending to the file still works, the rewrite through a temporary file next to it does not.
        chmod(dirname($this->path), 0o555);

        try {
            $this->send((new Email())->from('shop@example.org')->to('customer@example.org')->subject('New')->text('Body'));
        } finally {
            chmod(dirname($this->path), 0o775);
        }

        self::assertSame(['New', 'Old'], $this->subjects());
    }

    private function retention(int $maxMessages, int $maxAgeDays): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_letterbox']['maxMessages'] = (string) $maxMessages;
        $GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['typo3_letterbox']['maxAgeDays'] = (string) $maxAgeDays;
    }

    private function appendReceived(string $subject, DateTimeImmutable $received): void
    {
        (new MailboxWriter($this->path, new MboxFormat(), new MailboxLock($this->get(LockFactory::class))))
            ->append('s@example.org', $received, "From: s@example.org\nTo: r@example.org\nSubject: {$subject}\n\nbody\n");
    }

    /**
     * @return list<string> newest first
     */
    private function subjects(): array
    {
        return array_map(
            static fn ($entry): string => $entry->subject,
            $this->get(MailRepository::class)->findEntries(MailFilter::all(), 0, 100),
        );
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
