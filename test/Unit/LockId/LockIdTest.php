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
use PHPUnit\Framework\Attributes\DataProvider;

final class LockIdTest extends AbstractUnitTestCase
{
    #[DataProvider('provideItCanCreateLockIdData')]
    public function testItCanCreateLockId(
        string $key,
        string $value,
        string $expectedCompiledId,
    ): void {
        $lockId = new LockId($key, $value);

        $this->assertSame($key, $lockId->key);
        $this->assertSame($value, $lockId->value);
        $this->assertSame($expectedCompiledId, (string)$lockId);
    }

    public static function provideItCanCreateLockIdData(): array
    {
        return [
            'key only' => [
                'test',
                '',
                'test',
            ],
            'key space' => [
                ' ',
                '',
                ' ',
            ],
            'key space + value space' => [
                ' ',
                ' ',
                ' : ',
            ],
            'key + value' => [
                ' test ',
                ' 12 ',
                ' test : 12 ',
            ],
        ];
    }

    public function testItCannotCreateLockIdWithEmptyKey(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new LockId('', '1');
    }
}
