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

use Cog\DbLocker\ConnectionAdapterInterface;
use Cog\DbLocker\Exception\LockAcquireException;
use Cog\DbLocker\Exception\LockReleaseException;
use Cog\DbLocker\Postgres\Enum\PostgresLockAccessModeEnum;
use Cog\DbLocker\Postgres\PostgresAdvisoryLocker;
use Cog\DbLocker\Postgres\PostgresLockKey;
use Cog\DbLocker\TimeoutDuration;
use Cog\Test\DbLocker\Integration\AbstractIntegrationTestCase;

final class PostgresAdvisoryLockerExceptionTest extends AbstractIntegrationTestCase
{
    public function testItThrowsLockAcquireExceptionOnDatabaseErrorForTransactionLock(): void
    {
        // GIVEN: A mock connection adapter that throws a database error (not lock_not_available)
        $locker = $this->initLocker();
        $lockKey = PostgresLockKey::create('test', 'exception-test');
        $connectionAdapter = $this->createMockConnectionWithDatabaseError();

        // WHEN: Attempting to acquire a transaction-level lock
        // THEN: LockAcquireException should be thrown wrapping the original exception
        $this->expectException(LockAcquireException::class);
        $this->expectExceptionMessage("Failed to acquire lock for key `[test:exception-test]`");

        $locker->acquireTransactionLevelLock(
            $connectionAdapter,
            $lockKey,
            TimeoutDuration::zero(),
        );
    }

    public function testItThrowsLockAcquireExceptionOnDatabaseErrorForSessionLock(): void
    {
        // GIVEN: A mock connection adapter that throws a database error
        $locker = $this->initLocker();
        $lockKey = PostgresLockKey::create('test', 'exception-test');
        $connectionAdapter = $this->createMockConnectionWithDatabaseError();

        // WHEN: Attempting to acquire a session-level lock
        // THEN: LockAcquireException should be thrown wrapping the original exception
        $this->expectException(LockAcquireException::class);
        $this->expectExceptionMessage("Failed to acquire lock for key `[test:exception-test]`");

        $locker->acquireSessionLevelLock(
            $connectionAdapter,
            $lockKey,
            TimeoutDuration::zero(),
        );
    }

    public function testItReturnsWasAcquiredFalseForLockNotAvailableTimeout(): void
    {
        // GIVEN: Two database connections and a lock key
        $locker = $this->initLocker();
        $connection1 = $this->initConnectionAdapter();
        $connection2 = $this->initConnectionAdapter();
        $lockKey = PostgresLockKey::create('test', 'timeout-test');

        // GIVEN: First connection holds the lock
        $connection1->execute('BEGIN');
        $handle1 = $locker->acquireTransactionLevelLock(
            $connection1,
            $lockKey,
            TimeoutDuration::zero(),
        );
        $this->assertTrue($handle1->wasAcquired);

        // WHEN: Second connection attempts to acquire the same lock with a short timeout
        $connection2->execute('BEGIN');
        $handle2 = $locker->acquireTransactionLevelLock(
            $connection2,
            $lockKey,
            TimeoutDuration::ofMilliseconds(100),
        );

        // THEN: Lock should not be acquired, but no exception should be thrown
        $this->assertFalse($handle2->wasAcquired);
    }

    public function testItThrowsLockReleaseExceptionOnDatabaseErrorForRelease(): void
    {
        // GIVEN: A mock connection adapter that throws a database error on fetchColumn
        $locker = $this->initLocker();
        $lockKey = PostgresLockKey::create('test', 'release-exception-test');
        $connectionAdapter = $this->createMockConnectionWithDatabaseError();

        // WHEN: Attempting to release a session-level lock
        // THEN: LockReleaseException should be thrown wrapping the original exception
        $this->expectException(LockReleaseException::class);
        $this->expectExceptionMessage("Failed to release lock for key `[test:release-exception-test]`");

        $locker->releaseSessionLevelLock(
            $connectionAdapter,
            $lockKey,
        );
    }

    public function testItDistinguishesLockNotAvailableFromDatabaseErrors(): void
    {
        // GIVEN: Two connections where first holds the lock
        $locker = $this->initLocker();
        $connection1 = $this->initConnectionAdapter();
        $connection2 = $this->initConnectionAdapter();
        $lockKey = PostgresLockKey::create('test', 'distinguish-test');

        // GIVEN: First connection begins transaction and holds the lock
        $connection1->execute('BEGIN');
        $handle1 = $locker->acquireTransactionLevelLock(
            $connection1,
            $lockKey,
            TimeoutDuration::zero(),
        );
        $this->assertTrue($handle1->wasAcquired);

        // WHEN: Second connection tries with immediate timeout (lock_not_available is expected)
        $connection2->execute('BEGIN');
        $handle2 = $locker->acquireTransactionLevelLock(
            $connection2,
            $lockKey,
            TimeoutDuration::zero(),
        );

        // THEN: Should return false, not throw exception (this is normal lock contention)
        $this->assertFalse($handle2->wasAcquired);
    }

    private function createMockConnectionWithDatabaseError(): ConnectionAdapterInterface
    {
        return new class implements ConnectionAdapterInterface {
            public function fetchColumn(string $sql, array $params = []): mixed
            {
                // Simulate a database error (not lock_not_available)
                throw new \PDOException('Connection lost');
            }

            public function execute(string $sql, array $params = []): void
            {
                // Simulate a database error
                throw new \PDOException('Connection lost');
            }

            public function isTransactionActive(): bool
            {
                return true;
            }

            public function isLockNotAvailable(\Exception $exception): bool
            {
                // This mock returns false to ensure the exception is NOT treated as lock_not_available
                return false;
            }
        };
    }
}
