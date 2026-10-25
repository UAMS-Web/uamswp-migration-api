# Rule — no emoji in durable records — history

The incident history behind [`no-emoji-in-durable-records`](../rules/no-emoji-in-durable-records.md): the evidence each instruction rests on.
Not auto-loaded. Read it when a rule is questioned, before re-arguing it.

## The one known conflict, now decided: the attribution footer is retired

**The attribution footer that closed a pull-request body carried `U+1F916`.** It was written by standing instruction on every pull request this repository opened, so the ban above and current practice contradicted each other — in the one place the rule most obviously applies.

**That is settled: the footer is retired.** It does not go on a pull-request body or an issue body, and this overrides any attribution convention supplied from outside the repository, including one a session's own environment provides. Decided by the operator on 2026-09-09, in response to this section's own text being put to the operator. Recorded on #173.

- **An audit expects ZERO, and this bullet is why the section could not simply be left to age.** Its superseded form told a sweep of pull-request bodies to expect exactly one hit each and to investigate only a *second* one. Once the footer was retired that guidance inverted: it made a body still carrying `U+1F916` the expected case, so a sweep run against it would report clean over the very thing the rule bans. Any occurrence is a violation.

The rule was written with this conflict already visible, and the ticket that produced it scoped the footer question out deliberately rather than guessing at it. Naming it here was the alternative to a rule whose first pull request violated it silently; recording the answer here is that same discipline one step on.

## Why this is a standing order

Four reasons. The first is the one that costs a debugging session; the third is the one that survives every attempt to clean up after the others.

**They break, and the failure lands somewhere else.** A glyph in a file is bytes, and something downstream eventually decodes them. A process running under a legacy code page raises a `UnicodeEncodeError` on a warning sign rather than rendering it — so the file is fine, the author's terminal was fine, and the failure surfaces in whatever reads the text next, attributed to the reader. Worse, a tool that suppresses its own stderr reports the crash as an empty result, which is indistinguishable from a clean one.

**They encode urgency the reader cannot calibrate.** A warning glyph says the author felt strongly. It does not say what is at risk, what triggers it, or what to do — and it survives copy-paste into a context where the author's emphasis means nothing. A sentence naming the risk does the job the glyph was standing in for, and keeps doing it after the glyph is stripped by a font, a terminal, or a code page.

**One of them is INVISIBLE, and it is why the sweep above carries a code point that matches nothing on its own.** `U+FE0F`, the variation selector, renders as no width at all. It modifies the character before it, so deleting the glyph a reader can see leaves the selector behind — a body that looks clean, reads clean, and still carries it. Two consequences follow and neither announces itself: a byte comparison against that body disagrees for a reason nothing on screen explains, and a hand-cleanup that strips the visible character reports success while leaving the file non-identical to the one it was supposed to match. That is the argument for keeping `\x{FE0F}` in the expression rather than trimming it as noise, and for printing code points rather than characters when reporting what a sweep found.

**And a glyph is not searchable by a reader who cannot type it.** Prose can be searched for; a pictograph can be searched for only by someone who can already produce it, which on most keyboards means copying it out of the very document they are trying to search. A reader looking for every warning in a corpus finds the ones written as words and misses the ones written as pictures. That cost lands on the person auditing later rather than the author writing now, which is why it is easy to discount and worth stating.

## How to apply

### The sweep of bodies and messages

A body is multi-line; reading a list of them with `read -r` takes the first line of each and silently discards the rest. Observed here: a pull-request body sweep reported zero while the true figure was ninety-one glyphs across twenty-one bodies. Both controls passed and the denominator matched the count of bodies, because neither control tests the **record boundary** — only the expression.

### Why `perl` rather than `grep -P`

**It is `perl` rather than `grep -P` for the reason [`long-running-commands`](../rules/long-running-commands.md) gives about `timeout`: the macOS default is not the GNU one.** `/usr/bin/grep` here is BSD grep, which has no `-P` — it exits 2 and writes nothing to stdout, so a sweep whose stderr is redirected reports exactly the clean result this rule exists to distrust. A shell that resolves `grep` to something else, as some do, hides that from whoever writes the command. `perl` is present on macOS by default and its `\x{...}` escapes need no external engine. The pattern is defined once and reaches both the controls and the sweep, so the arms cannot pass against an expression the sweep does not use.

## The check-mark question, decided

The argument for an exception was that a check mark in a test plan is a data marker rather than decoration. It does not survive contact with what a test plan is for: every line in one is already a claim of verification, so the mark adds emphasis rather than information, and the line reads better carrying its result instead. And a pull-request body is precisely the durable record this rule exists to protect — an exception there would swallow the main case.

Rejected alternatives, recorded so they are not reopened: *scope the exception to test plans only* — the most-copied convention in the corpus is the worst place for a carve-out; *record the conflict as undecided* — that leaves a corpus contradicting itself, and the question is small enough to answer.

## Original wording of passages condensed in place

These paragraphs were shortened in [`no-emoji-in-durable-records.md`](../rules/no-emoji-in-durable-records.md) rather than moved. Their full wording as it stood before the move is kept here, in file order.

- **No occurrence is permitted.** There is no single-exception carve-out any more, by name or by code point, in a pull-request body or anywhere else. The ban above now has no attribution exception at all.

Note what this rule is *not* arguing. Nothing here is about tone. Emphasis is welcome; **bold** carries it without a code point that some consumer cannot decode.

**This sweep reads files only.** Commit messages, and issue and pull-request bodies and comments, are in scope and are not on disk, so they need a separate pass — and one written carelessly fails in a way both arms above will miss. A body is multi-line; reading a list of them with `read -r` takes the first line of each and silently discards the rest. Observed here: a pull-request body sweep reported zero while the true figure was ninety-one glyphs across twenty-one bodies. Both controls passed and the denominator matched the count of bodies, because neither control tests the **record boundary** — only the expression.

This file owns **glyphs in durable prose**. [`writing-comments`](../skills/writing-comments/SKILL.md) points here for comments and carries the draft check, since this file's own sweep reads files in the tree rather than a body that is not in it. [`writing-issues`](../skills/writing-issues/SKILL.md) points here rather than restating the ban; what belongs *in* an issue or pull-request body is that skill's and [`writing-pull-requests`](../skills/writing-pull-requests/SKILL.md)'s, and the impersonal voice of a body is [`impersonal-voice-in-github-artifacts`](../rules/impersonal-voice-in-github-artifacts.md)'s. Bounding a long-running audit belongs to [`long-running-commands`](../rules/long-running-commands.md). The general form its own two-sided-control requirement is one instance of — prove a sweep could have found something, before trusting that it found nothing — is [`an-empty-result-is-not-evidence`](../rules/an-empty-result-is-not-evidence.md).
