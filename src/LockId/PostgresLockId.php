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

namespace Cog\DbLocker\LockId;

use InvalidArgumentException;

final class PostgresLockId
{
    private const DB_INT32_VALUE_MIN = -2_147_483_648;
    private const DB_INT32_VALUE_MAX = 2_147_483_647;

    public function __construct(
        public readonly int $id,
        public readonly string $humanReadableValue = '',
    ) {
        if ($id < self::DB_INT32_VALUE_MIN) {
            throw new InvalidArgumentException('Out of bound exception (id is too small)');
        }
        if ($id > self::DB_INT32_VALUE_MAX) {
            throw new InvalidArgumentException('Out of bound exception (id is too big)');
        }
    }

    public static function fromLockId(
        LockId $lockId,
    ): self {
        $lockStringId = (string)$lockId;

        return new self(
            id: self::convertStringToSignedInt32($lockStringId),
            humanReadableValue: $lockStringId,
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
