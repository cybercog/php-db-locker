<?php

/*
 * This file is part of PHP DB Locker.
 *
 * (c) Anton Komarev <anton@komarev.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Cog\DbLocker\Postgres;

use Cog\DbLocker\DbConnectionAdapter\DbConnectionAdapterInterface;
use Cog\DbLocker\Postgres\Enum\PostgresLockAccessModeEnum;
use Cog\DbLocker\Postgres\Enum\PostgresLockWaitModeEnum;
use Cog\DbLocker\Postgres\LockHandle\SessionLevelLockHandle;

final class PostgresLock
{
    private readonly PostgresAdvisoryLocker $locker;

    public function __construct(
        private readonly DbConnectionAdapterInterface $connectionAdapter,
        private readonly PostgresLockKey $lockKey,
    ) {
        $this->locker = new PostgresAdvisoryLocker();
    }

    /**
     * Acquire a transaction-level advisory lock.
     *
     * Transaction-level locks are automatically released when the transaction
     * is committed or rolled back.
     *
     * @param PostgresLockWaitModeEnum $waitMode Whether to wait for the lock or fail immediately
     * @param PostgresLockAccessModeEnum $accessMode Whether to acquire a shared or exclusive lock
     * @return bool True if the lock was acquired, false otherwise
     */
    public function acquireTransactionLevel(
        PostgresLockWaitModeEnum $waitMode = PostgresLockWaitModeEnum::NonBlocking,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): bool {
        return $this->locker->acquireTransactionLevelLock(
            $this->connectionAdapter,
            $this->lockKey,
            $waitMode,
            $accessMode,
        );
    }

    /**
     * Acquire a session-level advisory lock.
     *
     * ⚠️ Session-level locks must be explicitly released or they will persist
     * until the database connection is closed.
     * Consider using withinSessionLock() for automatic cleanup.
     *
     * @param PostgresLockWaitModeEnum $waitMode Whether to wait for the lock or fail immediately
     * @param PostgresLockAccessModeEnum $accessMode Whether to acquire a shared or exclusive lock
     * @return SessionLevelLockHandle Lock handle for managing the acquired lock
     */
    public function acquireSessionLevel(
        PostgresLockWaitModeEnum $waitMode = PostgresLockWaitModeEnum::NonBlocking,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): SessionLevelLockHandle {
        return $this->locker->acquireSessionLevelLock(
            $this->connectionAdapter,
            $this->lockKey,
            $waitMode,
            $accessMode,
        );
    }

    /**
     * Execute code within a session-level advisory lock with automatic cleanup.
     *
     * This method guarantees that the lock is released even if an exception
     * is thrown during execution.
     *
     * @template TReturn
     *
     * @param callable(SessionLevelLockHandle): TReturn $callback Callback to execute within the lock
     * @param PostgresLockWaitModeEnum $waitMode Whether to wait for the lock or fail immediately
     * @param PostgresLockAccessModeEnum $accessMode Whether to acquire a shared or exclusive lock
     * @return TReturn The return value of the callback
     */
    public function withinSessionLock(
        callable $callback,
        PostgresLockWaitModeEnum $waitMode = PostgresLockWaitModeEnum::NonBlocking,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): mixed {
        return $this->locker->withinSessionLevelLock(
            $this->connectionAdapter,
            $this->lockKey,
            $callback,
            $waitMode,
            $accessMode,
        );
    }

    /**
     * Release the session-level advisory lock.
     *
     * @param PostgresLockAccessModeEnum $accessMode The access mode that was used to acquire the lock
     * @return bool True if the lock was released, false if it wasn't held
     */
    public function release(
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): bool {
        return $this->locker->releaseSessionLevelLock(
            $this->connectionAdapter,
            $this->lockKey,
            $accessMode,
        );
    }

    /**
     * Release all session-level advisory locks held by the current session.
     *
     * This affects all locks in the current database session, not just this specific lock.
     */
    public function releaseAll(): void
    {
        $this->locker->releaseAllSessionLevelLocks($this->connectionAdapter);
    }

    /**
     * Get the lock key associated with this lock instance.
     */
    public function getLockKey(): PostgresLockKey
    {
        return $this->lockKey;
    }

    /**
     * Check if the connection is currently in a transaction.
     */
    public function isInTransaction(): bool
    {
        return $this->connectionAdapter->isInTransaction();
    }

    /**
     * Get the database platform name.
     */
    public function getPlatformName(): string
    {
        return $this->connectionAdapter->getPlatformName();
    }

    /**
     * Extract PDO instance from the adapter.
     * 
     * Note: This is a temporary solution to maintain compatibility with the existing
     * PostgresAdvisoryLocker. In future versions, PostgresAdvisoryLocker should be
     * refactored to work directly with DbConnectionAdapterInterface.
     */
    private function getPdoFromAdapter(): \PDO
    {
        // For now, we need to extract PDO from adapters
        // This is not ideal but maintains compatibility
        
        if ($this->connectionAdapter instanceof \Cog\DbLocker\DbConnectionAdapter\PdoDbConnectionAdapter) {
            // Use reflection to access the private PDO property
            $reflection = new \ReflectionClass($this->connectionAdapter);
            $property = $reflection->getProperty('pdo');
            $property->setAccessible(true);
            return $property->getValue($this->connectionAdapter);
        }
        
        if ($this->connectionAdapter instanceof \Cog\DbLocker\DbConnectionAdapter\DoctrineDbConnectionAdapter) {
            // Get the underlying PDO from Doctrine DBAL connection
            $reflection = new \ReflectionClass($this->connectionAdapter);
            $property = $reflection->getProperty('dbConnection');
            $property->setAccessible(true);
            $doctrineConnection = $property->getValue($this->connectionAdapter);
            return $doctrineConnection->getNativeConnection();
        }
        
        if ($this->connectionAdapter instanceof \Cog\DbLocker\DbConnectionAdapter\EloquentDbConnectionAdapter) {
            // Get the underlying PDO from Eloquent connection
            $reflection = new \ReflectionClass($this->connectionAdapter);
            $property = $reflection->getProperty('dbConnection');
            $property->setAccessible(true);
            $eloquentConnection = $property->getValue($this->connectionAdapter);
            return $eloquentConnection->getPdo();
        }
        
        throw new \InvalidArgumentException(
            'Unsupported connection adapter: ' . get_class($this->connectionAdapter)
        );
    }
}