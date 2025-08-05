<?php

declare(strict_types=1);

namespace Cog\DbLocker\Postgres\Enum;

/**
 * PostgresLockAccessModeEnum defines the access mode of advisory lock acquisition.
 *
 * TODO: Write details about access mode.
 */
enum PostgresLockAccessModeEnum
{
    case Exclusive;
    case Share;
}
