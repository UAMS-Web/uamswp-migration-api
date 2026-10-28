<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * Pins `.github/workflows/assign-author.yml` (#332), which assigns each pull
 * request, and every issue it closes, to exactly the pull request's author.
 *
 * The shape tests pin what the job runs on: `opened` and a merged `closed`,
 * never a bot's pull request, never gated on `RUN_REMOTE_CI`, and only the two
 * scopes it writes with. The behavior tests run the step's own script, read
 * from the workflow, against a stub `gh` that records every call. The real `jq`
 * filters run, so a changed filter is exercised rather than trusted. Each
 * failure the script must report shows here as a non-zero exit: a dropped
 * assignee, a failed write, a GraphQL error and a truncated page.
 *
 * The workflow is read as text rather than parsed, as `CiParityTest` reads
 * `ci.yml`: this repository carries no YAML parser, and adding one for a test
 * is a dependency change. The reader is strict about indentation, so a
 * reshaped file fails here rather than being read loosely. Every behavior test
 * also depends on the extracted script, so a reader that extracted the wrong
 * lines fails those too.
 *
 * Ported from `uams-statamic`'s `AssignAuthorWorkflowTest` (UAMS-Web/uams-statamic-archive#2685),
 * with the same cases.
 */

/**
 * Pull request number every behavior case uses.
 */
const ASSIGN_AUTHOR_PR = '4242';

/**
 * Repository every behavior case uses.
 */
const ASSIGN_AUTHOR_REPO = 'UAMS-Web/uamswp-migration-api';

/**
 * SHA-256 of the workflow from its `on:` line to the end.
 *
 * The file above `on:` is the header, the one part the three copies may word
 * differently. Everything below it is kept identical across `uams-statamic`,
 * `wordpress-importer` and this repository, so a one-sided edit changes this
 * hash and fails here, before the copies can drift apart.
 */
const ASSIGN_AUTHOR_BODY_SHA256 = '83679189f8977da63396f0dac3d28849c628371ea6a894c443830c2729b10b17';

/**
 * The workflow's source, or another file's when a path is given.
 */
function assignAuthorSource(?string $path = null): string
{
    $path ??= dirname(__DIR__, 2).'/.github/workflows/assign-author.yml';

    // Fail with the reason rather than on an empty read
    if (! is_file($path)) {
        throw new RuntimeException('assign-author.yml is missing');
    }

    return str_replace("\r\n", "\n", (string) file_get_contents($path));
}

/**
 * The workflow's lines that are neither blank nor a comment.
 *
 * @return list<string>
 */
function assignAuthorCodeLines(?string $path = null): array
{
    return array_values(array_filter(
        explode("\n", assignAuthorSource($path)),
        fn (string $line): bool => trim($line) !== '' && ! str_starts_with(ltrim($line), '#'),
    ));
}

/**
 * The workflow's top-level keys, each with its code lines.
 *
 * @return array<string, list<string>>
 */
function assignAuthorBlocks(): array
{
    return assignAuthorBlocksOf(null);
}

/**
 * A workflow file's top-level keys, each with its code lines.
 *
 * @return array<string, list<string>>
 */
function assignAuthorBlocksOf(?string $path): array
{
    $blocks = [];
    $key = null;

    foreach (assignAuthorCodeLines($path) as $line) {
        // A line at column zero opens a new top-level key
        if (preg_match('/^([a-z_-]+):/', $line, $matches) === 1) {
            $key = $matches[1];

            if (isset($blocks[$key])) {
                throw new RuntimeException("assign-author.yml declares '{$key}' twice");
            }

            $blocks[$key] = [$line];

            continue;
        }

        // Anything else at column zero is a key in a form this reader does not accept, such as a quoted one
        if (! str_starts_with($line, ' ')) {
            throw new RuntimeException("assign-author.yml has a top-level line this test cannot read: {$line}");
        }

        if ($key === null) {
            throw new RuntimeException("assign-author.yml has a line before any key: {$line}");
        }

        $blocks[$key][] = $line;
    }

    return $blocks;
}

/**
 * The code lines of the one job, with the job's own key line dropped.
 *
 * @return list<string>
 */
function assignAuthorJob(): array
{
    $jobs = assignAuthorBlocks()['jobs'] ?? [];
    $names = preg_grep('/^  [a-z0-9_-]+:$/', $jobs);

    // Require exactly one job, so a second, unguarded one cannot slip in
    if ($names === false || count($names) !== 1) {
        throw new RuntimeException('assign-author.yml must declare exactly one job');
    }

    return array_slice($jobs, 2);
}

/**
 * The job's `if:`, folded as YAML folds `>-`: its lines joined by single spaces.
 */
function assignAuthorCondition(): string
{
    $job = assignAuthorJob();
    $start = array_search('    if: >-', $job, true);

    if ($start === false) {
        throw new RuntimeException('assign-author.yml job has no folded if: condition');
    }

    $parts = [];

    foreach (array_slice($job, $start + 1) as $line) {
        if (! str_starts_with($line, '      ')) {
            break;
        }

        $parts[] = trim($line);
    }

    return implode(' ', $parts);
}

/**
 * The job's `permissions:`, as scope => access.
 *
 * @return array<string, string>
 */
function assignAuthorJobPermissions(): array
{
    $job = assignAuthorJob();
    $start = array_search('    permissions:', $job, true);

    if ($start === false) {
        throw new RuntimeException('assign-author.yml job declares no permissions');
    }

    $permissions = [];

    foreach (array_slice($job, $start + 1) as $line) {
        if (preg_match('/^      ([a-z-]+): (\S+)$/', $line, $matches) !== 1) {
            break;
        }

        $permissions[$matches[1]] = $matches[2];
    }

    return $permissions;
}

/**
 * The step's shell script, read from the `run: |` block with its indentation removed.
 */
function assignAuthorScript(): string
{
    $steps = preg_grep('/^      - /', assignAuthorJob());

    // Require exactly one step
    if ($steps === false || count($steps) !== 1) {
        throw new RuntimeException('assign-author.yml must declare exactly one step');
    }

    // Read from the source, since the script's own blank lines and comments are part of it
    $lines = explode("\n", assignAuthorSource());
    $start = array_search('        run: |', $lines, true);

    if ($start === false) {
        throw new RuntimeException('assign-author.yml step has no literal run: block');
    }

    $script = [];

    foreach (array_slice($lines, $start + 1) as $line) {
        if ($line === '') {
            $script[] = '';

            continue;
        }

        if (! str_starts_with($line, '          ')) {
            break;
        }

        $script[] = substr($line, 10);
    }

    return rtrim(implode("\n", $script))."\n";
}

/**
 * Why the behavior tests cannot run here, or null when they can.
 */
function assignAuthorToolsMissing(): ?string
{
    $probe = new Process(['bash', '-c', 'command -v jq']);

    try {
        $probe->run();
    } catch (Throwable) {
        return 'bash is not available';
    }

    // 127 is the shell's own "command not found", which here means bash itself
    return match ($probe->getExitCode()) {
        0 => null,
        127 => 'bash is not available',
        default => 'jq is not available',
    };
}

/**
 * Remove a scratch directory and everything in it.
 */
function assignAuthorRemove(string $directory): void
{
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($entries as $entry) {
        assert($entry instanceof SplFileInfo);
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($directory);
}

/**
 * Run the step's script against a stub `gh`.
 *
 * The stub logs each call's arguments, one call per line, to `calls.log`. A
 * `PATCH` answers with the assignee it was sent, unless `drop` exists (the
 * answer GitHub gives for a login it cannot assign), `keep` exists (the
 * author added beside an assignee who was already there) or `fail-patch`
 * exists (a non-zero exit). A GraphQL call prints `closing.json`, or exits non-zero when
 * `fail-graphql` exists.
 *
 * @param  array<string, string>  $env  Event values overriding the opened-by-a-user defaults
 * @param  array<string, string>  $files  Stub control files to create, by name
 * @return array{exit: int|null, output: string, calls: list<string>} The run's exit code, output and logged calls
 */
function runAssignAuthor(array $env = [], array $files = []): array
{
    $directory = sys_get_temp_dir().'/assign-author-test-'.bin2hex(random_bytes(6));
    mkdir($directory.'/bin', 0777, true);

    // Write the stub, which answers from the control files beside it
    file_put_contents($directory.'/bin/gh', <<<'SH'
        #!/bin/bash
        dir="$(cd "$(dirname "$0")/.." && pwd)"
        printf '%s\n' "$*" >> "$dir/calls.log"
        if [ "$1 $2" = "api graphql" ]; then
          if [ -e "$dir/fail-graphql" ]; then
            exit 1
          fi
          cat "$dir/closing.json"
          exit 0
        fi
        if [ -e "$dir/fail-patch" ]; then
          exit 1
        fi
        for arg in "$@"; do
          if [ "${arg#assignees[]=}" != "$arg" ]; then
            login="${arg#assignees[]=}"
          fi
        done
        if [ -e "$dir/drop" ]; then
          echo '{"assignees":[{"login":"someone-else"}]}'
        elif [ -e "$dir/keep" ]; then
          printf '{"assignees":[{"login":"%s"},{"login":"old-default"}]}\n' "$login"
        else
          printf '{"assignees":[{"login":"%s"}]}\n' "$login"
        fi
        SH);
    chmod($directory.'/bin/gh', 0755);

    // Shadow jq with the real one minus carriage returns: jq.exe on Windows ends each
    // output line with \r\n, which a runner's jq does not, and `read` keeps the \r. The
    // shim drops its own directory, the first PATH entry, to reach the real jq, and
    // pipefail keeps jq's exit status for the script's `jq -e` checks
    file_put_contents($directory.'/bin/jq', <<<'SH'
        #!/bin/bash
        set -o pipefail
        PATH="${PATH#*:}" jq "$@" | tr -d '\r'
        SH);
    chmod($directory.'/bin/jq', 0755);

    // Default to a pull request that closes nothing
    $files += ['closing.json' => '{"data":{"repository":{"pullRequest":{"closingIssuesReferences":{"totalCount":0,"nodes":[]}}}}}'];

    // Create the control files this case asks for
    foreach ($files as $name => $contents) {
        file_put_contents($directory.'/'.$name, $contents);
    }

    // Run the script as the runner does, with the stub first on PATH
    $process = new Process(['bash', '-e', '-c', assignAuthorScript()], null, [
        'PATH' => $directory.'/bin'.PATH_SEPARATOR.getenv('PATH'),
        'RUNNER_TEMP' => $directory,
        'GH_TOKEN' => 'stub',
        'REPO' => ASSIGN_AUTHOR_REPO,
        'NUMBER' => ASSIGN_AUTHOR_PR,
        'AUTHOR' => 'the-author',
        'AUTHOR_TYPE' => 'User',
        'ACTION' => 'opened',
        'MERGED' => 'false',
        ...$env,
    ]);
    $process->run();

    // Read the calls before removing the scratch directory
    $log = is_file($directory.'/calls.log') ? (string) file_get_contents($directory.'/calls.log') : '';
    $calls = array_values(array_filter(explode("\n", $log), fn (string $line): bool => $line !== ''));

    assignAuthorRemove($directory);

    return [
        'exit' => $process->getExitCode(),
        'output' => $process->getOutput().$process->getErrorOutput(),
        'calls' => $calls,
    ];
}

/**
 * The stub-log line an assignment of one number writes.
 */
function assignAuthorPatch(string $number): string
{
    return 'api -X PATCH repos/'.ASSIGN_AUTHOR_REPO.'/issues/'.$number.' -f assignees[]=the-author';
}

/**
 * A GraphQL answer naming the given issues.
 *
 * @param  list<array{0: string, 1: int}>  $issues  Repository and number of each closed issue
 * @param  int|null  $totalCount  Total to report, defaulting to the number of issues
 */
function assignAuthorClosing(array $issues, ?int $totalCount = null): string
{
    $nodes = array_map(
        fn (array $issue): array => ['number' => $issue[1], 'repository' => ['nameWithOwner' => $issue[0]]],
        $issues,
    );

    return (string) json_encode(['data' => ['repository' => ['pullRequest' => ['closingIssuesReferences' => [
        'totalCount' => $totalCount ?? count($nodes),
        'nodes' => $nodes,
    ]]]]]);
}

it('refuses a top-level key it cannot read rather than folding it into the key above', function (string $key): void {
    // A quoted key, or one with a space before its colon, is still a key to GitHub
    $source = str_replace("\non:\n", "\n{$key}\non:\n", assignAuthorSource());
    $path = tempnam(sys_get_temp_dir(), 'assign-author-');
    file_put_contents((string) $path, $source);

    try {
        expect(fn (): array => assignAuthorBlocksOf((string) $path))->toThrow(RuntimeException::class, 'top-level line this test cannot read');
    } finally {
        unlink((string) $path);
    }
})->with([
    'quoted' => ['"defaults": {run: {shell: "bash -c true {0}"}}'],
    'space before colon' => ['concurrency : x'],
]);

it('declares only a name, its triggers, its permissions and its jobs', function (): void {
    expect(array_keys(assignAuthorBlocks()))->toBe(['name', 'on', 'permissions', 'jobs']);
});

it('triggers on a pull request opening or closing and on nothing else', function (): void {
    expect(assignAuthorBlocks()['on'])->toBe(['on:', '  pull_request:', '    types: [opened, closed]']);
});

it('runs its job only for a user on an opened or merged pull request', function (): void {
    expect(assignAuthorCondition())->toBe(
        "github.event.pull_request.user.type != 'Bot' && (github.event.action == 'opened' || (github.event.action == 'closed' && github.event.pull_request.merged == true))"
    );
});

it('is not gated on RUN_REMOTE_CI', function (): void {
    // Search only the YAML itself, since the header comment names the variable to explain its absence
    expect(implode("\n", assignAuthorCodeLines()))->not->toContain('RUN_REMOTE_CI');
});

it('grants no permission beyond writing issues and pull requests', function (): void {
    expect(assignAuthorBlocks()['permissions'])->toBe(['permissions: {}'])
        ->and(assignAuthorJobPermissions())->toBe(['issues' => 'write', 'pull-requests' => 'write']);
});

it('keeps everything below its header identical to the copies in the other two repositories', function (): void {
    $source = assignAuthorSource();
    $body = substr($source, (int) strpos($source, "\non:\n") + 1);

    expect(str_contains($source, "\non:\n"))->toBeTrue()
        ->and(hash('sha256', $body))->toBe(ASSIGN_AUTHOR_BODY_SHA256);
});

it('reads the whole step script out of the workflow', function (): void {
    $script = assignAuthorScript();

    // Both ends, so a reader that stopped early or started late fails here by name
    expect($script)->toStartWith("set -euo pipefail\n")
        ->and($script)->toEndWith("assign \"\$NUMBER\"\n");
});

it('assigns an opened pull request to exactly its author', function (): void {
    $run = runAssignAuthor();

    expect($run['exit'])->toBe(0)
        ->and($run['calls'])->toBe([assignAuthorPatch(ASSIGN_AUTHOR_PR)]);
})->skip(fn (): bool => assignAuthorToolsMissing() !== null, 'needs bash and jq');

it('assigns a merged pull request and the issues it closes in this repository', function (): void {
    $run = runAssignAuthor(
        ['ACTION' => 'closed', 'MERGED' => 'true'],
        ['closing.json' => assignAuthorClosing([
            [ASSIGN_AUTHOR_REPO, 11],
            ['UAMS-Web/wordpress-importer', 5],
            [ASSIGN_AUTHOR_REPO, 12],
        ])],
    );

    expect($run['exit'])->toBe(0)
        ->and(array_slice($run['calls'], 1))->toBe([
            assignAuthorPatch('11'),
            assignAuthorPatch('12'),
            assignAuthorPatch(ASSIGN_AUTHOR_PR),
        ])
        ->and($run['calls'][0] ?? '')->toStartWith('api graphql')
        ->and($run['output'])->toContain('Skipping UAMS-Web/wordpress-importer#5');
})->skip(fn (): bool => assignAuthorToolsMissing() !== null, 'needs bash and jq');

it('assigns only a merged pull request that closes nothing', function (): void {
    $run = runAssignAuthor(['ACTION' => 'closed', 'MERGED' => 'true']);

    expect($run['exit'])->toBe(0)
        ->and(array_slice($run['calls'], 1))->toBe([assignAuthorPatch(ASSIGN_AUTHOR_PR)]);
})->skip(fn (): bool => assignAuthorToolsMissing() !== null, 'needs bash and jq');

it('changes nothing for a pull request closed without merging', function (): void {
    $run = runAssignAuthor(
        ['ACTION' => 'closed', 'MERGED' => 'false'],
        ['closing.json' => assignAuthorClosing([[ASSIGN_AUTHOR_REPO, 11]])],
    );

    expect($run['exit'])->toBe(0)
        ->and($run['calls'])->toBe([]);
})->skip(fn (): bool => assignAuthorToolsMissing() !== null, 'needs bash and jq');

it('changes nothing for a bot author', function (): void {
    $run = runAssignAuthor(['AUTHOR_TYPE' => 'Bot']);

    expect($run['exit'])->toBe(0)
        ->and($run['calls'])->toBe([]);
})->skip(fn (): bool => assignAuthorToolsMissing() !== null, 'needs bash and jq');

it('fails when the answer shows a different assignee', function (): void {
    // GitHub answers 200 without the login it could not assign
    $run = runAssignAuthor([], ['drop' => '']);

    expect($run['exit'])->not->toBe(0)
        ->and($run['output'])->toContain("::error::#4242 is assigned to 'someone-else'");
})->skip(fn (): bool => assignAuthorToolsMissing() !== null, 'needs bash and jq');

it('fails when the author was added beside an assignee instead of replacing them', function (): void {
    // A membership check would pass this answer; only an exact comparison enforces replace, not add
    $run = runAssignAuthor([], ['keep' => '']);

    expect($run['exit'])->not->toBe(0)
        ->and($run['output'])->toContain("::error::#4242 is assigned to 'the-author,old-default'");
})->skip(fn (): bool => assignAuthorToolsMissing() !== null, 'needs bash and jq');

it('fails when an assignment call fails', function (): void {
    $run = runAssignAuthor([], ['fail-patch' => '']);

    expect($run['exit'])->not->toBe(0)
        ->and($run['output'])->toContain('::error::Assigning #4242 to the-author failed.');
})->skip(fn (): bool => assignAuthorToolsMissing() !== null, 'needs bash and jq');

it('fails without assigning anything when the closing references cannot be read', function (): void {
    $run = runAssignAuthor(
        ['ACTION' => 'closed', 'MERGED' => 'true'],
        ['closing.json' => '{"errors":[{"message":"rate limited"}],"data":null}'],
    );

    expect($run['exit'])->not->toBe(0)
        ->and($run['calls'])->toHaveCount(1)
        ->and($run['output'])->toContain('returned no closing references');
})->skip(fn (): bool => assignAuthorToolsMissing() !== null, 'needs bash and jq');

it('fails without assigning anything when the closing references are truncated', function (): void {
    $run = runAssignAuthor(
        ['ACTION' => 'closed', 'MERGED' => 'true'],
        ['closing.json' => assignAuthorClosing([[ASSIGN_AUTHOR_REPO, 11]], 101)],
    );

    expect($run['exit'])->not->toBe(0)
        ->and($run['calls'])->toHaveCount(1)
        ->and($run['output'])->toContain('closes more issues than could be read');
})->skip(fn (): bool => assignAuthorToolsMissing() !== null, 'needs bash and jq');

it('fails without assigning anything when the closing references call fails', function (): void {
    $run = runAssignAuthor(['ACTION' => 'closed', 'MERGED' => 'true'], ['fail-graphql' => '']);

    expect($run['exit'])->not->toBe(0)
        ->and($run['calls'])->toHaveCount(1)
        ->and($run['output'])->toContain('::error::Reading the issues #4242 closes failed.');
})->skip(fn (): bool => assignAuthorToolsMissing() !== null, 'needs bash and jq');

it('asks for the first page of closing references by owner, name and integer number', function (): void {
    $run = runAssignAuthor(['ACTION' => 'closed', 'MERGED' => 'true']);

    // The stub answers any query, so the query text itself is what this pins
    expect($run['calls'][0] ?? '')
        ->toContain('query($owner: String!, $name: String!, $number: Int!)')
        ->toContain('pullRequest(number: $number) { closingIssuesReferences(first: 100) { totalCount nodes { number repository { nameWithOwner } } } }')
        ->toContain('-F owner=UAMS-Web -F name=uamswp-migration-api -F number=4242');
})->skip(fn (): bool => assignAuthorToolsMissing() !== null, 'needs bash and jq');
