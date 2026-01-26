<?php

declare(strict_types=1);

namespace Cog\DbLocker\Test\Integration\Postgres;

use Cog\DbLocker\DbConnectionAdapter\PdoDbConnectionAdapter;
use Cog\DbLocker\Postgres\Enum\PostgresLockAccessModeEnum;
use Cog\DbLocker\Postgres\Enum\PostgresLockWaitModeEnum;
use Cog\DbLocker\Postgres\PostgresLockFactory;
use LogicException;
use PDO;
use PHPUnit\Framework\TestCase;

final class PostgresLockTest extends TestCase
{
    /**
     * @dataProvider provideItCanAcquireTransactionLevelLockData
     */
    public function testItCanAcquireTransactionLevelLockWithPdo(
        PostgresLockWaitModeEnum $waitMode,
        PostgresLockAccessModeEnum $accessMode,
    ): void {
        $dbConnection = $this->initDbConnection();
        $lock = PostgresLockFactory::create($dbConnection, 'test_transaction', 1);

        $dbConnection->beginTransaction();

        $acquired = $lock->acquireTransactionLevel($waitMode, $accessMode);

        self::assertTrue($acquired);

        $dbConnection->commit();
    }

    /**
     * @dataProvider provideItCanAcquireTransactionLevelLockData
     */
    public function testItCanAcquireTransactionLevelLockWithAdapter(
        PostgresLockWaitModeEnum $waitMode,
        PostgresLockAccessModeEnum $accessMode,
    ): void {
        $dbConnection = $this->initDbConnection();
        $adapter = new PdoDbConnectionAdapter($dbConnection);
        $lock = PostgresLockFactory::create($adapter, 'test_transaction_adapter', 1);

        $dbConnection->beginTransaction();

        $acquired = $lock->acquireTransactionLevel($waitMode, $accessMode);

        self::assertTrue($acquired);

        $dbConnection->commit();
    }

    public static function provideItCanAcquireTransactionLevelLockData(): iterable
    {
        yield [PostgresLockWaitModeEnum::NonBlocking, PostgresLockAccessModeEnum::Exclusive];
        yield [PostgresLockWaitModeEnum::NonBlocking, PostgresLockAccessModeEnum::Share];
        yield [PostgresLockWaitModeEnum::Blocking, PostgresLockAccessModeEnum::Exclusive];
        yield [PostgresLockWaitModeEnum::Blocking, PostgresLockAccessModeEnum::Share];
    }

    public function testItCannotAcquireTransactionLevelLockOutsideTransaction(): void
    {
        $dbConnection = $this->initDbConnection();
        $lock = PostgresLockFactory::create($dbConnection, 'test_no_transaction', 1);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage("Transaction-level advisory lock `test_no_transaction` cannot be acquired outside of transaction");

        $lock->acquireTransactionLevel();
    }

    /**
     * @dataProvider provideItCanAcquireSessionLevelLockData
     */
    public function testItCanAcquireSessionLevelLock(
        PostgresLockWaitModeEnum $waitMode,
        PostgresLockAccessModeEnum $accessMode,
    ): void {
        $dbConnection = $this->initDbConnection();
        $lock = PostgresLockFactory::create($dbConnection, 'test_session', 2);

        $lockHandle = $lock->acquireSessionLevel($waitMode, $accessMode);

        self::assertTrue($lockHandle->wasAcquired);
        self::assertSame($lock->getLockKey(), $lockHandle->lockKey);
        self::assertSame($accessMode, $lockHandle->accessMode);

        $released = $lock->release($accessMode);
        self::assertTrue($released);
    }

    public static function provideItCanAcquireSessionLevelLockData(): iterable
    {
        yield [PostgresLockWaitModeEnum::NonBlocking, PostgresLockAccessModeEnum::Exclusive];
        yield [PostgresLockWaitModeEnum::NonBlocking, PostgresLockAccessModeEnum::Share];
        yield [PostgresLockWaitModeEnum::Blocking, PostgresLockAccessModeEnum::Exclusive];
        yield [PostgresLockWaitModeEnum::Blocking, PostgresLockAccessModeEnum::Share];
    }

    public function testItCanExecuteCodeWithinSessionLock(): void
    {
        $dbConnection = $this->initDbConnection();
        $lock = PostgresLockFactory::create($dbConnection, 'test_within', 3);

        $executed = false;
        $lockWasAcquired = false;

        $result = $lock->withinSessionLock(
            function ($lockHandle) use (&$executed, &$lockWasAcquired) {
                $executed = true;
                $lockWasAcquired = $lockHandle->wasAcquired;
                return 'test-result';
            },
        );

        self::assertTrue($executed);
        self::assertTrue($lockWasAcquired);
        self::assertSame('test-result', $result);
    }

