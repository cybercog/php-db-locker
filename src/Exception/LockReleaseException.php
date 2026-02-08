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
 * Exception thrown when a lock cannot be released due to a database error.
 *
 * This indicates a genuine error condition such as:
 * - Connection failures
 * - Query execution errors
 * - Unexpected database state
 */
final class LockReleaseException extends AbstractLockException
{
    public static function fromDatabaseError(
        PostgresLockKey $key,
        \Throwable $previous,
    ): self {
        return new self(
            message: "Failed to release lock for key `{$key->humanReadableValue}`: {$previous->getMessage()}",
            previous: $previous,
        );
    }
}
