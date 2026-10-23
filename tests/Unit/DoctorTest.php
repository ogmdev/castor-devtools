<?php

declare(strict_types=1);

use Ogmdev\CastorDevtools\Checkout;
use Ogmdev\CastorDevtools\DockerCompose;
use Ogmdev\CastorDevtools\Doctor;
use Ogmdev\CastorDevtools\DoctorDisplay;
use Ogmdev\CastorDevtools\DoctorScene;
use Ogmdev\CastorDevtools\Finding;
use Ogmdev\CastorDevtools\FindingSeverity;
use Ogmdev\CastorDevtools\Tasks;

/**
 * @param  array<string, mixed>  $overrides
 * @param  array<string, callable(): list<Finding>>  $steps
 */
function doctor(array $overrides = [], array $steps = [], bool $withDefaultSteps = true): Doctor
{
    $project = sampleProject($overrides);

    return new Doctor($steps, $withDefaultSteps, $project, docker: new DockerCompose($project, Checkout::main('/home/dev/acme')));
}

function blocking(string $subject): Finding
{
    return new Finding($subject, FindingSeverity::Blocking, 'it is down.', remedy: 'Start it.');
}

function warning(string $subject): Finding
{
    return new Finding($subject, FindingSeverity::Warning, 'it is slow.');
}

test('the doctor walks the generic checks first, then the steps the project adds', function (): void {
    $steps = ['Secrets' => fn (): array => [Finding::ok('Secrets', 'present.')]];

    expect(array_keys(doctor(steps: $steps)->steps()))->toBe(['Local subdomains', 'TLS certificates', 'Docker', 'Local ingress', 'Stack vhosts', 'Secrets'])
        ->and(array_keys(doctor(steps: $steps, withDefaultSteps: false)->steps()))->toBe(['Secrets']);
});

test('the closing message counts what to fix and what is blocking', function (): void {
    expect(doctor()->closingMessage([Finding::ok('Docker', 'answers.')]))->toBe('Nothing to report: the environment is ready.')
        ->and(doctor()->closingMessage([warning('Disk')]))->toBe('1 point to watch, nothing that prevents working today.')
        ->and(doctor()->closingMessage([warning('Disk'), blocking('Docker'), blocking('Ingress')]))->toBe('3 points to fix, 2 of them blocking. Each one comes with its fix.');
});

test('blocking findings come first, and the worst severity decides the exit code', function (): void {
    $findings = [warning('Disk'), Finding::ok('Hosts', 'declared.'), blocking('Docker')];

    expect(array_map(static fn (Finding $finding): string => $finding->subject, doctor()->actionable($findings)))->toBe(['Docker', 'Disk'])
        ->and(doctor()->worstSeverity($findings))->toBe(FindingSeverity::Blocking)
        ->and(doctor()->worstSeverity([warning('Disk')]))->toBe(FindingSeverity::Warning);
});

test('the summary and the halt message name the doctor task the project configured', function (): void {
    $doctor = doctor(['tasks' => new Tasks(executable: './castor', doctor: 'dev:sos')]);

    expect($doctor->summary([blocking('Docker'), Finding::ok('Hosts', 'declared.')]))->toBe('"./castor dev:sos" reports:'.PHP_EOL.'- [blocking] Docker: it is down.')
        ->and($doctor->haltedMessage())->toContain('"./castor dev:sos"');
});

test('a support contact is only mentioned when the project names one', function (): void {
    expect(doctor()->supportMessage())->toBeNull()
        ->and(doctor(['supportContact' => 'the #platform channel'])->supportMessage())->toBe('Still stuck after the fixes above? Ask the #platform channel, with the summary below.');
});

test('a finding card lists only the entries it has, with generic labels', function (): void {
    $display = new DoctorDisplay(width: 60);
    $finding = new Finding('Docker', FindingSeverity::Blocking, 'it is down.', remedy: 'Start it.', humanHelp: 'the platform team', documentationUrl: 'https://example.test/faq');

    $lines = $display->findingLines(1, $finding);

    expect(array_keys($display->entries($finding)))->toBe(['Finding', 'Fix', 'Ask', 'Docs'])
        ->and($lines[0])->toContain('┌', 'Docker')
        ->and($lines[count($lines) - 1] ?? '')->toStartWith('  └');
});

test('the elephant walks along the road and leaves one footprint per check, coloured by its result', function (): void {
    $scene = new DoctorScene(new DoctorDisplay(width: 60));
    $start = explode(PHP_EOL, $scene->frame([], 4, 'Getting ready'));
    $halfway = explode(PHP_EOL, $scene->frame([FindingSeverity::Ok, FindingSeverity::Blocking], 4, 'Docker'));
    $done = explode(PHP_EOL, $scene->frame([FindingSeverity::Ok, FindingSeverity::Ok, FindingSeverity::Warning, FindingSeverity::Ok], 4, 'Stack vhosts'));

    expect($start)->toHaveCount($scene->height())
        ->and($start[2])->toBe('   /  o \__')
        ->and($halfway[0])->toBe('  Docker')
        ->and($halfway[4])->toContain('<fg=green>o</>', '<fg=red;options=bold>o</>')
        ->and(strpos($halfway[2], '/') > strpos($start[2], '/'))->toBeTrue()
        ->and($halfway[6])->toBe('  [<fg=green>#</><fg=red;options=bold>#</>..]  2/4 checking')
        ->and($done[1])->toEndWith('_)')
        ->and($done[6])->toEndWith('4/4 every check done!');
});

test('the elephant alternates its legs while walking and never leaves the road', function (): void {
    $scene = new DoctorScene(new DoctorDisplay(width: 48));

    expect($scene->pose(0, 3)[3])->not()->toBe($scene->pose(1, 3)[3])
        ->and($scene->elephantColumn(30, 30) + 9)->toBeLessThanOrEqual($scene->roadWidth())
        ->and(array_filter(array_merge(...array_map(static fn (int $step): array => $scene->pose($step, 3), [0, 1, 3])), static fn (string $line): bool => preg_match('/[^\x20-\x7E]/', $line) === 1))->toBe([]);
});
