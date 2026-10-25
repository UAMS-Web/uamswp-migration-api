<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\ClassMethod\LocallyCalledStaticMethodToNonStaticRector;
use Rector\CodingStyle\Rector\ClassMethod\MakeInheritedMethodVisibilitySameAsParentRector;
use Rector\CodingStyle\Rector\FuncCall\VersionCompareFuncCallToConstantRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveDuplicatedReturnSelfDocblockRector;
use Rector\DeadCode\Rector\ConstFetch\RemovePhpVersionIdCheckRector;
use Rector\DeadCode\Rector\If_\UnwrapFutureCompatibleIfPhpVersionRector;
use Rector\DeadCode\Rector\Property\RemoveUselessVarTagRector;
use Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector;
use Rector\Php81\Rector\Property\ReadOnlyPropertyRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use Rector\ValueObject\PhpVersion;

// Two profiles over one rule corpus. This file is the single source of truth
// for the sets both share; the profile only changes which rules are skipped.
//
//   Gating profile (default) — the rules safe to make mandatory. This is what
//   `composer test:refactor` reports on.
//
//   Sweep profile — every rule enabled here, including the sweep-only ones
//   below. Run it deliberately and review the diff:
//     composer test:refactor:sweep   # preview
//     composer refactor:sweep        # apply
//
// `rector-sweep.php` is a two-line shim that defines the constant below and
// requires this file, so neither profile can drift from the other.
$isSweepProfile = defined('UAMS_RECTOR_SWEEP') && UAMS_RECTOR_SWEEP;

// Rules excluded from the gating profile. Each is a rule whose suggestion is a
// judgment call rather than a mechanical one, so making it mandatory would turn
// a wrong suggestion into a change that *must* be applied or explicitly
// skipped. They stay fully active under the sweep profile.
$sweepOnlyRules = [
    // `readonly` promotion is a semantic change, not a style one: it interacts
    // badly with code that mutates after construction, with cloning, and with
    // mocking in tests. Worth doing deliberately, with the runtime consequences
    // reviewed — not on pain of a red gate.
    ReadOnlyClassRector::class,
    ReadOnlyPropertyRector::class,

    // Unsound at this config's path scope. Rector cannot see a static call from
    // a WordPress hook registered as a `[Class::class, 'method']` callable, and
    // will happily demote a method that WordPress still calls statically.
    LocallyCalledStaticMethodToNonStaticRector::class,

    // Docblock removal conflicts with the PHPDoc conventions these repos use.
    // What they reach is past what Pint owns: inline `@var` on local variables
    // pinning an otherwise-untyped value, and `@return $this` beside a
    // `: static`. Those are deliberate annotations, not leftovers.
    RemoveUselessVarTagRector::class,
    RemoveDuplicatedReturnSelfDocblockRector::class,

    // `add_action('x', [$this, 'method'])` registers a callback WordPress later
    // matches *by identity* for `remove_action()` / `has_action()`. A
    // first-class callable builds a fresh Closure at each evaluation, so the
    // handle used to register can never equal the one used to remove — hook
    // removal silently stops working, with nothing to see at the call site.
    // See docs/DECISIONS.md D-016.
    ArrayToFirstClassCallableRector::class,
];

// Skipped in BOTH profiles, scoped to the one file that carries the runtime
// guard — not a judgment call, a safety one.
//
// Three separate rules converge on the same target: the PHP 8.2 check in the
// plugin header. Each reasons from composer.json's `^8.2` and concludes the
// branch is unreachable, so between them they would delete the version test,
// the admin notice, and the early return. The branch exists precisely for the
// case that reasoning cannot cover — a site downgraded underneath an
// already-active plugin — where the alternative to this code is a fatal error
// instead of a notice. Path-scoped rather than blanket-skipped so the rules
// still apply everywhere else.
$guardFile = [__DIR__.'/uamswp-migration-api.php'];

$neverRules = [
    UnwrapFutureCompatibleIfPhpVersionRector::class => $guardFile,
    RemovePhpVersionIdCheckRector::class => $guardFile,
    VersionCompareFuncCallToConstantRector::class => $guardFile,
    // `factory()` is widened to public on purpose, so a Pest closure reaches it
    // as a typed static call; its docblock says why. Rector began proposing the
    // narrowing when the test toolchain split in two (#375).
    MakeInheritedMethodVisibilitySameAsParentRector::class => [__DIR__.'/tests/Support/WordPressTestCase.php'],
];

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/includes',
        __DIR__.'/config',
        __DIR__.'/scripts',
        __DIR__.'/tests',
    ])
    ->withRootFiles()
    // A cache per profile and per checkout. Rector's file cache does not key on
    // the active rule set, so a cache warmed by the sweep profile makes a later
    // gating run silently UNDER-report: a false green. Separate directories make
    // that impossible rather than relying on every runner to start each gating
    // run in a fresh temp dir, which the private runner did before #366.
    ->withCache(cacheDirectory: sys_get_temp_dir().'/rector-uamswp-migration-api-'
        .($isSweepProfile ? 'sweep' : 'gating').'-'.substr(hash('sha256', __DIR__), 0, 12))
    ->withSkip([
        // The Integration suite's own Composer setup (#375): a dependency
        // tree inside `tests/`, never first-party code.
        __DIR__.'/tests/Integration/vendor',
        ...$neverRules,
        ...($isSweepProfile ? [] : $sweepOnlyRules),
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        codingStyle: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    )
    ->withPhpSets()
    // The supported floor, stated rather than read from composer.json, so a
    // rewrite never produces syntax PHP 8.2 cannot parse even though every
    // tool here runs on 8.4 (#375).
    ->withPhpVersion(PhpVersion::PHP_82)
    // Promote inline FQCNs to `use` imports, matching the import-FQCN
    // convention. Leave global/short classes alone to avoid needless churn.
    //
    // `removeUnusedImports: false` disables Rector's unused-import removal,
    // which — unlike Pint's docblock-aware `no_unused_imports` — is not
    // `{@see}`-aware and silently strips imports referenced only inside a
    // `{@see ClassName}` docblock tag. Pint remains the single owner of
    // unused-import *removal*, so `{@see}`-only imports survive
    // `composer refactor`.
    ->withImportNames(importShortClasses: false, removeUnusedImports: false);
