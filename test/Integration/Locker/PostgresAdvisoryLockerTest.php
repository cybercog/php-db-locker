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
use Cog\DbLocker\LockId\LockId;
use Cog\DbLocker\LockId\PostgresLockId;
use Cog\Test\DbLocker\Integration\AbstractIntegrationTestCase;
use LogicException;

final class PostgresAdvisoryLockerTest extends AbstractIntegrationTestCase
{
    /** @test */
    public function it_can_acquire_lock(): void
    {
        $locker = $this->createLocker();
        $dbConnection = $this->createPostgresPdoConnection();
        $postgresLockId = $this->createPostgresLockId('test');

        $isLockAcquired = $locker->acquireLock($dbConnection, $postgresLockId);

        $this->assertTrue($isLockAcquired);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId);
        $this->assertPgAdvisoryLocksCount(1);
    }

    /** @test */
    public function it_can_acquire_lock_in_same_connection_only_once(): void
    {
        $locker = $this->createLocker();
        $dbConnection = $this->createPostgresPdoConnection();
        $postgresLockId = $this->createPostgresLockId('test');

        $isLockAcquired1 = $locker->acquireLock($dbConnection, $postgresLockId);
        $isLockAcquired2 = $locker->acquireLock($dbConnection, $postgresLockId);

        $this->assertTrue($isLockAcquired1);
        $this->assertTrue($isLockAcquired2);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId);
        $this->assertPgAdvisoryLocksCount(1);
    }

    /** @test */
    public function it_can_acquire_multiple_locks_in_one_connection(): void
    {
        $locker = $this->createLocker();
        $dbConnection = $this->createPostgresPdoConnection();
        $postgresLockId1 = $this->createPostgresLockId('test1');
        $postgresLockId2 = $this->createPostgresLockId('test2');

        $isLock1Acquired = $locker->acquireLock($dbConnection, $postgresLockId1);
        $isLock2Acquired = $locker->acquireLock($dbConnection, $postgresLockId2);

        $this->assertTrue($isLock1Acquired);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId1);
        $this->assertTrue($isLock2Acquired);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId2);
        $this->assertPgAdvisoryLocksCount(2);
    }

    /** @test */
    public function it_cannot_acquire_same_lock_in_two_connections(): void
    {
        $locker = $this->createLocker();
        $dbConnection1 = $this->createPostgresPdoConnection();
        $dbConnection2 = $this->createPostgresPdoConnection();
        $postgresLockId = $this->createPostgresLockId('test');
        $locker->acquireLock($dbConnection1, $postgresLockId);

        $isLockAcquired = $locker->acquireLock($dbConnection2, $postgresLockId);

        $this->assertFalse($isLockAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection2, $postgresLockId);
    }

    /** @test */
    public function it_can_release_lock(): void
    {
        $locker = $this->createLocker();
        $dbConnection = $this->createPostgresPdoConnection();
        $postgresLockId = $this->createPostgresLockId('test');
        $locker->acquireLock($dbConnection, $postgresLockId);

        $isLockReleased = $locker->releaseLock($dbConnection, $postgresLockId);

        $this->assertTrue($isLockReleased);
        $this->assertPgAdvisoryLocksCount(0);
    }

    /** @test */
    public function it_can_release_lock_twice_if_acquired_twice(): void
    {
        $locker = $this->createLocker();
        $dbConnection = $this->createPostgresPdoConnection();
        $postgresLockId = $this->createPostgresLockId('test');
        $locker->acquireLock($dbConnection, $postgresLockId);
        $locker->acquireLock($dbConnection, $postgresLockId);

        $isLockReleased1 = $locker->releaseLock($dbConnection, $postgresLockId);
        $isLockReleased2 = $locker->releaseLock($dbConnection, $postgresLockId);

        $this->assertTrue($isLockReleased1);
        $this->assertTrue($isLockReleased2);
        $this->assertPgAdvisoryLocksCount(0);
    }

    /** @test */
    public function it_can_acquire_lock_in_second_connection_after_release(): void
    {
        $locker = $this->createLocker();
        $dbConnection1 = $this->createPostgresPdoConnection();
        $dbConnection2 = $this->createPostgresPdoConnection();
        $postgresLockId = $this->createPostgresLockId('test');
        $locker->acquireLock($dbConnection1, $postgresLockId);
        $locker->releaseLock($dbConnection1, $postgresLockId);

        $isLockAcquired = $locker->acquireLock($dbConnection2, $postgresLockId);

        $this->assertTrue($isLockAcquired);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection2, $postgresLockId);
        $this->assertPgAdvisoryLocksCount(1);
    }

    /** @test */
    public function it_cannot_acquire_lock_in_second_connection_after_one_release_twice_locked(): void
    {
        $locker = $this->createLocker();
        $dbConnection1 = $this->createPostgresPdoConnection();
        $dbConnection2 = $this->createPostgresPdoConnection();
        $postgresLockId = $this->createPostgresLockId('test');
        $locker->acquireLock($dbConnection1, $postgresLockId);
        $locker->acquireLock($dbConnection1, $postgresLockId);

        $isLockReleased = $locker->releaseLock($dbConnection1, $postgresLockId);
        $isLockAcquired = $locker->acquireLock($dbConnection2, $postgresLockId);

        $this->assertTrue($isLockReleased);
        $this->assertFalse($isLockAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection1, $postgresLockId);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection2, $postgresLockId);
    }

    /** @test */
    public function it_cannot_release_lock_if_not_acquired(): void
    {
        $locker = $this->createLocker();
        $dbConnection = $this->createPostgresPdoConnection();
        $postgresLockId = $this->createPostgresLockId('test');

        $isLockReleased = $locker->releaseLock($dbConnection, $postgresLockId);

        $this->assertFalse($isLockReleased);
        $this->assertPgAdvisoryLocksCount(0);
    }

    /** @test */
    public function it_cannot_release_lock_if_acquired_in_other_connection(): void
    {
        $locker = $this->createLocker();
        $dbConnection1 = $this->createPostgresPdoConnection();
        $dbConnection2 = $this->createPostgresPdoConnection();
        $postgresLockId = $this->createPostgresLockId('test');
        $locker->acquireLock($dbConnection1, $postgresLockId);

        $isLockReleased = $locker->releaseLock($dbConnection2, $postgresLockId);

        $this->assertFalse($isLockReleased);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection1, $postgresLockId);
        $this->assertPgAdvisoryLocksCount(1);
    }

    /** @test */
    public function it_can_release_all_locks_in_connection(): void
    {
        $locker = $this->createLocker();
        $dbConnection = $this->createPostgresPdoConnection();
        $postgresLockId1 = $this->createPostgresLockId('test');
        $postgresLockId2 = $this->createPostgresLockId('test2');
        $locker->acquireLock($dbConnection, $postgresLockId1);
        $locker->acquireLock($dbConnection, $postgresLockId2);

        $locker->releaseAllLocks($dbConnection);

        $this->assertPgAdvisoryLocksCount(0);
    }

    /** @test */
    public function it_can_release_all_locks_in_connection_if_no_locks_were_acquired(): void
    {
        $locker = $this->createLocker();
        $dbConnection = $this->createPostgresPdoConnection();

        $locker->releaseAllLocks($dbConnection);

        $this->assertPgAdvisoryLocksCount(0);
    }

    /** @test */
    public function it_can_release_all_locks_in_connection_but_keeps_other_locks(): void
    {
        $locker = $this->createLocker();
        $dbConnection1 = $this->createPostgresPdoConnection();
        $dbConnection2 = $this->createPostgresPdoConnection();
        $postgresLockId1 = $this->createPostgresLockId('test');
        $postgresLockId2 = $this->createPostgresLockId('test2');
        $postgresLockId3 = $this->createPostgresLockId('test3');
        $postgresLockId4 = $this->createPostgresLockId('test4');
        $locker->acquireLock($dbConnection1, $postgresLockId1);
        $locker->acquireLock($dbConnection1, $postgresLockId2);
        $locker->acquireLock($dbConnection2, $postgresLockId3);
        $locker->acquireLock($dbConnection2, $postgresLockId4);

        $locker->releaseAllLocks($dbConnection1);

        $this->assertPgAdvisoryLocksCount(2);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection2, $postgresLockId3);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection2, $postgresLockId4);
    }

    /** @test */
    public function it_can_acquire_lock_within_transaction(): void
    {
        $locker = $this->createLocker();
        $dbConnection = $this->createPostgresPdoConnection();
        $postgresLockId = $this->createPostgresLockId('test');
        $dbConnection->beginTransaction();

        $isLockAcquired = $locker->acquireLockWithinTransaction($dbConnection, $postgresLockId);

        $this->assertTrue($isLockAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId);
        $this->assertPgAdvisoryLockExistsInTransaction($dbConnection, $postgresLockId);
    }

    /** @test */
    public function it_cannot_acquire_lock_within_transaction_not_in_transaction(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            "Transaction-level advisory lock `test` cannot be acquired outside of transaction"
        );

        $locker = $this->createLocker();
        $dbConnection = $this->createPostgresPdoConnection();
        $postgresLockId = $this->createPostgresLockId('test');

        $locker->acquireLockWithinTransaction($dbConnection, $postgresLockId);
    }

    /** @test */
    public function it_cannot_acquire_lock_in_second_connection_if_taken_within_transaction(): void
    {
        $locker = $this->createLocker();
        $dbConnection1 = $this->createPostgresPdoConnection();
        $dbConnection2 = $this->createPostgresPdoConnection();
        $postgresLockId = $this->createPostgresLockId('test');
        $dbConnection1->beginTransaction();
        $locker->acquireLockWithinTransaction($dbConnection1, $postgresLockId);

        $isLockAcquired = $locker->acquireLock($dbConnection2, $postgresLockId);

        $this->assertFalse($isLockAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection1, $postgresLockId);
    }

    /** @test */
    public function it_can_auto_release_lock_acquired_within_transaction(): void
    {
        $locker = $this->createLocker();
        $dbConnection = $this->createPostgresPdoConnection();
        $postgresLockId = $this->createPostgresLockId('test');
        $dbConnection->beginTransaction();

        $isLockAcquired = $locker->acquireLockWithinTransaction($dbConnection, $postgresLockId);
        $dbConnection->commit();

        $this->assertTrue($isLockAcquired);
        $this->assertPgAdvisoryLocksCount(0);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection, $postgresLockId);
    }

    /** @test */
    public function it_cannot_release_lock_acquired_within_transaction(): void
    {
        $locker = $this->createLocker();
        $dbConnection = $this->createPostgresPdoConnection();
        $postgresLockId = $this->createPostgresLockId('test');
        $dbConnection->beginTransaction();
        $locker->acquireLockWithinTransaction($dbConnection, $postgresLockId);

        $isLockReleased = $locker->releaseLock($dbConnection, $postgresLockId);

        $this->assertFalse($isLockReleased);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId);
    }

    /** @test */
    public function it_cannot_release_all_locks_acquired_within_transaction(): void
    {
        $locker = $this->createLocker();
        $dbConnection = $this->createPostgresPdoConnection();
        $postgresLockId1 = $this->createPostgresLockId('test');
        $postgresLockId2 = $this->createPostgresLockId('test2');
        $locker->acquireLock($dbConnection, $postgresLockId1);
        $dbConnection->beginTransaction();
        $locker->acquireLockWithinTransaction($dbConnection, $postgresLockId2);

        $locker->releaseAllLocks($dbConnection);

        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection, $postgresLockId1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId2);
    }

    private function createLocker(): PostgresAdvisoryLocker
    {
        return new PostgresAdvisoryLocker();
    }

    private function createPostgresLockId(
        string $lockKey
    ): PostgresLockId {
        return PostgresLockId::fromLockId(new LockId($lockKey));
    }
}
