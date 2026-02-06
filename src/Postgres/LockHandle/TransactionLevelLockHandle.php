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

use Cog\DbLocker\Postgres\Enum\PostgresLockAccessModeEnum;
use Cog\DbLocker\Postgres\PostgresLockKey;

/**
 * @internal
 */
final class TransactionLevelLockHandle
{
    public function __construct(
        public readonly PostgresLockKey $lockKey,
        public readonly PostgresLockAccessModeEnum $accessMode,
        public readonly bool $wasAcquired,
    ) {}
}
