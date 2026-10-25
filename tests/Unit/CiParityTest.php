<?php

declare(strict_types=1);

/*
 * Holds `.github/workflows/ci.yml` and `local-ci.json` against each other.
 *
 * The two are hand-maintained mirrors with no shared source of truth: one is the
 * job list GitHub executes, the other the job list the shared local CI runner
 * (`.claude/skills/local-ci/local-ci.mjs`) executes (#366). They are not
 * byte-comparable, and local-ci.json carries local-only jobs with no
 * counterpart on a fresh-checkout runner, named in ciLocalOnlyJobs().
 *
 * What CAN be compared mechanically is compared here, because the alternative is
 * a mirror that drifts in silence. `.githooks/pre-commit` insists the two files
 * change together; this insists the parts that must agree still do.
 */

/**
 * The workflow source.
 */
function ciWorkflowSource(): string
{
    $path = \dirname(__DIR__, 2).'/.github/workflows/ci.yml';

    expect($path)->toBeFile();

    return (string) file_get_contents($path);
}

/**
 * The shared runner's configuration for this repository, with its jobs.
 *
 * @return array{config: array<mixed>, jobs: list<array<mixed>>, names: list<string>}
 */
function ciLocalConfig(): array
{
    $path = \dirname(__DIR__, 2).'/local-ci.json';

    expect($path)->toBeFile();

    $config = json_decode((string) file_get_contents($path), true);

    // A throw rather than a failed expectation: every test below reads a job
    // list out of this, and an unreadable file returning [] would make them all
    // pass by comparing nothing to nothing.
    if (! \is_array($config) || ! isset($config['jobs']) || ! \is_array($config['jobs']) || $config['jobs'] === []) {
        throw new RuntimeException('local-ci.json is not JSON with a non-empty "jobs" list.');
    }

    $jobs = [];
    $names = [];

    foreach ($config['jobs'] as $job) {
        if (! \is_array($job) || ! isset($job['name']) || ! \is_string($job['name'])) {
            throw new RuntimeException('local-ci.json has a job with no string "name".');
        }

        $jobs[] = $job;
        $names[] = $job['name'];
    }

    return ['config' => $config, 'jobs' => $jobs, 'names' => $names];
}

/**
 * The jobs local-ci.json runs, in order, by name.
 *
 * @return list<string>
 */
function ciLocalChain(): array
{
    return ciLocalConfig()['names'];
}

/**
 * Jobs that run only locally, each because a fresh Actions checkout has nothing
 * for it to do or nothing to protect.
 *
 * @return list<string>
 */
function ciLocalOnlyJobs(): array
{
    return [
        // Installs into the runner's reused slot; Actions installs cold every job.
        'dependencies',
        // A hand edit to a synced file; the check needs only node, and the
        // remote chain was never asked to carry it.
        'synced-files',
        // The handed-back workflow against its shared template (UAMS-Web/uams-claude-skills#57).
        'handed-back-workflow',
        // What the GitHub download holds under the .gitattributes keep-list
        // (#439); the download-archive-check skill runs it locally in a
        // private repository.
        'download-archive',
    ];
}

/**
 * Every job in the workflow, in file order, with its body.
 *
 * A job key is the only thing indented exactly two spaces under `jobs:`;
 * everything a job contains sits at four or more.
 *
 * @return array<string, string>
 */
function ciWorkflowJobs(): array
{
    $jobs = [];
    $current = null;
    $body = [];
    $inJobs = false;

    foreach (explode("\n", ciWorkflowSource()) as $line) {
        if ($line === 'jobs:') {
            $inJobs = true;

            continue;
        }

        if (! $inJobs) {
            continue;
        }

        if (preg_match('/^  ([a-z0-9-]+):$/', $line, $matches) === 1) {
            if ($current !== null) {
                $jobs[$current] = implode("\n", $body);
            }

            $current = $matches[1];
            $body = [];

            continue;
        }

        if ($current !== null) {
            $body[] = $line;
        }
    }

    if ($current !== null) {
        $jobs[$current] = implode("\n", $body);
    }

    return $jobs;
}

/**
 * The id of the aggregate job the ruleset requires as `CI` (D-105).
 */
function ciAggregateJob(): string
{
    return 'ci';
}

/**
 * Every workflow job except the aggregate: the chain, each one gated and marked.
 *
 * @return array<string, string>
 */
