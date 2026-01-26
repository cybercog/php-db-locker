<?php

declare(strict_types=1);

namespace Cog\DbLocker\Test\Unit\Postgres;

use Cog\DbLocker\DbConnectionAdapter\DbConnectionAdapterInterface;
use Cog\DbLocker\DbConnectionAdapter\PdoDbConnectionAdapter;
use Cog\DbLocker\Postgres\PostgresLock;
use Cog\DbLocker\Postgres\PostgresLockFactory;
use Cog\DbLocker\Postgres\PostgresLockKey;
use PDO;
use PHPUnit\Framework\TestCase;

final class PostgresLockFactoryTest extends TestCase
{
    public function testItCanCreateLockFromPdoConnection(): void
    {
        $pdo = $this->createMock(PDO::class);
        
        $lock = PostgresLockFactory::create($pdo, 'test_namespace', 123);
        
        self::assertInstanceOf(PostgresLock::class, $lock);
        
        $lockKey = $lock->getLockKey();
        self::assertSame('test_namespace', $lockKey->humanReadableValue);
        self::assertSame(crc32('test_namespace'), $lockKey->classId);
        self::assertSame(123, $lockKey->objectId);
    }

    public function testItCanCreateLockFromAdapter(): void
    {
        $adapter = $this->createMock(DbConnectionAdapterInterface::class);
        
        $lock = PostgresLockFactory::create($adapter, 'adapter_namespace', 456);
        
        self::assertInstanceOf(PostgresLock::class, $lock);
        
        $lockKey = $lock->getLockKey();
        self::assertSame('adapter_namespace', $lockKey->humanReadableValue);
        self::assertSame(crc32('adapter_namespace'), $lockKey->classId);
        self::assertSame(456, $lockKey->objectId);
    }

    public function testItCanCreateLockFromStringWithPdo(): void
    {
        $pdo = $this->createMock(PDO::class);
        
        $lock = PostgresLockFactory::createFromString($pdo, 'string-lock-name');
        
        self::assertInstanceOf(PostgresLock::class, $lock);
        
        $lockKey = $lock->getLockKey();
        self::assertSame('string-lock-name', $lockKey->humanReadableValue);
        
        // PostgresLockKey::fromString() creates keys differently
        $expectedKey = PostgresLockKey::fromString('string-lock-name');
        self::assertSame($expectedKey->classId, $lockKey->classId);
        self::assertSame($expectedKey->objectId, $lockKey->objectId);
    }

    public function testItCanCreateLockFromStringWithAdapter(): void
    {
        $adapter = $this->createMock(DbConnectionAdapterInterface::class);
        
        $lock = PostgresLockFactory::createFromString($adapter, 'adapter-string-lock');
        
        self::assertInstanceOf(PostgresLock::class, $lock);
        
        $lockKey = $lock->getLockKey();
        self::assertSame('adapter-string-lock', $lockKey->humanReadableValue);
        
        // PostgresLockKey::fromString() creates keys differently
        $expectedKey = PostgresLockKey::fromString('adapter-string-lock');
        self::assertSame($expectedKey->classId, $lockKey->classId);
        self::assertSame($expectedKey->objectId, $lockKey->objectId);
    }

    public function testItConvertsIdParametersCorrectly(): void
    {
        $pdo = $this->createMock(PDO::class);
        
        // Test with different ID values
        $testCases = [
            ['namespace1', 0],
            ['namespace2', 1],
            ['namespace3', 999999],
            ['namespace4', -1], // This might be converted to unsigned
        ];
        
        foreach ($testCases as [$namespace, $id]) {
            $lock = PostgresLockFactory::create($pdo, $namespace, $id);
            $lockKey = $lock->getLockKey();
            
            self::assertSame($namespace, $lockKey->humanReadableValue);
            self::assertSame($id, $lockKey->objectId);
            self::assertSame(crc32($namespace), $lockKey->classId);
        }
    }

    public function testItHandlesDifferentNamespaces(): void
    {
        $pdo = $this->createMock(PDO::class);
        
        $testNamespaces = [
            'simple',
            'with-dashes',
            'with_underscores',
            'with.dots',
            'with spaces',
            'UPPERCASE',
            'MiXeDcAsE',
            'namespace123',
            '123numeric',
            'special!@#$%chars',
        ];
        
        foreach ($testNamespaces as $namespace) {
            $lock = PostgresLockFactory::create($pdo, $namespace, 1);
            $lockKey = $lock->getLockKey();
            
            self::assertSame($namespace, $lockKey->humanReadableValue);
            self::assertSame(crc32($namespace), $lockKey->classId);
        }
    }

    public function testItCreatesIndependentLockInstances(): void
    {
        $pdo = $this->createMock(PDO::class);
        $adapter = $this->createMock(DbConnectionAdapterInterface::class);
        
        $lock1 = PostgresLockFactory::create($pdo, 'test', 1);
        $lock2 = PostgresLockFactory::create($adapter, 'test', 1);
        $lock3 = PostgresLockFactory::createFromString($pdo, 'test-string');
        
        // All locks should be different instances
        self::assertNotSame($lock1, $lock2);
        self::assertNotSame($lock1, $lock3);
        self::assertNotSame($lock2, $lock3);
        
        // But locks with same parameters should have equivalent keys
        $lock1Key = $lock1->getLockKey();
        $lock2Key = $lock2->getLockKey();
        
        self::assertSame($lock1Key->humanReadableValue, $lock2Key->humanReadableValue);
        self::assertSame($lock1Key->classId, $lock2Key->classId);
        self::assertSame($lock1Key->objectId, $lock2Key->objectId);
    }

    public function testItPreservesConnectionType(): void
    {
        $pdo = $this->createMock(PDO::class);
        $adapter = $this->createMock(DbConnectionAdapterInterface::class);
        
        $pdoLock = PostgresLockFactory::create($pdo, 'test', 1);
        $adapterLock = PostgresLockFactory::create($adapter, 'test', 1);
        
        // Both should create valid PostgresLock instances
        self::assertInstanceOf(PostgresLock::class, $pdoLock);
        self::assertInstanceOf(PostgresLock::class, $adapterLock);
        
        // The locks should be able to report platform info if adapters support it
        $adapter->method('getPlatformName')->willReturn('postgresql');
        self::assertSame('postgresql', $adapterLock->getPlatformName());
    }

    public function testItHandlesEmptyNamespace(): void
    {
        $pdo = $this->createMock(PDO::class);
        
        $lock = PostgresLockFactory::create($pdo, '', 123);
        $lockKey = $lock->getLockKey();
        
        self::assertSame('', $lockKey->humanReadableValue);
        self::assertSame(crc32(''), $lockKey->classId);
        self::assertSame(123, $lockKey->objectId);
    }

    public function testItHandlesEmptyStringLock(): void
    {
        $pdo = $this->createMock(PDO::class);
        
        $lock = PostgresLockFactory::createFromString($pdo, '');
        $lockKey = $lock->getLockKey();
        
        self::assertSame('', $lockKey->humanReadableValue);
        
        $expectedKey = PostgresLockKey::fromString('');
        self::assertSame($expectedKey->classId, $lockKey->classId);
        self::assertSame($expectedKey->objectId, $lockKey->objectId);
    }
}