# Advisory Locks Guide: Transaction-Level vs Session-Level

This guide helps you choose the right type of PostgreSQL advisory lock for your use case.

## Quick Reference

| Factor | Transaction-Level | Session-Level |
|--------|-------------------|---------------|
| Requires open transaction | Yes | No |
| Auto-release on commit/rollback | Yes | No |
| Survives rollback | No | Yes |
| Manual unlock required | No | Yes |
| Works with PgBouncer (transaction pooling) | ✅ Yes | ❌ No |
| Can span multiple transactions | ❌ No | ✅ Yes |
| Risk of lock leaks | Low | Higher |

## When to Use Transaction-Level Locks

Transaction-level locks (`pg_advisory_xact_lock`) are the **safer default choice** for most web application scenarios.

### ✅ Ideal Use Cases

#### 1. Protecting a Single Atomic Operation

When your critical section fits within one transaction:

```php
$dbConnection->beginTransaction();
$lock = $locker->acquireTransactionLevelLock($dbConnection, $lockKey);

if ($lock->wasAcquired) {
    $balance = getBalance($userId);
    $balance -= $amount;
    updateBalance($userId, $balance);
}

$dbConnection->commit(); // Lock is automatically released
```

#### 2. PgBouncer with Transaction Pooling

If you use PgBouncer in transaction pooling mode (common in high-load applications), session-level locks **will not work correctly**. The connection is reassigned after each transaction, so:
- Your session lock may end up on a different connection than your queries
- Another client may inherit a connection with unreleased locks

Transaction-level locks are the **only safe option** with transaction pooling.

#### 3. When You Want Automatic Cleanup

Transaction-level locks are released automatically on:
- `COMMIT`
- `ROLLBACK`
- Connection loss
- Any transaction termination

This eliminates the risk of forgotten unlocks or locks surviving application crashes.

#### 4. Simple Request-Response Cycles

Typical web requests where you:
1. Start transaction
2. Do work
3. Commit and respond

```php
$dbConnection->beginTransaction();
$lock = $locker->acquireTransactionLevelLock($dbConnection, $lockKey);

if ($lock->wasAcquired) {
    processOrder($orderId);
}

$dbConnection->commit();
return response();
```

### ⚠️ Limitations

- **Cannot exist outside a transaction** — you must call `beginTransaction()` first
- **Cannot span multiple transactions** — lock disappears on commit
- **Cannot protect non-transactional work** — external API calls, file operations, etc.

---

## When to Use Session-Level Locks

Session-level locks (`pg_advisory_lock`) are necessary when your locking requirements **exceed the boundaries of a single transaction**.

### ✅ Ideal Use Cases

#### 1. Operations Spanning Multiple Transactions

When you need to maintain exclusive access across several commits:

```php
$lock = $locker->acquireSessionLevelLock($dbConnection, $lockKey);

try {
    // First transaction: create order
    $dbConnection->beginTransaction();
    $order = createOrder($data);
    $dbConnection->commit(); // Transaction closed, BUT LOCK REMAINS
    
    // No transaction here, but lock is still held
    $paymentResult = $paymentGateway->charge($order); // May take 30+ seconds
    
    // Second transaction: update order with payment result
    $dbConnection->beginTransaction();
    updateOrderStatus($order, $paymentResult);
    $dbConnection->commit();
} finally {
    $lock->release(); // CRITICAL: Always release in finally block
}
```

With transaction-level locks, this is **impossible** — the lock would vanish after the first `commit()`.

#### 2. Leader Election / Singleton Processes

When only one worker should run a task across your entire cluster:

```php
$lock = $locker->tryAcquireSessionLevelLock($dbConnection, $lockKey);

if ($lock->wasAcquired) {
    // This process is the leader
    while ($running) {
        $dbConnection->beginTransaction();
        $jobs = fetchPendingJobs();
        $dbConnection->commit();
        
        foreach ($jobs as $job) {
            processJob($job); // May not need a transaction
            
            $dbConnection->beginTransaction();
            markJobComplete($job);
            $dbConnection->commit();
        }
        
        sleep(60);
    }
    
    $lock->release();
}
```

