# castor-devtools

Building blocks for [Castor](https://castor.jolicode.com) task files that drive a web application running in Docker Compose (Laravel by default, every path configurable):

- compose commands that work from the main checkout and from git worktrees;
- Composer, Artisan, Pest, Pint, PHPStan and Rector run in the PHP container;
- npm and Vite in a throwaway Node container;
- **one local Traefik ingress shared by every project on the workstation**, with mkcert TLS, `*.localhost` subdomains and an http → https redirect, so each application runs on production-like hostnames;
- `/etc/hosts` management, an environment doctor, and a walking elephant while it checks.

## Install

The package needs Castor 1.9 or later. Castor installs it from `castor.composer.json`, at the root of the project or in `.castor/`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/ogmdev/castor-devtools" }
    ],
    "require": {
        "ogmdev/castor-devtools": "^0.2"
    }
}
```

Run `castor composer update` once and commit `castor.composer.lock`. Castor loads `.castor/vendor/autoload.php` on every run. Remote imports are an experimental Castor feature, and `castor --no-remote` (or `CASTOR_NO_REMOTE=1`) disables this package along with every other remote import.

The package has no runtime dependency: the Castor binary provides the functions it calls, and a `conflict` on `jolicode/castor <1.9` refuses older ones. Tests that exercise these classes in the application can therefore also require it as a Composer `require-dev`, with the same `vcs` repository, without any version conflict.

## Describe the project

```php
<?php

defined('CASTOR_USE_CHDIR') || define('CASTOR_USE_CHDIR', true);

use Castor\Attribute\AsTask;
use Ogmdev\CastorDevtools\Checkout;
use Ogmdev\CastorDevtools\CurrentCheckout;
use Ogmdev\CastorDevtools\CurrentProject;
use Ogmdev\CastorDevtools\Doctor;
use Ogmdev\CastorDevtools\DockerCompose;
use Ogmdev\CastorDevtools\Environment;
use Ogmdev\CastorDevtools\Ingress;
use Ogmdev\CastorDevtools\Project;
use Ogmdev\CastorDevtools\Tasks;

CurrentCheckout::use(Checkout::fromGit(__DIR__));
CurrentProject::use(new Project(
    name: 'acme',
    domain: 'acme.localhost',
    subdomains: ['app', 'www', 'vite'],
    phpService: 'php',
    nginxServicesByPhpService: ['php' => ['nginx']],
    projectMountedServicePrefixes: ['php'],
    tasks: new Tasks(executable: 'castor'),
));

#[AsTask(name: 'docker:start')]
function start(?string $service = null): void
{
    (new Ingress)->ensureRunning();
    (new DockerCompose)->startContainers($service);
    (new Environment)->ensureTlsOnStart($service);
}

#[AsTask(name: 'dev:doctor')]
function doctor(): int
{
    return (new Doctor)->run();
}
```

Every option of `Project` but `name`, `domain` and `subdomains` has a default:

| Option | Default | Use |
|---|---|---|
| `phpService` | `php` | compose service that runs Composer, Artisan and the quality tools |
| `nginxServicesByPhpService` | `[]` | nginx services reloaded after their PHP service restarts |
| `projectMountedServicePrefixes` | `['php']` | services that mount the project, and so receive the worktree directory |
| `containerRoot` | `/var/www/html` | where the services and the Node container mount the project |
| `containerShell` | `sh` | shell opened by `DockerCompose::openShellInService()` |
| `viteSubdomain`, `vitePort` | `vite`, `5173` | Vite dev server route, `null` to disable it |
| `nodeImage` | `node:26-alpine` | throwaway Node container |
| `npmCacheDirectory` | `storage/docker/cache/npm` | npm cache, relative to `containerRoot` |
| `phpstanCommands`, `rectorAnalysedPaths` | Laravel paths | quality tools |
| `worktreeClonedDirectories`, `worktreeCopiedFiles`, `worktreeSymlinks` | `vendor`, `public/build`, `.env` | what `WorktreeSetup` brings from the main checkout |
| `tasks` | `new Tasks` | task names the messages tell the developer to run |
| `supportContact` | `null` | who the doctor sends the developer to, if anyone |
| `setupDocumentationUrl`, `troubleshootingDocumentationUrl` | `null` | links printed with the findings |
| `guideSections`, `documentationLinks` | `[]` | content of `DeveloperGuide` |
| `beforeBuild` | `null` | closure called before anything is built or pulled |

### Task names

The package defines no task: `castor.php` does. The messages name the tasks to run through `Tasks`, so declare yours when they differ from the defaults:

| `Tasks` argument | Default |
|---|---|
| `executable` | `castor` (for instance `./castor` for a wrapper script) |
| `start` | `docker:start` |
| `status` | `docker:status` |
| `logs` | `docker:logs` |
| `tls` | `env:tls` |
| `hosts` | `env:hosts` |
| `assetsDev` | `assets:dev` |
| `doctor` | `dev:doctor` |

`PhpStan::run()`, `Pint::run()` and `Rector::run()` return the exit code of the tool: return it from the task, so Castor exits with it.

## The doctor

`Doctor::run()` walks through the checks, draws an elephant that leaves one footprint per check (coloured by its result) in an interactive terminal, then prints only what needs a gesture: the finding, the fix, the time it takes and the documentation. It changes nothing on the stack, and returns `1` when something blocks.

The generic checks come first: local subdomains, TLS certificates, Docker, the local ingress and the stack vhosts. A project adds its own after them, or replaces them:

```php
(new Doctor(steps: [
    'Secrets' => fn (): array => [Finding::ok('Secrets', 'present in every container.')],
]))->run();

