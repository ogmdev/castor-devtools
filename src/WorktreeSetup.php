<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use RuntimeException;

use function Castor\io;
use function Castor\run;

final readonly class WorktreeSetup
{
    private Project $project;

    public function __construct(private Checkout $checkout, private string $operatingSystemFamily = PHP_OS_FAMILY, ?Project $project = null)
    {
        $this->project = $project ?? CurrentProject::get();
    }

    public function run(): void
    {
        if (! $this->checkout->isWorktree()) {
            throw new RuntimeException($this->notAWorktreeMessage());
        }

        $worktree = $this->checkout->workingDirectory();

        foreach ($this->directoriesToClone($worktree) as $directory) {
            $this->cloneDirectory($directory, $worktree);
        }

        foreach ($this->filesToCopy($worktree) as $file) {
            copy($this->checkout->mainDirectory.'/'.$file, $worktree.'/'.$file);
            io()->writeln(sprintf('%s copied from the main checkout.', $file));
        }

        foreach ($this->symlinksToCreate($worktree) as $link => $target) {
            symlink($target, $worktree.'/'.$link);
            io()->writeln(sprintf('%s linked to %s.', $link, $target));
        }

        io()->success(sprintf('Worktree ready: Castor tasks run from %s drive the main checkout stack on its files.', $worktree));
    }

    /**
     * @return list<string>
     */
    public function directoriesToClone(string $worktree): array
    {
        return array_values(array_filter(
            $this->project->worktreeClonedDirectories,
            fn (string $directory): bool => is_dir($this->checkout->mainDirectory.'/'.$directory)
                && $this->isMissingOrEmpty($worktree.'/'.$directory),
        ));
    }

    /**
     * @return list<string>
     */
    public function filesToCopy(string $worktree): array
    {
        return array_values(array_filter(
            $this->project->worktreeCopiedFiles,
            fn (string $file): bool => is_file($this->checkout->mainDirectory.'/'.$file) && ! file_exists($worktree.'/'.$file),
        ));
    }

    /**
     * @return array<string, string>
     */
    public function symlinksToCreate(string $worktree): array
    {
        return array_filter(
            $this->project->worktreeSymlinks,
            static fn (string $link): bool => ! file_exists($worktree.'/'.$link) && ! is_link($worktree.'/'.$link),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return list<string>
     */
    public function buildCloneCommand(string $source, string $target): array
    {
        if ($this->operatingSystemFamily === 'Darwin') {
            return ['cp', '-Rc', $source, $target];
        }

        return ['cp', '-R', '--reflink=auto', $source, $target];
    }

    public function notAWorktreeMessage(): string
    {
        return sprintf(
            '%s is the main checkout, there is nothing to prepare. Run this task from a git worktree created inside %s.',
            $this->checkout->mainDirectory,
            $this->checkout->mainDirectory,
        );
    }

    private function cloneDirectory(string $directory, string $worktree): void
    {
        $target = $worktree.'/'.$directory;

        if (is_dir($target)) {
            rmdir($target);
        }

        run($this->buildCloneCommand($this->checkout->mainDirectory.'/'.$directory, $target));
        io()->writeln(sprintf('%s cloned from the main checkout.', $directory));
    }

    private function isMissingOrEmpty(string $directory): bool
    {
        if (! is_dir($directory)) {
            return true;
        }

        return (scandir($directory) ?: []) === ['.', '..'];
    }
}
