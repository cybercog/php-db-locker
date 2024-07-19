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
    public function tryAcquireLock(
        PDO $dbConnection,
        PostgresLockId $postgresLockId,
    ): bool {
        // TODO: Need to cleanup humanReadableValue?
        $statement = $dbConnection->prepare(
            <<<SQL
            SELECT pg_try_advisory_lock(:lock_id); -- $postgresLockId->humanReadableValue
            SQL
        );
        $statement->execute(
            [
                'lock_id' => $postgresLockId->id,
            ]
        );

        return $statement->fetchColumn(0);
    }

    public function tryAcquireLockWithinTransaction(
        PDO $dbConnection,
        PostgresLockId $postgresLockId,
    ): bool {
        if ($dbConnection->inTransaction() === false) {
            $lockId = $postgresLockId->humanReadableValue;

            throw new LogicException(
                "Transaction-level advisory lock `$lockId` cannot be acquired outside of transaction"
            );
        }

        // TODO: Need to cleanup humanReadableValue?
        $statement = $dbConnection->prepare(
            <<<SQL
            SELECT pg_try_advisory_xact_lock(:lock_id); -- $postgresLockId->humanReadableValue
            SQL
        );
        $statement->execute(
            [
                'lock_id' => $postgresLockId->id,
            ]
        );

        return $statement->fetchColumn(0);
    }

    public function releaseLock(
        PDO $dbConnection,
        PostgresLockId $postgresLockId,
    ): bool {
        $statement = $dbConnection->prepare(
            <<<'SQL'
            SELECT pg_advisory_unlock(:lock_id);
            SQL
        );
        $statement->execute(
            [
                'lock_id' => $postgresLockId->id,
            ]
        );

        return $statement->fetchColumn(0);
    }

    public function releaseAllLocks(
        PDO $dbConnection,
    ): void {
        $statement = $dbConnection->prepare(
            <<<'SQL'
            SELECT pg_advisory_unlock_all();
            SQL
        );
        $statement->execute();
    }
}
