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
use Cog\DbLocker\Postgres\PostgresLockKey;
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
        $lockKey = PostgresLockKey::create('test');

        $isLockAcquired = $locker->acquireSessionLevelLock(
            $dbConnection,
            $lockKey,
            accessMode: $accessMode,
        );

        $this->assertTrue($isLockAcquired->wasAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $lockKey, $accessMode);
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
        $lockKey = PostgresLockKey::create('test');
        $dbConnection->beginTransaction();

        $isLockAcquired = $locker->acquireTransactionLevelLock(
            $dbConnection,
            $lockKey,
            accessMode: $accessMode,
        );

        $this->assertTrue($isLockAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $lockKey, $accessMode);
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
    public function testItCanTryAcquireLockFromIntKeysCornerCases(
        int $classId,
        int $objectId,
    ): void {
        // GIVEN: A lock key created from corner-case int32 boundary values
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::createFromInternalIds($classId, $objectId);

        // WHEN: Acquiring a session-level lock with these boundary values
        $isLockAcquired = $locker->acquireSessionLevelLock(
            $dbConnection,
            $lockKey,
        );

        // THEN: Lock should be acquired successfully for all int32 boundary cases
        $this->assertTrue($isLockAcquired->wasAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $lockKey);
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
        $lockKey = PostgresLockKey::create('test');

        $isLock1Acquired = $locker->acquireSessionLevelLock(
            $dbConnection,
            $lockKey,
        );
        $isLock2Acquired = $locker->acquireSessionLevelLock(
            $dbConnection,
            $lockKey,
        );

        $this->assertTrue($isLock1Acquired->wasAcquired);
        $this->assertTrue($isLock2Acquired->wasAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $lockKey);
    }

    public function testItCanTryAcquireMultipleLocksInOneConnection(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey1 = PostgresLockKey::create('test1');
        $lockKey2 = PostgresLockKey::create('test2');

        $isLock1Acquired = $locker->acquireSessionLevelLock(
            $dbConnection,
            $lockKey1,
        );
        $isLock2Acquired = $locker->acquireSessionLevelLock(
            $dbConnection,
            $lockKey2,
        );

        $this->assertTrue($isLock1Acquired->wasAcquired);
        $this->assertTrue($isLock2Acquired->wasAcquired);
        $this->assertPgAdvisoryLocksCount(2);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $lockKey1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $lockKey2);
    }

    public function testItCannotAcquireSameLockInTwoConnections(): void
    {
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $locker->acquireSessionLevelLock(
            $dbConnection1,
            $lockKey,
        );

        $connection2Lock = $locker->acquireSessionLevelLock(
            $dbConnection2,
            $lockKey,
        );

        $this->assertFalse($connection2Lock->wasAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection2, $lockKey);
    }

    #[DataProvider('provideItCanReleaseLockData')]
    public function testItCanReleaseLock(
        PostgresLockAccessModeEnum $accessMode,
    ): void {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $locker->acquireSessionLevelLock(
            $dbConnection,
            $lockKey,
            accessMode: $accessMode,
        );

        $isLockReleased = $locker->releaseSessionLevelLock(
            $dbConnection,
            $lockKey,
            accessMode: $accessMode,
        );

        $this->assertTrue($isLockReleased);
        $this->assertPgAdvisoryLocksCount(0);
    }

    #[DataProvider('provideItCanReleaseLockViaHandleData')]
    public function testItCanReleaseLockViaHandle(
        PostgresLockAccessModeEnum $accessMode,
    ): void {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');

        $lockHandle = $locker->acquireSessionLevelLock(
            $dbConnection,
            $lockKey,
            accessMode: $accessMode,
        );

        $this->assertTrue($lockHandle->wasAcquired);
        $this->assertPgAdvisoryLocksCount(1);

        $wasReleased = $lockHandle->release();

        $this->assertTrue($wasReleased);
        $this->assertPgAdvisoryLocksCount(0);
    }

    public static function provideItCanReleaseLockViaHandleData(): array
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
        $lockKey = PostgresLockKey::create('test');
        $locker->acquireSessionLevelLock(
            $dbConnection,
            $lockKey,
            accessMode: $acquireMode,
        );

        $isLockReleased = $locker->releaseSessionLevelLock(
            $dbConnection,
            $lockKey,
            accessMode: $releaseMode,
        );

        $this->assertFalse($isLockReleased);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $lockKey, $acquireMode);
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
        $lockKey = PostgresLockKey::create('test');
        $locker->acquireSessionLevelLock(
            $dbConnection,
            $lockKey,
        );
        $locker->acquireSessionLevelLock(
            $dbConnection,
            $lockKey,
        );

        $isLockReleased1 = $locker->releaseSessionLevelLock($dbConnection, $lockKey);
        $isLockReleased2 = $locker->releaseSessionLevelLock($dbConnection, $lockKey);

        $this->assertTrue($isLockReleased1);
        $this->assertTrue($isLockReleased2);
        $this->assertPgAdvisoryLocksCount(0);
    }

    public function testItCanTryAcquireLockInSecondConnectionAfterRelease(): void
    {
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $locker->acquireSessionLevelLock(
            $dbConnection1,
            $lockKey,
        );
        $locker->releaseSessionLevelLock(
            $dbConnection1,
            $lockKey,
        );

        $isLockAcquired = $locker->acquireSessionLevelLock(
            $dbConnection2,
            $lockKey,
        );

        $this->assertTrue($isLockAcquired->wasAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection2, $lockKey);
    }

    public function testItCannotAcquireLockInSecondConnectionAfterOneReleaseTwiceLocked(): void
    {
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $locker->acquireSessionLevelLock(
            $dbConnection1,
            $lockKey,
        );
        $locker->acquireSessionLevelLock(
            $dbConnection1,
            $lockKey,
        );

        $isLockReleased = $locker->releaseSessionLevelLock($dbConnection1, $lockKey);
        $connection2Lock = $locker->acquireSessionLevelLock(
            $dbConnection2,
            $lockKey,
        );

        $this->assertTrue($isLockReleased);
        $this->assertFalse($connection2Lock->wasAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection1, $lockKey);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection2, $lockKey);
    }

    public function testItCannotReleaseLockIfNotAcquired(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');

        $isLockReleased = $locker->releaseSessionLevelLock($dbConnection, $lockKey);

        $this->assertFalse($isLockReleased);
        $this->assertPgAdvisoryLocksCount(0);
    }

    public function testItCannotReleaseLockIfAcquiredInOtherConnection(): void
    {
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $locker->acquireSessionLevelLock(
            $dbConnection1,
            $lockKey,
        );

        $isLockReleased = $locker->releaseSessionLevelLock($dbConnection2, $lockKey);

        $this->assertFalse($isLockReleased);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection1, $lockKey);
    }

    public function testItCanReleaseAllLocksInConnection(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $locker->acquireSessionLevelLock(
            $dbConnection,
            PostgresLockKey::create('test'),
        );
        $locker->acquireSessionLevelLock(
            $dbConnection,
            PostgresLockKey::create('test'),
        );
        $locker->acquireSessionLevelLock(
            $dbConnection,
            PostgresLockKey::create('test2'),
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
        $lockKey1 = PostgresLockKey::create('test');
        $lockKey2 = PostgresLockKey::create('test2');
        $lockKey3 = PostgresLockKey::create('test3');
        $lockKey4 = PostgresLockKey::create('test4');
        $locker->acquireSessionLevelLock(
            $dbConnection1,
            $lockKey1,
        );
        $locker->acquireSessionLevelLock(
            $dbConnection1,
            $lockKey2,
        );
        $locker->acquireSessionLevelLock(
            $dbConnection2,
            $lockKey3,
        );
        $locker->acquireSessionLevelLock(
            $dbConnection2,
            $lockKey4,
        );

        $locker->releaseAllSessionLevelLocks($dbConnection1);

        $this->assertPgAdvisoryLocksCount(2);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection2, $lockKey3);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection2, $lockKey4);
    }

    public function testItCannotAcquireLockWithinTransactionNotInTransaction(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Transaction-level advisory lock `test:` cannot be acquired outside of transaction',
        );

        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');

        $locker->acquireTransactionLevelLock(
            $dbConnection,
            $lockKey,
        );
    }

    public function testItCannotAcquireLockInSecondConnectionIfTakenWithinTransaction(): void
    {
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $dbConnection1->beginTransaction();
        $locker->acquireSessionLevelLock(
            $dbConnection1,
            $lockKey,
        );

        $connection2Lock = $locker->acquireSessionLevelLock(
            $dbConnection2,
            $lockKey,
        );

        $this->assertFalse($connection2Lock->wasAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection1, $lockKey);
    }

    public function testItCanAutoReleaseLockAcquiredWithinTransactionOnCommit(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $dbConnection->beginTransaction();
        $locker->acquireTransactionLevelLock(
            $dbConnection,
            $lockKey,
        );

        $dbConnection->commit();

        $this->assertPgAdvisoryLocksCount(0);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection, $lockKey);
    }

    public function testItCanAutoReleaseLockAcquiredWithinTransactionOnRollback(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $dbConnection->beginTransaction();
        $locker->acquireTransactionLevelLock(
            $dbConnection,
            $lockKey,
        );

        $dbConnection->rollBack();

        $this->assertPgAdvisoryLocksCount(0);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection, $lockKey);
    }

    public function testItCanAutoReleaseLockAcquiredWithinTransactionOnConnectionKill(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $dbConnection->beginTransaction();
        $locker->acquireTransactionLevelLock(
            $dbConnection,
            $lockKey,
        );

        $dbConnection = null;

        $this->assertPgAdvisoryLocksCount(0);
    }

    public function testItCannotReleaseLockAcquiredWithinTransaction(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $dbConnection->beginTransaction();
        $locker->acquireTransactionLevelLock(
            $dbConnection,
            $lockKey,
        );

        $isLockReleased = $locker->releaseSessionLevelLock($dbConnection, $lockKey);

        $this->assertFalse($isLockReleased);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $lockKey);
    }

    public function testItCannotReleaseAllLocksAcquiredWithinTransaction(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey1 = PostgresLockKey::create('test');
        $lockKey2 = PostgresLockKey::create('test2');
        $locker->acquireSessionLevelLock(
            $dbConnection,
            $lockKey1,
        );
        $dbConnection->beginTransaction();
        $locker->acquireTransactionLevelLock(
            $dbConnection,
            $lockKey2,
        );

        $locker->releaseAllSessionLevelLocks($dbConnection);

        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection, $lockKey1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $lockKey2);
    }

    #[DataProvider('provideItCanExecuteCodeWithinSessionLockData')]
    public function testItCanExecuteCodeWithinSessionLock(
        PostgresLockAccessModeEnum $accessMode,
    ): void {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $x = 2;
        $y = 3;

        $result = $locker->withinSessionLevelLock(
            $dbConnection,
            $lockKey,
            function () use ($dbConnection, $lockKey, $accessMode, $x, $y): int {
                $this->assertPgAdvisoryLocksCount(1);
                $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $lockKey, $accessMode);

                return $x + $y;
            },
            accessMode: $accessMode,
        );

        $this->assertSame(5, $result);
        $this->assertPgAdvisoryLocksCount(0);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection, $lockKey);
    }

    public static function provideItCanExecuteCodeWithinSessionLockData(): array
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

    private function initLocker(): PostgresAdvisoryLocker
    {
        return new PostgresAdvisoryLocker();
    }
}
