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

use Cog\DbLocker\ConnectionAdapterInterface;
use Cog\DbLocker\Exception\LockAcquireException;
use Cog\DbLocker\Exception\LockReleaseException;
use Cog\DbLocker\Postgres\Enum\PostgresLockAccessModeEnum;
use Cog\DbLocker\Postgres\Enum\PostgresLockLevelEnum;
use Cog\DbLocker\Postgres\LockHandle\SessionLevelLockHandle;
use Cog\DbLocker\Postgres\LockHandle\WithinSessionLevelLockHandle;
use Cog\DbLocker\Postgres\LockHandle\TransactionLevelLockHandle;
use Cog\DbLocker\TimeoutDuration;

final class PostgresAdvisoryLocker
{
    /**
     * Acquire a transaction-level advisory lock with configurable timeout and access mode.
     *
     * @param TimeoutDuration $timeoutDuration Maximum wait time. Use TimeoutDuration::zero() for an immediate (non-blocking) attempt.
     * @return TransactionLevelLockHandle Handle with wasAcquired=false if lock is held by another process (normal competition).
     *
     * @throws LockAcquireException If a database error occurs (connection failures, query errors, etc.). NOT thrown for normal lock contention.
     * @throws \LogicException If attempting to acquire outside of an active transaction.
     */
    public function acquireTransactionLevelLock(
        ConnectionAdapterInterface $dbConnection,
        PostgresLockKey $key,
        TimeoutDuration $timeoutDuration,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): TransactionLevelLockHandle {
        return new TransactionLevelLockHandle(
            lockKey: $key,
            accessMode: $accessMode,
            wasAcquired: $this->acquireLock(
                dbConnection: $dbConnection,
                key: $key,
                level: PostgresLockLevelEnum::Transaction,
                timeoutDuration: $timeoutDuration,
                accessMode: $accessMode,
            ),
        );
    }

    /**
     * Acquires a session-level advisory lock, executes the callback only if acquired, and ensures release.
     *
     * The callback is invoked only when the lock is successfully acquired. If the lock
     * is not acquired (held by another process), the callback is not called and
     * a handle with `wasAcquired = false` is returned.
     *
     * This method guarantees that the lock is released even if an exception is thrown during execution.
     * Useful for safely wrapping critical sections that require locking.
     *
     * ⚠️ Transaction-level advisory locks are strongly preferred whenever possible,
     * as they are automatically released at the end of a transaction and are less error-prone.
     * Use session-level locks only when transactional context is not available.
     *
     * @param callable(): TReturn $callback A callback to execute while the lock is held.
     * @param TimeoutDuration $timeoutDuration Maximum wait time. Use TimeoutDuration::zero() for an immediate (non-blocking) attempt.
     * @return WithinSessionLevelLockHandle<TReturn|null> Handle with wasAcquired and result of the callback (null if not acquired).
     *
     * @throws LockAcquireException If a database error occurs during lock acquisition. NOT thrown for normal lock contention.
     * @throws LockReleaseException If a database error occurs during lock release (only thrown if no other exception occurred during callback execution).
     *
     * @see acquireTransactionLevelLock() for preferred locking strategy.
     *
     * @template TReturn
     */
    public function withinSessionLevelLock(
        ConnectionAdapterInterface $dbConnection,
        PostgresLockKey $key,
        callable $callback,
        TimeoutDuration $timeoutDuration,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): WithinSessionLevelLockHandle {
        $lockHandle = $this->acquireSessionLevelLock(
            dbConnection: $dbConnection,
            key: $key,
            timeoutDuration: $timeoutDuration,
            accessMode: $accessMode,
        );

        if (!$lockHandle->wasAcquired) {
            return new WithinSessionLevelLockHandle(
                wasAcquired: false,
                result: null,
            );
        }

        $exception = null;
        try {
            $result = $callback();

            return new WithinSessionLevelLockHandle(
                wasAcquired: true,
                result: $result,
            );
        } catch (\Throwable $e) {
            $exception = $e;
            throw $e;
        } finally {
            try {
                $this->releaseSessionLevelLock(
                    dbConnection: $dbConnection,
                    key: $key,
                    accessMode: $accessMode,
                );
            } catch (\Throwable $releaseException) {
                if ($exception === null) {
                    throw $releaseException;
                }
            }
        }
    }

