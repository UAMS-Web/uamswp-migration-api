<?php

declare(strict_types=1);

/*
 * Pins the toolchain settings that a future edit could silently undo.
 *
 * Both assertions below guard against the same failure: a red build getting
 * quietly made green. Lowering the PHPStan level or restoring the machine-
 * global cache would each do that, and neither shows up as a behavior change
 * in review.
 */

function toolchainConfig(): string
{
    $path = dirname(__DIR__, 2).'/phpstan.neon.dist';

    expect($path)->toBeFile();

    return (string) file_get_contents($path);
}

it('analyzes at PHPStan level max', function (): void {
    // Opened at max with no climb: this repo had no legacy analysis debt to
    // pay down. Lowering it should be a deliberate, argued change.
    expect(toolchainConfig())->toMatch('/^\s*level:\s*max\s*$/m');
});

it('keeps the PHPStan cache scoped to this checkout', function (): void {
    // A relative tmpDir resolves against the config file's directory. The
    // default is machine-global and stores absolute phar paths, so a deleted
    // checkout poisons every later run with errors that blame whichever file
    // happened to be under analysis.
    $config = toolchainConfig();

    expect($config)->toMatch('/^\s*tmpDir:\s*\.phpstan\s*$/m')
        ->and($config)->not->toMatch('/^\s*tmpDir:\s*\//m');
});

it('enables the PHPStan bleeding-edge preview channel', function (): void {
    // Ships inside phpstan/phpstan, so it costs no dependency and keeps the
    // next major upgrade free of accumulated analysis debt.
    expect(toolchainConfig())->toContain('conf/bleedingEdge.neon');
});

it('analyzes against the PHP 8.2 deployment target', function (): void {
    // The whole local gate runs on PHP 8.4 — pest v5 and phpunit 13 both
    // require it — while production runs 8.2.30. This setting is the only
    // thing that reports a construct the deployment target cannot parse.
    // Without it a typed class constant analyzes clean and is a parse error
    // on deploy.
    $config = toolchainConfig();

    expect($config)->toMatch('/^\s*min:\s*80200\s*$/m')
        ->and($config)->toContain('phpVersion:');
});
