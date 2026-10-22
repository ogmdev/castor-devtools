<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use function Castor\io;

final readonly class Laravel
{
    public function __construct(private DockerCompose $docker = new DockerCompose, private Git $git = new Git) {}

    /**
     * @param  list<string>  $arguments
     */
    public function runArtisanCommand(array $arguments): void
    {
        $this->docker->runCommandInService($this->docker->phpService(), ['php', 'artisan', ...$arguments]);
    }

    /**
     * @param  list<string>  $arguments
     */
    public function captureArtisanCommand(array $arguments): string
    {
        return $this->docker->captureCommandInService($this->docker->phpService(), ['php', 'artisan', ...$arguments]);
    }

    public function clearLaravelCache(): void
    {
        $this->runArtisanCommand(['optimize:clear']);
    }

    /**
     * @param  list<string>  $arguments
     */
    public function runTests(array $arguments = []): void
    {
        io()->note($this->git->describeWorkingTree());

        foreach ($this->buildTestCommands($arguments) as $command) {
            $this->docker->runCommandInService($this->docker->phpService(), $command);
        }
    }

    public function openPhpShell(): void
    {
        $this->docker->openShellInService($this->docker->phpService());
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    public function buildArtisanCommand(array $arguments): array
    {
        return $this->docker->buildExecCommand($this->docker->phpService(), ['php', 'artisan', ...$arguments]);
    }

    /**
     * @param  list<string>  $arguments
     * @return list<list<string>>
     */
    public function buildTestCommands(array $arguments = []): array
    {
        return [
            ['php', 'artisan', 'config:clear', '--ansi'],
            ['php', 'artisan', 'test', '--compact', '--parallel', ...$arguments],
        ];
    }
}
