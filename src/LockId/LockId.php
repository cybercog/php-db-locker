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
    private string $key;

    private string $value;

    public function __construct(
        string $key,
        string $value = ''
    ) {
        $this->key = $key;
        $this->value = $value;

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
