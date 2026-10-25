<?php

declare(strict_types=1);

/*
 * The hand-run suite's single-flight guard (#212).
 *
 * #119 guarded the runners and stopped at the runner boundary. These cover the
 * half a hand-run `composer test:integration` needs, and one property #119 could not
 * have: the two guards are written in different languages and must agree on the
 * lock file, so a developer who points WP_TESTS_DB_NAME at the runner's database
 * is refused by whichever holds it first.
 *
 * The parity tests read the shared runner (`.claude/skills/local-ci/local-ci.mjs`)
 * and `local-ci.json` rather than executing node. A test that shells out to node
 * would skip wherever node is absent, and a skip that can become permanent is
 * what `GravityFormsGuardTest` exists to prevent.
 */

use Symfony\Component\Process\Process;

require_once __DIR__.'/../Support/single-flight.php';

/**
 * Remove the holder export from all three stores it is written to.
 */
function migrationApiForgetSuiteLockHolder(): void
{
    $name = migrationApiSuiteLockHolderEnv();

    putenv($name);
    unset($_ENV[$name], $_SERVER[$name]);
}

it('names the lock file exactly as the runner does, so the two guards interlock', function (): void {
    // Golden value, so a change to the PHP side alone fails here.
    expect(migrationApiLockPath('uamswp_migration_api_tests', '/tmp'))
        ->toBe('/tmp/uamswp-migration-api-ci.database.1a0c7d320a226bb9.lock');
});

it('derives that name by the same rule the runner states in its own source', function (): void {
    // Parses the shared runner and this repository's config rather than
    // trusting the two to stay in step. If `databaseLockPath` changes shape
    // there, or the `pest` job's prefix here, this fails rather than producing
    // two guards that quietly stop seeing each other.
    $runner = (string) file_get_contents(\dirname(__DIR__, 2).'/.claude/skills/local-ci/local-ci.mjs');
    $config = (string) file_get_contents(\dirname(__DIR__, 2).'/local-ci.json');

    expect($runner)->toContain('`${prefix}.database.${createHash(\'sha256\').update(database).digest(\'hex\').slice(0, 16)}.lock`')
        ->and($config)->toContain('"prefix": "uamswp-migration-api-ci"');

    // And the PHP side computes the same thing for the same input.
    $digest = substr(hash('sha256', 'uamswp_migration_api_tests'), 0, 16);

    expect(migrationApiLockPath('uamswp_migration_api_tests', '/tmp'))->toContain($digest);
});

it('acquires when no lock exists', function (): void {
    $verdict = migrationApiLockVerdict(null, 1, fn (int $pid): bool => true);

    expect($verdict['action'])->toBe('acquire')
        ->and($verdict['holder'])->toBeNull();
});

it('REFUSES when the lock names a different live process', function (): void {
    $raw = json_encode(['pid' => 4242, 'suite' => 'pest --testsuite=Feature'], JSON_THROW_ON_ERROR);
    $verdict = migrationApiLockVerdict($raw, 1, fn (int $pid): bool => true);

    $holder = $verdict['holder'];

    expect($verdict['action'])->toBe('refuse')
        ->and($holder)->not->toBeNull()
        ->and($holder['pid'] ?? null)->toBe(4242);
});

it('reclaims a lock left by a process that is gone, so a killed run cannot wedge the next', function (): void {
    $raw = json_encode(['pid' => 4242], JSON_THROW_ON_ERROR);
    $verdict = migrationApiLockVerdict($raw, 1, fn (int $pid): bool => false);

    expect($verdict['action'])->toBe('reclaim')
        ->and($verdict['reason'] ?? '')->toContain('4242 is gone');
});

it('reclaims an unreadable lock rather than refusing on it forever', function (): void {
    $verdict = migrationApiLockVerdict('not json{', 1, fn (int $pid): bool => true);

    expect($verdict['action'])->toBe('reclaim')
        ->and($verdict['reason'] ?? '')->toContain('unreadable');
});

it('reclaims a lock carrying no pid', function (): void {
    $raw = json_encode(['suite' => 'something'], JSON_THROW_ON_ERROR);

    expect(migrationApiLockVerdict($raw, 1, fn (int $pid): bool => true)['action'])->toBe('reclaim');
});

