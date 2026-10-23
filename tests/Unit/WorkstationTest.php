<?php

declare(strict_types=1);

use Ogmdev\CastorDevtools\Checkout;
use Ogmdev\CastorDevtools\Node;
use Ogmdev\CastorDevtools\WorktreeSetup;

test('npm runs in a throwaway node container named after the project, as the host user and group, without publishing a port', function (): void {
    $node = new Node('/home/dev/acme', sampleProject());
    $command = $node->buildDockerRunCommand([$node->buildNpmShellCommand(['audit'])], clean: true, user: '1234:20');

    expect($command)->toBe([
        'docker', 'run', '--rm', '--name', 'acme-node', '--user=1234:20',
        '-e', 'SHELL=/bin/sh',
        '-e', 'npm_config_cache=/var/www/html/storage/docker/cache/npm/',
        '-v', '/home/dev/acme:/var/www/html',
        'node:26-alpine',
        'sh', '-c', "cd '/var/www/html' && rm -fr node_modules && 'npm' 'audit'",
    ])
        ->and($node->buildStopCommand())->toBe(['docker', 'stop', 'acme-node']);
});

test('a vite hot file left by a killed dev server is removed, and its absence is not an error', function (): void {
    $directory = temporaryDirectory('castor-devtools-hot');
    mkdir($directory.'/public');
    file_put_contents($directory.'/public/hot', 'http://localhost:5173');
    $node = new Node($directory, sampleProject());

    $node->removeViteHotFile();
    $node->removeViteHotFile();

    expect(is_file($directory.'/public/hot'))->toBeFalse();
});

test('worktree setup clones with an APFS clone on macOS and a reflink elsewhere, and refuses the main checkout', function (): void {
    $worktree = Checkout::fromGitPaths('/home/dev/acme/.git', '/home/dev/acme/.git/worktrees/feature', '/home/dev/acme/.claude/worktrees/feature');

    expect((new WorktreeSetup($worktree, 'Darwin', sampleProject()))->buildCloneCommand('/main/vendor', '/wt/vendor'))->toBe(['cp', '-Rc', '/main/vendor', '/wt/vendor'])
        ->and((new WorktreeSetup($worktree, 'Linux', sampleProject()))->buildCloneCommand('/main/vendor', '/wt/vendor'))->toBe(['cp', '-R', '--reflink=auto', '/main/vendor', '/wt/vendor'])
        ->and(fn () => (new WorktreeSetup(Checkout::main('/home/dev/acme'), project: sampleProject()))->run())->toThrow(RuntimeException::class, 'main checkout');
});

test('worktree setup only brings what the main checkout has and the worktree lacks', function (): void {
    $main = temporaryDirectory('castor-devtools-main');
    $worktree = $main.'/.claude/worktrees/feature';
    mkdir($main.'/vendor', 0777, true);
    mkdir($worktree.'/vendor', 0777, true);
    file_put_contents($main.'/.env', 'APP_ENV=local');
    $setup = new WorktreeSetup(
        Checkout::fromGitPaths($main.'/.git', $main.'/.git/worktrees/feature', $worktree),
        project: sampleProject(['worktreeSymlinks' => ['.env.testing' => '.env.ci']]),
    );

    expect($setup->directoriesToClone($worktree))->toBe(['vendor'])
        ->and($setup->filesToCopy($worktree))->toBe(['.env'])
        ->and($setup->symlinksToCreate($worktree))->toBe(['.env.testing' => '.env.ci']);

    file_put_contents($worktree.'/vendor/autoload.php', '');
    file_put_contents($worktree.'/.env', 'APP_ENV=local');
    symlink('.env.ci', $worktree.'/.env.testing');

    expect($setup->directoriesToClone($worktree))->toBe([])
        ->and($setup->filesToCopy($worktree))->toBe([])
        ->and($setup->symlinksToCreate($worktree))->toBe([]);
});

test('the node container follows the configured container root and npm cache', function (): void {
    $node = new Node('/home/dev/acme', sampleProject(['containerRoot' => '/app/', 'npmCacheDirectory' => '/.cache/npm/']));

    expect($node->buildDockerRunCommand([$node->buildNpmShellCommand(['ci'])], clean: false))->toContain(
        'npm_config_cache=/app/.cache/npm/',
        '/home/dev/acme:/app',
        "cd '/app' && 'npm' 'ci'",
    );
});
