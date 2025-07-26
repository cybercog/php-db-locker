<?php

declare(strict_types=1);

namespace Cog\DbLocker\Locker;

/**
 * AdvisoryLockMode defines the mode of advisory lock acquisition.
 *
 * - Try: Attempt to acquire the lock without blocking (pg_try_advisory_lock).
 * - Block: Acquire the lock, blocking until it becomes available (pg_advisory_lock).
 */
enum PostgresLockModeEnum
{
    case Try;
    case Block;
}