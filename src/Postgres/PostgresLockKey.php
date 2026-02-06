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

namespace Cog\DbLocker\Postgres;

use InvalidArgumentException;

final class PostgresLockKey
{
    private const DB_INT32_VALUE_MIN = -2_147_483_648;
    private const DB_INT32_VALUE_MAX = 2_147_483_647;

    private function __construct(
        public readonly int $classId,
        public readonly int $objectId,
        public readonly string $humanReadableValue = '',
    ) {
        if ($classId < self::DB_INT32_VALUE_MIN) {
            throw new InvalidArgumentException("Out of bound exception (classId=$classId is too small)");
        }
        if ($classId > self::DB_INT32_VALUE_MAX) {
            throw new InvalidArgumentException("Out of bound exception (classId=$classId is too big)");
        }
        if ($objectId < self::DB_INT32_VALUE_MIN) {
            throw new InvalidArgumentException("Out of bound exception (objectId=$objectId is too small)");
        }
        if ($objectId > self::DB_INT32_VALUE_MAX) {
            throw new InvalidArgumentException("Out of bound exception (objectId=$objectId is too big)");
        }
    }

    /**
     * Create a lock key from human-readable string identifiers.
     *
     * Strings are hashed via CRC32 into signed 32-bit integers suitable for PostgreSQL advisory locks.
     * Use this when you have domain-level identifiers (e.g. entity class name + record ID).
     *
     * @param string $namespace Logical group (e.g. "user"). Hashed into classId.
     * @param string $value Identifier within the group (e.g. "42"). Hashed into objectId.
     * @param string|null $humanReadableValue Optional label for SQL comment debugging. Defaults to "$namespace:$value".
     */
    public static function create(
        string $namespace,
        string $value = '',
        ?string $humanReadableValue = null,
    ): self {
        $finalValue = $humanReadableValue === null
            ? "[{$namespace}:{$value}]"
            : "{$humanReadableValue}[{$namespace}:{$value}]";

        return new self(
            classId: self::convertStringToSignedInt32($namespace),
            objectId: self::convertStringToSignedInt32($value),
            humanReadableValue: self::sanitizeSqlComment($finalValue),
        );
    }

    /**
     * Create a lock key from raw PostgreSQL advisory lock integer identifiers.
     *
     * Use this when you already have pre-computed int32 classId/objectId pairs
     * (e.g. from an external system or database) and don't need string-to-hash conversion.
     *
     * @param int $classId First part of the two-part lock key (signed 32-bit integer).
     * @param int $objectId Second part of the two-part lock key (signed 32-bit integer).
     * @param string|null $humanReadableValue Optional label for SQL comment debugging. Defaults to "$classId:$objectId".
     */
    public static function createFromInternalIds(
        int $classId,
        int $objectId,
        ?string $humanReadableValue = null,
    ): self {
        $finalValue = $humanReadableValue === null
            ? "[{$classId}:{$objectId}]"
            : "{$humanReadableValue}[{$classId}:{$objectId}]";

        return new self(
            classId: $classId,
            objectId: $objectId,
            humanReadableValue: self::sanitizeSqlComment($finalValue),
        );
    }

    private static function sanitizeSqlComment(
        string $value,
    ): string {
        return preg_replace(
            '/[\x00-\x1F\x7F]/',
            '',
            $value,
        );
    }

    /**
     * Generates a deterministic signed 32-bit integer ID
     * from a string for use as a Postgres advisory lock key.
     *
     * The crc32 function returns an unsigned 32-bit integer (0 to 4_294_967_295).
     * Postgres advisory locks require a signed 32-bit integer (-2_147_483_648 to 2_147_483_647).
     *
     * This method converts the unsigned crc32 result to a signed 32-bit integer,
     * matching the way PostgreSQL interprets int4 values internally.
     *
     * @param string $string The input string to hash into a signed int32 lock ID.
     * @return int The signed 32-bit integer suitable for Postgres advisory locks.
     */
    private static function convertStringToSignedInt32(
        string $string,
    ): int {
        $unsignedInt = crc32($string);

        return $unsignedInt > 0x7FFFFFFF
            ? $unsignedInt - 0x100000000
            : $unsignedInt;
    }
}
