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

namespace Cog\Test\DbLocker\Integration\Locker;

use Cog\DbLocker\Locker\PostgresAdvisoryLocker;
use Cog\DbLocker\Locker\PostgresAdvisoryLockScopeEnum;
use Cog\DbLocker\LockId\PostgresLockId;
use Cog\Test\DbLocker\Integration\AbstractIntegrationTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

final class PostgresAdvisoryLockerTest extends AbstractIntegrationTestCase
{
    private const DB_INT32_VALUE_MIN = -2_147_483_648;
    private const DB_INT32_VALUE_MAX = 2_147_483_647;

    public function testItCanTryAcquireLockWithinSession(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromKeyValue('test');

        $isLockAcquired = $locker->acquireLock(
            $dbConnection,
            $postgresLockId,
            PostgresAdvisoryLockScopeEnum::Session,
        );

        $this->assertTrue($isLockAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId);
    }

    #[DataProvider('provideItCanTryAcquireLockFromIntKeysCornerCasesData')]
    public function testItCanTryAcquireLockFromIntKeysCornerCases(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromIntKeys(self::DB_INT32_VALUE_MIN, 0);

        $isLockAcquired = $locker->acquireLock(
            $dbConnection,
            $postgresLockId,
            PostgresAdvisoryLockScopeEnum::Session,
        );

        $this->assertTrue($isLockAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId);
    }

    public static function provideItCanTryAcquireLockFromIntKeysCornerCasesData(): array
    {
        return [
            'min class_id' => [
                self::DB_INT32_VALUE_MIN,
                0,
            ],
            'max class_id' => [
                self::DB_INT32_VALUE_MAX,
                0,
            ],
            'min object_id' => [
                0,
                self::DB_INT32_VALUE_MIN,
            ],
            'max object_id' => [
                0,
                self::DB_INT32_VALUE_MAX,
            ],
        ];
    }

