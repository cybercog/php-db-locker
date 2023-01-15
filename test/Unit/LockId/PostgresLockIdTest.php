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

final class PostgresLockIdTest extends AbstractUnitTestCase
{
    private const MIN_DB_INT_VALUE = 0;
    private const MAX_DB_INT_VALUE = 9223372036854775807;

    /** @test */
    public function it_can_create_postgres_lock_id_with_min_id(): void
    {
        $lockId = new PostgresLockId(self::MIN_DB_INT_VALUE);

        $this->assertSame(self::MIN_DB_INT_VALUE, $lockId->id());
    }

    /** @test */
    public function it_can_create_postgres_lock_id_with_max_id(): void
    {
        $lockId = new PostgresLockId(self::MAX_DB_INT_VALUE);

        $this->assertSame(self::MAX_DB_INT_VALUE, $lockId->id());
    }

    /** @test */
    public function it_can_create_postgres_lock_id_from_lock_id(): void
    {
        $lockId = new LockId('test');

        $postgresLockId = PostgresLockId::fromLockId($lockId);

        $this->assertSame(3632233996, $postgresLockId->id());
    }

    /** @test */
    public function it_can_create_postgres_lock_id_from_lock_id_with_value(): void
    {
        $lockId = new LockId('test', '1');

        $postgresLockId = PostgresLockId::fromLockId($lockId);

        $this->assertSame(782632948, $postgresLockId->id());
    }
}
