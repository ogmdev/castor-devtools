<?php

declare(strict_types=1);

use Ogmdev\CastorDevtools\Checkout;
use Ogmdev\CastorDevtools\CurrentCheckout;
use Ogmdev\CastorDevtools\DockerCompose;

test('the main checkout adds nothing to the compose commands', function (): void {
    $checkout = Checkout::fromGitPaths('/home/dev/acme/.git', '/home/dev/acme/.git', '/home/dev/acme');

    expect($checkout->isWorktree())->toBeFalse()
        ->and($checkout->mainDirectory)->toBe('/home/dev/acme')
        ->and($checkout->workingDirectory())->toBe('/home/dev/acme')
        ->and($checkout->containerWorkingDirectory())->toBe('/var/www/html')
        ->and($checkout->composeOptions())->toBe([])
        ->and($checkout->containerOptions())->toBe([]);
});

test('a worktree under the main checkout drives the main stack from its own directory', function (): void {
    $checkout = Checkout::fromGitPaths('/home/dev/acme/.git', '/home/dev/acme/.git/worktrees/feature', '/home/dev/acme/.claude/worktrees/feature');

    expect($checkout->isWorktree())->toBeTrue()
        ->and($checkout->workingDirectory())->toBe('/home/dev/acme/.claude/worktrees/feature')
        ->and($checkout->containerWorkingDirectory())->toBe('/var/www/html/.claude/worktrees/feature')
        ->and($checkout->composeOptions())->toBe(['--project-directory', '/home/dev/acme'])
        ->and($checkout->containerOptions())->toBe([
            '-w',
            '/var/www/html/.claude/worktrees/feature',
            '-e',
            'GIT_DIR=/var/www/html/.git/worktrees/feature',
            '-e',
            'GIT_WORK_TREE=/var/www/html/.claude/worktrees/feature',
        ]);
});

test('a worktree outside the main checkout is refused because the containers cannot see it', function (): void {
    expect(fn (): Checkout => Checkout::fromGitPaths('/home/dev/acme/.git', '/home/dev/acme/.git/worktrees/x', '/tmp/x'))
        ->toThrow(RuntimeException::class, 'outside the main checkout');
});

test('the git paths command resolves absolute paths for the three checkout coordinates', function (): void {
    expect(Checkout::buildGitPathsCommand('/home/dev/acme'))->toBe([
        'git', '-C', '/home/dev/acme', 'rev-parse', '--path-format=absolute', '--git-common-dir', '--git-dir', '--show-toplevel',
    ]);
});

test('the current checkout falls back to the working directory', function (): void {
    expect(CurrentCheckout::get()->mainDirectory)->toBe((string) getcwd());
});

test('compose commands built from a worktree target the main project and the worktree directory', function (): void {
    $checkout = Checkout::fromGitPaths('/home/dev/acme/.git', '/home/dev/acme/.git/worktrees/feature', '/home/dev/acme/.claude/worktrees/feature');
    $docker = new DockerCompose(sampleProject(), $checkout);
    $worktreeOptions = [
        '-w', '/var/www/html/.claude/worktrees/feature',
        '-e', 'GIT_DIR=/var/www/html/.git/worktrees/feature',
        '-e', 'GIT_WORK_TREE=/var/www/html/.claude/worktrees/feature',
    ];

    expect($docker->buildComposeCommand(['up', '-d']))->toBe([
        'docker', 'compose', '--project-directory', '/home/dev/acme', 'up', '-d',
    ])->and($docker->buildRunCommand('php-app', ['composer', 'phpstan']))->toBe([
        'docker', 'compose', '--project-directory', '/home/dev/acme', 'run', '-T', '--rm', ...$worktreeOptions, 'php-app', 'composer', 'phpstan',
    ])->and($docker->buildExecCommand('php-app', ['php', 'artisan', 'test'], environment: ['XDEBUG_MODE' => 'off']))->toBe([
        'docker', 'compose', '--project-directory', '/home/dev/acme', 'exec', '-T', ...$worktreeOptions, '-e', 'XDEBUG_MODE=off', 'php-app', 'php', 'artisan', 'test',
    ])->and($docker->buildExecCommand('nginx', ['nginx', '-s', 'reload']))->toBe([
        'docker', 'compose', '--project-directory', '/home/dev/acme', 'exec', '-T', 'nginx', 'nginx', '-s', 'reload',
    ]);
});

test('a worktree loads its own compose file with the personal override of the main checkout', function (): void {
    $main = temporaryDirectory('checkout');
    $worktree = $main.'/.claude/worktrees/feature';
    mkdir($worktree, 0777, true);
    touch($main.'/docker-compose.yml');
    touch($main.'/docker-compose.override.yml');
    touch($worktree.'/docker-compose.yml');

    $checkout = Checkout::fromGitPaths($main.'/.git', $main.'/.git/worktrees/feature', $worktree);

    expect($checkout->composeOptions())->toBe([
        '--project-directory', $main, '-f', $worktree.'/docker-compose.yml', '-f', $main.'/docker-compose.override.yml',
    ]);
});

test('a compose file chain set in the main .env keeps its order and takes each file from the worktree when it has one', function (): void {
    $main = temporaryDirectory('checkout');
    $worktree = $main.'/.claude/worktrees/feature';
    mkdir($worktree.'/docker', 0777, true);
    file_put_contents($main.'/.env', "APP_ENV=local\nCOMPOSE_FILE=\"docker-compose.yml:docker/ci.yml:docker-compose.local.yml\"\n");
    touch($worktree.'/docker-compose.yml');
    touch($worktree.'/docker/ci.yml');

    $checkout = Checkout::fromGitPaths($main.'/.git', $main.'/.git/worktrees/feature', $worktree);
    $exported = getenv('COMPOSE_FILE');
    putenv('COMPOSE_FILE');

    try {
        $composeOptions = $checkout->composeOptions();
    } finally {
        if ($exported !== false) {
            putenv('COMPOSE_FILE='.$exported);
        }
    }

    expect($composeOptions)->toBe([
        '--project-directory', $main,
        '-f', $worktree.'/docker-compose.yml', '-f', $worktree.'/docker/ci.yml', '-f', $main.'/docker-compose.local.yml',
    ]);
});
