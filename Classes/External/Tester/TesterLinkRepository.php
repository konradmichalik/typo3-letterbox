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

namespace KonradMichalik\Typo3Letterbox\External\Tester;

use InvalidArgumentException;
use KonradMichalik\Typo3Letterbox\Mail\RecipientPattern;
use TYPO3\CMS\Core\Database\{Connection, ConnectionPool};

use function in_array;
use function is_string;

/**
 * TesterLinkRepository.
 *
 * Only the SHA-256 hash of a token is stored. Not a TCA table, so it is read and written through the DBAL.
 *
 * @author Konrad Michalik <hej@konradmichalik.dev>
 */
final readonly class TesterLinkRepository
{
    public const TABLE = 'tx_typo3letterbox_tester_link';

    private const DAYS = [1, 7, 30];

    private const PERMISSION_DOWNLOAD = 1;

    private const PERMISSION_DELETE = 2;

    private const TOUCH_INTERVAL = 60;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @param list<RecipientPattern> $scope
     */
    public function issue(string $label, array $scope, bool $unrestricted, bool $canDownload, bool $canDelete, int $days, int $createdBy, int $now): IssuedLink
    {
        if (!in_array($days, self::DAYS, true)) {
            throw new InvalidArgumentException('A tester link is valid for 1, 7 or 30 days.', 1761561700);
        }
        if ([] === $scope && !$unrestricted) {
            throw new InvalidArgumentException('A tester link without recipient patterns must be marked unrestricted.', 1761561701);
        }
        if ([] !== $scope && $unrestricted) {
            throw new InvalidArgumentException('An unrestricted tester link cannot carry recipient patterns.', 1761561702);
        }

        $token = bin2hex(random_bytes(32));
        $expires = $now + $days * 86400;
        $connection = $this->connection();
        $connection->insert(self::TABLE, [
            'token_hash' => hash('sha256', $token),
            'label' => $label,
            'scope' => implode("\n", array_map(static fn (RecipientPattern $pattern): string => $pattern->value(), $scope)),
            'unrestricted' => $unrestricted ? 1 : 0,
            'permissions' => ($canDownload ? self::PERMISSION_DOWNLOAD : 0) | ($canDelete ? self::PERMISSION_DELETE : 0),
            'expires' => $expires,
            'created' => $now,
            'created_by' => $createdBy,
        ]);

        $link = new TesterLink((int) $connection->lastInsertId(), $label, $scope, $unrestricted, $canDownload, $canDelete, $expires, $now, $createdBy, 0, 0);

        return new IssuedLink($link, $token);
    }

    public function findByToken(string $token): ?TesterLink
    {
        if (1 !== preg_match('/^[0-9a-f]{64}$/', $token)) {
            return null;
        }

        $hash = hash('sha256', $token);
        $queryBuilder = $this->connection()->createQueryBuilder();
        $row = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->where($queryBuilder->expr()->eq('token_hash', $queryBuilder->createNamedParameter($hash, Connection::PARAM_STR)))
            ->executeQuery()
            ->fetchAssociative();

        if (false === $row || !is_string($row['token_hash']) || !hash_equals($hash, $row['token_hash'])) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * @return list<TesterLink>
     */
    public function findAll(): array
    {
        $queryBuilder = $this->connection()->createQueryBuilder();
        $rows = $queryBuilder
            ->select('*')
            ->from(self::TABLE)
            ->orderBy('created', 'DESC')
            ->addOrderBy('uid', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    public function revoke(int $uid, int $now): void
    {
        $this->connection()->update(self::TABLE, ['revoked' => $now], ['uid' => $uid]);
    }

    /**
     * Writes only when the last use is older than a minute, so polling does not cause a write per request.
     */
    public function touch(int $uid, int $now): void
    {
        $queryBuilder = $this->connection()->createQueryBuilder();
        $queryBuilder
            ->update(self::TABLE)
            ->set('last_used', $now, true, Connection::PARAM_INT)
            ->where(
                $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)),
                $queryBuilder->expr()->lt('last_used', $queryBuilder->createNamedParameter($now - self::TOUCH_INTERVAL, Connection::PARAM_INT)),
            )
            ->executeStatement();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): TesterLink
    {
        $scope = [];
        $scopeValid = true;
        foreach (explode("\n", (string) $row['scope']) as $line) {
            if ('' === trim($line)) {
                continue;
            }
            try {
                $scope[] = RecipientPattern::fromString($line);
            } catch (InvalidArgumentException) {
                $scopeValid = false;
            }
        }
        $permissions = (int) $row['permissions'];

        return new TesterLink(
            (int) $row['uid'],
            (string) $row['label'],
            $scopeValid ? $scope : [],
            0 !== (int) $row['unrestricted'],
            0 !== ($permissions & self::PERMISSION_DOWNLOAD),
            0 !== ($permissions & self::PERMISSION_DELETE),
            (int) $row['expires'],
            (int) $row['created'],
            (int) $row['created_by'],
            (int) $row['last_used'],
            (int) $row['revoked'],
            $scopeValid,
        );
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
