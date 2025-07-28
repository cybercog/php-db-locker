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
     * Acquire a transaction-level advisory lock with a configurable acquisition type and mode.
     */
    public function acquireTransactionLevelLock(
        PDO $dbConnection,
        PostgresLockId $postgresLockId,
        PostgresAdvisoryLockTypeEnum $type = PostgresAdvisoryLockTypeEnum::NonBlocking,
        PostgresLockModeEnum $mode = PostgresLockModeEnum::Exclusive,
    ): bool {
        return $this->acquireLock(
            $dbConnection,
            $postgresLockId,
            PostgresAdvisoryLockLevelEnum::Transaction,
            $type,
            $mode,
        );
    }

    /**
     * Acquire a session-level advisory lock with a configurable acquisition type and mode.
     *
     * TODO: Write that transaction-level is recommended.
     */
    public function acquireSessionLevelLock(
        PDO $dbConnection,
        PostgresLockId $postgresLockId,
        PostgresAdvisoryLockTypeEnum $type = PostgresAdvisoryLockTypeEnum::NonBlocking,
        PostgresLockModeEnum $mode = PostgresLockModeEnum::Exclusive,
    ): bool {
        return $this->acquireLock(
            $dbConnection,
            $postgresLockId,
            PostgresAdvisoryLockLevelEnum::Session,
            $type,
            $mode,
        );
    }

    /**
     * Release session level advisory lock.
     */
    public function releaseSessionLevelLock(
        PDO $dbConnection,
        PostgresLockId $postgresLockId,
        PostgresAdvisoryLockLevelEnum $level = PostgresAdvisoryLockLevelEnum::Session,
        PostgresLockModeEnum $mode = PostgresLockModeEnum::Exclusive,
    ): bool {
        if ($level === PostgresAdvisoryLockLevelEnum::Transaction) {
            throw new \InvalidArgumentException('Transaction-level advisory lock cannot be released');
        }

        $sql = match ($mode) {
            PostgresLockModeEnum::Exclusive => 'SELECT PG_ADVISORY_UNLOCK(:class_id, :object_id);',
            PostgresLockModeEnum::Share => 'SELECT PG_ADVISORY_UNLOCK_SHARED(:class_id, :object_id);',
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
        PostgresAdvisoryLockTypeEnum $type = PostgresAdvisoryLockTypeEnum::NonBlocking,
        PostgresLockModeEnum $mode = PostgresLockModeEnum::Exclusive,
    ): bool {
        if ($level === PostgresAdvisoryLockLevelEnum::Transaction && $dbConnection->inTransaction() === false) {
            throw new LogicException(
                "Transaction-level advisory lock `$postgresLockId->humanReadableValue` cannot be acquired outside of transaction",
            );
        }

        $sql = match ([$level, $type, $mode]) {
            [
                PostgresAdvisoryLockLevelEnum::Transaction,
                PostgresAdvisoryLockTypeEnum::NonBlocking,
                PostgresLockModeEnum::Exclusive,
            ] => 'SELECT PG_TRY_ADVISORY_XACT_LOCK(:class_id, :object_id);',
            [
                PostgresAdvisoryLockLevelEnum::Transaction,
                PostgresAdvisoryLockTypeEnum::Blocking,
                PostgresLockModeEnum::Exclusive,
            ] => 'SELECT PG_ADVISORY_XACT_LOCK(:class_id, :object_id);',
            [
                PostgresAdvisoryLockLevelEnum::Transaction,
                PostgresAdvisoryLockTypeEnum::NonBlocking,
                PostgresLockModeEnum::Share,
            ] => 'SELECT PG_TRY_ADVISORY_XACT_LOCK_SHARED(:class_id, :object_id);',
            [
                PostgresAdvisoryLockLevelEnum::Transaction,
                PostgresAdvisoryLockTypeEnum::Blocking,
                PostgresLockModeEnum::Share,
            ] => 'SELECT PG_ADVISORY_XACT_LOCK_SHARED(:class_id, :object_id);',
            [
                PostgresAdvisoryLockLevelEnum::Session,
                PostgresAdvisoryLockTypeEnum::NonBlocking,
                PostgresLockModeEnum::Exclusive,
            ] => 'SELECT PG_TRY_ADVISORY_LOCK(:class_id, :object_id);',
            [
                PostgresAdvisoryLockLevelEnum::Session,
                PostgresAdvisoryLockTypeEnum::Blocking,
                PostgresLockModeEnum::Exclusive,
            ] => 'SELECT PG_ADVISORY_LOCK(:class_id, :object_id);',
            [
                PostgresAdvisoryLockLevelEnum::Session,
                PostgresAdvisoryLockTypeEnum::NonBlocking,
                PostgresLockModeEnum::Share,
            ] => 'SELECT PG_TRY_ADVISORY_LOCK_SHARED(:class_id, :object_id);',
            [
                PostgresAdvisoryLockLevelEnum::Session,
                PostgresAdvisoryLockTypeEnum::Blocking,
                PostgresLockModeEnum::Share,
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
