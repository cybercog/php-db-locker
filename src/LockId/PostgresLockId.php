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
    private const MIN_INT_DB_VALUE = -2147483648;
    private const MAX_INT_DB_VALUE = 2147483647;

    private int $id;

    private string $humanReadableValue;

    public function __construct(
        int $id,
        string $humanReadableValue = ''
    ) {
        $this->id = $id;
        $this->humanReadableValue = $humanReadableValue;

        if ($id < self::MIN_INT_DB_VALUE) {
            throw new InvalidArgumentException('Out of bound exception (id is too small)');
        }
        if ($id > self::MAX_INT_DB_VALUE) {
            throw new InvalidArgumentException('Out of bound exception (id is too big)');
        }
    }

    public function id(): int
    {
        return $this->id;
    }

    public function humanReadableValue(): string
    {
        return $this->humanReadableValue;
    }

    /**
     * crc32 returns value from 0 to 4294967295 in x64 systems.
     * Postgres int range from -2147483648 to 2147483647.
     *
     * TODO: Recheck it https://www.postgresql.org/docs/14/functions-admin.html#FUNCTIONS-ADVISORY-LOCKS
     */
    public static function fromLockId(
        LockId $lockId
    ): self {
        $humanReadableValue = (string)$lockId;

        return new self(
            crc32($humanReadableValue) - (self::MAX_INT_DB_VALUE + 1),
            $humanReadableValue
        );
    }
}
