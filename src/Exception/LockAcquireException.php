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

namespace Cog\DbLocker\Exception;

use Cog\DbLocker\Postgres\PostgresLockKey;

/**
 * Exception thrown when a lock cannot be acquired due to a database error.
 *
 * This exception is NOT thrown when a lock is simply unavailable due to competition
 * from other processes. In that case, methods return `false` instead.
 *
 * This exception IS thrown for genuine errors such as:
 * - Connection failures
 * - Query execution errors (excluding lock_not_available SQLSTATE 55P03)
 * - Transaction state errors
 */
final class LockAcquireException extends AbstractLockException
{
    public static function fromDatabaseError(
        PostgresLockKey $key,
        \Throwable $previous,
    ): self {
        return new self(
            message: "Failed to acquire lock for key `{$key->humanReadableValue}`: {$previous->getMessage()}",
            previous: $previous,
        );
    }
}
