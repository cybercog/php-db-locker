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

namespace Cog\DbLocker\LockId;

use InvalidArgumentException;

final class LockId
{
    public function __construct(
        public readonly string $key,
        public readonly string $value = '',
    ) {
        if ($key === '') {
            throw new InvalidArgumentException('LockId key is empty');
        }
    }

    public function __toString(): string
    {
        return $this->compileId();
    }

    private function compileId(): string
    {
        return $this->value !== ''
            ? $this->key . ':' . $this->value
            : $this->key;
    }
}
