# Rule — re-verify at the merge, not only at the build — history

The incident history behind [`pre-merge-check`](../rules/pre-merge-check.md): the evidence each instruction rests on.
Not auto-loaded. Read it when a rule is questioned, before re-arguing it.

## Why this is a standing order

It earns its cost. Applied before four consecutive merges it found something every time, and none of it was visible from a green build:

- **#32** — the acceptance criterion named the importer's parser, and checking it properly showed `title` and `description` ship entity-encoded where the WXR path yields decoded text. A review finding (K4) had raised exactly this and been refuted by both skeptics; both were wrong, and `default-filters.php:268` settles it. → #37.
- **#35** — one commit behind `main`, sharing precisely the two files `main` had just gained. Its green run was against a base that no longer existed.
- **#39, first pass** — the audit script's own guarantee ("prints no option values") could be met to the letter while printing a credential, because printed key paths are built from array keys. Also: the tuned heuristics at the centre of the PR had **no tests at all**.
- **#39, second pass** — the fix from the first pass made a detector under-report: two sibling keys redacting to the same placeholder collapsed two findings into one.
- **#39, third pass** — a full adversarial review of the head commit: 15 findings, 13 surviving, and **four independent lenses agreeing the rewrite had silenced a whole detection class** — the audit had stopped checking the option name at all. Two of its own guards could not see what they guarded.

Note the last one. **The fixes a pre-merge check produces have themselves had no review**, so a PR that changed after its check gets another one. Re-asking is not pedantry; it caught a real defect that the first pass introduced.

## The check

### 1. State — read the head SHA; never derive it

**A `409` is not the guard that catches this.** It fired here on 2026-09-05 merging #101, against a SHA padded from `5ae8e1c05` to a full-length string that named no commit:

```
sent        5ae8e1c05a1e0b4ab8d2fd07ed4d3ac81b4e1e01
real head   5ae8e1c0520473f574f380350ad423736b93c771
response    409  Head branch was modified. Review and try the merge again.
```

**That is the same refusal a genuinely moved branch produces, and its text names that other cause** — so the natural response is to re-fetch and retry, possibly with the same invented SHA. What actually prevented a wrong merge was that a fabricated hash is overwhelmingly unlikely to name a real commit. That is luck with a good success rate, not a safeguard.

### 6. What the merge will close

**What the message is composed FROM is a repository setting, and on this one it is the pull-request title and body — not the branch commits.** This step used to say the opposite, which made it contradict [`github-api-budget`](../rules/github-api-budget.md)'s measurement of the same behavior in the same corpus. Both surfaces are still enumerated, because which route actually fires is open there and not settled by knowing the source.

## Adversarial review at the merge

- **Review what is actually merging.** The diff at the head commit, including everything added *after* the build's own review. That is the part nobody has looked at, and on #39 it is where the defect was.
- **Distrust the tally, and do not stop at fixing the arithmetic.** Two skeptics per finding is not a vote, it is a coin flip with a tie-break: in one review here K1, K4 and K5 were all killed on 1-of-2 votes and all three were real. So use an odd number and keep blockers alive on a tie — but that is not sufficient, and the next review proved it. Run with three skeptics, the `auditDecoded()` finding was refuted **3-of-3, unanimously, and was true when it was run**. Four killed-but-real findings across two reviews, and the last one defeats the quorum fix.

  What actually separates them is not the count. **Every one was settled by executing ten lines of code**, and no skeptic did.

## Original wording of passages condensed in place

These paragraphs were shortened in [`pre-merge-check.md`](../rules/pre-merge-check.md) rather than moved. Their full wording as it stood before the move is kept here, in file order.

**Read the head SHA; never derive it.** The merge call needs the full forty characters, and every surface you see before merging — the CI summary, `git log --oneline`, the runner's own `Commit:` line — prints the abbreviated form. Padding the short one out reads as formatting rather than invention, which is exactly why it does not feel like fabricating a value at the moment it happens:

**Those two agreeing is what discharges this step**, rather than being an extra chore on top of it — "the head is the commit you think it is" is precisely the claim that comparison settles. When they disagree, the branch moved or the local ref is stale; either way, stop.

6. **What the merge will close.** A squash merge closes issues from **two** surfaces — the pull-request body, and the commit message GitHub composes when `commit_message` is omitted. A `Fixes #N` written into a branch commit weeks ago is invisible in the body a reviewer reads, and the close is attributed to the merge rather than to the commit that caused it. Enumerate both before merging; [`github-api-budget`](../rules/github-api-budget.md) carries the command, the pattern, the control that proves the pattern can match, and the parsing details that defeat checking by eye.

7. **Plumbing in the body, because the merge makes it permanent.** The composition in step 6 has a second consequence that has nothing to do with closing anything: the body's wording becomes a commit message, and this repository does not rewrite `main`. So a body corrected after the merge fixes the page a reader visits and leaves the original text in history forever. Sweep it per [`coordination-plumbing-stays-out-of-artifacts`](../rules/coordination-plumbing-stays-out-of-artifacts.md), which owns the sweep, its two-sided control, and the worked example of a correction that did not reach the message. **Publishing is the first chance to catch this, not the last one.**

What actually separates them is not the count. **Every one was settled by executing ten lines of code**, and no skeptic did. So: when a refuted claim is mechanically checkable — a return value, a regex, a branch — check it yourself rather than accept the verdict, whatever the margin. Reserve the count-based rules for claims that are genuinely a matter of judgment.

This file is the standing statement of the **merge** gate. The *build* gate — TDD, branch, PR, the chain — lives in [`AGENTS.md`](../../AGENTS.md); bringing a branch current lives in [`sync-pr-branch`](../rules/sync-pr-branch.md); the adversarial frame and its mechanics live in [`adversarial-review`](../rules/adversarial-review.md); recording the verdict on the pull request, green or red, lives in [`local-ci`](../skills/local-ci/SKILL.md); what has to be true either side of the merge — never pushing to `main`, the board card, and residue — lives in [`closing-a-ticket`](../rules/closing-a-ticket.md); and the plumbing sweep step 7 requires belongs to [`coordination-plumbing-stays-out-of-artifacts`](../rules/coordination-plumbing-stays-out-of-artifacts.md), which owns what counts as plumbing and how to sweep for it. The composing behavior both step 6 and step 7 rest on is measured in [`github-api-budget`](../rules/github-api-budget.md) and is not re-measured here. Don't restate any of them.
