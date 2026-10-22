<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use RuntimeException;

final class CommandFailedException extends RuntimeException
{
    public function __construct(public readonly int $exitCode)
    {
        parent::__construct(sprintf('Command exited with code %d.', $exitCode), $exitCode);
    }
}
