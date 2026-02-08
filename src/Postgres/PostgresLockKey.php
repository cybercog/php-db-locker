<?php

/*
 * This file is part of PHP DB Locker.
 *
 * (c) Anton Komarev <anton@komarev.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Cog\DbLocker\Postgres;

use InvalidArgumentException;

final class PostgresLockKey
{
    private const DB_INT32_VALUE_MIN = -2_147_483_648;
    private const DB_INT32_VALUE_MAX = 2_147_483_647;

    private function __construct(
        public readonly int $classId,
        public readonly int $objectId,
        public readonly string $humanReadableValue = '',
    ) {
        if ($classId < self::DB_INT32_VALUE_MIN) {
            throw new InvalidArgumentException("Out of bound exception (classId=$classId is too small)");
        }
        if ($classId > self::DB_INT32_VALUE_MAX) {
            throw new InvalidArgumentException("Out of bound exception (classId=$classId is too big)");
        }
        if ($objectId < self::DB_INT32_VALUE_MIN) {
            throw new InvalidArgumentException("Out of bound exception (objectId=$objectId is too small)");
        }
        if ($objectId > self::DB_INT32_VALUE_MAX) {
            throw new InvalidArgumentException("Out of bound exception (objectId=$objectId is too big)");
        }
    }

    /**
     * Create a lock key from human-readable string identifiers.
     *
     * Namespace and value are concatenated with a null byte separator and hashed via xxh3
     * into a 64-bit digest, which is then split into two signed 32-bit integers (classId, objectId)
     * suitable for PostgreSQL advisory locks.
     *
     * @param string $namespace Logical group (e.g. "user"). Combined with $value for hashing.
     * @param string $value Identifier within the group (e.g. "42"). Combined with $namespace for hashing.
     * @param string $humanReadableValue Optional label for SQL comment debugging. Defaults to "$namespace:$value".
     */
    public static function create(
        string $namespace,
        string $value = '',
        string $humanReadableValue = '',
    ): self {
        [$classId, $objectId] = self::hashToSignedInt32Pair($namespace, $value);

        return new self(
            classId: $classId,
            objectId: $objectId,
            humanReadableValue: self::sanitizeSqlComment(
                "{$humanReadableValue}[{$namespace}:{$value}]",
            ),
        );
    }

    /**
     * Create a lock key from raw PostgreSQL advisory lock integer identifiers.
     *
     * Use this when you already have pre-computed int32 classId/objectId pairs
     * (e.g. from an external system or database) and don't need string-to-hash conversion.
     *
     * @param int $classId First part of the two-part lock key (signed 32-bit integer).
     * @param int $objectId Second part of the two-part lock key (signed 32-bit integer).
     * @param string $humanReadableValue Optional label for SQL comment debugging. Defaults to "$classId:$objectId".
     */
    public static function createFromInternalIds(
        int $classId,
        int $objectId,
        string $humanReadableValue = '',
    ): self {
        return new self(
            classId: $classId,
            objectId: $objectId,
            humanReadableValue: self::sanitizeSqlComment(
                "{$humanReadableValue}[{$classId}:{$objectId}]",
            ),
        );
    }

    private static function sanitizeSqlComment(
        string $value,
    ): string {
        return preg_replace(
            '/[\x00-\x1F\x7F]/',
            '',
            $value,
        );
    }

    /**
     * Hashes namespace and value into a pair of signed 32-bit integers
     * for use as PostgreSQL advisory lock key (classId, objectId).
     *
     * Uses xxh3 (64-bit) on the concatenated string "namespace\0value".
     * The null byte separator prevents collisions between different
     * namespace/value splits (e.g. "ab"+"cd" vs "abc"+"d").
     *
     * The 64-bit digest is split into two signed 32-bit integers using
     * native byte order, giving a combined key space of 2^64.
     *
     * @return array{int, int} [classId, objectId] as signed 32-bit integers.
     */
    private static function hashToSignedInt32Pair(
        string $namespace,
        string $value,
    ): array {
        $hash = hash('xxh3', "{$namespace}\0{$value}", binary: true);

        return [
            unpack('l', substr($hash, 0, 4))[1],
            unpack('l', substr($hash, 4, 4))[1],
        ];
    }
}
