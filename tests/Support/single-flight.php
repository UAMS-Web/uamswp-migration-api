<?php

declare(strict_types=1);

/*
 * A single-flight guard for the HAND-RUN Feature suite.
 *
 * #119 stopped two local CI runners colliding, keyed on the CI
 * worktree path and `uamswp_migration_api_ci`. It stops at the runner boundary:
 * a hand-run `composer test:integration` goes straight to
 * `uamswp_migration_api_tests` and acquires nothing, so two of them on one
 * machine still drop each other's tables. That is the likelier collision rather
 * than the rarer one -- a runner is started deliberately and announced, while
 * the inner-loop test command is run without ceremony by anyone (#212).
 *
 * WHY THIS IS A SECOND IMPLEMENTATION RATHER THAN A SHARED ONE. The runner's
 * guard is JavaScript and this is PHP; nothing can be imported across that line.
 * What IS shared is the on-disk protocol, and deliberately so: the lock file is
 * named by the same rule, so the two implementations interlock whenever they
 * name the same database. Measured on this machine, `node` and PHP agree:
 *
 *     node  os.tmpdir()          /var/folders/.../T
 *     php   sys_get_temp_dir()   /var/folders/.../T
 *     both  for uamswp_migration_api_tests
 *           -> uamswp-migration-api-ci.database.1a0c7d320a226bb9.lock
 *
 * So a developer who points `WP_TESTS_DB_NAME` at the runner's database is
 * refused by whichever holds it first, across languages. That is a consequence
 * of matching the protocol rather than a feature added on top.
 *
 * The unit suite acquires nothing: it runs from the root setup, never loads
 * `tests/Integration/bootstrap.php`, and touches no database at all.
 */

/**
 * The lock file for a resolved database name.
 *
 * Byte-identical to `databaseLockPath(prefix, $name)` in the shared runner,
 * `.claude/skills/local-ci/local-ci.mjs`, with the prefix `local-ci.json` gives
 * the `pest` job.
 * A change to either is a change to both, and the test asserts the shape rather
 * than trusting the two to stay in step.
 */
function migrationApiLockPath(string $database, ?string $directory = null): string
{
    $directory ??= sys_get_temp_dir();

    return rtrim($directory, '/').'/uamswp-migration-api-ci.database.'
        .substr(hash('sha256', $database), 0, 16).'.lock';
}

/**
 * The environment variable a lock holder uses to name itself to its children.
 *
 * `--mutate` runs each mutant in a child `pest` process, and every child loads
 * this bootstrap while the parent still holds the lock. Without a way to tell a
 * child of the holder from a stranger, each child was refused, exited 1, and was
 * reported KILLED -- every covered mutant a false kill, with nothing on the
 * survivor list to explain (#287).
 */
function migrationApiSuiteLockHolderEnv(): string
{
    return 'UAMSWP_SUITE_LOCK_HOLDER';
}

/**
 * Whether this process is a ParaTest worker, which runs CONCURRENTLY with its
 * siblings.
 *
 * ParaTest's main process loads this bootstrap, takes the lock and exports the
 * holder, then starts its workers through Symfony Process, so every worker
 * inherits the export. Nothing gives a worker its own database, and N WordPress
 * test installers would drop tables under each other with no message. A
 * `--mutate` child is inheritable only because it runs ALONE while its parent
 * waits; a worker never is. ParaTest sets both of these for every worker, and
 * `pest-plugin-mutate` sets them for its `--parallel` children too.
 */
function migrationApiIsParallelWorker(): bool
{
    foreach (['PARATEST', 'TEST_TOKEN'] as $name) {
        $value = getenv($name);

        if (\is_string($value) && $value !== '') {
            return true;
        }
    }

    return false;
}

/**
 * The lock holder named by an ancestor, or null when no ancestor holds it.
 *
 * Always null for a parallel worker, so a worker meets the holder as a stranger
 * and is refused, exactly as it was before any process could inherit.
 */
function migrationApiInheritedSuiteLockHolder(): ?int
{
    if (migrationApiIsParallelWorker()) {
        return null;
    }

    $value = getenv(migrationApiSuiteLockHolderEnv());

    if (! \is_string($value) || preg_match('/^[1-9]\d*$/', $value) !== 1) {
        return null;
    }

    return (int) $value;
}

/**
 * Name this process as the holder, for every process it starts from here on.
 *
 * All three stores, because Symfony Process -- the route `--mutate` starts its
 * children by -- builds a child's environment from `getenv()` INTERSECTED with
 * `$_SERVER`, plus `$_ENV`. A variable set by `putenv()` alone is dropped on the
 * way to the child, and the child is refused exactly as before.
 */
