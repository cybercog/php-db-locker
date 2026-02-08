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

use Cog\DbLocker\ConnectionAdapterInterface;
use Cog\DbLocker\Postgres\Enum\PostgresLockAccessModeEnum;
use Cog\DbLocker\Postgres\PostgresAdvisoryLocker;
use Cog\DbLocker\Postgres\PostgresLockKey;

/**
 * @internal
 */
final class SessionLevelLockHandle
{
    private bool $isReleased = false;

    public function __construct(
        private readonly ConnectionAdapterInterface $connection,
        private readonly PostgresAdvisoryLocker $locker,
        public readonly PostgresLockKey $lockKey,
        public readonly PostgresLockAccessModeEnum $accessMode,
        public readonly bool $wasAcquired,
    ) {}

    /**
     * Explicitly release the lock if it was acquired and not yet released.
     *
     * @return bool True if the lock was released, false if it wasn't acquired or already released
     */
    public function release(): bool
    {
        /**
         * This code is mimicking the behavior of DB lock release.
         */
        if (!$this->wasAcquired || $this->isReleased) {
            return false;
        }

        $wasReleased = $this->locker->releaseSessionLevelLock(
            $this->connection,
            $this->lockKey,
            $this->accessMode,
        );

        if ($wasReleased) {
            $this->isReleased = true;
        }

        return $wasReleased;
    }
}