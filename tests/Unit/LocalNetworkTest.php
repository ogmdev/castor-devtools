<?php

declare(strict_types=1);

use Ogmdev\CastorDevtools\Ingress;
use Ogmdev\CastorDevtools\LocalNetwork;
use Symfony\Component\Yaml\Yaml;

/**
 * @param  array<string, mixed>  $overrides
 */
function localNetwork(array $overrides = [], ?string $ipAddress = '192.168.1.20', string $osFamily = 'Darwin'): LocalNetwork
{
    return new LocalNetwork(sampleProject($overrides), new Ingress('traefik:v3.6', '/data'), hostName: 'dev-mac.local', ipAddress: $ipAddress, osFamily: $osFamily);
}

/**
 * @param  array<string, string>  $labels
 * @return array{name: string, project: string, labels: array<string, string>, networks: list<string>, health: string}
 */
function routedContainer(array $labels, string $project = 'acme'): array
{
    return ['name' => $project.'-php-1', 'project' => $project, 'labels' => [Ingress::EnableLabel => 'true', ...$labels], 'networks' => [Ingress::Network], 'health' => 'healthy'];
}

test('the workstation is named by its bonjour name, lowercased, and its address when the network gives one', function (): void {
    expect(LocalNetwork::bonjourName('Dev-Mac'))->toBe('dev-mac.local')
        ->and(LocalNetwork::bonjourName('dev-mac.local'))->toBe('dev-mac.local')
        ->and(localNetwork()->names())->toBe(['dev-mac.local', '192.168.1.20'])
        ->and(localNetwork(ipAddress: '')->names())->toBe(['dev-mac.local'])
        ->and(localNetwork()->urls(['dev-mac.local', '192.168.1.20'], 8443))->toBe(['https://dev-mac.local:8443', 'https://192.168.1.20:8443']);
});

test('the name comes from scutil on macOS and hostname elsewhere, the address from the interface of the default route first', function (): void {
    $macCommands = localNetwork()->buildIpAddressCommands();
    $linuxCommands = localNetwork(osFamily: 'Linux')->buildIpAddressCommands();

    expect(localNetwork()->buildHostNameCommand())->toBe(['scutil', '--get', 'LocalHostName'])
        ->and($macCommands[0][2])->toContain('route -n get default', 'ipconfig getifaddr')
        ->and(array_slice($macCommands, 1))->toBe([['ipconfig', 'getifaddr', 'en0'], ['ipconfig', 'getifaddr', 'en1']])
        ->and(localNetwork(osFamily: 'Linux')->buildHostNameCommand())->toBe(['hostname', '-s'])
        ->and($linuxCommands[0][2])->toContain('ip -4 route get', 'src')
        ->and($linuxCommands[1])->toBe(['sh', '-c', 'hostname -I | cut -d " " -f 1']);
});

test('the certificate lives with the ingress certificates, named after the domain, as pem files for traefik and a p12 without password for the symfony cli', function (): void {
    $network = localNetwork();

    expect($network->certificateFile())->toBe('/data/certs/acme.localhost-lan.pem')
        ->and($network->keyFile())->toBe('/data/certs/acme.localhost-lan.key.pem')
        ->and($network->p12File())->toBe('/data/certs/acme.localhost-lan.p12')
        ->and($network->symfonyPortFile())->toBe('/data/certs/acme.localhost-lan.port')
        ->and($network->dynamicFile())->toBe('/data/dynamic/acme.localhost-lan.yaml')
        ->and($network->buildCertificateCommands(['dev-mac.local', '192.168.1.20']))->toBe([
            ['mkcert', '-cert-file', '/data/certs/acme.localhost-lan.pem', '-key-file', '/data/certs/acme.localhost-lan.key.pem', 'dev-mac.local', '192.168.1.20'],
            ['openssl', 'pkcs12', '-export', '-in', '/data/certs/acme.localhost-lan.pem', '-inkey', '/data/certs/acme.localhost-lan.key.pem', '-out', '/data/certs/acme.localhost-lan.p12', '-passout', 'pass:'],
        ]);
});

test('the symfony cli server listens on every interface, in the background, with the p12 certificate', function (): void {
    expect(localNetwork()->buildSymfonyServerCommand())->toBe(['symfony', 'server:start', '--daemon', '--listen-ip=0.0.0.0', '--port=8001', '--p12=/data/certs/acme.localhost-lan.p12'])
        ->and(localNetwork()->buildSymfonyServerCommand(9001))->toContain('--port=9001');
});

test('the symfony cli server started for the local network is told apart by the port remembered on start, since the cli reports 127.0.0.1 whatever the listen address', function (): void {
    $dataDirectory = sys_get_temp_dir().'/castor-devtools-'.uniqid();
    mkdir($dataDirectory.'/certs', 0755, true);
    $network = new LocalNetwork(sampleProject(), new Ingress('traefik:v3.6', $dataDirectory), hostName: 'dev-mac.local', ipAddress: '192.168.1.20');
    $status = 'Listening on https://127.0.0.1:8001';

    try {
        expect(LocalNetwork::listeningPort($status))->toBe(8001)
            ->and(LocalNetwork::listeningPort('Not Running'))->toBeNull()
            ->and($network->runningSymfonyServerPort($status))->toBeNull();

        file_put_contents($network->symfonyPortFile(), "8001\n");

        expect($network->runningSymfonyServerPort($status))->toBe(8001)
            ->and($network->runningSymfonyServerPort('Listening on https://127.0.0.1:8000'))->toBeNull()
            ->and($network->runningSymfonyServerPort('Not Running'))->toBeNull();
    } finally {
        @unlink($network->symfonyPortFile());
        @rmdir($dataDirectory.'/certs');
        @rmdir($dataDirectory);
    }
});

