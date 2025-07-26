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
    private const DB_INT32_VALUE_MIN = -2_147_483_648;
    private const DB_INT32_VALUE_MAX = 2_147_483_647;

    public function test_it_can_acquire_lock(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = $this->initPostgresLockId('test');

        $isLockAcquired = $locker->tryAcquireLock($dbConnection, $postgresLockId);

        $this->assertTrue($isLockAcquired);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId);
        $this->assertPgAdvisoryLocksCount(1);
    }

    public function test_it_can_acquire_lock_with_smallest_lock_id(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = new PostgresLockId(self::DB_INT32_VALUE_MIN);

        $isLockAcquired = $locker->tryAcquireLock($dbConnection, $postgresLockId);

        $this->assertTrue($isLockAcquired);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId);
        $this->assertPgAdvisoryLocksCount(1);
    }

    public function test_it_can_acquire_lock_with_biggest_lock_id(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = new PostgresLockId(self::DB_INT32_VALUE_MAX);

        $isLockAcquired = $locker->tryAcquireLock($dbConnection, $postgresLockId);

        $this->assertTrue($isLockAcquired);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId);
        $this->assertPgAdvisoryLocksCount(1);
    }

    public function test_it_can_acquire_lock_in_same_connection_only_once(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = $this->initPostgresLockId('test');

        $isLockAcquired1 = $locker->tryAcquireLock($dbConnection, $postgresLockId);
        $isLockAcquired2 = $locker->tryAcquireLock($dbConnection, $postgresLockId);

        $this->assertTrue($isLockAcquired1);
        $this->assertTrue($isLockAcquired2);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId);
        $this->assertPgAdvisoryLocksCount(1);
    }

    public function test_it_can_acquire_multiple_locks_in_one_connection(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId1 = $this->initPostgresLockId('test1');
        $postgresLockId2 = $this->initPostgresLockId('test2');

        $isLock1Acquired = $locker->tryAcquireLock($dbConnection, $postgresLockId1);
        $isLock2Acquired = $locker->tryAcquireLock($dbConnection, $postgresLockId2);

        $this->assertTrue($isLock1Acquired);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId1);
        $this->assertTrue($isLock2Acquired);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId2);
        $this->assertPgAdvisoryLocksCount(2);
    }

    public function test_it_cannot_acquire_same_lock_in_two_connections(): void
    {
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $postgresLockId = $this->initPostgresLockId('test');
        $locker->tryAcquireLock($dbConnection1, $postgresLockId);

        $isLockAcquired = $locker->tryAcquireLock($dbConnection2, $postgresLockId);

        $this->assertFalse($isLockAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection2, $postgresLockId);
    }

    public function test_it_can_release_lock(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = $this->initPostgresLockId('test');
        $locker->tryAcquireLock($dbConnection, $postgresLockId);

        $isLockReleased = $locker->releaseLock($dbConnection, $postgresLockId);

        $this->assertTrue($isLockReleased);
        $this->assertPgAdvisoryLocksCount(0);
    }

    public function test_it_can_release_lock_twice_if_acquired_twice(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = $this->initPostgresLockId('test');
        $locker->tryAcquireLock($dbConnection, $postgresLockId);
        $locker->tryAcquireLock($dbConnection, $postgresLockId);

        $isLockReleased1 = $locker->releaseLock($dbConnection, $postgresLockId);
        $isLockReleased2 = $locker->releaseLock($dbConnection, $postgresLockId);

        $this->assertTrue($isLockReleased1);
        $this->assertTrue($isLockReleased2);
        $this->assertPgAdvisoryLocksCount(0);
    }

    public function test_it_can_acquire_lock_in_second_connection_after_release(): void
    {
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $postgresLockId = $this->initPostgresLockId('test');
        $locker->tryAcquireLock($dbConnection1, $postgresLockId);
        $locker->releaseLock($dbConnection1, $postgresLockId);

        $isLockAcquired = $locker->tryAcquireLock($dbConnection2, $postgresLockId);

        $this->assertTrue($isLockAcquired);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection2, $postgresLockId);
        $this->assertPgAdvisoryLocksCount(1);
    }

    public function test_it_cannot_acquire_lock_in_second_connection_after_one_release_twice_locked(): void
    {
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $postgresLockId = $this->initPostgresLockId('test');
        $locker->tryAcquireLock($dbConnection1, $postgresLockId);
        $locker->tryAcquireLock($dbConnection1, $postgresLockId);

        $isLockReleased = $locker->releaseLock($dbConnection1, $postgresLockId);
        $isLockAcquired = $locker->tryAcquireLock($dbConnection2, $postgresLockId);

        $this->assertTrue($isLockReleased);
        $this->assertFalse($isLockAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection1, $postgresLockId);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection2, $postgresLockId);
    }

    public function test_it_cannot_release_lock_if_not_acquired(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = $this->initPostgresLockId('test');

        $isLockReleased = $locker->releaseLock($dbConnection, $postgresLockId);

        $this->assertFalse($isLockReleased);
        $this->assertPgAdvisoryLocksCount(0);
    }

    public function test_it_cannot_release_lock_if_acquired_in_other_connection(): void
    {
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $postgresLockId = $this->initPostgresLockId('test');
        $locker->tryAcquireLock($dbConnection1, $postgresLockId);

        $isLockReleased = $locker->releaseLock($dbConnection2, $postgresLockId);

        $this->assertFalse($isLockReleased);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection1, $postgresLockId);
        $this->assertPgAdvisoryLocksCount(1);
    }

    public function test_it_can_release_all_locks_in_connection(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId1 = $this->initPostgresLockId('test');
        $postgresLockId2 = $this->initPostgresLockId('test2');
        $locker->tryAcquireLock($dbConnection, $postgresLockId1);
        $locker->tryAcquireLock($dbConnection, $postgresLockId2);

        $locker->releaseAllLocks($dbConnection);

        $this->assertPgAdvisoryLocksCount(0);
    }

    public function test_it_can_release_all_locks_in_connection_if_no_locks_were_acquired(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();

        $locker->releaseAllLocks($dbConnection);

        $this->assertPgAdvisoryLocksCount(0);
    }

    public function test_it_can_release_all_locks_in_connection_but_keeps_other_locks(): void
    {
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $postgresLockId1 = $this->initPostgresLockId('test');
        $postgresLockId2 = $this->initPostgresLockId('test2');
        $postgresLockId3 = $this->initPostgresLockId('test3');
        $postgresLockId4 = $this->initPostgresLockId('test4');
        $locker->tryAcquireLock($dbConnection1, $postgresLockId1);
        $locker->tryAcquireLock($dbConnection1, $postgresLockId2);
        $locker->tryAcquireLock($dbConnection2, $postgresLockId3);
        $locker->tryAcquireLock($dbConnection2, $postgresLockId4);

        $locker->releaseAllLocks($dbConnection1);

        $this->assertPgAdvisoryLocksCount(2);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection2, $postgresLockId3);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection2, $postgresLockId4);
    }

    public function test_it_can_acquire_lock_within_transaction(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = $this->initPostgresLockId('test');
        $dbConnection->beginTransaction();

        $isLockAcquired = $locker->tryAcquireLockWithinTransaction($dbConnection, $postgresLockId);

        $this->assertTrue($isLockAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId);
    }

    public function test_it_cannot_acquire_lock_within_transaction_not_in_transaction(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(
            'Transaction-level advisory lock `test` cannot be acquired outside of transaction',
        );

        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = $this->initPostgresLockId('test');

        $locker->tryAcquireLockWithinTransaction($dbConnection, $postgresLockId);
    }

    public function test_it_cannot_acquire_lock_in_second_connection_if_taken_within_transaction(): void
    {
        $locker = $this->initLocker();
        $dbConnection1 = $this->initPostgresPdoConnection();
        $dbConnection2 = $this->initPostgresPdoConnection();
        $postgresLockId = $this->initPostgresLockId('test');
        $dbConnection1->beginTransaction();
        $locker->tryAcquireLockWithinTransaction($dbConnection1, $postgresLockId);

        $isLockAcquired = $locker->tryAcquireLock($dbConnection2, $postgresLockId);

        $this->assertFalse($isLockAcquired);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection1, $postgresLockId);
    }

    public function test_it_can_auto_release_lock_acquired_within_transaction_on_commit(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = $this->initPostgresLockId('test');
        $dbConnection->beginTransaction();
        $locker->tryAcquireLockWithinTransaction($dbConnection, $postgresLockId);

        $dbConnection->commit();

        $this->assertPgAdvisoryLocksCount(0);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection, $postgresLockId);
    }

    public function test_it_can_auto_release_lock_acquired_within_transaction_on_rollback(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = $this->initPostgresLockId('test');
        $dbConnection->beginTransaction();
        $locker->tryAcquireLockWithinTransaction($dbConnection, $postgresLockId);

        $dbConnection->rollBack();

        $this->assertPgAdvisoryLocksCount(0);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection, $postgresLockId);
    }

    public function test_it_can_auto_release_lock_acquired_within_transaction_on_connection_kill(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = $this->initPostgresLockId('test');
        $dbConnection->beginTransaction();
        $locker->tryAcquireLockWithinTransaction($dbConnection, $postgresLockId);

        $dbConnection = null;

        $this->assertPgAdvisoryLocksCount(0);
    }

    public function test_it_cannot_release_lock_acquired_within_transaction(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId = $this->initPostgresLockId('test');
        $dbConnection->beginTransaction();
        $locker->tryAcquireLockWithinTransaction($dbConnection, $postgresLockId);

        $isLockReleased = $locker->releaseLock($dbConnection, $postgresLockId);

        $this->assertFalse($isLockReleased);
        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId);
    }

    public function test_it_cannot_release_all_locks_acquired_within_transaction(): void
    {
        $locker = $this->initLocker();
        $dbConnection = $this->initPostgresPdoConnection();
        $postgresLockId1 = $this->initPostgresLockId('test');
        $postgresLockId2 = $this->initPostgresLockId('test2');
        $locker->tryAcquireLock($dbConnection, $postgresLockId1);
        $dbConnection->beginTransaction();
        $locker->tryAcquireLockWithinTransaction($dbConnection, $postgresLockId2);

        $locker->releaseAllLocks($dbConnection);

        $this->assertPgAdvisoryLocksCount(1);
        $this->assertPgAdvisoryLockMissingInConnection($dbConnection, $postgresLockId1);
        $this->assertPgAdvisoryLockExistsInConnection($dbConnection, $postgresLockId2);
    }

    private function initLocker(): PostgresAdvisoryLocker
    {
        return new PostgresAdvisoryLocker();
    }

    private function initPostgresLockId(
        string $lockKey,
    ): PostgresLockId {
        return PostgresLockId::fromLockId(new LockId($lockKey));
    }
}
