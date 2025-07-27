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
     * Acquire an advisory lock with configurable scope, mode and behavior.
     */
    public function acquireLock(
        PDO $dbConnection,
        PostgresLockId $postgresLockId,
        PostgresAdvisoryLockScopeEnum $scope = PostgresAdvisoryLockScopeEnum::Transaction,
        PostgresAdvisoryLockTypeEnum $type = PostgresAdvisoryLockTypeEnum::NonBlocking,
        PostgresLockModeEnum $mode = PostgresLockModeEnum::Exclusive,
    ): bool {
        if ($scope === PostgresAdvisoryLockScopeEnum::Transaction && $dbConnection->inTransaction() === false) {
            throw new LogicException(
                "Transaction-level advisory lock `$postgresLockId->humanReadableValue` cannot be acquired outside of transaction",
            );
        }

        $sql = match ([$scope, $type, $mode]) {
            [
                PostgresAdvisoryLockScopeEnum::Transaction,
                PostgresAdvisoryLockTypeEnum::NonBlocking,
                PostgresLockModeEnum::Exclusive,
            ] => 'SELECT PG_TRY_ADVISORY_XACT_LOCK(:class_id, :object_id);',
            [
                PostgresAdvisoryLockScopeEnum::Transaction,
                PostgresAdvisoryLockTypeEnum::Blocking,
                PostgresLockModeEnum::Exclusive,
            ] => 'SELECT PG_ADVISORY_XACT_LOCK(:class_id, :object_id);',
            [
                PostgresAdvisoryLockScopeEnum::Transaction,
                PostgresAdvisoryLockTypeEnum::NonBlocking,
                PostgresLockModeEnum::Share,
            ] => 'SELECT PG_TRY_ADVISORY_XACT_LOCK_SHARED(:class_id, :object_id);',
            [
                PostgresAdvisoryLockScopeEnum::Transaction,
                PostgresAdvisoryLockTypeEnum::Blocking,
                PostgresLockModeEnum::Share,
            ] => 'SELECT PG_ADVISORY_XACT_LOCK_SHARED(:class_id, :object_id);',
            [
                PostgresAdvisoryLockScopeEnum::Session,
                PostgresAdvisoryLockTypeEnum::NonBlocking,
                PostgresLockModeEnum::Exclusive,
            ] => 'SELECT PG_TRY_ADVISORY_LOCK(:class_id, :object_id);',
            [
                PostgresAdvisoryLockScopeEnum::Session,
                PostgresAdvisoryLockTypeEnum::Blocking,
                PostgresLockModeEnum::Exclusive,
            ] => 'SELECT PG_ADVISORY_LOCK(:class_id, :object_id);',
            [
                PostgresAdvisoryLockScopeEnum::Session,
                PostgresAdvisoryLockTypeEnum::NonBlocking,
                PostgresLockModeEnum::Share,
            ] => 'SELECT PG_TRY_ADVISORY_LOCK_SHARED(:class_id, :object_id);',
            [
                PostgresAdvisoryLockScopeEnum::Session,
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

    /**
     * Release session level advisory lock.
     */
    public function releaseLock(
        PDO $dbConnection,
        PostgresLockId $postgresLockId,
        PostgresAdvisoryLockScopeEnum $scope = PostgresAdvisoryLockScopeEnum::Session,
        PostgresLockModeEnum $mode = PostgresLockModeEnum::Exclusive,
    ): bool {
        if ($scope === PostgresAdvisoryLockScopeEnum::Transaction) {
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
    public function releaseAllLocks(
        PDO $dbConnection,
        PostgresAdvisoryLockScopeEnum $scope = PostgresAdvisoryLockScopeEnum::Session,
    ): void {
        if ($scope === PostgresAdvisoryLockScopeEnum::Transaction) {
            throw new \InvalidArgumentException('Transaction-level advisory lock cannot be released');
        }

        $statement = $dbConnection->prepare(
            <<<'SQL'
                SELECT PG_ADVISORY_UNLOCK_ALL();
                SQL,
        );
        $statement->execute();
    }
}
