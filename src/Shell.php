<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use RuntimeException;

use function Castor\context;
use function Castor\exit_code;

/**
 * The few probes and writes every class makes on the workstation itself.
 *
 * @internal
 */
final readonly class Shell
{
    /**
     * @phpstan-impure
     */
    public static function hasBinary(string $binary): bool
    {
        return exit_code(['sh', '-c', 'command -v '.escapeshellarg($binary)], context()->withQuiet()) === 0;
    }

    public static function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException(sprintf('Unable to write %s.', $path));
        }
    }
}
