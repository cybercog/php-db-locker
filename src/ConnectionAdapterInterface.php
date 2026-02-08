<?php

declare(strict_types=1);

namespace Cog\DbLocker;

/**
 * Minimal connection abstraction for PostgreSQL advisory lock operations.
 *
 * This interface defines only the operations required by PostgresAdvisoryLocker.
 * It is NOT a general-purpose database abstraction layer.
 *
 * Exception Handling Contract:
 * - Implementations MUST throw the original database exception unwrapped.
 * - For PDO: throw \PDOException with SQLSTATE in getCode()
 * - For ORMs: throw their native exceptions (Doctrine\DBAL\Exception, etc.)
 * - The locker will inspect exceptions for SQLSTATE '55P03' (lock_not_available)
 */
interface ConnectionAdapterInterface
{
    /**
     * Execute a parameterized query and return the first column of the first row.
     *
     * Used for lock acquisition functions that return boolean results:
     * - SELECT PG_TRY_ADVISORY_LOCK(:class_id, :object_id)
     * - SELECT PG_ADVISORY_UNLOCK(:class_id, :object_id)
     * - SHOW lock_timeout
     *
     * @param string $sql SQL query with named parameters (e.g., :class_id)
     * @param array<string, mixed> $params Named parameters (e.g., ['class_id' => 1, 'object_id' => 2])
     * @return mixed The value of the first column (typically bool or string)
     * @throws \Throwable Database-specific exception on error
     */
    public function fetchColumn(string $sql, array $params = []): mixed;

    /**
     * Execute a statement without returning results.
     *
     * Used for:
     * - Blocking lock acquisition: SELECT PG_ADVISORY_LOCK(:class_id, :object_id)
     * - Transaction control: SAVEPOINT, RELEASE SAVEPOINT, ROLLBACK TO SAVEPOINT
     * - Configuration: SET [LOCAL] lock_timeout = '...'
     * - Cleanup: SELECT PG_ADVISORY_UNLOCK_ALL()
     *
     * @param string $sql SQL statement with optional named parameters
     * @param array<string, mixed> $params Named parameters (e.g., ['class_id' => 1])
     * @throws \Throwable Database-specific exception on error
     */
    public function execute(string $sql, array $params = []): void;

    /**
     * Check whether a transaction is currently active on this connection.
     *
     * Used to validate that transaction-level locks are only acquired within a transaction.
     *
     * @return bool True if a transaction is active, false otherwise
     */
    public function isTransactionActive(): bool;
}
