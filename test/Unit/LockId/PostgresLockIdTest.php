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

use Cog\DbLocker\LockId\PostgresLockId;
use Cog\Test\DbLocker\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PostgresLockIdTest extends AbstractUnitTestCase
{
    private const DB_INT32_VALUE_MIN = -2_147_483_648;
    private const DB_INT32_VALUE_MAX = 2_147_483_647;

    #[DataProvider('provideItCanCreatePostgresLockIdFromKeyValueData')]
    public function testItCanCreatePostgresLockIdFromKeyValue(
        string $key,
        string $value,
        int $expectedClassId,
        int $expectedObjectId,
    ): void {
        $postgresLockId = PostgresLockId::fromKeyValue($key, $value);

        $this->assertSame($expectedClassId, $postgresLockId->classId);
        $this->assertSame($expectedObjectId, $postgresLockId->objectId);
    }

    public static function provideItCanCreatePostgresLockIdFromKeyValueData(): array
    {
        return [
            'key + empty value' => [
                'test',
                '',
                -662733300,
                0,
            ],
            'key + value' => [
                'test',
                '1',
                -662733300,
                -2082672713,
            ],
        ];
    }

    #[DataProvider('provideItCanCreatePostgresLockIdFromIntKeysData')]
    public function testItCanCreatePostgresLockIdFromIntKeys(
        int $classId,
        int $objectId,
    ): void {
        $lockId = PostgresLockId::fromIntKeys($classId, $objectId);

        $this->assertSame($classId, $lockId->classId);
        $this->assertSame($objectId, $lockId->objectId);
    }

    public static function provideItCanCreatePostgresLockIdFromIntKeysData(): array
    {
        return [
            'min class_id' => [
                self::DB_INT32_VALUE_MIN,
                0,
            ],
            'max class_id' => [
                self::DB_INT32_VALUE_MAX,
                0,
            ],
            'min object_id' => [
                0,
                self::DB_INT32_VALUE_MIN,
            ],
            'max object_id' => [
                0,
                self::DB_INT32_VALUE_MAX,
            ],
        ];
    }

    #[DataProvider('provideItCanCreatePostgresLockIdFromOutOfRangeIntKeysData')]
    public function testItCanNotCreatePostgresLockIdFromOutOfRangeIntKeys(
        int $classId,
        int $objectId,
        string $expectedExceptionMessage,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $lockId = PostgresLockId::fromIntKeys($classId, $objectId);

        $this->assertSame($classId, $lockId->classId);
        $this->assertSame($objectId, $lockId->objectId);
    }

    public static function provideItCanCreatePostgresLockIdFromOutOfRangeIntKeysData(): array
    {
        return [
            'min class_id' => [
                self::DB_INT32_VALUE_MIN - 1,
                0,
                "Out of bound exception (classId=-2147483649 is too small)",
            ],
            'max class_id' => [
                self::DB_INT32_VALUE_MAX + 1,
                0,
                "Out of bound exception (classId=2147483648 is too big)",
            ],
            'min object_id' => [
                0,
                self::DB_INT32_VALUE_MIN - 1,
                "Out of bound exception (objectId=-2147483649 is too small)",
            ],
            'max object_id' => [
                0,
                self::DB_INT32_VALUE_MAX + 1,
                "Out of bound exception (objectId=2147483648 is too big)",
            ],
        ];
    }
}
