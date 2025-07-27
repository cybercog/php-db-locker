<?php

declare(strict_types=1);

namespace Cog\DbLocker\Locker;

/**
 * PostgresAdvisoryLockScopeEnum defines the scope of advisory lock acquisition.
 *
 * - Session. Session-level advisory lock (without _XACT_):
 *      - PG_ADVISORY_LOCK
 *      - PG_ADVISORY_LOCK_SHARED
 *      - PG_TRY_ADVISORY_LOCK
 *      - PG_TRY_ADVISORY_LOCK_SHARED
 * - Transaction. Transaction-level advisory lock (with _XACT_):
 *      - PG_ADVISORY_XACT_LOCK
 *      - PG_ADVISORY_XACT_LOCK_SHARED
 *      - PG_TRY_ADVISORY_XACT_LOCK
 *      - PG_TRY_ADVISORY_XACT_LOCK_SHARED
 */
enum PostgresAdvisoryLockScopeEnum
{
    case Session;
    case Transaction;
}
