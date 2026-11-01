<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use RuntimeException;

use function Castor\context;
use function Castor\exit_code;
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

    private const string RouterRuleLabel = '/^traefik\.http\.routers\.([^.]+)\.rule$/';

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

            if (filter_var($address, FILTER_VALIDATE_IP) !== false) {
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
     * @return list<list<string>>
     */
    public function buildIpAddressCommands(): array
    {
        if ($this->osFamily === 'Darwin') {
            return [['ipconfig', 'getifaddr', 'en0'], ['ipconfig', 'getifaddr', 'en1']];
        }

        return [['sh', '-c', 'hostname -I | cut -d " " -f 1']];
    }

    public function certificateFile(): string
    {
        return $this->ingress->certificatesDirectory().'/'.$this->project->domain.'-lan.pem';
    }

    public function keyFile(): string
    {
        return $this->ingress->certificatesDirectory().'/'.$this->project->domain.'-lan.key.pem';
    }

    public function p12File(): string
    {
        return $this->ingress->certificatesDirectory().'/'.$this->project->domain.'-lan.p12';
    }

    public function dynamicFile(): string
    {
        return $this->ingress->dynamicDirectory().'/'.$this->project->domain.'-lan.yaml';
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
        (new Environment($this->project, $this->ingress))->prepareTlsDirectories();

        $names = $this->names();

        foreach ($this->buildCertificateCommands($names) as $command) {
            run($command, context()->withQuiet());
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

        $names = $this->issueCertificate();
        run($this->buildSymfonyServerCommand($port));

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
            $host = '`'.$subdomain.'.'.$this->project->domain.'`';

            foreach ($containers as $container) {
                foreach ($container['labels'] as $label => $rule) {
                    if (preg_match(self::RouterRuleLabel, $label, $matches) !== 1 || ! str_contains($rule, $host)) {
                        continue;
                    }

                    $router = $matches[1];
                    $service = $container['labels']['traefik.http.routers.'.$router.'.service'] ?? null;

                    if ($service !== null) {
                        return $service;
                    }

                    $declaresService = array_any(
                        array_keys($container['labels']),
                        static fn (string $label): bool => str_starts_with($label, 'traefik.http.services.'.$router.'.'),
                    );

                    return $declaresService ? $router : null;
                }
            }
        }

        return null;
    }

    public function missingServiceAdvice(): string
    {
        return sprintf(
            'No running container routes %s with a named Traefik service: label the service with traefik.http.services.<router>.loadbalancer.server.port (or traefik.http.routers.<router>.service), and start the stack.',
            $this->project->subdomains[0].'.'.$this->project->domain,
        );
    }

    /**
     * The route and certificate Traefik reads from its dynamic directory: the names on https, forwarded to the project's own service.
     *
     * @param  non-empty-list<string>  $names
     */
    public function buildTraefikConfig(string $service, array $names): string
    {
        $router = $this->slug().'-lan';
        $rule = implode(' || ', array_map(static fn (string $name): string => 'Host(`'.$name.'`)', $names));

        return "http:\n"
            ."  routers:\n"
            ."    {$router}:\n"
            ."      rule: {$rule}\n"
            ."      entryPoints: [web, websecure]\n"
            ."      service: {$service}@docker\n"
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
        $this->writeFile($this->dynamicFile(), $this->buildTraefikConfig($service, $names));

        if ($this->ingress->runningListenIp() !== Ingress::EveryInterface) {
            io()->note('Recreating the local ingress so it listens on every network interface.');
            $this->ingress->listeningOn(Ingress::EveryInterface)->restart();
        }

        $this->showAccess('The local ingress', $names, Ingress::HttpsPort);
        io()->writeln('Through the ingress, use the name rather than the address: without a server name, Traefik answers with its default certificate.');
    }

    public function stop(): void
    {
        $stopped = [];

        if ($this->isSymfonyServerRunning()) {
            run(['symfony', 'server:stop'], context()->withQuiet());
            $stopped[] = 'the Symfony CLI server is stopped';
        }

        if (is_file($this->dynamicFile())) {
            if (! @unlink($this->dynamicFile())) {
                throw new RuntimeException(sprintf('Unable to remove %s.', $this->dynamicFile()));
            }

            $stopped[] = 'the ingress route is removed';
        }

        if ($this->ingress->runningListenIp() === Ingress::EveryInterface) {
            $this->ingress->listeningOn(Ingress::Loopback)->restart();
            $stopped[] = 'the ingress is back on the loopback';
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

        $names = $this->names();
        $listenIp = $this->ingress->runningListenIp();

        io()->writeln(sprintf('  %-14s %s', 'Workstation', implode(', ', $names)));
        io()->writeln(sprintf('  %-14s %s', 'Certificate', is_file($this->p12File()) ? $this->p12File() : 'none yet, issued on start'));
        io()->writeln(sprintf('  %-14s %s', 'Ingress', match (true) {
            $listenIp === null => 'stopped',
            $listenIp === Ingress::EveryInterface && is_file($this->dynamicFile()) => sprintf('serving %s on the local network, https://%s:%d', $this->project->name, $names[0], Ingress::HttpsPort),
            $listenIp === Ingress::EveryInterface => 'open to the local network, without a route for '.$this->project->name,
            default => 'loopback only',
        }));
        io()->writeln(sprintf('  %-14s %s', 'Symfony CLI', match (true) {
            ! $this->hasBinary('symfony') => 'not installed',
            $this->isSymfonyServerRunning() => 'running: '.$this->symfonyServerStatus(),
            default => 'stopped',
        }));
        io()->writeln($this->rootCertificateAdvice());
    }

    /**
     * @return list<string>
     */
    public function buildCheckCommand(string $address, int $port, string $serverName): array
    {
        return ['sh', '-c', sprintf(
            'openssl s_client -connect %s:%d -servername %s </dev/null 2>/dev/null | openssl x509 -noout -issuer -ext subjectAltName',
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
            io()->error(sprintf('Nothing answers over TLS on %s:%d: start the local network access first.', $address, $port));

            return 1;
        }

        io()->writeln($output);

        $missing = self::namesMissingFrom($output, $names);

        if ($missing !== []) {
            io()->error(sprintf('The certificate served on port %d does not cover %s: start again, a fresh certificate is issued each time.', $port, implode(', ', $missing)));

            return 1;
        }

        io()->success(sprintf('The certificate served on port %d covers %s.', $port, implode(' and ', $names)));

        return 0;
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
     * @param  non-empty-list<string>  $names
     * @return list<string>
     */
    public function urls(array $names, int $port): array
    {
        return array_map(static fn (string $name): string => sprintf('https://%s:%d', $name, $port), $names);
    }

    public function rootCertificateAdvice(): string
    {
        $caRoot = $this->hasBinary('mkcert') ? $this->probe(['mkcert', '-CAROOT']) : '';

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

    private function isSymfonyServerRunning(): bool
    {
        return $this->hasBinary('symfony') && str_contains($this->symfonyServerStatus(), 'Listening on');
    }

    private function symfonyServerStatus(): string
    {
        $status = $this->probe(['symfony', 'server:status', '--no-ansi']);

        return trim(preg_match('/Listening on \S+/', $status, $matches) === 1 ? $matches[0] : $status);
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
        if (! $this->hasBinary($binary)) {
            throw new RuntimeException($advice);
        }
    }

    /**
     * @phpstan-impure
     */
    private function hasBinary(string $binary): bool
    {
        return exit_code(['sh', '-c', 'command -v '.escapeshellarg($binary)], context()->withQuiet()) === 0;
    }

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException(sprintf('Unable to write %s.', $path));
        }
    }
}
