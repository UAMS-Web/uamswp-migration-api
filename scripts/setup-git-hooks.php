<?php

/**
 * Point git at the committed `.githooks/` directory, so the CI-parity
 * pre-commit hook is active in every clone without anyone running the config
 * command by hand.
 *
 * A hook nobody installs is a hook nobody has. `.git/hooks/` is not tracked,
 * so the only way a committed hook takes effect is `core.hooksPath` — and
 * leaving that to a line in a README means it is set on the machine of whoever
 * wrote it and nowhere else.
 *
 * Invoked from composer `post-install-cmd` / `post-update-cmd`. It is a no-op
 * outside a git work tree (a dist install, an artifact build), so Composer can
 * never fail on a checkout that has no `.git`.
 */

declare(strict_types=1);

// Bail quietly when this is not a git work tree — there is nothing to configure
// and failing here would break an install that is otherwise fine.
exec('git rev-parse --is-inside-work-tree 2>&1', $output, $exitCode);

if ($exitCode !== 0 || trim(implode('', $output)) !== 'true') {
    return;
}

// Idempotent, so it is safe on every install and update.
exec('git config core.hooksPath .githooks', $configOutput, $configExit);

if ($configExit === 0) {
    echo "CI-parity git hook installed (core.hooksPath -> .githooks).\n";

    return;
}

fwrite(
    STDERR,
    "Warning: could not set core.hooksPath; run 'git config core.hooksPath .githooks' manually.\n"
);