it('treats a lock naming this process as ours', function (): void {
    $raw = json_encode(['pid' => 99], JSON_THROW_ON_ERROR);

    expect(migrationApiLockVerdict($raw, 99, fn (int $pid): bool => true)['action'])->toBe('acquire');
});

it('names the holder, the database and the lock to remove in the refusal', function (): void {
    $holder = ['pid' => 4242, 'suite' => 'pest --testsuite=Feature', 'startedAt' => '2026-09-11T08:00:00+00:00'];
    $message = migrationApiLockRefusal($holder, 'uamswp_migration_api_tests', '/tmp/some.lock');

    expect($message)->toContain('pid 4242')
        ->and($message)->toContain('pest --testsuite=Feature')
        ->and($message)->toContain('uamswp_migration_api_tests')
        ->and($message)->toContain('/tmp/some.lock')
        // The reason, not just the refusal: a reader who does not know why two
        // suites cannot share a database will assume the guard is overcautious.
        ->and($message)->toContain('DROPS EVERY TABLE');
});

it('distinguishes a live pid from a dead one against the real process table', function (): void {
    $self = getmypid();

    expect($self)->toBeInt();
    expect(migrationApiPidIsAlive((int) $self))->toBeTrue();

    // 2^31 - 2 cannot be a live pid on this platform. Not a low number, which
    // could be recycled onto something real between the two assertions.
    expect(migrationApiPidIsAlive(2_147_483_646))->toBeFalse();
});

it('reports a process owned by another user as alive, rather than as gone', function (): void {
    // pid 1 exists and is not ours, so `posix_kill` fails with EPERM. Reading
    // that as "gone" would let a run delete a lock held by another user's
    // process -- the fail-OPEN direction.
    //
    // POSIX-only by nature rather than by convenience: EPERM is what this
    // asserts, and Windows has neither ext-posix nor a pid 1. The non-POSIX
    // path is not left uncovered -- the calibration tests below exercise it,
    // and they are the ones that matter there, so this is a pair that covers
    // both platforms rather than a skip that can quietly become permanent.
    if (! \function_exists('posix_kill')) {
        expect(migrationApiPidIsAlive((int) getmypid()))->toBeTrue();

        return;
    }

    expect(migrationApiPidIsAlive(1))->toBeTrue();
});

it('defers to the runner when the runner says it already holds the lock', function (): void {
    // The chain refused against ITSELF before this existed: the `pest` job names
    // the database the runner locked at startup, and the two guards share one
    // protocol on purpose, so the suite collided with its own parent. Caught by
    // the gate rather than by review, which is the whole reason the chain runs.
    $directory = sys_get_temp_dir().'/uamswp-suite-lock-'.bin2hex(random_bytes(6));
    mkdir($directory);

    putenv('LOCAL_CI_SUITE_LOCK_HELD=1');

    try {
        migrationApiAcquireSuiteLock('uamswp_migration_api_ci', 'pest --testsuite=Feature', $directory);

        expect(glob($directory.'/*.lock'))->toBe([], 'the suite must acquire nothing when the runner holds it');
    } finally {
        putenv('LOCAL_CI_SUITE_LOCK_HELD');
        array_map(unlink(...), glob($directory.'/*.lock') ?: []);
        rmdir($directory);
    }
});

it('acquires normally when the runner has not claimed the lock', function (): void {
    // The control for the test above. Without it, an acquisition that silently
    // stopped working everywhere would still pass the deferral test.
    $directory = sys_get_temp_dir().'/uamswp-suite-lock-'.bin2hex(random_bytes(6));
    mkdir($directory);

    putenv('LOCAL_CI_SUITE_LOCK_HELD');

    try {
        migrationApiAcquireSuiteLock('uamswp_migration_api_tests', 'pest --testsuite=Feature', $directory);

        expect(glob($directory.'/*.lock'))->toHaveCount(1)
            ->and(migrationApiInheritedSuiteLockHolder())->toBe(getmypid(), 'the holder names itself to its children');
    } finally {
        migrationApiForgetSuiteLockHolder();
        array_map(unlink(...), glob($directory.'/*.lock') ?: []);
        rmdir($directory);
    }
});

