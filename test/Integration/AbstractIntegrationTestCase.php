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

namespace Cog\Test\DbLocker\Integration;

use Cog\DbLocker\LockId\PostgresLockId;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class AbstractIntegrationTestCase extends TestCase
{
    protected function createPostgresPdoConnection(): PDO
    {
        $dsn = implode(';', [
            'dbname=' . $_ENV['DB_POSTGRES_DATABASE'],
            'host=' . $_ENV['DB_POSTGRES_HOST'],
            'port=' . $_ENV['DB_POSTGRES_PORT'],
        ]);

        return new PDO(
            'pgsql:' . $dsn,
            $_ENV['DB_POSTGRES_USERNAME'],
            $_ENV['DB_POSTGRES_PASSWORD'],
        );
    }

    protected function assertPgAdvisoryLockExistsInConnection(
        PDO $dbConnection,
        PostgresLockId $postgresLockId
    ): void {
        $row = $this->findPostgresAdvisoryLockInConnection($dbConnection, $postgresLockId);

        $lockIdString = $postgresLockId->humanReadableValue();

        $this->assertTrue(
            $row !== null,
            "Lock id `$lockIdString` does not exists"
        );
    }

    protected function assertPgAdvisoryLockExistsInTransaction(
        PDO $dbConnection,
        PostgresLockId $postgresLockId
    ): void {
        $row = $this->findPostgresAdvisoryLockInConnection($dbConnection, $postgresLockId);

        $lockIdString = $postgresLockId->humanReadableValue();

        $this->assertTrue(
            $row !== null,
            "Lock id `$lockIdString` does not exists"
        );
    }

    protected function assertPgAdvisoryLockMissingInConnection(
        PDO $dbConnection,
        PostgresLockId $postgresLockId
    ): void {
        $row = $this->findPostgresAdvisoryLockInConnection($dbConnection, $postgresLockId);

        $lockIdString = $postgresLockId->humanReadableValue();

        $this->assertTrue(
            $row === null,
            "Lock id `$lockIdString` is present"
        );
    }

    protected function assertPgAdvisoryLocksCount(
        int $expectedCount
    ): void {
        $rows = $this->findAllPostgresAdvisoryLocks();
        $rowsCount = count($rows);

        $this->assertSame(
            $expectedCount,
            $rowsCount,
            "Failed asserting that advisory locks actual count $rowsCount matches expected count $expectedCount."
        );
    }

    private function findPostgresAdvisoryLockInConnection(
        PDO $dbConnection,
        PostgresLockId $postgresLockId
    ): ?object {
        $statement = $dbConnection->prepare(
            <<<SQL
                SELECT *
                FROM pg_locks
                WHERE locktype = 'advisory'
                AND objid = :lock_id
                AND pid = :connection_pid
                AND mode = 'ExclusiveLock'
            SQL
        );
        $statement->execute(
            [
                'lock_id' => $postgresLockId->id(),
                'connection_pid' => $dbConnection->pgsqlGetPid(),
            ]
        );

        $result = $statement->fetchObject();

        if ($result === false) {
            return null;
        }

        return $result;
    }

    private function findAllPostgresAdvisoryLocks(): array
    {
        $dbConnection = $this->createPostgresPdoConnection();

        $statement = $dbConnection->prepare(
            <<<SQL
                SELECT *
                FROM pg_locks
                WHERE locktype = 'advisory'
                AND mode = 'ExclusiveLock'
            SQL
        );
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_OBJ);
    }
}