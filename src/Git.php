<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use function Castor\capture;

final readonly class Git
{
    /**
     * @return list<string>
     */
    public function stagedFiles(string $pathspec): array
    {
        return $this->parseNullSeparatedPaths(capture($this->buildStagedFilesCommand($pathspec)));
    }

    /**
     * @return list<string>
     */
    public function buildStagedFilesCommand(string $pathspec): array
    {
        return ['git', 'diff', '--cached', '--name-only', '--diff-filter=ACMR', '-z', '--', $pathspec];
    }

    public function describeWorkingTree(): string
    {
        return $this->formatWorkingTreeDescription(
            trim(capture($this->buildCurrentBranchCommand())),
            trim(capture($this->buildHeadRevisionCommand())),
            trim(capture($this->buildUncommittedChangesCommand())) !== '',
        );
    }

    public function formatWorkingTreeDescription(string $branch, string $revision, bool $hasUncommittedChanges): string
    {
        return sprintf(
            'Analysing %s @ %s, %s.',
            $branch,
            $revision,
            $hasUncommittedChanges ? 'working tree has uncommitted changes' : 'working tree clean',
        );
    }

    /**
     * @return list<string>
     */
    public function buildCurrentBranchCommand(): array
    {
        return ['git', 'rev-parse', '--abbrev-ref', 'HEAD'];
    }

    /**
     * @return list<string>
     */
    public function buildHeadRevisionCommand(): array
    {
        return ['git', 'rev-parse', '--short', 'HEAD'];
    }

    /**
     * @return list<string>
     */
    public function buildUncommittedChangesCommand(): array
    {
        return ['git', 'status', '--porcelain', '--untracked-files=no'];
    }

    /**
     * @return list<string>
     */
    public function parseNullSeparatedPaths(string $paths): array
    {
        if ($paths === '') {
            return [];
        }

        return explode("\0", rtrim($paths, "\0"));
    }
}
