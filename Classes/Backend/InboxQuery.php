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
use Psr\Http\Message\ServerRequestInterface;

use function in_array;
use function is_string;

/**
 * InboxQuery.
 *
 * The validated inbox state from the request. Every list, pagination, filter and detail link is built from
 * toParameters(), so search, filter and selection survive navigation. Unknown values fall back to the default.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class InboxQuery
{
    public const MAX_QUERY_LENGTH = 200;
    private const MAIL_ID_PATTERN = '/^[0-9a-f]{32}$/D';
    private const TABS = ['html', 'text', 'headers', 'source', 'links', 'checks', 'attachments'];
    private const WIDTHS = ['desktop', 'tablet', 'mobile'];
    private const DEFAULT_WIDTH = 'desktop';
    private const VIEWS = ['preview', 'code'];
    private const DEFAULT_VIEW = 'preview';

    private function __construct(
        public string $query,
        public QuickFilter $quick,
        public int $page,
        public ?string $id,
        public ?string $tab,
        public string $width,
        public string $view,
        public bool $remote,
    ) {}

    public static function fromRequest(ServerRequestInterface $request): self
    {
        $params = $request->getQueryParams();
        $value = static fn (string $name): ?string => is_string($params[$name] ?? null) ? $params[$name] : null;

        return new self(
            mb_substr(trim($value('q') ?? ''), 0, self::MAX_QUERY_LENGTH),
            QuickFilter::fromRequest($value('filter')),
            self::matching($value('page'), '/^[1-9]\d{0,8}$/D') ? (int) $value('page') : 1,
            self::isMailId($value('id')) ? $value('id') : null,
            in_array($value('tab'), self::TABS, true) ? $value('tab') : null,
            in_array($value('width'), self::WIDTHS, true) ? $value('width') : self::DEFAULT_WIDTH,
            in_array($value('view'), self::VIEWS, true) ? $value('view') : self::DEFAULT_VIEW,
            '1' === $value('remote'),
        );
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    public static function isMailId(mixed $value): bool
    {
        return is_string($value) && 1 === preg_match(self::MAIL_ID_PATTERN, $value);
    }

    /**
     * Only non-default values are emitted. "remote" and "view" are never carried over, they apply to one mail view only.
     *
     * @param array<string, string|null> $override null removes a parameter
     *
     * @return array<string, string>
     */
    public function toParameters(array $override = []): array
    {
        $parameters = array_filter([
            'q' => $this->query,
            'filter' => QuickFilter::All === $this->quick ? '' : $this->quick->value,
            'page' => $this->page > 1 ? (string) $this->page : '',
            'id' => $this->id ?? '',
            'tab' => $this->tab ?? '',
            'width' => self::DEFAULT_WIDTH === $this->width ? '' : $this->width,
        ], static fn (string $value): bool => '' !== $value);

        foreach ($override as $name => $value) {
            if (null === $value) {
                unset($parameters[$name]);
            } else {
                $parameters[$name] = $value;
            }
        }

        return $parameters;
    }

    private static function matching(?string $value, string $pattern): bool
    {
        return null !== $value && 1 === preg_match($pattern, $value);
    }
}
