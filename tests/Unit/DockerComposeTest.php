<?php

declare(strict_types=1);

use Ogmdev\CastorDevtools\Checkout;
use Ogmdev\CastorDevtools\CurrentProject;
use Ogmdev\CastorDevtools\DockerCompose;

function compose(): DockerCompose
{
    return new DockerCompose(sampleProject(), Checkout::main('/home/dev/acme'));
}

test('a missing project configuration is reported with the call that fixes it', function (): void {
    expect(fn (): DockerCompose => new DockerCompose)->toThrow(LogicException::class, 'CurrentProject::use()');
});

test('the classes read the project registered in castor.php by default', function (): void {
    CurrentProject::use(sampleProject(['phpService' => 'php-registered']));

    expect((new DockerCompose(checkout: Checkout::main('/srv')))->phpService())->toBe('php-registered');
});

test('start, stop and restart target one service or the whole stack', function (): void {
    expect(compose()->buildStartContainersCommand(null))->toBe(['docker', 'compose', 'up', '-d'])
        ->and(compose()->buildStartContainersCommand('php-app'))->toBe(['docker', 'compose', 'up', '-d', 'php-app'])
        ->and(compose()->buildStartContainersCommand(null, ['nginx']))->toBe(['docker', 'compose', 'up', '-d', '--force-recreate', 'nginx']);
});

test('rebuild pulls base images and optionally skips the cache', function (): void {
    expect(compose()->buildRebuildImagesCommand())->toBe(['docker', 'compose', 'build', '--pull'])
        ->and(compose()->buildRebuildImagesCommand(noCache: true))->toBe(['docker', 'compose', 'build', '--pull', '--no-cache'])
        ->and(compose()->buildPullImagesCommand())->toBe(['docker', 'compose', 'pull']);
});

test('exec keeps the tty for shells and passes environment variables', function (): void {
    expect(compose()->buildExecCommand('php-app', ['zsh'], tty: true))->toBe(['docker', 'compose', 'exec', 'php-app', 'zsh'])
        ->and(compose()->buildExecCommand('php-app', ['php', '-v'], environment: ['XDEBUG_MODE' => 'off']))
        ->toBe(['docker', 'compose', 'exec', '-T', '-e', 'XDEBUG_MODE=off', 'php-app', 'php', '-v']);
});

test('nginx reloads follow a restart of its php service or of the whole stack only', function (): void {
    expect(compose()->nginxServicesFor('php-app'))->toBe(['nginx'])
        ->and(compose()->nginxServicesFor('php-api'))->toBe(['nginx-api'])
        ->and(compose()->nginxServicesFor(null))->toBe(['nginx', 'nginx-api'])
        ->and(compose()->nginxServicesFor('nginx'))->toBe([])
        ->and(compose()->buildNginxReloadCommand('nginx'))->toBe(['docker', 'compose', 'exec', '-T', 'nginx', 'nginx', '-s', 'reload']);
});

test('only the configured service prefixes receive the worktree directory', function (): void {
    expect(compose()->mountsProjectAsWorkingDirectory('php-app'))->toBeTrue()
        ->and(compose()->mountsProjectAsWorkingDirectory('test-php-app'))->toBeTrue()
        ->and(compose()->mountsProjectAsWorkingDirectory('nginx'))->toBeFalse();
});

test('profiled commands enable the profile and can run as another user', function (): void {
    expect(compose()->buildProfiledComposeCommand('quality', ['up', '-d', 'lint']))->toBe(['docker', 'compose', '--profile', 'quality', 'up', '-d', 'lint'])
        ->and(compose()->buildProfiledRunCommand('quality', 'lint', ['pint'], '1000'))
        ->toBe(['docker', 'compose', '--profile', 'quality', 'run', '-T', '--user=1000', '--rm', 'lint', 'pint']);
});

test('a captured failure names the command, the service and the output', function (): void {
    expect(compose()->capturedCommandFailureMessage('php-app', ['php', 'artisan', 'about'], 2, ''))
        ->toBe('"php artisan about" failed in php-app with exit code 2.'.PHP_EOL.'(the command printed nothing)');
});

test('the project hook runs before anything is built or pulled', function (): void {
    $calls = 0;
    $project = sampleProject(['beforeBuild' => function () use (&$calls): void {
        $calls++;
    }]);

    $project->warnBeforeBuild();
    sampleProject()->warnBeforeBuild();

    expect($calls)->toBe(1);
});
