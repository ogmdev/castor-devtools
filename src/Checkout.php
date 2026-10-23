<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use RuntimeException;

final readonly class Checkout
{
    public const string ContainerRoot = '/var/www/html';

    private const array ComposeFileNames = ['compose.yaml', 'compose.yml', 'docker-compose.yml', 'docker-compose.yaml'];

    private const array ComposeOverrideFileNames = ['compose.override.yml', 'compose.override.yaml', 'docker-compose.override.yml', 'docker-compose.override.yaml'];

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
            'The worktree %s lives outside the main checkout %s: the containers only mount the main checkout. Create the worktree inside %s.',
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

    public function containerWorkingDirectory(string $containerRoot = self::ContainerRoot): string
    {
        $containerRoot = rtrim($containerRoot, '/');

        return $this->worktreePath === null ? $containerRoot : $containerRoot.'/'.$this->worktreePath;
    }

    /**
     * @return list<string>
     */
    public function composeOptions(): array
    {
        if ($this->worktreePath === null) {
            return [];
        }

        $separator = getenv('COMPOSE_PATH_SEPARATOR');
        $fileOptions = array_map(
            static fn (string $file): array => ['-f', $file],
            $this->composeFiles($this->composeFileVariable(), is_string($separator) && $separator !== '' ? $separator : PATH_SEPARATOR),
        );

        return ['--project-directory', $this->mainDirectory, ...array_merge(...$fileOptions)];
    }

    /**
     * @param  non-empty-string  $separator
     * @return list<string>
     */
    public function composeFiles(?string $composeFileVariable, string $separator = PATH_SEPARATOR): array
    {
        if ($this->worktreePath === null) {
            return [];
        }

        if ($composeFileVariable !== null && $composeFileVariable !== '') {
            return array_map($this->preferWorktreeCopy(...), array_values(array_filter(explode($separator, $composeFileVariable))));
        }

        $composeFile = self::firstExistingFile($this->workingDirectory(), self::ComposeFileNames);

        if ($composeFile === null) {
            return [];
        }

        $overrideFile = self::firstExistingFile($this->workingDirectory(), self::ComposeOverrideFileNames)
            ?? self::firstExistingFile($this->mainDirectory, self::ComposeOverrideFileNames);

        return $overrideFile === null ? [$composeFile] : [$composeFile, $overrideFile];
    }

    /**
     * @return list<string>
     */
    public function containerOptions(string $containerRoot = self::ContainerRoot): array
    {
        if ($this->worktreePath === null || $this->worktreeGitDirectoryPath === null) {
            return [];
        }

        return [
            '-w',
            $this->containerWorkingDirectory($containerRoot),
            '-e',
            'GIT_DIR='.rtrim($containerRoot, '/').'/'.$this->worktreeGitDirectoryPath,
            '-e',
            'GIT_WORK_TREE='.$this->containerWorkingDirectory($containerRoot),
        ];
    }

    private function composeFileVariable(): ?string
    {
        $variable = getenv('COMPOSE_FILE');

        if (is_string($variable) && $variable !== '') {
            return $variable;
        }

        $dotEnv = $this->mainDirectory.'/.env';

        foreach (is_file($dotEnv) ? (file($dotEnv, FILE_IGNORE_NEW_LINES) ?: []) : [] as $line) {
            if (preg_match('/^\s*(?:export\s+)?COMPOSE_FILE\s*=\s*(.*)$/', $line, $match) === 1) {
                return trim(trim($match[1]), '"\'');
            }
        }

        return null;
    }

    private function preferWorktreeCopy(string $file): string
    {
        $absolute = str_starts_with($file, '/') ? $file : $this->mainDirectory.'/'.$file;

        if (! str_starts_with($absolute, $this->mainDirectory.'/')) {
            return $absolute;
        }

        $worktreeCopy = $this->workingDirectory().'/'.substr($absolute, strlen($this->mainDirectory) + 1);

        return is_file($worktreeCopy) ? $worktreeCopy : $absolute;
    }

    /**
     * @param  list<string>  $fileNames
     */
    private static function firstExistingFile(string $directory, array $fileNames): ?string
    {
        foreach ($fileNames as $fileName) {
            if (is_file($directory.'/'.$fileName)) {
                return $directory.'/'.$fileName;
            }
        }

        return null;
    }
}
