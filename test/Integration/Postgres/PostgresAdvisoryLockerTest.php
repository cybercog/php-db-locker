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

namespace Cog\Test\DbLocker\Integration\Postgres;

use Cog\DbLocker\Postgres\Enum\PostgresLockAccessModeEnum;
use Cog\DbLocker\Postgres\PostgresAdvisoryLocker;
use Cog\DbLocker\Postgres\PostgresLockId;
use Cog\Test\DbLocker\Integration\AbstractIntegrationTestCase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

final class PostgresAdvisoryLockerTest extends AbstractIntegrationTestCase
{
    private const DB_INT32_VALUE_MIN = -2_147_483_648;
    private const DB_INT32_VALUE_MAX = 2_147_483_647;

    #[DataProvider('provideItCanTryAcquireLockWithinSessionData')]
    public function testItCanTryAcquireLockWithinSession(
        PostgresLockAccessModeEnum $accessMode,
    ): void {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromKeyValue('test');

        $isLockAcquired = $locker->acquireSessionLevelLock(
            $dbConnection,
            $postgresLockId,
            accessMode: $accessMode,
        );

        $this->assertTrue($isLockAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId, $accessMode);
    }

    public static function provideItCanTryAcquireLockWithinSessionData(): array
    {
        return [
            'exclusive lock' => [
                PostgresLockAccessModeEnum::Exclusive,
            ],
            'share lock' => [
                PostgresLockAccessModeEnum::Share,
            ],
        ];
    }

    #[DataProvider('provideItCanTryAcquireLockWithinTransactionData')]
    public function testItCanTryAcquireLockWithinTransaction(
        PostgresLockAccessModeEnum $accessMode,
    ): void {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromKeyValue('test');
        $dbConnection->beginTransaction();

        $isLockAcquired = $locker->acquireTransactionLevelLock(
            $dbConnection,
            $postgresLockId,
            accessMode: $accessMode,
        );

        $this->assertTrue($isLockAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId, $accessMode);
    }

    public static function provideItCanTryAcquireLockWithinTransactionData(): array
    {
        return [
            'exclusive lock' => [
                PostgresLockAccessModeEnum::Exclusive,
            ],
            'share lock' => [
                PostgresLockAccessModeEnum::Share,
            ],
        ];
    }

