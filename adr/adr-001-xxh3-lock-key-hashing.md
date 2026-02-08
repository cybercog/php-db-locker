# ADR-001: xxh3 for lock key hashing

## Status

Accepted

## Context

`PostgresLockKey::create($namespace, $value)` needs to produce a deterministic pair of signed int32 (`classId`, `objectId`) from two strings for use as a PostgreSQL advisory lock key.

PostgreSQL advisory locks accept at most 64 bits — either `pg_advisory_lock(bigint)` or `pg_advisory_lock(int, int)`. The hash algorithm must produce at least 64 bits of output with good distribution.

## Decision

Hash the concatenated string `"$namespace\0$value"` with xxh3, split the 64-bit digest into two signed int32.

### Hash algorithm: xxh3

- **xxh3 (64-bit)** — fastest available, excellent distribution, built into PHP 8.1+.
- murmur3f (128-bit) — no advantage, PostgreSQL caps at 64 bits.
- SHA-256 — cryptographic strength unnecessary, significantly slower.

### Concatenation separator: null byte

Without a separator, different splits produce identical input: `"ab" + "cd"` and `"abc" + "d"` both give `"abcd"`. Null byte ensures `"ab\0cd" != "abc\0d"`. Does not appear in typical inputs (class names, identifiers).

### Output format: two int32 vs one int64

Two int32 chosen over one int64:
- **Diagnostics** — `pg_locks` exposes `classid` and `objid` as separate columns, enabling filtering by entity type.
- **API** — `createFromInternalIds(classId, objectId)` remains a natural two-part interface for users with pre-computed identifiers.
- **Performance** — splitting into two int32 adds ~27 ns per call (benchmarked at 1M iterations). Negligible vs. network roundtrip to PostgreSQL.

## Consequences

- Key space is 2^64 (birthday paradox threshold ~4.3 billion unique inputs for 50% collision probability).
- Requires PHP 8.1+ (xxh3 support). Already a project requirement.
