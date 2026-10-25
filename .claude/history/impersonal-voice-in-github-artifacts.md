# Write GitHub artifacts in an impersonal voice — history

The incident history behind [`impersonal-voice-in-github-artifacts`](../rules/impersonal-voice-in-github-artifacts.md): the evidence each instruction rests on.
Not auto-loaded. Read it when a rule is questioned, before re-arguing it.

## Why this is a standing order

The mechanism is structural rather than stylistic. Check the authorship on any issue, pull request or review comment in this repo: it is the same account every time. So "I verified this" is that account telling itself what it just did, and "you found the reason I did not" addresses nobody. The sentence does not mean what its grammar says.

Then it sharpens, and the sharpening is the half worth keeping. **Deleting the pronoun is not enough — the vouching stance has to go with it.** "All three defects are confirmed from use" contains no pronoun and still reads as the author confirming their own pull request. A rule that stops at `I` and `you` catches the easy half and leaves the sentence that actually misleads.

This matters more here than the artifact count suggests, because [`pre-merge-check`](../rules/pre-merge-check.md) requires a written finding before every merge, and a finding is exactly the sentence most tempted into vouching.

## How to apply

### 1. Scope: PR bodies, issue bodies, and PR/issue comments

Bodies already have skills — [`writing-pull-requests`](../skills/writing-pull-requests/SKILL.md) and [`writing-issues`](../skills/writing-issues/SKILL.md). **Comments are the largest class by volume**, and for a long time no skill covered them at all -- which is why this is a rule rather than an edit to those skills. [`writing-comments`](../skills/writing-comments/SKILL.md) now covers them, added by D-072 once the glyph ban became the second rule to reach the same uncovered class. It carries a pointer here and owns no rule of its own, exactly as the body skills do.

### 2. No `I`, `my`, `you`, or `your` — state the finding as a fact about a run or an artifact

The replacement is almost always shorter, because the pronoun was carrying no information the sentence needed.

### 3. The harder half — remove the confirming narrator, not just the pronoun

**Praise is the same defect wearing different clothes.** "The sharpest sentence in the PR" reads, under one account, as self-congratulation; and it tells a reader nothing. State what the sentence *establishes* instead. The same goes for "good catch", "excellent point", and any assessment of a contribution's quality — none of them survive the single-account reading, and none of them is load-bearing.

### 5. Two carve-outs, and they are real

- **Repo prose deliberately addresses a reader as "you".** `.claude/**`, `docs/**` and [`README.md`](../../README.md) are instructions to whoever reads them next, and second person is correct there. **A sweep must not strip it.** This file does it in the sentence you are reading.
- **Verbatim quotations keep their original wording, including "you".** A quote is evidence; altering it to satisfy the rule is a worse defect than the quote. Quote it as written and let the surrounding prose carry the impersonal voice.

## Commit messages are already impersonal

The [`writing-commits`](../skills/writing-commits/SKILL.md) convention — a Conventional-Commits subject in the imperative, a body of `-` bullets describing the change — produces impersonal prose by construction. Nothing needs to change there. **Keep it that way**: a body bullet that starts "I moved…" or "as you can see…" is the same defect arriving through a door nobody was watching.

## The DRY line

This file is the standing statement on **voice in GitHub artifacts**. What a COMMENT is held to, and the check to run on one before posting it, is [`writing-comments`](../skills/writing-comments/SKILL.md). What goes *in* a body — the section inventory, `Closes #N.`, the `## Test plan`, the archetype templates, the label taxonomy, the board fields — belongs to [`writing-pull-requests`](../skills/writing-pull-requests/SKILL.md) and [`writing-issues`](../skills/writing-issues/SKILL.md), and subject/body/staging mechanics to [`writing-commits`](../skills/writing-commits/SKILL.md); each points here rather than restating this. It composes with [`adversarial-review`](../rules/adversarial-review.md), whose findings are exactly the sentences most tempted into a vouching stance, and with [`pre-merge-check`](../rules/pre-merge-check.md), whose "say what you found" step is where the vouching stance most often creeps in.

## Original wording of passages condensed in place

These paragraphs were shortened in [`impersonal-voice-in-github-artifacts.md`](../rules/impersonal-voice-in-github-artifacts.md) rather than moved. Their full wording as it stood before the move is kept here, in file order.

This rule removes a narrator, never a fact. **Keep every number, path, ticket reference, command, and error string.** "Verified: X" is impersonal; "this was checked" is the same claim with its source removed as well as its pronoun, and it is worse than what it replaced. If deleting the narrator leaves nothing behind, the sentence had no content — cut it or go find the evidence.
