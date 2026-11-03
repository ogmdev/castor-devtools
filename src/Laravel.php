<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

final readonly class Laravel extends Framework
{
    /**
     * @param  list<string>  $arguments
     */
    public function runArtisanCommand(array $arguments, bool $tty = true): void
    {
        $this->runConsoleCommand($arguments, $tty);
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     */
    public function captureArtisanCommand(array $arguments, array $environment = []): string
    {
        return $this->captureConsoleCommand($arguments, $environment);
    }

    public function clearLaravelCache(): void
    {
        $this->clearCache();
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     * @return list<string>
     */
    public function buildArtisanCommand(array $arguments, array $environment = []): array
    {
        return $this->buildConsoleCommand($arguments, environment: $environment);
    }

    protected function consoleCommand(): array
    {
        return ['php', 'artisan'];
    }

    protected function cacheClearArguments(): array
    {
        return ['optimize:clear'];
    }

    protected function defaultTestCommands(): array
    {
        return [
            ['php', 'artisan', 'config:clear', '--ansi'],
            ['php', 'artisan', 'test', '--compact', '--parallel'],
        ];
    }
}
