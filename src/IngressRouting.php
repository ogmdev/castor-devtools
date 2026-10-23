<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use function Castor\context;
use function Castor\run;

final readonly class IngressRouting
{
    private const string RouterLabel = '/^traefik\.http\.routers\.([^.]+)\.rule$/';

    /**
     * @return list<array{name: string, project: string, labels: array<string, string>, networks: list<string>}>
     */
    public function runningContainers(): array
    {
        $ids = array_values(array_filter(explode("\n", trim(run(['docker', 'ps', '--quiet'], context()->withAllowFailure()->withQuiet())->getOutput()))));

        if ($ids === []) {
            return [];
        }

        $process = run(['docker', 'inspect', ...$ids], context()->withAllowFailure()->withQuiet());

        return ($process->getExitCode() ?? 1) === 0 ? $this->parseInspect($process->getOutput()) : [];
    }

    /**
     * @return list<array{name: string, project: string, labels: array<string, string>, networks: list<string>}>
     */
    public function parseInspect(string $json): array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return [];
        }

        $containers = [];

        foreach ($decoded as $container) {
            if (! is_array($container)) {
                continue;
            }

            $labels = array_filter(
                is_array($container['Config']['Labels'] ?? null) ? $container['Config']['Labels'] : [],
                static fn (mixed $value, mixed $key): bool => is_string($key) && is_string($value),
                ARRAY_FILTER_USE_BOTH,
            );
            $networks = is_array($container['NetworkSettings']['Networks'] ?? null) ? $container['NetworkSettings']['Networks'] : [];

            $containers[] = [
                'name' => ltrim(is_string($container['Name'] ?? null) ? $container['Name'] : '', '/'),
                'project' => $labels['com.docker.compose.project'] ?? '',
                'labels' => $labels,
                'networks' => array_values(array_filter(array_keys($networks), is_string(...))),
            ];
        }

        return $containers;
    }

    /**
     * @param  list<array{name: string, project: string, labels: array<string, string>, networks: list<string>}>  $containers
     * @return list<string>
     */
    public function unroutedContainers(array $containers): array
    {
        $unrouted = [];

        foreach ($containers as $container) {
            if ($container['project'] === Ingress::ProjectName || ! $this->hasTraefikLabels($container['labels'])) {
                continue;
            }

            $reasons = [];

            if (($container['labels'][Ingress::EnableLabel] ?? '') !== 'true') {
                $reasons[] = sprintf('no %s=true label', Ingress::EnableLabel);
            }

            if (! in_array(Ingress::Network, $container['networks'], true)) {
                $reasons[] = sprintf('not on the %s network', Ingress::Network);
            }

            if ($reasons !== []) {
                $unrouted[] = sprintf('%s (%s)', $this->describe($container), implode(', ', $reasons));
            }
        }

        return $unrouted;
    }

    /**
     * @param  list<array{name: string, project: string, labels: array<string, string>, networks: list<string>}>  $containers
     * @return list<string>
     */
    public function duplicateRoutes(array $containers): array
    {
        $owners = ['router' => [], 'rule' => []];

        foreach ($containers as $container) {
            if ($container['project'] === Ingress::ProjectName || ! $this->isRouted($container)) {
                continue;
            }

            foreach ($container['labels'] as $label => $value) {
                if (preg_match(self::RouterLabel, $label, $matches) !== 1) {
                    continue;
                }

                $owner = $container['project'] === '' ? $container['name'] : $container['project'];
                $owners['router'][$matches[1]][$owner] = $this->describe($container);
                $owners['rule'][$value][$owner] = $this->describe($container);
            }
        }

        $duplicates = [];

        foreach ($owners as $kind => $byKey) {
            foreach ($byKey as $key => $holders) {
                if (count($holders) > 1) {
                    $duplicates[] = sprintf('%s %s is declared by %s', $kind === 'router' ? 'the router' : 'the rule', $key, implode(' and ', $holders));
                }
            }
        }

        return $duplicates;
    }

    /**
     * @param  array<string, string>  $labels
     */
    private function hasTraefikLabels(array $labels): bool
    {
        return array_any(array_keys($labels), static fn (string $label): bool => str_starts_with($label, 'traefik.'));
    }

    /**
     * @param  array{name: string, project: string, labels: array<string, string>, networks: list<string>}  $container
     */
    private function isRouted(array $container): bool
    {
        return ($container['labels'][Ingress::EnableLabel] ?? '') === 'true' && in_array(Ingress::Network, $container['networks'], true);
    }

    /**
     * @param  array{name: string, project: string, labels: array<string, string>, networks: list<string>}  $container
     */
    private function describe(array $container): string
    {
        return $container['project'] === '' ? $container['name'] : sprintf('%s of the project %s', $container['name'], $container['project']);
    }
}