it('keeps the runner and the suite naming the same handshake variable', function (): void {
    // Two files, two languages, one string. A rename on either side alone turns
    // the deferral off silently and the chain refuses against itself again.
    $config = (string) file_get_contents(\dirname(__DIR__, 2).'/local-ci.json');
    $guard = (string) file_get_contents(__DIR__.'/../Support/single-flight.php');

    expect($config)->toContain('"heldEnv": "LOCAL_CI_SUITE_LOCK_HELD"')
        ->and($guard)->toContain("getenv('LOCAL_CI_SUITE_LOCK_HELD')");
});

it('treats a BLIND prober as reporting alive, so a broken probe cannot unlock everything', function (): void {
    // The gate found this on Windows at fcd37f0cb: no ext-posix, and Git Bash's
    // Cygwin `ps` rejects `-o` and exits non-zero for EVERY pid -- including the
    // interpreter's own. Trusted directly it reported every lock holder gone, so
    // every lock was reclaimed and the guard could never refuse, on the one
    // platform this repository's gate runs on.
    $blind = fn (int $pid): bool => false;

    expect(migrationApiPidIsAliveVia(4242, $blind, 999))->toBeTrue();
});

it('trusts a prober that can see this process, and then reports what it says', function (): void {
    // The control for the test above. Without it, a calibration that answered
    // "alive" unconditionally would pass the blind-prober test and be useless.
    $seeing = fn (int $pid): bool => $pid === 999 || $pid === 4242;

    expect(migrationApiPidIsAliveVia(4242, $seeing, 999))->toBeTrue()
        ->and(migrationApiPidIsAliveVia(5555, $seeing, 999))->toBeFalse();
});

it('calibrates before answering, not after', function (): void {
    // Ordering matters: a prober asked about the target first would have already
    // returned its useless answer by the time the calibration ran.
    $asked = [];
    $probe = function (int $pid) use (&$asked): bool {
        $asked[] = $pid;

        return true;
    };

    migrationApiPidIsAliveVia(4242, $probe, 999);

    expect($asked[0] ?? null)->toBe(999, 'the calibration pid must be asked first');
});

it('reads the tasklist PID column, not the whole row', function (): void {
    // Real `tasklist /NH /FO CSV` rows. The second is the one that matters: pid
    // 140 appears NOWHERE in it except the memory figure, so a line search finds
    // a match and reports a dead process as running.
    $rows = [
        '"System Idle Process","0","Services","0","8 K"',
        '"System","4","Services","0","140 K"',
    ];

    expect(migrationApiTasklistReportsPid($rows, 4))->toBeTrue('pid 4 IS the PID column')
        ->and(migrationApiTasklistReportsPid($rows, 140))->toBeFalse('"140 K" is memory, not a pid')
        ->and(migrationApiTasklistReportsPid($rows, 8))->toBeFalse('"8 K" is memory, not a pid')
        ->and(migrationApiTasklistReportsPid($rows, 0))->toBeTrue('pid 0 is a real row here');
});

it('reports no match for the line tasklist prints when nothing matches', function (): void {
    // Not a CSV row at all, and it must not be mistaken for one. `str_getcsv`
    // returns a single field, so there is no column 1 to compare.
    $absent = ['INFO: No tasks are running which match the specified criteria.'];

    expect(migrationApiTasklistReportsPid($absent, 4242))->toBeFalse()
        ->and(migrationApiTasklistReportsPid([], 4242))->toBeFalse('empty output is not a match');
});

it('says the holder was NOT checked when the prober is blind', function (): void {
    // #233. A blind prober reports every pid alive, so the pid named here is the
    // fail-closed default standing in for an observation -- not an observation.
    // Without this the refusal reads identically to one naming a verified-live
    // holder, and the advice to wait is wrong rather than merely unhelpful.
    $holder = ['pid' => 4242, 'suite' => 'pest --testsuite=Feature', 'startedAt' => '2026-09-11T08:00:00+00:00'];
    $message = migrationApiLockRefusal($holder, 'uamswp_migration_api_tests', '/tmp/some.lock', true);

    expect($message)->toContain('NOT CHECKED')
        ->and($message)->toContain('may have exited long ago')
        // It must still be a refusal, with everything the working branch carries.
        ->and($message)->toContain('pid 4242')
        ->and($message)->toContain('DROPS EVERY TABLE')
        ->and($message)->toContain('/tmp/some.lock');
});

