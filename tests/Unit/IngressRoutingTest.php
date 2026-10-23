<?php

declare(strict_types=1);

use Ogmdev\CastorDevtools\Environment;
use Ogmdev\CastorDevtools\FindingSeverity;
use Ogmdev\CastorDevtools\Ingress;
use Ogmdev\CastorDevtools\IngressRouting;

/**
 * @param  array<string, string>  $labels
 * @param  list<string>  $networks
 * @return array<string, mixed>
 */
function inspected(string $name, string $project, array $labels, array $networks = ['local-ingress']): array
{
    return [
        'Name' => '/'.$name,
        'Config' => ['Labels' => [...($project === '' ? [] : ['com.docker.compose.project' => $project]), ...$labels]],
        'NetworkSettings' => ['Networks' => array_fill_keys($networks, ['NetworkID' => 'x'])],
    ];
}

/**
 * @return array<string, string>
 */
function routed(string $router, string $rule): array
{
    return [
        Ingress::EnableLabel => 'true',
        "traefik.http.routers.{$router}.rule" => $rule,
        "traefik.http.routers.{$router}.entrypoints" => 'web,websecure',
    ];
}

/**
 * @param  list<array<string, mixed>>  $containers
 * @return list<array{name: string, project: string, labels: array<string, string>, networks: list<string>}>
 */
function containers(array $containers): array
{
    return (new IngressRouting)->parseInspect((string) json_encode($containers));
}

test('docker inspect is read into names, compose projects, labels and networks', function (): void {
    expect(containers([inspected('acme-nginx-1', 'acme', routed('acme-app', 'Host(`app.acme.localhost`)'), ['acme_default', 'local-ingress'])]))->toBe([[
        'name' => 'acme-nginx-1',
        'project' => 'acme',
        'labels' => ['com.docker.compose.project' => 'acme', ...routed('acme-app', 'Host(`app.acme.localhost`)')],
        'networks' => ['acme_default', 'local-ingress'],
    ]])
        ->and((new IngressRouting)->parseInspect('not json'))->toBe([]);
});

test('containers with traefik labels but without the opt-in label or the shared network are reported as unrouted', function (): void {
    $unrouted = (new IngressRouting)->unroutedContainers(containers([
        inspected('acme-nginx-1', 'acme', routed('acme-app', 'Host(`app.acme.localhost`)')),
        inspected('saas-nginx-1', 'saas', ['ingress.stack' => 'saas', 'traefik.http.routers.saas.rule' => 'Host(`saas.localhost`)'], ['saas_default']),
        inspected('saas-api-1', 'saas', routed('saas-api', 'Host(`api.saas.localhost`)'), ['saas_default']),
        inspected('saas-redis-1', 'saas', []),
        inspected('local-ingress-traefik-1', 'local-ingress', ['traefik.http.routers.local-ingress-dashboard.rule' => 'Host(`ingress.localhost`)']),
    ]));

    expect($unrouted)->toBe([
        'saas-nginx-1 of the project saas (no local-ingress.enable=true label, not on the local-ingress network)',
        'saas-api-1 of the project saas (not on the local-ingress network)',
    ]);
});

test('a router name or a host rule claimed by two compose projects is reported, replicas of one project are not', function (): void {
    $duplicates = (new IngressRouting)->duplicateRoutes(containers([
        inspected('acme-nginx-1', 'acme', routed('app', 'Host(`app.acme.localhost`)')),
        inspected('acme-nginx-2', 'acme', routed('app', 'Host(`app.acme.localhost`)')),
        inspected('other-nginx-1', 'other', routed('app', 'Host(`app.other.localhost`)')),
        inspected('copy-nginx-1', 'copy', routed('copy-app', 'Host(`app.acme.localhost`)')),
        inspected('acme-vite', '', routed('acme-vite', 'Host(`vite.acme.localhost`)')),
    ]));

    expect($duplicates)->toBe([
        'the router app is declared by acme-nginx-2 of the project acme and other-nginx-1 of the project other',
        'the rule Host(`app.acme.localhost`) is declared by acme-nginx-2 of the project acme and copy-nginx-1 of the project copy',
    ]);
});

test('the routing findings block on shared routes, warn on unrouted containers, and are ok otherwise', function (): void {
    $environment = new Environment(sampleProject(), new Ingress(dataDirectory: '/data'));

    $clean = $environment->routingFindings(containers([inspected('acme-nginx-1', 'acme', routed('acme-app', 'Host(`app.acme.localhost`)'))]));
    $broken = $environment->routingFindings(containers([
        inspected('acme-nginx-1', 'acme', routed('app', 'Host(`app.acme.localhost`)')),
        inspected('other-nginx-1', 'other', routed('app', 'Host(`app.other.localhost`)')),
        inspected('saas-nginx-1', 'saas', ['traefik.http.routers.saas.rule' => 'Host(`saas.localhost`)'], ['saas_default']),
    ]));

    expect($clean)->toHaveCount(1)
        ->and($clean[0]->severity)->toBe(FindingSeverity::Ok)
        ->and(array_map(static fn ($finding): FindingSeverity => $finding->severity, $broken))->toBe([FindingSeverity::Blocking, FindingSeverity::Warning])
        ->and($broken[0]->detail)->toContain('the router app')
        ->and($broken[1]->detail)->toContain('saas-nginx-1', 'compose override');
});
