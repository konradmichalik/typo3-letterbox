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

namespace KonradMichalik\Typo3Letterbox\Domain\Repository;

use KonradMichalik\Typo3Letterbox\Mbox\{MailIndex, Mailbox, MailboxLock, MailboxWriter, MboxFormat, MessageParser};
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

use function is_string;

/**
 * MailRepositoryFactory.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MailRepositoryFactory
{
    public function __construct(
        private MailboxLock $lock,
        private FrontendInterface $cache,
    ) {}

    public function create(): MailRepository
    {
        $configured = $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_mbox_file'] ?? '';
        $path = is_string($configured) ? $configured : '';

        $format = new MboxFormat();
        $mailbox = new Mailbox($path, $format);
        $parser = new MessageParser();

        return new MailRepository(
            new MailIndex($mailbox, $parser, $this->cache),
            $mailbox,
            $parser,
            new MailboxWriter($path, $format, $this->lock),
        );
    }
}