    /**
     * Acquire a session-level advisory lock with configurable timeout and access mode.
     *
     * ⚠️ Transaction-level advisory locks are strongly preferred whenever possible,
     * as they are automatically released at the end of a transaction and are less error-prone.
     * Use session-level locks only when transactional context is not available.
     *
     * ⚠️ When using session-level locks, prefer withinSessionLevelLock() over this method,
     * as it guarantees automatic lock release via try/finally even if exceptions occur.
     * This method requires manual release management via releaseSessionLevelLock() or the
     * lock handle's release() method.
     *
     * @param TimeoutDuration $timeoutDuration Maximum wait time. Use TimeoutDuration::zero() for an immediate (non-blocking) attempt.
     * @return SessionLevelLockHandle Handle with wasAcquired=false if lock is held by another process (normal competition).
     *
     * @throws LockAcquireException If a database error occurs (connection failures, query errors, etc.). NOT thrown for normal lock contention.
     *
     * @see acquireTransactionLevelLock() for preferred locking strategy.
     * @see withinSessionLevelLock() for automatic session lock management.
     */
    public function acquireSessionLevelLock(
        ConnectionAdapterInterface $dbConnection,
        PostgresLockKey $key,
        TimeoutDuration $timeoutDuration,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): SessionLevelLockHandle {
        return new SessionLevelLockHandle(
            dbConnection: $dbConnection,
            locker: $this,
            lockKey: $key,
            accessMode: $accessMode,
            wasAcquired: $this->acquireLock(
                dbConnection: $dbConnection,
                key: $key,
                level: PostgresLockLevelEnum::Session,
                timeoutDuration: $timeoutDuration,
                accessMode: $accessMode,
            ),
        );
    }

    /**
     * Release session level advisory lock.
     *
     * @return bool True if the lock was successfully released, false if it was not held by this session.
     *
     * @throws LockReleaseException If a database error occurs during release.
     */
    public function releaseSessionLevelLock(
        ConnectionAdapterInterface $dbConnection,
        PostgresLockKey $key,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): bool {
        try {
            $sql = match ($accessMode) {
                PostgresLockAccessModeEnum::Exclusive
                => 'SELECT PG_ADVISORY_UNLOCK(:class_id, :object_id);',

                PostgresLockAccessModeEnum::Share
                => 'SELECT PG_ADVISORY_UNLOCK_SHARED(:class_id, :object_id);',
            };
            $sql .= " -- $key->humanReadableValue";

            return $dbConnection->fetchColumn($sql, [
                'class_id' => $key->classId,
                'object_id' => $key->objectId,
            ]);
        } catch (\Exception $exception) {
            throw LockReleaseException::fromDatabaseError($key, $exception);
        }
    }

    /**
     * Release all session level advisory locks held by the current session.
     */
    public function releaseAllSessionLevelLocks(
        ConnectionAdapterInterface $dbConnection,
    ): void {
        $dbConnection->execute('SELECT PG_ADVISORY_UNLOCK_ALL();');
    }

    private function acquireLock(
        ConnectionAdapterInterface $dbConnection,
        PostgresLockKey $key,
        PostgresLockLevelEnum $level,
        TimeoutDuration $timeoutDuration,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): bool {
        if ($level === PostgresLockLevelEnum::Transaction && $dbConnection->isTransactionActive() === false) {
            throw new \LogicException(
                "Transaction-level advisory lock `$key->humanReadableValue` cannot be acquired outside of transaction",
            );
        }

        return $timeoutDuration->toMilliseconds() === 0
            ? $this->tryAcquireLock(
                dbConnection: $dbConnection,
                key: $key,
                level: $level,
                accessMode: $accessMode,
            )
            : $this->acquireLockWithTimeout(
                dbConnection: $dbConnection,
                key: $key,
                level: $level,
                accessMode: $accessMode,
                timeoutDuration: $timeoutDuration,
            );
    }

    private function tryAcquireLock(
        ConnectionAdapterInterface $dbConnection,
        PostgresLockKey $key,
        PostgresLockLevelEnum $level,
        PostgresLockAccessModeEnum $accessMode,
    ): bool {
        try {
            $sql = match ([$level, $accessMode]) {
                [PostgresLockLevelEnum::Session, PostgresLockAccessModeEnum::Exclusive]
                => 'SELECT PG_TRY_ADVISORY_LOCK(:class_id, :object_id);',

                [PostgresLockLevelEnum::Session, PostgresLockAccessModeEnum::Share]
                => 'SELECT PG_TRY_ADVISORY_LOCK_SHARED(:class_id, :object_id);',

                [PostgresLockLevelEnum::Transaction, PostgresLockAccessModeEnum::Exclusive]
                => 'SELECT PG_TRY_ADVISORY_XACT_LOCK(:class_id, :object_id);',

                [PostgresLockLevelEnum::Transaction, PostgresLockAccessModeEnum::Share]
                => 'SELECT PG_TRY_ADVISORY_XACT_LOCK_SHARED(:class_id, :object_id);',
            };
            $sql .= " -- $key->humanReadableValue";

            return $dbConnection->fetchColumn($sql, [
                'class_id' => $key->classId,
                'object_id' => $key->objectId,
            ]);
        } catch (\Exception $exception) {
            throw LockAcquireException::fromDatabaseError($key, $exception);
        }
    }

