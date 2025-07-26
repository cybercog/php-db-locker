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
     * Acquire transaction-level lock (recommended).
     */
    public function acquireLockWithinTransaction(
        PDO $dbConnection,
        PostgresLockId $postgresLockId,
        PostgresLockModeEnum $lockMode = PostgresLockModeEnum::Try,
    ): bool {
        if ($dbConnection->inTransaction() === false) {
            $lockId = $postgresLockId->humanReadableValue;

            throw new LogicException(
                "Transaction-level advisory lock `$lockId` cannot be acquired outside of transaction",
            );
        }

        $sql = match ($lockMode) {
            PostgresLockModeEnum::Try => 'SELECT PG_TRY_ADVISORY_XACT_LOCK(:class_id, :object_id); -- ' . $postgresLockId->humanReadableValue,
            PostgresLockModeEnum::Block => 'SELECT PG_ADVISORY_XACT_LOCK(:class_id, :object_id); -- ' . $postgresLockId->humanReadableValue,
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
     * Acquire session-level lock (use only if transaction-level lock not applicable).
     */
    public function acquireLockWithinSession(
        PDO $dbConnection,
        PostgresLockId $postgresLockId,
        PostgresLockModeEnum $lockMode = PostgresLockModeEnum::Try,
    ): bool {
        $sql = match ($lockMode) {
            PostgresLockModeEnum::Try => 'SELECT PG_TRY_ADVISORY_LOCK(:class_id, :object_id); -- ' . $postgresLockId->humanReadableValue,
            PostgresLockModeEnum::Block => 'SELECT PG_ADVISORY_LOCK(:class_id, :object_id); -- ' . $postgresLockId->humanReadableValue,
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
