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

namespace KonradMichalik\Typo3Letterbox\Mbox;

use TYPO3\CMS\Core\Locking\LockFactory;

/**
 * MailboxLock.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MailboxLock
{
    public function __construct(
        private LockFactory $lockFactory,
    ) {}

    /**
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T
     */
    public function run(callable $fn): mixed
    {
        $locker = $this->lockFactory->createLocker('mbox');
        $locker->acquire();

        try {
            return $fn();
        } finally {
            $locker->release();
        }
    }
}