function migrationApiExportSuiteLockHolder(int $pid): void
{
    $name = migrationApiSuiteLockHolderEnv();

    putenv($name.'='.$pid);
    $_ENV[$name] = (string) $pid;
    $_SERVER[$name] = (string) $pid;
}

/**
 * Decide what to do about a lock file's contents, without touching the disk.
 *
 * Pure, so every branch is testable without a second suite running: the refusal,
 * and each reason a lock is NOT a refusal. The shared local CI runner reads the
 * same file with two differences: it refuses a record it cannot read, where this
 * reclaims one, and it has no `inherit`, because the runner never starts a copy
 * of itself.
 *
 * `inherit` requires the lock to name exactly the pid an ancestor exported, and
 * that pid to be alive. A child of a DIFFERENT suite's holder inherits the wrong
 * pid and is still refused, and a child whose holder has exited reclaims.
 *
 * @param  callable(int): bool  $isAlive
 * @return array{action: string, holder: array{pid: int, suite?: string, database?: string, startedAt?: string}|null, reason?: string}
 */
function migrationApiLockVerdict(?string $raw, int $selfPid, callable $isAlive, ?int $inheritedHolder = null): array
{
    if ($raw === null) {
        return ['action' => 'acquire', 'holder' => null];
    }

    /** @var mixed $decoded */
    $decoded = json_decode($raw, true);

    if (! \is_array($decoded)) {
        // A truncated or hand-edited lock names nobody, so nobody can be waited
        // for. Refusing on it would wedge every later run with no way to say
        // which run to wait for; reclaiming is the recoverable direction.
        return ['action' => 'reclaim', 'holder' => null, 'reason' => 'the lock file is unreadable'];
    }

    if (! isset($decoded['pid']) || ! \is_int($decoded['pid'])) {
        return ['action' => 'reclaim', 'holder' => null, 'reason' => 'the lock names no process'];
    }

    // Rebuilt with string keys so the declared shape is provable rather than
    // asserted: json_decode returns array<int|string, mixed> and level max
    // will not take the narrowing on trust.
    $holder = ['pid' => $decoded['pid']];

    foreach (['suite', 'database', 'startedAt'] as $key) {
        if (isset($decoded[$key]) && \is_string($decoded[$key])) {
            $holder[$key] = $decoded[$key];
        }
    }

    if ($holder['pid'] === $selfPid) {
        return ['action' => 'acquire', 'holder' => $holder];
    }

    if (! $isAlive($holder['pid'])) {
        return ['action' => 'reclaim', 'holder' => $holder, 'reason' => "pid {$holder['pid']} is gone"];
    }

    if ($inheritedHolder === $holder['pid']) {
        return ['action' => 'inherit', 'holder' => $holder];
    }

    return ['action' => 'refuse', 'holder' => $holder];
}

/**
 * The refusal a reader gets, named so a test can assert the message rather than
 * only that the run failed.
 *
 * @param  array{pid: int, suite?: string, database?: string, startedAt?: string}|null  $holder
 */
function migrationApiLockRefusal(?array $holder, string $database, string $path, bool $proberIsBlind = false): string
{
    $who = $holder === null
        ? 'another run'
        : 'pid '.$holder['pid']
            .(isset($holder['suite']) ? " ({$holder['suite']})" : '')
            .(isset($holder['startedAt']) ? ", started {$holder['startedAt']}" : '');

    $unchecked = $proberIsBlind
        ? PHP_EOL
            .'  THAT PID WAS NOT CHECKED. This platform has no working way to ask whether a'
            .PHP_EOL
            .'  process is running -- the probe cannot see even THIS process -- so every lock'
            .PHP_EOL
            .'  is treated as held. The run named above may have exited long ago, and nothing'
            .PHP_EOL
            .'  here can tell the difference.'
        : '';

    return PHP_EOL
        ."Refusing to run: another suite is already using '{$database}'."
        .PHP_EOL
        ."  {$who} holds it."
        .$unchecked
        .PHP_EOL.PHP_EOL
        ."WordPress' test installer DROPS EVERY TABLE it finds on startup, so two suites"
        .PHP_EOL
        .'interleaving here do not collide loudly -- they produce a wall of failures'
        .PHP_EOL
        .'indistinguishable from real regressions, and the first instinct is to bisect an'
        .PHP_EOL
        .'innocent diff. Wait for that run, or ask the seat holding it.'
        .PHP_EOL.PHP_EOL
        ."If you are certain that run is gone, remove {$path}."
        .PHP_EOL.PHP_EOL;
}

