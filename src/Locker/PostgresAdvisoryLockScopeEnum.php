<?php

declare(strict_types=1);

namespace Cog\DbLocker\Locker;

/**
 * PostgresAdvisoryLockScopeEnum defines the scope of advisory lock acquisition.
 *
 * - Session: Session-level advisory lock (PG_ADVISORY_LOCK, PG_TRY_ADVISORY_LOCK)
 * - Transaction: Transaction-level advisory lock (PG_ADVISORY_XACT_LOCK, PG_TRY_ADVISORY_XACT_LOCK)
 */
enum PostgresAdvisoryLockScopeEnum
{
    case Session;
    case Transaction;
}