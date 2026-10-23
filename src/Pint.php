<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use function Castor\io;

final readonly class Pint
{
    public function __construct(private DockerCompose $docker = new DockerCompose, private Git $git = new Git) {}

    /**
     * @param  list<string>  $arguments
     */
    public function run(array $arguments = []): int
    {
        io()->note($this->git->describeWorkingTree());

        try {
            $this->docker->runOneOffCommandInService($this->docker->phpService(), $this->buildCommand($arguments), suppressCastorError: true);
        } catch (CommandFailedException $commandFailedException) {
            return $commandFailedException->exitCode;
        }

        return 0;
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    public function buildCommand(array $arguments = []): array
    {
        return ['vendor/bin/pint', '-p', ...($arguments === [] ? ['--dirty', '--format', 'agent'] : $arguments)];
    }
}
