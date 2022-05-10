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
    private const MIN_DB_INT_VALUE = 0;
    private const MAX_DB_INT_VALUE = PHP_INT_MAX;

    private int $id;

    private string $humanReadableValue;

    public function __construct(
        int $id,
        string $humanReadableValue = ''
    ) {
        $this->id = $id;
        $this->humanReadableValue = $humanReadableValue;

        if ($id < self::MIN_DB_INT_VALUE) {
            throw new InvalidArgumentException('Out of bound exception (id is too small)');
        }
        if ($id > self::MAX_DB_INT_VALUE) {
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

    public static function fromLockId(
        LockId $lockId
    ): self {
        $humanReadableValue = (string)$lockId;

        return new self(
            crc32($humanReadableValue),
            $humanReadableValue
        );
    }
}
