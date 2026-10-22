<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use InvalidArgumentException;

use function Castor\capture;
use function Castor\io;

final readonly class Completion
{
    public function __construct(private string $projectDirectory) {}

    public function installCompletion(string $shell = 'zsh', bool $configure = true): void
    {
        $shell = $this->normalizeShell($shell);
        $target = $this->completionTargetPath($shell);

        $this->ensureDirectory(dirname($target));
        file_put_contents($target, $this->generateCompletionScript($shell));

        if ($shell === 'zsh' && $configure) {
            $this->configureZsh();
        }

        io()->success(sprintf('Castor %s completion installed in %s.', $shell, $target));

        if ($shell === 'zsh') {
            io()->note('Restart the shell with: exec zsh');
        }

        if ($shell === 'fish') {
            io()->note('Restart the shell with: exec fish');
        }
    }

    public function generateCompletionScript(string $shell): string
    {
        return capture([$this->projectCastorExecutable(), 'completion', $this->normalizeShell($shell)]);
    }

    public function completionTargetPath(string $shell): string
    {
        return match ($this->normalizeShell($shell)) {
            'zsh' => $this->home().'/.zfunc/_castor',
            'fish' => $this->home().'/.config/fish/completions/castor.fish',
            default => throw new InvalidArgumentException('Supported completion shells are zsh and fish.'),
        };
    }

    public function projectCastorExecutable(): string
    {
        return $this->projectDirectory.'/castor';
    }

    public function normalizeShell(string $shell): string
    {
        $shell = strtolower($shell);

        if (! in_array($shell, ['zsh', 'fish'], true)) {
            throw new InvalidArgumentException('Supported completion shells are zsh and fish.');
        }

        return $shell;
    }

    private function configureZsh(): void
    {
        $zshrc = $this->home().'/.zshrc';
        $block = <<<'ZSH'

# Castor completion
fpath=(~/.zfunc $fpath)
autoload -Uz compinit
compinit
ZSH;

        $currentContent = is_file($zshrc) ? (string) file_get_contents($zshrc) : '';

        if (str_contains($currentContent, '# Castor completion')) {
            return;
        }

        file_put_contents($zshrc, rtrim($currentContent).$block.PHP_EOL);
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        mkdir($directory, 0775, recursive: true);
    }

    private function home(): string
    {
        $home = getenv('HOME');

        if (! is_string($home) || $home === '') {
            throw new InvalidArgumentException('The HOME environment variable is required.');
        }

        return rtrim($home, '/');
    }
}
