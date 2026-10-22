<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use function Castor\io;

final readonly class PhpStan
{
    private Project $project;

    public function __construct(private DockerCompose $docker = new DockerCompose, private Git $git = new Git, ?Project $project = null)
    {
        $this->project = $project ?? CurrentProject::get();
    }

    /**
     * @param  list<string>  $arguments
     */
    public function run(array $arguments = []): void
    {
        try {
            if ($arguments !== []) {
                io()->note('Ignoring PHPStan arguments to keep local analysis identical to CI and preserve the result cache.');
            }

            io()->note($this->git->describeWorkingTree());

            foreach ($this->buildCommands() as $command) {
                $this->docker->runOneOffCommandInService($this->docker->phpService(), $command, suppressCastorError: true);
            }
        } catch (CommandFailedException $commandFailedException) {
            exit($commandFailedException->exitCode);
        }
    }

    /**
     * @return list<list<string>>
     */
    public function buildCommands(): array
    {
        return $this->project->phpstanCommands;
    }
}