it('leaves the refusal untouched when the prober works', function (): void {
    // The control for the test above, and AC2 in its own right: a working prober
    // must produce exactly what it produced before #233. Asserting the absence
    // alone would pass against a function that returned an empty string.
    $holder = ['pid' => 4242, 'suite' => 'pest --testsuite=Feature', 'startedAt' => '2026-09-11T08:00:00+00:00'];
    $working = migrationApiLockRefusal($holder, 'uamswp_migration_api_tests', '/tmp/some.lock', false);
    $defaulted = migrationApiLockRefusal($holder, 'uamswp_migration_api_tests', '/tmp/some.lock');

    expect($working)->not->toContain('NOT CHECKED')
        ->and($working)->toContain('DROPS EVERY TABLE')
        // The default must BE the working branch, or every existing caller --
        // the acquisition path included -- silently starts claiming blindness.
        ->and($defaulted)->toBe($working);
});

it('puts the unchecked notice with the holder, not adrift in the message', function (): void {
    // Placement is the whole value: a reader scanning for the pid must meet the
    // caveat attached to it. This also pins the blank-line rhythm, which a
    // careless edit to the concatenation breaks without changing any wording.
    $holder = ['pid' => 4242];
    $blind = migrationApiLockRefusal($holder, 'db', '/tmp/x.lock', true);
    $working = migrationApiLockRefusal($holder, 'db', '/tmp/x.lock', false);

    // PHP_EOL, never a bare \n. The message is PHP_EOL-joined, which is TWO
    // bytes on Windows, and a literal \n in a PCRE pattern matches LF only --
    // so it cannot match the CRLF the code correctly produced. The gate caught
    // exactly this at 81ccc80ef: the feature was right on that platform and
    // this assertion was not. Quoting PHP_EOL keeps the test portable AND still
    // fails if the message is ever joined with something else.
    $eol = preg_quote(PHP_EOL, '/');

    expect($blind)->toMatch('/holds it\.'.$eol.'  THAT PID WAS NOT CHECKED/')
        ->and($blind)->toMatch('/difference\.'.$eol.$eol.'WordPress/')
        ->and($working)->toMatch('/holds it\.'.$eol.$eol.'WordPress/');
});

it('asserts line structure in a way that holds under CRLF as well as LF', function (): void {
    // The control for the test above, and it runs on a machine whose PHP_EOL is
    // LF. Without it, "these patterns are portable" is a claim nobody on this
    // toolchain can check -- which is how the previous version shipped broken
    // and stayed broken until a Windows run found it.
    foreach (["\n", "\r\n"] as $ending) {
        $message = 'holds it.'.$ending.'  THAT PID WAS NOT CHECKED'.$ending.$ending.'WordPress';
        $quoted = preg_quote($ending, '/');

        expect($message)->toMatch('/holds it\.'.$quoted.'  THAT PID WAS NOT CHECKED/')
            ->and($message)->toMatch('/CHECKED'.$quoted.$quoted.'WordPress/');
    }

    // The defect itself, pinned: a bare \n pattern CANNOT match a CRLF join.
    // An edit back to '\n' reds here, on any platform, rather than waiting for
    // whichever machine happens to run the suite next.
    expect(preg_match('/holds it\.\n/', "holds it.\r\nx"))->toBe(0)
        ->and(preg_match('/holds it\.'.preg_quote("\r\n", '/').'/', "holds it.\r\nx"))->toBe(1);
});

it('reports the prober as sighted wherever ext-posix answers', function (): void {
    // There is no blind mode on a platform with ext-posix: `posix_kill` reports
    // ESRCH for a pid that is gone and EPERM for one owned by another user, so
    // there is nothing to calibrate and nothing to disclaim.
    $hasPosix = \function_exists('posix_kill') && \function_exists('posix_get_last_error');

    if (! $hasPosix) {
        expect(migrationApiProberIsBlind())->toBeBool();

        return;
    }

    expect(migrationApiProberIsBlind())->toBeFalse();
});

