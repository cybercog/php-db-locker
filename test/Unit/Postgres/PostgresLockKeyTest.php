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

namespace Cog\Test\DbLocker\Unit\Postgres;

use Cog\DbLocker\Postgres\PostgresLockKey;
use Cog\Test\DbLocker\Unit\AbstractUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class PostgresLockKeyTest extends AbstractUnitTestCase
{
    private const DB_INT32_VALUE_MIN = -2_147_483_648;
    private const DB_INT32_VALUE_MAX = 2_147_483_647;

    #[DataProvider('provideItCanCreatePostgresLockKeyFromNamespaceValueData')]
    public function testItCanCreatePostgresLockKeyFromNamespaceValue(
        string $key,
        string $value,
        int $expectedClassId,
        int $expectedObjectId,
    ): void {
        // GIVEN: A namespace key and value string
        // WHEN: Creating a PostgresLockKey from the namespace and value
        $lockKey = PostgresLockKey::create($key, $value);

        // THEN: Lock key should have the expected classId and objectId generated via CRC32 hash
        $this->assertSame($expectedClassId, $lockKey->classId);
        $this->assertSame($expectedObjectId, $lockKey->objectId);
    }

    public static function provideItCanCreatePostgresLockKeyFromNamespaceValueData(): array
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

    #[DataProvider('provideItCanCreatePostgresLockKeyFromIntKeysData')]
    public function testItCanCreatePostgresLockKeyFromIntKeys(
        int $classId,
        int $objectId,
    ): void {
        // GIVEN: Valid int32 boundary values for classId and objectId
        // WHEN: Creating a PostgresLockKey from internal IDs
        $lockKey = PostgresLockKey::createFromInternalIds($classId, $objectId);

        // THEN: Lock key should contain the exact provided classId and objectId
        $this->assertSame($classId, $lockKey->classId);
        $this->assertSame($objectId, $lockKey->objectId);
    }

    public static function provideItCanCreatePostgresLockKeyFromIntKeysData(): array
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

    #[DataProvider('provideItCanCreatePostgresLockKeyFromOutOfRangeIntKeysData')]
    public function testItCanNotCreatePostgresLockKeyFromOutOfRangeIntKeys(
        int $classId,
        int $objectId,
        string $expectedExceptionMessage,
    ): void {
        // GIVEN: Integer values outside the int32 range (-2147483648 to 2147483647)
        // WHEN: Attempting to create a PostgresLockKey from out-of-range internal IDs
        // THEN: Should throw InvalidArgumentException with descriptive message
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        $lockKey = PostgresLockKey::createFromInternalIds($classId, $objectId);

        $this->assertSame($classId, $lockKey->classId);
        $this->assertSame($objectId, $lockKey->objectId);
    }

    public static function provideItCanCreatePostgresLockKeyFromOutOfRangeIntKeysData(): array
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
