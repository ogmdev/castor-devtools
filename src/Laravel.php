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
     */
    public function captureArtisanCommand(array $arguments): string
    {
        return $this->captureConsoleCommand($arguments);
    }

    public function clearLaravelCache(): void
    {
        $this->clearCache();
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    public function buildArtisanCommand(array $arguments): array
    {
        return $this->buildConsoleCommand($arguments);
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
