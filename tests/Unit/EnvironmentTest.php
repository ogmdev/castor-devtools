<?php

declare(strict_types=1);

use Ogmdev\CastorDevtools\Assets;
use Ogmdev\CastorDevtools\Environment;
use Ogmdev\CastorDevtools\Ingress;

/**
 * @param  array<string, mixed>  $overrides
 */
function environment(array $overrides = [], ?string $dataDirectory = null): Environment
{
    return new Environment(sampleProject($overrides), new Ingress(dataDirectory: $dataDirectory ?? '/data'));
}

function environmentWithTls(bool $tls): Environment
{
    $directory = temporaryDirectory('castor-devtools-tls');
    $environment = environment(dataDirectory: $directory);
    $environment->prepareTlsDirectories();

    if ($tls) {
        foreach ([$environment->certificateFile(), $environment->keyFile(), $environment->tlsDynamicFile(), $environment->redirectFile()] as $file) {
            touch($file);
        }
    }

    return $environment;
}

test('urls target the shared ingress entrypoints', function (): void {
    expect(environment()->serviceUrl('app'))->toBe('http://app.acme.localhost:8000')
        ->and(environment()->httpsServiceUrl('www'))->toBe('https://www.acme.localhost:8443')
        ->and(environment(['subdomainPaths' => ['app' => '/health']])->urls(tls: true))->toBe([
            'app' => 'https://app.acme.localhost:8443/health',
            'www' => 'https://www.acme.localhost:8443',
            'vite' => 'https://vite.acme.localhost:8443',
        ]);
});

test('probes reach the vhost on the loopback with the host header a browser sends', function (): void {
    expect(environment()->buildHttpCheckCommand('app'))->toBe([
        'curl', '--silent', '--output', '/dev/null', '--write-out', '%{http_code}', '--max-time', '3',
        '--header', 'Host: app.acme.localhost:8000', 'http://127.0.0.1:8000',
    ])
        ->and(environment()->buildHttpsCheckCommand('www'))->toContain('--resolve', 'www.acme.localhost:8443:127.0.0.1', 'https://www.acme.localhost:8443');
});

test('certificates and dynamic files are named after the project domain', function (): void {
    $environment = environment();

    expect($environment->certificateFile())->toBe('/data/certs/acme.localhost.pem')
        ->and($environment->keyFile())->toBe('/data/certs/acme.localhost.key.pem')
        ->and($environment->tlsDynamicFile())->toBe('/data/dynamic/acme.localhost-tls.yaml')
        ->and($environment->redirectFile())->toBe('/data/dynamic/acme.localhost-redirect.yaml')
        ->and($environment->buildTlsDynamicConfig())->toBe(
            "tls:\n  certificates:\n    - certFile: /etc/traefik/certs/acme.localhost.pem\n      keyFile: /etc/traefik/certs/acme.localhost.key.pem\n",
        );
});

test('each project redirects only its own domain, so two projects never collide on the shared ingress', function (): void {
    $acme = environment()->buildHttpsRedirectConfig();
    $other = environment(['domain' => 'other.localhost'])->buildHttpsRedirectConfig();

    expect($acme)->toContain('https-redirect-acme-localhost:')
        ->and($acme)->toContain('rule: HostRegexp(`^(?:.+\.)?acme\.localhost$`)')
        ->and($acme)->toContain('entryPoints: [web]')
        ->and($acme)->toContain('service: noop@internal')
        ->and($acme)->toContain('port: "8443"')
        ->and($acme)->toContain('permanent: true')
        ->and($other)->toContain('https-redirect-other-localhost:')
        ->and($other)->not()->toContain('acme');
});

test('the redirect host pattern matches the project subdomains and nothing else', function (): void {
    preg_match('/HostRegexp\(`(.+)`\)/', environment()->buildHttpsRedirectConfig(), $rule);
    $pattern = '/'.($rule[1] ?? 'no rule').'/';

    expect(preg_match($pattern, 'app.acme.localhost'))->toBe(1)
        ->and(preg_match($pattern, 'acme.localhost'))->toBe(1)
        ->and(preg_match($pattern, 'app.notacme.localhost'))->toBe(0)
        ->and(preg_match($pattern, 'acme.localhost.evil'))->toBe(0);
});

test('tls counts as configured only when the four files exist', function (): void {
    expect(environmentWithTls(true)->isTlsConfigured())->toBeTrue()
        ->and(environmentWithTls(false)->isTlsConfigured())->toBeFalse();
});

test('the hosts line declares every subdomain and is appended on a line of its own', function (): void {
    $environment = environment();
    $line = '127.0.0.1 app.acme.localhost www.acme.localhost vite.acme.localhost';

    expect($environment->buildHostsLine())->toBe($line)
        ->and($environment->buildHostsAppendCommand("127.0.0.1 localhost\n"))
        ->toBe("printf '%s' ".escapeshellarg($line."\n").' | sudo tee -a /etc/hosts > /dev/null')
        ->and($environment->buildHostsAppendCommand('255.255.255.255 broadcasthost'))
        ->toBe("printf '%s' ".escapeshellarg("\n".$line."\n").' | sudo tee -a /etc/hosts > /dev/null');
});