/*
 * `--mutate` against the guard (#287). Each mutant runs in a child `pest` that
 * loads this bootstrap while the parent still holds the lock, so every child was
 * refused, exited 1, and was reported KILLED. The holder now names itself to its
 * children, and a child that finds exactly that pid on the lock proceeds.
 */

it('lets a child of the live holder through', function (): void {
    $raw = json_encode(['pid' => 4242], JSON_THROW_ON_ERROR);
    $verdict = migrationApiLockVerdict($raw, 1, fn (int $pid): bool => true, 4242);

    expect($verdict['action'])->toBe('inherit')
        ->and($verdict['holder']['pid'] ?? null)->toBe(4242);
});

it('still refuses a child whose inherited holder is a DIFFERENT process', function (): void {
    // The control for the test above: inheriting must key on the pid, or any
    // descendant of any suite would walk past any other suite's lock.
    $raw = json_encode(['pid' => 4242], JSON_THROW_ON_ERROR);

    expect(migrationApiLockVerdict($raw, 1, fn (int $pid): bool => true, 5555)['action'])->toBe('refuse')
        ->and(migrationApiLockVerdict($raw, 1, fn (int $pid): bool => true)['action'])->toBe('refuse');
});

it('reclaims rather than inherits when the named holder has exited', function (): void {
    $raw = json_encode(['pid' => 4242], JSON_THROW_ON_ERROR);

    expect(migrationApiLockVerdict($raw, 1, fn (int $pid): bool => false, 4242)['action'])->toBe('reclaim');
});

it('reads an inherited holder only when it is a positive integer', function (): void {
    $name = migrationApiSuiteLockHolderEnv();

    try {
        foreach (['4242' => 4242, '' => null, '0' => null, '-5' => null, '42x' => null, '007' => null] as $value => $expected) {
            putenv($name.'='.$value);

            expect(migrationApiInheritedSuiteLockHolder())->toBe($expected, "value '{$value}'");
        }

        putenv($name);

        expect(migrationApiInheritedSuiteLockHolder())->toBeNull('unset');
    } finally {
        migrationApiForgetSuiteLockHolder();
    }
});

/**
 * Run a child PHP process the way `--mutate` does -- through Symfony Process,
 * with the plugin's own explicit env array -- that tries to take the same lock.
 *
 * @return array{0: int|null, 1: string}
 */
function migrationApiRunLockingChild(string $directory, string $database): array
{
    return migrationApiRunLockingChildren($directory, $database, 1)[0];
}

/**
 * Start N such children CONCURRENTLY and wait for all of them -- the shape of a
 * ParaTest run, where every worker is started before any finishes.
 *
 * @param  array<string, string|false>  $extraEnv
 * @return list<array{0: int|null, 1: string}>
 */
