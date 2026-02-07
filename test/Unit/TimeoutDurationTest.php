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

namespace Cog\Test\DbLocker\Unit;

use Brick\DateTime\Duration;
use Cog\DbLocker\TimeoutDuration;
use InvalidArgumentException;

final class TimeoutDurationTest extends AbstractUnitTestCase
{
    public function testItCanCreateFromMilliseconds(): void
    {
        // GIVEN: A duration value in milliseconds
        $milliseconds = 500;

        // WHEN: Creating a TimeoutDuration from milliseconds
        $duration = TimeoutDuration::ofMilliseconds($milliseconds);

        // THEN: The duration should return the same value in milliseconds
        $this->assertSame(500, $duration->toMilliseconds());
    }

    public function testItCanCreateFromSeconds(): void
    {
        // GIVEN: A duration value in seconds
        $seconds = 5;

        // WHEN: Creating a TimeoutDuration from seconds
        $duration = TimeoutDuration::ofSeconds($seconds);

        // THEN: The duration should return the equivalent value in milliseconds
        $this->assertSame(5000, $duration->toMilliseconds());
    }

    public function testItCanCreateZeroMillisecondsDuration(): void
    {
        // GIVEN: A zero duration value
        // WHEN: Creating a TimeoutDuration with zero milliseconds
        $duration = TimeoutDuration::ofMilliseconds(0);

        // THEN: The duration should return zero milliseconds
        $this->assertSame(0, $duration->toMilliseconds());
    }

    public function testItCanCreateZeroSecondsDuration(): void
    {
        // GIVEN: A zero duration value
        // WHEN: Creating a TimeoutDuration with zero seconds
        $duration = TimeoutDuration::ofSeconds(0);

        // THEN: The duration should return zero milliseconds
        $this->assertSame(0, $duration->toMilliseconds());
    }

    public function testItCanCreateZeroDurationViaFactory(): void
    {
        // GIVEN: No input parameters
        // WHEN: Creating a TimeoutDuration using the zero() factory
        $duration = TimeoutDuration::zero();

        // THEN: The duration should return zero milliseconds
        $this->assertSame(0, $duration->toMilliseconds());
    }

    public function testItCannotCreateFromNegativeMilliseconds(): void
    {
        // GIVEN: A negative duration value in milliseconds
        // WHEN: Attempting to create a TimeoutDuration with negative milliseconds
        // THEN: Should throw InvalidArgumentException
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout duration must not be negative, got -1 milliseconds');

        TimeoutDuration::ofMilliseconds(-1);
    }

    public function testItCannotCreateFromNegativeSeconds(): void
    {
        // GIVEN: A negative duration value in seconds
        // WHEN: Attempting to create a TimeoutDuration with negative seconds
        // THEN: Should throw InvalidArgumentException
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Timeout duration must not be negative, got -1 seconds');

        TimeoutDuration::ofSeconds(-1);
    }

    public function testItCanCreateFromBrickDuration(): void
    {
        // GIVEN: A Brick\DateTime\Duration of 3 seconds and 500 milliseconds
        $brickDuration = Duration::ofSeconds(3, 500_000_000);

        // WHEN: Creating a TimeoutDuration from a Brick Duration
        $duration = TimeoutDuration::fromBrickDuration($brickDuration);

        // THEN: The duration should return the equivalent value in milliseconds
        $this->assertSame(3500, $duration->toMilliseconds());
    }
}
