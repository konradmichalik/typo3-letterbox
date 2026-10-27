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

namespace KonradMichalik\Typo3Letterbox\Pagination;

use KonradMichalik\Typo3Letterbox\Domain\Model\{Mail, MailFilter};
use KonradMichalik\Typo3Letterbox\Domain\Repository\MailRepository;
use TYPO3\CMS\Core\Pagination\AbstractPaginator;

use function count;

/**
 * MailPaginator.
 *
 * Loads only the mails of the current page from the repository.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final class MailPaginator extends AbstractPaginator
{
    private readonly int $total;

    /**
     * @var list<Mail>
     */
    private array $paginatedItems = [];

    public function __construct(
        private readonly MailRepository $repository,
        private readonly MailFilter $filter,
        int $currentPageNumber = 1,
        int $itemsPerPage = 10,
    ) {
        $this->total = $repository->count($filter);
        $this->setCurrentPageNumber($currentPageNumber);
        $this->setItemsPerPage($itemsPerPage);

        $this->updateInternalState();
    }

    /**
     * @return list<Mail>
     */
    public function getPaginatedItems(): iterable
    {
        return $this->paginatedItems;
    }

    public function getTotalNumberOfItems(): int
    {
        return $this->total;
    }

    protected function updatePaginatedItems(int $itemsPerPage, int $offset): void
    {
        $this->paginatedItems = $this->repository->find($this->filter, $offset, $itemsPerPage);
    }

    protected function getTotalAmountOfItems(): int
    {
        return $this->total;
    }

    protected function getAmountOfItemsOnCurrentPage(): int
    {
        return count($this->paginatedItems);
    }
}
