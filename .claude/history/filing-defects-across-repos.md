# File a defect in the repository that owns it — history

The incident history behind [`filing-defects-across-repos`](../rules/filing-defects-across-repos.md): the evidence each instruction rests on.
Not auto-loaded. Read it when a rule is questioned, before re-arguing it.

## Why this is a standing order

**The routing half, because the alternatives are not tracking artifacts.** A coordination message is read once by whoever is watching and is gone. A pull-request aside is read during review and never again. A comment on a *closed* issue is the worst of the three: nothing surfaces that thread a second time, so the finding is filed in the one place guaranteed not to be revisited. Each of these feels like recording the problem, and none of them is.

**The claiming half, because the race is real rather than theoretical.** Concurrent sessions adopt the same release within minutes of each other and run the same diagnostics against it. Without a claim they each find the same defect, each reasonably conclude nobody has filed it, and each file it. The duplicates are not the whole cost — the reviewer then has to work out whether two reports describe one problem or two.

## How to apply

2. **Check before filing, and check the shipped artifact.** Search the target tracker with several phrasings and **include closed issues** — a closed one still means do not refile. Then read the *released* copy rather than a checkout: a fix may already have shipped in a version nobody has adopted yet, and a defect reproduced against an authoring tree may not exist in what people are running.

3. **Decide whether a race is plausible.** It is, whenever the finding came from something other sessions also just did — adopting a release, running a diagnostic, reading a shared announcement. It is not, for something only this session could have seen: a defect in work in progress here, or one surfaced by a fixture only this branch has.

4. **When it is plausible, announce and claim before doing the work.** Read what the other sessions have already said about it first; a filing already reported means stop. Then say you are filing, and take the claim the shared tooling offers for it. The mechanics belong to that tooling and are not restated here.

5. **Treat a claim as an ordering convention, not a lock.** It does not exclude a concurrent claimant; it makes the winner deterministic and visible. Do not take work from a claimant judged unresponsive without asking it first — silence is inferred, and a quiet session is not a dead one.

6. **Cross-link adjacent issues in the issue bodies, not only in coordination messages.** A claim prevents duplicate *filings*; it does nothing about two legitimately distinct issues that edit the same lines. When you notice that overlap, say so in the issue itself — a coordination message reaches whoever is listening now, and the body reaches whoever picks the work up next month.

7. **Record what was measured and under what conditions.** Name the version, the platform, and the surface the observation came from, and state the bounds you did not test. For a claim about a dependency's code that means the **revision**, not only the version — the two are not interchangeable, and [`adversarial-review`](../rules/adversarial-review.md) owns why. A finding whose conditions are not written down is generalized past its evidence by the next person to act on it — including by whoever writes the fix.

## What this is not

Not a reason to file upstream instead of fixing something here. If the defect is ours, fix it. This rule governs where a report lands when the fix is not ours to make, and how concurrent sessions avoid filing over each other.

Nor does it apply to residue from a ticket in this repository — that is covered by the ticket-closing rule, which already requires residue to become an issue rather than a mention.