test('the traefik route forwards the workstation names on both entrypoints to the project service, with the certificate', function (): void {
    $config = Yaml::parse(localNetwork()->buildTraefikConfig('acme-app', ['dev-mac.local', '192.168.1.20']));
    $elsewhere = Yaml::parse(localNetwork()->buildTraefikConfig('acme-app@file', ['dev-mac.local']));

    expect($config['http']['routers']['acme-localhost-lan'])->toBe([
        'rule' => 'Host(`dev-mac.local`) || Host(`192.168.1.20`)',
        'entryPoints' => ['web', 'websecure'],
        'service' => 'acme-app@docker',
    ])
        ->and($config['tls']['certificates'])->toBe([[
            'certFile' => '/etc/traefik/certs/acme.localhost-lan.pem',
            'keyFile' => '/etc/traefik/certs/acme.localhost-lan.key.pem',
        ]])
        ->and($elsewhere['http']['routers']['acme-localhost-lan']['service'])->toBe('acme-app@file');
});

test('the routed service is the one the project labels on a router of its first subdomain, explicitly or by the router name, on a container the ingress routes', function (): void {
    $network = localNetwork();
    $explicit = routedContainer([
        'traefik.http.routers.acme-app.rule' => 'Host(`app.acme.localhost`)',
        'traefik.http.routers.acme-app.service' => 'acme-backend',
    ]);
    $suffixed = routedContainer([
        'traefik.http.routers.acme-app.rule' => 'Host(`app.acme.localhost`)',
        'traefik.http.routers.acme-app.service' => 'acme-backend@docker',
    ]);
    $byRouterName = routedContainer([
        'traefik.http.routers.acme-app.rule' => 'Host(`app.acme.localhost`)',
        'traefik.http.services.acme-app.loadbalancer.server.port' => '80',
    ]);
    $withRedirect = routedContainer([
        'traefik.http.routers.acme-app-http.rule' => 'Host(`app.acme.localhost`)',
        'traefik.http.routers.acme-app-http.service' => 'noop@internal',
        'traefik.http.routers.acme-app-plain.rule' => 'Host(`app.acme.localhost`)',
        'traefik.http.routers.acme-app.rule' => 'Host(`app.acme.localhost`)',
        'traefik.http.services.acme-app.loadbalancer.server.port' => '80',
    ]);
    $implicit = routedContainer(['traefik.http.routers.acme-app.rule' => 'Host(`app.acme.localhost`)']);
    $other = routedContainer(['traefik.http.routers.other-app.rule' => 'Host(`app.other.localhost`)', 'traefik.http.services.other-app.loadbalancer.server.port' => '80'], 'other');
    $www = routedContainer(['traefik.http.routers.acme-www.rule' => 'Host(`www.acme.localhost`)', 'traefik.http.services.acme-www.loadbalancer.server.port' => '80']);
    $unrouted = [...$byRouterName, 'networks' => ['acme_default']];
    $ingress = [...$byRouterName, 'project' => Ingress::ProjectName];

    expect($network->routedService([$other, $explicit]))->toBe('acme-backend')
        ->and($network->routedService([$suffixed]))->toBe('acme-backend')
        ->and($network->routedService([$byRouterName]))->toBe('acme-app')
        ->and($network->routedService([$withRedirect]))->toBe('acme-app')
        ->and($network->routedService([$implicit, $byRouterName]))->toBe('acme-app')
        ->and($network->routedService([$www, $byRouterName]))->toBe('acme-app')
        ->and($network->routedService([$www]))->toBe('acme-www')
        ->and($network->routedService([$implicit]))->toBeNull()
        ->and($network->routedService([$other]))->toBeNull()
        ->and($network->routedService([$unrouted, $ingress]))->toBeNull()
        ->and($network->missingServiceAdvice())->toContain('app.acme.localhost', 'traefik.http.services.<router>.loadbalancer.server.port', Ingress::EnableLabel);
});

test('the check reads the served certificate as text through the address with the bonjour name as server name, sums it up, and spots the names it misses', function (): void {
    $certificate = "issuer=O=mkcert development CA\nCertificate:\n    Data:\n        X509v3 extensions:\n            X509v3 Subject Alternative Name: \n                DNS:dev-mac.local, IP Address:192.168.1.20\n            X509v3 Basic Constraints:";

    expect(localNetwork()->buildCheckCommand('192.168.1.20', 8443, 'dev-mac.local'))->toBe(['sh', '-c', "openssl s_client -connect 192.168.1.20:8443 -servername 'dev-mac.local' </dev/null 2>/dev/null | openssl x509 -noout -issuer -text"])
        ->and(LocalNetwork::certificateSummary($certificate))->toBe("issuer=O=mkcert development CA\nSubject Alternative Name: DNS:dev-mac.local, IP Address:192.168.1.20")
        ->and(LocalNetwork::namesMissingFrom($certificate, ['dev-mac.local', '192.168.1.20']))->toBe([])
        ->and(LocalNetwork::namesMissingFrom($certificate, ['dev-mac.local', '192.168.1.200']))->toBe(['192.168.1.200'])
        ->and(LocalNetwork::namesMissingFrom('DNS:dev-mac.localhost', ['dev-mac.local']))->toBe(['dev-mac.local']);
});
