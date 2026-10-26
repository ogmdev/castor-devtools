<?php

declare(strict_types=1);

use Castor\Context;
use Ogmdev\CastorDevtools\Checkout;
use Ogmdev\CastorDevtools\Completion;
use Ogmdev\CastorDevtools\Composer;
use Ogmdev\CastorDevtools\DeveloperGuide;
use Ogmdev\CastorDevtools\DockerCompose;
use Ogmdev\CastorDevtools\Git;
use Ogmdev\CastorDevtools\InteractiveContext;
use Ogmdev\CastorDevtools\Laravel;
use Ogmdev\CastorDevtools\PhpStan;
use Ogmdev\CastorDevtools\Pint;
use Ogmdev\CastorDevtools\PreCommit;
use Ogmdev\CastorDevtools\Rector;
use Ogmdev\CastorDevtools\Symfony;
use Ogmdev\CastorDevtools\Tasks;

function mainCompose(): DockerCompose
{
    return new DockerCompose(sampleProject(), Checkout::main('/home/dev/acme'));
}

test('composer and artisan run in the configured php service', function (): void {
    expect((new Composer(mainCompose()))->buildComposerCommand(['install']))->toBe(['docker', 'compose', 'run', '-T', '--rm', 'php-app', 'composer', 'install'])
        ->and((new Laravel(mainCompose()))->buildArtisanCommand(['route:list']))->toBe(['docker', 'compose', 'exec', '-T', 'php-app', 'php', 'artisan', 'route:list']);
});

test('the test run clears the config cache then runs the suite in parallel', function (): void {
    expect((new Laravel(mainCompose()))->buildTestCommands(['--filter=SomeTest']))->toBe([
        ['php', 'artisan', 'config:clear', '--ansi'],
        ['php', 'artisan', 'test', '--compact', '--parallel', '--filter=SomeTest'],
    ]);
});

test('the symfony console runs in the configured php service', function (): void {
    expect((new Symfony(mainCompose()))->buildConsoleCommand(['debug:router']))->toBe(['docker', 'compose', 'exec', '-T', 'php-app', 'php', 'bin/console', 'debug:router']);
});

test('the symfony test run forwards its arguments to phpunit', function (): void {
    expect((new Symfony(mainCompose()))->buildTestCommands(['--filter=SomeTest']))->toBe([
        ['php', 'bin/phpunit', '--filter=SomeTest'],
    ]);
});

test('pint formats dirty files by default and forwards explicit arguments', function (): void {
    expect((new Pint(mainCompose()))->buildCommand())->toBe(['vendor/bin/pint', '-p', '--dirty', '--format', 'agent'])
        ->and((new Pint(mainCompose()))->buildCommand(['--test']))->toBe(['vendor/bin/pint', '-p', '--test']);
});

test('phpstan runs the commands the project declares, whatever the arguments', function (): void {
    expect((new PhpStan(mainCompose(), project: sampleProject()))->buildCommands())->toBe([['composer', 'phpstan']])
        ->and((new PhpStan(mainCompose(), project: sampleProject(['phpstanCommands' => [['composer', 'phpstan'], ['composer', 'phpstan:rules']]])))->buildCommands())
        ->toBe([['composer', 'phpstan'], ['composer', 'phpstan:rules']]);
});

test('rector processes by default and keeps staged files to the analysed php paths', function (): void {
    $rector = new Rector(mainCompose(), project: sampleProject(['rectorAnalysedPaths' => ['app/', 'tests/']]));
    $staged = (new Git)->parseNullSeparatedPaths("app/Service.php\0resources/views/page.blade.php\0tests/Feature/Page Test.php\0castor.php\0");

    expect($rector->buildCommand())->toBe(['vendor/bin/rector', 'process'])
        ->and($rector->buildStagedArguments(dryRun: true))->toBe(['process', '--dry-run', '--no-progress-bar'])
        ->and($rector->filterPhpFiles($staged))->toBe(['app/Service.php', 'tests/Feature/Page Test.php'])
        ->and($rector->analysedPaths())->toBe(['app/', 'tests/']);
});

