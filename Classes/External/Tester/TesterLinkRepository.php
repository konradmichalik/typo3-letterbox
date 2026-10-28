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

    public const LIFETIME_DAYS = 7;

    /** Expired and revoked links stay listed this long, then the next issue() removes them. */
    public const RETENTION_DAYS = 30;

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @param list<RecipientPattern> $scope
     */
    public function issue(string $label, array $scope, int $createdBy, int $now): IssuedLink
    {
        if ([] === $scope) {
            throw new InvalidArgumentException('A tester link needs at least one recipient pattern.', 1761561701);
        }

        $this->purge($now - self::RETENTION_DAYS * 86400);
        $token = bin2hex(random_bytes(32));
        $expires = $now + self::LIFETIME_DAYS * 86400;
        $connection = $this->connection();
        $connection->insert(self::TABLE, [
            'token_hash' => hash('sha256', $token),
            'label' => $label,
            'scope' => implode("\n", array_map(static fn (RecipientPattern $pattern): string => $pattern->value(), $scope)),
            'expires' => $expires,
            'created' => $now,
            'created_by' => $createdBy,
        ]);

        $link = new TesterLink((int) $connection->lastInsertId(), $label, $scope, $expires, $now, $createdBy, 0);

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

    private function purge(int $before): void
    {
        $queryBuilder = $this->connection()->createQueryBuilder();
        $cutoff = $queryBuilder->createNamedParameter($before, Connection::PARAM_INT);
        $queryBuilder
            ->delete(self::TABLE)
            ->where($queryBuilder->expr()->or(
                $queryBuilder->expr()->lt('expires', $cutoff),
                $queryBuilder->expr()->and(
                    $queryBuilder->expr()->gt('revoked', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                    $queryBuilder->expr()->lt('revoked', $cutoff),
                ),
            ))
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

        return new TesterLink(
            (int) $row['uid'],
            (string) $row['label'],
            $scopeValid ? $scope : [],
            (int) $row['expires'],
            (int) $row['created'],
            (int) $row['created_by'],
            (int) $row['revoked'],
            $scopeValid,
        );
    }

    private function connection(): Connection
    {
        return $this->connectionPool->getConnectionForTable(self::TABLE);
    }
}
