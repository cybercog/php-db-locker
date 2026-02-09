# ADR-002: Exception safety in withinSessionLevelLock

## Status

Accepted

## Context

`withinSessionLevelLock()` acquires a session-level advisory lock, executes a user callback, and releases the lock. The release must happen reliably, but two edge cases require careful handling:

1. **Double failure.** The callback throws, and then the release also throws (e.g. the database connection was lost). In PHP, an exception thrown from a `finally` block replaces the in-flight exception. A naive `try/finally` would expose the caller to a `PDOException` from release instead of the real error.

2. **Release of an unacquired lock.** With a zero timeout the lock may not be acquired (`wasAcquired === false`). Calling `PG_ADVISORY_UNLOCK` on a key that was never held returns `false` in isolation, but if the same key was previously acquired in the same session (reentrant locking), the call decrements the counter and silently releases someone else's lock.

## Decision

Use `try/catch/finally` with two guards:

- **Skip release when the lock was not acquired** — `if ($lockHandle->wasAcquired)`.
- **Protect the original exception** — catch the callback exception, store it, re-throw; wrap release in its own `try/catch` so a release failure cannot mask the original.

```php
$exception = null;
try {
    return $callback($lockHandle);
} catch (\Throwable $e) {
    $exception = $e;
    throw $e;
} finally {
    if ($lockHandle->wasAcquired) {
        try {
            $this->releaseSessionLevelLock(...);
        } catch (\Throwable $releaseException) {
            if ($exception === null) {
                throw $releaseException;
            }
        }
    }
}
```

### Behavior matrix

| Callback | Release | Result |
|----------|---------|--------|
| OK | OK | Return callback result |
| OK | Throws | Throw release exception |
| Throws | OK | Throw original exception |
| Throws | Throws | Throw original exception, suppress release exception |

### Why suppress the release exception (not chain it)

PHP sets `previous` only in the exception constructor — there is no `addPrevious()`. Wrapping the original in a new exception would change its type and break typed `catch` blocks in user code. Suppressing the secondary release error is the lesser evil: the root cause (callback failure) is always preserved.

### Why not log the suppressed exception

The library has no logger dependency and adding one for a single edge case is not justified. Users who need visibility into release failures can wrap `withinSessionLevelLock` in their own try/catch or use `acquireSessionLevelLock` / `releaseSessionLevelLock` directly.

## Known limitation: callback result is lost on release failure

When the callback succeeds but `releaseSessionLevelLock()` throws, `LockReleaseException` is thrown and the callback's return value is discarded. The caller receives no indication of the callback result.

This is a consequence of PHP's `finally` semantics — a `throw` in `finally` prevents the `try` block's `return` from completing. The `LockReleaseException` itself serves as a reliable signal that the callback completed successfully (it is only thrown when `$exception === null`), but the return value is not preserved.

A future improvement could introduce a dedicated exception subclass (e.g. `WithinSessionLockReleaseException`) that carries the callback result. For now, users who need the callback result in this scenario should use `acquireSessionLevelLock()` / `releaseSessionLevelLock()` directly.

## Consequences

- Original exceptions from user callbacks are never masked by release failures.
- `PG_ADVISORY_UNLOCK` is not called when the lock was never acquired, preventing reentrant counter corruption.
- Release failures are silently suppressed when a callback exception is already in flight — acceptable trade-off given no logger.
- Callback return value is lost when release fails — acceptable trade-off; low-level API is available for callers who need it.