test('the working tree description names the analysed code and flags uncommitted changes', function (): void {
    expect((new Git)->formatWorkingTreeDescription('main', 'abc1234', true))
        ->toBe('Analysing main @ abc1234, working tree has uncommitted changes.')
        ->and((new Git)->buildStagedFilesCommand('*.php'))->toBe(['git', 'diff', '--cached', '--name-only', '--diff-filter=ACMR', '-z', '--', '*.php']);
});

test('completion targets zsh by default and supports fish', function (): void {
    $completion = new Completion('/home/dev/acme');

    expect($completion->projectCastorExecutable())->toBe('/home/dev/acme/castor')
        ->and($completion->normalizeShell('ZSH'))->toBe('zsh')
        ->and($completion->completionTargetPath('zsh'))->toEndWith('/.zfunc/_castor')
        ->and($completion->completionTargetPath('fish'))->toEndWith('/.config/fish/completions/castor.fish')
        ->and(fn (): string => $completion->normalizeShell('bash'))->toThrow(InvalidArgumentException::class);
});

test('pre-commit installs with the first available package manager', function (): void {
    $preCommit = new PreCommit;

    expect($preCommit->buildInstallArguments())->toBe(['install', '--install-hooks'])
        ->and($preCommit->buildInstallArguments(installHookEnvironments: false))->toBe(['install'])
        ->and($preCommit->chooseInstallStrategy(['brew' => true, 'pipx' => true]))->toBe('brew')
        ->and($preCommit->chooseInstallStrategy(['python3' => true]))->toBe('python3')
        ->and($preCommit->chooseInstallStrategy([]))->toBeNull()
        ->and($preCommit->buildInstallToolCommand('pipx'))->toBe(['pipx', 'install', 'pre-commit']);
});

test('the developer guide flattens the sections the project declares', function (): void {
    $guide = new DeveloperGuide(sampleProject(['guideSections' => ['Docker' => ['./castor docker:start'], 'Tests' => ['./castor test:run']]]));

    expect($guide->everydayCommands())->toBe(['./castor docker:start', './castor test:run'])
        ->and($guide->documentationLinks())->toBe([]);
});

test('only the interactive context guard switches a castor context to interactive mode', function (): void {
    foreach (glob(dirname(__DIR__, 2).'/src/*.php') ?: [] as $file) {
        $source = (string) file_get_contents($file);

        expect(basename($file) === 'InteractiveContext.php' || ! str_contains($source, '->toInteractive('))->toBeTrue(basename($file))
            ->and(str_contains($source, '->withTty('))->toBeFalse(basename($file));
    }
});

test('an interactive context keeps the terminal but still fails the task on a failed command', function (): void {
    $interactive = InteractiveContext::whenSupported(new Context(workingDirectory: '/tmp', supportsInteraction: true));
    $headless = InteractiveContext::whenSupported(new Context(workingDirectory: '/tmp', supportsInteraction: false));

    expect($interactive->tty)->toBeTrue()
        ->and($interactive->timeout)->toBeNull()
        ->and($interactive->allowFailure)->toBeFalse()
        ->and($headless->tty)->toBeFalse()
        ->and($headless->allowFailure)->toBeFalse();
});

test('quality tools hand their exit code back to the castor task instead of exiting', function (): void {
    foreach (['PhpStan', 'Pint', 'Rector'] as $tool) {
        $source = (string) file_get_contents(dirname(__DIR__, 2).'/src/'.$tool.'.php');

        expect($source)->not()->toContain('exit(')
            ->and($source)->toContain('public function run(array $arguments = []): int');
    }
});

test('the messages name the tasks of the project, never a hard-coded executable or task list', function (): void {
    foreach (glob(dirname(__DIR__, 2).'/src/*.php') ?: [] as $file) {
        expect((string) file_get_contents($file))->not()->toContain('./castor', basename($file));
    }

    $tasks = new Tasks(executable: './castor', start: 'stack:up');

    expect($tasks->command($tasks->start))->toBe('./castor stack:up')
        ->and((new Tasks)->command('env:tls'))->toBe('castor env:tls')
        ->and($tasks->command('docker:logs', '--service=php'))->toBe('./castor docker:logs --service=php');
});
