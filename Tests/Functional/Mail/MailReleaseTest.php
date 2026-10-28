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
use InvalidArgumentException;
use KonradMichalik\Typo3Letterbox\Domain\Model\{Mail, MailFilter};
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepositoryFactory;
use KonradMichalik\Typo3Letterbox\Mail\{LetterboxTransport, MailRelease};
use KonradMichalik\Typo3Letterbox\Mbox\{MailboxLock, MailboxWriter, MboxFormat};
use KonradMichalik\Typo3Letterbox\Tests\Functional\Mail\Fixtures\RecordingTransport;
use PHPUnit\Framework\Attributes\{DataProvider, Test};
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stringable;
use Symfony\Component\Mime\Email;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Mail\{Mailer, TransportFactory};
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function dirname;

/**
 * MailReleaseTest.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MailReleaseTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = [
        'typo3_letterbox',
    ];

    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = Environment::getVarPath().'/letterbox-test/release.mbox';
        GeneralUtility::mkdir_deep(dirname($this->path));
        if (is_file($this->path)) {
            unlink($this->path);
        }
        RecordingTransport::reset();
        $this->get(CacheManager::class)->getCache('typo3_letterbox_index')->flush();
        $this->configure([]);
    }

    #[Test]
    public function releasedMessageHasNoEnvelopeHeaderAndCrlf(): void
    {
        $mail = $this->catch((new Email())->from('shop@example.org')->to('anna@example.org')->subject('Hello')->text("Line one\nLine two"));

        $this->release()->release($mail, 'anna@example.org', 1);

        self::assertCount(1, RecordingTransport::$messages);
        $sent = RecordingTransport::$messages[0];
        self::assertStringNotContainsString('X-Letterbox-Envelope-To', $sent);
        self::assertSame(0, preg_match('/(?<!\r)\n/', $sent), 'Every line ends with CRLF.');
        self::assertSame((new MboxFormat())->toCrlf(substr($mail->source, (int) strpos($mail->source, "\n") + 1)), $sent);
    }

    #[Test]
    public function envelopeUsesFromAndChosenRecipientOnly(): void
    {
        $mail = $this->catch((new Email())->from('Shop <shop@example.org>')->to('anna@example.org', 'ben@example.org')->cc('carl@example.org')->bcc('dora@example.org')->subject('Hello')->text('Body'));

        $this->release()->release($mail, 'ben@example.org', 1);

        self::assertSame([['ben@example.org']], RecordingTransport::$recipients);
        self::assertSame(['shop@example.org'], RecordingTransport::$senders);
        self::assertStringNotContainsString('dora@example.org', RecordingTransport::$messages[0]);
    }

    #[Test]
    public function envelopePrefersDefaultFromAddressOverTheMailsFrom(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'] = 'noreply@example.org';
        $mail = $this->catch((new Email())->from('visitor@other.test')->to('anna@example.org')->subject('Contact form')->text('Body'));

        $this->release()->release($mail, 'anna@example.org', 1);

        self::assertSame(['noreply@example.org'], RecordingTransport::$senders, 'bounces must not go to the visitor of a contact form');
        self::assertStringContainsString('From: visitor@other.test', RecordingTransport::$messages[0]);
    }

    #[Test]
    public function envelopeFallsBackToDefaultFromAddress(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'] = 'noreply@example.org';
        $mail = $this->storeRaw("To: anna@example.org\nSubject: No sender\n\nBody\n");

        $this->release()->release($mail, 'anna@example.org', 1);

        self::assertSame(['noreply@example.org'], RecordingTransport::$senders);
    }

    #[Test]
    public function mailWithoutAnySenderIsRefused(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['defaultMailFromAddress'] = '';
        $mail = $this->storeRaw("To: anna@example.org\nSubject: No sender\n\nBody\n");

        self::assertSame('sender_missing', $this->release()->refusal($mail));
        try {
            $this->release()->release($mail, 'anna@example.org', 1);
            self::fail('Expected a RuntimeException.');
        } catch (RuntimeException) {
        }
        self::assertSame([], RecordingTransport::$messages);
    }

    #[Test]
    public function addressOutsideAllowlistIsRefused(): void
    {
        $mail = $this->catch((new Email())->from('shop@example.org')->to('anna@example.org')->subject('Hello')->text('Body'));

        try {
            $this->release()->release($mail, 'anna@example.com', 1);
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException) {
        }
        self::assertSame([], RecordingTransport::$messages);
    }

    #[Test]
    #[DataProvider('injectedAddresses')]
    public function headerInjectionIsRefused(string $recipient): void
    {
        $mail = $this->catch((new Email())->from('shop@example.org')->to('anna@example.org')->subject('Hello')->text('Body'));

        try {
            $this->release()->release($mail, $recipient, 1);
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException) {
        }
        self::assertSame([], RecordingTransport::$messages);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function injectedAddresses(): array
    {
        return [
            'CRLF with Bcc header' => ["anna@example.org\r\nBcc: eve@evil.test"],
            'trailing LF' => ["anna@example.org\n"],
            'bare CR' => ["anna@example.org\rX: y"],
            'comma list' => ['anna@example.org, ben@example.org'],
            'semicolon list' => ['anna@example.org;ben@example.org'],
            'name and address' => ['Anna <anna@example.org>'],
            'NUL byte' => ["anna@example.org\0"],
            'empty' => [''],
            'no at sign' => ['example.org'],
        ];
    }

    #[Test]
    public function refusedSettingsSendNothing(): void
    {
        $mail = $this->catch((new Email())->from('shop@example.org')->to('anna@example.org')->subject('Hello')->text('Body'));
        $this->configure(['transport_letterbox_delivery' => 'mbox']);

        self::assertSame('delivery_loops', $this->release()->refusal($mail));
        $this->expectException(RuntimeException::class);

        $this->release()->release($mail, 'anna@example.org', 1);
    }

    #[Test]
    public function spoolSettingDoesNotQueueTheRelease(): void
    {
        $mail = $this->catch((new Email())->from('shop@example.org')->to('anna@example.org')->subject('Hello')->text('Body'));
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_spool_type'] = 'memory';

        $this->release()->release($mail, 'anna@example.org', 1);

        self::assertSame([['anna@example.org']], RecordingTransport::$recipients);
    }

    #[Test]
    public function failingDeliveryThrowsRuntimeException(): void
    {
        $mail = $this->catch((new Email())->from('shop@example.org')->to('anna@example.org')->subject('Hello')->text('Body'));
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_letterbox_delivery'] = 'smtp';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_server'] = '127.0.0.1:1';

        $this->expectException(RuntimeException::class);

        $this->release()->release($mail, 'anna@example.org', 1);
    }

    #[Test]
    public function releaseIsLoggedWithDomainOnly(): void
    {
        $mail = $this->catch((new Email())->from('shop@example.org')->to('anna@example.org')->subject('Secret subject')->text('Body'));
        $logger = $this->recordingLogger();

        (new MailRelease($this->get(TransportFactory::class), new MboxFormat(), $logger))->release($mail, 'anna@example.org', 7);

        self::assertSame(['info Released mail '.$mail->id.' to a recipient at example.org by backend user 7'], $logger->lines);
    }

    #[Test]
    public function failedReleaseIsLoggedWithoutContent(): void
    {
        $mail = $this->catch((new Email())->from('shop@example.org')->to('anna@example.org')->subject('Secret subject')->text('Secret body'));
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_letterbox_delivery'] = 'smtp';
        $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_smtp_server'] = '127.0.0.1:1';
        $logger = $this->recordingLogger();

        try {
            (new MailRelease($this->get(TransportFactory::class), new MboxFormat(), $logger))->release($mail, 'anna@example.org', 7);
            self::fail('Expected a RuntimeException.');
        } catch (RuntimeException $exception) {
            self::assertSame(1761900001, $exception->getCode());
        }

        self::assertCount(1, $logger->lines);
        self::assertMatchesRegularExpression('/^warning Release of mail '.$mail->id.' to a recipient at example\.org failed: Symfony\\\\[\w\\\\]+Exception$/', $logger->lines[0]);
    }

    #[Test]
    public function storedCopyStaysInMailbox(): void
    {
        $mail = $this->catch((new Email())->from('shop@example.org')->to('anna@example.org')->subject('Hello')->text('Body'));
        $before = (string) file_get_contents($this->path);

        $this->release()->release($mail, 'anna@example.org', 1);

        self::assertSame($before, file_get_contents($this->path));
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function configure(array $overrides): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = [
            'transport' => LetterboxTransport::class,
            'transport_mbox_file' => $this->path,
            'transport_letterbox_mode' => 'all',
            'transport_letterbox_delivery' => RecordingTransport::class,
            'transport_letterbox_release' => '*@example.org',
            ...$overrides,
        ];
    }

    private function catch(Email $email): Mail
    {
        GeneralUtility::makeInstance(Mailer::class)->send($email);

        return $this->latest();
    }

    private function storeRaw(string $raw): Mail
    {
        (new MailboxWriter($this->path, new MboxFormat(), new MailboxLock($this->get(LockFactory::class))))
            ->append('MAILER-DAEMON', new DateTimeImmutable('2026-10-27 10:00:00'), "X-Letterbox-Envelope-To: anna@example.org\n".$raw);

        return $this->latest();
    }

    private function latest(): Mail
    {
        $mail = $this->get(MailRepositoryFactory::class)->forPath($this->path)->latest(MailFilter::all());
        self::assertNotNull($mail);

        return $mail;
    }

    /**
     * @return AbstractLogger&object{lines: list<string>}
     */
    private function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<string> */
            public array $lines = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $replacements = [];
                foreach ($context as $key => $value) {
                    $replacements['{'.$key.'}'] = (string) $value;
                }
                $this->lines[] = $level.' '.strtr((string) $message, $replacements);
            }
        };
    }

    private function release(): MailRelease
    {
        return $this->get(MailRelease::class);
    }
}
