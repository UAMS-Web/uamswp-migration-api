# An empty result is not evidence — history

The incident history behind [`an-empty-result-is-not-evidence`](../rules/an-empty-result-is-not-evidence.md): the evidence each instruction rests on.
Not auto-loaded. Read it when a rule is questioned, before re-arguing it.

## Why this is a standing order

Ported from [`uams-statamic`'s `an-empty-result-is-not-evidence.md`](https://github.com/UAMS-Web/uams-statamic/blob/main/.claude/rules/an-empty-result-is-not-evidence.md), because this repository stated the same discipline three times — in [`reading-exit-status`](../rules/reading-exit-status.md), in [`no-emoji-in-durable-records`](../rules/no-emoji-in-durable-records.md), and in [`adversarial-review`](../rules/adversarial-review.md) — with each statement bound to one instrument and none reaching general. `#139` records this repository's own cost: six failures in one session, every one a probe that returned a well-formed, wrong answer.

## Where this has bitten, so the next reader recognizes their case

All three, executed in a clean directory this turn, not read from memory.

**A pattern that cannot see its own target.** `grep -c "unhandledRejection"` was run against a build believed to carry a fix, and against one believed not to — both read `0`, which was reported as "the fix is not present." The fix is a `try`/`catch`, and the pattern was never capable of matching one, on either file:

```
$ grep -c "unhandledRejection" plugin-unfixed.js plugin-fixed.js
plugin-unfixed.js:0
plugin-fixed.js:0
$ grep -c "unhandledRejection" plugin-fixed.js    # the file KNOWN to carry the fix
0
$ grep -c "try {" plugin-fixed.js plugin-unfixed.js   # the correct probe, same test
plugin-fixed.js:1
plugin-unfixed.js:0
```

The first three lines are indistinguishable from a real absence. The last line is the control that would have caught it — run against the file the claim is actually about, not a different one.

**A flag that was never real.** `--until` was passed to a tool with no such flag, and the resulting silence was read as "no messages in that window," when four messages existed. The tool's own supported-flag list settles it without needing to interpret any output:

```
$ node toy-watch.mjs      # prints its own usage
usage flags: --channel --since --interval
$ node toy-watch.mjs | grep -c -- "--until"
0
$ node toy-watch.mjs | grep -c -- "--since"   # a flag that DOES exist, same check
1
```

The `0` for `--until` is not a report about the messages; it is a report about the tool's argument parser, checked before any filtered output is trusted.

**A pipeline that reports its last stage, not its first.** `php -v 2>&1 | head -3; echo $?` returned `0` for an interpreter that had in fact been killed. `head` succeeded; its success is what `$?` held by the time the sentence ran:

```
$ false | head -3; echo "reported -> $?"
reported -> 0
$ false; echo "the real status -> $?"
the real status -> 1
```

[`reading-exit-status`](../rules/reading-exit-status.md) already covers the mechanics of *why* `$?` reflects the pipeline's last element. What is new here is the general form: **a piped instrument reporting success is a claim about the pipe, not about the thing you piped.**

## A number asserted without ever being measured is not a weak version of this

A control cannot rescue a figure that was never read from anything — it is a different failure, not a smaller one. If a timestamp, a count, or a version string appears in a finding with no command that produced it, that is not an instrument that returned an empty or misleading result; it is no instrument at all. Say so plainly rather than filing it beside a probe that at least ran.

## A true measurement attached to the wrong subject is not this rule's either

[`adversarial-review`](../rules/adversarial-review.md#how-to-apply) already states it: *"A reading taken from one and reported as the other is a true measurement attached to the wrong subject."* A correct, two-sided-controlled reading of the wrong file is non-compliance with that rule, not a gap this one needs to fill a second time.

## The DRY line

This file states the **general form and its remedy** — prove the probe could have returned something else, before trusting that it did not. The concrete detail stays where it was already discovered: pipeline and command-substitution mechanics belong to [`reading-exit-status`](../rules/reading-exit-status.md), the two-sided sweep control for durable-record audits belongs to [`no-emoji-in-durable-records`](../rules/no-emoji-in-durable-records.md), and reading the correct subject at all belongs to [`adversarial-review`](../rules/adversarial-review.md). Each of those is more useful than this file for the reader already in that situation; this one exists for the reader in a fourth situation who would otherwise re-derive it.
