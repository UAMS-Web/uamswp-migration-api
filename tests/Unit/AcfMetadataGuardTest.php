<?php

declare(strict_types=1);

/*
 * Drives both arms of the `function_exists()` guard in
 * `FieldReader::storedMetadata()` (#22, D-069, D-107).
 *
 * `acf_get_metadata_by_field()` is `@since 6.4`. An ACF older than that does
 * not define it, and the unguarded call raised a fatal error on the go.uams.edu
 * network when `posts/page` read site 3. The Integration suite cannot reach
 * that arm: ACF is loaded before any test runs there, so the predicate is fixed
 * for the whole process and a loaded function cannot be undefined.
 *
 * So each arm runs in a child PHP process of its own, with no WordPress and no
 * ACF, and stand-ins for exactly the ACF functions that arm should see. The
 * `legacy` child defines `acf_get_metadata()` only, which is the pre-6.4 shape;
 * the `native` child defines both. Each child calls the private method through
 * reflection and prints which stand-ins were called and what came back.
 *
 * A child per arm, rather than declaring the stand-ins in this process, because
 * a function declared here stays declared for every later test in the Unit
 * process, and the `native` stand-in would turn the `legacy` arm into the one
 * that is never run.
 *
 * Removing the guard makes the `legacy` child die with `Call to undefined
 * function`, which the exit-code assertion below reports. What the stand-ins
 * cannot show is that the two ACF functions agree on real data: that is
 * `tests/Integration/Feature/ACF/AcfMetadataFallbackTest.php`.
 */

/**
 * Run `storedMetadata()` in a child process against stand-in ACF functions.
 *
 * @param  'legacy'|'native'  $arm  Which ACF API the child pretends to have.
 * @param  list<array{0: int|string, 1: array<string, mixed>}>  $reads  Object id and field definition, one per call.
 * @return array{0: int, 1: string} The exit code and the combined output.
 */
function acfMetadataGuardRun(string $arm, array $reads): array
{
    $root = \dirname(__DIR__, 2);

    $directory = sys_get_temp_dir().'/uamswp-acf-metadata-guard-'.bin2hex(random_bytes(6));
    mkdir($directory);

    $script = $directory.'/probe.php';

    // The child is generated rather than committed so PHPStan, which analyzes
    // `tests/`, never sees a second declaration of an ACF function it already
    // knows from the ACF sources it scans.
    file_put_contents($script, <<<'PHP'
<?php

declare(strict_types=1);

require $argv[1].'/vendor/autoload.php';

$GLOBALS['acfGuardCalls'] = [];

function acf_get_metadata(int|string $id, string $name, bool $hidden = false): mixed
{
    $GLOBALS['acfGuardCalls'][] = ['acf_get_metadata', $id, $name];

    return $name === 'stored' ? 'legacy row' : null;
}

if ($argv[2] === 'native') {
    /** @param array<string, mixed> $field */
    function acf_get_metadata_by_field(int|string $id, array $field): mixed
    {
        $GLOBALS['acfGuardCalls'][] = ['acf_get_metadata_by_field', $id, $field['name'] ?? null];

        return ($field['name'] ?? null) === 'stored' ? 'native row' : null;
    }
}

$method = new ReflectionMethod(UAMSWP\MigrationAPI\ACF\FieldReader::class, 'storedMetadata');

$results = [];

foreach (json_decode($argv[3], true) as [$id, $field]) {
    $results[] = $method->invoke(null, $id, $field);
}

echo json_encode(['results' => $results, 'calls' => $GLOBALS['acfGuardCalls']]);
PHP);

    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($root)
        .' '.escapeshellarg($arm).' '.escapeshellarg((string) json_encode($reads)).' 2>&1';

    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    unlink($script);
    rmdir($directory);

    return [$exitCode, implode("\n", $output)];
}

/**
 * The child's report, decoded, failing the test when the child did not finish.
 *
 * @param  'legacy'|'native'  $arm
 * @param  list<array{0: int|string, 1: array<string, mixed>}>  $reads
 * @return array{results: list<mixed>, calls: list<array{0: string, 1: int|string, 2: mixed}>}
 */
function acfMetadataGuardReport(string $arm, array $reads): array
{
    [$exitCode, $output] = acfMetadataGuardRun($arm, $reads);

    // The exit code first: an unguarded call dies here, and its message is the
    // evidence worth reading.
    expect($exitCode)->toBe(0, $output);

    $report = json_decode($output, true);

    expect($report)->toBeArray()->toHaveKeys(['results', 'calls']);

    /** @var array{results: list<mixed>, calls: list<array{0: string, 1: int|string, 2: mixed}>} $report */
    return $report;
}

it('reads through `acf_get_metadata()` where `acf_get_metadata_by_field()` does not exist', function (): void {
    $report = acfMetadataGuardReport('legacy', [
        [42, ['key' => 'field_stored', 'name' => 'stored']],
        [42, ['key' => 'field_absent', 'name' => 'absent']],
        ['term_7', ['key' => 'field_stored', 'name' => 'stored']],
        ['options', ['key' => 'field_absent', 'name' => 'absent']],
    ]);

    // A row and no row, on each of the three object shapes the reader passes,
    // so a fallback that answered "stored" everywhere fails here as well as
    // one that is not reached at all.
    expect($report['results'])->toBe(['legacy row', null, 'legacy row', null])
        ->and($report['calls'])->toBe([
            ['acf_get_metadata', 42, 'stored'],
            ['acf_get_metadata', 42, 'absent'],
            ['acf_get_metadata', 'term_7', 'stored'],
            ['acf_get_metadata', 'options', 'absent'],
        ]);
});

it('asks nothing of the fallback for a field with no usable name', function (): void {
    // The 6.4 call bails on an empty name; the fallback must not instead ask
    // for a meta key of '' or of a non-string.
    $report = acfMetadataGuardReport('legacy', [
        [42, ['key' => 'field_unnamed', 'name' => '']],
        [42, ['key' => 'field_nameless']],
        [42, ['key' => 'field_numeric', 'name' => 7]],
    ]);

    expect($report['results'])->toBe([null, null, null])
        ->and($report['calls'])->toBe([]);
});

it('treats a field named `0` as a name on the fallback', function (): void {
    // `empty('0')` is true in PHP, and the reader deliberately does not copy
    // that quirk into its contract.
    $report = acfMetadataGuardReport('legacy', [
        [42, ['key' => 'field_zero', 'name' => '0']],
    ]);

    expect($report['calls'])->toBe([['acf_get_metadata', 42, '0']]);
});

it('still uses `acf_get_metadata_by_field()` where it exists', function (): void {
    $report = acfMetadataGuardReport('native', [
        [42, ['key' => 'field_stored', 'name' => 'stored']],
        ['term_7', ['key' => 'field_absent', 'name' => 'absent']],
    ]);

    // The fallback must not be reached at all on 6.4: it bypasses the
    // meta-location indirection 6.4 added.
    expect($report['results'])->toBe(['native row', null])
        ->and($report['calls'])->toBe([
            ['acf_get_metadata_by_field', 42, 'stored'],
            ['acf_get_metadata_by_field', 'term_7', 'absent'],
        ]);
});
