# Mutation testing in this repository

The shared [`pcov-setup`](../.claude/skills/pcov-setup/SKILL.md) skill is the procedure: getting a coverage driver working, the invocation, and the checks that tell a run that executed from one that did not. Read it first. This file holds only what is specific to this repository, which that skill does not carry. It replaces the local `coverage-driver` skill (#367).

## `php84` is the version-pinned binary to try first

When `pcov-setup`'s state check exits 2 and it says to try a version-pinned binary, try `php84 -v` first. The gate resolves `php84` for every PHP job but one by construction: the shared local CI runner prefers Herd's version-pinned binary for the `php` that [`local-ci.json`](../local-ci.json) names, `8.4`, over the default PHP. (The `lint-floor` job names `8.2` and needs no coverage driver.) So a working `php84` keeps the gate available while the default build is investigated.

## A hand-run pass takes the same shape as `composer mutate`

`composer mutate` runs one pass per class from the Integration suite's own Composer setup (D-092), each `@php tests/Integration/vendor/bin/pest --configuration=tests/Integration/phpunit.xml --test-directory=. --mutate --covered-only --path=includes/<File>.php`, for `Pagination`, `Config` and `Auth` ([`composer.json`](../composer.json)). The mutate plugin and its patches live in that setup, not the root one, so a pass run by hand, for example under Herd's bundled Xdebug, uses the same binary and the same two options:

```bash
php -d zend_extension=/Applications/Herd.app/Contents/Resources/xdebug/xdebug-84-arm64.so \
    -d xdebug.mode=coverage \
    tests/Integration/vendor/bin/pest --configuration=tests/Integration/phpunit.xml --test-directory=. \
    --mutate --covered-only --path=includes/Pagination.php
```

Pest removes `--test-directory` from argv before any plugin runs, so every mutant child is started without it. `tests/Integration/bootstrap.php` loads `Pest.php` itself so that a child's Feature tests still run inside `WordPressTestCase` and its rolled-back transaction (#443, D-102). Before that, a child's Feature tests leaked rows into each other and could score a mutant killed for no reason the mutant caused, so no `composer mutate` result between #375, which moved the suite under `tests/Integration`, and D-102 counts as measured. A mutant reported `timeout` is counted as caught and is not evidence of a kill. A child widened to class filters runs nearly the whole suite, so it gets at least three times the initial run rather than the plugin's limit (#447, D-103); a timeout on any other child still means the mutant ran past the plugin's limit.

The `pcov-setup` example runs the root `vendor/bin/pest`, which here is Pest 5 over the `Unit` suite alone: its own, unpatched mutate plugin and none of the tests that kill these mutants. On Windows, run it through `composer mutate` for the reason `pcov-setup` gives. Which classes can be mutated at all, and why, is D-023.

`pcov-setup`'s `check-mutation-instrument.php` reads the root `vendor/` by default, which is not the plugin `composer mutate` runs. Point it at the Integration setup: `php .claude/skills/pcov-setup/scripts/check-mutation-instrument.php --root=tests/Integration`.
