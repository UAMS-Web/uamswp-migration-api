# Branch work happens in a dotted slot — history

The incident history behind [`worktrees`](../rules/worktrees.md): the evidence each instruction rests on.
Not auto-loaded. Read it when a rule is questioned, before re-arguing it.

## Why this model differs from the sibling repositories

**This repository's model is not either sibling's, and the difference is structural rather than stylistic.** `uams-statamic` and `wordpress-importer` make their primary a reference tree that is never worked in. That cannot hold here, and the reason is in the layout below.

## The layout

**Dot-prefixed, because a sibling without the dot is a second copy of this plugin.** `wp-content/plugins/` is a directory WordPress scans. A worktree named `uamswp-migration-api-a` would appear on the development site's plugins screen at whatever commit it happened to hold. Core skips a dotted entry in `get_plugins()`:

```php
while ( ( $file = readdir( $plugins_dir ) ) !== false ) {
    if ( str_starts_with( $file, '.' ) ) {
        continue;                        // wp-admin/includes/plugin.php:302
    }
```

The `continue` fires **before the directory is opened**, so the contents are never read. This is not a filter on the plugins screen; the slot is invisible to WordPress entirely. The pattern is adopted rather than invented — `scripts/local-ci.mjs` has used it for its own tree since the runner landed, for exactly this reason.

**Inside `wp-content/plugins/`, because the test bootstrap locates WordPress by relative position.** `tests/wp-tests-config.php` finds WordPress four levels up from `tests/`, which holds for any checkout in that directory and for nothing outside it. A slot elsewhere needs `LOCAL_CI_WP_ABSPATH` pointed at the WordPress root before a hand-run suite will start. Slots inside the plugins directory need nothing.

## Why the primary is still a work surface

**No slot arrangement runs them.** Not a slot outside `wp-content/plugins/`, because the site would not load a plugin that is not there; and not a dotted slot inside it either, because core skips dotted entries before reading them. The primary is the only tree WordPress loads.

So the primary is claimable, for those runs and for anything else needing a loaded plugin. Making it a pure reference tree would silently retire both checks — and a check that does not run is indistinguishable from a check that passed, which is the failure this repository has repeatedly filed against itself.

## A slot at rest, and how occupancy is read

**A slot at rest is detached at `origin/main`, with a clean tree — both halves, not one.** Git permits a branch in exactly one worktree, so a slot holding a branch *is* a slot in use, and `git worktree list` answers that half without a lock file. But it reports the checked-out ref alone; it says nothing about the index or the working tree, so a slot that is detached and carries **uncommitted work** reads exactly like a genuinely free one to that command:

```
detached at origin/main, tree clean        genuinely free
detached at origin/main, 4 files staged    reads free to `git worktree list`, is not
```

A slot is free only when it is detached **and** that second command prints nothing — verified here directly: a staged, modified, or merely untracked file all show up (` M`, `A `, `??` respectively), while `git worktree list`'s own output is byte-identical whether the tree is clean or not. Illustrative, not a reading — see the provisioning note below:

```
…/uamswp-migration-api      <sha> [main]                     the primary
…/.uamswp-migration-api-a   <sha> [126-some-branch]          slot A — in use (on a branch)
…/.uamswp-migration-api-b   <sha> (detached HEAD), dirty     slot B — in use (uncommitted work, no branch)
…/.uamswp-migration-api-ci  <sha> (detached HEAD)            runner-owned
```

Both directions matter and neither substitutes for the other. **A slot left on a merged branch reads as occupied to everyone else** — leave it detached when you finish with it. **A slot left detached but dirty reads as free to `git worktree list` alone** — commit or clear your own work before stepping away, and always run the tree check before taking a slot that looks free. **If you find a slot detached and dirty, leave it and ask the other sessions whose it is — do not clean it up, and do not take it.** Cleaning up looks like the tidy move and is the one that loses somebody's patch.

**The primary is the exception, and it needs a different signal.** It holds `main` by definition, so "is it on a branch" cannot mean "is it in use" there. That question is open — see the DRY line below.

## The slots are provisioned

**Verified directly on one development machine:** `git worktree list` returns the primary, both slots, and the runner's own tree, and both slots have been bootstrapped (`composer install`, `npm ci`) since 2026-09-08. A second development machine provisioned its own pair on 2026-09-10 and has been branching and building in slot A since — reported by a session on that machine, not independently verified from here.

#131, which tracks provisioning, stays open for the one item neither machine has checked yet: confirming by observation, on a real development site, that the plugins screen does not list a dotted slot — which the dot-prefix argument above currently rests on reading core alone.

## The CI tree belongs to the runner

**There is exactly ONE CI tree, whichever worktree the chain is invoked from.** The path is derived from the **main** checkout, never from the invoking one, so a run started in slot A and a run started in the primary resolve to the same `.uamswp-migration-api-ci`. Until #184 it was derived from the invoking worktree, and a chain run from slot A minted `..uamswp-migration-api-a-ci` — a second tree, a leading double dot, and a full `vendor/` and `node_modules/` per slot that had ever run the chain. They were not independent: `DEFAULT_DB_NAME` is a single constant, so every one of those trees used the same database.

**One tree is the correct shape and it is not the same thing as safe.** Two chains on one machine now contend for that single tree rather than quietly forking it, which is the more visible failure and also the more disruptive one — a second run can take the worktree out from under a live gate run. What actually holds the line is the single-flight refusal (#119): the runner takes a lock on the CI worktree path **and** on the database, and refuses to start when either is held. Do not read the one-tree guarantee as permission to run two chains at once.

The runner resolves the WordPress root from the **repository** rather than from its worktree and passes it as `WP_TESTS_ABSPATH`, so the CI tree works wherever it is placed. That is what makes `--worktree-path` safe, and it is why the runner's tree is the one part of this layout that was already solved.

## What this does not solve

**Two sessions in one tree still collide.** A slot per session removes the common case; it does not remove the primary, which is shared by construction. [`long-running-commands`](../rules/long-running-commands.md) attributes processes by working directory and cannot separate two sessions in the same checkout (#98), and nothing here changes that for the primary.

**Two concurrent runners still share the CI tree and its database.** Slots are prevention; a refusal is still needed, and #119 covers it.
