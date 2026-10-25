# Keep session-coordination plumbing out of GitHub artifacts — history

The incident history behind [`coordination-plumbing-stays-out-of-artifacts`](../rules/coordination-plumbing-stays-out-of-artifacts.md): the evidence each instruction rests on.
Not auto-loaded. Read it when a rule is questioned, before re-arguing it.

## Why this is a standing order

An artifact is read by people and tools that were not present when it was written, and that have no access to the coordination channel at all. A finding whose evidence is expressed in that channel's terms is therefore **unverifiable to its own audience** — the reader cannot inspect a roster, cannot resolve a session label, and cannot tell whether the claim was ever true. A machine alias is worse than useless: it identifies a person's box and buys the reader nothing.

The failure is not hypothetical and it is not obvious while writing. `UAMS-Web/wordpress-importer#849` was filed with a machine alias in its measurement line, three session labels in its evidence table, and a consequence argued entirely through liveness vocabulary — a chain from "a swept process stops publishing" to "its owner reads as stale" to "a takeover then looks justified." Every step was true, and none of it was checkable by anyone reading the tracker.

**The rewrite is the argument for this rule.** The same defect, restated as "three long-running background processes, each started by a different concurrent session and distinguishable only by their command-line arguments," kept every process id, path, date and platform, and lost nothing. Its consequence was re-argued from harms the repo already documents — a killed run destroys what it was going to emit, a benchmark taken beside the wreckage is not a benchmark, an interrupted correctness run returns failures indistinguishable from regressions. That version is **stronger**, because it stands on things the reader can check.

## How to apply

3. **Keep the evidence; drop the proper nouns.** This rule removes provenance vocabulary, never data. Process ids, paths, counts, dates, commands, error strings and the platform a measurement was taken on all stay. An artifact that loses its numbers along with its labels is worse than the one it replaced.

4. **Audit the draft before publishing**, because this leaks in while writing rather than as a deliberate choice:

   **The placeholder is why this one command needs a two-sided control rather than a glance.** Left as written it cannot match an alias, so a clean result proves nothing about the half of the rule that matters most; substituted wrongly it also cannot, and looks identical. Run it once against text you know carries plumbing before trusting a clean result, per [`an-empty-result-is-not-evidence`](../rules/an-empty-result-is-not-evidence.md).

5. **For a PULL-REQUEST body, audit again at the merge — publishing is not the last chance, it is the first one.** A body is not only a page that can be corrected later. Merging composes it into a commit message, and that message is never rewritten here, so a body cleaned up afterwards fixes what a reader visits and leaves the original wording on `main` permanently. [`github-api-budget`](../rules/github-api-budget.md) carries the measurement of that composing behavior and [`pre-merge-check`](../rules/pre-merge-check.md) carries the merge-gate step; neither is restated here.

   **A correction after the merge does not reach the commit message. Measured on #228:**

   ```
   the body, after correction                   0 plumbing hits
   the squash commit message it composed        2 hits, permanent
   a body-only heading present in that message  1
   the same heading in the 6 branch commits     0
   ```

   The last two rows are the control: they establish the message was composed from the body rather than from the commits, so the two figures above them are about the same text at two moments rather than about two different sources. The body was clean when it was read; the message was not, because it had been composed hours earlier from the version that was not.

   **The remedy is unavailable by design**, which is what makes the timing load-bearing rather than merely tidy: rewriting the message means rewriting `main`, which this repository does not do. A reader of `git log` then meets vocabulary the tracker no longer shows, with nothing to explain the discrepancy.

## What this does not cover

- **Vocabulary the project owns independently.** A word is not plumbing because it also appears in the coordination tooling. This repo's validation pipeline has a *watcher* of its own — [`scripts/watch-github.mjs`](../../scripts/watch-github.mjs), documented under *The watcher* in [`local-ci`](../skills/local-ci/SKILL.md) — and that term is ordinary project vocabulary with its own history in the tracker. A sweep must not strip it. The test is whether the term names coordination infrastructure in the sentence it appears in, not whether it pattern-matches.

  **The first run of the audit above produced exactly this, which is why the carve-out is not hypothetical.** Across five recently-published bodies it returned one hit and zero machine aliases: the word *stale* in `#199`, in the phrase "sweep with a stale copy" — an out-of-date file, not a session that stopped publishing. A sentence to read and keep, not a word to delete.

- **This file.** A rule that forbids naming something must name it, and describing the prohibition is the one legitimate use. Note that this file deliberately *describes* the channel and the alias pattern rather than reproducing either, so a repo-wide sweep stays clean and the rule that forbids the leak is not its own only hit.

## The DRY line

This file is the standing statement on **provenance vocabulary in outward-facing artifacts**. It composes with [`impersonal-voice-in-github-artifacts`](../rules/impersonal-voice-in-github-artifacts.md), which governs a different leak in the same sentences — that rule removes the *narrator*, this one removes the *plumbing*, and a sentence can easily need both. What goes *in* a body belongs to the [`writing-issues`](../skills/writing-issues/SKILL.md) and [`writing-pull-requests`](../skills/writing-pull-requests/SKILL.md) skills, and subject/body mechanics to [`writing-commits`](../skills/writing-commits/SKILL.md); each points here rather than restating it. **When** the audit runs for a pull-request body is shared with [`pre-merge-check`](../rules/pre-merge-check.md), whose merge-gate step requires this sweep against the body about to be composed, and whose closing-keyword step carries the composing behavior that makes the timing matter. **Named rather than numbered, deliberately:** a step number cited from another file goes stale silently when that file renumbers, and nothing in the chain compares a count or an ordinal in one file against another; the measurement behind that behavior is [`github-api-budget`](../rules/github-api-budget.md)'s.
