<?php

declare(strict_types=1);

namespace Cog\DbLocker\DbConnectionAdapter;

use Cog\DbLocker\DbConnectionAdapter\Exception\UncheckedDoctrineDbalException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use RuntimeException;

/**
 * Doctrine DBAL adapter for unified database connection interface.
 *
 * This adapter wraps a Doctrine DBAL Connection instance and provides
 * a consistent interface for database operations across different database drivers.
 */
final class DoctrineDbConnectionAdapter implements
    DbConnectionAdapterInterface
{
    public function __construct(
        private readonly Connection $dbConnection,
    ) {}

    /**
     * @inheritDoc
     */
    public function executeAndFetchColumn(
        string $sql,
        array $parameters = [],
    ): mixed {
        try {
            return $this->dbConnection->fetchOne($sql, $parameters);
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
        return $this->dbConnection->isTransactionActive();
    }

    public function getPlatformName(): string
    {
        try {
            return match (true) {
                $this->dbConnection->getDatabasePlatform() instanceof MySqlPlatform => self::PLATFORM_MYSQL,
                $this->dbConnection->getDatabasePlatform() instanceof PostgreSqlPlatform => self::PLATFORM_POSTGRESQL,
                default => gettype($this->dbConnection->getDatabasePlatform()),
            };
        } catch (DbalException $exception) {
            throw UncheckedDoctrineDbalException::ofDbalException($exception);
        }
    }
}
