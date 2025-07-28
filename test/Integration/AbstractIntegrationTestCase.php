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

use Cog\DbLocker\Postgres\Enum\PostgresLockAccessModeEnum;
use Cog\DbLocker\Postgres\PostgresLockId;
use PDO;
use PHPUnit\Framework\TestCase;

abstract class AbstractIntegrationTestCase extends TestCase
{
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
        PostgresLockAccessModeEnum $mode = PostgresLockAccessModeEnum::Exclusive,
    ): void {
        $row = $this->findPostgresAdvisoryLockInConnection(
            $dbConnection,
            $postgresLockId,
            $mode,
        );

        $lockIdString = $postgresLockId->humanReadableValue;

        $this->assertTrue(
            $row !== null,
            "Lock id `$lockIdString` does not exists",
        );
    }

    protected function assertPgAdvisoryLockMissingInConnection(
        PDO $dbConnection,
        PostgresLockId $postgresLockId,
        PostgresLockAccessModeEnum $mode = PostgresLockAccessModeEnum::Exclusive,
    ): void {
        $row = $this->findPostgresAdvisoryLockInConnection(
            $dbConnection,
            $postgresLockId,
            $mode,
        );

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
        PostgresLockAccessModeEnum $mode,
    ): object | null {
        $statement = $dbConnection->prepare(
            <<<'SQL'
                SELECT *
                FROM pg_locks
                WHERE locktype = 'advisory'
                AND classid = :lock_class_id
                AND objid = :lock_object_id
                AND objsubid = :lock_object_subid
                AND pid = :connection_pid
                AND mode = :mode
                SQL,
        );
        $statement->execute(
            [
                'lock_class_id' => $postgresLockId->classId,
                'lock_object_id' => $postgresLockId->objectId,
                'lock_object_subid' => 2, // Using two keyed locks
                'connection_pid' => $dbConnection->pgsqlGetPid(),
                'mode' => $mode->value,
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
                SQL,
        );
        $statement->execute();

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
