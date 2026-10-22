<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use function Castor\capture;
use function Castor\context;
use function Castor\run;

final readonly class Node
{
    private const string ViteHotFile = 'public/hot';

    private Project $project;

    public function __construct(private string $projectDirectory, ?Project $project = null)
    {
        $this->project = $project ?? CurrentProject::get();
    }

    /**
     * @param  list<string>  $arguments
     */
    public function runNpmCommand(array $arguments, bool $clean = false, bool $pull = false, bool $interactive = true): void
    {
        if ($pull) {
            run(['docker', 'pull', $this->project->nodeImage]);
        }

        $this->stopRunningContainer();

        $command = $this->buildDockerRunCommand([$this->buildNpmShellCommand($arguments)], $clean, capture('id -u'));

        if ($interactive) {
            run($command, InteractiveContext::whenSupported());

            return;
        }

        run($command);
    }

    public function stopRunningContainer(): void
    {
        run($this->buildStopCommand(), context()->withAllowFailure()->withQuiet());
        run(['docker', 'rm', '-f', $this->project->nodeContainerName()], context()->withAllowFailure()->withQuiet());

        $this->removeViteHotFile();
    }

    /**
     * @return list<string>
     */
    public function buildStopCommand(): array
    {
        return ['docker', 'stop', $this->project->nodeContainerName()];
    }

    public function isDevServerRunning(): bool
    {
        $containerId = run(
            ['docker', 'ps', '--quiet', '--filter', sprintf('name=^%s$', $this->project->nodeContainerName())],
            context()->withAllowFailure()->withQuiet(),
        )->getOutput();

        return trim($containerId) !== '';
    }

    public function viteHotFile(): string
    {
        return $this->projectDirectory.'/'.self::ViteHotFile;
    }

    public function removeViteHotFile(): void
    {
        if (is_file($this->viteHotFile())) {
            unlink($this->viteHotFile());
        }
    }

    /**
     * @param  list<string>  $arguments
     */
    public function installDependencies(array $arguments = [], bool $clean = false, bool $pull = false): void
    {
        $this->runNpmCommand(['install', ...$arguments], $clean, $pull);
    }

    /**
     * @param  list<string>  $arguments
     */
    public function updateDependencies(array $arguments = [], bool $pull = false): void
    {
        $this->runNpmCommand(['update', ...$arguments], pull: $pull);
    }

    /**
     * @param  list<string>  $arguments
     */
    public function auditDependencies(array $arguments = [], bool $pull = false): void
    {
        $this->runNpmCommand(['audit', ...$arguments], pull: $pull);
    }

    public function lintJavaScript(bool $pull = false): void
    {
        $this->runNpmCommand($this->buildJavaScriptLintArguments(), pull: $pull, interactive: false);
    }

    /**
     * @return list<string>
     */
    public function buildJavaScriptLintArguments(): array
    {
        return ['run', 'lint'];
    }

    /**
     * @param  list<string>  $commands
     * @param  list<string>  $options
     * @return list<string>
     */
    public function buildDockerRunCommand(array $commands, bool $clean, string $userId = '1000', array $options = []): array
    {
        return [
            'docker',
            'run',
            '--rm',
            '--name',
            $this->project->nodeContainerName(),
            sprintf('-u%s', $userId),
            '-e',
            'SHELL=/bin/sh',
            '-e',
            sprintf('npm_config_cache=%s/storage/docker/cache/npm/', Checkout::ContainerRoot),
            '-v',
            sprintf('%s:%s', $this->projectDirectory, Checkout::ContainerRoot),
            ...$options,
            $this->project->nodeImage,
            'sh',
            '-c',
            $this->buildShellCommand($commands, $clean),
        ];
    }

    /**
     * @param  list<string>  $commands
     */
    public function buildShellCommand(array $commands, bool $clean): string
    {
        $steps = [sprintf('cd %s', Checkout::ContainerRoot)];

        if ($clean) {
            $steps[] = 'rm -fr node_modules';
        }

        return implode(' && ', [...$steps, ...$commands]);
    }

    /**
     * @param  list<string>  $arguments
     */
    public function buildNpmShellCommand(array $arguments): string
    {
        return implode(' ', array_map(escapeshellarg(...), ['npm', ...$arguments]));
    }
}
