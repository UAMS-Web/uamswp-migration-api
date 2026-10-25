# Finish a ticket where it is visible — history

The incident history behind [`closing-a-ticket`](../rules/closing-a-ticket.md): the evidence each instruction rests on.
Not auto-loaded. Read it when a rule is questioned, before re-arguing it.

## Why this is a standing order

**Why this is a standing order.** `main` is deliberately unprotected here — branch protection and the org-level `RUN_REMOTE_CI` kill-switch cannot both be on (D-029) — so nothing mechanical stops a direct push. The board is what iteration planning reads, and a card left in `Todo` while its branch merges makes it a lie. And residue that never gets a ticket is indistinguishable from residue nobody noticed. Each failure is silent by construction.

## How to apply

2. **Move the board card in step with the branch — and where there is no card, create it before moving it.** This step used to presuppose one existed. That left the requirement to create it falling between two documents: this rule insisted the card move, while the skill it defers to supplied the mechanics without ever saying a ticket must be boarded at all, so each read as though the other carried it. The obligation, the `Status` vocabulary and the field ids are all in [`writing-issues`](../skills/writing-issues/SKILL.md); this rule only insists the move happens while the work is happening. A card updated a week later is a card nobody trusted in between.

## What this is not

Not a duplicate of [`pre-merge-check`](../rules/pre-merge-check.md). **Acceptance criteria belong to that rule** — its step 5 already requires them item by item, with anything unverifiable reported as deferred rather than ticked. This rule does not restate that and must not grow a copy of it.
