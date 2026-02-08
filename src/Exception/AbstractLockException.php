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

namespace Cog\DbLocker\Exception;

/**
 * Base exception for all database lock-related errors.
 *
 * This exception is thrown only for exceptional situations (database errors, connection issues, etc.),
 * NOT for normal lock contention. When a lock is unavailable due to competition from other processes,
 * methods return `false` instead of throwing this exception.
 */
abstract class AbstractLockException extends \RuntimeException
{
}
