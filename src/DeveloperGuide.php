<?php

declare(strict_types=1);

namespace Ogmdev\CastorDevtools;

use function Castor\io;

final readonly class DeveloperGuide
{
    private Project $project;

    public function __construct(?Project $project = null)
    {
        $this->project = $project ?? CurrentProject::get();
    }

    /**
     * @return array<string, list<string>>
     */
    public function everydayCommandSections(): array
    {
        return $this->project->guideSections;
    }

    /**
     * @return array<string, string>
     */
    public function documentationLinks(): array
    {
        return $this->project->documentationLinks;
    }

    /**
     * @return list<string>
     */
    public function everydayCommands(): array
    {
        $commands = [];

        foreach ($this->everydayCommandSections() as $sectionCommands) {
            array_push($commands, ...$sectionCommands);
        }

        return $commands;
    }

    public function showEverydayCommands(): void
    {
        io()->title(sprintf('%s developer toolbox', $this->project->name));

        foreach ($this->everydayCommandSections() as $section => $commands) {
            io()->section($section);

            foreach ($commands as $command) {
                io()->writeln(sprintf('  %s', $command));
            }
        }

        if ($this->documentationLinks() !== []) {
            io()->section('Documentation');

            foreach ($this->documentationLinks() as $label => $url) {
                io()->writeln(sprintf('  %-24s %s', $label, $url));
            }
        }

        io()->section('Discover more');
        io()->writeln('  '.$this->project->task('list'));
        io()->writeln('  '.$this->project->task('<command>', '--help'));
    }
}
