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

use KonradMichalik\Typo3Letterbox\Domain\Model\QuickFilter;
use KonradMichalik\Typo3Letterbox\Mbox\MessageIndexEntry;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Pagination\SlidingWindowPagination;

use function is_string;

/**
 * MailListView.
 *
 * Builds the links and state of the mail list from the inbox query, so every link keeps search, filter and selection.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class MailListView
{
    private const ROUTE_INDEX = 'system_letterbox';
    private const ROUTE_SHOW = 'system_letterbox.show';
    private const ROUTE_PREVIEW = 'system_letterbox.preview';
    private const ROUTE_DELETE = 'system_letterbox.delete';
    private const ROUTE_MARK_ALL_READ = 'system_letterbox.markAllRead';
    private const ROUTE_RELEASE = 'system_letterbox.release';

    public function __construct(
        private UriBuilder $uriBuilder,
    ) {}

    /**
     * @param iterable<MessageIndexEntry>       $entries
     * @param array<value-of<QuickFilter>, int> $counts
     * @param array<string, int>                $readLookup read ids as keys
     *
     * @return array<string, mixed>
     */
    public function build(InboxQuery $query, iterable $entries, SlidingWindowPagination $pagination, array $counts, array $readLookup): array
    {
        [$rowUrls, $unread] = $this->rows($query, $entries, $readLookup);

        return [
            'query' => $query->query,
            'resultTotal' => $counts[$query->quick->value] ?? 0,
            'maxQueryLength' => InboxQuery::MAX_QUERY_LENGTH,
            'rowUrls' => $rowUrls,
            'unread' => $unread,
            'pageUrls' => $this->pageUrls($query, $pagination),
            'quickFilters' => $this->quickFilters($query, $counts),
            'resetUrl' => $this->url($query, ['q' => null, 'filter' => null, 'page' => null]),
            'search' => $this->form($query->toParameters(['q' => null, 'page' => null])),
            'deleteUrl' => $this->deleteUrl($query),
        ];
    }

    /**
     * Keeps search, filter and page like the delete route. The selected mail is left out: a detail swap in inbox.js
     * does not update this form, so its id could be stale.
     */
    public function markAllReadUrl(InboxQuery $query): string
    {
        return (string) $this->uriBuilder->buildUriFromRoute(self::ROUTE_MARK_ALL_READ, $query->toParameters(['id' => null, 'tab' => null]));
    }

    /**
     * The delete route keeps search, filter and page, so the redirect after deleting returns to the same list.
     */
    public function deleteUrl(InboxQuery $query): string
    {
        return (string) $this->uriBuilder->buildUriFromRoute(self::ROUTE_DELETE, $query->toParameters(['id' => null, 'tab' => null]));
    }

    /**
     * Keeps the whole query except the mail id, which the form posts, so the redirect returns to the same mail and tab.
     */
    public function releaseUrl(InboxQuery $query): string
    {
        return (string) $this->uriBuilder->buildUriFromRoute(self::ROUTE_RELEASE, $query->toParameters(['id' => null]));
    }

    /**
     * @param array<string, string|null> $override
     */
    public function url(InboxQuery $query, array $override = []): string
    {
        $parameters = $query->toParameters($override);

        return (string) $this->uriBuilder->buildUriFromRoute(isset($parameters['id']) ? self::ROUTE_SHOW : self::ROUTE_INDEX, $parameters);
    }

    /**
     * View and width switch forms and remote image links for the HTML tab. Remote loading is carried only to the
     * width form of the same mail, every other link built from the query drops it.
     *
     * @return array{viewForm: array{action: string, fields: array<string, string>}, widthForm: array{action: string, fields: array<string, string>}, remoteUrl: string, blockUrl: string, previewUrl: string}
     */
    public function previewControls(InboxQuery $query): array
    {
        $tab = ['tab' => 'html'];

        return [
            'viewForm' => $this->form($query->toParameters($tab + ['remote' => $query->remote ? '1' : null])),
            'widthForm' => $this->form($query->toParameters($tab + ['width' => null, 'remote' => $query->remote ? '1' : null])),
            'remoteUrl' => $this->url($query, $tab + ['remote' => '1']),
            'blockUrl' => $this->url($query, $tab),
            'previewUrl' => (string) $this->uriBuilder->buildUriFromRoute(self::ROUTE_PREVIEW, ['id' => $query->id ?? '']),
        ];
    }

    /**
     * @param iterable<MessageIndexEntry> $entries
     * @param array<string, int>          $readLookup
     *
     * @return array{array<string, string>, array<string, true>}
     */
    private function rows(InboxQuery $query, iterable $entries, array $readLookup): array
    {
        $urls = [];
        $unread = [];
        foreach ($entries as $entry) {
            $urls[$entry->id] = $this->url($query, ['id' => $entry->id, 'tab' => null]);
            if (!isset($readLookup[$entry->id])) {
                $unread[$entry->id] = true;
            }
        }

        return [$urls, $unread];
    }

    /**
     * @return array<int, string>
     */
    private function pageUrls(InboxQuery $query, SlidingWindowPagination $pagination): array
    {
        $urls = [];
        foreach ([...$pagination->getAllPageNumbers(), $pagination->getPreviousPageNumber(), $pagination->getNextPageNumber()] as $page) {
            if (null !== $page) {
                $urls[$page] = $this->url($query, ['page' => $page > 1 ? (string) $page : null]);
            }
        }

        return $urls;
    }

    /**
     * @param array<value-of<QuickFilter>, int> $counts
     *
     * @return list<array{value: string, count: int, active: bool, url: string}>
     */
    private function quickFilters(InboxQuery $query, array $counts): array
    {
        $filters = [];
        foreach (QuickFilter::cases() as $quick) {
            $filters[] = [
                'value' => $quick->value,
                'count' => $counts[$quick->value] ?? 0,
                'active' => $quick === $query->quick,
                'url' => $this->url($query, ['filter' => QuickFilter::All === $quick ? null : $quick->value, 'page' => null]),
            ];
        }

        return $filters;
    }

    /**
     * A GET form replaces the query of its action, so the route token and the kept parameters become hidden fields.
     *
     * @param array<string, string> $parameters
     *
     * @return array{action: string, fields: array<string, string>}
     */
    private function form(array $parameters): array
    {
        $uri = $this->uriBuilder->buildUriFromRoute(isset($parameters['id']) ? self::ROUTE_SHOW : self::ROUTE_INDEX, $parameters);
        parse_str($uri->getQuery(), $query);
        $fields = [];
        foreach ($query as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $fields[$name] = $value;
            }
        }

        return ['action' => $uri->getPath(), 'fields' => $fields];
    }
}
