<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use InvalidArgumentException;

use function Castor\run;

final readonly class Assets
{
    private Environment $environment;

    private Project $project;

    public function __construct(private string $projectDirectory, ?Environment $environment = null, ?Project $project = null)
    {
        $this->project = $project ?? CurrentProject::get();
        $this->environment = $environment ?? new Environment($this->project);
    }

    public function buildAssets(string $action, bool $clean = false, bool $pull = false): void
    {
        $this->assertSupportedAction($action);

        if ($action === 'dev') {
            $this->environment->ensureIngressIsRunning();
        }

        if ($pull) {
            run(['docker', 'pull', $this->project->nodeImage]);
        }

        $node = new Node($this->projectDirectory, $this->project);
        $node->stopRunningContainer();

        run($this->buildDockerRunCommand($action, $clean, $node->hostUser()), InteractiveContext::whenSupported());
    }

    /**
     * @return list<string>
     */
    public function buildDockerRunCommand(string $action, bool $clean = false, string $user = '1000:1000'): array
    {
        $this->assertSupportedAction($action);
        $node = new Node($this->projectDirectory, $this->project);

        return $node->buildDockerRunCommand([
            $node->buildNpmShellCommand(['ci']),
            $node->buildNpmShellCommand(['run', $action]),
        ], $clean, $user, $action === 'dev' ? $this->environment->viteContainerOptions() : []);
    }

    public function buildAssetShellCommand(string $action, bool $clean = false): string
    {
        $this->assertSupportedAction($action);

        $node = new Node($this->projectDirectory, $this->project);

        return $node->buildShellCommand([
            $node->buildNpmShellCommand(['ci']),
            $node->buildNpmShellCommand(['run', $action]),
        ], $clean);
    }

    private function assertSupportedAction(string $action): void
    {
        if (! in_array($action, ['build', 'dev'], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported asset action "%s".', $action));
        }
    }
}
