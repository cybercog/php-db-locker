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
use Cog\DbLocker\TimeoutDuration;
use Cog\DbLocker\Connection\PdoConnectionAdapter;
use Cog\Test\DbLocker\Integration\AbstractIntegrationTestCase;
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
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            TimeoutDuration::zero(),
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
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            TimeoutDuration::zero(),
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
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            TimeoutDuration::zero(),
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
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            TimeoutDuration::zero(),
        );
        $isLock2Acquired = $locker->acquireSessionLevelLock(
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            TimeoutDuration::zero(),
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
            new PdoConnectionAdapter($dbConnection),
            $lockKey1,
            TimeoutDuration::zero(),
        );
        $isLock2Acquired = $locker->acquireSessionLevelLock(
            new PdoConnectionAdapter($dbConnection),
            $lockKey2,
            TimeoutDuration::zero(),
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
            new PdoConnectionAdapter($dbConnection1),
            $lockKey,
            TimeoutDuration::zero(),
        );

        // WHEN: Trying to acquire the same lock in a second connection
        $connection2Lock = $locker->acquireSessionLevelLock(
            new PdoConnectionAdapter($dbConnection2),
            $lockKey,
            TimeoutDuration::zero(),
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
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            TimeoutDuration::zero(),
            accessMode: $accessMode,
        );

        // WHEN: Releasing the lock with the same access mode
        $isLockReleased = $locker->releaseSessionLevelLock(
            new PdoConnectionAdapter($dbConnection),
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
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            TimeoutDuration::zero(),
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
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            TimeoutDuration::zero(),
            accessMode: $acquireMode,
        );

        // WHEN: Attempting to release the lock with a different access mode
        $isLockReleased = $locker->releaseSessionLevelLock(
            new PdoConnectionAdapter($dbConnection),
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
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            TimeoutDuration::zero(),
        );
        $locker->acquireSessionLevelLock(
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            TimeoutDuration::zero(),
        );

        // WHEN: Releasing the lock twice
        $isLockReleased1 = $locker->releaseSessionLevelLock(new PdoConnectionAdapter($dbConnection), $lockKey);
        $isLockReleased2 = $locker->releaseSessionLevelLock(new PdoConnectionAdapter($dbConnection), $lockKey);

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
            new PdoConnectionAdapter($dbConnection1),
            $lockKey,
            TimeoutDuration::zero(),
        );
        $locker->releaseSessionLevelLock(
            new PdoConnectionAdapter($dbConnection1),
            $lockKey,
        );

        // WHEN: Trying to acquire the same lock in a second connection
        $isLockAcquired = $locker->acquireSessionLevelLock(
            new PdoConnectionAdapter($dbConnection2),
            $lockKey,
            TimeoutDuration::zero(),
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
            new PdoConnectionAdapter($dbConnection1),
            $lockKey,
            TimeoutDuration::zero(),
        );
        $locker->acquireSessionLevelLock(
            new PdoConnectionAdapter($dbConnection1),
            $lockKey,
            TimeoutDuration::zero(),
        );

        // WHEN: Releasing once and trying to acquire in second connection
        $isLockReleased = $locker->releaseSessionLevelLock(new PdoConnectionAdapter($dbConnection1), $lockKey);
        $connection2Lock = $locker->acquireSessionLevelLock(
            new PdoConnectionAdapter($dbConnection2),
            $lockKey,
            TimeoutDuration::zero(),
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
        $isLockReleased = $locker->releaseSessionLevelLock(new PdoConnectionAdapter($dbConnection), $lockKey);

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
            new PdoConnectionAdapter($dbConnection1),
            $lockKey,
            TimeoutDuration::zero(),
        );

        // WHEN: Attempting to release the lock from a different connection
        $isLockReleased = $locker->releaseSessionLevelLock(new PdoConnectionAdapter($dbConnection2), $lockKey);

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
            new PdoConnectionAdapter($dbConnection),
            PostgresLockKey::create('test'),
            TimeoutDuration::zero(),
        );
        $locker->acquireSessionLevelLock(
            new PdoConnectionAdapter($dbConnection),
            PostgresLockKey::create('test'),
            TimeoutDuration::zero(),
        );
        $locker->acquireSessionLevelLock(
            new PdoConnectionAdapter($dbConnection),
            PostgresLockKey::create('test2'),
            TimeoutDuration::zero(),
        );

        // WHEN: Releasing all session-level locks in the connection
        $locker->releaseAllSessionLevelLocks(new PdoConnectionAdapter($dbConnection));

        // THEN: All locks should be removed from database
        $this->assertPgAdvisoryLocksCount(0);
    }

    public function testItCanReleaseAllLocksInConnectionIfNoLocksWereAcquired(): void
    {
        // GIVEN: A database connection with no locks acquired
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();

        // WHEN: Releasing all session-level locks when none exist
        $locker->releaseAllSessionLevelLocks(new PdoConnectionAdapter($dbConnection));

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
            new PdoConnectionAdapter($dbConnection1),
            $lockKey1,
            TimeoutDuration::zero(),
        );
        $locker->acquireSessionLevelLock(
            new PdoConnectionAdapter($dbConnection1),
            $lockKey2,
            TimeoutDuration::zero(),
        );
        $locker->acquireSessionLevelLock(
            new PdoConnectionAdapter($dbConnection2),
            $lockKey3,
            TimeoutDuration::zero(),
        );
        $locker->acquireSessionLevelLock(
            new PdoConnectionAdapter($dbConnection2),
            $lockKey4,
            TimeoutDuration::zero(),
        );

        // WHEN: Releasing all locks in the first connection only
        $locker->releaseAllSessionLevelLocks(new PdoConnectionAdapter($dbConnection1));

        // THEN: First connection locks removed, second connection locks remain
        $this->assertPgAdvisoryLocksCount(2);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection2, $lockKey3);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection2, $lockKey4);
    }

    public function testItThrowsExceptionWhenPdoErrorModeIsNotExceptionForAcquireSessionLevelLock(): void
    {
        // GIVEN: A PDO connection with silent error mode (not ERRMODE_EXCEPTION)
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $dbConnection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
        $lockKey = PostgresLockKey::create('test');

        // WHEN: Attempting to acquire a session-level lock with non-exception error mode
        // THEN: Should throw LogicException requiring ERRMODE_EXCEPTION
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('PDO connection must use PDO::ERRMODE_EXCEPTION');

        $locker->acquireSessionLevelLock(
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            TimeoutDuration::zero(),
        );
    }

    public function testItThrowsExceptionWhenPdoErrorModeIsNotExceptionForAcquireTransactionLevelLock(): void
    {
        // GIVEN: A PDO connection with silent error mode (not ERRMODE_EXCEPTION)
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $dbConnection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
        $lockKey = PostgresLockKey::create('test');
        $dbConnection->beginTransaction();

        // WHEN: Attempting to acquire a transaction-level lock with non-exception error mode
        // THEN: Should throw LogicException requiring ERRMODE_EXCEPTION
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('PDO connection must use PDO::ERRMODE_EXCEPTION');

        $locker->acquireTransactionLevelLock(
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            TimeoutDuration::zero(),
        );
    }

    public function testItThrowsExceptionWhenPdoErrorModeIsNotExceptionForWithinSessionLevelLock(): void
    {
        // GIVEN: A PDO connection with silent error mode (not ERRMODE_EXCEPTION)
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $dbConnection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);
        $lockKey = PostgresLockKey::create('test');

        // WHEN: Attempting to use withinSessionLevelLock with non-exception error mode
        // THEN: Should throw LogicException requiring ERRMODE_EXCEPTION
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('PDO connection must use PDO::ERRMODE_EXCEPTION');

        $locker->withinSessionLevelLock(
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            function () {
                return true;
            },
            TimeoutDuration::zero(),
        );
    }

    public function testItThrowsExceptionWhenPdoErrorModeIsNotExceptionForReleaseSessionLevelLock(): void
    {
        // GIVEN: A PDO connection with a lock acquired, then error mode changed to silent
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');

        // First acquire the lock with proper error mode
        $locker->acquireSessionLevelLock(
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            TimeoutDuration::zero(),
        );

        // Then change to silent mode
        $dbConnection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);

        // WHEN: Attempting to release the lock with non-exception error mode
        // THEN: Should throw LogicException requiring ERRMODE_EXCEPTION
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('PDO connection must use PDO::ERRMODE_EXCEPTION');

        $locker->releaseSessionLevelLock(
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
        );
    }

    public function testItThrowsExceptionWhenPdoErrorModeIsNotExceptionForReleaseAllSessionLevelLocks(): void
    {
        // GIVEN: A PDO connection with a lock acquired, then error mode changed to silent
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');

        // First acquire the lock with proper error mode
        $locker->acquireSessionLevelLock(
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            TimeoutDuration::zero(),
        );

        // Then change to silent mode
        $dbConnection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_SILENT);

        // WHEN: Attempting to release all locks with non-exception error mode
        // THEN: Should throw LogicException requiring ERRMODE_EXCEPTION
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('PDO connection must use PDO::ERRMODE_EXCEPTION');

        $locker->releaseAllSessionLevelLocks(
            new PdoConnectionAdapter($dbConnection),
        );
    }

    public function testItCannotAcquireLockWithinTransactionNotInTransaction(): void
    {
        // GIVEN: A database connection without an active transaction
        // WHEN: Attempting to acquire a transaction-level lock outside of a transaction
        // THEN: Should throw LogicException with descriptive message
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Transaction-level advisory lock `[test:]` cannot be acquired outside of transaction',
        );

        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');

        $locker->acquireTransactionLevelLock(
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            TimeoutDuration::zero(),
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
            new PdoConnectionAdapter($dbConnection1),
            $lockKey,
            TimeoutDuration::zero(),
        );

        // WHEN: Trying to acquire the same lock in a second connection
        $connection2Lock = $locker->acquireSessionLevelLock(
            new PdoConnectionAdapter($dbConnection2),
            $lockKey,
            TimeoutDuration::zero(),
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
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            TimeoutDuration::zero(),
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
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            TimeoutDuration::zero(),
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
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            TimeoutDuration::zero(),
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
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            TimeoutDuration::zero(),
        );

        // WHEN: Attempting to manually release a transaction-level lock as session-level
        $isLockReleased = $locker->releaseSessionLevelLock(new PdoConnectionAdapter($dbConnection), $lockKey);

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
            new PdoConnectionAdapter($dbConnection),
            $lockKey1,
            TimeoutDuration::zero(),
        );
        $dbConnection->beginTransaction();
        $locker->acquireTransactionLevelLock(
            new PdoConnectionAdapter($dbConnection),
            $lockKey2,
            TimeoutDuration::zero(),
        );

        // WHEN: Releasing all session-level locks
        $locker->releaseAllSessionLevelLocks(new PdoConnectionAdapter($dbConnection));

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
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            function () use ($dbConnection, $lockKey, $accessMode, $x, $y): int {
                $this->assertPgAdvisoryLocksCount(1);
                $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $lockKey, $accessMode);

                return $x + $y;
            },
            TimeoutDuration::zero(),
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

    public function testWithinSessionLevelLockPreservesOriginalExceptionWhenReleaseFails(): void
    {
        // GIVEN: A session-level lock acquired via withinSessionLevelLock callback
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $dbConnection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $lockKey = PostgresLockKey::create('test');

        // WHEN: Callback throws an exception and the connection is killed before release
        try {
            $locker->withinSessionLevelLock(
                new PdoConnectionAdapter($dbConnection),
                $lockKey,
                function () use ($dbConnection): never {
                    // Kill own connection's backend to make release fail
                    $pid = $dbConnection->pgsqlGetPid();
                    $killerConnection = $this->initPostgresPdoConnection();
                    $killerConnection->exec("SELECT PG_TERMINATE_BACKEND($pid)");

                    throw new \RuntimeException('Original error from callback');
                },
                TimeoutDuration::zero(),
            );
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            // THEN: The original exception from callback should be preserved, not masked by release failure
            $this->assertSame('Original error from callback', $e->getMessage());
        } catch (\PDOException $e) {
            $this->fail('PDOException from release should not mask the original RuntimeException: ' . $e->getMessage());
        }
    }

    public function testWithinSessionLevelLockDoesNotReleaseWhenLockWasNotAcquired(): void
    {
        // GIVEN: A lock already held by another connection so the second cannot acquire it
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $locker->acquireSessionLevelLock(
            new PdoConnectionAdapter($dbConnection1),
            $lockKey,
            TimeoutDuration::zero(),
        );

        // WHEN: withinSessionLevelLock is called on second connection (lock not acquired)
        $locker->withinSessionLevelLock(
            new PdoConnectionAdapter($dbConnection2),
            $lockKey,
            function ($lockHandle): void {
                $this->assertFalse($lockHandle->wasAcquired);
            },
            TimeoutDuration::zero(),
        );

        // THEN: The first connection's lock should still be intact (not decremented by a spurious release)
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection1, $lockKey);
    }

    public function testItSanitizesCommentToPreventSqlInjection(): void
    {
        // GIVEN: A lock key with newline that could break SQL comment and inject code
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create("test\n; DROP TABLE users; --", "value");

        // WHEN: Acquiring a transaction-level lock with the malicious key
        $dbConnection->beginTransaction();
        $lockHandle = $locker->acquireTransactionLevelLock(
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            TimeoutDuration::zero(),
        );

        // THEN: The lock should be acquired without SQL errors (proving sanitization worked)
        $this->assertTrue($lockHandle->wasAcquired);

        // THEN: Lock should exist in database (proving query executed correctly)
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $lockKey);

        $dbConnection->rollBack();
    }

    public function testItCanAcquireTransactionLevelLockWithTimeout(): void
    {
        // GIVEN: An active transaction and a free lock key
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $dbConnection->beginTransaction();

        // WHEN: Acquiring a transaction-level lock with timeout on a free lock
        $lockHandle = $locker->acquireTransactionLevelLock(
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            TimeoutDuration::ofSeconds(5),
        );

        // THEN: Lock should be successfully acquired
        $this->assertTrue($lockHandle->wasAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $lockKey);
    }

    public function testItCannotAcquireTransactionLevelLockWhenTimeoutExceeded(): void
    {
        // GIVEN: A lock already held by another connection
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $locker->acquireSessionLevelLock(
            new PdoConnectionAdapter($dbConnection1),
            $lockKey,
            TimeoutDuration::zero(),
        );
        $dbConnection2->beginTransaction();

        // WHEN: Attempting to acquire the same lock with a short timeout
        $lockHandle = $locker->acquireTransactionLevelLock(
            new PdoConnectionAdapter($dbConnection2),
            $lockKey,
            TimeoutDuration::ofMilliseconds(100),
        );

        // THEN: Lock acquisition should fail due to timeout, transaction remains usable
        $this->assertFalse($lockHandle->wasAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection1, $lockKey);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection2, $lockKey);
    }

    public function testItCanAcquireSessionLevelLockWithTimeout(): void
    {
        // GIVEN: A database connection and a free lock key
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');

        // WHEN: Acquiring a session-level lock with timeout on a free lock
        $lockHandle = $locker->acquireSessionLevelLock(
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            TimeoutDuration::ofSeconds(5),
        );

        // THEN: Lock should be successfully acquired
        $this->assertTrue($lockHandle->wasAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $lockKey);
    }

    public function testItCannotAcquireSessionLevelLockWhenTimeoutExceeded(): void
    {
        // GIVEN: A lock already held by another connection
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $locker->acquireSessionLevelLock(
            new PdoConnectionAdapter($dbConnection1),
            $lockKey,
            TimeoutDuration::zero(),
        );

        // WHEN: Attempting to acquire the same lock with a short timeout
        $lockHandle = $locker->acquireSessionLevelLock(
            new PdoConnectionAdapter($dbConnection2),
            $lockKey,
            TimeoutDuration::ofMilliseconds(100),
        );

        // THEN: Lock acquisition should fail due to timeout
        $this->assertFalse($lockHandle->wasAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection1, $lockKey);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection2, $lockKey);
    }

    public function testItResetsSessionLockTimeoutAfterAcquisition(): void
    {
        // GIVEN: A session-level lock acquired with a timeout
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');

        $locker->acquireSessionLevelLock(
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            TimeoutDuration::ofSeconds(5),
        );

        // WHEN: Checking the lock_timeout setting after acquisition
        $statement = $dbConnection->query('SHOW lock_timeout');
        $lockTimeout = $statement->fetchColumn(0);

        // THEN: lock_timeout should be reset to 0 (no timeout)
        $this->assertSame('0', $lockTimeout);
    }

    public function testItRestoresOriginalLockTimeoutAfterAcquisition(): void
    {
        // GIVEN: A connection with a custom lock_timeout set
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $lockKey = PostgresLockKey::create('test');
        $dbConnection->exec('SET lock_timeout = 10000'); // 10 seconds

        // WHEN: Acquiring a session-level lock with a different timeout
        $locker->acquireSessionLevelLock(
            new PdoConnectionAdapter($dbConnection),
            $lockKey,
            TimeoutDuration::ofSeconds(5),
        );

        // THEN: Original lock_timeout should be restored
        $statement = $dbConnection->query('SHOW lock_timeout');
        $lockTimeout = $statement->fetchColumn(0);
        $this->assertSame('10s', $lockTimeout);
    }

    private function initLocker(): PostgresAdvisoryLocker
    {
        return new PostgresAdvisoryLocker();
    }
}
