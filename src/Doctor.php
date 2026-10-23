<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use function Castor\context;
use function Castor\exit_code;
use function Castor\io;

final readonly class Doctor
{
    private Project $project;

    private Environment $environment;

    private DockerCompose $docker;

    /**
     * @param  array<string, callable(): list<Finding>>  $steps
     */
    public function __construct(
        private array $steps = [],
        private bool $withDefaultSteps = true,
        ?Project $project = null,
        ?Environment $environment = null,
        ?DockerCompose $docker = null,
        private DoctorDisplay $display = new DoctorDisplay,
        private ?DoctorScene $scene = null,
    ) {
        $this->project = $project ?? CurrentProject::get();
        $this->environment = $environment ?? new Environment($this->project);
        $this->docker = $docker ?? new DockerCompose($this->project);
    }

    public function run(): int
    {
        io()->newLine();
        io()->writeln($this->display->heading(sprintf('Doctor: the %s local environment', $this->project->name)));
        io()->newLine();
        $this->writeLines($this->display->paragraph('Diagnosis only: nothing is started, stopped or changed on the stack.'));
        io()->newLine();

        $findings = $this->walkThroughSteps();
        $this->reportFindings($findings);

        return $this->worstSeverity($findings) === FindingSeverity::Blocking ? 1 : 0;
    }

    /**
     * @return array<string, callable(): list<Finding>>
     */
    public function steps(): array
    {
        return $this->withDefaultSteps ? [...$this->defaultSteps(), ...$this->steps] : $this->steps;
    }

    /**
     * @return array<string, callable(): list<Finding>>
     */
    public function defaultSteps(): array
    {
        return [
            'Local subdomains' => fn (): array => $this->environment->hostsFindings(),
            'TLS certificates' => fn (): array => $this->environment->tlsFindings(),
            'Docker' => fn (): array => $this->dockerFindings(),
            'Local ingress' => fn (): array => $this->environment->ingressFindings(),
            'Ingress routes' => fn (): array => $this->environment->routingFindings(),
            'Stack vhosts' => fn (): array => $this->environment->vhostFindings(),
        ];
    }

    /**
     * @param  list<Finding>  $findings
     */
    public function haltsTheWalk(array $findings): bool
    {
        return array_any($findings, static fn (Finding $finding): bool => $finding->halts);
    }

    public function haltedMessage(): string
    {
        return sprintf('Stopping here: every other check depends on this one. Apply its fix, then run "%s" again.', $this->doctorCommand());
    }

    public function supportMessage(): ?string
    {
        if ($this->project->supportContact === null) {
            return null;
        }

        return sprintf('Still stuck after the fixes above? Ask %s, with the summary below.', $this->project->supportContact);
    }

    /**
     * @param  list<Finding>  $findings
     */
    public function worstSeverity(array $findings): FindingSeverity
    {
        $worst = FindingSeverity::Ok;

        foreach ($findings as $finding) {
            if ($finding->severity->rank() < $worst->rank()) {
                $worst = $finding->severity;
            }
        }

        return $worst;
    }

    /**
     * @param  list<Finding>  $findings
     * @return list<Finding>
     */
    public function actionable(array $findings): array
    {
        $actionable = array_values(array_filter($findings, static fn (Finding $finding): bool => $finding->needsAction()));

        usort($actionable, static fn (Finding $first, Finding $second): int => $first->severity->rank() <=> $second->severity->rank());

        return $actionable;
    }

    /**
     * @param  list<Finding>  $findings
     */
    public function summary(array $findings): string
    {
        $lines = [sprintf('"%s" reports:', $this->doctorCommand())];

        foreach ($this->actionable($findings) as $finding) {
            $lines[] = $finding->shareLine();
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * @param  list<Finding>  $findings
     */
    public function closingMessage(array $findings): string
    {
        $actionable = $this->actionable($findings);

        if ($actionable === []) {
            return 'Nothing to report: the environment is ready.';
        }

        $blocking = array_filter($actionable, static fn (Finding $finding): bool => $finding->severity === FindingSeverity::Blocking);

        if ($blocking === []) {
            return sprintf(
                '%d point%s to watch, nothing that prevents working today.',
                count($actionable),
                count($actionable) > 1 ? 's' : '',
            );
        }

        return sprintf(
            '%d point%s to fix, %d of them blocking. Each one comes with its fix.',
            count($actionable),
            count($actionable) > 1 ? 's' : '',
            count($blocking),
        );
    }

    /**
     * @return list<Finding>
     */
    private function walkThroughSteps(): array
    {
        $steps = $this->steps();
        $total = count($steps);
        $findings = [];
        $walked = [];
        $scene = $this->display->isAnimated() ? ($this->scene ?? new DoctorScene($this->display)) : null;

        if ($scene !== null) {
            io()->writeln($scene->frame([], $total, 'Getting ready'));
        }

        foreach ($steps as $label => $step) {
            $stepFindings = $step();
            $findings = [...$findings, ...$stepFindings];
            $walked[$label] = $this->worstSeverity($stepFindings);

            $this->announce($scene, $walked, $total, $label);

            if ($this->haltsTheWalk($stepFindings)) {
                break;
            }
        }

        if ($scene !== null) {
            io()->newLine();
            $this->writeLines($this->display->recap($walked, $total));
        }

        if ($this->haltsTheWalk($findings)) {
            io()->newLine();
            $this->writeLines($this->display->paragraph($this->haltedMessage()));
        }

        io()->newLine();

        return $findings;
    }

    /**
     * @param  array<string, FindingSeverity>  $walked
     */
    private function announce(?DoctorScene $scene, array $walked, int $total, string $label): void
    {
        if ($scene === null) {
            io()->writeln($this->display->progressLine(count($walked), $total, $label, $walked[$label]));

            return;
        }

        io()->write(sprintf("\033[%dA\033[0J", $scene->height()));
        io()->writeln($scene->frame(array_values($walked), $total, $label));
    }

    /**
     * @param  list<Finding>  $findings
     */
    private function reportFindings(array $findings): void
    {
        $actionable = $this->actionable($findings);

        io()->writeln($this->display->rule());
        io()->newLine();
        io()->writeln($this->display->indented($this->closingMessage($findings)));
        io()->newLine();

        if ($actionable === []) {
            return;
        }

        $position = 0;

        foreach ([FindingSeverity::Blocking, FindingSeverity::Warning, FindingSeverity::Unknown] as $severity) {
            $group = array_values(array_filter($actionable, static fn (Finding $finding): bool => $finding->severity === $severity));

            if ($group === []) {
                continue;
            }

            io()->writeln($this->display->groupHeading($severity, count($group)));
            io()->newLine();

            foreach ($group as $finding) {
                $position++;
                $this->writeLines($this->display->findingLines($position, $finding));
                io()->newLine();
            }
        }

        $support = $this->supportMessage();

        if ($support !== null) {
            $this->writeLines($this->display->paragraph($support));
            io()->newLine();
            $this->writeLines($this->display->quoted(explode(PHP_EOL, $this->summary($actionable)), 'summary'));
            io()->newLine();
        }

        if ($this->project->troubleshootingDocumentationUrl !== null) {
            io()->writeln($this->display->indented('Troubleshooting: '.$this->project->troubleshootingDocumentationUrl));
            io()->newLine();
        }
    }

    /**
     * @param  list<string>  $lines
     */
    private function writeLines(array $lines): void
    {
        foreach ($lines as $line) {
            io()->writeln($line);
        }
    }

    private function doctorCommand(): string
    {
        return $this->project->task($this->project->tasks->doctor);
    }

    /**
     * @return list<Finding>
     */
    private function dockerFindings(): array
    {
        if (exit_code($this->docker->buildDockerInfoCommand(), context()->withQuiet()) === 0) {
            return [Finding::ok('Docker', 'the engine answers.')];
        }

        return [new Finding(
            subject: 'Docker',
            severity: FindingSeverity::Blocking,
            detail: 'the engine does not answer: nothing else can be checked until it runs.',
            remedy: sprintf('Start the Docker engine (Docker Desktop, OrbStack, Colima, or the docker service on Linux), then run "%s" again.', $this->doctorCommand()),
            effort: 'the time Docker takes to start',
            documentationUrl: $this->project->troubleshootingDocumentationUrl,
            halts: true,
        )];
    }
}
