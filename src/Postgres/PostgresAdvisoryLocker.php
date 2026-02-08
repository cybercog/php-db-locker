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
use Cog\DbLocker\Postgres\Enum\PostgresLockAccessModeEnum;
use Cog\DbLocker\Postgres\Enum\PostgresLockLevelEnum;
use Cog\DbLocker\Postgres\LockHandle\SessionLevelLockHandle;
use Cog\DbLocker\Postgres\LockHandle\TransactionLevelLockHandle;
use Cog\DbLocker\TimeoutDuration;

final class PostgresAdvisoryLocker
{
    private const PG_SQLSTATE_LOCK_NOT_AVAILABLE = '55P03';

    /**
     * Acquire a transaction-level advisory lock with configurable timeout and access mode.
     *
     * @param TimeoutDuration $timeoutDuration Maximum wait time. Use TimeoutDuration::zero() for an immediate (non-blocking) attempt.
     */
    public function acquireTransactionLevelLock(
        ConnectionAdapterInterface $connection,
        PostgresLockKey $key,
        TimeoutDuration $timeoutDuration,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): TransactionLevelLockHandle {
        return new TransactionLevelLockHandle(
            lockKey: $key,
            accessMode: $accessMode,
            wasAcquired: $this->acquireLock(
                connection: $connection,
                key: $key,
                level: PostgresLockLevelEnum::Transaction,
                timeoutDuration: $timeoutDuration,
                accessMode: $accessMode,
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
     *
     * @param callable(SessionLevelLockHandle): TReturn $callback A callback that receives the lock handle.
     * @param TimeoutDuration $timeoutDuration Maximum wait time. Use TimeoutDuration::zero() for an immediate (non-blocking) attempt.
     * @return TReturn The return value of the callback.
     *
     * @see acquireTransactionLevelLock() for preferred locking strategy.
     *
     * @template TReturn
     */
    public function withinSessionLevelLock(
        ConnectionAdapterInterface $connection,
        PostgresLockKey $key,
        callable $callback,
        TimeoutDuration $timeoutDuration,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): mixed {
        $lockHandle = $this->acquireSessionLevelLock(
            connection: $connection,
            key: $key,
            timeoutDuration: $timeoutDuration,
            accessMode: $accessMode,
        );

        $exception = null;
        try {
            return $callback($lockHandle);
        } catch (\Throwable $e) {
            $exception = $e;
            throw $e;
        } finally {
            if ($lockHandle->wasAcquired) {
                try {
                    $this->releaseSessionLevelLock(
                        connection: $connection,
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
     *
     * @see acquireTransactionLevelLock() for preferred locking strategy.
     * @see withinSessionLevelLock() for automatic session lock management.
     */
    public function acquireSessionLevelLock(
        ConnectionAdapterInterface $connection,
        PostgresLockKey $key,
        TimeoutDuration $timeoutDuration,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): SessionLevelLockHandle {
        return new SessionLevelLockHandle(
            connection: $connection,
            locker: $this,
            lockKey: $key,
            accessMode: $accessMode,
            wasAcquired: $this->acquireLock(
                connection: $connection,
                key: $key,
                level: PostgresLockLevelEnum::Session,
                timeoutDuration: $timeoutDuration,
                accessMode: $accessMode,
            ),
        );
    }

    /**
     * Release session level advisory lock.
     */
    public function releaseSessionLevelLock(
        ConnectionAdapterInterface $connection,
        PostgresLockKey $key,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): bool {
        $sql = match ($accessMode) {
            PostgresLockAccessModeEnum::Exclusive
            => 'SELECT PG_ADVISORY_UNLOCK(:class_id, :object_id);',

            PostgresLockAccessModeEnum::Share
            => 'SELECT PG_ADVISORY_UNLOCK_SHARED(:class_id, :object_id);',
        };
        $sql .= " -- $key->humanReadableValue";

        return $connection->fetchColumn($sql, [
            'class_id' => $key->classId,
            'object_id' => $key->objectId,
        ]);
    }

    /**
     * Release all session level advisory locks held by the current session.
     */
    public function releaseAllSessionLevelLocks(
        ConnectionAdapterInterface $connection,
    ): void {
        $connection->execute('SELECT PG_ADVISORY_UNLOCK_ALL();');
    }

    private function acquireLock(
        ConnectionAdapterInterface $connection,
        PostgresLockKey $key,
        PostgresLockLevelEnum $level,
        TimeoutDuration $timeoutDuration,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): bool {
        if ($level === PostgresLockLevelEnum::Transaction && $connection->isTransactionActive() === false) {
            throw new \LogicException(
                "Transaction-level advisory lock `$key->humanReadableValue` cannot be acquired outside of transaction",
            );
        }

        return $timeoutDuration->toMilliseconds() === 0
            ? $this->tryAcquireLock(
                connection: $connection,
                key: $key,
                level: $level,
                accessMode: $accessMode,
            )
            : $this->acquireLockWithTimeout(
                connection: $connection,
                key: $key,
                level: $level,
                accessMode: $accessMode,
                timeoutDuration: $timeoutDuration,
            );
    }

    private function tryAcquireLock(
        ConnectionAdapterInterface $connection,
        PostgresLockKey $key,
        PostgresLockLevelEnum $level,
        PostgresLockAccessModeEnum $accessMode,
    ): bool {
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

        return $connection->fetchColumn($sql, [
            'class_id' => $key->classId,
            'object_id' => $key->objectId,
        ]);
    }

    private function acquireLockWithTimeout(
        ConnectionAdapterInterface $connection,
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
                connection: $connection,
                sql: $sql,
                key: $key,
                timeoutDuration: $timeoutDuration,
            ),
            PostgresLockLevelEnum::Session => $this->acquireSessionLockWithTimeout(
                connection: $connection,
                sql: $sql,
                key: $key,
                timeoutDuration: $timeoutDuration,
            ),
        };
    }

    /**
     * Check if an exception indicates a PostgreSQL lock_not_available error.
     *
     * This method supports multiple exception types:
     * - PDOException: SQLSTATE in getCode()
     * - Doctrine\DBAL\Exception: SQLSTATE in getSQLState()
     * - Cycle\Database\Exception: wraps PDOException as previous exception
     *
     * @param \Throwable $exception Exception to inspect
     * @return bool True if SQLSTATE is '55P03' (lock_not_available)
     */
    private function isLockNotAvailable(\Throwable $exception): bool
    {
        // PDOException: getCode() returns SQLSTATE string
        if ($exception instanceof \PDOException) {
            return $exception->getCode() === self::PG_SQLSTATE_LOCK_NOT_AVAILABLE;
        }

        // Doctrine\DBAL\Exception: getSQLState() method
        if (method_exists($exception, 'getSQLState')) {
            return $exception->getSQLState() === self::PG_SQLSTATE_LOCK_NOT_AVAILABLE;
        }

        // Cycle StatementException: wraps PDOException as previous
        $previous = $exception->getPrevious();
        if ($previous instanceof \PDOException) {
            return $previous->getCode() === self::PG_SQLSTATE_LOCK_NOT_AVAILABLE;
        }

        return false;
    }

    private function acquireTransactionLockWithTimeout(
        ConnectionAdapterInterface $connection,
        string $sql,
        PostgresLockKey $key,
        TimeoutDuration $timeoutDuration,
    ): bool {
        $timeoutMs = $timeoutDuration->toMilliseconds();
        $connection->execute("SET LOCAL lock_timeout = '$timeoutMs'");

        /**
         * Use a savepoint so that a lock_timeout error does not abort the entire transaction.
         * PostgreSQL handles same-name savepoints as a stack, so nested calls are safe.
         */
        $connection->execute('SAVEPOINT _lock_timeout_savepoint');

        try {
            $connection->execute($sql, [
                'class_id' => $key->classId,
                'object_id' => $key->objectId,
            ]);

            $connection->execute('RELEASE SAVEPOINT _lock_timeout_savepoint');

            return true;
        } catch (\Throwable $exception) {
            if ($this->isLockNotAvailable($exception)) {
                $connection->execute('ROLLBACK TO SAVEPOINT _lock_timeout_savepoint');

                return false;
            }

            throw $exception;
        }
    }

    private function acquireSessionLockWithTimeout(
        ConnectionAdapterInterface $connection,
        string $sql,
        PostgresLockKey $key,
        TimeoutDuration $timeoutDuration,
    ): bool {
        $timeoutMs = $timeoutDuration->toMilliseconds();
        $originalLockTimeout = $connection->fetchColumn('SHOW lock_timeout');
        $connection->execute("SET lock_timeout = '$timeoutMs'");

        try {
            $connection->execute($sql, [
                'class_id' => $key->classId,
                'object_id' => $key->objectId,
            ]);

            return true;
        } catch (\Throwable $exception) {
            if ($this->isLockNotAvailable($exception)) {
                return false;
            }

            throw $exception;
        }
        finally {
            $connection->execute("SET lock_timeout = '$originalLockTimeout'");
        }
    }
}
