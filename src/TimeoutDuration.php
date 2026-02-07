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

namespace Cog\DbLocker;

use Brick\DateTime\Duration;

final class TimeoutDuration
{
    private function __construct(
        private readonly int $milliseconds,
    ) {}

    public static function zero(): self
    {
        return new self(0);
    }

    public static function ofMilliseconds(
        int $milliseconds,
    ): self {
        if ($milliseconds < 0) {
            throw new \InvalidArgumentException(
                "Timeout duration must not be negative, got $milliseconds milliseconds",
            );
        }

        return new self($milliseconds);
    }

    public static function ofSeconds(
        int $seconds,
    ): self {
        if ($seconds < 0) {
            throw new \InvalidArgumentException(
                "Timeout duration must not be negative, got $seconds seconds",
            );
        }

        return new self($seconds * 1000);
    }

    /**
     * Create from a Brick\DateTime\Duration instance.
     *
     * Requires the `brick/date-time` package to be installed.
     */
    public static function fromBrickDuration(
        Duration $duration,
    ): self {
        return self::ofMilliseconds($duration->getTotalMillis());
    }

    public function toMilliseconds(): int
    {
        return $this->milliseconds;
    }
}
