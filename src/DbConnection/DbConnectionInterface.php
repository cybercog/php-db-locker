<?php

declare(strict_types=1);

namespace Cog\DbLocker\DbConnection;

interface DbConnectionInterface
{
    /**
     * Execute a SQL query and return the first column of the first row.
     *
     * @param string $sql The SQL query to execute
     * @param array<string, mixed> $parameters Parameters to bind to the query
     * @return mixed The value from the first column of the first row
     */
    public function executeAndFetchColumn(string $sql, array $parameters = []): mixed;

    /**
     * Check if the connection is currently inside a transaction.
     *
     * @return bool True if inside a transaction, false otherwise
     */
    public function isInTransaction(): bool;
}
