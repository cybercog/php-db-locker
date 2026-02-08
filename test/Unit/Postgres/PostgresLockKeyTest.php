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

        // THEN: Lock key should have the expected classId and objectId generated via xxh3 hash
        $this->assertSame($expectedClassId, $lockKey->classId);
        $this->assertSame($expectedObjectId, $lockKey->objectId);
    }

    public static function provideItCanCreatePostgresLockKeyFromNamespaceValueData(): array
    {
        return [
            'key + empty value' => [
                'test',
                '',
                1933805137,
                1940164458,
            ],
            'key + value' => [
                'test',
                '1',
                1454951023,
                1735669873,
            ],
        ];
    }

    public function testItCanCreatePostgresLockKeyFromNamespaceValueWithCustomHumanReadableValue(): void
    {
        // GIVEN: A namespace, value, and a custom humanReadableValue
        // WHEN: Creating a PostgresLockKey with a custom humanReadableValue
        $lockKey = PostgresLockKey::create('App\Order', '42', 'order:42');

        // THEN: Lock key should use the provided humanReadableValue with namespace:value suffix
        $this->assertSame('order:42[App\Order:42]', $lockKey->humanReadableValue);
    }

    public function testItSanitizesCustomHumanReadableValueInCreate(): void
    {
        // GIVEN: A custom humanReadableValue containing control characters
        // WHEN: Creating a PostgresLockKey with that custom humanReadableValue
        $lockKey = PostgresLockKey::create('ns', 'val', "custom\n; DROP TABLE users; --");

        // THEN: humanReadableValue should have control characters stripped and include namespace:value suffix
        $this->assertSame('custom; DROP TABLE users; --[ns:val]', $lockKey->humanReadableValue);
    }

    #[DataProvider('provideItCanCreatePostgresLockKeyFromIntKeysData')]
    public function testItCanCreatePostgresLockKeyFromIntKeys(
        int $classId,
        int $objectId,
        string $expectedHumanReadableValue,
    ): void {
        // GIVEN: Valid int32 boundary values for classId and objectId
        // WHEN: Creating a PostgresLockKey from internal IDs
        $lockKey = PostgresLockKey::createFromInternalIds($classId, $objectId);

        // THEN: Lock key should contain the exact provided classId and objectId and a default humanReadableValue
        $this->assertSame($classId, $lockKey->classId);
        $this->assertSame($objectId, $lockKey->objectId);
        $this->assertSame($expectedHumanReadableValue, $lockKey->humanReadableValue);
    }

    public static function provideItCanCreatePostgresLockKeyFromIntKeysData(): array
    {
        return [
            'min class_id' => [
                self::DB_INT32_VALUE_MIN,
                0,
                '[-2147483648:0]',
            ],
            'max class_id' => [
                self::DB_INT32_VALUE_MAX,
                0,
                '[2147483647:0]',
            ],
            'min object_id' => [
                0,
                self::DB_INT32_VALUE_MIN,
                '[0:-2147483648]',
            ],
            'max object_id' => [
                0,
                self::DB_INT32_VALUE_MAX,
                '[0:2147483647]',
            ],
        ];
    }

    public function testItCanCreatePostgresLockKeyFromIntKeysWithCustomHumanReadableValue(): void
    {
        // GIVEN: Valid classId and objectId with a custom humanReadableValue
        // WHEN: Creating a PostgresLockKey from internal IDs with a custom humanReadableValue
        $lockKey = PostgresLockKey::createFromInternalIds(42, 100, 'orders:pending');

        // THEN: Lock key should use the provided humanReadableValue with classId:objectId suffix
        $this->assertSame(42, $lockKey->classId);
        $this->assertSame(100, $lockKey->objectId);
        $this->assertSame('orders:pending[42:100]', $lockKey->humanReadableValue);
    }

    #[DataProvider('provideItSanitizesHumanReadableValueFromInternalIdsData')]
    public function testItSanitizesHumanReadableValueFromInternalIds(
        string $humanReadableValue,
        string $expectedHumanReadableValue,
    ): void {
        // GIVEN: A custom humanReadableValue containing control characters
        // WHEN: Creating a PostgresLockKey from internal IDs with that humanReadableValue
        $lockKey = PostgresLockKey::createFromInternalIds(1, 2, $humanReadableValue);

        // THEN: humanReadableValue should have control characters stripped
        $this->assertSame($expectedHumanReadableValue, $lockKey->humanReadableValue);
    }

    public static function provideItSanitizesHumanReadableValueFromInternalIdsData(): array
    {
        return [
            'newline injection' => [
                "orders\n; DROP TABLE users; --",
                'orders; DROP TABLE users; --[1:2]',
            ],
            'carriage return' => [
                "orders\rpending",
                'orderspending[1:2]',
            ],
            'null byte' => [
                "orders\x00pending",
                'orderspending[1:2]',
            ],
            'clean input unchanged' => [
                'orders:pending',
                'orders:pending[1:2]',
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

    #[DataProvider('provideItSanitizesHumanReadableValueForSqlCommentSafetyData')]
    public function testItSanitizesHumanReadableValueForSqlCommentSafety(
        string $namespace,
        string $value,
        string $expectedHumanReadableValue,
    ): void {
        // GIVEN: A namespace or value containing control characters that could break SQL comments
        // WHEN: Creating a PostgresLockKey from the namespace and value
        $lockKey = PostgresLockKey::create($namespace, $value);

        // THEN: humanReadableValue should have control characters stripped
        $this->assertSame($expectedHumanReadableValue, $lockKey->humanReadableValue);
    }

    public static function provideItSanitizesHumanReadableValueForSqlCommentSafetyData(): array
    {
        return [
            'newline in namespace' => [
                "test\n; DROP TABLE users; --",
                'value',
                '[test; DROP TABLE users; --:value]',
            ],
            'carriage return in value' => [
                'namespace',
                "val\rue",
                '[namespace:value]',
            ],
            'null byte in namespace' => [
                "test\x00injection",
                'value',
                '[testinjection:value]',
            ],
            'tabs and newlines in both' => [
                "ns\t1",
                "val\n2",
                '[ns1:val2]',
            ],
            'clean input unchanged' => [
                'orders',
                '123',
                '[orders:123]',
            ],
        ];
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