test('hosts detection ignores comments and names that only contain a subdomain', function (): void {
    $hosts = "# 127.0.0.1 app.acme.localhost\n127.0.0.1 xwww.acme.localhost\n127.0.0.1\tvite.acme.localhost # dev server\n";

    expect(environment()->missingFromHostsFile($hosts))->toBe(['app.acme.localhost', 'www.acme.localhost']);
});

test('the vite dev server joins the shared network, opts in and is routed by its project name', function (): void {
    $directory = temporaryDirectory('castor-devtools-assets');
    $command = (new Assets($directory, environmentWithTls(true), sampleProject()))->buildDockerRunCommand('dev', userId: '1234');
    $image = array_search('node:26-alpine', $command, true);

    expect(array_slice($command, (int) $image - 12, 12))->toBe([
        '--network', 'local-ingress',
        '-e', 'VITE_DEV_SERVER_ORIGIN=https://vite.acme.localhost:8443',
        '--label', 'local-ingress.enable=true',
        '--label', 'traefik.http.routers.acme-vite.rule=Host(`vite.acme.localhost`)',
        '--label', 'traefik.http.routers.acme-vite.entrypoints=web,websecure',
        '--label', 'traefik.http.services.acme-vite.loadbalancer.server.port=5173',
    ])
        ->and($command)->not()->toContain('-p')
        ->and((new Assets($directory, environmentWithTls(true), sampleProject()))->buildDockerRunCommand('build'))->not()->toContain('--network');
});

test('without certificates the vite dev server is served over http like the pages', function (): void {
    expect(environmentWithTls(false)->viteDevServerOrigin())->toBe('http://vite.acme.localhost:8000')
        ->and(environmentWithTls(true)->viteDevServerOrigin())->toBe('https://vite.acme.localhost:8443')
        ->and(environment(['viteSubdomain' => null])->viteContainerOptions())->toBe([]);
});

test('mkcert install advice and command match the workstation platform', function (): void {
    $environment = environment();

    expect($environment->mkcertInstallAdvice(true, 'Darwin'))->toContain('apt install mkcert libnss3-tools')
        ->and($environment->mkcertInstallAdvice(false, 'Darwin'))->toContain('brew install mkcert')
        ->and($environment->mkcertInstallAdvice(false, 'Windows'))->toContain('github.com/FiloSottile/mkcert')
        ->and($environment->mkcertInstallCommand(false, 'Linux', hasApt: true, hasBrew: false))->toBe(['sudo', 'apt-get', 'install', '-y', 'mkcert', 'libnss3-tools'])
        ->and($environment->mkcertInstallCommand(false, 'Darwin', hasApt: false, hasBrew: true))->toBe(['brew', 'install', 'mkcert'])
        ->and($environment->mkcertInstallCommand(false, 'Darwin', hasApt: false, hasBrew: false))->toBeNull();
});

test('wsl detection and advices route the mkcert authority and the hosts to windows', function (): void {
    $environment = environment();

    expect($environment->detectsWsl('Linux version 5.15.167.4-microsoft-standard-WSL2'))->toBeTrue()
        ->and($environment->detectsWsl('Linux version 6.8.0-45-generic'))->toBeFalse()
        ->and($environment->windowsHostsAdvice())->toContain('C:\Windows\System32\drivers\etc\hosts')
        ->and($environment->wslForwardingAdvice())->toContain('wsl --shutdown')
        ->and($environment->wslCertificateTrustAdvice())->toContain('certutil.exe -addstore -f ROOT', 'mkcert -CAROOT')
        ->and($environment->buildWindowsUserStoreImportCommand('C:\rootCA.pem'))->toBe(['certutil.exe', '-user', '-addstore', '-f', 'Root', 'C:\rootCA.pem']);
});

test('the windows store is matched on the sha1 of the authority, not on its name', function (): void {
    $der = 'the certificate bytes';
    $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 16, "\n")."-----END CERTIFICATE-----\n";
    $listing = 'Cert Hash(sha1): '.strtoupper(implode(' ', str_split(sha1($der), 2)))."\n";

    expect(environment()->certificateAuthorityFingerprint($pem))->toBe(sha1($der))
        ->and(environment()->certificateAuthorityFingerprint('not a certificate'))->toBeNull()
        ->and(environment()->isWindowsCaTrusted($listing, sha1($der)))->toBeTrue()
        ->and(environment()->isWindowsCaTrusted("Issuer: CN=mkcert\n", sha1($der)))->toBeFalse();
});

test('the ownership repair hands the directories back to the current user', function (): void {
    expect(environment()->buildTlsDirectoryOwnershipCommand('501:20', ['/a', '/b']))->toBe(['sudo', 'chown', '-R', '501:20', '/a', '/b'])
        ->and(environment()->tlsDirectoryOwnershipAdvice(['/a', '/b']))->toContain('sudo chown -R "$(id -u):$(id -g)" /a /b');
});

test('the tls findings report missing certificates as blocking with the remedy', function (): void {
    [$missing] = environmentWithTls(false)->tlsFindings();
    [$present] = environmentWithTls(true)->tlsFindings();

    expect($missing->needsAction())->toBeTrue()
        ->and($missing->remedy)->toContain('./castor env:tls')
        ->and($present->needsAction())->toBeFalse();
});