function migrationApiRunLockingChildren(string $directory, string $database, int $count, array $extraEnv = []): array
{
    $script = $directory.'/child.php';

    file_put_contents($script, sprintf(
        "<?php\nrequire %s;\nmigrationApiAcquireSuiteLock(%s, 'child', %s);\necho 'proceeded';\n",
        var_export(__DIR__.'/../Support/single-flight.php', true),
        var_export($database, true),
        var_export($directory, true),
    ));

    // The runner's deferral flag is REMOVED from the child (false, in Symfony's
    // env array, means unset). Under `npm run ci:local` it sits in `$_ENV` and
    // `$_SERVER`, so a child would inherit it and skip the lock for the runner's
    // reason -- passing the positive test and failing the control, both for the
    // wrong reason. The gate caught exactly that on the first chain run. The two
    // ParaTest variables are removed for the same reason unless a test sets them.
    $env = $extraEnv + [
        'PEST_MUTATION_TESTING' => 'stand-in for the plugin env',
        'LOCAL_CI_SUITE_LOCK_HELD' => false,
        'PARATEST' => false,
        'TEST_TOKEN' => false,
    ];

    $processes = [];

    for ($token = 1; $token <= $count; $token++) {
        // A ParaTest worker carries its own TEST_TOKEN, 1..N, alongside PARATEST.
        $workerEnv = ($extraEnv['PARATEST'] ?? false) !== false ? ['TEST_TOKEN' => (string) $token] + $env : $env;
        $process = new Process(command: [PHP_BINARY, $script], env: $workerEnv);
        $process->start();
        $processes[] = $process;
    }

    $results = [];

    foreach ($processes as $process) {
        $process->wait();
        $results[] = [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
    }

    return $results;
}

it('lets a child started through Symfony Process run under the holder', function (): void {
    // End to end, through the exact route the bug lived on. Symfony Process
    // builds a child's environment from getenv() INTERSECTED with $_SERVER, so an
    // export that reached only putenv() would be dropped and this would refuse.
    $directory = sys_get_temp_dir().'/uamswp-suite-lock-'.bin2hex(random_bytes(6));
    mkdir($directory);
    putenv('LOCAL_CI_SUITE_LOCK_HELD');

    try {
        migrationApiAcquireSuiteLock('uamswp_migration_api_tests', 'pest --testsuite=Feature', $directory);

        expect(glob($directory.'/*.lock'))->toHaveCount(1);

        [$exit, $output] = migrationApiRunLockingChild($directory, 'uamswp_migration_api_tests');

        expect($output)->toContain('proceeded')
            ->and($exit)->toBe(0)
            // The parent's lock survives the child: it wrote nothing and
            // registered no release of its own.
            ->and(glob($directory.'/*.lock'))->toHaveCount(1);
    } finally {
        migrationApiForgetSuiteLockHolder();
        array_map(unlink(...), glob($directory.'/*') ?: []);
        rmdir($directory);
    }
});

it('refuses the same child when the holder never named itself', function (): void {
    // The control for the test above, and the defect itself: without the export
    // the child is refused and exits 1, which `--mutate` reports as a KILL.
    $directory = sys_get_temp_dir().'/uamswp-suite-lock-'.bin2hex(random_bytes(6));
    mkdir($directory);
    putenv('LOCAL_CI_SUITE_LOCK_HELD');

    try {
        migrationApiAcquireSuiteLock('uamswp_migration_api_tests', 'pest --testsuite=Feature', $directory);
        migrationApiForgetSuiteLockHolder();

        [$exit, $output] = migrationApiRunLockingChild($directory, 'uamswp_migration_api_tests');

        expect($output)->toContain('Refusing to run')
            ->and($output)->not->toContain('proceeded')
            ->and($exit)->toBe(1);
    } finally {
        migrationApiForgetSuiteLockHolder();
        array_map(unlink(...), glob($directory.'/*') ?: []);
        rmdir($directory);
    }
});

it('refuses ParaTest workers that inherit the holder, so a plain --parallel run cannot collide', function (): void {
    // #297's hand-back. ParaTest's main process loads the bootstrap, takes the
    // lock, exports the holder, and starts its workers through Symfony Process.
    // Granting them `inherit` let N WordPress installers drop tables under each
    // other with no message. Three workers, started before any finishes.
    $directory = sys_get_temp_dir().'/uamswp-suite-lock-'.bin2hex(random_bytes(6));
    mkdir($directory);
    putenv('LOCAL_CI_SUITE_LOCK_HELD');

    try {
        migrationApiAcquireSuiteLock('uamswp_migration_api_tests', 'pest --testsuite=Feature', $directory);

        $results = migrationApiRunLockingChildren($directory, 'uamswp_migration_api_tests', 3, ['PARATEST' => '1']);

        expect($results)->toHaveCount(3);

        foreach ($results as [$exit, $output]) {
            expect($output)->toContain('Refusing to run')
                ->and($output)->not->toContain('proceeded')
                ->and($exit)->toBe(1);
        }
    } finally {
        migrationApiForgetSuiteLockHolder();
        array_map(unlink(...), glob($directory.'/*') ?: []);
        rmdir($directory);
    }
});

it('still lets the same children through when they are not ParaTest workers', function (): void {
    // The control for the test above: the same three concurrent children,
    // without the ParaTest variables, all inherit. Without it, a refusal that
    // fired for any reason at all would pass the worker test.
    $directory = sys_get_temp_dir().'/uamswp-suite-lock-'.bin2hex(random_bytes(6));
    mkdir($directory);
    putenv('LOCAL_CI_SUITE_LOCK_HELD');

    try {
        migrationApiAcquireSuiteLock('uamswp_migration_api_tests', 'pest --testsuite=Feature', $directory);

        foreach (migrationApiRunLockingChildren($directory, 'uamswp_migration_api_tests', 3) as [$exit, $output]) {
            expect($output)->toContain('proceeded')
                ->and($exit)->toBe(0);
        }
    } finally {
        migrationApiForgetSuiteLockHolder();
        array_map(unlink(...), glob($directory.'/*') ?: []);
        rmdir($directory);
    }
});

it('reads no inherited holder inside a ParaTest worker, whichever variable marks it', function (): void {
    $name = migrationApiSuiteLockHolderEnv();

    try {
        putenv($name.'=4242');

        expect(migrationApiInheritedSuiteLockHolder())->toBe(4242, 'the control: no worker variable set');

        foreach (['PARATEST', 'TEST_TOKEN'] as $marker) {
            putenv($marker.'=1');

            expect(migrationApiIsParallelWorker())->toBeTrue($marker)
                ->and(migrationApiInheritedSuiteLockHolder())->toBeNull($marker);

            putenv($marker);
        }

        putenv('PARATEST=');

        expect(migrationApiIsParallelWorker())->toBeFalse('an empty PARATEST is not a worker');
    } finally {
        putenv('PARATEST');
        putenv('TEST_TOKEN');
        migrationApiForgetSuiteLockHolder();
    }
});

it('writes the holder to $_ENV and to $_SERVER, each on its own', function (): void {
    // #297's hand-back, finding 3. Symfony Process builds a child's environment
    // from $_ENV plus the process environment intersected with $_SERVER, so
    // EITHER array alone carries the variable to a child, and the end-to-end
    // test cannot tell if one write is deleted. Each is pinned here directly.
    $name = migrationApiSuiteLockHolderEnv();

    try {
        migrationApiForgetSuiteLockHolder();
        migrationApiExportSuiteLockHolder(4242);

        expect(getenv($name))->toBe('4242')
            ->and($_ENV[$name] ?? null)->toBe('4242')
            ->and($_SERVER[$name] ?? null)->toBe('4242');
    } finally {
        migrationApiForgetSuiteLockHolder();
    }
});

it('names itself to its children when the lock it finds already names this process', function (): void {
    // #297's hand-back, finding 2: a stale lock whose pid the OS has since given
    // to this process. The acquisition is ours without writing a file, and must
    // still export, or every `--mutate` child is refused again (#287).
    $directory = sys_get_temp_dir().'/uamswp-suite-lock-'.bin2hex(random_bytes(6));
    mkdir($directory);
    putenv('LOCAL_CI_SUITE_LOCK_HELD');

    try {
        $self = (int) getmypid();
        file_put_contents(
            migrationApiLockPath('uamswp_migration_api_tests', $directory),
            json_encode(['pid' => $self, 'suite' => 'stale'], JSON_THROW_ON_ERROR),
        );
        migrationApiForgetSuiteLockHolder();

        // The guard's `@fopen($path, 'x')` fails on the existing file by design,
        // and PHPUnit reports even a suppressed warning (failOnWarning is on).
        // Absorb exactly that message and nothing else, for this one call.
        set_error_handler(
            fn (int $number, string $message): bool => str_contains($message, 'Failed to open stream: File exists'),
            E_WARNING,
        );

        try {
            migrationApiAcquireSuiteLock('uamswp_migration_api_tests', 'pest --testsuite=Feature', $directory);
        } finally {
            restore_error_handler();
        }

        expect(migrationApiInheritedSuiteLockHolder())->toBe($self);
    } finally {
        migrationApiForgetSuiteLockHolder();
        array_map(unlink(...), glob($directory.'/*') ?: []);
        rmdir($directory);
    }
});
