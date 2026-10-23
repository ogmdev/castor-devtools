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

/**
 * @param  array<string, string|null>  $variables
 */
function withEnvironment(array $variables, Closure $callback): mixed
{
    $previous = [];

    foreach ($variables as $name => $value) {
        $previous[$name] = getenv($name);
        putenv($value === null ? $name : $name.'='.$value);
    }

    try {
        return $callback();
    } finally {
        foreach ($previous as $name => $value) {
            putenv($value === false ? $name : $name.'='.$value);
        }
    }
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

test('forwarded headers are only trusted when the workstation asks for it', function (): void {
    expect(ingressCompose()['services'][Ingress::Service]['command'])
        ->toContain('--entrypoints.web.forwardedheaders.insecure=${LOCAL_INGRESS_TRUST_FORWARDED_HEADERS:-false}');
});

test('the docker socket can be moved for rootless docker or podman', function (): void {
    expect(ingressCompose()['services'][Ingress::Service]['volumes'])
        ->toContain('${LOCAL_INGRESS_DOCKER_SOCKET:-/var/run/docker.sock}:/var/run/docker.sock:ro');
});

test('the compose file labels the running ingress with the package version and the data directory the code expects', function (): void {
    $traefik = ingressCompose()['services'][Ingress::Service];
    $dataDirectory = '${LOCAL_INGRESS_DATA_DIR:-${XDG_DATA_HOME:-${HOME}/.local/share}/local-ingress}';

    expect($traefik['labels'][Ingress::VersionLabel])->toBe(Ingress::Version)
        ->and($traefik['labels'][Ingress::DataDirectoryLabel])->toBe($dataDirectory)
        ->and($traefik['volumes'])->toContain(
            $dataDirectory.'/dynamic:/etc/traefik/dynamic:ro',
            $dataDirectory.'/certs:/etc/traefik/certs:ro',
        )
        ->and($traefik['image'])->toBe('${LOCAL_INGRESS_TRAEFIK_IMAGE:-'.Ingress::DefaultImage.'}');
});

test('certificates and dynamic files live in one data directory every project shares', function (): void {
    $ingress = new Ingress('traefik:v3.6', '/home/dev/.local/share/local-ingress/');

    expect($ingress->certificatesDirectory())->toBe('/home/dev/.local/share/local-ingress/certs')
        ->and($ingress->dynamicDirectory())->toBe('/home/dev/.local/share/local-ingress/dynamic')
        ->and($ingress->composeEnvironment())->toBe([
            'LOCAL_INGRESS_DATA_DIR' => '/home/dev/.local/share/local-ingress',
            'LOCAL_INGRESS_TRAEFIK_IMAGE' => 'traefik:v3.6',
        ]);
});

test('the data directory follows LOCAL_INGRESS_DATA_DIR, then an absolute XDG_DATA_HOME, then HOME', function (): void {
    $home = rtrim((string) getenv('HOME'), '/');

    expect(withEnvironment(['LOCAL_INGRESS_DATA_DIR' => '/srv/ingress/', 'XDG_DATA_HOME' => '/srv/data'], Ingress::defaultDataDirectory(...)))->toBe('/srv/ingress')
        ->and(withEnvironment(['LOCAL_INGRESS_DATA_DIR' => null, 'XDG_DATA_HOME' => '/srv/data'], Ingress::defaultDataDirectory(...)))->toBe('/srv/data/local-ingress')
        ->and(withEnvironment(['LOCAL_INGRESS_DATA_DIR' => null, 'XDG_DATA_HOME' => 'relative/data'], Ingress::defaultDataDirectory(...)))->toBe($home.'/.local/share/local-ingress')
        ->and(withEnvironment(['LOCAL_INGRESS_DATA_DIR' => null, 'XDG_DATA_HOME' => null], Ingress::defaultDataDirectory(...)))->toBe($home.'/.local/share/local-ingress');
});

test('the traefik image comes from the workstation, never from a project', function (): void {
    expect(withEnvironment(['LOCAL_INGRESS_TRAEFIK_IMAGE' => 'mirror.example/traefik:v3.6'], fn (): string => (new Ingress(dataDirectory: '/data'))->traefikImage()))->toBe('mirror.example/traefik:v3.6')
        ->and(withEnvironment(['LOCAL_INGRESS_TRAEFIK_IMAGE' => null], fn (): string => (new Ingress(dataDirectory: '/data'))->traefikImage()))->toBe(Ingress::DefaultImage);
});

test('a running ingress of the same version or newer is kept, an older or unversioned one is recreated', function (): void {
    $ingress = new Ingress(dataDirectory: '/data');

    expect($ingress->isUpToDate(Ingress::Version))->toBeTrue()
        ->and($ingress->isUpToDate('9.0.0'))->toBeTrue()
        ->and($ingress->isUpToDate('0.1.1'))->toBeFalse()
        ->and($ingress->isUpToDate(''))->toBeFalse()
        ->and($ingress->upgradeMessage(''))->toContain('an unversioned release', Ingress::Version)
        ->and($ingress->upgradeMessage('0.1.1'))->toContain('version 0.1.1');
});

test('a kept ingress is only reported when it is newer than this project or reads another data directory', function (): void {
    $ingress = new Ingress(dataDirectory: '/data');

    expect($ingress->parseRunningIngress(''))->toBeNull()
        ->and($ingress->parseRunningIngress(Ingress::Version."\t/data\n"))->toBe(['version' => Ingress::Version, 'dataDirectory' => '/data'])
        ->and($ingress->runningIngressWarnings(Ingress::Version, '/data'))->toBe([])
        ->and($ingress->runningIngressWarnings('9.0.0', '/data')[0])->toContain('version 9.0.0', 'Update ogmdev/castor-devtools in this project')
        ->and($ingress->runningIngressWarnings(Ingress::Version, '/elsewhere')[0])->toContain('/elsewhere', '/data', 'LOCAL_INGRESS_DATA_DIR');
});

test('a port held by another container names that container, its compose project and the command that frees it', function (): void {
    $ingress = new Ingress(dataDirectory: '/data');
    $holders = $ingress->parsePortHolders("acme-traefik-1\tacme\ttraefik\nlocal-ingress-traefik-1\tlocal-ingress\ttraefik\nstray\t\t\n");
    $message = (string) $ingress->portConflictMessage([8000 => $holders, 8443 => []]);

    expect($holders)->toBe([
        ['name' => 'acme-traefik-1', 'project' => 'acme', 'service' => 'traefik'],
        ['name' => 'stray', 'project' => '', 'service' => ''],
    ])
        ->and($message)->toContain('port 8000 is published by the container acme-traefik-1 of the compose project acme: docker compose --project-name acme stop traefik')
        ->and($message)->toContain('port 8000 is published by the container stray: docker stop stray')
        ->and($ingress->portConflictMessage([8000 => [], 8443 => []]))->toBeNull()
        ->and($ingress->buildPortHoldersCommand(8443))->toContain('publish=8443');
});

test('the ingress commands target one compose project and find it by its compose labels', function (): void {
    $ingress = new Ingress(dataDirectory: '/data');
    $base = ['docker', 'compose', '--project-name', Ingress::ProjectName, '--file', $ingress->composeFile()];

    expect($ingress->buildUpCommand())->toBe([...$base, 'up', '-d', '--wait'])
        ->and($ingress->buildRestartCommand())->toBe([...$base, 'up', '-d', '--wait', '--force-recreate'])
        ->and($ingress->buildRunningCommand())->toContain('label=com.docker.compose.project='.Ingress::ProjectName, 'label=com.docker.compose.service='.Ingress::Service)
        ->and($ingress->buildNetworkInspectCommand())->toBe(['docker', 'network', 'inspect', Ingress::Network])
        ->and($ingress->buildNetworkCreateCommand())->toBe(['docker', 'network', 'create', Ingress::Network])
        ->and($ingress->composeFile())->toBeFile();
});

test('a port already taken is explained instead of the raw compose error', function (): void {
    $ingress = new Ingress(dataDirectory: '/data');

    expect($ingress->failureMessage(1, 'Bind for 127.0.0.1:8000 failed: port is already allocated'))->toContain('another process', '8000', '8443')
        ->and($ingress->failureMessage(17, 'something else'))->toContain('exit code 17', 'logs traefik');
});
