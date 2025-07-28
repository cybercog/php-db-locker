<?php

declare(strict_types=1);

namespace Cog\DbLocker\Locker;

/**
 * PostgresLockAccessModeEnum defines the access mode of advisory lock acquisition.
 */
enum PostgresLockAccessModeEnum: string
{
    case Exclusive = 'ExclusiveLock';
    case Share = 'ShareLock';
}
