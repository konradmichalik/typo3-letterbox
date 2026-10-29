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

namespace KonradMichalik\Typo3Letterbox\Tests\Functional\Command;

use DateTimeImmutable;
use KonradMichalik\Typo3Letterbox\Domain\Model\MailFilter;
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use KonradMichalik\Typo3Letterbox\Mail\LetterboxTransport;
use KonradMichalik\Typo3Letterbox\Mbox\{MailboxLock, MailboxWriter, MboxFormat};
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

use function dirname;

/**
 * MailboxCommandTestCase.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
abstract class MailboxCommandTestCase extends FunctionalTestCase
{
    protected const UNKNOWN_ID = '0123456789abcdef0123456789abcdef';

    protected array $testExtensionsToLoad = [
        'typo3_letterbox',
    ];

    protected string $path;

    final protected function setUp(): void
    {
        parent::setUp();

        $this->path = Environment::getVarPath().'/letterbox-test/commands.mbox';
        GeneralUtility::mkdir_deep(dirname($this->path));
        if (is_file($this->path)) {
            unlink($this->path);
        }
        $this->get(CacheManager::class)->getCache('typo3_letterbox_index')->flush();
        $this->useMailbox($this->path);
    }

    final protected function useMailbox(string $path): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['MAIL'] = [
            'transport' => LetterboxTransport::class,
            'transport_mbox_file' => $path,
        ];
    }

    final protected function addMail(string $subject, string $to = 'r@example.org', string $received = '2026-10-27 10:00:00', string $text = 'body'): void
    {
        $writer = new MailboxWriter($this->path, new MboxFormat(), new MailboxLock($this->get(LockFactory::class)));
        $raw = "From: s@example.org\nTo: {$to}\nSubject: {$subject}\nMessage-ID: <".bin2hex(random_bytes(4))."@example.org>\n\n{$text}\n";
        $writer->append('s@example.org', new DateTimeImmutable($received), $raw);
    }

    final protected function mailCount(): int
    {
        return $this->get(MailRepository::class)->count(MailFilter::all());
    }

    final protected function firstId(): string
    {
        return $this->get(MailRepository::class)->findEntries(MailFilter::all(), 0, 1)[0]->id;
    }
}
