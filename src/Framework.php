<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use function Castor\io;

abstract readonly class Framework
{
    public function __construct(protected DockerCompose $docker = new DockerCompose, private Git $git = new Git) {}

    /**
     * @param  list<string>  $arguments
     */
    public function runConsoleCommand(array $arguments, bool $tty = true): void
    {
        $this->docker->runCommandInService($this->docker->phpService(), [...$this->consoleCommand(), ...$arguments], $tty);
    }

    /**
     * @param  list<string>  $arguments
     */
    public function captureConsoleCommand(array $arguments): string
    {
        return $this->docker->captureCommandInService($this->docker->phpService(), [...$this->consoleCommand(), ...$arguments]);
    }

    public function clearCache(): void
    {
        $this->runConsoleCommand($this->cacheClearArguments(), tty: false);
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
    public function buildConsoleCommand(array $arguments, bool $tty = false): array
    {
        return $this->docker->buildExecCommand($this->docker->phpService(), [...$this->consoleCommand(), ...$arguments], $tty);
    }

    /**
     * The arguments go to the last command, the test runner.
     *
     * @param  list<string>  $arguments
     * @return list<list<string>>
     */
    public function buildTestCommands(array $arguments = []): array
    {
        $commands = $this->docker->project()->testCommands ?? $this->defaultTestCommands();
        $runner = array_pop($commands);

        return [...$commands, [...$runner, ...$arguments]];
    }

    /**
     * @return list<string>
     */
    abstract protected function consoleCommand(): array;

    /**
     * @return list<string>
     */
    abstract protected function cacheClearArguments(): array;

    /**
     * @return non-empty-list<list<string>>
     */
    abstract protected function defaultTestCommands(): array;
}