/**
 * Ask a prober about a pid, but CALIBRATE it first.
 *
 * A prober that cannot see a pid that is alive by definition -- this very
 * process -- is blind, and its "not running" about any other pid means nothing.
 * That is the positive-control discipline this repository already requires of a
 * sweep, applied in code rather than to a command someone types: an empty result
 * is not evidence until the instrument has shown it can produce a full one.
 *
 * Blind means ALIVE, which fails closed. The cost of that is waiting for a run
 * that has ended; the cost of the other direction is deleting a lock a live
 * suite is holding, which is the collision this guard exists to prevent.
 *
 * @param  callable(int): bool  $probe
 */
function migrationApiPidIsAliveVia(int $pid, callable $probe, int $selfPid): bool
{
    if (! $probe($selfPid)) {
        return true;
    }

    return $probe($pid);
}

/**
 * Whether a pid is running.
 *
 * Three routes, and the last one is why the calibration above exists.
 *
 * ext-posix        macOS and Linux. EPERM means the process exists and belongs
 *                  to another user, so only ESRCH is "gone".
 * tasklist         Windows, where ext-posix does not exist. Reports by output
 *                  rather than exit status, which it does not set usefully.
 * ps               anything else.
 *
 * MEASURED, not assumed: on Windows with Git Bash on PATH, `ps` is a
 * Cygwin build that rejects `-o` and exits non-zero for EVERY pid, including
 * this interpreter's own. Trusted directly it reports every lock holder as
 * gone, so every lock is reclaimed and the guard never refuses -- on the one
 * platform this repository's gate runs on. The calibration turns that from a
 * silent fail-open into a conservative fail-closed.
 */
function migrationApiPidIsAlive(int $pid): bool
{
    if (\function_exists('posix_kill') && \function_exists('posix_get_last_error')) {
        if (posix_kill($pid, 0)) {
            return true;
        }

        // EPERM (1) means the process EXISTS and belongs to another user, which
        // is alive for this purpose. Only ESRCH says it is gone, so anything
        // that is not ESRCH is treated as alive -- failing closed.
        return posix_get_last_error() !== 3;
    }

    $self = getmypid();

    if ($self === false) {
        return true;
    }

    return migrationApiPidIsAliveVia($pid, migrationApiProbeProcessTable(...), $self);
}

/**
 * Whether `tasklist /FO CSV` output contains a row FOR this pid.
 *
 * Split out from the `exec` above so the deciding half is testable on a machine
 * that has no `tasklist` -- which is every machine this repository is developed
 * on. The platform that runs this code is the one that cannot exercise it here,
 * so the parse is kept pure and pinned rather than left to the gate to discover.
 *
 * The PID COLUMN is compared exactly, rather than the line being searched. A row
 * carries a session number and a memory figure as well, so a `\b140\b` search
 * against `"System","4","Services","0","140 K"` matches the memory column and
 * reports pid 140 as running when it is not. The guard would then wait forever
 * on a holder that had already exited -- the opposite failure to the one this
 * file was kicked back for, out of the same habit: trusting a match without
 * asking which column it landed in.
 *
 * @param  list<string>  $output
 */
function migrationApiTasklistReportsPid(array $output, int $pid): bool
{
    foreach ($output as $line) {
        $fields = str_getcsv($line, ',', '"', '\\');

        // Field 1 is the pid. A non-row -- `tasklist`'s INFO line when nothing
        // matches -- has no second field, so `isset()` skips it rather than
        // matching it; `isset()` is also false for a null field, which is why
        // no separate null check belongs here.
        if (isset($fields[1]) && $fields[1] === (string) $pid) {
            return true;
        }
    }

    return false;
}

/**
 * Whether the process-table prober can see even THIS process.
 *
 * This is the calibration migrationApiPidIsAliveVia() already performs, exposed
 * as a fact the refusal can report rather than discarded. When it is true every
 * pid reads as alive, so a holder named in a refusal has NOT been observed --
 * it is the fail-closed default standing in for an observation. A refusal that
 * does not say so invites the reader to wait on a process that exited hours ago,
 * and the advice to wait is then simply wrong.
 *
 * Nothing here changes what the guard DOES. Treating a blind prober as reporting
 * alive stays deliberate: the alternative deletes a lock a live suite is holding.
 * This only changes what the refusal is willing to claim.
 */
