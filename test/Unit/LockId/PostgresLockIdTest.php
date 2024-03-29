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
    private const DB_INT64_VALUE_MIN = 0;
    private const DB_INT64_VALUE_MAX = 9223372036854775807;

    public function test_it_can_create_postgres_lock_id_with_min_id(): void
    {
        $lockId = new PostgresLockId(self::DB_INT64_VALUE_MIN);

        $this->assertSame(self::DB_INT64_VALUE_MIN, $lockId->id);
    }

    public function test_it_can_create_postgres_lock_id_with_max_id(): void
    {
        $lockId = new PostgresLockId(self::DB_INT64_VALUE_MAX);

        $this->assertSame(self::DB_INT64_VALUE_MAX, $lockId->id);
    }

    public function test_it_can_create_postgres_lock_id_from_lock_id(): void
    {
        $lockId = new LockId('test');

        $postgresLockId = PostgresLockId::fromLockId($lockId);

        $this->assertSame(3632233996, $postgresLockId->id);
    }

    public function test_it_can_create_postgres_lock_id_from_lock_id_with_value(): void
    {
        $lockId = new LockId('test', '1');

        $postgresLockId = PostgresLockId::fromLockId($lockId);

        $this->assertSame(782632948, $postgresLockId->id);
    }
}
