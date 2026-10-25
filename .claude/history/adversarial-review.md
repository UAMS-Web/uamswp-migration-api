# Rule — adversarially verify before shipping — history

The incident history behind [`adversarial-review`](../rules/adversarial-review.md): the evidence each instruction rests on.
Not auto-loaded. Read it when a rule is questioned, before re-arguing it.

## Why this is a standing order

**Why this is a standing order, not a nicety.** The author and the author's tests share the same blind spots; an adversary with a fresh, hostile frame does not. A green suite is necessary, never sufficient — it passes on every slice while structurally *cannot* catch the defects it wasn't written to look for: a false-green test that stays green with its target deleted, an authorization gate that covers every route it knows about and misses the one core generates, or a serialized option that round-trips wrong for exactly one plugin. "Tests pass" earns the build; adversarial review earns the ship.

This repo makes the argument concretely. Its **defining failure mode is a well-formed response full of wrong data** — a 200 with stale values, a page that silently skipped a record, an options payload missing every underscore-prefixed key. Nothing about that shape is red. It is caught by someone deliberately looking for it, or not at all.

## How to apply

From step 1, the claim half:

**The claim half is not a second discipline, it is the same one applied to a sentence.** A finding is the artifact this repository produces most often — [`pre-merge-check`](../rules/pre-merge-check.md) requires one before every merge — and it decides whether a merge proceeds, so it earns the scrutiny the diff gets. What discharges it for a claim about text this repository produced is **execution rather than reading**, and the reason a pattern will not do it is that the probe and the material share an author: both encode the same assumption, so a second pattern reproduces the blind spot of the first. That argument and its method belong to [`reading-exit-status`](../rules/reading-exit-status.md) and are not repeated here.

From step 4, the site-scoping risk surface:

The switch branch is still unexercised locally (#12).

From step 6, the reasons behind revert-by-copy:

The stash stack is shared across worktrees and a failed `stash push` followed by `stash pop` applies somebody else's entry; and `git checkout --` throws away uncommitted work.

## Tooling that raises the floor (so the review can reach the ceiling)

*And the criterion has a blind spot worth naming rather than discovering by surprise: a false kill leaves nothing on the survivor list to explain. `#287` found every covered mutant reporting **killed** because this repository's own single-flight guard refused each mutant's child process — so the score read 100% while three real survivors went unseen. A false survivor is caught by this discipline; a false kill is invisible to it by construction. See [`pest-testing`](../skills/pest-testing/SKILL.md#mutation-testing) for the mechanism, the fix, and the Windows failures that remain.*

A trailing marker is silently inert or worse (it can suppress the wrong statement instead), and a run cannot tell that from a wrong reason — both look like an ordinary surviving mutant.

A percentage aggregates the two classes it most needs to separate, because an unexamined survivor and a provably equivalent one are the same number under a threshold while being opposite states; and raising the bar makes that worse, since the last few percent are usually the equivalent mutants, so a high threshold converts "explain this survivor" into "delete a defensive guard to move the number".

## Original wording of passages condensed in place

These paragraphs were shortened in [`adversarial-review.md`](../rules/adversarial-review.md) rather than moved. Their full wording as it stood before the move is kept here, in file order.

**A claim about a dependency's code carries the revision it was measured at.** "Present in `<package>`" is incomplete the way an absence without a search scope is incomplete. A pinned or vendored copy can report the correct version, and the correct source reference, while containing different code — so a version name is not a revision, and the two diverge exactly when it matters. Name the commit, tag or lock entry the reading came from, and say which tree was read: the installed copy, or the upstream default branch, which are routinely not the same thing. A reading taken from one and reported as the other is a true measurement attached to the wrong subject.

3. **Keep the review agents out of the tree being validated.** Prompt them read-only, and mean it about *writes of every kind*. Once #15 lands a `wp-phpunit` suite, running it is a write: the WordPress test database is shared, so an agent "just checking whether the test passes" collides with any concurrent run and the scattered failures it produces read as real regressions. Either forbid test execution in the prompt, give the agents `isolation: "worktree"`, or (simplest) do not have a validation run in flight at all — which the sequencing above already gives you. Committing the diff first is still required so agents review a stable tree.

- **The auth gate** ([`includes/Auth.php`](https://github.com/UAMS-Web/uamswp-migration-api/blob/main/includes/Auth.php)) — the API serves unpublished content and raw site settings, so any anonymous path is a disclosure. The namespace gate exists because core auto-generates a namespace index route with no permission callback and it leaked the full route table; assume there is another route nobody registered.

- **Site scoping** ([`includes/Site.php`](https://github.com/UAMS-Web/uamswp-migration-api/blob/main/includes/Site.php)) — a missed `restore_current_blog()`, or an ordering change that puts the capability check on the wrong site, reads data from a site the caller may not access. The switch branch is still unexercised locally (#12).

- **The option denylist** ([`config/defaults.php`](https://github.com/UAMS-Web/uamswp-migration-api/blob/main/config/defaults.php)) — it is a denylist, so the failure direction is *leaking a credential*, not dropping one. Every new option key is a fresh chance to ship a secret.

- **Anything reading raw postmeta** — the underscore-prefix trap. A filter that looks like hygiene silently drops ACF field keys, `_menu_item_*` and `_thumbnail_id`.

- **Pagination** ([`includes/Pagination.php`](https://github.com/UAMS-Web/uamswp-migration-api/blob/main/includes/Pagination.php)) — ordering must stay ID-ascending. Date ordering lets a record move between pages while editors work, so a migration reads it twice or never. A skipped record produces no error anywhere.

6. **Triage and close the loop.** Fix every **blocker / major** and every cheap, high-confidence **minor**. For a real bug fixed, add a regression test and **verify the test actually fails without the fix** (disable the fix → the test must go red; re-enable → green). **Revert by copying the file aside, never with the stash** — `cp includes/Auth.php "$SCRATCH/Auth.fixed.php"`, `git show HEAD:includes/Auth.php > includes/Auth.php`, run, restore from the copy. The stash stack is shared across worktrees and a failed `stash push` followed by `stash pop` applies somebody else's entry; and `git checkout --` throws away uncommitted work. Read the *reason* the test went red, not just that it did — a negative control that fails for the wrong reason proves nothing, and a mutation applied with a shell one-liner can corrupt the file instead of editing it, which reads as a very convincing red. A **pre-existing** bug a new slice merely *touches or reuses* is in scope — fix it. **Document** accepted gaps and deferred residue honestly (named, never silent). **Re-run the gate** (`composer ci`) after the fixes. Only then ship.

The human review is irreplaceable for *novel* reasoning (a threat model, "is this structure sound?"). Two cheap, durable tools catch the **mechanical** half of what review keeps finding. One of them is wired here and one is not, so they are stated separately rather than together:

- **Mutation testing — wired, and run with `composer mutate`.** Pest's `--mutate` mutates your code and demands a test fail; a **surviving mutant is a false-green test** — exactly the class review catches repeatedly. **The criterion is no unexplained survivors, never a score.** *And the criterion has a blind spot worth naming rather than discovering by surprise: a false kill leaves nothing on the survivor list to explain. `#287` found every covered mutant reporting **killed** because this repository's own single-flight guard refused each mutant's child process — so the score read 100% while three real survivors went unseen. A false survivor is caught by this discipline; a false kill is invisible to it by construction. See [`pest-testing`](../skills/pest-testing/SKILL.md#mutation-testing) for the mechanism, the fix, and the Windows failures that remain.* Every survivor ends in one of three states: killed, deleted as dead code, or annotated with the reason it cannot be killed — **only one placement of that annotation actually suppresses the mutant: standalone, on the line above the node, never trailing on the node's own line.** A trailing marker is silently inert or worse (it can suppress the wrong statement instead), and a run cannot tell that from a wrong reason — both look like an ordinary surviving mutant. A survivor is a test to write, dead code to delete, or a correctly-placed annotation; see [`pest-testing`](../skills/pest-testing/SKILL.md#mutation-testing) for the failure shapes and why they matter. There is **no minimum-score gate, and mutation is not part of `composer ci`**. A percentage aggregates the two classes it most needs to separate, because an unexamined survivor and a provably equivalent one are the same number under a threshold while being opposite states; and raising the bar makes that worse, since the last few percent are usually the equivalent mutants, so a high threshold converts "explain this survivor" into "delete a defensive guard to move the number". It needs a coverage driver (PCOV or Xdebug — a per-machine install that does not travel via git, so it is set up once per developer rather than checked in). **`0 Mutations for 0 Files created` means it found nothing to mutate, not that you passed** — treat that as a setup failure, never a green. The annotation's syntax, the equivalent-mutant judgement, and which classes can be mutated at all belong to [`pest-testing`](../skills/pest-testing/SKILL.md) and to `docs/DECISIONS.md` D-023.

- **Property-based / round-trip tests — not wired here.** For the pure cores — meta serialization, pagination bounds, the option filter. Assert the load-bearing **invariant** over many generated inputs instead of three examples (`decode(encode(x)) == x`, "every stored meta key survives the response", "no page boundary drops or repeats an ID"). Use a fixed seed: deterministic, non-flaky, still broad.

Both **raise the floor cheaply and forever**; neither invents the threat model. The discipline is *both* — tooling for the mechanical classes, the adversarial review for the novel ones.

This file is the standing statement of the discipline. The `Workflow` tool description holds the orchestration *mechanics* (the review-pattern examples — adversarial verify, perspective-diverse lenses, loop-until-dry) — don't restate it. It composes with [`design-decision-forks`](../rules/design-decision-forks.md) (surface genuine forks *before* building); the build→ship sequence itself (TDD, a green gate, branch, PR) lives in [`AGENTS.md`](../../AGENTS.md).