Using transaction-level locks would require keeping **one huge transaction open** for hours — an anti-pattern that causes:
- Table bloat
- Lock contention
- Snapshot isolation issues
- Risk of hitting `idle_in_transaction_session_timeout`

#### 3. Long-Running Operations Without Database Work

When the protected operation doesn't involve the database:

```php
$lock = $locker->acquireSessionLevelLock($dbConnection, $lockKey);

try {
    // Generate report — 5 minutes, no database transactions needed
    $pdf = generateMassiveReport();
    
    // Upload to S3 — 2 minutes
    $s3->upload($pdf);
    
    // Only at the end — a short transaction
    $dbConnection->beginTransaction();
    saveReportMetadata($pdf);
    $dbConnection->commit();
} finally {
    $lock->release();
}
```

#### 4. Database Migrations

Migration tools often need to:
1. Acquire lock
2. Run multiple DDL statements (each may auto-commit)
3. Release lock

```php
$lock = $locker->acquireSessionLevelLock($dbConnection, $migrationLockKey);

try {
    foreach ($migrations as $migration) {
        $migration->up($dbConnection); // May contain multiple transactions
    }
} finally {
    $lock->release();
}
```

### ⚠️ Critical Warnings

#### Always Use try/finally

Session-level locks are **not** released on rollback:

```php
// DANGEROUS — lock leak if exception occurs
$dbConnection->beginTransaction();
$lock = $locker->acquireSessionLevelLock($dbConnection, $lockKey);
doWork(); // Throws exception
$dbConnection->rollback();
// LOCK IS STILL HELD!

// SAFE — lock is always released
$lock = $locker->acquireSessionLevelLock($dbConnection, $lockKey);
try {
    $dbConnection->beginTransaction();
    doWork();
    $dbConnection->commit();
} finally {
    $lock->release();
}
```

#### Beware of Lock Stacking

If you acquire the same lock twice, you must release it twice:

```php
pg_advisory_lock(123);
pg_advisory_lock(123); // Stacks!

pg_advisory_unlock(123); // Still locked
pg_advisory_unlock(123); // Now released
```

#### Connection Pooling Complications

With application-level connection pooling (persistent connections), if your process dies without releasing the lock, it remains held until:
- The connection times out
- The database server restarts
- Someone manually kills the connection

---

## Decision Flowchart

```
Start
  │
  ▼
Does your operation fit within a single transaction?
  │
  ├─ Yes ──► Use TRANSACTION-LEVEL lock
  │
  ▼ No
  │
Do you use PgBouncer with transaction pooling?
  │
  ├─ Yes ──► Consider architectural changes, or use a dedicated
  │          non-pooled connection for session locks
  ▼ No
  │
Use SESSION-LEVEL lock with try/finally
```

## PostgreSQL Functions Reference

### Transaction-Level

| Function | Behavior |
|----------|----------|
| `pg_advisory_xact_lock(key)` | Blocks until lock acquired |
| `pg_try_advisory_xact_lock(key)` | Returns immediately with true/false |
| `pg_advisory_xact_lock_shared(key)` | Shared lock, blocks until acquired |
| `pg_try_advisory_xact_lock_shared(key)` | Shared lock, returns immediately |

### Session-Level

| Function | Behavior |
|----------|----------|
| `pg_advisory_lock(key)` | Blocks until lock acquired |
| `pg_try_advisory_lock(key)` | Returns immediately with true/false |
| `pg_advisory_lock_shared(key)` | Shared lock, blocks until acquired |
| `pg_try_advisory_lock_shared(key)` | Shared lock, returns immediately |
| `pg_advisory_unlock(key)` | Releases one instance of the lock |
| `pg_advisory_unlock_all()` | Releases all session locks |

## Further Reading

- [PostgreSQL Advisory Locks Documentation](https://www.postgresql.org/docs/current/explicit-locking.html#ADVISORY-LOCKS)
- [PgBouncer Transaction Pooling](https://www.pgbouncer.org/features.html)
