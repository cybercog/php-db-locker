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
    private const DB_INT64_VALUE_MIN = -9_223_372_036_854_775_808;
    private const DB_INT64_VALUE_MAX = 9_223_372_036_854_775_807;
    private const DB_INT32_VALUE_MAX = 2_147_483_647;

    public function __construct(
        public readonly int $id,
        public readonly string $humanReadableValue = '',
    ) {
        if ($id < self::DB_INT64_VALUE_MIN) {
            throw new InvalidArgumentException('Out of bound exception (id is too small)');
        }
        if ($id > self::DB_INT64_VALUE_MAX) {
            throw new InvalidArgumentException('Out of bound exception (id is too big)');
        }
    }

    public static function fromLockId(
        LockId $lockId,
    ): self {
        $lockStringId = (string)$lockId;

        return new self(
            id: self::generateIdFromString($lockStringId),
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
     * This method shifts the crc32 output into the signed int32 range by subtracting (2^31) + 1,
     * ensuring the result fits within Postgres's required range and preserves uniqueness.
     *
     * @param string $string The input string to hash into an int32 lock ID.
     * @return int The signed 32-bit integer suitable for Postgres advisory locks.
     */
    private static function generateIdFromString(
        string $string,
    ): int {
        return crc32($string) - self::DB_INT32_VALUE_MAX - 1;
    }
}
