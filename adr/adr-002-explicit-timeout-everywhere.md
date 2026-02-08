# ADR-004: Explicit timeout required for all lock operations

## Status

Accepted

## Context

When acquiring locks, developers must decide how long to wait for lock availability. There are several approaches:

1. **Optional timeout with default** — `acquire($timeout = null)` where `null` means "wait forever"
2. **Separate methods** — `tryAcquire()` (non-blocking) vs `acquire()` (blocking forever) vs `acquireWithTimeout($timeout)`
3. **Explicit timeout everywhere** — All methods require explicit `TimeoutDuration` parameter

The choice affects:
- API verbosity
- Risk of accidental infinite blocking
- Clarity of developer intent
- Consistency across the library

## Decision

**All lock acquisition methods MUST require an explicit `TimeoutDuration` parameter.**

There is no "default timeout" and no "wait forever" convenience method.

### Rationale

1. **Explicit intent over convenience**
   - Forcing timeout specification makes developers think about lock contention scenarios
   - No accidental infinite waits that hang production systems
   - Clear documentation: "how long is this willing to wait?"

2. **Consistent API surface**
   - `tryAcquire(TimeoutDuration::zero())` — non-blocking
   - `tryAcquire(TimeoutDuration::ofSeconds(5))` — 5-second timeout
   - `tryAcquire(TimeoutDuration::ofMinutes(1))` — 1-minute timeout
   - No special cases, no optional parameters, no nulls

3. **Production safety**
   - Infinite blocking is almost never correct in production
   - If truly needed: `TimeoutDuration::ofHours(24)` makes intent explicit
   - Code review catches unreasonable timeouts

4. **Alignment with PostgreSQL philosophy**
   - PostgreSQL `lock_timeout` setting requires explicit value
   - No "wait forever" is recommended practice
   - Library enforces good practices by design

### Examples

```php
// ✅ GOOD: Explicit timeout, clear intent
$lock->tryAcquire(TimeoutDuration::zero()); // immediate
$lock->tryAcquire(TimeoutDuration::ofSeconds(5)); // 5s max
$lock->tryAcquire(TimeoutDuration::ofMilliseconds(100)); // 100ms max

// ❌ BAD: Would allow these anti-patterns if timeout was optional
$lock->tryAcquire(); // unclear: blocking or non-blocking?
$lock->acquire(); // dangerous: infinite wait
$lock->acquire(null); // ambiguous: what does null mean?
```

## Consequences

### Positive

- **No accidental infinite waits** — Every lock operation has bounded wait time
- **Self-documenting code** — Timeout value signals expected lock contention
- **Easier debugging** — No mystery hangs from forgotten timeout configuration
- **Consistent with library philosophy** — Transaction-level locks preferred (auto-release), session-level as escape hatch with explicit bounds

### Negative

- **Slightly more verbose** — Must write `TimeoutDuration::zero()` instead of just `tryAcquire()`
- **No "blocking acquire"** — If you need long timeout, must specify large value explicitly

### Mitigation

The verbosity cost is minimal:
- `TimeoutDuration::zero()` is 22 characters — acceptable for safety gained
- IDE autocomplete makes it fast to type
- Named constructors are readable: `ofSeconds(5)` is clearer than `5000` milliseconds

## Alternatives Considered

### Alternative 1: Optional timeout with sensible default

```php
public function tryAcquire(
    ?TimeoutDuration $timeout = null,
 ): bool {
    $timeout ??= TimeoutDuration::ofSeconds(30); // default
    // ...
}
```

**Rejected because:**
- Hidden default makes code less clear
- What is "sensible" varies by use case (background job vs user request vs batch process)
- Default encourages not thinking about timeout strategy

### Alternative 2: Separate methods for blocking vs non-blocking

```php
public function tryAcquire(): bool; // non-blocking
public function acquireWithTimeout(TimeoutDuration $t): bool; // explicit timeout
public function acquire(): void; // blocking forever, throws on failure
```

**Rejected because:**
- Three methods instead of one increases API surface
- `acquire()` encourages infinite blocking (bad practice)
- Inconsistent: why does one need timeout but others don't?

### Alternative 3: Global timeout configuration

```php
$lock = new PostgresSessionLock(
    $connection,
    $key,
    defaultTimeout: TimeoutDuration::ofSeconds(30),
);
$lock->tryAcquire(); // uses configured default
```

**Rejected because:**
- Still allows per-call override → two ways to do same thing
- Default stored in object state → less visible at call site
- What if different operations need different timeouts?

## Implementation Notes

### For stateless `PostgresAdvisoryLocker`

All public methods require `TimeoutDuration $timeoutDuration` parameter:

```php
public function acquireTransactionLevelLock(
    ConnectionAdapterInterface $dbConnection,
    PostgresLockKey $key,
    TimeoutDuration $timeoutDuration, // ✅ Required
    PostgresLockAccessModeEnum $accessMode = PostgresLockAccessModeEnum::Exclusive,
): TransactionLevelLockHandle;
```

**No methods like:**
- ❌ `tryAcquire()` — ambiguous
- ❌ `acquire()` — dangerous infinite wait
- ❌ `acquireOrFail()` — when does it fail?

### For `TimeoutDuration` value object

Consider adding named constructors for common cases:

```php
final class TimeoutDuration
{
    public static function zero(): self;
    public static function ofMilliseconds(int $ms): self;
    public static function ofSeconds(int $s): self;
}
```

## References

- PostgreSQL lock_timeout documentation: https://www.postgresql.org/docs/current/runtime-config-client.html#GUC-LOCK-TIMEOUT
