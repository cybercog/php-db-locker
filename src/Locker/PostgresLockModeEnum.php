<?php

declare(strict_types=1);

namespace Cog\DbLocker\Locker;

/**
 * PostgresLockModeEnum defines the access mode of advisory lock acquisition.
 */
enum PostgresLockModeEnum: string
{
    case Exclusive = 'ExclusiveLock';
    case Share = 'ShareLock';
}