(new Doctor(steps: $mySteps, withDefaultSteps: false))->run();
```

A step returns a list of `Finding`. A finding with `halts: true` stops the walk, for the checks that make every later one meaningless. When `supportContact` is set, the report ends with a plain text summary to hand over to that contact.

## The shared ingress

`Ingress::ensureRunning()` starts the `local-ingress` compose project shipped in `ingress/compose.yaml`: Traefik on `127.0.0.1:8000` (http) and `127.0.0.1:8443` (https), attached to the external Docker network `local-ingress`.

It works the same with one project, several, or any number of them: no project depends on another, none breaks or downgrades the ingress of another.

- The ingress carries the version of the package that started it. A running ingress of the same version or newer is kept as is, whichever project started it; a newer one only adds a warning to update castor-devtools in the project that is behind. An older one is recreated, once, with a message.
- When another container publishes port 8000 or 8443 (a former project-local Traefik, typically), the start stops before compose and names that container, its compose project and the command that frees the port.
- The doctor reports the containers that carry Traefik labels the ingress ignores (no opt-in label, or not on the network), and the router names or `Host` rules claimed by two projects.
- `Ingress::restart()` recreates the ingress on purpose.

Traefik only routes containers that opt in. A project exposes a service by joining the network and labelling it:

```yaml
services:
  nginx:
    networks: [default, local-ingress]
    labels:
      local-ingress.enable: "true"
      traefik.http.routers.acme-app.rule: Host(`app.acme.localhost`)
      traefik.http.routers.acme-app.entrypoints: web,websecure

networks:
  local-ingress:
    external: true
```

Prefix router names with the project name: every project shares the same Traefik. A labelled container without router labels gets the Traefik default rule, and one that exposes several ports needs `traefik.http.services.<name>.loadbalancer.server.port`.

### Add a project

1. Pick a domain of its own under `.localhost`, such as `acme.localhost`.
2. Label each exposed service with `local-ingress.enable: "true"` and its routers, named `<project>-<service>`.
3. Join those services to the external `local-ingress` network, declared with `external: true`.
4. Describe the project with `Project` in `castor.php` (name, domain, subdomains, task names), and start it with `Ingress::ensureRunning()` before `DockerCompose::startContainers()`.
5. Run the TLS task once, then the doctor.

Without Castor, a project still joins the ingress the same way: create the network once with `docker network create local-ingress`, and start the ingress with `docker compose --project-name local-ingress --file ingress/compose.yaml up -d` from a checkout of this package.

`Environment::enableTls()` asks mkcert for a `<domain>` and `*.<domain>` certificate and writes two files named after the domain: the certificate declaration, and a router that redirects that domain only from http to https. Two projects never overwrite each other.

The ingress belongs to the workstation, not to a project, so its settings are environment variables:

| Variable | Default | Use |
|---|---|---|
| `LOCAL_INGRESS_DATA_DIR` | `${XDG_DATA_HOME:-~/.local/share}/local-ingress` | certificates and dynamic configuration |
| `LOCAL_INGRESS_TRAEFIK_IMAGE` | `traefik:v3.6` | Traefik image, for instance from a registry mirror |
| `LOCAL_INGRESS_DOCKER_SOCKET` | `/var/run/docker.sock` | Docker socket, for rootless Docker or Podman |
| `LOCAL_INGRESS_TRUST_FORWARDED_HEADERS` | `false` | trust the `X-Forwarded-*` headers received on port 8000, behind a tunnel for instance |

Traefik reads the Docker socket to discover the containers. Mounted read-only or not, the socket gives full access to the Docker engine: the ingress only listens on the loopback, and routes on the `Host` header only.

The dashboard answers on http://ingress.localhost:8000/dashboard/. Port 8000 is also the default of `php artisan serve`: stop it before starting the ingress.

## Vite through the ingress

`Assets::buildAssets('dev')` runs the Vite dev server on the ingress network, as the host user and group, and routes `vite.<domain>` to it. It refuses to start when the ingress is down. It passes the origin to use in `VITE_DEV_SERVER_ORIGIN`: https once certificates exist, http before. In `vite.config.js`:

```js
const devServerOrigin = new URL(process.env.VITE_DEV_SERVER_ORIGIN ?? 'https://vite.acme.localhost:8443');

export default defineConfig({
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        origin: devServerOrigin.origin,
        cors: { origin: new RegExp(`^${devServerOrigin.protocol}//(?:[\\w-]+\\.)+localhost:${devServerOrigin.port}$`) },
        hmr: {
            protocol: devServerOrigin.protocol === 'https:' ? 'wss' : 'ws',
            host: devServerOrigin.hostname,
            clientPort: Number(devServerOrigin.port),
        },
    },
});
```

## Git worktrees

The containers mount the main checkout only, so a worktree must live inside it (any ignored directory will do). Tasks run from the worktree then drive the main stack on the worktree files, and `WorktreeSetup` clones `vendor` and copies `.env` from the main checkout.

## Development

```bash
composer install
composer test
composer phpstan
composer pint
```

## Credits

- [Castor](https://github.com/jolicode/castor) by JoliCode (MIT) runs these task files.
- [Traefik](https://github.com/traefik/traefik) by Traefik Labs (MIT) serves the shared ingress.
- [mkcert](https://github.com/FiloSottile/mkcert) by Filippo Valsorda (BSD-3-Clause) issues the local certificates.

This package is not affiliated with JoliCode or Traefik Labs. Traefik is a trademark of Traefik Labs.

## License

MIT
