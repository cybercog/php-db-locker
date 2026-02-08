<?php

declare(strict_types=1);

namespace Cog\DbLocker\DbConnection;

use Cog\DbLocker\ConnectionAdapterInterface;
use PDO;

/**
 * PDO-based implementation of ConnectionAdapterInterface.
 *
 * This adapter wraps a PDO connection and provides the minimal interface
 * required by PostgresAdvisoryLocker.
 *
 * Requirements:
 * - PDO connection MUST use PDO::ERRMODE_EXCEPTION
 * - Constructor validates this requirement and throws LogicException if violated
 */
final class PdoConnectionAdapter implements ConnectionAdapterInterface
{
    private const PG_SQLSTATE_LOCK_NOT_AVAILABLE = '55P03';

    public function __construct(
        private readonly PDO $pdo,
    ) {
        if ((int) $this->pdo->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new \LogicException(
                'PDO connection must use PDO::ERRMODE_EXCEPTION. '
                . 'Set it via: $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)',
            );
        }
    }

    public function fetchColumn(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn(0);
    }

    public function execute(string $sql, array $params = []): void
    {
        if ($params === []) {
            // exec() is more efficient for non-parameterized queries
            $this->pdo->exec($sql);
            return;
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
    }

    public function isTransactionActive(): bool
    {
        return $this->pdo->inTransaction();
    }

    public function isLockNotAvailable(\Exception $exception): bool
    {
        // PDOException: getCode() returns SQLSTATE string
        if ($exception instanceof \PDOException) {
            return $exception->getCode() === self::PG_SQLSTATE_LOCK_NOT_AVAILABLE;
        }

        return false;
    }
}
