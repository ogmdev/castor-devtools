<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

const SECRET_CANARY = 'dummy-secret-canary-0123456789';

const FAKE_DOCKER = <<<'SH'
    #!/bin/sh
    printf '%s [%s]\n' "$*" "$APP_SECRETS_KEY" >> "$DOCKER_CALLS"
    case " $* " in
        *" up "*) exit "$SECRET_COMMAND_EXIT_CODE" ;;
    esac
    SH;

const START_SCENARIO = <<<'PHP_WRAP'
    <?php

    namespace Castor {
        use Symfony\Component\Process\Exception\ProcessFailedException;
        use Symfony\Component\Process\Process;

        final class Context
        {
            public array $environment = [];

            public bool $allowFailure = false;

            public function withEnvironment(array $environment): self
            {
                $context = clone $this;
                $context->environment = $environment;

                return $context;
            }

            public function withAllowFailure(): self
            {
                $context = clone $this;
                $context->allowFailure = true;

                return $context;
            }

            public function __call(string $method, array $arguments): self
            {
                return $this;
            }
        }

        function context(): Context
        {
            return new Context;
        }

        function run(array $command, ?Context $context = null): Process
        {
            $context ??= context();
            $process = new Process($command, null, $context->environment);

            echo json_encode(['env' => $process->getEnv(), 'runnable' => $process->getCommandLine()]), PHP_EOL;

            $process->run();

            if (! $process->isSuccessful() && ! $context->allowFailure) {
                throw new ProcessFailedException($process);
            }

            return $process;
        }
    }

    namespace {
        use Composer\Autoload\ClassLoader;
        use Ogmdev\CastorDevtools\Checkout;
        use Ogmdev\CastorDevtools\DockerCompose;
        use Ogmdev\CastorDevtools\Project;
        use Symfony\Component\Process\Exception\ProcessFailedException;

        [, $packageDirectory, $workingDirectory] = $argv;

        require_once $packageDirectory.'/vendor/composer/ClassLoader.php';

        $loader = new ClassLoader;

        foreach (require $packageDirectory.'/vendor/composer/autoload_psr4.php' as $namespace => $paths) {
            $loader->setPsr4($namespace, $paths);
        }

        $loader->register();

        $project = new Project(
            name: 'acme',
            domain: 'acme.localhost',
            subdomains: ['app'],
            phpService: 'php-app',
            nginxServicesByPhpService: ['php-app' => ['nginx']],
        );

        try {
            new DockerCompose($project, Checkout::main($workingDirectory))->startContainers(null, ['APP_SECRETS_KEY' => getenv('CANARY')]);
        } catch (ProcessFailedException $exception) {
            echo $exception->getMessage(), PHP_EOL;

            exit($exception->getProcess()->getExitCode());
        }

        echo json_encode(['environmentAfter' => $_ENV]), PHP_EOL;
    }
    PHP_WRAP;

function startContainersWithSecret(string $directory, int $secretCommandExitCode): Process
{
    mkdir($directory.'/fake-bin');
    file_put_contents($directory.'/fake-bin/docker', FAKE_DOCKER);
    chmod($directory.'/fake-bin/docker', 0o755);
    file_put_contents($directory.'/scenario.php', START_SCENARIO);

    $process = new Process([PHP_BINARY, $directory.'/scenario.php', dirname(__DIR__, 2), $directory], env: [
        'PATH' => $directory.'/fake-bin:'.getenv('PATH'),
        'CANARY' => SECRET_CANARY,
        'DOCKER_CALLS' => $directory.'/docker-calls',
        'SECRET_COMMAND_EXIT_CODE' => (string) $secretCommandExitCode,
        'APP_SECRETS_KEY' => '',
    ]);
    $process->run();

    return $process;
}

function dockerCallContaining(string $directory, string $argument): string
{
    $calls = array_filter(
        file($directory.'/docker-calls', FILE_IGNORE_NEW_LINES) ?: [],
        fn (string $call): bool => str_contains(" {$call} ", " {$argument} "),
    );

    return array_values($calls)[0] ?? '';
}

test('a failing start receives its secret, and Castor can print it neither from the journal nor from the failure', function (): void {
    $directory = temporaryDirectory('castor-secret-environment');

    $process = startContainersWithSecret($directory, 7);

    expect($process->getExitCode())->toBe(7, $process->getErrorOutput())
        ->and(dockerCallContaining($directory, 'up'))->toEndWith('['.SECRET_CANARY.']')
        ->and($process->getOutput().$process->getErrorOutput())->not->toContain(SECRET_CANARY)
        ->and($process->getOutput())->toContain("'up'");
});

test('the secret stops at the start and reaches neither the nginx reload nor the environment of the task', function (): void {
    $directory = temporaryDirectory('castor-secret-environment');

    $process = startContainersWithSecret($directory, 0);

    expect($process->getErrorOutput())->toBe('')
        ->and(dockerCallContaining($directory, 'up'))->toEndWith('['.SECRET_CANARY.']')
        ->and(dockerCallContaining($directory, 'nginx'))->toEndWith('[]')
        ->and($process->getOutput())->not->toContain(SECRET_CANARY);
});