function ciChainedJobs(): array
{
    $jobs = ciWorkflowJobs();

    // A throw rather than a filter that finds nothing: without the aggregate,
    // the aggregate test below would have nothing to read, and this list would
    // quietly equal every job.
    if (! isset($jobs[ciAggregateJob()])) {
        throw new RuntimeException('ci.yml has no `'.ciAggregateJob().'` job.');
    }

    unset($jobs[ciAggregateJob()]);

    return $jobs;
}

/**
 * The workflow's chain, derived from the `needs:` graph rather than from file
 * order — order in the file is presentation; `needs:` is what GitHub executes.
 *
 * @return list<string>
 */
function ciWorkflowChain(): array
{
    $needs = [];

    foreach (ciChainedJobs() as $job => $body) {
        $needs[$job] = preg_match('/^    needs: (\S+)$/m', $body, $matches) === 1 ? $matches[1] : null;
    }

    // Exactly one head — a job with no `needs:`. A second one is a job that runs
    // concurrently with the chain instead of behind it, which is how an
    // expensive job starts billing even when a cheap one has already failed.
    $heads = array_keys(array_filter($needs, static fn (?string $need): bool => $need === null));

    expect($heads)->toHaveCount(1);

    $chain = [$heads[0] ?? ''];

    while (true) {
        $last = $chain[\count($chain) - 1];
        $next = array_keys(array_filter($needs, static fn (?string $need): bool => $need === $last));

        if ($next === []) {
            break;
        }

        // A fan-out is not a chain, and a cycle would loop here forever.
        expect(\count($next))->toBe(1)
            ->and(\in_array($next[0], $chain, true))->toBeFalse();

        $chain[] = $next[0];
    }

    return $chain;
}

it('gates every workflow job on the RUN_REMOTE_CI kill-switch', function (): void {
    // The switch is the whole budget story: Actions minutes bill at the
    // organization level, and a single job missing the gate keeps billing after
    // somebody flips it off — which reads as "the switch does not work".
    // Collected rather than asserted per job: `toContain()` is variadic, so a
    // trailing message string would be read as a second needle — and the
    // offender list is what a failure needs to name anyway.
    $missingGate = [];

    foreach (ciChainedJobs() as $job => $body) {
        if (! str_contains($body, "if: vars.RUN_REMOTE_CI != 'false'")) {
            $missingGate[] = $job;
        }
    }

    expect($missingGate)->toBe([]);
});

it('chains the workflow jobs in one strict sequence', function (): void {
    // A cost-ordered chain is what makes a cheap failure skip the expensive
    // jobs rather than run them in parallel and bill for all of them. A job
    // added without `needs:` silently becomes a second head and runs
    // concurrently — passing, and costing, regardless of what failed.
    expect(ciWorkflowChain())->toHaveCount(\count(ciChainedJobs()));
});

it('aggregates every chained job into the required CI check', function (): void {
    // The ruleset requires `CI` alone. A job missing from its `needs:` list is
    // required by nothing, which reads as a gate and is not one; a gated
    // aggregate would be skipped with the chain and report success (D-101).
    $body = ciWorkflowJobs()[ciAggregateJob()];

    preg_match('/^    needs:\n((?:      - \S+\n?)+)/m', $body, $matches);
    preg_match_all('/^      - (\S+)$/m', $matches[1] ?? '', $needed);

    expect($body)->toMatch('/^    name: CI$/m')
        ->and($body)->toMatch('/^    if: always\(\)$/m')
        ->and($body)->not->toContain('RUN_REMOTE_CI')
        ->and($needed[1])->toBe(ciWorkflowChain());
});

it('runs the same jobs, in the same order, locally and remotely', function (): void {
    // The one assertion that actually catches drift: a job added to ci.yml and
    // not to the runner is a job the local gate never runs, and while
    // RUN_REMOTE_CI is off the local gate is the only gate there is.
    $shared = array_values(array_diff(ciLocalChain(), ciLocalOnlyJobs()));

    expect($shared)->toBe(ciWorkflowChain());
});

it('names every local-only job, and no workflow job as local-only', function (): void {
    // A stale entry in ciLocalOnlyJobs() would excuse a job the runner no
    // longer has; a workflow job listed there would vanish from the comparison.
    expect(array_values(array_diff(ciLocalOnlyJobs(), ciLocalChain())))->toBe([])
        ->and(array_values(array_intersect(ciLocalOnlyJobs(), ciWorkflowChain())))->toBe([]);
});

