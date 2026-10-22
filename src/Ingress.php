<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use RuntimeException;

use function Castor\context;
use function Castor\run;

final readonly class Ingress
{
    public const string Network = 'local-ingress';

    public const string ProjectName = 'local-ingress';

    public const string Service = 'traefik';

    public const string EnableLabel = 'local-ingress.enable';

    public const int HttpPort = 8000;

    public const int HttpsPort = 8443;

    public const string DashboardUrl = 'http://ingress.localhost:8000/dashboard/';

    private string $dataDirectory;

    public function __construct(private string $traefikImage = 'traefik:v3.6', ?string $dataDirectory = null)
    {
        $this->dataDirectory = rtrim($dataDirectory ?? self::defaultDataDirectory(), '/');
    }

    public static function defaultDataDirectory(): string
    {
        $dataHome = getenv('XDG_DATA_HOME');

        if (is_string($dataHome) && $dataHome !== '') {
            return rtrim($dataHome, '/').'/local-ingress';
        }

        $home = getenv('HOME');

        if (! is_string($home) || $home === '') {
            throw new RuntimeException('The HOME environment variable is required to locate the local ingress data directory.');
        }

        return rtrim($home, '/').'/.local/share/local-ingress';
    }

    public function dataDirectory(): string
    {
        return $this->dataDirectory;
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

    public function ensureRunning(): void
    {
        foreach ([$this->certificatesDirectory(), $this->dynamicDirectory()] as $directory) {
            if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw new RuntimeException(sprintf('Unable to create the local ingress directory %s.', $directory));
            }
        }

        if ($this->exitCode($this->buildNetworkInspectCommand()) !== 0) {
            run($this->buildNetworkCreateCommand(), context()->withQuiet());
        }

        run($this->buildUpCommand(), context()->withEnvironment($this->composeEnvironment()));
    }

    public function isRunning(): bool
    {
        $process = run($this->buildRunningCommand(), context()->withEnvironment($this->composeEnvironment())->withAllowFailure()->withQuiet());

        return trim($process->getOutput()) !== '';
    }

    /**
     * @return array<string, string>
     */
    public function composeEnvironment(): array
    {
        return [
            'LOCAL_INGRESS_DATA_DIR' => $this->dataDirectory,
            'LOCAL_INGRESS_TRAEFIK_IMAGE' => $this->traefikImage,
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
    public function buildRunningCommand(): array
    {
        return [...$this->buildComposeBase(), 'ps', '--quiet', '--status', 'running', self::Service];
    }

    /**
     * @return list<string>
     */
    private function buildComposeBase(): array
    {
        return ['docker', 'compose', '--project-name', self::ProjectName, '--file', $this->composeFile()];
    }

    /**
     * @param  list<string>  $command
     */
    private function exitCode(array $command): int
    {
        return run($command, context()->withAllowFailure()->withQuiet())->getExitCode() ?? 1;
    }
}
