<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

/**
 * Not named Symfony: importing a class of that name would capture every Symfony\... name in the task file.
 */
final readonly class SymfonyApp extends Framework
{
    protected function consoleCommand(): array
    {
        return ['php', 'bin/console'];
    }

    protected function cacheClearArguments(): array
    {
        return ['cache:clear'];
    }

    protected function defaultTestCommands(): array
    {
        return [
            ['php', 'bin/phpunit'],
        ];
    }
}
