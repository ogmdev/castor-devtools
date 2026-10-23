<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use function Castor\io;

final readonly class Rector
{
    private Project $project;

    public function __construct(
        private DockerCompose $docker = new DockerCompose,
        private Git $git = new Git,
        ?Project $project = null,
    ) {
        $this->project = $project ?? CurrentProject::get();
    }

    /**
     * @param  list<string>  $arguments
     */
    public function run(array $arguments = []): int
    {
        try {
            $this->docker->runOneOffCommandInService($this->docker->phpService(), $this->buildCommand($arguments), suppressCastorError: true);
        } catch (CommandFailedException $commandFailedException) {
            return $commandFailedException->exitCode;
        }

        return 0;
    }

    public function runOnStagedPhpFiles(bool $dryRun = false): int
    {
        $stagedPhpFiles = $this->filterPhpFiles($this->git->stagedFiles('*.php'));

        if ($stagedPhpFiles === []) {
            io()->note('No staged PHP files to process.');

            return 0;
        }

        return $this->run([...$this->buildStagedArguments($dryRun), ...$stagedPhpFiles]);
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    public function buildCommand(array $arguments = []): array
    {
        return ['vendor/bin/rector', ...($arguments === [] ? ['process'] : $arguments)];
    }

    /**
     * @return list<string>
     */
    public function buildStagedArguments(bool $dryRun = false): array
    {
        return ['process', ...($dryRun ? ['--dry-run', '--no-progress-bar'] : [])];
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    public function filterPhpFiles(array $files): array
    {
        return array_values(array_filter(
            $files,
            fn (string $file): bool => str_ends_with($file, '.php')
                && ! str_ends_with($file, '.blade.php')
                && array_any($this->project->rectorAnalysedPaths, static fn (string $path): bool => str_starts_with($file, $path)),
        ));
    }

    /**
     * @return list<string>
     */
    public function analysedPaths(): array
    {
        return $this->project->rectorAnalysedPaths;
    }
}