it('runs every local job on every run, stopping at the first failure', function (): void {
    // The shared runner selects jobs from the diff by default. A job whose
    // inputs a diff touches but whose paths do not name them is skipped, and
    // with remote CI off nothing else catches it, so every job here is always
    // selected; `chain` mirrors the workflow's `needs:` chain.
    $local = ciLocalConfig();
    $selective = [];

    foreach ($local['jobs'] as $job) {
        if (($job['always'] ?? false) !== true) {
            $selective[] = $job['name'];
        }
    }

    expect($selective)->toBe([])
        ->and($local['config']['chain'] ?? false)->toBeTrue();
});

it('never skips a job for want of what the dependencies job installs', function (): void {
    // The shared runner checks `requires` before any job runs, so before the
    // dependencies job has run `npm ci`. A job requiring node_modules is then
    // SKIPPED on a fresh slot and the run exits 0 as "PASS (incomplete)". And
    // `prepare` runs before any job too, so prepare.patches would reinstall
    // the slot's old package versions against the commit's patches; the
    // dependencies job re-syncs patches after it installs instead.
    $local = ciLocalConfig();
    $needsInstall = [];

    foreach ($local['jobs'] as $job) {
        $requires = $job['requires'] ?? [];

        if (\is_array($requires) && (\in_array('node_modules', $requires, true) || \in_array('vendor', $requires, true))) {
            $needsInstall[] = $job['name'];
        }
    }

    $prepare = $local['config']['prepare'] ?? [];

    expect($needsInstall)->toBe([])
        ->and(\is_array($prepare) ? ($prepare['patches'] ?? false) : false)->toBeFalse();
});

it('marks the parity-critical run step of every job', function (): void {
    // The markers are how a person editing one file finds the other's
    // counterpart. Unmarked, the mirror is only discoverable by reading both
    // files end to end, which is exactly what does not happen under time
    // pressure.
    $unmarked = [];

    foreach (ciChainedJobs() as $job => $body) {
        if (! str_contains($body, '# PARITY:')) {
            $unmarked[] = $job;
        }
    }

    expect($unmarked)->toBe([]);
});

it('keeps the workflow header documenting the chain it actually runs', function (): void {
    // The header spells the chain out in prose for a reader. Prose goes stale
    // silently, and a stale chain diagram is worse than none: it is read as
    // authoritative.
    expect(ciWorkflowSource())->toContain(implode(' -> ', ciWorkflowChain()));
});

it('pins the same PHP version on both sides', function (): void {
    // A local runner on a different PHP than CI produces a green that does not
    // transfer. The shared runner pins no Node version, so there is no Node pin
    // to compare.
    $php = ciLocalConfig()['config']['php'] ?? null;

    expect($php)->toBe('8.4')
        // The development PHP: Pest 5 requires 8.4, and every PHPUnit 13
        // release needs 8.4.1, which `config.platform.php` names (#375).
        ->and(ciWorkflowSource())->toContain("php-version: '8.4'");
});

it('lints every shipped file on the PHP 8.2 floor on both sides', function (): void {
    // No test runs on the floor, so this job and `floor-functions` are the only
    // things that execute PHP 8.2 at all (#375). A floor job that quietly ran on
    // 8.4 would pass every construct the floor cannot parse.
    $lint = null;

    foreach (ciLocalConfig()['jobs'] as $job) {
        if ($job['name'] === 'lint-floor') {
            $lint = $job;
        }
    }

    $workflow = ciWorkflowJobs()['lint-floor'] ?? '';

    expect($lint)->toBeArray()
        ->and(\is_array($lint) ? ($lint['php'] ?? null) : null)->toBe('8.2')
        ->and(\is_array($lint) ? ($lint['run'] ?? null) : null)->toBe('php -l')
        ->and($workflow)->toContain("php-version: '8.2'")
        ->and($workflow)->toContain('php -l');
});

it('checks shipped calls against the PHP 8.2 floor on both sides', function (): void {
    // The guard asks the running PHP whether it has each polyfilled function,
    // so on 8.4 it would pass every 8.4 function the floor lacks (#399). The
    // script refuses to run on any other PHP; this holds the pin that keeps it
    // from refusing on every run.
    $guard = null;

    foreach (ciLocalConfig()['jobs'] as $job) {
        if ($job['name'] === 'floor-functions') {
            $guard = $job;
        }
    }

    $workflow = ciWorkflowJobs()['floor-functions'] ?? '';

    expect($guard)->toBeArray()
        ->and(\is_array($guard) ? ($guard['php'] ?? null) : null)->toBe('8.2')
        ->and(\is_array($guard) ? ($guard['run'] ?? null) : null)->toBe('php scripts/check-floor-functions.php')
        ->and($workflow)->toContain("php-version: '8.2'")
        ->and($workflow)->toContain('php scripts/check-floor-functions.php');
});