function migrationApiProberIsBlind(): bool
{
    // ext-posix answers directly and has no blind mode -- `posix_kill` reports
    // ESRCH for a pid that is gone and EPERM for one owned by somebody else, so
    // there is nothing to calibrate against.
    if (\function_exists('posix_kill') && \function_exists('posix_get_last_error')) {
        return false;
    }

    $self = getmypid();

    if ($self === false) {
        return true; // Cannot calibrate, so cannot claim the prober works.
    }

    return ! migrationApiProbeProcessTable($self);
}

/** One process-table lookup, by whichever tool this platform has. */
function migrationApiProbeProcessTable(int $pid): bool
{
    $output = [];
    $status = 1;

    if (\defined('PHP_WINDOWS_VERSION_MAJOR')) {
        // `tasklist` sets no useful exit status -- it succeeds whether or not
        // the pid exists -- so the ROW is the answer, and CSV is asked for so
        // the row can be read by column instead of searched as text.
        exec(sprintf('tasklist /FI "PID eq %d" /NH /FO CSV 2>NUL', $pid), $output, $status);

        return migrationApiTasklistReportsPid($output, $pid);
    }

    exec(sprintf('ps -p %d -o pid= 2>/dev/null', $pid), $output, $status);

    return $status === 0 && $output !== [];
}

/**
 * Acquire the lock for a database, or exit non-zero naming the holder.
 *
 * Registers its own release on shutdown, and only removes a lock that still
 * names this process -- a run declared stale and taken over must not delete its
 * successor's lock on the way out.
 */
function migrationApiAcquireSuiteLock(string $database, string $suite, ?string $directory = null): void
{
    // The runner already holds this database's lock and says so. Acquiring
    // again would refuse against our own parent, because the two guards share
    // one protocol and the chain's `pest` job names the database the runner
    // locked at startup. Told rather than inferred: nothing else in this
    // environment separates "my parent holds it" from "a stranger holds it".
    if (getenv('LOCAL_CI_SUITE_LOCK_HELD') === '1') {
        return;
    }

    $path = migrationApiLockPath($database, $directory);
    $self = getmypid();

    if ($self === false) {
        return; // Nothing to record, so nothing to guard with.
    }

    $record = json_encode([
        'pid' => $self,
        'suite' => $suite,
        'database' => $database,
        'startedAt' => gmdate('c'),
    ], JSON_THROW_ON_ERROR);

    $inherited = migrationApiInheritedSuiteLockHolder();

    // Two attempts. The second exists only to settle a reclaim; a third would be
    // a spin against a live competitor, which is the case this REFUSES rather
    // than waits out.
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $handle = @fopen($path, 'x');

        if ($handle !== false) {
            fwrite($handle, $record);
            fclose($handle);

            migrationApiExportSuiteLockHolder($self);

            register_shutdown_function(static function () use ($path, $self): void {
                $raw = @file_get_contents($path);

                if ($raw === false) {
                    return;
                }

                $verdict = migrationApiLockVerdict($raw, $self, migrationApiPidIsAlive(...));

                if ($verdict['holder'] !== null && $verdict['holder']['pid'] === $self) {
                    @unlink($path);
                }
            });

            return;
        }

        $raw = @file_get_contents($path);
        $verdict = migrationApiLockVerdict($raw === false ? null : $raw, $self, migrationApiPidIsAlive(...), $inherited);

        // A child of the holder -- a `--mutate` child, one at a time, while its
        // parent waits. It writes nothing and registers no release, so the
        // parent's lock outlives every child.
        if ($verdict['action'] === 'inherit') {
            return;
        }

        if ($verdict['action'] === 'refuse') {
            fwrite(STDERR, migrationApiLockRefusal($verdict['holder'], $database, $path, migrationApiProberIsBlind()));

            exit(1);
        }

        if ($verdict['action'] === 'reclaim') {
            @unlink($path);

            continue;
        }

        // The file already names this process -- in practice a stale lock whose
        // pid the OS has since given to this one. It is ours, so name ourselves
        // to our children exactly as a fresh acquisition does; returning without
        // the export would refuse every `--mutate` child and reproduce #287.
        if ($verdict['holder'] !== null && $verdict['holder']['pid'] === $self) {
            migrationApiExportSuiteLockHolder($self);
        }

        return;
    }

    fwrite(STDERR, PHP_EOL."Could not acquire the suite lock at {$path}; another run took it mid-retry.".PHP_EOL.PHP_EOL);

    exit(1);
}
