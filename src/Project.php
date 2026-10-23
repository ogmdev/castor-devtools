<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use Closure;

final readonly class Project
{
    public Tasks $tasks;

    /**
     * @param  list<string>  $subdomains
     * @param  array<string, string>  $subdomainPaths
     * @param  array<string, list<string>>  $nginxServicesByPhpService
     * @param  list<string>  $projectMountedServicePrefixes
     * @param  list<list<string>>  $phpstanCommands
     * @param  list<string>  $rectorAnalysedPaths
     * @param  list<string>  $worktreeClonedDirectories
     * @param  list<string>  $worktreeCopiedFiles
     * @param  array<string, string>  $worktreeSymlinks
     * @param  array<string, list<string>>  $guideSections
     * @param  array<string, string>  $documentationLinks
     */
    public function __construct(
        public string $name,
        public string $domain,
        public array $subdomains,
        public string $phpService = 'php',
        public array $nginxServicesByPhpService = [],
        public array $projectMountedServicePrefixes = ['php'],
        public array $subdomainPaths = [],
        public ?string $viteSubdomain = 'vite',
        public int $vitePort = 5173,
        public string $nodeImage = 'node:26-alpine',
        public array $phpstanCommands = [['composer', 'phpstan']],
        public array $rectorAnalysedPaths = ['app/', 'bootstrap/', 'config/', 'database/', 'routes/', 'tests/'],
        public array $worktreeClonedDirectories = ['vendor', 'public/build'],
        public array $worktreeCopiedFiles = ['.env'],
        public array $worktreeSymlinks = [],
        public array $guideSections = [],
        public array $documentationLinks = [],
        public ?string $setupDocumentationUrl = null,
        public ?string $troubleshootingDocumentationUrl = null,
        public ?Closure $beforeBuild = null,
        ?Tasks $tasks = null,
        public string $containerRoot = Checkout::ContainerRoot,
        public string $containerShell = 'sh',
        public string $npmCacheDirectory = 'storage/docker/cache/npm',
        public ?string $supportContact = null,
    ) {
        $this->tasks = $tasks ?? new Tasks;
    }

    public function warnBeforeBuild(): void
    {
        if ($this->beforeBuild !== null) {
            ($this->beforeBuild)();
        }
    }

    public function nodeContainerName(): string
    {
        return $this->name.'-node';
    }

    public function task(string $task, string $arguments = ''): string
    {
        return $this->tasks->command($task, $arguments);
    }

    /**
     * @return list<string>
     */
    public function nginxServicesFor(?string $service): array
    {
        $nginxServices = [];

        foreach ($this->nginxServicesByPhpService as $phpService => $nginx) {
            if ($service === null || $service === $phpService) {
                array_push($nginxServices, ...$nginx);
            }
        }

        return $nginxServices;
    }

    public function mountsProjectAsWorkingDirectory(string $service): bool
    {
        return array_any(
            $this->projectMountedServicePrefixes,
            static fn (string $prefix): bool => str_starts_with($service, $prefix),
        );
    }
}
