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

use Cog\DbLocker\Postgres\Enum\PostgresLockAccessModeEnum;
use Cog\DbLocker\Postgres\Enum\PostgresLockLevelEnum;
use Cog\DbLocker\Postgres\Enum\PostgresLockWaitModeEnum;
use Cog\DbLocker\Postgres\LockHandle\SessionLevelLockHandle;
use Cog\DbLocker\Postgres\LockHandle\TransactionLevelLockHandle;
use LogicException;
use PDO;

final class PostgresAdvisoryLocker
{
    /**
     * Acquire a transaction-level advisory lock with configurable wait and access modes.
     */
    public function acquireTransactionLevelLock(
        PDO $dbConnection,
        PostgresLockKey $key,
        PostgresLockWaitModeEnum $waitMode = PostgresLockWaitModeEnum::NonBlocking,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): TransactionLevelLockHandle {
        return new TransactionLevelLockHandle(
            wasAcquired: $this->acquireLock(
                $dbConnection,
                $key,
                PostgresLockLevelEnum::Transaction,
                $waitMode,
                $accessMode,
            ),
        );
    }

    /**
     * Acquire a session-level advisory lock with configurable wait and access modes.
     *
     * ⚠️ You MUST retain the returned handle in a variable.
     * If the handle is not stored and is immediately garbage collected,
     * the lock will be released in the lock handle __destruct method.
     * @see SessionLevelLockHandle::__destruct
     *
     * @example
     * $handle = $locker->acquireSessionLevelLock(...); // ✅ Lock held
     *
     * $locker->acquireSessionLevelLock(...); // ❌ Lock immediately released
     *
     * ⚠️ Transaction-level advisory locks are strongly preferred whenever possible,
     * as they are automatically released at the end of a transaction and are less error-prone.
     * Use session-level locks only when transactional context is not available.
     * @see acquireTransactionLevelLock() for preferred locking strategy.
     */
    public function acquireSessionLevelLock(
        PDO $dbConnection,
        PostgresLockKey $key,
        PostgresLockWaitModeEnum $waitMode = PostgresLockWaitModeEnum::NonBlocking,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): SessionLevelLockHandle {
        return new SessionLevelLockHandle(
            $dbConnection,
            $this,
            $key,
            $accessMode,
            wasAcquired: $this->acquireLock(
                $dbConnection,
                $key,
                PostgresLockLevelEnum::Session,
                $waitMode,
                $accessMode,
            ),
        );
    }

    /**
     * Acquires a session-level advisory lock and ensures its release after executing the callback.
     *
     * This method guarantees that the lock is released even if an exception is thrown during execution.
     * Useful for safely wrapping critical sections that require locking.
     *
     * If the lock was not acquired (i.e., `wasAcquired` is `false`), it is up to the callback
     * to decide how to handle the situation (e.g., retry, throw, log, or silently skip).
     *
     * ⚠️ Transaction-level advisory locks are strongly preferred whenever possible,
     * as they are automatically released at the end of a transaction and are less error-prone.
     * Use session-level locks only when transactional context is not available.
     * @see acquireTransactionLevelLock() for preferred locking strategy.
     *
     * @param PDO $dbConnection Active database connection.
     * @param PostgresLockKey $key Lock key to be acquired.
     * @param callable(SessionLevelLockHandle): TReturn $callback A callback that receives the lock handle.
     * @param PostgresLockWaitModeEnum $waitMode Whether to wait for the lock or fail immediately. Default is non-blocking.
     * @param PostgresLockAccessModeEnum $accessMode Whether to acquire a shared or exclusive lock. Default is exclusive.
     * @return TReturn The return value of the callback.
     *
     * @template TReturn
     *
     * TODO: Cover with tests
     */
    public function withinSessionLevelLock(
        PDO $dbConnection,
        PostgresLockKey $key,
        callable $callback,
        PostgresLockWaitModeEnum $waitMode = PostgresLockWaitModeEnum::NonBlocking,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): mixed {
        $lockHandle = $this->acquireSessionLevelLock(
            $dbConnection,
            $key,
            $waitMode,
            $accessMode,
        );

        try {
            return $callback($lockHandle);
        }
        finally {
            $this->releaseSessionLevelLock(
                $dbConnection,
                $key,
                $accessMode,
            );
        }
    }

