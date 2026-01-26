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

use Cog\DbLocker\DbConnectionAdapter\DbConnectionAdapterInterface;
use Cog\DbLocker\DbConnectionAdapter\PdoDbConnectionAdapter;
use PDO;

final class PostgresLockFactory
{
    /**
     * Create a PostgresLock instance for the given connection and lock identifier.
     *
     * @param PDO|DbConnectionAdapterInterface $connection Database connection
     * @param string $namespace Lock namespace for grouping related locks
     * @param string $id Unique identifier within the namespace
     * @return PostgresLock Pre-configured lock instance
     */
    public static function create(
        PDO|DbConnectionAdapterInterface $connection,
        string $namespace,
        string $id,
    ): PostgresLock {
        $adapter = $connection instanceof PDO
            ? new PdoDbConnectionAdapter($connection)
            : $connection;

        $lockKey = PostgresLockKey::create($namespace, $id);

        return new PostgresLock($adapter, $lockKey);
    }
}
