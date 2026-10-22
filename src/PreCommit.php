<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use RuntimeException;

use function Castor\capture;
use function Castor\run;

final readonly class PreCommit
{
    public function installHooks(bool $installHookEnvironments = true): void
    {
        $this->runPreCommitCommand($this->buildInstallArguments($installHookEnvironments));
    }

    public function uninstallHooks(): void
    {
        $preCommitExecutable = $this->findPreCommitExecutable();

        if ($preCommitExecutable !== null) {
            run($this->buildPreCommitCommand($preCommitExecutable, $this->buildUninstallArguments()), InteractiveContext::whenSupported());

            return;
        }

        $this->removeGitHookIfItExists();
    }

    /**
     * @param  list<string>  $arguments
     */
    public function runPreCommitCommand(array $arguments): void
    {
        run($this->buildPreCommitCommand($this->ensurePreCommitExecutable(), $arguments), InteractiveContext::whenSupported());
    }

    /**
     * @return list<string>
     */
    public function buildInstallArguments(bool $installHookEnvironments = true): array
    {
        return ['install', ...($installHookEnvironments ? ['--install-hooks'] : [])];
    }

    /**
     * @return list<string>
     */
    public function buildUninstallArguments(): array
    {
        return ['uninstall'];
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    public function buildPreCommitCommand(string $preCommitExecutable, array $arguments): array
    {
        return [$preCommitExecutable, ...$arguments];
    }

    /**
     * @return list<string>
     */
    public function buildInstallToolCommand(string $installStrategy): array
    {
        return match ($installStrategy) {
            'brew' => ['brew', 'install', 'pre-commit'],
            'pipx' => ['pipx', 'install', 'pre-commit'],
            'python3' => ['python3', '-m', 'pip', 'install', '--user', 'pre-commit'],
            'python' => ['python', '-m', 'pip', 'install', '--user', 'pre-commit'],
            default => throw new RuntimeException(sprintf('Unsupported pre-commit install strategy "%s".', $installStrategy)),
        };
    }

    /**
     * @param  array<string, bool>  $availableCommands
     */
    public function chooseInstallStrategy(array $availableCommands): ?string
    {
        foreach (['brew', 'pipx', 'python3', 'python'] as $command) {
            if (($availableCommands[$command] ?? false) === true) {
                return $command;
            }
        }

        return null;
    }

    private function ensurePreCommitExecutable(): string
    {
        $preCommitExecutable = $this->findPreCommitExecutable();

        if ($preCommitExecutable !== null) {
            return $preCommitExecutable;
        }

        $installStrategy = $this->detectInstallStrategy();

        if ($installStrategy === null) {
            throw new RuntimeException('Unable to install pre-commit: install brew, pipx, python3 or python first.');
        }

        run($this->buildInstallToolCommand($installStrategy), InteractiveContext::whenSupported());

        $preCommitExecutable = $this->findPreCommitExecutable();

        if ($preCommitExecutable === null) {
            throw new RuntimeException('pre-commit was installed but cannot be found in PATH. Restart your shell or add ~/.local/bin to PATH.');
        }

        return $preCommitExecutable;
    }

    private function detectInstallStrategy(): ?string
    {
        return $this->chooseInstallStrategy([
            'brew' => $this->commandExists('brew'),
            'pipx' => $this->commandExists('pipx'),
            'python3' => $this->commandExists('python3'),
            'python' => $this->commandExists('python'),
        ]);
    }

    private function findPreCommitExecutable(): ?string
    {
        $preCommitExecutable = $this->captureCommandPath('pre-commit');

        if ($preCommitExecutable !== null) {
            return $preCommitExecutable;
        }

        $homeDirectory = rtrim((string) getenv('HOME'), '/');
        $localUserExecutables = [
            sprintf('%s/.local/bin/pre-commit', $homeDirectory),
            ...glob(sprintf('%s/Library/Python/*/bin/pre-commit', $homeDirectory)) ?: [],
        ];

        foreach ($localUserExecutables as $localUserExecutable) {
            if (is_file($localUserExecutable) && is_executable($localUserExecutable)) {
                return $localUserExecutable;
            }
        }

        return null;
    }

    private function commandExists(string $command): bool
    {
        return $this->captureCommandPath($command) !== null;
    }

    private function captureCommandPath(string $command): ?string
    {
        $path = trim(capture(sprintf('command -v %s || true', escapeshellarg($command))));

        return $path === '' ? null : $path;
    }

    private function removeGitHookIfItExists(): void
    {
        $hookPath = trim(capture('git rev-parse --git-path hooks/pre-commit'));

        if ($hookPath === '' || ! is_file($hookPath)) {
            return;
        }

        $hookContent = file_get_contents($hookPath);

        if ($hookContent === false || ! str_contains($hookContent, 'pre-commit')) {
            return;
        }

        unlink($hookPath);
    }
}
