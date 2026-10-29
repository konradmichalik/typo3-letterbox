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

namespace KonradMichalik\Typo3Letterbox\Backend;

use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

/**
 * FlashToasts.
 *
 * Takes the flash messages of the last redirect out of the queue, so the module template does not render them inline
 * above the module, and names each one by the notification method of EXT:backend that inbox.js shows it with.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class FlashToasts
{
    public function __construct(
        private FlashMessageService $flashMessageService,
    ) {}

    /**
     * @return list<array{severity: string, title: string}>
     */
    public function take(): array
    {
        $toasts = [];
        foreach ($this->flashMessageService->getMessageQueueByIdentifier()->getAllMessagesAndFlush() as $message) {
            $toasts[] = ['severity' => self::notification($message->getSeverity()), 'title' => $message->getMessage()];
        }

        return $toasts;
    }

    private static function notification(ContextualFeedbackSeverity $severity): string
    {
        return match ($severity) {
            ContextualFeedbackSeverity::NOTICE => 'notice',
            ContextualFeedbackSeverity::INFO => 'info',
            ContextualFeedbackSeverity::OK => 'success',
            ContextualFeedbackSeverity::WARNING => 'warning',
            ContextualFeedbackSeverity::ERROR => 'error',
        };
    }
}
