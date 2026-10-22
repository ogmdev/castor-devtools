<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use RuntimeException;

final readonly class Checkout
{
    public const string ContainerRoot = '/var/www/html';

    private function __construct(
        public string $mainDirectory,
        private ?string $worktreePath,
        private ?string $worktreeGitDirectoryPath,
    ) {}

    public static function main(string $mainDirectory): self
    {
        return new self(rtrim($mainDirectory, '/'), null, null);
    }

    public static function fromGitPaths(string $commonDirectory, string $gitDirectory, string $topLevel): self
    {
        $mainDirectory = dirname(rtrim($commonDirectory, '/'));
        $topLevel = rtrim($topLevel, '/');

        if ($topLevel === $mainDirectory) {
            return self::main($mainDirectory);
        }

        if (! str_starts_with($topLevel, $mainDirectory.'/')) {
            throw new RuntimeException(self::outsideMainCheckoutMessage($topLevel, $mainDirectory));
        }

        return new self(
            $mainDirectory,
            substr($topLevel, strlen($mainDirectory) + 1),
            substr(rtrim($gitDirectory, '/'), strlen($mainDirectory) + 1),
        );
    }

    public static function fromGit(string $directory): self
    {
        $lines = [];
        $exitCode = 1;

        exec(implode(' ', array_map(escapeshellarg(...), self::buildGitPathsCommand($directory))).' 2>/dev/null', $lines, $exitCode);

        if ($exitCode !== 0 || count($lines) !== 3) {
            return self::main($directory);
        }

        return self::fromGitPaths($lines[0], $lines[1], $lines[2]);
    }

    /**
     * @return list<string>
     */
    public static function buildGitPathsCommand(string $directory): array
    {
        return ['git', '-C', $directory, 'rev-parse', '--path-format=absolute', '--git-common-dir', '--git-dir', '--show-toplevel'];
    }

    public static function outsideMainCheckoutMessage(string $topLevel, string $mainDirectory): string
    {
        return sprintf(
            'The worktree %s lives outside the main checkout %s: the containers only mount the main checkout. Create the worktree under %s/.claude/worktrees/.',
            $topLevel,
            $mainDirectory,
            $mainDirectory,
        );
    }

    public function isWorktree(): bool
    {
        return $this->worktreePath !== null;
    }

    public function workingDirectory(): string
    {
        return $this->worktreePath === null ? $this->mainDirectory : $this->mainDirectory.'/'.$this->worktreePath;
    }

    public function containerWorkingDirectory(): string
    {
        return $this->worktreePath === null ? self::ContainerRoot : self::ContainerRoot.'/'.$this->worktreePath;
    }

    /**
     * @return list<string>
     */
    public function composeOptions(): array
    {
        return $this->worktreePath === null ? [] : ['--project-directory', $this->mainDirectory];
    }

    /**
     * @return list<string>
     */
    public function containerOptions(): array
    {
        if ($this->worktreePath === null || $this->worktreeGitDirectoryPath === null) {
            return [];
        }

        return [
            '-w',
            $this->containerWorkingDirectory(),
            '-e',
            'GIT_DIR='.self::ContainerRoot.'/'.$this->worktreeGitDirectoryPath,
            '-e',
            'GIT_WORK_TREE='.$this->containerWorkingDirectory(),
        ];
    }
}
