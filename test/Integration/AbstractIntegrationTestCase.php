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
    private const POSTGRES_BLOCK_SIZE = 4294967296;

    private const MODE_EXCLUSIVE = 'ExclusiveLock';
    private const MODE_SHARE = 'ShareLock';

    protected function tearDown(): void
    {
        $this->closeAllPostgresPdoConnections();

        parent::tearDown();
    }

    protected function initPostgresPdoConnection(): PDO
    {
        $dsn = implode(';', [
            'dbname=' . getenv('DB_POSTGRES_DATABASE'),
            'host=' . getenv('DB_POSTGRES_HOST'),
            'port=' . getenv('DB_POSTGRES_PORT'),
        ]);

        return new PDO(
            'pgsql:' . $dsn,
            getenv('DB_POSTGRES_USERNAME'),
            getenv('DB_POSTGRES_PASSWORD'),
        );
    }

    protected function assertPgAdvisoryLockExistsInConnection(
        PDO $dbConnection,
        PostgresLockId $postgresLockId,
    ): void {
        $row = $this->findPostgresAdvisoryLockInConnection($dbConnection, $postgresLockId);

        $lockIdString = $postgresLockId->humanReadableValue;

        $this->assertTrue(
            $row !== null,
            "Lock id `$lockIdString` does not exists",
        );
    }

    protected function assertPgAdvisoryLockMissingInConnection(
        PDO $dbConnection,
        PostgresLockId $postgresLockId,
    ): void {
        $row = $this->findPostgresAdvisoryLockInConnection($dbConnection, $postgresLockId);

        $lockIdString = $postgresLockId->humanReadableValue;

        $this->assertTrue(
            $row === null,
            "Lock id `$lockIdString` is present",
        );
    }

    protected function assertPgAdvisoryLocksCount(
        int $expectedCount,
    ): void {
        $rows = $this->findAllPostgresAdvisoryLocks();
        $rowsCount = count($rows);

        $this->assertSame(
            $expectedCount,
            $rowsCount,
            "Failed asserting that advisory locks actual count $rowsCount matches expected count $expectedCount.",
        );
    }

    private function findPostgresAdvisoryLockInConnection(
        PDO $dbConnection,
        PostgresLockId $postgresLockId,
    ): object | null {
        $id = $postgresLockId->id;

        $lockObjectId = $id % self::POSTGRES_BLOCK_SIZE;
        $lockCatalogId = ($id - $lockObjectId) / self::POSTGRES_BLOCK_SIZE;

        $statement = $dbConnection->prepare(
            <<<'SQL'
                SELECT *
                FROM pg_locks
                WHERE locktype = 'advisory'
                AND classid = :lock_catalog_id
                AND objid = :lock_object_id
                AND pid = :connection_pid
                AND mode = :mode
                SQL,
        );
        $statement->execute(
            [
                'lock_catalog_id' => $lockCatalogId,
                'lock_object_id' => $lockObjectId,
                'connection_pid' => $dbConnection->pgsqlGetPid(),
                'mode' => self::MODE_EXCLUSIVE,
            ],
        );

        $result = $statement->fetchObject();

        if ($result === false) {
            return null;
        }

        return $result;
    }

    private function findAllPostgresAdvisoryLocks(): array
    {
        $dbConnection = $this->initPostgresPdoConnection();

        $statement = $dbConnection->prepare(
            <<<'SQL'
                SELECT *
                FROM pg_locks
                WHERE locktype = 'advisory'
                AND mode = :mode
                SQL,
        );
        $statement->execute(
            [
                'mode' => self::MODE_EXCLUSIVE,
            ],
        );

        return $statement->fetchAll(PDO::FETCH_OBJ);
    }

    private function closeAllPostgresPdoConnections(): void
    {
        $this->initPostgresPdoConnection()->query(
            <<<'SQL'
            SELECT PG_TERMINATE_BACKEND(pid) 
            FROM pg_stat_activity
            WHERE pid <> PG_BACKEND_PID()
            SQL,
        );
    }
}
