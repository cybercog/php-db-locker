<?php

declare(strict_types=1);

namespace Cog\DbLocker\DbConnectionAdapter\Exception;

use Doctrine\DBAL\Exception as DoctrineDbalException;
use RuntimeException;

final class UncheckedDoctrineDbalException extends RuntimeException
{
    public static function ofDbalException(
        DoctrineDbalException $exception,
    ): self {
        return new self(
            $exception->getMessage(),
            $exception->getCode(),
            $exception,
        );
    }
}
