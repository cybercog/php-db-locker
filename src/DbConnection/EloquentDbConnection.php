<?php

declare(strict_types=1);

namespace Cog\DbLocker\DbConnection;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Eloquent Database adapter for unified database connection interface.
 *
 * This adapter wraps an Illuminate\Database\Connection instance and provides
 * a consistent interface for database operations across different database drivers.
 */
final class EloquentDbConnection implements
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
            // Convert named parameters to positional parameters for Eloquent
            $bindings = array_values($parameters);

            $result = $this->connection->selectOne($sql, $bindings);

            if ($result === null) {
                return null;
            }

            // Convert stdClass to array and get first value
            $resultArray = (array)$result;

            return reset($resultArray) ?: null;
        } catch (QueryException $e) {
            throw new RuntimeException(
                sprintf(
                    'Eloquent Database error while executing SQL query: %s. Error: %s',
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
        return $this->connection->transactionLevel() > 0;
    }
}
