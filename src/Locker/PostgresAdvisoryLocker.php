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
     * Acquire an advisory lock with configurable scope and mode.
     */
    public function acquireLock(
        PDO $dbConnection,
        PostgresLockId $postgresLockId,
        PostgresAdvisoryLockScopeEnum $scope = PostgresAdvisoryLockScopeEnum::Transaction,
        PostgresAdvisoryLockModeEnum $mode = PostgresAdvisoryLockModeEnum::Try,
    ): bool {
        if ($scope === PostgresAdvisoryLockScopeEnum::Transaction && $dbConnection->inTransaction() === false) {
            throw new LogicException(
                "Transaction-level advisory lock `$postgresLockId->humanReadableValue` cannot be acquired outside of transaction",
            );
        }

        $sql = match ([$scope, $mode]) {
            [PostgresAdvisoryLockScopeEnum::Transaction, PostgresAdvisoryLockModeEnum::Try] =>
                'SELECT PG_TRY_ADVISORY_XACT_LOCK(:class_id, :object_id); -- ' . $postgresLockId->humanReadableValue,
            [PostgresAdvisoryLockScopeEnum::Transaction, PostgresAdvisoryLockModeEnum::Block] =>
                'SELECT PG_ADVISORY_XACT_LOCK(:class_id, :object_id); -- ' . $postgresLockId->humanReadableValue,
            [PostgresAdvisoryLockScopeEnum::Session, PostgresAdvisoryLockModeEnum::Try] =>
                'SELECT PG_TRY_ADVISORY_LOCK(:class_id, :object_id); -- ' . $postgresLockId->humanReadableValue,
            [PostgresAdvisoryLockScopeEnum::Session, PostgresAdvisoryLockModeEnum::Block] =>
                'SELECT PG_ADVISORY_LOCK(:class_id, :object_id); -- ' . $postgresLockId->humanReadableValue,
        };

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
     * Release session-level lock.
     */
    public function releaseLockWithinSession(
        PDO $dbConnection,
        PostgresLockId $postgresLockId,
    ): bool {
        $statement = $dbConnection->prepare(
            <<<SQL
                SELECT PG_ADVISORY_UNLOCK(:class_id, :object_id); -- $postgresLockId->humanReadableValue
                SQL,
        );
        $statement->execute(
            [
                'class_id' => $postgresLockId->classId,
                'object_id' => $postgresLockId->objectId,
            ],
        );

        return $statement->fetchColumn(0);
    }

    /**
     * Release all session-level locks.
     */
    public function releaseAllLocksWithinSession(
        PDO $dbConnection,
    ): void {
        $statement = $dbConnection->prepare(
            <<<'SQL'
                SELECT PG_ADVISORY_UNLOCK_ALL();
                SQL,
        );
        $statement->execute();
    }
}
