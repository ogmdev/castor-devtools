<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use RuntimeException;

use function Castor\context;
use function Castor\io;
use function Castor\run;

/**
 * Serves the project over https to the other devices of the local network (a phone, a tablet, another machine),
 * through the Symfony CLI server or the shared ingress, with a mkcert certificate for the workstation's Bonjour name and address.
 */
final readonly class LocalNetwork
{
    public const string SymfonyCli = 'symfony';

    public const string Traefik = 'traefik';

    public const int SymfonyCliPort = 8001;

    private const string LanSuffix = '-lan';

    private Project $project;

    private Ingress $ingress;

    public function __construct(
        ?Project $project = null,
        ?Ingress $ingress = null,
        private IngressRouting $routing = new IngressRouting,
        private ?string $hostName = null,
        private ?string $ipAddress = null,
        private string $osFamily = PHP_OS_FAMILY,
    ) {
        $this->project = $project ?? CurrentProject::get();
        $this->ingress = $ingress ?? new Ingress;
    }

    /**
     * The Bonjour name of the workstation, which phones and other machines resolve on the local network without any DNS.
     */
    public function hostName(): string
    {
        if ($this->hostName !== null) {
            return $this->hostName;
        }

        $name = $this->probe($this->buildHostNameCommand());

        if ($name === '') {
            throw new RuntimeException(sprintf('Unable to read the name of this workstation on the local network: "%s" printed nothing.', implode(' ', $this->buildHostNameCommand())));
        }

        return self::bonjourName($name);
    }

    public static function bonjourName(string $localHostName): string
    {
        $name = strtolower(trim($localHostName));

        return str_ends_with($name, '.local') ? $name : $name.'.local';
    }

    public function ipAddress(): ?string
    {
        if ($this->ipAddress !== null) {
            return $this->ipAddress === '' ? null : $this->ipAddress;
        }

        foreach ($this->buildIpAddressCommands() as $command) {
            $address = $this->probe($command);

            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $address;
            }
        }

        return null;
    }

    /**
     * The names the certificate covers and the URLs use: the Bonjour name first, then the address when it is known.
     *
     * @return non-empty-list<string>
     */
    public function names(): array
    {
        $address = $this->ipAddress();

        return $address === null ? [$this->hostName()] : [$this->hostName(), $address];
    }

    /**
     * @return list<string>
     */
    public function buildHostNameCommand(): array
    {
        return $this->osFamily === 'Darwin' ? ['scutil', '--get', 'LocalHostName'] : ['hostname', '-s'];
    }

    /**
     * The address of the interface the default route leaves by, the one the other devices reach; then the usual interfaces, in case there is no default route.
     *
     * @return list<list<string>>
     */
    public function buildIpAddressCommands(): array
    {
        if ($this->osFamily === 'Darwin') {
            return [
                ['sh', '-c', 'ipconfig getifaddr "$(route -n get default 2>/dev/null | awk \'/interface:/ { print $2 }\')"'],
                ['ipconfig', 'getifaddr', 'en0'],
                ['ipconfig', 'getifaddr', 'en1'],
            ];
        }

        return [
            ['sh', '-c', 'ip -4 route get 1.1.1.1 2>/dev/null | awk \'{ for (i = 1; i <= NF; i++) if ($i == "src") print $(i + 1) }\''],
            ['sh', '-c', 'hostname -I | cut -d " " -f 1'],
        ];
    }

    public function certificateFile(): string
    {
        return $this->ingress->certificatesDirectory().'/'.$this->project->domain.self::LanSuffix.'.pem';
    }

    public function keyFile(): string
    {
        return $this->ingress->certificatesDirectory().'/'.$this->project->domain.self::LanSuffix.'.key.pem';
    }

    public function p12File(): string
    {
        return $this->ingress->certificatesDirectory().'/'.$this->project->domain.self::LanSuffix.'.p12';
    }

    /**
     * Remembers the port of the Symfony CLI server started for the local network: the CLI reports neither the listen address nor who started it.
     */
    public function symfonyPortFile(): string
    {
        return $this->ingress->certificatesDirectory().'/'.$this->project->domain.self::LanSuffix.'.port';
    }

    public function dynamicFile(): string
    {
        return $this->ingress->dynamicDirectory().'/'.$this->project->domain.self::LanSuffix.'.yaml';
    }

    /**
     * The pem files for Traefik, then the same certificate as a p12 without password, the only form the Symfony CLI reads.
     *
     * @param  non-empty-list<string>  $names
     * @return list<list<string>>
     */
    public function buildCertificateCommands(array $names): array
    {
        return [
            ['mkcert', '-cert-file', $this->certificateFile(), '-key-file', $this->keyFile(), ...$names],
            ['openssl', 'pkcs12', '-export', '-in', $this->certificateFile(), '-inkey', $this->keyFile(), '-out', $this->p12File(), '-passout', 'pass:'],
        ];
    }

    /**
     * Issues a fresh certificate on every start: the address changes with the network, the mkcert authority does not.
     *
     * @return non-empty-list<string>
     */
    public function issueCertificate(): array
    {
        $this->ensureBinary('mkcert', sprintf('mkcert is missing: run "%s" first, it installs mkcert and its authority.', $this->project->task($this->project->tasks->tls)));
        $this->ensureBinary('openssl', 'openssl is missing: it converts the certificate to the p12 the Symfony CLI reads.');
        $this->ingress->ensureDataDirectories();

        $names = $this->names();

        foreach ($this->buildCertificateCommands($names) as $command) {
            run($command, context()->withQuiet());
        }

        // mkcert protects the key; openssl leaves the unprotected p12 to the umask.
        if (! @chmod($this->p12File(), 0600)) {
            throw new RuntimeException(sprintf('Unable to protect %s: the p12 holds the private key without password.', $this->p12File()));
        }

        io()->writeln(sprintf('Certificate issued for %s.', implode(' and ', $names)));

        return $names;
    }

    /**
     * @return list<string>
     */
    public function buildSymfonyServerCommand(int $port = self::SymfonyCliPort): array
    {
        return ['symfony', 'server:start', '--daemon', '--listen-ip='.Ingress::EveryInterface, '--port='.$port, '--p12='.$this->p12File()];
    }

    public function startSymfonyServer(int $port = self::SymfonyCliPort): void
    {
        $this->ensureBinary('symfony', 'The Symfony CLI is missing: install it (https://symfony.com/download), or serve through the ingress instead.');

        $runningPort = $this->runningSymfonyServerPort($this->symfonyServerStatus());

        if ($runningPort !== null) {
            io()->note(sprintf('Stopping the Symfony CLI server started for the local network on port %d, to serve the fresh certificate.', $runningPort));
            $this->stopSymfonyServer();
        }

        $names = $this->issueCertificate();
        run($this->buildSymfonyServerCommand($port));
        Shell::writeFile($this->symfonyPortFile(), $port.PHP_EOL);

        $this->showAccess('The Symfony CLI server', $names, $port);
    }

    /**
     * The Traefik service, declared by the project's own labels, that the local network route forwards to.
     *
     * @param  list<array{name: string, project: string, labels: array<string, string>, networks: list<string>, health: string}>  $containers
     */
    public function routedService(array $containers): ?string
    {
        foreach ($this->project->subdomains as $subdomain) {
            $service = $this->routing->routedService($containers, $subdomain.'.'.$this->project->domain);

            if ($service !== null) {
                return $service;
            }
        }

        return null;
    }

    public function missingServiceAdvice(): string
    {
        return sprintf(
            'No container the ingress routes serves %s with a named Traefik service: label the service with traefik.http.services.<router>.loadbalancer.server.port (or traefik.http.routers.<router>.service), with %s=true and the %s network, and start the stack.',
            $this->project->subdomains[0].'.'.$this->project->domain,
            Ingress::EnableLabel,
            Ingress::Network,
        );
    }

    /**
     * The route and certificate Traefik reads from its dynamic directory: the names on the local network entrypoint alone, forwarded to the project's own service.
     *
     * @param  non-empty-list<string>  $names
     */
    public function buildTraefikConfig(string $service, array $names): string
    {
        $router = $this->slug().self::LanSuffix;
        $rule = implode(' || ', array_map(static fn (string $name): string => 'Host(`'.$name.'`)', $names));
        $reference = str_contains($service, '@') ? $service : $service.'@docker';

        return "http:\n"
            ."  routers:\n"
            ."    {$router}:\n"
            ."      rule: {$rule}\n"
            .'      entryPoints: ['.Ingress::LanEntryPoint."]\n"
            ."      service: {$reference}\n"
            ."tls:\n"
            ."  certificates:\n"
            .'    - certFile: /etc/traefik/certs/'.basename($this->certificateFile())."\n"
            .'      keyFile: /etc/traefik/certs/'.basename($this->keyFile())."\n";
    }

    public function startTraefik(): void
    {
        if (! $this->ingress->isRunning()) {
            throw new RuntimeException(sprintf('The local ingress is stopped: start the stack with "%s" first.', $this->project->task($this->project->tasks->start)));
        }

        $service = $this->routedService($this->routing->runningContainers()) ?? throw new RuntimeException($this->missingServiceAdvice());
        $names = $this->issueCertificate();

        // The ingress is recreated before the route is written: a recreate that fails leaves nothing behind.
        if (! $this->ingress->runsOpenToLocalNetwork()) {
            io()->note(sprintf('Recreating the local ingress so it publishes port %d on every network interface: the vhosts of every project stay on %s.', Ingress::LanHttpsPort, $this->ingress->listenIp()));
            $this->ingress->openToLocalNetwork()->restart();
        }

        Shell::writeFile($this->dynamicFile(), $this->buildTraefikConfig($service, $names));

        $this->showAccess('The local ingress', $names, Ingress::LanHttpsPort);
        io()->writeln('Through the ingress, use the name rather than the address: without a server name, Traefik answers with its default certificate.');
    }

    public function stop(): void
    {
        $stopped = [];

        if ($this->runningSymfonyServerPort($this->symfonyServerStatus()) !== null) {
            $this->stopSymfonyServer();
            $stopped[] = 'the Symfony CLI server is stopped';
        } elseif (is_file($this->symfonyPortFile())) {
            $this->removeFile($this->symfonyPortFile());
        }

        if (is_file($this->dynamicFile())) {
            $this->removeFile($this->dynamicFile());
            $stopped[] = 'the ingress route is removed';
        }

        if ($this->ingress->runsOpenToLocalNetwork() || $this->ingressListensBeyondItsAddress()) {
            $others = $this->otherLanRoutes();

            if ($others === []) {
                $this->ingress->restart();
                $stopped[] = sprintf('the ingress only listens on %s', $this->ingress->listenIp());
            } else {
                io()->note(sprintf('The ingress stays open to the local network: %s still served there.', implode(', ', $others)));
            }
        }

        if ($stopped === []) {
            io()->writeln(sprintf('%s was not served on the local network.', $this->project->name));

            return;
        }

        io()->success(sprintf('%s is no longer reachable from the local network: %s.', $this->project->name, implode(', ', $stopped)));
    }

    public function showStatus(): void
    {
        io()->section('Local network');

        try {
            $names = $this->names();
        } catch (RuntimeException $exception) {
            io()->warning($exception->getMessage());
            $names = [];
        }

        $listenIp = $this->ingress->runningListenIp();
        $open = $this->ingress->runsOpenToLocalNetwork();
        $symfonyStatus = Shell::hasBinary('symfony') ? $this->symfonyServerStatus() : null;
        $symfonyPort = $symfonyStatus === null ? null : $this->runningSymfonyServerPort($symfonyStatus);

        io()->writeln(sprintf('  %-14s %s', 'Workstation', $names === [] ? 'unknown' : implode(', ', $names)));
        io()->writeln(sprintf('  %-14s %s', 'Certificate', is_file($this->p12File()) ? $this->p12File() : 'none yet, issued on start'));
        io()->writeln(sprintf('  %-14s %s', 'Ingress', match (true) {
            $listenIp === null => 'stopped',
            $open && is_file($this->dynamicFile()) => sprintf('serving %s on the local network, https://%s:%d', $this->project->name, $names[0] ?? Ingress::EveryInterface, Ingress::LanHttpsPort),
            $open => sprintf('open to the local network on port %d, without a route for %s', Ingress::LanHttpsPort, $this->project->name),
            $listenIp === Ingress::Loopback || $listenIp === '' => 'loopback only',
            default => sprintf('listening on %s with the vhosts of every project', $listenIp),
        }));
        io()->writeln(sprintf('  %-14s %s', 'Symfony CLI', match (true) {
            $symfonyStatus === null => 'not installed',
            $symfonyPort !== null => sprintf('serving %s on the local network, https://%s:%d', $this->project->name, $names[0] ?? Ingress::EveryInterface, $symfonyPort),
            $symfonyStatus !== '' => 'running for this workstation only: '.$symfonyStatus,
            default => 'stopped',
        }));
        io()->writeln($this->rootCertificateAdvice());
    }

    /**
     * Reads the certificate as text: `x509 -ext` would be shorter, but LibreSSL, the openssl of macOS, does not have it.
     *
     * @return list<string>
     */
    public function buildCheckCommand(string $address, int $port, string $serverName): array
    {
        return ['sh', '-c', sprintf(
            'openssl s_client -connect %s:%d -servername %s </dev/null 2>/dev/null | openssl x509 -noout -issuer -text',
            $address,
            $port,
            escapeshellarg($serverName),
        )];
    }

    /**
     * Checks that the certificate served on the port covers the workstation's names, through the address when it is known.
     */
    public function check(int $port): int
    {
        $this->ensureBinary('openssl', 'openssl is missing: it reads the certificate the server sends.');

        $names = $this->names();
        $address = $names[1] ?? Ingress::Loopback;
        $process = run($this->buildCheckCommand($address, $port, $names[0]), context()->withAllowFailure()->withQuiet());
        $output = trim($process->getOutput());

        if (($process->getExitCode() ?? 1) !== 0 || $output === '') {
            $error = trim($process->getErrorOutput());
            io()->error(sprintf('Nothing answers over TLS on %s:%d: start the local network access first.%s', $address, $port, $error === '' ? '' : PHP_EOL.$error));

            return 1;
        }

        io()->writeln(self::certificateSummary($output));

        $missing = self::namesMissingFrom($output, $names);

        if ($missing !== []) {
            io()->error(sprintf('The certificate served on port %d does not cover %s: start again, a fresh certificate is issued each time.', $port, implode(', ', $missing)));

            return 1;
        }

        io()->success(sprintf('The certificate served on port %d covers %s.', $port, implode(' and ', $names)));

        return 0;
    }

    /**
     * The issuer and the subject alternative names, out of the certificate text.
     */
    public static function certificateSummary(string $certificateText): string
    {
        $lines = array_map(trim(...), explode("\n", $certificateText));
        $summary = [];

        foreach ($lines as $index => $line) {
            if (str_starts_with($line, 'issuer=')) {
                $summary[] = $line;
            }

            if (str_contains($line, 'Subject Alternative Name') && isset($lines[$index + 1])) {
                $summary[] = 'Subject Alternative Name: '.$lines[$index + 1];
            }
        }

        return implode(PHP_EOL, $summary);
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function namesMissingFrom(string $subjectAltNames, array $names): array
    {
        return array_values(array_filter(
            $names,
            static fn (string $name): bool => preg_match('/(?:DNS|IP Address):'.preg_quote($name, '/').'(?=,|\s|$)/', $subjectAltNames) !== 1,
        ));
    }

    /**
     * The port of the Symfony CLI server started for the local network, when the server the CLI reports is that one.
     */
    public function runningSymfonyServerPort(string $status): ?int
    {
        if (! is_file($this->symfonyPortFile())) {
            return null;
        }

        $port = (int) trim((string) file_get_contents($this->symfonyPortFile()));

        return $port > 0 && self::listeningPort($status) === $port ? $port : null;
    }

    public static function listeningPort(string $status): ?int
    {
        return preg_match('/Listening on \S+:(\d+)/', $status, $matches) === 1 ? (int) $matches[1] : null;
    }

    /**
     * @param  non-empty-list<string>  $names
     * @return list<string>
     */
    public function urls(array $names, int $port): array
    {
        return array_map(static fn (string $name): string => sprintf('https://%s:%d', $name, $port), $names);
    }

    public function rootCertificateAdvice(): string
    {
        $caRoot = Shell::hasBinary('mkcert') ? $this->probe(['mkcert', '-CAROOT']) : '';

        return sprintf(
            'Each device needs the mkcert authority: copy %s to it and trust it in its settings. One authority signs the vhosts of every project.',
            $caRoot === '' ? 'rootCA.pem from "mkcert -CAROOT"' : $caRoot.'/rootCA.pem',
        );
    }

    /**
     * @param  non-empty-list<string>  $names
     */
    private function showAccess(string $server, array $names, int $port): void
    {
        io()->success(sprintf('%s serves %s on the local network.', $server, $this->project->name));

        foreach ($this->urls($names, $port) as $url) {
            io()->writeln('  '.$url);
        }

        io()->writeln($this->rootCertificateAdvice());
        io()->warning('Every device on the local network reaches it: stop it once done.');
    }

    private function stopSymfonyServer(): void
    {
        run(['symfony', 'server:stop'], context()->withQuiet());
        $this->removeFile($this->symfonyPortFile());
    }

    /**
     * The "Listening on" line of the Symfony CLI status, empty when it reports no server.
     */
    private function symfonyServerStatus(): string
    {
        if (! Shell::hasBinary('symfony')) {
            return '';
        }

        return preg_match('/Listening on \S+/', $this->probe(['symfony', 'server:status', '--no-ansi']), $matches) === 1 ? $matches[0] : '';
    }

    /**
     * An ingress recreated by a release that opened the ports of every project to the local network, instead of a port of its own.
     */
    private function ingressListensBeyondItsAddress(): bool
    {
        return ! in_array($this->ingress->runningListenIp(), [null, '', $this->ingress->listenIp()], true);
    }

    /**
     * The routes other projects keep in the shared dynamic directory while served on the local network.
     *
     * @return list<string>
     */
    private function otherLanRoutes(): array
    {
        $routes = glob($this->ingress->dynamicDirectory().'/*'.self::LanSuffix.'.yaml') ?: [];

        return array_values(array_map(
            static fn (string $route): string => basename($route, self::LanSuffix.'.yaml'),
            array_filter($routes, fn (string $route): bool => $route !== $this->dynamicFile()),
        ));
    }

    private function slug(): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '-', strtolower($this->project->domain));
    }

    /**
     * @param  list<string>  $command
     */
    private function probe(array $command): string
    {
        return trim(run($command, context()->withAllowFailure()->withQuiet())->getOutput());
    }

    private function ensureBinary(string $binary, string $advice): void
    {
        if (! Shell::hasBinary($binary)) {
            throw new RuntimeException($advice);
        }
    }

    private function removeFile(string $path): void
    {
        if (! @unlink($path)) {
            throw new RuntimeException(sprintf('Unable to remove %s.', $path));
        }
    }
}
