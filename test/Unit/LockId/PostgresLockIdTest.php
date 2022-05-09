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

namespace Cog\Test\DbLocker\Unit\LockId;

use Cog\DbLocker\LockId\LockId;
use Cog\DbLocker\LockId\PostgresLockId;
use Cog\Test\DbLocker\Unit\AbstractUnitTestCase;
use InvalidArgumentException;

final class PostgresLockIdTest extends AbstractUnitTestCase
{
    private const MIN_INT_DB_VALUE = -2147483648;
    private const MAX_INT_DB_VALUE = 2147483647;

    /** @test */
    public function it_can_create_postgres_lock_id_with_min_id(): void
    {
        $lockId = new PostgresLockId(self::MIN_INT_DB_VALUE);

        $this->assertSame(self::MIN_INT_DB_VALUE, $lockId->id());
    }

    /** @test */
    public function it_can_create_postgres_lock_id_with_max_id(): void
    {
        $lockId = new PostgresLockId(self::MAX_INT_DB_VALUE);

        $this->assertSame(self::MAX_INT_DB_VALUE, $lockId->id());
    }

    /** @test */
    public function it_cannot_create_postgres_lock_id_with_too_small_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PostgresLockId(self::MIN_INT_DB_VALUE - 1);
    }

    /** @test */
    public function it_cannot_create_postgres_lock_id_with_too_big_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PostgresLockId(self::MAX_INT_DB_VALUE + 1);
    }

    /** @test */
    public function it_can_create_postgres_lock_id_from_lock_id(): void
    {
        $lockId = new LockId('test');

        $postgresLockId = PostgresLockId::fromLockId($lockId);

        $this->assertSame(1484750348, $postgresLockId->id());
    }

    /** @test */
    public function it_can_create_postgres_lock_id_from_lock_id_with_value(): void
    {
        $lockId = new LockId('test', '1');

        $postgresLockId = PostgresLockId::fromLockId($lockId);

        $this->assertSame(-1364850700, $postgresLockId->id());
    }
}
