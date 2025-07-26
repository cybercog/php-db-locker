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
use Cog\Test\DbLocker\Unit\AbstractUnitTestCase;
use InvalidArgumentException;

final class LockIdTest extends AbstractUnitTestCase
{
    public function test_it_can_create_lock_id(): void
    {
        $lockId = new LockId('test');

        $this->assertSame('test', (string)$lockId);
    }

    public function test_it_can_create_lock_id_with_space_key(): void
    {
        $lockId = new LockId(' ');

        $this->assertSame(' ', (string)$lockId);
    }

    public function test_it_can_create_lock_id_with_spaced_key(): void
    {
        $lockId = new LockId('  test  ');

        $this->assertSame('  test  ', (string)$lockId);
    }

    public function test_it_can_create_lock_id_with_value(): void
    {
        $lockId = new LockId('test', '1');

        $this->assertSame('test:1', (string)$lockId);
    }

    public function test_it_can_create_lock_id_with_value_and_spaced_key(): void
    {
        $lockId = new LockId('  test  ', '1');

        $this->assertSame('  test  :1', (string)$lockId);
    }

    public function test_it_cannot_create_lock_id_with_empty_key(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LockId('', '1');
    }
}
