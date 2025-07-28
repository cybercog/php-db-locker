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

namespace Cog\DbLocker\Postgres\LockHandle;

/**
 * @internal
 */
final class PostgresTransactionLevelLockHandle
{
    public function __construct(
        public readonly bool $wasAcquired,
    ) {}
}