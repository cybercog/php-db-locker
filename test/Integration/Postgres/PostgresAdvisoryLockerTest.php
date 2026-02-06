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
        // GIVEN: A database connection and lock key
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');

        // WHEN: Acquiring a session-level lock with specified access mode
        $isLockAcquired = $locker->acquireSessionLevelLock(
            $dbConnection,
            $lockKey,
            accessMode: $accessMode,
        );

        // THEN: Lock should be successfully acquired and exist in the database
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
        // GIVEN: An active database transaction
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $dbConnection->beginTransaction();

        // WHEN: Acquiring a transaction-level lock with specified access mode
        $isLockAcquired = $locker->acquireTransactionLevelLock(
            $dbConnection,
            $lockKey,
            accessMode: $accessMode,
        );

        // THEN: Lock should be successfully acquired and exist in the database
        $this->assertTrue($isLockAcquired->wasAcquired);
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
        // GIVEN: A database connection and lock key
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');

        // WHEN: Acquiring the same lock twice in the same connection
        $isLock1Acquired = $locker->acquireSessionLevelLock(
            $dbConnection,
            $lockKey,
        );
        $isLock2Acquired = $locker->acquireSessionLevelLock(
            $dbConnection,
            $lockKey,
        );

        // THEN: Both acquisitions should succeed but only one lock exists in the database
        $this->assertTrue($isLock1Acquired->wasAcquired);
        $this->assertTrue($isLock2Acquired->wasAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $lockKey);
    }

    public function testItCanTryAcquireMultipleLocksInOneConnection(): void
    {
        // GIVEN: A database connection and two different lock keys
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey1 = PostgresLockKey::create('test1');
        $lockKey2 = PostgresLockKey::create('test2');

        // WHEN: Acquiring two different locks in the same connection
        $isLock1Acquired = $locker->acquireSessionLevelLock(
            $dbConnection,
            $lockKey1,
        );
        $isLock2Acquired = $locker->acquireSessionLevelLock(
            $dbConnection,
            $lockKey2,
        );

        // THEN: Both locks should be acquired and exist in the database
        $this->assertTrue($isLock1Acquired->wasAcquired);
        $this->assertTrue($isLock2Acquired->wasAcquired);
        $this->assertPgAdvisoryLocksCount(2);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $lockKey1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $lockKey2);
    }

    public function testItCannotAcquireSameLockInTwoConnections(): void
    {
        // GIVEN: A lock already acquired in the first connection
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $locker->acquireSessionLevelLock(
            $dbConnection1,
            $lockKey,
        );

        // WHEN: Trying to acquire the same lock in a second connection (non-blocking)
        $connection2Lock = $locker->acquireSessionLevelLock(
            $dbConnection2,
            $lockKey,
        );

        // THEN: Second connection should fail to acquire the lock
        $this->assertFalse($connection2Lock->wasAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection2, $lockKey);
    }

    #[DataProvider('provideItCanReleaseLockData')]
    public function testItCanReleaseLock(
        PostgresLockAccessModeEnum $accessMode,
    ): void {
        // GIVEN: A session-level lock acquired with specific access mode
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $locker->acquireSessionLevelLock(
            $dbConnection,
            $lockKey,
            accessMode: $accessMode,
        );

        // WHEN: Releasing the lock with the same access mode
        $isLockReleased = $locker->releaseSessionLevelLock(
            $dbConnection,
            $lockKey,
            accessMode: $accessMode,
        );

        // THEN: Lock should be successfully released and removed from database
        $this->assertTrue($isLockReleased);
        $this->assertPgAdvisoryLocksCount(0);
    }

    #[DataProvider('provideItCanReleaseLockViaHandleData')]
    public function testItCanReleaseLockViaHandle(
        PostgresLockAccessModeEnum $accessMode,
    ): void {
        // GIVEN: A session-level lock acquired via handle with specific access mode
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

        // WHEN: Releasing the lock via the handle's release() method
        $wasReleased = $lockHandle->release();

        // THEN: Lock should be successfully released and removed from database
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
        // GIVEN: A lock acquired with one access mode (exclusive or share)
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $locker->acquireSessionLevelLock(
            $dbConnection,
            $lockKey,
            accessMode: $acquireMode,
        );

        // WHEN: Attempting to release the lock with a different access mode
        $isLockReleased = $locker->releaseSessionLevelLock(
            $dbConnection,
            $lockKey,
            accessMode: $releaseMode,
        );

        // THEN: Release should fail and lock should remain in database with original mode
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
        // GIVEN: The same lock acquired twice in the same connection
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

        // WHEN: Releasing the lock twice
        $isLockReleased1 = $locker->releaseSessionLevelLock($dbConnection, $lockKey);
        $isLockReleased2 = $locker->releaseSessionLevelLock($dbConnection, $lockKey);

        // THEN: Both releases should succeed and lock should be removed from database
        $this->assertTrue($isLockReleased1);
        $this->assertTrue($isLockReleased2);
        $this->assertPgAdvisoryLocksCount(0);
    }

    public function testItCanTryAcquireLockInSecondConnectionAfterRelease(): void
    {
        // GIVEN: A lock acquired and released in the first connection
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

        // WHEN: Trying to acquire the same lock in a second connection
        $isLockAcquired = $locker->acquireSessionLevelLock(
            $dbConnection2,
            $lockKey,
        );

        // THEN: Second connection should successfully acquire the lock
        $this->assertTrue($isLockAcquired->wasAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection2, $lockKey);
    }

    public function testItCannotAcquireLockInSecondConnectionAfterOneReleaseTwiceLocked(): void
    {
        // GIVEN: A lock acquired twice in the first connection, then released once
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

        // WHEN: Releasing once and trying to acquire in second connection
        $isLockReleased = $locker->releaseSessionLevelLock($dbConnection1, $lockKey);
        $connection2Lock = $locker->acquireSessionLevelLock(
            $dbConnection2,
            $lockKey,
        );

        // THEN: Lock is still held by first connection, second connection cannot acquire
        $this->assertTrue($isLockReleased);
        $this->assertFalse($connection2Lock->wasAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection1, $lockKey);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection2, $lockKey);
    }

    public function testItCannotReleaseLockIfNotAcquired(): void
    {
        // GIVEN: A database connection with no locks acquired
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');

        // WHEN: Attempting to release a lock that was never acquired
        $isLockReleased = $locker->releaseSessionLevelLock($dbConnection, $lockKey);

        // THEN: Release should fail and no locks should exist in database
        $this->assertFalse($isLockReleased);
        $this->assertPgAdvisoryLocksCount(0);
    }

    public function testItCannotReleaseLockIfAcquiredInOtherConnection(): void
    {
        // GIVEN: A lock acquired in the first connection
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $locker->acquireSessionLevelLock(
            $dbConnection1,
            $lockKey,
        );

        // WHEN: Attempting to release the lock from a different connection
        $isLockReleased = $locker->releaseSessionLevelLock($dbConnection2, $lockKey);

        // THEN: Release should fail and lock should remain in first connection
        $this->assertFalse($isLockReleased);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection1, $lockKey);
    }

    public function testItCanReleaseAllLocksInConnection(): void
    {
        // GIVEN: Multiple locks acquired in a single connection (same lock twice + different lock)
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

        // WHEN: Releasing all session-level locks in the connection
        $locker->releaseAllSessionLevelLocks($dbConnection);

        // THEN: All locks should be removed from database
        $this->assertPgAdvisoryLocksCount(0);
    }

    public function testItCanReleaseAllLocksInConnectionIfNoLocksWereAcquired(): void
    {
        // GIVEN: A database connection with no locks acquired
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();

        // WHEN: Releasing all session-level locks when none exist
        $locker->releaseAllSessionLevelLocks($dbConnection);

        // THEN: Operation should succeed with no locks in database
        $this->assertPgAdvisoryLocksCount(0);
    }

    public function testItCanReleaseAllLocksInConnectionButKeepsOtherConnectionLocks(): void
    {
        // GIVEN: Two locks in first connection and two locks in second connection
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

        // WHEN: Releasing all locks in the first connection only
        $locker->releaseAllSessionLevelLocks($dbConnection1);

        // THEN: First connection locks removed, second connection locks remain
        $this->assertPgAdvisoryLocksCount(2);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection2, $lockKey3);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection2, $lockKey4);
    }

    public function testItCannotAcquireLockWithinTransactionNotInTransaction(): void
    {
        // GIVEN: A database connection without an active transaction
        // WHEN: Attempting to acquire a transaction-level lock outside of a transaction
        // THEN: Should throw LogicException with descriptive message
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
        // GIVEN: A session-level lock acquired within an active transaction in first connection
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $dbConnection1->beginTransaction();
        $locker->acquireSessionLevelLock(
            $dbConnection1,
            $lockKey,
        );

        // WHEN: Trying to acquire the same lock in a second connection
        $connection2Lock = $locker->acquireSessionLevelLock(
            $dbConnection2,
            $lockKey,
        );

        // THEN: Second connection should fail to acquire the lock
        $this->assertFalse($connection2Lock->wasAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection1, $lockKey);
    }

    public function testItCanAutoReleaseLockAcquiredWithinTransactionOnCommit(): void
    {
        // GIVEN: A transaction-level lock acquired within an active transaction
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $dbConnection->beginTransaction();
        $locker->acquireTransactionLevelLock(
            $dbConnection,
            $lockKey,
        );

        // WHEN: Committing the transaction
        $dbConnection->commit();

        // THEN: Transaction-level lock should be automatically released
        $this->assertPgAdvisoryLocksCount(0);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection, $lockKey);
    }

    public function testItCanAutoReleaseLockAcquiredWithinTransactionOnRollback(): void
    {
        // GIVEN: A transaction-level lock acquired within an active transaction
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $dbConnection->beginTransaction();
        $locker->acquireTransactionLevelLock(
            $dbConnection,
            $lockKey,
        );

        // WHEN: Rolling back the transaction
        $dbConnection->rollBack();

        // THEN: Transaction-level lock should be automatically released
        $this->assertPgAdvisoryLocksCount(0);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection, $lockKey);
    }

    public function testItCanAutoReleaseLockAcquiredWithinTransactionOnConnectionKill(): void
    {
        // GIVEN: A transaction-level lock acquired within an active transaction
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $dbConnection->beginTransaction();
        $locker->acquireTransactionLevelLock(
            $dbConnection,
            $lockKey,
        );

        // WHEN: Closing the database connection (setting to null)
        $dbConnection = null;

        // THEN: Transaction-level lock should be automatically released
        $this->assertPgAdvisoryLocksCount(0);
    }

    public function testItCannotReleaseLockAcquiredWithinTransaction(): void
    {
        // GIVEN: A transaction-level lock acquired within an active transaction
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $dbConnection->beginTransaction();
        $locker->acquireTransactionLevelLock(
            $dbConnection,
            $lockKey,
        );

        // WHEN: Attempting to manually release a transaction-level lock as session-level
        $isLockReleased = $locker->releaseSessionLevelLock($dbConnection, $lockKey);

        // THEN: Release should fail and lock should remain in database
        $this->assertFalse($isLockReleased);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $lockKey);
    }

    public function testItCannotReleaseAllLocksAcquiredWithinTransaction(): void
    {
        // GIVEN: One session-level lock and one transaction-level lock in the same connection
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

        // WHEN: Releasing all session-level locks
        $locker->releaseAllSessionLevelLocks($dbConnection);

        // THEN: Only session-level lock released, transaction-level lock remains
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection, $lockKey1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $lockKey2);
    }

    #[DataProvider('provideItCanExecuteCodeWithinSessionLockData')]
    public function testItCanExecuteCodeWithinSessionLock(
        PostgresLockAccessModeEnum $accessMode,
    ): void {
        // GIVEN: A database connection and callback function to execute
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $x = 2;
        $y = 3;

        // WHEN: Executing code within a session-level lock using callback
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

        // THEN: Callback should execute successfully and lock should be auto-released
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
