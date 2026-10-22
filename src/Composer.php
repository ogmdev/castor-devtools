<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

final readonly class Composer
{
    public function __construct(private DockerCompose $docker = new DockerCompose) {}

    /**
     * @param  list<string>  $arguments
     */
    public function runComposerCommand(array $arguments): void
    {
        $this->docker->runOneOffCommandInService($this->docker->phpService(), ['composer', ...$arguments]);
    }

    /**
     * @param  list<string>  $arguments
     */
    public function installDependencies(array $arguments = []): void
    {
        $this->runComposerCommand(['install', ...$arguments]);
    }

    /**
     * @param  list<string>  $arguments
     */
    public function updateDependencies(array $arguments = []): void
    {
        $this->runComposerCommand(['update', ...$arguments]);
    }

    /**
     * @param  list<string>  $arguments
     */
    public function auditDependencies(array $arguments = []): void
    {
        $this->runComposerCommand(['audit', ...$arguments]);
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    public function buildComposerCommand(array $arguments): array
    {
        return $this->docker->buildRunCommand($this->docker->phpService(), ['composer', ...$arguments]);
    }
}
