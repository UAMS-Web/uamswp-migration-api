# Rule — presenting design-decision forks — history

The incident history behind [`design-decision-forks`](../rules/design-decision-forks.md): the evidence each instruction rests on.
Not auto-loaded. Read it when a rule is questioned, before re-arguing it.

## The `decision-fork` label

`decision-fork` is never removed once resolved — it is the durable record that this ticket once carried a fork, which is why a resolved example (`#45`, `#37`, `#11`, among others) still shows it beside `afk`.

## What a `decision-fork` ticket delivers, and what it does not

**Why this is the shape rather than a refinement of "flip the label and build it."** A ticket carrying both a fork and its build has two deliverables in one artifact: one that waits on a human and one that waits on nothing. Fused, the second inherits the first's latency with no signal that it has — the build sits blocked behind a decision it does not actually depend on. Splitting them means the decision resolves in one conversation and the build ships the same afternoon, instead of both moving at the slower one's pace. `UAMS-Web/uams-statamic#2159` is the case that cost real time this way: build work fused to a fork sat blocked for days before an operator instruction split it out as `UAMS-Web/uams-statamic#2171`, which shipped immediately once separated.

## Original wording of passages condensed in place

These paragraphs were shortened in [`design-decision-forks.md`](../rules/design-decision-forks.md) rather than moved. Their full wording as it stood before the move is kept here, in file order.

**A ticket in this state carries the `decision-fork` label**, alongside `hitl`. It marks a ticket for what it is rather than for how long it stays that way: `decision-fork` is never removed once resolved — it is the durable record that this ticket once carried a fork, which is why a resolved example (`#45`, `#37`, `#11`, among others) still shows it beside `afk`.

A human decides a fork by **weighing trade-offs across dimensions**, not from a list of one-line option labels. So your job is NOT to ask "which do you want, A or B?" — it's to do the analysis first and hand over a complete, comparable packet, *then* ask. Never let an interactive prompt be the first substantial thing the human sees, and never compress the analysis to make room for the prompt. The prompt is the last step, not the deliverable.

1. **What the issue is, and what's already pinned/settled.** State the decision at stake and explicitly name what is NOT up for grabs — the already-decided constraints, the chosen format, the settled neighbors — so nothing settled gets relitigated. Re-opening a closed decision wastes the human's time.

3. **Trap → reframe.** Name the naive trap (the framing that makes this look harder, binary, or unsolvable than it is), then the reframe that dissolves it. This is the move that makes the options legible.

- The human can always choose "Other" and write their own answer — your options are a starting point, not a cage. Don't force a false binary; if there's a real third path, offer it.

2. **File the follow-up ticket(s) the decision produces**, one per independently shippable build slice, each carrying its own `afk`/`hitl` label — most will be `afk`, since the fork that would have made them `hitl` is now decided. Set each as `blocked_by` this ticket through the native dependency edge, never a relationship line in either body.

- Not the *action* flavor of `hitl`. An issue that is `hitl` only because it needs a real-world step (a deploy, provisioning, recruiting, installing — work outside the repo) has **no fork to surface**; manufacturing "decisions" on it misleads a future agent. This rule is for the decision flavor.

- Not a reopening of how dependencies are expressed. `UAMS-Web/uams-statamic#2097` settled that as native edges only, never a textual relationship line in a body — *what a `decision-fork` ticket may contain* is a different question from *how a dependency is recorded*, and this file only answers the first.
