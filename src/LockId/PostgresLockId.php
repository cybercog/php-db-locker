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
    private const DB_INT64_VALUE_MIN = 0;
    private const DB_INT64_VALUE_MAX = 9223372036854775807;

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
        $humanReadableValue = (string)$lockId;

        return new self(
            self::generateIdFromString($humanReadableValue),
            $humanReadableValue
        );
    }

    private static function generateIdFromString(
        string $string,
    ): int {
        return crc32($string);
    }
}
