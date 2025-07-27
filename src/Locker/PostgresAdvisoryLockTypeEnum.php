<?php

declare(strict_types=1);

namespace Cog\DbLocker\Locker;

/**
 * PostgresAdvisoryLockTypeEnum defines the type of advisory lock acquisition.
 *
 * - NonBlocking: Attempt to acquire the lock without blocking (PG_TRY_ADVISORY_LOCK, PG_TRY_ADVISORY_XACT_LOCK).
 * - Blocking: Acquire the lock, blocking until it becomes available (PG_ADVISORY_LOCK, PG_ADVISORY_XACT_LOCK).
 */
enum PostgresAdvisoryLockTypeEnum
{
    case NonBlocking;
    case Blocking;
}
