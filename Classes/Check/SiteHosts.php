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

namespace KonradMichalik\Typo3Letterbox\Check;

use TYPO3\CMS\Core\Site\SiteFinder;

use function is_array;
use function is_string;

/**
 * SiteHosts.
 *
 * The lowercase host names of all sites: site bases, language bases and base variants.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class SiteHosts
{
    public function __construct(
        private SiteFinder $siteFinder,
    ) {}

    /**
     * @return list<string>
     */
    public function all(): array
    {
        $hosts = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $hosts[] = $site->getBase()->getHost();
            foreach ($site->getAllLanguages() as $language) {
                $hosts[] = $language->getBase()->getHost();
            }
            foreach ($site->getConfiguration()['baseVariants'] ?? [] as $variant) {
                $hosts[] = is_array($variant) && is_string($variant['base'] ?? null) ? (string) parse_url($variant['base'], \PHP_URL_HOST) : '';
            }
        }

        return array_values(array_unique(array_filter(array_map(strtolower(...), $hosts), static fn (string $host): bool => '' !== $host)));
    }
}
