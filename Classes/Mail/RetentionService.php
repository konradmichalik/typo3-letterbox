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

namespace KonradMichalik\Typo3Letterbox\Mail;

use DateTimeImmutable;
use KonradMichalik\Typo3Letterbox\Configuration\ExtensionSettings;
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepositoryFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * RetentionService.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class RetentionService
{
    public function __construct(
        private MailRepositoryFactory $repositories,
        private ExtensionSettings $settings,
        private LoggerInterface $logger,
    ) {}

    /**
     * Applies the retention limits after a mail was appended. Call it after append() released the lock: the lock is not re-entrant.
     */
    public function afterAppend(string $mboxFile): void
    {
        try {
            $retention = RetentionSettings::fromExtensionSettings($this->settings);
            if (!$retention->isActive()) {
                return;
            }

            $repository = $this->repositories->forPath($mboxFile);
            $now = new DateTimeImmutable();
            // The count may exceed the limit by a slack, so a full file sees one rewrite per slack mails, not one per mail.
            $countLimit = $retention->maxMessages > 0 ? $retention->maxMessages + self::countSlack($retention->maxMessages) : 0;
            if ($repository->needsPruning($countLimit, $retention->maxAgeDays, $now)) {
                $repository->prune($retention->maxMessages, $retention->maxAgeDays, $now);
            }
        } catch (Throwable $e) {
            // The mail is already stored, so a failed cleanup must not fail the caller.
            $this->logger->warning('Letterbox could not apply the retention limits: {exception} ({code})', ['exception' => $e::class, 'code' => $e->getCode()]);
        }
    }

    private static function countSlack(int $maxMessages): int
    {
        return max(1, intdiv($maxMessages, 10));
    }
}
