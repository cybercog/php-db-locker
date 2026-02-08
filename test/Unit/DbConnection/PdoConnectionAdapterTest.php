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

namespace Cog\Test\DbLocker\Unit\DbConnection;

use Cog\DbLocker\DbConnection\PdoConnectionAdapter;
use PDO;
use PHPUnit\Framework\TestCase;

final class PdoConnectionAdapterTest extends TestCase
{
    public function testItThrowsExceptionWhenPdoIsNotInExceptionMode(): void
    {
        // GIVEN: A PDO connection with silent error mode
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())
            ->method('getAttribute')
            ->with(PDO::ATTR_ERRMODE)
            ->willReturn(PDO::ERRMODE_SILENT);

        // WHEN: Attempting to create an adapter with non-exception error mode
        // THEN: Should throw LogicException
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('PDO connection must use PDO::ERRMODE_EXCEPTION');

        new PdoConnectionAdapter($pdo);
    }

    public function testItAcceptsPdoWithExceptionMode(): void
    {
        // GIVEN: A PDO connection with exception error mode
        $pdo = $this->createMock(PDO::class);
        $pdo->expects($this->once())
            ->method('getAttribute')
            ->with(PDO::ATTR_ERRMODE)
            ->willReturn(PDO::ERRMODE_EXCEPTION);

        // WHEN: Creating an adapter
        $adapter = new PdoConnectionAdapter($pdo);

        // THEN: Adapter is created successfully
        $this->assertInstanceOf(PdoConnectionAdapter::class, $adapter);
    }

    public function testFetchColumnExecutesQueryAndReturnsScalar(): void
    {
        // GIVEN: A PDO connection with a prepared statement
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->willReturn(PDO::ERRMODE_EXCEPTION);

        $statement = $this->createMock(\PDOStatement::class);
        $pdo->expects($this->once())
            ->method('prepare')
            ->with('SELECT 1 AS test')
            ->willReturn($statement);

        $statement->expects($this->once())
            ->method('execute')
            ->with(['param' => 'value']);

        $statement->expects($this->once())
            ->method('fetchColumn')
            ->with(0)
            ->willReturn(42);

        $adapter = new PdoConnectionAdapter($pdo);

        // WHEN: Calling fetchColumn with SQL and parameters
        $result = $adapter->fetchColumn('SELECT 1 AS test', ['param' => 'value']);

        // THEN: Result is returned correctly
        $this->assertSame(42, $result);
    }

    public function testExecuteWithParametersUsesPrepareAndExecute(): void
    {
        // GIVEN: A PDO connection with a prepared statement
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->willReturn(PDO::ERRMODE_EXCEPTION);

        $statement = $this->createMock(\PDOStatement::class);
        $pdo->expects($this->once())
            ->method('prepare')
            ->with('INSERT INTO test VALUES (:value)')
            ->willReturn($statement);

        $statement->expects($this->once())
            ->method('execute')
            ->with(['value' => 123]);

        $adapter = new PdoConnectionAdapter($pdo);

        // WHEN: Calling execute with SQL and parameters
        $adapter->execute('INSERT INTO test VALUES (:value)', ['value' => 123]);

        // THEN: Statement is prepared and executed (verified by mock expectations)
    }

    public function testExecuteWithoutParametersUsesExec(): void
    {
        // GIVEN: A PDO connection
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->willReturn(PDO::ERRMODE_EXCEPTION);

        $pdo->expects($this->once())
            ->method('exec')
            ->with('SET LOCAL lock_timeout = 5000');

        $adapter = new PdoConnectionAdapter($pdo);

        // WHEN: Calling execute without parameters
        $adapter->execute('SET LOCAL lock_timeout = 5000');

        // THEN: PDO exec() is used (verified by mock expectations)
    }

    public function testIsTransactionActiveReturnsTrueWhenInTransaction(): void
    {
        // GIVEN: A PDO connection within a transaction
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->willReturn(PDO::ERRMODE_EXCEPTION);
        $pdo->expects($this->once())
            ->method('inTransaction')
            ->willReturn(true);

        $adapter = new PdoConnectionAdapter($pdo);

        // WHEN: Checking transaction status
        $result = $adapter->isTransactionActive();

        // THEN: Returns true
        $this->assertTrue($result);
    }

    public function testIsTransactionActiveReturnsFalseWhenNotInTransaction(): void
    {
        // GIVEN: A PDO connection not in a transaction
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->willReturn(PDO::ERRMODE_EXCEPTION);
        $pdo->expects($this->once())
            ->method('inTransaction')
            ->willReturn(false);

        $adapter = new PdoConnectionAdapter($pdo);

        // WHEN: Checking transaction status
        $result = $adapter->isTransactionActive();

        // THEN: Returns false
        $this->assertFalse($result);
    }

    public function testIsLockNotAvailableReturnsTrueForPdoExceptionWith55P03(): void
    {
        // GIVEN: A PDO connection and a PDOException with SQLSTATE 55P03
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->willReturn(PDO::ERRMODE_EXCEPTION);
        $adapter = new PdoConnectionAdapter($pdo);

        $exception = new \PDOException('Lock timeout');
        // Set the SQLSTATE code via reflection since PDOException doesn't allow setting it directly
        $reflection = new \ReflectionClass($exception);
        $property = $reflection->getProperty('code');
        $property->setAccessible(true);
        $property->setValue($exception, '55P03');

        // WHEN: Checking if exception is lock_not_available
        $result = $adapter->isLockNotAvailable($exception);

        // THEN: Returns true
        $this->assertTrue($result);
    }

    public function testIsLockNotAvailableReturnsFalseForPdoExceptionWithDifferentCode(): void
    {
        // GIVEN: A PDO connection and a PDOException with different SQLSTATE
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->willReturn(PDO::ERRMODE_EXCEPTION);
        $adapter = new PdoConnectionAdapter($pdo);

        $exception = new \PDOException('Different error');
        $reflection = new \ReflectionClass($exception);
        $property = $reflection->getProperty('code');
        $property->setAccessible(true);
        $property->setValue($exception, '23505'); // Unique violation

        // WHEN: Checking if exception is lock_not_available
        $result = $adapter->isLockNotAvailable($exception);

        // THEN: Returns false
        $this->assertFalse($result);
    }

    public function testIsLockNotAvailableReturnsFalseForNonPdoException(): void
    {
        // GIVEN: A PDO connection and a non-PDO exception
        $pdo = $this->createMock(PDO::class);
        $pdo->method('getAttribute')->willReturn(PDO::ERRMODE_EXCEPTION);
        $adapter = new PdoConnectionAdapter($pdo);

        $exception = new \RuntimeException('Some other error');

        // WHEN: Checking if exception is lock_not_available
        $result = $adapter->isLockNotAvailable($exception);

        // THEN: Returns false
        $this->assertFalse($result);
    }
}