    public function testItCannotAcquireSameLockInTwoConnections(): void
    {
        $dbConnection1 = $this->initDbConnection();
        $dbConnection2 = $this->initDbConnection();

        $lock1 = PostgresLockFactory::create($dbConnection1, 'test_conflict', 4);
        $lock2 = PostgresLockFactory::create($dbConnection2, 'test_conflict', 4);

        $lockHandle1 = $lock1->acquireSessionLevel();
        self::assertTrue($lockHandle1->wasAcquired);

        $lockHandle2 = $lock2->acquireSessionLevel();
        self::assertFalse($lockHandle2->wasAcquired);

        $released = $lock1->release();
        self::assertTrue($released);
    }

    public function testItCanAcquireLockInSecondConnectionAfterRelease(): void
    {
        $dbConnection1 = $this->initDbConnection();
        $dbConnection2 = $this->initDbConnection();

        $lock1 = PostgresLockFactory::create($dbConnection1, 'test_release', 5);
        $lock2 = PostgresLockFactory::create($dbConnection2, 'test_release', 5);

        $lockHandle1 = $lock1->acquireSessionLevel();
        self::assertTrue($lockHandle1->wasAcquired);

        $released = $lock1->release();
        self::assertTrue($released);

        $lockHandle2 = $lock2->acquireSessionLevel();
        self::assertTrue($lockHandle2->wasAcquired);

        $lock2->release();
    }

    public function testItCanReleaseAllLocks(): void
    {
        $dbConnection = $this->initDbConnection();
        $dbConnection2 = $this->initDbConnection();

        $lock1 = PostgresLockFactory::create($dbConnection, 'test_release_all_1', 6);
        $lock2 = PostgresLockFactory::create($dbConnection, 'test_release_all_2', 7);

        $lockHandle1 = $lock1->acquireSessionLevel();
        $lockHandle2 = $lock2->acquireSessionLevel();

        self::assertTrue($lockHandle1->wasAcquired);
        self::assertTrue($lockHandle2->wasAcquired);

        // Release all locks
        $lock1->releaseAll();

        // Try to acquire same locks from different connection - should succeed
        $lock3 = PostgresLockFactory::create($dbConnection2, 'test_release_all_1', 6);
        $lock4 = PostgresLockFactory::create($dbConnection2, 'test_release_all_2', 7);

        $lockHandle3 = $lock3->acquireSessionLevel();
        $lockHandle4 = $lock4->acquireSessionLevel();

        self::assertTrue($lockHandle3->wasAcquired);
        self::assertTrue($lockHandle4->wasAcquired);

        $lock3->releaseAll();
    }

    public function testItCanAutoReleaseLockAcquiredWithinTransactionOnCommit(): void
    {
        $dbConnection1 = $this->initDbConnection();
        $dbConnection2 = $this->initDbConnection();

        $lock1 = PostgresLockFactory::create($dbConnection1, 'test_auto_release_commit', 8);
        $lock2 = PostgresLockFactory::create($dbConnection2, 'test_auto_release_commit', 8);

        $dbConnection1->beginTransaction();
        $acquired = $lock1->acquireTransactionLevel();
        self::assertTrue($acquired);
        $dbConnection1->commit();

        // Lock should be automatically released after commit
        $lockHandle = $lock2->acquireSessionLevel();
        self::assertTrue($lockHandle->wasAcquired);

        $lock2->release();
    }

    public function testItCanAutoReleaseLockAcquiredWithinTransactionOnRollback(): void
    {
        $dbConnection1 = $this->initDbConnection();
        $dbConnection2 = $this->initDbConnection();

        $lock1 = PostgresLockFactory::create($dbConnection1, 'test_auto_release_rollback', 9);
        $lock2 = PostgresLockFactory::create($dbConnection2, 'test_auto_release_rollback', 9);

        $dbConnection1->beginTransaction();
        $acquired = $lock1->acquireTransactionLevel();
        self::assertTrue($acquired);
        $dbConnection1->rollback();

        // Lock should be automatically released after rollback
        $lockHandle = $lock2->acquireSessionLevel();
        self::assertTrue($lockHandle->wasAcquired);

        $lock2->release();
    }

    public function testItCanCheckTransactionStatus(): void
    {
        $dbConnection = $this->initDbConnection();
        $lock = PostgresLockFactory::create($dbConnection, 'test_transaction_status', 10);

        self::assertFalse($lock->isInTransaction());

        $dbConnection->beginTransaction();
        self::assertTrue($lock->isInTransaction());

        $dbConnection->commit();
        self::assertFalse($lock->isInTransaction());
    }

