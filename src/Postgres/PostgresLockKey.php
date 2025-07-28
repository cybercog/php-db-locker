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

    public static function create(
        string $namespace,
        string $value = '',
    ): self {
        return new self(
            classId: self::convertStringToSignedInt32($namespace),
            objectId: self::convertStringToSignedInt32($value),
            // TODO: Do we need to sanitize it?
            // TODO: Do we need to omit ":" on end if no value is passed
            humanReadableValue: "$namespace:$value",
        );
    }

    public static function createFromInternalIds(
        int $classId,
        int $objectId,
    ): self {
        return new self(
            $classId,
            $objectId,
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
