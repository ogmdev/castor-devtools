<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use RuntimeException;

use function Castor\context;
use function Castor\exit_code;
use function Castor\run;

final readonly class DockerCompose
{
    private Checkout $checkout;

    private Project $project;

    public function __construct(?Project $project = null, ?Checkout $checkout = null)
    {
        $this->project = $project ?? CurrentProject::get();
        $this->checkout = $checkout ?? CurrentCheckout::get();
    }

    public function project(): Project
    {
        return $this->project;
    }

    public function phpService(): string
    {
        return $this->project->phpService;
    }

    /**
     * @param  array<string, string>  $environment
     * @param  list<string>  $forceRecreate
     */
    public function startContainers(?string $service = null, array $environment = [], array $forceRecreate = []): void
    {
        $this->project->warnBeforeBuild();

        run($this->buildStartContainersCommand($service, $forceRecreate), context()->withEnvironment($environment));

        $this->reloadNginxUpstreams($service);
    }

    /**
     * @param  list<string>  $forceRecreate
     * @return list<string>
     */
    public function buildStartContainersCommand(?string $service, array $forceRecreate = []): array
    {
        if ($forceRecreate !== []) {
            return $this->buildComposeCommand(['up', '-d', '--force-recreate', ...$forceRecreate]);
        }

        return $this->buildComposeCommand(array_filter(['up', '-d', $service]));
    }

    public function stopContainers(?string $service = null): void
    {
        run($this->buildComposeCommand(array_filter(['stop', $service])));
    }

    public function restartContainers(?string $service = null): void
    {
        run($this->buildComposeCommand(array_filter(['restart', $service])));

        $this->reloadNginxUpstreams($service);
    }

    /**
     * @return list<string>
     */
    public function nginxServicesFor(?string $service): array
    {
        return $this->project->nginxServicesFor($service);
    }

    /**
     * @return list<string>
     */
    public function buildNginxReloadCommand(string $nginxService): array
    {
        return $this->buildComposeCommand(['exec', '-T', $nginxService, 'nginx', '-s', 'reload']);
    }

    public function reloadNginxUpstreams(?string $service = null): void
    {
        foreach ($this->nginxServicesFor($service) as $nginxService) {
            run($this->buildNginxReloadCommand($nginxService), context()->withAllowFailure()->withQuiet());
        }
    }

    public function pullImages(): void
    {
        $this->project->warnBeforeBuild();

        run($this->buildPullImagesCommand());
    }

    public function rebuildContainers(bool $noCache = false): void
    {
        $this->pullImages();

        run($this->buildRebuildImagesCommand($noCache));
    }

    public function startProfiledService(string $profile, string $service): void
    {
        run($this->buildProfiledComposeCommand($profile, ['up', '-d', $service]));
    }

    /**
     * @param  list<string>  $command
     */
    public function runProfiledOneOffCommand(string $profile, string $service, array $command, ?string $user = null): void
    {
        run($this->buildProfiledRunCommand($profile, $service, $command, $user));
    }

    public function showContainerStatus(): void
    {
        run($this->buildComposeCommand(['ps']));
    }

    public function streamLogs(?string $service = null, int $tail = 100, bool $follow = false): void
    {
        $arguments = ['logs', sprintf('--tail=%d', $tail)];

        if ($follow) {
            $arguments[] = '--follow';
        }

        if ($service !== null) {
            $arguments[] = $service;
        }

        run($this->buildComposeCommand($arguments), InteractiveContext::whenSupported());
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function runCommandInService(string $service, array $command, bool $tty = false, array $environment = []): void
    {
        $this->ensureDockerDaemonIsRunning();
        $this->ensureServiceIsRunning($service);

        if ($tty && context()->supportsInteraction()) {
            run($this->buildExecCommand($service, $command, true, $environment), InteractiveContext::whenSupported());

            return;
        }

        run($this->buildExecCommand($service, $command, false, $environment));
    }

    /**
     * @param  array<string, string>  $environment
     */
    public function openShellInService(string $service, array $environment = []): void
    {
        $context = InteractiveContext::required();

        $this->ensureDockerDaemonIsRunning();
        $this->ensureServiceIsRunning($service);

        run($this->buildExecCommand($service, [$this->project->containerShell], true, $environment), $context);
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     */
    public function captureCommandInService(string $service, array $command, array $environment = []): string
    {
        $this->ensureDockerDaemonIsRunning();
        $this->ensureServiceIsRunning($service);

        $process = run(
            $this->buildExecCommand($service, $command, environment: $environment),
            context()->withAllowFailure()->withQuiet(),
        );

        $output = trim((string) $process->getOutput());

        if (($process->getExitCode() ?? 1) !== 0) {
            throw new RuntimeException($this->capturedCommandFailureMessage($service, $command, $process->getExitCode() ?? 1, trim($output."\n".$process->getErrorOutput())));
        }

        return $output;
    }

    /**
     * @param  list<string>  $command
     */
    public function capturedCommandFailureMessage(string $service, array $command, int $exitCode, string $output): string
    {
        return implode(PHP_EOL, [
            sprintf('"%s" failed in %s with exit code %d.', implode(' ', $command), $service, $exitCode),
            $output === '' ? '(the command printed nothing)' : $output,
        ]);
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     *
     * @throws CommandFailedException when $suppressCastorError is true and the command exits with a non-zero code
     */
    public function runOneOffCommandInService(string $service, array $command, array $environment = [], bool $suppressCastorError = false): void
    {
        $this->ensureDockerDaemonIsRunning();
        $this->project->warnBeforeBuild();

        if (! $suppressCastorError) {
            run($this->buildRunCommand($service, $command, $environment));

            return;
        }

        $process = run($this->buildRunCommand($service, $command, $environment), context()->withAllowFailure());
        $exitCode = $process->getExitCode() ?? 1;

        if ($exitCode !== 0) {
            throw new CommandFailedException($exitCode);
        }
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    public function buildComposeCommand(array $arguments): array
    {
        return [...$this->buildComposeBase(), ...$arguments];
    }

    /**
     * @return list<string>
     */
    private function buildComposeBase(): array
    {
        return ['docker', 'compose', ...$this->checkout->composeOptions()];
    }

    public function mountsProjectAsWorkingDirectory(string $service): bool
    {
        return $this->project->mountsProjectAsWorkingDirectory($service);
    }

    /**
     * @return list<string>
     */
    private function buildContainerOptions(string $service): array
    {
        return $this->mountsProjectAsWorkingDirectory($service) ? $this->checkout->containerOptions($this->project->containerRoot) : [];
    }

    /**
     * @return list<string>
     */
    public function buildPullImagesCommand(): array
    {
        return $this->buildComposeCommand(['pull']);
    }

    /**
     * @return list<string>
     */
    public function buildDockerInfoCommand(): array
    {
        return ['docker', 'info', '--format', '{{.ServerVersion}}'];
    }

    /**
     * @return list<string>
     */
    public function buildRunningServicesCommand(string $service): array
    {
        return $this->buildComposeCommand(['ps', '--services', '--status', 'running', $service]);
    }

    /**
     * @return list<string>
     */
    public function buildStartServiceCommand(string $service): array
    {
        return $this->buildComposeCommand(['up', '-d', $service]);
    }

    public function dockerUnavailableMessage(): string
    {
        return implode(PHP_EOL, [
            'Docker does not seem to be running or reachable.',
            'Start the Docker engine (Docker Desktop, OrbStack, Colima, or the docker service on Linux), then run the command again.',
        ]);
    }

    public function serviceStartFailedMessage(string $service): string
    {
        return sprintf(
            'Docker is reachable, but the service "%s" could not be started. Run "%s", then run the command again.',
            $service,
            $this->project->task($this->project->tasks->start),
        );
    }

    public function serviceStillStoppedMessage(string $service): string
    {
        return sprintf(
            'The Docker service "%s" is still stopped after the automatic start. Check it with "%s".',
            $service,
            $this->project->task($this->project->tasks->status),
        );
    }

    /**
     * @return list<string>
     */
    public function buildRebuildImagesCommand(bool $noCache = false): array
    {
        return $this->buildComposeCommand([
            'build',
            '--pull',
            ...($noCache ? ['--no-cache'] : []),
        ]);
    }

    /**
     * @param  list<string>  $arguments
     * @return list<string>
     */
    public function buildProfiledComposeCommand(string $profile, array $arguments): array
    {
        return [...$this->buildComposeBase(), '--profile', $profile, ...$arguments];
    }

    /**
     * @param  list<string>  $command
     * @return list<string>
     */
    public function buildProfiledRunCommand(string $profile, string $service, array $command, ?string $user = null): array
    {
        return [
            ...$this->buildComposeBase(),
            '--profile',
            $profile,
            'run',
            '-T',
            ...($user === null ? [] : [sprintf('--user=%s', $user)]),
            '--rm',
            ...$this->buildContainerOptions($service),
            $service,
            ...$command,
        ];
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     * @return list<string>
     */
    public function buildExecCommand(string $service, array $command, bool $tty = false, array $environment = []): array
    {
        return [
            ...$this->buildComposeBase(),
            'exec',
            ...($tty ? [] : ['-T']),
            ...$this->buildContainerOptions($service),
            ...$this->buildEnvironmentOptions($environment),
            $service,
            ...$command,
        ];
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>  $environment
     * @return list<string>
     */
    public function buildRunCommand(string $service, array $command, array $environment = []): array
    {
        return [
            ...$this->buildComposeBase(),
            'run',
            '-T',
            '--rm',
            ...$this->buildContainerOptions($service),
            ...$this->buildEnvironmentOptions($environment),
            $service,
            ...$command,
        ];
    }

    /**
     * @param  array<string, string>  $environment
     * @return list<string>
     */
    private function buildEnvironmentOptions(array $environment): array
    {
        $options = [];

        foreach ($environment as $name => $value) {
            $options[] = '-e';
            $options[] = sprintf('%s=%s', $name, $value);
        }

        return $options;
    }

    private function ensureDockerDaemonIsRunning(): void
    {
        if ($this->commandExitCode($this->buildDockerInfoCommand()) === 0) {
            return;
        }

        throw new RuntimeException($this->dockerUnavailableMessage());
    }

    private function ensureServiceIsRunning(string $service): void
    {
        if ($this->isServiceRunning($service)) {
            return;
        }

        $exitCode = $this->commandExitCode($this->buildStartServiceCommand($service));

        if ($exitCode !== 0) {
            throw new RuntimeException($this->serviceStartFailedMessage($service));
        }

        if (! $this->isServiceRunning($service)) {
            throw new RuntimeException($this->serviceStillStoppedMessage($service));
        }
    }

    /**
     * @phpstan-impure
     */
    private function isServiceRunning(string $service): bool
    {
        $runningServices = array_filter(explode(PHP_EOL, $this->commandOutput($this->buildRunningServicesCommand($service))));

        return in_array($service, $runningServices, true);
    }

    /**
     * @param  list<string>  $command
     */
    private function commandExitCode(array $command): int
    {
        return exit_code($command, context()->withQuiet());
    }

    /**
     * @param  list<string>  $command
     */
    private function commandOutput(array $command): string
    {
        $process = run($command, context()->withAllowFailure()->withQuiet());

        if (($process->getExitCode() ?? 1) !== 0) {
            return '';
        }

        return trim((string) $process->getOutput());
    }
}
