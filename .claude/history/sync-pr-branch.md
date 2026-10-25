# Sync every PR branch from `origin/main` — history

The incident history behind [`sync-pr-branch`](../rules/sync-pr-branch.md): the evidence each instruction rests on.
Not auto-loaded. Read it when a rule is questioned, before re-arguing it.

## Why this is a standing order

**Why this is a standing order.** A branch left behind `origin/main` is validated against stale code. When it touches a surface a since-merged change also touched, it can merge to a combined state that was **never tested together**: semantic drift, or un-formatted files slipping past the gate. The failure is silent — the branch's own gate stays green because it never saw the interacting change.

This repo is small enough that the shared surface is nearly everything. `includes/` is eight files; `phpstan.neon.dist`, `rector.php`, `pint.json` and `composer.json` are single files every change reads. Two branches open at once are almost never file-disjoint here, which makes the sync cheap and skipping it correspondingly risky. A repo-wide reformat is the classic case: it rewrites files a concurrent branch also edits, and neither branch's gate can see the other's version.

## How to apply

1. **Author side — sync before the gating run and before opening the PR.** `git fetch origin && git merge origin/main` (or rebase onto it) *before* running `composer ci` and *before* `gh pr create`. A conflict surfaced here is one caught before review rather than after merge.
2. **Validator side — sync before the gating run when the branch is behind an interacting change**, and keep local `main` current so the diff base is right. This matters most for the Pint check, which is scoped to files changed against the base: a stale base distorts the changed-file set, so a file that needs formatting can go unexamined.
3. **The exception — genuinely independent branches need not chase every `main` advance.** A branch the intervening merges cannot reach is validated correctly as-is. Given the repo's size, verify that rather than assume it: `git diff --name-only origin/main...HEAD` against the same list for the intervening commits.

   **Compare against `origin/main`, never against local `main`.** A stale local ref attributes every merge since you last pulled to the other branch, which manufactures an overlap that is not there. Measured here: the same command returned **13 files** against a local `main` twelve merges behind and **2** against `origin/main`, for a branch whose real surface was two.

4. **But "no shared file" is not the test. "Nothing the gate reads has moved" is.** Some inputs are consulted for *every* file the chain examines, so two branches can share no filename and still decide each other's result. A changed-file comparison cannot see that by construction — the interaction runs through a file neither branch appears to touch.

   **`project-words.txt` is the one that bites, because adding a word is routine.** `cspell.json` declares it as a dictionary with no per-path scoping, and the `spell` script globs `**/*.{php,js,mjs,md,yml,yaml,json}` plus `.github/**`, so the dictionary applies to the whole corpus rather than to the files that changed with it. A branch that adds or removes an entry is never independent of one whose prose depends on that entry, however disjoint their filenames look. Two branches added words on a single evening here — `alrm`, `setpgrp`, `sigalrm` and `waitpid` in one, a `git log` flag in another — while their rule files did not overlap at all. **The second of those is not quoted literally, and the reason is this rule's own subject:** writing the word here would make this file depend on a dictionary entry that has not merged yet, so this branch's `spell` job would go red against `origin/main` and green only after the other branch landed. Verified by doing it accidentally — the word was in this paragraph, `spell` failed on it, and the two branches shared no rule file.

   **The direction that bites is removal or rename**, not addition: an added word can only widen what passes, while an entry a branch's prose depends on disappearing under it turns a green into a red that neither gate saw.

   **The other global inputs, checked rather than assumed** — each read for the whole corpus regardless of what changed:

   `.github/workflows/ci.yml` is the same class and is inert while `RUN_REMOTE_CI` is off. Pint is the instructive one: the *job* is scoped to changed files, and its *ruleset* is not, so scoping the run says nothing about scoping the input.

5. **The exception survives, and deliberately so.** For genuinely independent surfaces the rule is right that chasing every advance is waste, and in a repository merging several times an hour it does not converge. What changed is the question: not *do the changed-file sets intersect*, but *did the intervening merge move anything this branch's gate reads*. Answer the second and the first is usually enough to settle it.
