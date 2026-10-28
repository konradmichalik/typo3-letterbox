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
 * One repository per path, so the index read once stays in memory for every later call of the request.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MailRepositoryFactory
{
    /** @var array<string, MailRepository> */
    private array $repositories = [];

    public function __construct(
        private readonly MailboxLock $lock,
        private readonly FrontendInterface $cache,
    ) {}

    public function create(): MailRepository
    {
        $configured = $GLOBALS['TYPO3_CONF_VARS']['MAIL']['transport_mbox_file'] ?? '';

        return $this->forPath(is_string($configured) ? $configured : '');
    }

    public function forPath(string $path): MailRepository
    {
        return $this->repositories[$path] ??= $this->build($path);
    }

    private function build(string $path): MailRepository
    {
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
