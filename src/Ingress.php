<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use RuntimeException;

use function Castor\context;
use function Castor\exit_code;
use function Castor\io;
use function Castor\run;

final readonly class Ingress
{
    public const string Network = 'local-ingress';

    public const string ProjectName = 'local-ingress';

    public const string Service = 'traefik';

    public const string EnableLabel = 'local-ingress.enable';

    public const string VersionLabel = 'local-ingress.version';

    public const string DataDirectoryLabel = 'local-ingress.data-dir';

    public const string ListenIpLabel = 'local-ingress.listen-ip';

    public const string LocalNetworkLabel = 'local-ingress.local-network';

    public const string Version = '0.4.0';

    public const string DefaultImage = 'traefik:v3.6';

    public const int HttpPort = 8000;

    public const int HttpsPort = 8443;

    public const int LanHttpsPort = 8444;

    public const string LanEntryPoint = 'lan';

    public const string DashboardUrl = 'http://ingress.localhost:8000/dashboard/';

    public const string Loopback = '127.0.0.1';

    public const string EveryInterface = '0.0.0.0';

    private string $traefikImage;

    private string $dataDirectory;

    private string $listenIp;

    public function __construct(?string $traefikImage = null, ?string $dataDirectory = null, ?string $listenIp = null, private bool $localNetwork = false)
    {
        $this->traefikImage = $traefikImage ?? self::environmentValue('LOCAL_INGRESS_TRAEFIK_IMAGE') ?? self::DefaultImage;
        $this->dataDirectory = rtrim($dataDirectory ?? self::defaultDataDirectory(), '/');
        $this->listenIp = $listenIp ?? self::environmentValue('LOCAL_INGRESS_LISTEN_IP') ?? self::Loopback;
    }

    public static function defaultDataDirectory(): string
    {
        $configured = self::environmentValue('LOCAL_INGRESS_DATA_DIR');

        if ($configured !== null) {
            return rtrim($configured, '/');
        }

        $dataHome = self::environmentValue('XDG_DATA_HOME');

        if ($dataHome !== null && str_starts_with($dataHome, '/')) {
            return rtrim($dataHome, '/').'/local-ingress';
        }

        $home = self::environmentValue('HOME') ?? throw new RuntimeException('The HOME environment variable is required to locate the local ingress data directory.');

        return rtrim($home, '/').'/.local/share/local-ingress';
    }

    public function dataDirectory(): string
    {
        return $this->dataDirectory;
    }

    public function traefikImage(): string
    {
        return $this->traefikImage;
    }

    /**
     * The address the ingress publishes the ports of every project on: the loopback, unless the workstation sets another.
     */
    public function listenIp(): string
    {
        return $this->listenIp;
    }

    public function runningListenIp(): ?string
    {
        return $this->runningIngress()['listenIp'] ?? null;
    }

    /**
     * The same ingress, publishing on every interface the one port whose entrypoint only serves the routes written for the local network.
     */
    public function openToLocalNetwork(): self
    {
        return new self($this->traefikImage, $this->dataDirectory, $this->listenIp, true);
    }

    public function runsOpenToLocalNetwork(): bool
    {
        return $this->runningIngress()['localNetwork'] ?? false;
    }

    public function certificatesDirectory(): string
    {
        return $this->dataDirectory.'/certs';
    }

    public function dynamicDirectory(): string
    {
        return $this->dataDirectory.'/dynamic';
    }

    public function composeFile(): string
    {
        return dirname(__DIR__).'/ingress/compose.yaml';
    }

    public function localNetworkComposeFile(): string
    {
        return dirname(__DIR__).'/ingress/compose.lan.yaml';
    }

    public function ensureRunning(): void
    {
        $this->ensureDataDirectories();

        $running = $this->runningIngress();

        if ($running !== null && $this->isUpToDate($running['version'])) {
            foreach ($this->runningIngressWarnings($running['version'], $running['dataDirectory'], $running['listenIp']) as $warning) {
                io()->warning($warning);
            }

            return;
        }

        $this->ensureNetwork();

        if ($running !== null) {
            io()->note($this->upgradeMessage($running['version']));
            $this->up($this->buildRestartCommand());

            return;
        }

        $this->ensurePortsAreFree();
        $this->up($this->buildUpCommand());
    }

    public function restart(): void
    {
        $this->ensureDataDirectories();
        $this->ensureNetwork();
        // The running ingress is not a holder of its own ports: the check also covers a recreate that publishes one more.
        $this->ensurePortsAreFree();
        $this->up($this->buildRestartCommand());
    }

    public function isUpToDate(string $runningVersion): bool
    {
        return $runningVersion !== '' && version_compare($runningVersion, self::Version, '>=');
    }

    public function upgradeMessage(string $runningVersion): string
    {
        return sprintf(
            'The local ingress runs %s, older than the %s this project ships: recreating it. The other projects keep their routes, they only need a recent castor-devtools to keep it from being recreated again.',
            $runningVersion === '' ? 'an unversioned release' : 'version '.$runningVersion,
            self::Version,
        );
    }

    public function isRunning(): bool
    {
        return $this->runningIngress() !== null;
    }

    /**
     * @return list<string>
     */
    public function runningIngressWarnings(string $version, string $dataDirectory, string $listenIp = self::Loopback): array
    {
        $warnings = [];

        if ($listenIp !== '' && $listenIp !== self::Loopback && $listenIp !== $this->listenIp) {
            $warnings[] = sprintf(
                'The local ingress listens on %s rather than %s, as a release that opened every vhost to the local network left it, or as LOCAL_INGRESS_LISTEN_IP asks elsewhere: every device reaching that address reaches the vhosts of every project. LocalNetwork::stop() brings it back.',
                $listenIp,
                $this->listenIp,
            );
        }

        if ($version !== '' && version_compare($version, self::Version, '>')) {
            $warnings[] = sprintf(
                'The local ingress runs version %s, newer than the %s this project ships: it is kept as is. Update ogmdev/castor-devtools in this project.',
                $version,
                self::Version,
            );
        }

        if (rtrim($dataDirectory, '/') !== $this->dataDirectory) {
            $warnings[] = sprintf(
                'The local ingress reads its certificates from %s, while this project writes them to %s: set LOCAL_INGRESS_DATA_DIR to the same directory everywhere.',
                $dataDirectory === '' ? 'an unknown directory' : $dataDirectory,
                $this->dataDirectory,
            );
        }

        return $warnings;
    }

    /**
     * @return array{version: string, dataDirectory: string, listenIp: string, localNetwork: bool}|null
     */
    public function parseRunningIngress(string $output): ?array
    {
        $line = trim(strtok($output, "\n") ?: '');

        if ($line === '') {
            return null;
        }

        [$version, $dataDirectory, $listenIp, $localNetwork] = array_pad(explode("\t", $line, 4), 4, '');

        return ['version' => trim($version), 'dataDirectory' => trim($dataDirectory), 'listenIp' => trim($listenIp), 'localNetwork' => trim($localNetwork) === 'true'];
    }

    /**
     * @return list<array{name: string, project: string, service: string}>
     */
    public function parsePortHolders(string $output): array
    {
        $holders = [];

        foreach (explode("\n", trim($output)) as $line) {
            if (trim($line) === '') {
                continue;
            }

            [$name, $project, $service] = array_pad(explode("\t", trim($line), 3), 3, '');

            if ($project !== self::ProjectName) {
                $holders[] = ['name' => $name, 'project' => $project, 'service' => $service];
            }
        }

        return $holders;
    }

    /**
     * @param  array<int, list<array{name: string, project: string, service: string}>>  $holdersByPort
     */
    public function portConflictMessage(array $holdersByPort): ?string
    {
        $lines = [];

        foreach ($holdersByPort as $port => $holders) {
            foreach ($holders as $holder) {
                // Without a service, "docker compose stop" would stop the whole project, so the container is stopped by name.
                $lines[] = match (true) {
                    $holder['project'] === '' => sprintf('- port %d is published by the container %s: docker stop %s', $port, $holder['name'], $holder['name']),
                    $holder['service'] === '' => sprintf('- port %d is published by the container %s of the compose project %s: docker stop %s', $port, $holder['name'], $holder['project'], $holder['name']),
                    default => sprintf(
                        '- port %d is published by the container %s of the compose project %s: docker compose --project-name %s stop %s',
                        $port,
                        $holder['name'],
                        $holder['project'],
                        $holder['project'],
                        $holder['service'],
                    ),
                };
            }
        }

        if ($lines === []) {
            return null;
        }

        return implode(PHP_EOL, [
            sprintf('The local ingress needs %s, shared by every project, and another container holds them:', $this->publishedPorts()),
            ...array_values(array_unique($lines)),
            'Stop it, move that project to the local ingress (label local-ingress.enable and the external network local-ingress), then start again.',
        ]);
    }

    /**
     * @return list<string>
     */
    public function buildPortHoldersCommand(int $port): array
    {
        return [
            'docker',
            'ps',
            '--filter',
            'publish='.$port,
            '--format',
            '{{.Names}}{{"\t"}}{{.Label "com.docker.compose.project"}}{{"\t"}}{{.Label "com.docker.compose.service"}}',
        ];
    }

    public function failureMessage(int $exitCode, string $errorOutput): string
    {
        if (str_contains($errorOutput, 'port is already allocated') || str_contains($errorOutput, 'address already in use')) {
            return sprintf(
                'The local ingress cannot listen on %s: another process already uses one of these ports (a former Traefik, "php artisan serve", "symfony server:start"...). Free them, then start again.',
                $this->publishedPorts(),
            );
        }

        return sprintf('The local ingress did not start (exit code %d). Inspect it with: %s', $exitCode, implode(' ', [...$this->buildComposeBase(), 'logs', self::Service]));
    }

    /**
     * @return array<string, string>
     */
    public function composeEnvironment(): array
    {
        return [
            'LOCAL_INGRESS_DATA_DIR' => $this->dataDirectory,
            'LOCAL_INGRESS_TRAEFIK_IMAGE' => $this->traefikImage,
            'LOCAL_INGRESS_LISTEN_IP' => $this->listenIp,
        ];
    }

    /**
     * @return list<string>
     */
    public function buildNetworkInspectCommand(): array
    {
        return ['docker', 'network', 'inspect', self::Network];
    }

    /**
     * @return list<string>
     */
    public function buildNetworkCreateCommand(): array
    {
        return ['docker', 'network', 'create', self::Network];
    }

    /**
     * @return list<string>
     */
    public function buildUpCommand(): array
    {
        return [...$this->buildComposeBase(), 'up', '-d', '--wait'];
    }

    /**
     * @return list<string>
     */
    public function buildRestartCommand(): array
    {
        return [...$this->buildUpCommand(), '--force-recreate'];
    }

    /**
     * @return list<string>
     */
    public function buildRunningCommand(): array
    {
        return [
            'docker',
            'ps',
            '--filter',
            'label=com.docker.compose.project='.self::ProjectName,
            '--filter',
            'label=com.docker.compose.service='.self::Service,
            '--format',
            sprintf('{{.Label "%s"}}{{"\t"}}{{.Label "%s"}}{{"\t"}}{{.Label "%s"}}{{"\t"}}{{.Label "%s"}}', self::VersionLabel, self::DataDirectoryLabel, self::ListenIpLabel, self::LocalNetworkLabel),
        ];
    }

    /**
     * @return list<string>
     */
    private function buildComposeBase(): array
    {
        return [
            'docker',
            'compose',
            '--project-name',
            self::ProjectName,
            '--file',
            $this->composeFile(),
            ...($this->localNetwork ? ['--file', $this->localNetworkComposeFile()] : []),
        ];
    }

    /**
     * @return list<int>
     */
    private function hostPorts(): array
    {
        return [self::HttpPort, self::HttpsPort, ...($this->localNetwork ? [self::LanHttpsPort] : [])];
    }

    private function publishedPorts(): string
    {
        $ports = sprintf('%1$s:%2$d and %1$s:%3$d', $this->listenIp, self::HttpPort, self::HttpsPort);

        return $this->localNetwork ? sprintf('%s, and %s:%d for the local network', $ports, self::EveryInterface, self::LanHttpsPort) : $ports;
    }

    /**
     * @return array{version: string, dataDirectory: string, listenIp: string, localNetwork: bool}|null
     */
    private function runningIngress(): ?array
    {
        $process = run($this->buildRunningCommand(), context()->withAllowFailure()->withQuiet());

        return ($process->getExitCode() ?? 1) === 0 ? $this->parseRunningIngress($process->getOutput()) : null;
    }

    public function ensureDataDirectories(): void
    {
        foreach ([$this->certificatesDirectory(), $this->dynamicDirectory()] as $directory) {
            if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw new RuntimeException(sprintf('Unable to create the local ingress directory %s.', $directory));
            }
        }
    }

    private function ensurePortsAreFree(): void
    {
        $holdersByPort = [];

        foreach ($this->hostPorts() as $port) {
            $process = run($this->buildPortHoldersCommand($port), context()->withAllowFailure()->withQuiet());
            $holdersByPort[$port] = ($process->getExitCode() ?? 1) === 0 ? $this->parsePortHolders($process->getOutput()) : [];
        }

        $message = $this->portConflictMessage($holdersByPort);

        if ($message !== null) {
            throw new RuntimeException($message);
        }
    }

    private function ensureNetwork(): void
    {
        if ($this->networkExists()) {
            return;
        }

        run($this->buildNetworkCreateCommand(), context()->withAllowFailure()->withQuiet());

        if (! $this->networkExists()) {
            throw new RuntimeException(sprintf('Unable to create the Docker network %s: run "%s" to see why.', self::Network, implode(' ', $this->buildNetworkCreateCommand())));
        }
    }

    /**
     * @phpstan-impure
     */
    private function networkExists(): bool
    {
        return exit_code($this->buildNetworkInspectCommand(), context()->withQuiet()) === 0;
    }

    /**
     * @param  list<string>  $command
     */
    private function up(array $command): void
    {
        $process = run($command, context()->withEnvironment($this->composeEnvironment())->withAllowFailure());
        $exitCode = $process->getExitCode() ?? 1;

        if ($exitCode !== 0) {
            throw new RuntimeException($this->failureMessage($exitCode, $process->getErrorOutput().$process->getOutput()));
        }
    }

    private static function environmentValue(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
