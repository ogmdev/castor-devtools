<?php

declare(strict_types=1);

use Ogmdev\CastorDevtools\Ingress;
use Symfony\Component\Yaml\Yaml;

/**
 * @return array<string, mixed>
 */
function ingressCompose(): array
{
    return Yaml::parseFile(dirname(__DIR__, 2).'/ingress/compose.yaml', Yaml::PARSE_CUSTOM_TAGS);
}

test('the ingress lives in its own compose project on a shared external network', function (): void {
    $compose = ingressCompose();

    expect($compose['name'])->toBe(Ingress::ProjectName)
        ->and($compose['networks'][Ingress::Network])->toBe(['external' => true])
        ->and($compose['services'][Ingress::Service]['networks'])->toBe([Ingress::Network]);
});

test('traefik routes every container that opts in with the one label, and nothing else, on loopback ports', function (): void {
    $traefik = ingressCompose()['services'][Ingress::Service];

    expect($traefik['command'])->toContain(
        '--providers.docker.exposedbydefault=true',
        '--providers.docker.network='.Ingress::Network,
        '--providers.docker.constraints=Label(`'.Ingress::EnableLabel.'`, `true`)',
        '--entrypoints.websecure.http.tls=true',
    )
        ->and($traefik['ports'])->toBe([
            sprintf('127.0.0.1:%d:80', Ingress::HttpPort),
            sprintf('127.0.0.1:%d:443', Ingress::HttpsPort),
        ])
        ->and($traefik['labels'][Ingress::EnableLabel])->toBe('true');
});

test('certificates and dynamic files live in one data directory every project shares', function (): void {
    $ingress = new Ingress('traefik:v3.6', '/home/dev/.local/share/local-ingress/');

    expect($ingress->certificatesDirectory())->toBe('/home/dev/.local/share/local-ingress/certs')
        ->and($ingress->dynamicDirectory())->toBe('/home/dev/.local/share/local-ingress/dynamic')
        ->and($ingress->composeEnvironment())->toBe([
            'LOCAL_INGRESS_DATA_DIR' => '/home/dev/.local/share/local-ingress',
            'LOCAL_INGRESS_TRAEFIK_IMAGE' => 'traefik:v3.6',
        ])
        ->and(ingressCompose()['services'][Ingress::Service]['volumes'])->toContain(
            '${LOCAL_INGRESS_DATA_DIR:-${HOME}/.local/share/local-ingress}/dynamic:/etc/traefik/dynamic:ro',
            '${LOCAL_INGRESS_DATA_DIR:-${HOME}/.local/share/local-ingress}/certs:/etc/traefik/certs:ro',
        );
});

test('the data directory follows XDG_DATA_HOME, then HOME', function (): void {
    $dataHome = getenv('XDG_DATA_HOME');
    putenv('XDG_DATA_HOME=/srv/data');
    $fromXdg = Ingress::defaultDataDirectory();
    putenv('XDG_DATA_HOME');
    $fromHome = Ingress::defaultDataDirectory();
    putenv($dataHome === false ? 'XDG_DATA_HOME' : 'XDG_DATA_HOME='.$dataHome);

    expect($fromXdg)->toBe('/srv/data/local-ingress')
        ->and($fromHome)->toBe(rtrim((string) getenv('HOME'), '/').'/.local/share/local-ingress');
});

test('starting the ingress is idempotent: one compose project, a network created once', function (): void {
    $ingress = new Ingress(dataDirectory: '/data');
    $base = ['docker', 'compose', '--project-name', Ingress::ProjectName, '--file', $ingress->composeFile()];

    expect($ingress->buildUpCommand())->toBe([...$base, 'up', '-d', '--wait'])
        ->and($ingress->buildRunningCommand())->toBe([...$base, 'ps', '--quiet', '--status', 'running', Ingress::Service])
        ->and($ingress->buildNetworkInspectCommand())->toBe(['docker', 'network', 'inspect', Ingress::Network])
        ->and($ingress->buildNetworkCreateCommand())->toBe(['docker', 'network', 'create', Ingress::Network])
        ->and($ingress->composeFile())->toBeFile();
});