    public function testItCanGetPlatformName(): void
    {
        $dbConnection = $this->initDbConnection();
        $lock = PostgresLockFactory::create($dbConnection, 'test_platform', 11);

        $platformName = $lock->getPlatformName();
        self::assertSame('postgresql', $platformName);
    }

    public function testLockHandleCanReleaseItself(): void
    {
        $dbConnection = $this->initDbConnection();
        $lock = PostgresLockFactory::create($dbConnection, 'test_handle_release', 12);

        $lockHandle = $lock->acquireSessionLevel();
        self::assertTrue($lockHandle->wasAcquired);

        $released = $lockHandle->release();
        self::assertTrue($released);

        // Try to release again - should return false
        $releasedAgain = $lockHandle->release();
        self::assertFalse($releasedAgain);

        // Lock should be available for other connections
        $dbConnection2 = $this->initDbConnection();
        $lock2 = PostgresLockFactory::create($dbConnection2, 'test_handle_release', 12);
        $lockHandle2 = $lock2->acquireSessionLevel();
        self::assertTrue($lockHandle2->wasAcquired);

        $lock2->release();
    }

    public function testItWorksWithExceptionHandling(): void
    {
        $dbConnection = $this->initDbConnection();
        $lock = PostgresLockFactory::create($dbConnection, 'test_exception_handling', 13);

        $exceptionThrown = false;

        try {
            $lock->withinSessionLock(
                function ($lockHandle) {
                    self::assertTrue($lockHandle->wasAcquired);
                    throw new \RuntimeException('Test exception');
                },
            );
        } catch (\RuntimeException $e) {
            $exceptionThrown = true;
            self::assertSame('Test exception', $e->getMessage());
        }

        self::assertTrue($exceptionThrown);

        // Lock should be released even after exception
        $dbConnection2 = $this->initDbConnection();
        $lock2 = PostgresLockFactory::create($dbConnection2, 'test_exception_handling', 13);
        $lockHandle2 = $lock2->acquireSessionLevel();
        self::assertTrue($lockHandle2->wasAcquired);

        $lock2->release();
    }

    public function testItWorksWithStringBasedLocks(): void
    {
        $dbConnection = $this->initDbConnection();
        $lock = PostgresLockFactory::createFromString($dbConnection, 'string-based-lock');

        $lockHandle = $lock->acquireSessionLevel();
        self::assertTrue($lockHandle->wasAcquired);
        self::assertSame('string-based-lock', $lockHandle->lockKey->humanReadableValue);

        $released = $lock->release();
        self::assertTrue($released);
    }

    public function testItWorksWithMixedAdapterTypes(): void
    {
        $dbConnection1 = $this->initDbConnection();
        $dbConnection2 = $this->initDbConnection();
        $adapter = new PdoDbConnectionAdapter($dbConnection2);

        $lock1 = PostgresLockFactory::create($dbConnection1, 'mixed_adapter_test', 14);
        $lock2 = PostgresLockFactory::create($adapter, 'mixed_adapter_test', 14);

        // Lock with PDO
        $lockHandle1 = $lock1->acquireSessionLevel();
        self::assertTrue($lockHandle1->wasAcquired);

        // Try to lock with adapter - should fail
        $lockHandle2 = $lock2->acquireSessionLevel();
        self::assertFalse($lockHandle2->wasAcquired);

        // Release with PDO
        $released = $lock1->release();
        self::assertTrue($released);

        // Now adapter should be able to acquire the lock
        $lockHandle3 = $lock2->acquireSessionLevel();
        self::assertTrue($lockHandle3->wasAcquired);

        $lock2->release();
    }

    public function testItPreservesLockKeyInformation(): void
    {
        $dbConnection = $this->initDbConnection();
        $lock = PostgresLockFactory::create($dbConnection, 'preserve_test', 999);

        $lockKey = $lock->getLockKey();
        self::assertSame('preserve_test', $lockKey->humanReadableValue);
        self::assertSame(999, $lockKey->objectId);
        self::assertSame(crc32('preserve_test'), $lockKey->classId);
    }

    private function initDbConnection(): PDO
    {
        $host = $_ENV['POSTGRES_HOST'] ?? 'localhost';
        $port = $_ENV['POSTGRES_PORT'] ?? '5432';
        $database = $_ENV['POSTGRES_DB'] ?? 'db_locker_test';
        $username = $_ENV['POSTGRES_USER'] ?? 'db_locker_test';
        $password = $_ENV['POSTGRES_PASSWORD'] ?? 'db_locker_test';

        $dsn = "pgsql:host={$host};port={$port};dbname={$database}";

        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}