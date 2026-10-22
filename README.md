# castor-devtools

Building blocks for [Castor](https://castor.jolicode.com) task files that drive a Laravel application running in Docker Compose:

- compose commands that work from the main checkout and from git worktrees;
- Composer, Artisan, Pest, Pint, PHPStan and Rector run in the PHP container;
- npm and Vite in a throwaway Node container;
- **one local Traefik ingress shared by every project on the workstation**, with mkcert TLS, `*.localhost` subdomains and an http → https redirect, so each application runs on production-like hostnames;
- `/etc/hosts` management and an environment doctor.

## Install

Castor installs the package itself from `.castor/castor.composer.json`:

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/ogmdev/castor-devtools" }
    ],
    "require": {
        "ogmdev/castor-devtools": "^0.1"
    }
}
```

Run `castor composer update` once and commit `.castor/castor.composer.lock`. Castor loads `.castor/vendor/autoload.php` on every run.

The package has no runtime dependency: the Castor binary provides the functions it calls. Tests that exercise these classes in the application can therefore also require it as a Composer `require-dev`, with the same `vcs` repository, without any version conflict.

## Describe the project

```php
use Castor\Attribute\AsTask;
use Ogmdev\CastorDevtools\Checkout;
use Ogmdev\CastorDevtools\CurrentCheckout;
use Ogmdev\CastorDevtools\CurrentProject;
use Ogmdev\CastorDevtools\DockerCompose;
use Ogmdev\CastorDevtools\Environment;
use Ogmdev\CastorDevtools\Ingress;
use Ogmdev\CastorDevtools\Project;

CurrentCheckout::use(Checkout::fromGit(__DIR__));
CurrentProject::use(new Project(
    name: 'acme',
    domain: 'acme.localhost',
    subdomains: ['app', 'www', 'vite'],
    phpService: 'php',
    nginxServicesByPhpService: ['php' => ['nginx']],
    projectMountedServicePrefixes: ['php'],
));

#[AsTask(name: 'docker:start')]
function start(?string $service = null): void
{
    (new Ingress)->ensureRunning();
    (new DockerCompose)->startContainers($service);
    (new Environment)->ensureTlsOnStart($service);
}
```

## The shared ingress

`Ingress::ensureRunning()` starts the `local-ingress` compose project shipped in `ingress/compose.yaml`: Traefik on `127.0.0.1:8000` (http) and `127.0.0.1:8443` (https), attached to the external Docker network `local-ingress`. Starting it again from another project is a no-op.

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

Prefix router names with the project name: every project shares the same Traefik.

Certificates and dynamic configuration live in `${XDG_DATA_HOME:-~/.local/share}/local-ingress`. `Environment::enableTls()` asks mkcert for a `*.<domain>` certificate and writes two files named after the domain: the certificate declaration, and a router that redirects that domain only from http to https. Two projects never overwrite each other.

The dashboard answers on http://ingress.localhost:8000/dashboard/.

## Vite through the ingress

`Assets::buildAssets('dev')` runs the Vite dev server on the ingress network and routes `vite.<domain>` to it. It passes the origin to use in `VITE_DEV_SERVER_ORIGIN`: https once certificates exist, http before. In `vite.config.js`:

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

## Development

```bash
composer install
composer test
composer phpstan
composer pint
```

## License

MIT
