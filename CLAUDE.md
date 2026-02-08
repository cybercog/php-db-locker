# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PHP library providing application-level database locking via PostgreSQL Advisory Locks. Pure PDO, no framework dependencies. Package: `cybercog/php-db-locker`.

**Status:** Under active development, API not yet stable.

## Development Commands

All commands run inside Docker containers via Makefile:

```bash
make setup-dev          # First-time setup (starts containers + composer install)
make start / make stop  # Docker containers (PostgreSQL 13.4 + PHP 8.1-FPM)
make phpunit-test       # Run full test suite
make ssh                # Shell into PHP container
make composer-install   # Install dependencies
make run <cmd>          # Run arbitrary command in container (e.g., make run 'php -v')
```

Run a single test:
```bash
make run 'php vendor/bin/phpunit --filter=test_method_name'
```

Tests require the Docker PostgreSQL container to be running. DB credentials are in `.env` and forwarded to both `compose.yml` and `phpunit.xml.dist`.

## Architecture

### Namespace: `Cog\DbLocker\Postgres\`

**`PostgresAdvisoryLocker`** — Main API class. Stateless, takes a `PDO` connection per call. Methods:
- `acquireTransactionLevelLock()` — Preferred. Lock auto-releases on commit/rollback. Requires active transaction.
- `withinSessionLevelLock()` — Callback-based session lock with automatic release via try/finally.
- `acquireSessionLevelLock()` / `releaseSessionLevelLock()` — Low-level manual session lock management.
- `releaseAllSessionLevelLocks()` — Releases all session locks.

Lock behavior is configured by:
- `TimeoutDuration` — Required timeout for lock acquisition. `TimeoutDuration::zero()` for immediate (non-blocking) attempt using `PG_TRY_*` functions; positive values use `PG_ADVISORY_*` + `lock_timeout`.
- `PostgresLockLevelEnum` — Transaction vs Session (internal)
- `PostgresLockAccessModeEnum` — Exclusive vs Share

The `acquireLock()` private method dispatches based on timeout: zero timeout uses `PG_TRY_*` functions (immediate, returns bool), positive timeout uses blocking `PG_ADVISORY_*` functions with PostgreSQL `lock_timeout` setting. It also validates that a transaction is active before acquiring transaction-level locks. Transaction-level timeout locks use savepoints (`SAVEPOINT`/`ROLLBACK TO SAVEPOINT`) to prevent lock timeout errors from aborting the entire transaction. Session-level timeout locks save and restore the original `lock_timeout` value.

All public methods call `assertPdoExceptionMode()` which throws `LogicException` if the PDO connection is not using `PDO::ERRMODE_EXCEPTION` (required for correct timeout handling).

**`PostgresLockKey`** — Two-part lock identifier (classId + objectId, both signed int32). Two factory methods:
- `create('namespace', 'value')` — Hashes strings via xxh3 (64-bit) into two signed 32-bit integers. Uses null byte separator (`"$namespace\0$value"`) to prevent collision between different namespace/value splits.
- `createFromInternalIds(classId, objectId)` — Uses pre-computed int32 pairs directly.
Both accept an optional `humanReadableValue` for SQL comment debugging. The `humanReadableValue` is sanitized via `sanitizeSqlComment()` (strips control characters) and appended as an SQL comment to every lock query.

**`TransactionLevelLockHandle`** — Returned by `acquireTransactionLevelLock()`. Has `wasAcquired` bool. No `release()` method (lock auto-releases on commit/rollback).

**`SessionLevelLockHandle`** — Returned by `acquireSessionLevelLock()`. Has `wasAcquired` bool and `release()` method. Tracks `isReleased` state internally to prevent double-release.

### Tests: `Cog\Test\DbLocker\`

- `test/Unit/` — Tests for `PostgresLockKey` (hashing, boundary values, validation)
- `test/Integration/` — Tests for `PostgresAdvisoryLocker` against real PostgreSQL (multi-connection scenarios)
- `AbstractIntegrationTestCase` — Provides `initPostgresPdoConnection()` and custom assertions (`assertPgAdvisoryLockExistsInConnection`, `assertPgAdvisoryLockMissingInConnection`, `assertPgAdvisoryLocksCount`). Tears down by terminating all non-self PG connections.

### Architecture Decision Records: `adr/`

Design decisions are documented in `adr/` — consult these before changing hashing, exception handling, or PDO validation behavior.

## Conventions

- PHP 8.1+ features: enums, readonly properties, named arguments, `match` expressions
- PSR-4 autoloading: `src/` → `Cog\DbLocker\`, `test/` → `Cog\Test\DbLocker\`
- All classes use `declare(strict_types=1)`
- **Abstract classes** must be prefixed with `Abstract` (e.g., `AbstractLockException`, `AbstractIntegrationTestCase`)
- SQL comments with `humanReadableValue` appended to lock queries for debugging — these must be sanitized to prevent SQL comment injection (see `sanitizeSqlComment()`)

## Git Workflow

- **Never push directly to `master` branch** — Always create a new feature branch for any changes
- Use descriptive branch names (e.g., `feature/add-timeout-support`, `fix/session-lock-release`)

## Testing Standards

- All test methods must include **GIVEN-WHEN-THEN** comments to document test scenarios:
  ```php
  public function testExample(): void
  {
      // GIVEN: Initial state/preconditions
      // WHEN: Action being tested
      // THEN: Expected outcome
  }
  ```