    public function testItCanTryAcquireLockInSameConnectionOnlyOnce(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromKeyValue('test');

        $isLockAcquired1 = $locker->acquireLock(
            $dbConnection,
            $postgresLockId,
            PostgresAdvisoryLockScopeEnum::Session,
        );
        $isLockAcquired2 = $locker->acquireLock(
            $dbConnection,
            $postgresLockId,
            PostgresAdvisoryLockScopeEnum::Session,
        );

        $this->assertTrue($isLockAcquired1);
        $this->assertTrue($isLockAcquired2);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId);
    }

    public function testItCanTryAcquireMultipleLocksInOneConnection(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId1 = PostgresLockId::fromKeyValue('test1');
        $postgresLockId2 = PostgresLockId::fromKeyValue('test2');

        $isLock1Acquired = $locker->acquireLock(
            $dbConnection,
            $postgresLockId1,
            PostgresAdvisoryLockScopeEnum::Session,
        );
        $isLock2Acquired = $locker->acquireLock(
            $dbConnection,
            $postgresLockId2,
            PostgresAdvisoryLockScopeEnum::Session,
        );

        $this->assertTrue($isLock1Acquired);
        $this->assertTrue($isLock2Acquired);
        $this->assertPgAdvisoryLocksCount(2);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId2);
    }

    public function testItCannotAcquireSameLockInTwoConnections(): void
    {
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromKeyValue('test');
        $locker->acquireLock(
            $dbConnection1,
            $postgresLockId,
            PostgresAdvisoryLockScopeEnum::Session,
        );

        $isLockAcquired = $locker->acquireLock(
            $dbConnection2,
            $postgresLockId,
            PostgresAdvisoryLockScopeEnum::Session,
        );

        $this->assertFalse($isLockAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection2, $postgresLockId);
    }

    public function testItCanReleaseLock(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromKeyValue('test');
        $locker->acquireLock(
            $dbConnection,
            $postgresLockId,
            PostgresAdvisoryLockScopeEnum::Session,
        );

        $isLockReleased = $locker->releaseLockWithinSession($dbConnection, $postgresLockId);

        $this->assertTrue($isLockReleased);
        $this->assertPgAdvisoryLocksCount(0);
    }

    public function testItCanReleaseLockTwiceIfAcquiredTwice(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromKeyValue('test');
        $locker->acquireLock(
            $dbConnection,
            $postgresLockId,
            PostgresAdvisoryLockScopeEnum::Session,
        );
        $locker->acquireLock(
            $dbConnection,
            $postgresLockId,
            PostgresAdvisoryLockScopeEnum::Session,
        );

        $isLockReleased1 = $locker->releaseLockWithinSession($dbConnection, $postgresLockId);
        $isLockReleased2 = $locker->releaseLockWithinSession($dbConnection, $postgresLockId);

        $this->assertTrue($isLockReleased1);
        $this->assertTrue($isLockReleased2);
        $this->assertPgAdvisoryLocksCount(0);
    }

    public function testItCanTryAcquireLockInSecondConnectionAfterRelease(): void
    {
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromKeyValue('test');
        $locker->acquireLock(
            $dbConnection1,
            $postgresLockId,
            PostgresAdvisoryLockScopeEnum::Session,
        );
        $locker->releaseLockWithinSession(
            $dbConnection1,
            $postgresLockId,
        );

        $isLockAcquired = $locker->acquireLock(
            $dbConnection2,
            $postgresLockId,
            PostgresAdvisoryLockScopeEnum::Session,
        );

        $this->assertTrue($isLockAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection2, $postgresLockId);
    }

    public function testItCannotAcquireLockInSecondConnectionAfterOneReleaseTwiceLocked(): void
    {
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromKeyValue('test');
        $locker->acquireLock(
            $dbConnection1,
            $postgresLockId,
            PostgresAdvisoryLockScopeEnum::Session,
        );
        $locker->acquireLock(
            $dbConnection1,
            $postgresLockId,
            PostgresAdvisoryLockScopeEnum::Session,
        );

        $isLockReleased = $locker->releaseLockWithinSession($dbConnection1, $postgresLockId);
        $isLockAcquired = $locker->acquireLock(
            $dbConnection2,
            $postgresLockId,
            PostgresAdvisoryLockScopeEnum::Session,
        );

        $this->assertTrue($isLockReleased);
        $this->assertFalse($isLockAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection1, $postgresLockId);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection2, $postgresLockId);
    }

    public function testItCannotReleaseLockIfNotAcquired(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromKeyValue('test');

        $isLockReleased = $locker->releaseLockWithinSession($dbConnection, $postgresLockId);

        $this->assertFalse($isLockReleased);
        $this->assertPgAdvisoryLocksCount(0);
    }

    public function testItCannotReleaseLockIfAcquiredInOtherConnection(): void
    {
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromKeyValue('test');
        $locker->acquireLock(
            $dbConnection1,
            $postgresLockId,
            PostgresAdvisoryLockScopeEnum::Session,
        );

        $isLockReleased = $locker->releaseLockWithinSession($dbConnection2, $postgresLockId);

        $this->assertFalse($isLockReleased);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection1, $postgresLockId);
    }

    public function testItCanReleaseAllLocksInConnection(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $locker->acquireLock(
            $dbConnection,
            PostgresLockId::fromKeyValue('test'),
            PostgresAdvisoryLockScopeEnum::Session,
        );
        $locker->acquireLock(
            $dbConnection,
            PostgresLockId::fromKeyValue('test2'),
            PostgresAdvisoryLockScopeEnum::Session,
        );

        $locker->releaseAllLocksWithinSession($dbConnection);

        $this->assertPgAdvisoryLocksCount(0);
    }

    public function testItCanReleaseAllLocksInConnectionIfNoLocksWereAcquired(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();

        $locker->releaseAllLocksWithinSession($dbConnection);

        $this->assertPgAdvisoryLocksCount(0);
    }

    public function testItCanReleaseAllLocksInConnectionButKeepsOtherLocks(): void
    {
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $postgresLockId1 = PostgresLockId::fromKeyValue('test');
        $postgresLockId2 = PostgresLockId::fromKeyValue('test2');
        $postgresLockId3 = PostgresLockId::fromKeyValue('test3');
        $postgresLockId4 = PostgresLockId::fromKeyValue('test4');
        $locker->acquireLock(
            $dbConnection1,
            $postgresLockId1,
            PostgresAdvisoryLockScopeEnum::Session,
        );
        $locker->acquireLock(
            $dbConnection1,
            $postgresLockId2,
            PostgresAdvisoryLockScopeEnum::Session,
        );
        $locker->acquireLock(
            $dbConnection2,
            $postgresLockId3,
            PostgresAdvisoryLockScopeEnum::Session,
        );
        $locker->acquireLock(
            $dbConnection2,
            $postgresLockId4,
            PostgresAdvisoryLockScopeEnum::Session,
        );

        $locker->releaseAllLocksWithinSession($dbConnection1);

        $this->assertPgAdvisoryLocksCount(2);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection2, $postgresLockId3);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection2, $postgresLockId4);
    }

    public function testItCanTryAcquireLockWithinTransaction(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromKeyValue('test');
        $dbConnection->beginTransaction();

        $isLockAcquired = $locker->acquireLock(
            $dbConnection,
            $postgresLockId,
            PostgresAdvisoryLockScopeEnum::Session,
        );

        $this->assertTrue($isLockAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId);
    }

    public function testItCannotAcquireLockWithinTransactionNotInTransaction(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Transaction-level advisory lock `test` cannot be acquired outside of transaction',
        );

        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromKeyValue('test');

        $locker->acquireLock(
            $dbConnection,
            $postgresLockId,
            PostgresAdvisoryLockScopeEnum::Transaction,
        );
    }

    public function testItCannotAcquireLockInSecondConnectionIfTakenWithinTransaction(): void
    {
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromKeyValue('test');
        $dbConnection1->beginTransaction();
        $locker->acquireLock(
            $dbConnection1,
            $postgresLockId,
            PostgresAdvisoryLockScopeEnum::Session,
        );

        $isLockAcquired = $locker->acquireLock(
            $dbConnection2,
            $postgresLockId,
            PostgresAdvisoryLockScopeEnum::Session,
        );

        $this->assertFalse($isLockAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection1, $postgresLockId);
    }

    public function testItCanAutoReleaseLockAcquiredWithinTransactionOnCommit(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromKeyValue('test');
        $dbConnection->beginTransaction();
        $locker->acquireLock(
            $dbConnection,
            $postgresLockId,
            PostgresAdvisoryLockScopeEnum::Transaction,
        );

        $dbConnection->commit();

        $this->assertPgAdvisoryLocksCount(0);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection, $postgresLockId);
    }

    public function testItCanAutoReleaseLockAcquiredWithinTransactionOnRollback(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromKeyValue('test');
        $dbConnection->beginTransaction();
        $locker->acquireLock(
            $dbConnection,
            $postgresLockId,
            PostgresAdvisoryLockScopeEnum::Transaction,
        );

        $dbConnection->rollBack();

        $this->assertPgAdvisoryLocksCount(0);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection, $postgresLockId);
    }

    public function testItCanAutoReleaseLockAcquiredWithinTransactionOnConnectionKill(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromKeyValue('test');
        $dbConnection->beginTransaction();
        $locker->acquireLock(
            $dbConnection,
            $postgresLockId,
            PostgresAdvisoryLockScopeEnum::Transaction,
        );

        $dbConnection = null;

        $this->assertPgAdvisoryLocksCount(0);
    }

    public function testItCannotReleaseLockAcquiredWithinTransaction(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromKeyValue('test');
        $dbConnection->beginTransaction();
        $locker->acquireLock(
            $dbConnection,
            $postgresLockId,
            PostgresAdvisoryLockScopeEnum::Transaction,
        );

        $isLockReleased = $locker->releaseLockWithinSession($dbConnection, $postgresLockId);

        $this->assertFalse($isLockReleased);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId);
    }

    public function testItCannotReleaseAllLocksAcquiredWithinTransaction(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId1 = PostgresLockId::fromKeyValue('test');
        $postgresLockId2 = PostgresLockId::fromKeyValue('test2');
        $locker->acquireLock(
            $dbConnection,
            $postgresLockId1,
            PostgresAdvisoryLockScopeEnum::Session,
        );
        $dbConnection->beginTransaction();
        $locker->acquireLock(
            $dbConnection,
            $postgresLockId2,
            PostgresAdvisoryLockScopeEnum::Transaction,
        );

        $locker->releaseAllLocksWithinSession($dbConnection);

        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection, $postgresLockId1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId2);
    }

    private function initLocker(): PostgresAdvisoryLocker
    {
        return new PostgresAdvisoryLocker();
    }
}
