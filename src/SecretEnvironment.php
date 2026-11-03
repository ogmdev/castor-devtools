<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use Castor\Context;
use Symfony\Component\Process\Process;

use function Castor\run;

final readonly class SecretEnvironment
{
    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $secretEnvironment
     */
    public static function runWithoutCastorPrintingIt(array $command, array $secretEnvironment, ?Context $context = null): Process
    {
        $environmentBefore = $_ENV;
        $_ENV = $secretEnvironment + $_ENV;

        try {
            return run($command, $context);
        } finally {
            $_ENV = $environmentBefore;
        }
    }
}
