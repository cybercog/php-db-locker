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

namespace Cog\DbLocker\Locker;

use Cog\DbLocker\LockId\PostgresLockId;
use PDO;

final class AdvisoryLockSessionLevel
{
    private bool $isReleased = false;

    public function __construct(
        private readonly PDO $dbConnection,
        private readonly PostgresAdvisoryLocker $locker,
        public readonly PostgresLockId $lockId,
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
            $this->dbConnection,
            $this->lockId,
        );

        if ($wasReleased) {
            $this->isReleased = true;
        }

        return $wasReleased;
    }

    /**
     * Automatically release the lock when the handle is destroyed.
     */
    public function __destruct()
    {
        // TODO: Do we need to
        $this->release();
    }
}