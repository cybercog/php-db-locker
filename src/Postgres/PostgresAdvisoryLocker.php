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
     *
     * TODO: Cover with tests
     */
    public function acquireTransactionLevelLock(
        PDO $dbConnection,
        PostgresLockKey $postgresLockId,
        PostgresLockWaitModeEnum $waitMode = PostgresLockWaitModeEnum::NonBlocking,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): TransactionLevelLockHandle {
        return new TransactionLevelLockHandle(
            wasAcquired: $this->acquireLock(
                $dbConnection,
                $postgresLockId,
                PostgresLockLevelEnum::Transaction,
                $waitMode,
                $accessMode,
            ),
        );
    }

    /**
     * Acquire a session-level advisory lock with configurable wait and access modes.
     *
     * TODO: Write that transaction-level is recommended.
     * TODO: Cover with tests
     */
    public function acquireSessionLevelLock(
        PDO $dbConnection,
        PostgresLockKey $postgresLockId,
        PostgresLockWaitModeEnum $waitMode = PostgresLockWaitModeEnum::NonBlocking,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): SessionLevelLockHandle {
        return new SessionLevelLockHandle(
            $dbConnection,
            $this,
            $postgresLockId,
            $accessMode,
            wasAcquired: $this->acquireLock(
                $dbConnection,
                $postgresLockId,
                PostgresLockLevelEnum::Session,
                $waitMode,
                $accessMode,
            ),
        );
    }

    /**
     * Release session level advisory lock.
     */
    public function releaseSessionLevelLock(
        PDO $dbConnection,
        PostgresLockKey $postgresLockId,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): bool {
        $sql = match ($accessMode) {
            PostgresLockAccessModeEnum::Exclusive => 'SELECT PG_ADVISORY_UNLOCK(:class_id, :object_id);',
            PostgresLockAccessModeEnum::Share => 'SELECT PG_ADVISORY_UNLOCK_SHARED(:class_id, :object_id);',
        };
        $sql .= " -- $postgresLockId->humanReadableValue";

        $statement = $dbConnection->prepare($sql);
        $statement->execute(
            [
                'class_id' => $postgresLockId->classId,
                'object_id' => $postgresLockId->objectId,
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
        PostgresLockKey $postgresLockId,
        PostgresLockLevelEnum $level,
        PostgresLockWaitModeEnum $waitMode = PostgresLockWaitModeEnum::NonBlocking,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): bool {
        if ($level === PostgresLockLevelEnum::Transaction && $dbConnection->inTransaction() === false) {
            throw new LogicException(
                "Transaction-level advisory lock `$postgresLockId->humanReadableValue` cannot be acquired outside of transaction",
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
        $sql .= " -- $postgresLockId->humanReadableValue";

        $statement = $dbConnection->prepare($sql);
        $statement->execute(
            [
                'class_id' => $postgresLockId->classId,
                'object_id' => $postgresLockId->objectId,
            ],
        );

        return $statement->fetchColumn(0);
    }
}
