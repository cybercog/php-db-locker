# ADR-003: Require PDO::ERRMODE_EXCEPTION

## Status

Accepted

## Context

Every public method of `PostgresAdvisoryLocker` accepts a `PDO` connection and executes SQL via `prepare()`, `execute()`, and `fetchColumn()`. The correctness of lock acquisition and release depends on these calls either succeeding or throwing an exception.

PDO supports three error modes:

| Mode | Behavior on SQL error |
|------|-----------------------|
| `ERRMODE_SILENT` (default) | `prepare()`/`execute()` return `false`, no exception |
| `ERRMODE_WARNING` | PHP warning + return `false` |
| `ERRMODE_EXCEPTION` | Throws `PDOException` |

In `ERRMODE_SILENT` (PHP's default), a failed `prepare()` returns `false`. Calling `execute()` or `fetchColumn()` on `false` produces a fatal error or returns an unpredictable result. A lock may appear "acquired" when the SQL query actually failed. The timeout-based blocking path (`acquireLockWithTimeout`) relies on catching `PDOException` with SQLSTATE `55P03` to distinguish timeout from success — this mechanism is completely broken without exception mode.

## Decision

Validate `PDO::ATTR_ERRMODE` at the entry point of every public method that accepts a PDO connection. Throw `LogicException` immediately if the mode is not `ERRMODE_EXCEPTION`.

```php
private function assertPdoExceptionMode(PDO $dbConnection): void
{
    if ((int) $dbConnection->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
        throw new \LogicException(
            'PDO connection must use PDO::ERRMODE_EXCEPTION. '
            . 'Set it via: $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION)',
        );
    }
}
```

Guarded methods:

- `acquireTransactionLevelLock()`
- `withinSessionLevelLock()`
- `acquireSessionLevelLock()`
- `releaseSessionLevelLock()`
- `releaseAllSessionLevelLocks()`

### Why validate, not override

Setting `ERRMODE_EXCEPTION` silently would mutate the caller's PDO state — a side effect invisible at the call site. Throwing a `LogicException` makes the requirement explicit and keeps the connection configuration under the caller's control.

### Why LogicException

Passing a PDO connection with an incompatible error mode is a programming error (precondition violation), not a runtime condition. `LogicException` signals that the code must be fixed, not retried.

### Why validate in every public method, not once in acquireLock()

`releaseSessionLevelLock()` and `releaseAllSessionLevelLocks()` do not pass through `acquireLock()`. Validating at each public entry point guarantees coverage regardless of the call path.

## Consequences

- Fail-fast with a clear message when PDO is misconfigured.
- No silent mutation of the caller's PDO attributes.
- Adds one `getAttribute()` call per public method invocation — negligible overhead.
