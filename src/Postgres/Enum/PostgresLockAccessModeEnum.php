<?php

declare(strict_types=1);

namespace Cog\DbLocker\Postgres\Enum;

/**
 * PostgresLockAccessModeEnum defines the access mode of advisory lock acquisition.
 *
 * TODO: Need string values only for tests, should add match to tests instead.
 */
enum PostgresLockAccessModeEnum: string
{
    case Exclusive = 'ExclusiveLock';
    case Share = 'ShareLock';
}