    #[DataProvider('provideItCanTryAcquireLockFromIntKeysCornerCasesData')]
    public function testItCanTryAcquireLockFromIntKeysCornerCases(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromIntKeys(self::DB_INT32_VALUE_MIN, 0);

        $isLockAcquired = $locker->acquireSessionLevelLock(
            $dbConnection,
            $postgresLockId,
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

        $isLockAcquired1 = $locker->acquireSessionLevelLock(
            $dbConnection,
            $postgresLockId,
        );
        $isLockAcquired2 = $locker->acquireSessionLevelLock(
            $dbConnection,
            $postgresLockId,
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

        $isLock1Acquired = $locker->acquireSessionLevelLock(
            $dbConnection,
            $postgresLockId1,
        );
        $isLock2Acquired = $locker->acquireSessionLevelLock(
            $dbConnection,
            $postgresLockId2,
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
        $locker->acquireSessionLevelLock(
            $dbConnection1,
            $postgresLockId,
        );

        $isLockAcquired = $locker->acquireSessionLevelLock(
            $dbConnection2,
            $postgresLockId,
        );

        $this->assertFalse($isLockAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection2, $postgresLockId);
    }

    #[DataProvider('provideItCanReleaseLockData')]
    public function testItCanReleaseLock(
        PostgresLockAccessModeEnum $accessMode,
    ): void {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromKeyValue('test');
        $locker->acquireSessionLevelLock(
            $dbConnection,
            $postgresLockId,
            accessMode: $accessMode,
        );

        $isLockReleased = $locker->releaseSessionLevelLock(
            $dbConnection,
            $postgresLockId,
            accessMode: $accessMode,
        );

        $this->assertTrue($isLockReleased);
        $this->assertPgAdvisoryLocksCount(0);
    }

    public static function provideItCanReleaseLockData(): array
    {
        return [
            'exclusive lock' => [
                PostgresLockAccessModeEnum::Exclusive,
            ],
            'share lock' => [
                PostgresLockAccessModeEnum::Share,
            ],
        ];
    }

    #[DataProvider('provideItCanNotReleaseLockOfDifferentModesData')]
    public function testItCanNotReleaseLockOfDifferentModes(
        PostgresLockAccessModeEnum $acquireMode,
        PostgresLockAccessModeEnum $releaseMode,
    ): void {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromKeyValue('test');
        $locker->acquireSessionLevelLock(
            $dbConnection,
            $postgresLockId,
            accessMode: $acquireMode,
        );

        $isLockReleased = $locker->releaseSessionLevelLock(
            $dbConnection,
            $postgresLockId,
            accessMode: $releaseMode,
        );

        $this->assertFalse($isLockReleased);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId, $acquireMode);
    }

    public static function provideItCanNotReleaseLockOfDifferentModesData(): array
    {
        return [
            'release exclusive lock as share' => [
                'acquireAccessMode' => PostgresLockAccessModeEnum::Exclusive,
                'releaseAccessMode' => PostgresLockAccessModeEnum::Share,
            ],
            'release share lock as exclusive' => [
                'acquireAccessMode' => PostgresLockAccessModeEnum::Share,
                'releaseAccessMode' => PostgresLockAccessModeEnum::Exclusive,
            ],
        ];
    }

    public function testItCanReleaseLockTwiceIfAcquiredTwice(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromKeyValue('test');
        $locker->acquireSessionLevelLock(
            $dbConnection,
            $postgresLockId,
        );
        $locker->acquireSessionLevelLock(
            $dbConnection,
            $postgresLockId,
        );

        $isLockReleased1 = $locker->releaseSessionLevelLock($dbConnection, $postgresLockId);
        $isLockReleased2 = $locker->releaseSessionLevelLock($dbConnection, $postgresLockId);

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
        $locker->acquireSessionLevelLock(
            $dbConnection1,
            $postgresLockId,
        );
        $locker->releaseSessionLevelLock(
            $dbConnection1,
            $postgresLockId,
        );

        $isLockAcquired = $locker->acquireSessionLevelLock(
            $dbConnection2,
            $postgresLockId,
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
        $locker->acquireSessionLevelLock(
            $dbConnection1,
            $postgresLockId,
        );
        $locker->acquireSessionLevelLock(
            $dbConnection1,
            $postgresLockId,
        );

        $isLockReleased = $locker->releaseSessionLevelLock($dbConnection1, $postgresLockId);
        $isLockAcquired = $locker->acquireSessionLevelLock(
            $dbConnection2,
            $postgresLockId,
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

        $isLockReleased = $locker->releaseSessionLevelLock($dbConnection, $postgresLockId);

        $this->assertFalse($isLockReleased);
        $this->assertPgAdvisoryLocksCount(0);
    }

    public function testItCannotReleaseLockIfAcquiredInOtherConnection(): void
    {
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromKeyValue('test');
        $locker->acquireSessionLevelLock(
            $dbConnection1,
            $postgresLockId,
        );

        $isLockReleased = $locker->releaseSessionLevelLock($dbConnection2, $postgresLockId);

        $this->assertFalse($isLockReleased);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection1, $postgresLockId);
    }

    public function testItCanReleaseAllLocksInConnection(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $locker->acquireSessionLevelLock(
            $dbConnection,
            PostgresLockId::fromKeyValue('test'),
        );
        $locker->acquireSessionLevelLock(
            $dbConnection,
            PostgresLockId::fromKeyValue('test'),
        );
        $locker->acquireSessionLevelLock(
            $dbConnection,
            PostgresLockId::fromKeyValue('test2'),
        );

        $locker->releaseAllSessionLevelLocks($dbConnection);

        $this->assertPgAdvisoryLocksCount(0);
    }

    public function testItCanReleaseAllLocksInConnectionIfNoLocksWereAcquired(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();

        $locker->releaseAllSessionLevelLocks($dbConnection);

        $this->assertPgAdvisoryLocksCount(0);
    }

    public function testItCanReleaseAllLocksInConnectionButKeepsOtherConnectionLocks(): void
    {
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $postgresLockId1 = PostgresLockId::fromKeyValue('test');
        $postgresLockId2 = PostgresLockId::fromKeyValue('test2');
        $postgresLockId3 = PostgresLockId::fromKeyValue('test3');
        $postgresLockId4 = PostgresLockId::fromKeyValue('test4');
        $locker->acquireSessionLevelLock(
            $dbConnection1,
            $postgresLockId1,
        );
        $locker->acquireSessionLevelLock(
            $dbConnection1,
            $postgresLockId2,
        );
        $locker->acquireSessionLevelLock(
            $dbConnection2,
            $postgresLockId3,
        );
        $locker->acquireSessionLevelLock(
            $dbConnection2,
            $postgresLockId4,
        );

        $locker->releaseAllSessionLevelLocks($dbConnection1);

        $this->assertPgAdvisoryLocksCount(2);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection2, $postgresLockId3);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection2, $postgresLockId4);
    }

    public function testItCannotAcquireLockWithinTransactionNotInTransaction(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Transaction-level advisory lock `test:` cannot be acquired outside of transaction',
        );

        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromKeyValue('test');

        $locker->acquireTransactionLevelLock(
            $dbConnection,
            $postgresLockId,
        );
    }

    public function testItCannotAcquireLockInSecondConnectionIfTakenWithinTransaction(): void
    {
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $postgresLockId = PostgresLockId::fromKeyValue('test');
        $dbConnection1->beginTransaction();
        $locker->acquireSessionLevelLock(
            $dbConnection1,
            $postgresLockId,
        );

        $isLockAcquired = $locker->acquireSessionLevelLock(
            $dbConnection2,
            $postgresLockId,
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
        $locker->acquireTransactionLevelLock(
            $dbConnection,
            $postgresLockId,
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
        $locker->acquireTransactionLevelLock(
            $dbConnection,
            $postgresLockId,
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
        $locker->acquireTransactionLevelLock(
            $dbConnection,
            $postgresLockId,
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
        $locker->acquireTransactionLevelLock(
            $dbConnection,
            $postgresLockId,
        );

        $isLockReleased = $locker->releaseSessionLevelLock($dbConnection, $postgresLockId);

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
        $locker->acquireSessionLevelLock(
            $dbConnection,
            $postgresLockId1,
        );
        $dbConnection->beginTransaction();
        $locker->acquireTransactionLevelLock(
            $dbConnection,
            $postgresLockId2,
        );

        $locker->releaseAllSessionLevelLocks($dbConnection);

        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection, $postgresLockId1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId2);
    }

    private function initLocker(): PostgresAdvisoryLocker
    {
        return new PostgresAdvisoryLocker();
    }
}
