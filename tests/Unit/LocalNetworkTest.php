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
    return ['name' => $project.'-php-1', 'project' => $project, 'labels' => $labels, 'networks' => [Ingress::Network], 'health' => 'healthy'];
}

test('the workstation is named by its bonjour name, lowercased, and its address when the network gives one', function (): void {
    expect(LocalNetwork::bonjourName('Dev-Mac'))->toBe('dev-mac.local')
        ->and(LocalNetwork::bonjourName('dev-mac.local'))->toBe('dev-mac.local')
        ->and(localNetwork()->names())->toBe(['dev-mac.local', '192.168.1.20'])
        ->and(localNetwork(ipAddress: '')->names())->toBe(['dev-mac.local'])
        ->and(localNetwork()->urls(['dev-mac.local', '192.168.1.20'], 8443))->toBe(['https://dev-mac.local:8443', 'https://192.168.1.20:8443']);
});

test('the name and address come from scutil and ipconfig on macOS, from hostname elsewhere', function (): void {
    expect(localNetwork()->buildHostNameCommand())->toBe(['scutil', '--get', 'LocalHostName'])
        ->and(localNetwork()->buildIpAddressCommands())->toBe([['ipconfig', 'getifaddr', 'en0'], ['ipconfig', 'getifaddr', 'en1']])
        ->and(localNetwork(osFamily: 'Linux')->buildHostNameCommand())->toBe(['hostname', '-s'])
        ->and(localNetwork(osFamily: 'Linux')->buildIpAddressCommands())->toBe([['sh', '-c', 'hostname -I | cut -d " " -f 1']]);
});

test('the certificate lives with the ingress certificates, named after the domain, as pem files for traefik and a p12 without password for the symfony cli', function (): void {
    $network = localNetwork();

    expect($network->certificateFile())->toBe('/data/certs/acme.localhost-lan.pem')
        ->and($network->keyFile())->toBe('/data/certs/acme.localhost-lan.key.pem')
        ->and($network->p12File())->toBe('/data/certs/acme.localhost-lan.p12')
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

test('the traefik route forwards the workstation names on both entrypoints to the project service, with the certificate', function (): void {
    $config = Yaml::parse(localNetwork()->buildTraefikConfig('acme-app', ['dev-mac.local', '192.168.1.20']));

    expect($config['http']['routers']['acme-localhost-lan'])->toBe([
        'rule' => 'Host(`dev-mac.local`) || Host(`192.168.1.20`)',
        'entryPoints' => ['web', 'websecure'],
        'service' => 'acme-app@docker',
    ])
        ->and($config['tls']['certificates'])->toBe([[
            'certFile' => '/etc/traefik/certs/acme.localhost-lan.pem',
            'keyFile' => '/etc/traefik/certs/acme.localhost-lan.key.pem',
        ]]);
});

test('the routed service is the one the project labels on the router of its first subdomain, explicitly or by the router name', function (): void {
    $network = localNetwork();
    $explicit = routedContainer([
        'traefik.http.routers.acme-app.rule' => 'Host(`app.acme.localhost`)',
        'traefik.http.routers.acme-app.service' => 'acme-backend',
    ]);
    $byRouterName = routedContainer([
        'traefik.http.routers.acme-app.rule' => 'Host(`app.acme.localhost`)',
        'traefik.http.services.acme-app.loadbalancer.server.port' => '80',
    ]);
    $implicit = routedContainer(['traefik.http.routers.acme-app.rule' => 'Host(`app.acme.localhost`)']);
    $other = routedContainer(['traefik.http.routers.other-app.rule' => 'Host(`app.other.localhost`)', 'traefik.http.services.other-app.loadbalancer.server.port' => '80'], 'other');
    $www = routedContainer(['traefik.http.routers.acme-www.rule' => 'Host(`www.acme.localhost`)', 'traefik.http.services.acme-www.loadbalancer.server.port' => '80']);

    expect($network->routedService([$other, $explicit]))->toBe('acme-backend')
        ->and($network->routedService([$byRouterName]))->toBe('acme-app')
        ->and($network->routedService([$www, $byRouterName]))->toBe('acme-app')
        ->and($network->routedService([$www]))->toBe('acme-www')
        ->and($network->routedService([$implicit]))->toBeNull()
        ->and($network->routedService([$other]))->toBeNull()
        ->and($network->missingServiceAdvice())->toContain('app.acme.localhost', 'traefik.http.services.<router>.loadbalancer.server.port');
});

test('the check reads the served certificate through the address with the bonjour name as server name, and spots the names it misses', function (): void {
    $subjectAltNames = "issuer=O=mkcert development CA\nX509v3 Subject Alternative Name: \n    DNS:dev-mac.local, IP Address:192.168.1.20";

    expect(localNetwork()->buildCheckCommand('192.168.1.20', 8443, 'dev-mac.local'))->toBe(['sh', '-c', "openssl s_client -connect 192.168.1.20:8443 -servername 'dev-mac.local' </dev/null 2>/dev/null | openssl x509 -noout -issuer -ext subjectAltName"])
        ->and(LocalNetwork::namesMissingFrom($subjectAltNames, ['dev-mac.local', '192.168.1.20']))->toBe([])
        ->and(LocalNetwork::namesMissingFrom($subjectAltNames, ['dev-mac.local', '192.168.1.200']))->toBe(['192.168.1.200'])
        ->and(LocalNetwork::namesMissingFrom('DNS:dev-mac.localhost', ['dev-mac.local']))->toBe(['dev-mac.local']);
});
