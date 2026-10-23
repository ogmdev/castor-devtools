<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use RuntimeException;

use function Castor\context;
use function Castor\exit_code;
use function Castor\io;
use function Castor\parallel;
use function Castor\run;

final readonly class Environment
{
    private const int ProbeTimeout = 3;

    private const int UpstreamProbeTimeout = 10;

    private Project $project;

    private Ingress $ingress;

    public function __construct(?Project $project = null, ?Ingress $ingress = null)
    {
        $this->project = $project ?? CurrentProject::get();
        $this->ingress = $ingress ?? new Ingress;
    }

    public function enableTls(): void
    {
        $this->ensureMkcertInstalled();
        $this->prepareTlsDirectories();
        $this->ensureTlsDirectoriesAreWritable();
        $this->trustLocalCertificateAuthority();

        run([
            'mkcert',
            '-cert-file',
            $this->certificateFile(),
            '-key-file',
            $this->keyFile(),
            $this->project->domain,
            '*.'.$this->project->domain,
        ]);

        $this->writeFile($this->tlsDynamicFile(), $this->buildTlsDynamicConfig());
        $this->writeFile($this->redirectFile(), $this->buildHttpsRedirectConfig());

        io()->success(sprintf('TLS enabled on the local ingress for *.%s: port %d now redirects to https, like a production edge.', $this->project->domain, Ingress::HttpPort));

        foreach ($this->project->subdomains as $subdomain) {
            io()->writeln(sprintf('  %-12s %s', $subdomain, $this->httpsServiceUrl($subdomain)));
        }

        if ($this->isWsl()) {
            $this->trustCertificateAuthorityOnWindows();
        }
    }

    public function prepareTlsDirectories(): void
    {
        foreach ($this->tlsDirectories() as $directory) {
            if (! is_dir($directory)) {
                @mkdir($directory, 0755, true);
            }
        }
    }

    /**
     * @return list<string>
     */
    public function tlsDirectories(): array
    {
        return [$this->ingress->certificatesDirectory(), $this->ingress->dynamicDirectory()];
    }

    public function certificateFile(): string
    {
        return sprintf('%s/%s.pem', $this->ingress->certificatesDirectory(), $this->project->domain);
    }

    public function keyFile(): string
    {
        return sprintf('%s/%s.key.pem', $this->ingress->certificatesDirectory(), $this->project->domain);
    }

    public function tlsDynamicFile(): string
    {
        return sprintf('%s/%s-tls.yaml', $this->ingress->dynamicDirectory(), $this->project->domain);
    }

    public function redirectFile(): string
    {
        return sprintf('%s/%s-redirect.yaml', $this->ingress->dynamicDirectory(), $this->project->domain);
    }

    /**
     * @param  list<string>  $directories
     * @return list<string>
     */
    public function buildTlsDirectoryOwnershipCommand(string $owner, array $directories): array
    {
        return ['sudo', 'chown', '-R', $owner, ...$directories];
    }

    /**
     * @param  list<string>  $directories
     */
    public function tlsDirectoryOwnershipAdvice(array $directories): string
    {
        return sprintf(
            'Certificate directories are not writable: %s. Docker creates missing bind-mount sources as root on the first "docker compose up". Take them back, then run "%s" again: sudo chown -R "$(id -u):$(id -g)" %s',
            implode(', ', $directories),
            $this->project->task($this->project->tasks->tls),
            implode(' ', $directories),
        );
    }

    public function ensureTls(): void
    {
        if ($this->isTlsConfigured()) {
            return;
        }

        $this->enableTls();
    }

    public function ensureTlsOnStart(?string $service): void
    {
        if ($service !== null) {
            return;
        }

        try {
            if ($this->isTlsConfigured()) {
                if ($this->isWsl()) {
                    $this->trustCertificateAuthorityOnWindows(quietWhenTrusted: true);
                }

                return;
            }

            $this->enableTls();
        } catch (RuntimeException $exception) {
            io()->warning($exception->getMessage());
        }
    }

    public function ensureIngressIsRunning(): void
    {
        if ($this->ingress->isRunning()) {
            return;
        }

        throw new RuntimeException(sprintf(
            'The local ingress is not running, so no vhost would serve the Vite dev server. Run "%s", then run the command again.',
            $this->project->task($this->project->tasks->start),
        ));
    }

    public function mkcertInstallAdvice(bool $wsl, string $osFamily, bool $hasApt = true): string
    {
        $then = sprintf('then "%s"', $this->project->task($this->project->tasks->tls));

        if ($osFamily === 'Darwin') {
            return sprintf('mkcert is required for local TLS: brew install mkcert, %s.', $then);
        }

        if (($wsl || $osFamily === 'Linux') && $hasApt) {
            return sprintf('mkcert is required for local TLS: sudo apt install mkcert libnss3-tools, %s.', $then);
        }

        return sprintf('mkcert is required for local TLS: install it with your package manager (https://github.com/FiloSottile/mkcert#installation), %s.', $then);
    }

    /**
     * @return list<string>|null
     */
    public function mkcertInstallCommand(bool $wsl, string $osFamily, bool $hasApt, bool $hasBrew): ?array
    {
        if ($wsl || $osFamily === 'Linux') {
            return $hasApt ? ['sudo', 'apt-get', 'install', '-y', 'mkcert', 'libnss3-tools'] : null;
        }

        if ($osFamily === 'Darwin') {
            return $hasBrew ? ['brew', 'install', 'mkcert'] : null;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function buildWindowsUserStoreImportCommand(string $windowsPemPath): array
    {
        return ['certutil.exe', '-user', '-addstore', '-f', 'Root', $windowsPemPath];
    }

    /**
     * @return list<string>
     */
    public function buildWindowsRootStoreQueryCommand(): array
    {
        return ['certutil.exe', '-user', '-store', 'Root'];
    }

    public function certificateAuthorityFingerprint(string $pemContents): ?string
    {
        if (preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $pemContents, $matches) !== 1) {
            return null;
        }

        $der = base64_decode((string) preg_replace('/\s+/', '', $matches[1]), true);

        return $der === false || $der === '' ? null : sha1($der);
    }

    public function isWindowsCaTrusted(string $rootStoreOutput, string $fingerprint): bool
    {
        return str_contains(
            strtolower((string) preg_replace('/\s+/', '', $rootStoreOutput)),
            strtolower($fingerprint),
        );
    }

    public function firefoxWindowsAdvice(): string
    {
        return 'Firefox on Windows keeps its own trust store: enable security.enterprise_roots.enabled in about:config so it follows the mkcert authority.';
    }

    public function wslCertificateTrustAdvice(): string
    {
        return 'WSL workstation: the mkcert authority lives in Linux. For Windows browsers, import it into the Windows user store from this shell: certutil.exe -user -addstore -f Root "$(wslpath -w "$(mkcert -CAROOT)/rootCA.pem")"';
    }

    public function buildTlsDynamicConfig(): string
    {
        return "tls:\n"
            ."  certificates:\n"
            .'    - certFile: /etc/traefik/certs/'.$this->project->domain.".pem\n"
            .'      keyFile: /etc/traefik/certs/'.$this->project->domain.".key.pem\n";
    }

    public function buildHttpsRedirectConfig(): string
    {
        $name = 'https-redirect-'.$this->slug();

        return "http:\n"
            ."  routers:\n"
            ."    {$name}:\n"
            .'      rule: HostRegexp(`^(?:[^.]+\.)?'.preg_quote($this->project->domain, '/')."$`)\n"
            ."      entryPoints: [web]\n"
            ."      priority: 1000\n"
            ."      middlewares: [{$name}]\n"
            ."      service: noop@internal\n"
            ."  middlewares:\n"
            ."    {$name}:\n"
            ."      redirectScheme:\n"
            ."        scheme: https\n"
            .'        port: "'.Ingress::HttpsPort."\"\n"
            ."        permanent: true\n";
    }

    public function isTlsConfigured(): bool
    {
        return file_exists($this->certificateFile())
            && file_exists($this->keyFile())
            && file_exists($this->tlsDynamicFile())
            && file_exists($this->redirectFile());
    }

    public function showUrls(): void
    {
        io()->section('Local environment URLs');

        $tls = $this->isTlsConfigured();

        foreach ($this->urls($tls) as $label => $url) {
            io()->writeln(sprintf('  %-12s %s', $label, $url));
        }

        io()->writeln($tls
            ? sprintf('Port %d redirects to these addresses, like a production edge.', Ingress::HttpPort)
            : sprintf('TLS is inactive: "%s" enables https and the redirect of port %d.', $this->project->task($this->project->tasks->tls), Ingress::HttpPort));

        if ($this->project->viteSubdomain !== null) {
            io()->writeln(sprintf('%s only answers while "%s" runs.', $this->project->viteSubdomain, $this->project->task($this->project->tasks->assetsDev)));
        }

        io()->writeln(sprintf('Ingress dashboard: %s', Ingress::DashboardUrl));
    }

    public function applyHosts(): void
    {
        $hostsContent = (string) @file_get_contents('/etc/hosts');
        $missing = $this->missingFromHostsFile($hostsContent);

        if ($missing === []) {
            io()->success('/etc/hosts already declares every subdomain.');

            return;
        }

        io()->writeln('Subdomains missing from /etc/hosts: '.implode(', ', $missing));
        io()->writeln('Browsers resolve *.localhost on their own; the line serves tools that go through the system resolver (Safari, CLI).');

        $command = $this->buildHostsAppendCommand($hostsContent);

        if (InteractiveContext::confirm('Append the line now (sudo)?')) {
            run(['sh', '-c', $command], InteractiveContext::whenSupported());
        } else {
            io()->writeln('Command to run: '.$command);
        }

        if ($this->isWsl()) {
            io()->writeln($this->windowsHostsAdvice());
        }
    }

    /**
     * @return list<Finding>
     */
    public function ingressFindings(): array
    {
        if ($this->ingress->isRunning()) {
            return [Finding::ok('Local ingress', sprintf('running, dashboard on %s.', Ingress::DashboardUrl))];
        }

        return [new Finding(
            subject: 'Local ingress',
            severity: FindingSeverity::Blocking,
            detail: sprintf('the shared Traefik is stopped: nothing answers on ports %d and %d.', Ingress::HttpPort, Ingress::HttpsPort),
            remedy: sprintf('Start it with "%s".', $this->project->task($this->project->tasks->start)),
            effort: 'one command, a few seconds',
            documentationUrl: $this->project->troubleshootingDocumentationUrl,
        )];
    }

    /**
     * @return list<Finding>
     */
    public function hostsFindings(): array
    {
        $missing = $this->missingFromHostsFile((string) @file_get_contents('/etc/hosts'));

        if ($missing === []) {
            return [Finding::ok('Local subdomains', 'all declared in /etc/hosts.')];
        }

        return [new Finding(
            subject: 'Local subdomains',
            severity: FindingSeverity::Warning,
            detail: sprintf('missing from /etc/hosts: %s. Browsers resolve *.localhost on their own, tools using the system resolver do not.', implode(', ', $missing)),
            remedy: sprintf('Declare them with "%s".', $this->project->task($this->project->tasks->hosts)),
            effort: 'one command, a few seconds',
            documentationUrl: $this->project->setupDocumentationUrl,
        )];
    }

    /**
     * @return list<Finding>
     */
    public function tlsFindings(): array
    {
        if ($this->isTlsConfigured()) {
            return [Finding::ok('TLS certificates', 'present, the vhosts are served over https.')];
        }

        return [new Finding(
            subject: 'TLS certificates',
            severity: FindingSeverity::Blocking,
            detail: 'mkcert certificates are missing: the https URLs do not answer.',
            remedy: sprintf('Generate them with "%s".', $this->project->task($this->project->tasks->tls)),
            effort: 'one command, a minute the first time',
            documentationUrl: $this->project->setupDocumentationUrl,
        )];
    }

    /**
     * @return list<Finding>
     */
    public function vhostFindings(): array
    {
        if (! $this->isTlsConfigured()) {
            return [new Finding(
                subject: 'Stack vhosts',
                severity: FindingSeverity::Unknown,
                detail: 'not checked: without a certificate the https URLs cannot answer. This is not an extra problem.',
                remedy: sprintf('Come back to this point after "%s".', $this->project->task($this->project->tasks->tls)),
                documentationUrl: $this->project->troubleshootingDocumentationUrl,
            )];
        }

        $subdomains = $this->routedSubdomains();
        $statuses = $this->probeAll($this->buildHttpsCheckCommand(...), $subdomains);
        $unreachable = array_values(array_filter(
            $subdomains,
            static fn (string $subdomain, int $index): bool => in_array($statuses[$index], ['', '000'], true),
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($unreachable === []) {
            return [Finding::ok('Stack vhosts', sprintf('all %d subdomains answer over https.', count($subdomains)))];
        }

        $allDown = count($unreachable) === count($subdomains);

        return [new Finding(
            subject: 'Stack vhosts',
            severity: FindingSeverity::Blocking,
            detail: $allDown
                ? 'no subdomain answers: the stack is not running.'
                : sprintf('no answer from: %s.', implode(', ', $unreachable)),
            remedy: $allDown
                ? sprintf('Start the stack with "%s".', $this->project->task($this->project->tasks->start))
                : sprintf('Inspect the service with "%s", then "%s".', $this->project->task($this->project->tasks->status), $this->project->task($this->project->tasks->logs, '--service=...')),
            effort: $allDown ? 'one command, a few minutes on the first start' : 'a few minutes',
            documentationUrl: $this->project->troubleshootingDocumentationUrl,
        )];
    }

    public function showDoctor(): void
    {
        io()->title('Environment doctor');

        io()->writeln($this->ingress->isRunning()
            ? sprintf('Local ingress: running (%s).', Ingress::DashboardUrl)
            : sprintf('Local ingress: stopped, start it with "%s".', $this->project->task($this->project->tasks->start)));

        $missing = $this->missingFromHostsFile((string) @file_get_contents('/etc/hosts'));
        io()->writeln($missing === []
            ? '/etc/hosts: every subdomain is declared.'
            : sprintf('Declare with "%s": %s', $this->project->task($this->project->tasks->hosts), implode(', ', $missing)));

        $tls = $this->isTlsConfigured();

        io()->writeln($tls
            ? sprintf('Port %d, expected 301 everywhere:', Ingress::HttpPort)
            : sprintf('Port %d:', Ingress::HttpPort));
        $this->showProbes($this->serviceUrl(...), $this->buildHttpCheckCommand(...));

        if ($this->isWsl()) {
            io()->writeln($this->wslForwardingAdvice());
        }

        if (! $tls) {
            io()->warning(sprintf('TLS is inactive: run "%s" (mkcert certificates are missing).', $this->project->task($this->project->tasks->tls)));

            return;
        }

        io()->writeln(sprintf('Port %d:', Ingress::HttpsPort));
        $this->showProbes($this->httpsServiceUrl(...), $this->buildHttpsCheckCommand(...));
    }

    public function detectsWsl(string $procVersion): bool
    {
        return str_contains(strtolower($procVersion), 'microsoft');
    }

    public function windowsHostsAdvice(): string
    {
        return 'WSL workstation: this line only covers the Linux resolver. Windows browsers resolve *.localhost on their own; for a Windows tool using the system resolver (Postman, PowerShell), add the same line to C:\Windows\System32\drivers\etc\hosts.';
    }

    public function wslForwardingAdvice(): string
    {
        return 'WSL workstation: if the vhosts answer here but not from Windows, localhost forwarding dropped (typical after hibernation). Run "wsl --shutdown" from Windows, then restart the stack.';
    }

    /**
     * @return array<string, string>
     */
    public function urls(bool $tls = false): array
    {
        $urls = [];

        foreach ($this->project->subdomains as $subdomain) {
            $urls[$subdomain] = ($tls ? $this->httpsServiceUrl($subdomain) : $this->serviceUrl($subdomain)).($this->project->subdomainPaths[$subdomain] ?? '');
        }

        return $urls;
    }

    public function serviceUrl(string $subdomain): string
    {
        return sprintf('http://%s:%d', $this->hostName($subdomain), Ingress::HttpPort);
    }

    public function httpsServiceUrl(string $subdomain): string
    {
        return sprintf('https://%s:%d', $this->hostName($subdomain), Ingress::HttpsPort);
    }

    public function hostName(string $subdomain): string
    {
        return $subdomain.'.'.$this->project->domain;
    }

    public function httpsStatus(string $subdomain, int $timeout = self::ProbeTimeout): string
    {
        return $this->probe($this->buildHttpsCheckCommand($subdomain, $timeout));
    }

    public function isSubdomainServing(string $subdomain): bool
    {
        return ! in_array($this->httpsStatus($subdomain, self::UpstreamProbeTimeout), ['', '000', '502'], true);
    }

    /**
     * @return list<string>
     */
    public function buildHttpsCheckCommand(string $subdomain, int $timeout = self::ProbeTimeout): array
    {
        return [
            'curl',
            '--silent',
            '--insecure',
            '--resolve',
            sprintf('%s:%d:127.0.0.1', $this->hostName($subdomain), Ingress::HttpsPort),
            '--output',
            '/dev/null',
            '--write-out',
            '%{http_code}',
            '--max-time',
            (string) $timeout,
            $this->httpsServiceUrl($subdomain),
        ];
    }

    /**
     * @return list<string>
     */
    public function buildHttpCheckCommand(string $subdomain): array
    {
        return [
            'curl',
            '--silent',
            '--output',
            '/dev/null',
            '--write-out',
            '%{http_code}',
            '--max-time',
            (string) self::ProbeTimeout,
            '--header',
            sprintf('Host: %s:%d', $this->hostName($subdomain), Ingress::HttpPort),
            sprintf('http://127.0.0.1:%d', Ingress::HttpPort),
        ];
    }

    /**
     * @return list<string>
     */
    public function hostNames(): array
    {
        return array_map($this->hostName(...), $this->project->subdomains);
    }

    public function buildHostsLine(): string
    {
        return '127.0.0.1 '.implode(' ', $this->hostNames());
    }

    public function buildHostsAppendCommand(string $hostsContent): string
    {
        $separator = $hostsContent === '' || str_ends_with($hostsContent, "\n") ? '' : "\n";

        return sprintf("printf '%%s' %s | sudo tee -a /etc/hosts > /dev/null", escapeshellarg($separator.$this->buildHostsLine()."\n"));
    }

    /**
     * @return list<string>
     */
    public function missingFromHostsFile(string $hostsContent): array
    {
        $declared = $this->declaredHostNames($hostsContent);

        return array_values(array_filter(
            $this->hostNames(),
            static fn (string $host): bool => ! in_array($host, $declared, true),
        ));
    }

    /**
     * @return list<string>
     */
    public function declaredHostNames(string $hostsContent): array
    {
        $names = [];

        foreach (explode("\n", $hostsContent) as $line) {
            $fields = preg_split('/\s+/', trim(explode('#', $line, 2)[0])) ?: [];
            array_push($names, ...array_slice($fields, 1));
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    public function viteContainerOptions(): array
    {
        if ($this->project->viteSubdomain === null) {
            return [];
        }

        $name = $this->project->name.'-vite';

        return [
            '--network',
            Ingress::Network,
            '-e',
            'VITE_DEV_SERVER_ORIGIN='.$this->viteDevServerOrigin(),
            '--label',
            Ingress::EnableLabel.'=true',
            '--label',
            sprintf('traefik.http.routers.%s.rule=Host(`%s`)', $name, $this->hostName($this->project->viteSubdomain)),
            '--label',
            sprintf('traefik.http.routers.%s.entrypoints=web,websecure', $name),
            '--label',
            sprintf('traefik.http.services.%s.loadbalancer.server.port=%d', $name, $this->project->vitePort),
        ];
    }

    public function viteDevServerOrigin(): string
    {
        $subdomain = $this->project->viteSubdomain ?? throw new RuntimeException('The project declares no Vite subdomain.');

        return $this->isTlsConfigured() ? $this->httpsServiceUrl($subdomain) : $this->serviceUrl($subdomain);
    }

    /**
     * @return list<string>
     */
    private function routedSubdomains(): array
    {
        return array_values(array_filter(
            $this->project->subdomains,
            fn (string $subdomain): bool => $subdomain !== $this->project->viteSubdomain,
        ));
    }

    /**
     * @param  callable(string): string  $url
     * @param  callable(string): list<string>  $command
     */
    private function showProbes(callable $url, callable $command): void
    {
        $statuses = $this->probeAll($command, $this->project->subdomains);

        foreach ($this->project->subdomains as $index => $subdomain) {
            io()->writeln(sprintf('  %-12s %s → %s', $subdomain, $url($subdomain), $this->describeStatus($statuses[$index])));
        }
    }

    /**
     * @param  callable(string): list<string>  $command
     * @param  list<string>  $subdomains
     * @return list<string>
     */
    private function probeAll(callable $command, array $subdomains): array
    {
        return array_values(parallel(...array_map(
            fn (string $subdomain): callable => fn (): string => $this->probe($command($subdomain)),
            $subdomains,
        )));
    }

    /**
     * @param  list<string>  $command
     */
    private function probe(array $command): string
    {
        return trim(run($command, context()->withAllowFailure()->withQuiet())->getOutput());
    }

    private function describeStatus(string $status): string
    {
        return $status === '' || $status === '000' ? 'unreachable' : 'HTTP '.$status;
    }

    private function slug(): string
    {
        return (string) preg_replace('/[^a-z0-9]+/', '-', strtolower($this->project->domain));
    }

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException(sprintf('Unable to write %s.', $path));
        }
    }

    private function ensureMkcertInstalled(): void
    {
        if ($this->isMkcertAvailable()) {
            return;
        }

        $hasApt = $this->hasBinary('apt-get');
        $advice = $this->mkcertInstallAdvice($this->isWsl(), PHP_OS_FAMILY, $hasApt);

        $command = $this->mkcertInstallCommand(
            $this->isWsl(),
            PHP_OS_FAMILY,
            $hasApt,
            $this->hasBinary('brew'),
        );

        if ($command === null) {
            throw new RuntimeException($advice);
        }

        io()->writeln('mkcert is missing from this workstation, and local https depends on it.');

        if (! InteractiveContext::confirm(sprintf('Install mkcert now (%s)?', implode(' ', $command)))) {
            throw new RuntimeException($advice);
        }

        run($command, InteractiveContext::whenSupported()->withAllowFailure());

        if (! $this->isMkcertAvailable()) {
            throw new RuntimeException($advice);
        }
    }

    private function ensureTlsDirectoriesAreWritable(): void
    {
        $unwritable = $this->unwritableTlsDirectories();

        if ($unwritable === []) {
            return;
        }

        io()->writeln('Certificate directories are unusable, missing or owned by root: Docker creates missing bind-mount sources on the first "docker compose up", before mkcert could write into them.');

        $command = $this->buildTlsDirectoryOwnershipCommand(
            sprintf('%d:%d', posix_getuid(), posix_getgid()),
            $unwritable,
        );

        if (InteractiveContext::confirm(sprintf('Take them back now (%s)?', implode(' ', $command)))) {
            run($command, InteractiveContext::whenSupported()->withAllowFailure());
            clearstatcache();
        }

        if ($this->unwritableTlsDirectories() !== []) {
            throw new RuntimeException($this->tlsDirectoryOwnershipAdvice($unwritable));
        }
    }

    /**
     * @return list<string>
     */
    private function unwritableTlsDirectories(): array
    {
        return array_values(array_filter(
            $this->tlsDirectories(),
            static fn (string $directory): bool => ! is_dir($directory) || ! is_writable($directory),
        ));
    }

    private function trustLocalCertificateAuthority(): void
    {
        $process = run(['mkcert', '-install'], InteractiveContext::whenSupported()->withAllowFailure());

        if (($process->getExitCode() ?? 1) === 0) {
            return;
        }

        io()->warning('The mkcert authority could not be added to the local trust stores: run "mkcert -install", otherwise browsers will reject the certificate.');
    }

    private function trustCertificateAuthorityOnWindows(bool $quietWhenTrusted = false): void
    {
        $pemPath = $this->certificateAuthorityPemPath();

        if ($pemPath === null || ! $this->hasBinary('certutil.exe')) {
            io()->writeln($this->wslCertificateTrustAdvice());

            return;
        }

        $windowsPemPath = $this->probe(['wslpath', '-w', $pemPath]);

        if ($windowsPemPath === '') {
            io()->writeln($this->wslCertificateTrustAdvice());

            return;
        }

        $fingerprint = $this->certificateAuthorityFingerprint((string) @file_get_contents($pemPath));
        $store = run($this->buildWindowsRootStoreQueryCommand(), context()->withAllowFailure()->withQuiet());

        if ($fingerprint !== null && $this->isWindowsCaTrusted($store->getOutput(), $fingerprint)) {
            if (! $quietWhenTrusted) {
                io()->writeln('The mkcert authority is already in the Windows user store: Windows browsers trust the vhosts.');
            }

            return;
        }

        io()->writeln('Importing the mkcert authority into the Windows user store: Windows asks for a confirmation, accept it.');

        $import = run($this->buildWindowsUserStoreImportCommand($windowsPemPath), InteractiveContext::whenSupported()->withAllowFailure());

        if (($import->getExitCode() ?? 1) !== 0) {
            io()->warning('Windows import cancelled: the confirmation was refused, or the certificate is not reachable from Windows.');
            io()->writeln($this->wslCertificateTrustAdvice());

            return;
        }

        io()->success('Windows accepted the mkcert authority: the vhosts answer over https without warning.');
        io()->writeln($this->firefoxWindowsAdvice());
    }

    private function certificateAuthorityPemPath(): ?string
    {
        $caRoot = $this->probe(['mkcert', '-CAROOT']);

        if ($caRoot === '') {
            return null;
        }

        $pemPath = $caRoot.'/rootCA.pem';

        return is_file($pemPath) ? $pemPath : null;
    }

    private function isWsl(): bool
    {
        return $this->detectsWsl((string) @file_get_contents('/proc/version'));
    }

    /**
     * @phpstan-impure
     */
    private function isMkcertAvailable(): bool
    {
        return exit_code(['mkcert', '-version'], context()->withQuiet()) === 0;
    }

    private function hasBinary(string $binary): bool
    {
        return exit_code(['sh', '-c', 'command -v '.escapeshellarg($binary)], context()->withQuiet()) === 0;
    }
}
