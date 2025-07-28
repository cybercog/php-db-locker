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

namespace Cog\DbLocker\Locker;

use Cog\DbLocker\LockId\PostgresLockId;
use LogicException;
use PDO;

final class PostgresAdvisoryLocker
{
    /**
     * Acquire a transaction-level advisory lock with configurable wait and access modes.
     *
     * TODO: Cover with tests
     */
    public function acquireTransactionLevelLockHandler(
        PDO $dbConnection,
        PostgresLockId $postgresLockId,
        PostgresAdvisoryLockWaitModeEnum $waitMode = PostgresAdvisoryLockWaitModeEnum::NonBlocking,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): AdvisoryLockTransactionLevel {
        return new AdvisoryLockTransactionLevel(
            wasAcquired: $this->acquireLock(
                $dbConnection,
                $postgresLockId,
                PostgresAdvisoryLockLevelEnum::Transaction,
                $waitMode,
                $accessMode,
            ),
        );
    }

    /**
     * Acquire a transaction-level advisory lock with configurable wait and access modes.
     */
    public function acquireTransactionLevelLock(
        PDO $dbConnection,
        PostgresLockId $postgresLockId,
        PostgresAdvisoryLockWaitModeEnum $waitMode = PostgresAdvisoryLockWaitModeEnum::NonBlocking,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): bool {
        return $this->acquireLock(
            $dbConnection,
            $postgresLockId,
            PostgresAdvisoryLockLevelEnum::Transaction,
            $waitMode,
            $accessMode,
        );
    }

    /**
     * Acquire a session-level advisory lock with configurable wait and access modes.
     *
     * TODO: Write that transaction-level is recommended.
     * TODO: Cover with tests
     */
    public function acquireSessionLevelLockHandler(
        PDO $dbConnection,
        PostgresLockId $postgresLockId,
        PostgresAdvisoryLockWaitModeEnum $waitMode = PostgresAdvisoryLockWaitModeEnum::NonBlocking,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): AdvisoryLockSessionLevel {
        return new AdvisoryLockSessionLevel(
            $dbConnection,
            $this,
            $postgresLockId,
            $accessMode,
            wasAcquired: $this->acquireLock(
                $dbConnection,
                $postgresLockId,
                PostgresAdvisoryLockLevelEnum::Session,
                $waitMode,
                $accessMode,
            ),
        );
    }

    /**
     * Acquire a session-level advisory lock with configurable wait and access modes.
     *
     * TODO: Write that transaction-level is recommended.
     */
    public function acquireSessionLevelLock(
        PDO $dbConnection,
        PostgresLockId $postgresLockId,
        PostgresAdvisoryLockWaitModeEnum $waitMode = PostgresAdvisoryLockWaitModeEnum::NonBlocking,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): bool {
        return $this->acquireLock(
            $dbConnection,
            $postgresLockId,
            PostgresAdvisoryLockLevelEnum::Session,
            $waitMode,
            $accessMode,
        );
    }

    /**
     * Release session level advisory lock.
     */
    public function releaseSessionLevelLock(
        PDO $dbConnection,
        PostgresLockId $postgresLockId,
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
        PostgresLockId $postgresLockId,
        PostgresAdvisoryLockLevelEnum $level,
        PostgresAdvisoryLockWaitModeEnum $waitMode = PostgresAdvisoryLockWaitModeEnum::NonBlocking,
        PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
    ): bool {
        if ($level === PostgresAdvisoryLockLevelEnum::Transaction && $dbConnection->inTransaction() === false) {
            throw new LogicException(
                "Transaction-level advisory lock `$postgresLockId->humanReadableValue` cannot be acquired outside of transaction",
            );
        }

        $sql = match ([$level, $waitMode, $accessMode]) {
            [
                PostgresAdvisoryLockLevelEnum::Transaction,
                PostgresAdvisoryLockWaitModeEnum::NonBlocking,
                PostgresLockAccessModeEnum::Exclusive,
            ] => 'SELECT PG_TRY_ADVISORY_XACT_LOCK(:class_id, :object_id);',
            [
                PostgresAdvisoryLockLevelEnum::Transaction,
                PostgresAdvisoryLockWaitModeEnum::Blocking,
                PostgresLockAccessModeEnum::Exclusive,
            ] => 'SELECT PG_ADVISORY_XACT_LOCK(:class_id, :object_id);',
            [
                PostgresAdvisoryLockLevelEnum::Transaction,
                PostgresAdvisoryLockWaitModeEnum::NonBlocking,
                PostgresLockAccessModeEnum::Share,
            ] => 'SELECT PG_TRY_ADVISORY_XACT_LOCK_SHARED(:class_id, :object_id);',
            [
                PostgresAdvisoryLockLevelEnum::Transaction,
                PostgresAdvisoryLockWaitModeEnum::Blocking,
                PostgresLockAccessModeEnum::Share,
            ] => 'SELECT PG_ADVISORY_XACT_LOCK_SHARED(:class_id, :object_id);',
            [
                PostgresAdvisoryLockLevelEnum::Session,
                PostgresAdvisoryLockWaitModeEnum::NonBlocking,
                PostgresLockAccessModeEnum::Exclusive,
            ] => 'SELECT PG_TRY_ADVISORY_LOCK(:class_id, :object_id);',
            [
                PostgresAdvisoryLockLevelEnum::Session,
                PostgresAdvisoryLockWaitModeEnum::Blocking,
                PostgresLockAccessModeEnum::Exclusive,
            ] => 'SELECT PG_ADVISORY_LOCK(:class_id, :object_id);',
            [
                PostgresAdvisoryLockLevelEnum::Session,
                PostgresAdvisoryLockWaitModeEnum::NonBlocking,
                PostgresLockAccessModeEnum::Share,
            ] => 'SELECT PG_TRY_ADVISORY_LOCK_SHARED(:class_id, :object_id);',
            [
                PostgresAdvisoryLockLevelEnum::Session,
                PostgresAdvisoryLockWaitModeEnum::Blocking,
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
