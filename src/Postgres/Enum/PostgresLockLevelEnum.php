<?php

declare(strict_types=1);

namespace Cog\DbLocker\Postgres\Enum;

/**
 * PostgresLockLevelEnum defines the level of advisory lock acquisition.
 *
 * - Transaction. Transaction-level (recommended) advisory lock (with _XACT_):
 *      - PG_ADVISORY_XACT_LOCK
 *      - PG_ADVISORY_XACT_LOCK_SHARED
 *      - PG_TRY_ADVISORY_XACT_LOCK
 *      - PG_TRY_ADVISORY_XACT_LOCK_SHARED
 * - Session. Session-level advisory lock (without _XACT_):
 *      - PG_ADVISORY_LOCK
 *      - PG_ADVISORY_LOCK_SHARED
 *      - PG_TRY_ADVISORY_LOCK
 *      - PG_TRY_ADVISORY_LOCK_SHARED
 */
enum PostgresLockLevelEnum
{
    case Transaction;
    case Session;
}