    private function acquireLockWithTimeout(
        ConnectionAdapterInterface $dbConnection,
        PostgresLockKey $key,
        PostgresLockLevelEnum $level,
        PostgresLockAccessModeEnum $accessMode,
        TimeoutDuration $timeoutDuration,
    ): bool {
        $sql = match ([$level, $accessMode]) {
            [PostgresLockLevelEnum::Session, PostgresLockAccessModeEnum::Exclusive]
            => 'SELECT PG_ADVISORY_LOCK(:class_id, :object_id);',

            [PostgresLockLevelEnum::Session, PostgresLockAccessModeEnum::Share]
            => 'SELECT PG_ADVISORY_LOCK_SHARED(:class_id, :object_id);',

            [PostgresLockLevelEnum::Transaction, PostgresLockAccessModeEnum::Exclusive]
            => 'SELECT PG_ADVISORY_XACT_LOCK(:class_id, :object_id);',

            [PostgresLockLevelEnum::Transaction, PostgresLockAccessModeEnum::Share]
            => 'SELECT PG_ADVISORY_XACT_LOCK_SHARED(:class_id, :object_id);',
        };
        $sql .= " -- $key->humanReadableValue";

        return match ($level) {
            PostgresLockLevelEnum::Transaction => $this->acquireTransactionLockWithTimeout(
                dbConnection: $dbConnection,
                sql: $sql,
                key: $key,
                timeoutDuration: $timeoutDuration,
            ),
            PostgresLockLevelEnum::Session => $this->acquireSessionLockWithTimeout(
                dbConnection: $dbConnection,
                sql: $sql,
                key: $key,
                timeoutDuration: $timeoutDuration,
            ),
        };
    }



    private function acquireTransactionLockWithTimeout(
        ConnectionAdapterInterface $dbConnection,
        string $sql,
        PostgresLockKey $key,
        TimeoutDuration $timeoutDuration,
    ): bool {
        try {
            $timeoutMs = $timeoutDuration->toMilliseconds();
            $dbConnection->execute("SET LOCAL lock_timeout = '$timeoutMs'");

            /**
             * Use a savepoint so that a lock_timeout error does not abort the entire transaction.
             * PostgreSQL handles same-name savepoints as a stack, so nested calls are safe.
             */
            $dbConnection->execute('SAVEPOINT _lock_timeout_savepoint');

            try {
                $dbConnection->execute($sql, [
                    'class_id' => $key->classId,
                    'object_id' => $key->objectId,
                ]);

                $dbConnection->execute('RELEASE SAVEPOINT _lock_timeout_savepoint');

                return true;
            } catch (\Exception $exception) {
                if ($dbConnection->isLockNotAvailable($exception)) {
                    $dbConnection->execute('ROLLBACK TO SAVEPOINT _lock_timeout_savepoint');

                    return false;
                }

                throw $exception;
            }
        } catch (\Exception $exception) {
            throw LockAcquireException::fromDatabaseError($key, $exception);
        }
    }

    private function acquireSessionLockWithTimeout(
        ConnectionAdapterInterface $dbConnection,
        string $sql,
        PostgresLockKey $key,
        TimeoutDuration $timeoutDuration,
    ): bool {
        try {
            $timeoutMs = $timeoutDuration->toMilliseconds();
            $originalLockTimeout = $dbConnection->fetchColumn('SHOW lock_timeout');
            $dbConnection->execute("SET lock_timeout = '$timeoutMs'");

            try {
                $dbConnection->execute($sql, [
                    'class_id' => $key->classId,
                    'object_id' => $key->objectId,
                ]);

                return true;
            } catch (\Exception $exception) {
                if ($dbConnection->isLockNotAvailable($exception)) {
                    return false;
                }

                throw $exception;
            }
            finally {
                $dbConnection->execute("SET lock_timeout = '$originalLockTimeout'");
            }
        } catch (\Exception $exception) {
            throw LockAcquireException::fromDatabaseError($key, $exception);
        }
    }
}
