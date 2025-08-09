<?php

declare(strict_types=1);

namespace Cog\DbLocker\DbConnection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use RuntimeException;

/**
 * Doctrine DBAL adapter for unified database connection interface.
 *
 * This adapter wraps a Doctrine DBAL Connection instance and provides
 * a consistent interface for database operations across different database drivers.
 */
final class DoctrineDbConnection implements
    DbConnectionInterface
{
    public function __construct(
        private Connection $connection,
    ) {}

    /**
     * @inheritDoc
     */
    public function executeAndFetchColumn(string $sql, array $parameters = []): mixed
    {
        try {
            return $this->connection->fetchOne($sql, $parameters);
        } catch (Exception $e) {
            throw new RuntimeException(
                sprintf(
                    'Doctrine DBAL error while executing SQL query: %s. Error: %s',
                    $sql,
                    $e->getMessage(),
                ),
                previous: $e,
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function isInTransaction(): bool
    {
        return $this->connection->isTransactionActive();
    }
}