    /**
     * Release session level advisory lock.
     */
    public function releaseSessionLevelLock(
        PDO $dbConnection,
        PostgresLockKey $key,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): bool {
        $sql = match ($accessMode) {
            PostgresLockAccessModeEnum::Exclusive => 'SELECT PG_ADVISORY_UNLOCK(:class_id, :object_id);',
            PostgresLockAccessModeEnum::Share => 'SELECT PG_ADVISORY_UNLOCK_SHARED(:class_id, :object_id);',
        };
        $sql .= " -- $key->humanReadableValue";

        $statement = $dbConnection->prepare($sql);
        $statement->execute(
            [
                'class_id' => $key->classId,
                'object_id' => $key->objectId,
            ],
        );

        return $statement->fetchColumn(0);
    }

    /**
     * Release all session level advisory locks held by the current session.
     */
    public function releaseAllSessionLevelLocks(
        PDO $dbConnection,
    ): void {
        $statement = $dbConnection->prepare(
            <<<'SQL'
                SELECT PG_ADVISORY_UNLOCK_ALL();
                SQL,
        );
        $statement->execute();
    }

    private function acquireLock(
        PDO $dbConnection,
        PostgresLockKey $key,
        PostgresLockLevelEnum $level,
        PostgresLockWaitModeEnum $waitMode = PostgresLockWaitModeEnum::NonBlocking,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): bool {
        if ($level === PostgresLockLevelEnum::Transaction && $dbConnection->inTransaction() === false) {
            throw new LogicException(
                "Transaction-level advisory lock `$key->humanReadableValue` cannot be acquired outside of transaction",
            );
        }

        $sql = match ([$level, $waitMode, $accessMode]) {
            [
                PostgresLockLevelEnum::Transaction,
                PostgresLockWaitModeEnum::NonBlocking,
                PostgresLockAccessModeEnum::Exclusive,
            ] => 'SELECT PG_TRY_ADVISORY_XACT_LOCK(:class_id, :object_id);',
            [
                PostgresLockLevelEnum::Transaction,
                PostgresLockWaitModeEnum::Blocking,
                PostgresLockAccessModeEnum::Exclusive,
            ] => 'SELECT PG_ADVISORY_XACT_LOCK(:class_id, :object_id);',
            [
                PostgresLockLevelEnum::Transaction,
                PostgresLockWaitModeEnum::NonBlocking,
                PostgresLockAccessModeEnum::Share,
            ] => 'SELECT PG_TRY_ADVISORY_XACT_LOCK_SHARED(:class_id, :object_id);',
            [
                PostgresLockLevelEnum::Transaction,
                PostgresLockWaitModeEnum::Blocking,
                PostgresLockAccessModeEnum::Share,
            ] => 'SELECT PG_ADVISORY_XACT_LOCK_SHARED(:class_id, :object_id);',
            [
                PostgresLockLevelEnum::Session,
                PostgresLockWaitModeEnum::NonBlocking,
                PostgresLockAccessModeEnum::Exclusive,
            ] => 'SELECT PG_TRY_ADVISORY_LOCK(:class_id, :object_id);',
            [
                PostgresLockLevelEnum::Session,
                PostgresLockWaitModeEnum::Blocking,
                PostgresLockAccessModeEnum::Exclusive,
            ] => 'SELECT PG_ADVISORY_LOCK(:class_id, :object_id);',
            [
                PostgresLockLevelEnum::Session,
                PostgresLockWaitModeEnum::NonBlocking,
                PostgresLockAccessModeEnum::Share,
            ] => 'SELECT PG_TRY_ADVISORY_LOCK_SHARED(:class_id, :object_id);',
            [
                PostgresLockLevelEnum::Session,
                PostgresLockWaitModeEnum::Blocking,
                PostgresLockAccessModeEnum::Share,
            ] => 'SELECT PG_ADVISORY_LOCK_SHARED(:class_id, :object_id);',
        };
        $sql .= " -- $key->humanReadableValue";

        $statement = $dbConnection->prepare($sql);
        $statement->execute(
            [
                'class_id' => $key->classId,
                'object_id' => $key->objectId,
            ],
        );

        return $statement->fetchColumn(0);
    }
}
