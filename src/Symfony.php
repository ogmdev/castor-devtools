<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use function Castor\io;

final readonly class Symfony
{
    public function __construct(private DockerCompose $docker = new DockerCompose, private Git $git = new Git) {}

    /**
     * @param  list<string>  $arguments
     */
    public function runConsoleCommand(array $arguments): void
    {
        $this->docker->runCommandInService($this->docker->phpService(), ['php', 'bin/console', ...$arguments]);
    }

    /**
     * @param  list<string>  $arguments
     */
    public function captureConsoleCommand(array $arguments): string
    {
        return $this->docker->captureCommandInService($this->docker->phpService(), ['php', 'bin/console', ...$arguments]);
    }

    public function clearSymfonyCache(): void
    {
        $this->runConsoleCommand(['cache:clear']);
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
    public function buildConsoleCommand(array $arguments): array
    {
        return $this->docker->buildExecCommand($this->docker->phpService(), ['php', 'bin/console', ...$arguments]);
    }

    /**
     * @param  list<string>  $arguments
     * @return list<list<string>>
     */
    public function buildTestCommands(array $arguments = []): array
    {
        return [
            ['php', 'bin/phpunit', ...$arguments],
        ];
    }
}
