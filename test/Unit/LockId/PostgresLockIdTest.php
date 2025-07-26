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
    private const DB_INT32_VALUE_MIN = -2_147_483_648;
    private const DB_INT32_VALUE_MAX = 2_147_483_647;

    public function test_it_can_create_postgres_lock_id_with_min_class_id(): void
    {
        $lockId = new PostgresLockId(self::DB_INT32_VALUE_MIN, 0);

        $this->assertSame(self::DB_INT32_VALUE_MIN, $lockId->classId);
    }

    public function test_it_can_create_postgres_lock_id_with_max_class_id(): void
    {
        $lockId = new PostgresLockId(self::DB_INT32_VALUE_MAX, 0);

        $this->assertSame(self::DB_INT32_VALUE_MAX, $lockId->classId);
    }

    public function test_it_can_create_postgres_lock_id_with_min_object_id(): void
    {
        $lockId = new PostgresLockId(0, self::DB_INT32_VALUE_MIN);

        $this->assertSame(self::DB_INT32_VALUE_MIN, $lockId->objectId);
    }

    public function test_it_can_create_postgres_lock_id_with_max_object_id(): void
    {
        $lockId = new PostgresLockId(0, self::DB_INT32_VALUE_MAX);

        $this->assertSame(self::DB_INT32_VALUE_MAX, $lockId->objectId);
    }

    public function test_it_can_create_postgres_lock_id_from_lock_id(): void
    {
        $lockId = new LockId('test');

        $postgresLockId = PostgresLockId::fromLockId($lockId);

        $this->assertSame(-662733300, $postgresLockId->classId);
    }

    public function test_it_can_create_postgres_lock_id_from_lock_id_with_value(): void
    {
        $lockId = new LockId('test', '1');

        $postgresLockId = PostgresLockId::fromLockId($lockId);

        $this->assertSame(-662733300, $postgresLockId->classId);
    }
}
