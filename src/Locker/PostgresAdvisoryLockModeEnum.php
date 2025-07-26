<?php

declare(strict_types=1);

namespace Cog\DbLocker\Locker;

/**
 * PostgresAdvisoryLockModeEnum defines the mode of advisory lock acquisition.
 *
 * - Try: Attempt to acquire the lock without blocking (PG_TRY_ADVISORY_LOCK, PG_TRY_ADVISORY_XACT_LOCK).
 * - Block: Acquire the lock, blocking until it becomes available (PG_ADVISORY_LOCK, PG_ADVISORY_XACT_LOCK).
 */
enum PostgresAdvisoryLockModeEnum
{
    case Try;
    case Block;
}