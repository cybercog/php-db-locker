<?php

declare(strict_types=1);

namespace Cog\DbLocker\Postgres\Enum;

/**
 * PostgresLockWaitModeEnum defines the type of advisory lock acquisition.
 *
 * - Blocking. Acquire the lock, blocking until it becomes available (without _TRY_):
 *      - PG_ADVISORY_LOCK
 *      - PG_ADVISORY_LOCK_SHARED
 *      - PG_ADVISORY_XACT_LOCK
 *      - PG_ADVISORY_XACT_LOCK_SHARED
 * - NonBlocking. Attempt to acquire the lock without blocking (with _TRY_):
 *      - PG_TRY_ADVISORY_LOCK
 *      - PG_TRY_ADVISORY_LOCK_SHARED
 *      - PG_TRY_ADVISORY_XACT_LOCK
 *      - PG_TRY_ADVISORY_XACT_LOCK_SHARED
 */
enum PostgresLockWaitModeEnum
{
    case Blocking;
    case NonBlocking;
}
