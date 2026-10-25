# Bound every long-running command — history

The incident history behind [`long-running-commands`](../rules/long-running-commands.md): the evidence each instruction rests on.
Not auto-loaded. Read it when a rule is questioned, before re-arguing it.

## Why this is a standing order

**Why this is a standing order.** A detached multi-minute run does not sit still. It contends with every run you start next, so the next thing you time comes back slower and you draw the wrong conclusion from it; and it holds the files, caches and database the next run wants. Nothing warns you: the tool result says the command ended, and it is telling the truth about the *wrapper*, not about the work.

## Bounding on macOS — `perl`, because there is no `timeout(1)`

Development here is macOS under Herd, and **macOS ships no `timeout`**. It is `gtimeout`, from coreutils, if installed at all — and neither is present on this machine (verified). A bare `timeout` fails with `command not found`, which inside a pipeline can read as a `0` exit.

### The absolute `pest` path

**On Windows, `#179` reported a bare relative `vendor/bin/pest` failing loudly (`command not found`) specifically on this function's `perl` fallback branch, bypassing `timeout`/`gtimeout` to reach it.** That is a distinct claim from the `perl`-fallback no-op below, which is about the *older* alarm-across-exec one-liner rather than this forking form. A re-check on this machine did not reproduce it — `perl -e '... exec @ARGV ...'` against the bare relative path ran the script correctly, no error — so the exact trigger is unconfirmed rather than a settled mechanism; treat that account as reported, not verified here. The absolute path above is the conservative fix either way, since it costs nothing and is what the two related Windows sites already need — see [`pest-testing`](../skills/pest-testing/SKILL.md#mutation-testing) for the confirmed, more serious Windows failure this same binary has under `--mutate` specifically.

### Why the fallback forks

**Expiry is `124`, whichever branch runs**, which is what makes a fired bound readable. Measured in this checkout, exit status read with nothing between the command and `$?`:

```
bounded sleep 10          124      expiry
bounded php -r sleep(10)  124      expiry
completes inside the bound  0      the command's own status
bounded sh -c 'exit 7'      7      propagated, not swallowed
```

**A single `alarm` across `exec` cannot do this, and the difference is the reason for the fork.** The older one-line form ended the process it replaced and nothing else:

```
                    exit   'sleep 30' children still alive after
forking form         124                                       0
alarm-across-exec    142                                       2
```

Both arms run here on `sh -c 'sleep 30 & sleep 30'`. That is the whole parallel-runner case in miniature: a bounded `pest --parallel` under the old form would end the parent and leave its workers running, and this repository's own guidance on stray processes then applies to wreckage the bound created.

**The `142` is not a wrong number — it is the right number for the wrong command.** `128 + SIGALRM` is what a signal death looks like, and it is unmistakable. **A bounded PHP process never produces it**, because PHP installs its own `SIGALRM` handler for `max_execution_time`, absorbs the external alarm and re-raises it as a fatal error:

```
$ perl -e 'alarm shift; exec @ARGV' 3 php -r 'sleep(10);'
Fatal error: Maximum execution time of 0 seconds exceeded in Command line code on line 1
exit 255
```

**`255` with those words is indistinguishable from the suite failing on its own**, and it reads as a slow test rather than as the bound the operator set — which is exactly the confusion the *size the bound* paragraph below warns about, arriving through the door the old form opened. The worked example above is a Pest run, so the PHP branch is the one that actually fires.

Controls for all of the above: a bare `false` immediately beforehand returned `1`, so `$?` was live; an unbounded `php -r 'sleep(10);'` exits `0`, so the bound is what changed it; and neither `timeout` nor `gtimeout` is present here, so the cascade fell through to the `perl` branch in every reading.

> **The `perl` branch is a silent no-op under Git Bash on Windows for the older alarm-across-exec one-liner.** Its Perl has no real `exec`, so a pending alarm dies with the emulated process and the command runs unbounded — no error, just an unbounded run. Irrelevant on this repo's macOS toolchain, but worth knowing before copying that specific idiom into a Windows checkout.

**The forking form is different, and is now verified on Windows rather than merely flagged: `#215` measured it, and it works.** `#213` shipped it with an honest caveat — `fork`, `setpgrp` and process-group signals were unverified there, and nobody validating that PR had a Windows checkout. This repository's own CI gate does, and its own pre-merge review on that machine exercised the fallback directly (bypassing the `timeout`/`gtimeout` preference so the third branch runs) against `sh -c 'sleep 30 & sleep 30'`, then read `tasklist` immediately afterward and saw two `sleep.exe` rows — filed as `#215`.

**That reading does not reproduce under a controlled measurement, and the reason is the measurement, not the fallback.** This machine (Git for Windows / MSYS2, `perl 5.32.1` built for `x86_64-msys-thread-multi`) runs an ambient, periodically-recurring `sleep.exe` unrelated to anything this file bounds — its parent is already gone by the time it is observed, and a fresh one appears roughly every two minutes under a duration (`120`, `900`) nobody here ever passes to `bound()`. A bare `tasklist //FI "IMAGENAME eq sleep.exe"` count cannot tell that process apart from a genuine survivor, so the check the original finding used cannot distinguish "the fallback left a child running" from "an unrelated process happened to be on the machine at that moment" — the same shape [`an-empty-result-is-not-evidence`](../rules/an-empty-result-is-not-evidence.md) already names for a *missing* result, here on a *present* one instead: a hit that is not evidence, because the instrument cannot tell what it hit.

Filtering on the exact command line (the specific duration passed to this invocation, not the bare image name) removes that confound. Thirty trials, each a fresh unique duration so no run can be confused with another or with the ambient process, each checked by exact `CommandLine` match rather than a process count:

```
direct exec'd child, no shell        bound 3 sleep <N>                    15/15 clean
two children via shell job control   bound 3 sh -c "sleep <N1> & sleep <N2>"  15/15 clean
```

Zero survivors across both shapes. A negative control confirms the method can see a real failure when one exists: the retired alarm-across-exec one-liner, run against the same `sh -c 'sleep <N1> & sleep <N2>'` shape, left the entire tree running — worse than the two-survivor reading above, because `sh` defers a pending `SIGALRM` until its current foreground job returns, so the one-liner does not even bound the call in reasonable time once a shell is holding a foreground child. That form is superseded and nothing here recommends it; it is cited only as the control that proves the measurement methodology detects a genuine failure rather than always reporting clean.

**So: no Windows-compatible bound needs to be added — the forking form already is one — and the fix here is the claim, not the code.** The mechanism behind the original two-survivor reading is, as best this measurement can tell, an unfiltered process count on a machine that already had unrelated `sleep.exe` activity, not a defect in `kill("-TERM"/"-KILL", $pid)` against the process group `setpgrp` created. Where a `bound()` invocation genuinely times out, `command -v timeout` on this repository's Windows checkout resolves to GNU coreutils `timeout` (`8.32`, via Git for Windows) before the fallback is ever reached, so the measurement above concerns the fallback path specifically, exercised the way `#215`'s own reproduction exercised it — deliberately bypassing the cascade's first two branches, not the ordinary case.


### Sizing the bound

**Size the bound to the measured runtime, not to your patience.** A bound that fires reads exactly like a failure and invites you to go debug a passing command. Measure once, then bound generously above it. Today almost nothing here is slow — `composer ci` finishes in seconds against eight files. What earns a bound is the work that touches the live install or the network: a scratch script querying `uamshealth.com`-scale data, a `composer install` on a cold cache, and the `wp-phpunit` suite once #15 lands.

## Resolve a process before you kill it

`pgrep -f` / `pkill -f` match on the full command line, which is wider than you think — including **the command doing the searching**. A `pkill -9 -f "vendor/bin/pest"` can kill your own monitor, because the monitor's command line contains the pattern it is searching for.

### Why cwd alone cannot attribute a process

**Never kill a process whose cwd is not yours — and cwd alone does not tell you that.** It separates runs **across** checkouts: a second clone, a linked worktree, another agent in a different WordPress install. **It cannot separate two sessions inside ONE checkout**, and that is the normal configuration here rather than an exotic one — [`worktrees`](../rules/worktrees.md) keeps the primary shared by construction, and one machine routinely runs several concurrent sessions. Two of them in one tree have byte-identical working directories.

**The failure direction is what makes this urgent.** For the case cwd does handle, the test fails closed and leaves a stranger's process alone. For a same-checkout peer it fails **open**: the peer's cwd *is* prefixed by yours, so the test returns true and authorizes the kill. A false negative leaves litter you can see. This false positive reds another session's work, and the killing session gets no signal, because every check it was told to run passed.

### So attribution needs a marker, and cwd is only the coarse filter

**Put it in `argv`, not the environment.** Measured on this machine: a marker set with `exec -a` is readable straight out of `ps -o command=`, while a variable exported into a child's environment is invisible — it is absent from `argv`, and `ps eww -p <pid>` prints no environment on macOS even for your own process. A convention that hides the marker from the reader who needs it is no convention at all.

```
marker in argv          ps -o pid=,command= -p <pid>   ->  readable
marker in environment   ps eww -p <pid>                ->  nothing on macOS
```

**An unmarked process is not "probably mine".** Treating it as yours is the same defaulting mistake as a denylist, one level in — it restores fail-open behavior for exactly the case the marker was added to close. Fail closed: anything you cannot positively attribute to yourself belongs to somebody else.

Every branch after the cwd filter would be identical without the marker, which is the point: `cwd` has already returned true for all three.

**And the `-P` is not a detail.** `lsof` reports a resolved physical path; `pwd` and `git rev-parse --show-toplevel` report the logical one, which differs wherever any component is a symlink — `/tmp` against `/private/tmp` on macOS is the case you will meet first. Compare the two forms and the prefix test is false for *every* candidate, so the loop `continue`s past all of them and reports no strays at all. That is this file's own subject arriving in its own worked example: a filter that rejects everything looks exactly like a tree with nothing to find.

### Repeated sweeps and vanished process ids

**And a match is not always the process you think it matched.** `pgrep -f` matches the whole command line, so a wrapper shell that carries the entire command as one argument matches every pattern its child would — counting one run as two. Read `argv0` and the parent-process link (`ps -o ppid=`) rather than the match alone: a wrapper and the process it wraps are a parent and a child, not two runs.

**Kill the parent loop first, or the run restarts itself.** A harness that runs bands in a loop (`for i in $(seq 1 10)`) launches one child per iteration. Killing the child — or the bounding wrapper around it — only ends the current iteration; the parent sees it exit and immediately starts the next. Kill the parent, then any surviving children, then **re-list and confirm zero**.

**One sweep is not proof, and neither is any number of sweeps when you are not the only actor.** Confirm-zero converges only against *your own* parent loop, which stops restarting once you kill it. Against a peer that keeps re-arming in the same tree it never converges, and the process table cannot tell you which you are in:

```
killed, restarted by YOUR OWN parent loop      same argv, same cwd, new pid
killed, re-armed BY A PEER in the same tree    same argv, same cwd, new pid
```

Same evidence, opposite meanings, opposite remedies — the first needs the parent stopped first, the second needs a message to a person. **If a second sweep finds it back, stop sweeping and ask the other session.** Sweeping again is correct for the first case and an infinite loop for the second.

**A vanished pid does not establish that the work stopped.** A waiter observed as gone by pid was running again seconds later under a new pid with identical argv and cwd. Re-list by cwd and argv — or by the marker above — never by the pid you were watching.

## Confirm the run actually ended before you measure anything

**Reap a run's waiter when that run ends, not at session end.** A stranded `until … done` waiter polls a log that will never change again. They accumulate silently. `kill` by explicit PID once the run is reported, and re-check that zero remain.

The principle at the top of this file already says so: the tool result is telling the truth about the *wrapper*, not about the work. A checklist naming only the timeout cases is narrower than the principle it serves, and the checklist is the part anyone follows under time pressure.

**A reported FAILURE is the case most often skipped, and it detaches the child exactly as thoroughly as a timeout does.** `143` invites suspicion; `exit code 1` invites a fix and a re-run — so the natural response to a failure is to change something and start again, which is precisely when a surviving child is still holding the database the next run wants. Treat `exit 1`, `exit 2` and a harness's own "command failed" line as saying nothing whatsoever about whether the work stopped.

This matters more once #15 lands the `wp-phpunit` suite. Two concurrent runs share one WordPress test database, so their transactions collide and each one's fixtures vanish under the other. What comes back is a run full of failures indistinguishable from real regressions, and an afternoon spent bisecting an innocent diff.

## A sweep that cannot see reports exactly what a clean one reports

**`| grep -v grep` deletes the target class outright.** A process that waits on a rate limit *must* carry `grep` in its own command line, because that is how it reads the header — so the idiom conventionally added to drop the searching process drops every process being searched for, and returns a confident empty. Use the two properties in the worked example above instead.

**Control the whole pipeline, not its first stage.** A control that proves `ps` enumerates all 1123 processes says nothing about the filter behind it — a sweep whose selector was validated and whose `sed` stage was blind is fully blind and looks verified. Point the control at the same command you are about to trust, end to end.

**`ps` without a selecting flag lists only the current terminal's processes.** Measured on this machine, same instant:

```
ps -A  -o pid= | wc -l    1123    the real table
ps -axo pid= | wc -l      1123    a selecting form
ps -ww -o pid= | wc -l      29    this session's own shell chain only
```

`-ww` sets output **width**, not selection — the natural thing to reach for when a command line is being truncated, and the resulting sweep searches twenty-nine processes out of eleven hundred. It reports the same clean nothing a complete sweep does.

**And a control on the expression is only half of it.** It establishes the pattern can match. It is silent on whether the inputs were the ones you meant:

```
expression broken   the probe cannot match what is there   a control CATCHES this
coverage empty      the probe matched nothing because it
                    was aimed at nothing                   a control DOES NOT
```

So establish both: that the expression finds a string it must find, **and** that the inputs were non-empty and were the intended ones — with a negative control showing what a failed or empty fetch looks like, so a wrong empty and a real one are distinguishable. A run over zero inputs produces the same clean output as a complete run with nothing to find.

**A worked example of getting this wrong, produced while writing this section.** A probe checked whether a marker exported into a child's environment was visible to a sweep, and reported `YES`:

```
ps -Aww -o pid=,command= | grep -qF "UAMSWP_SESSION=$MARKER"   ->  match
```

The match was the probe's own `grep`, whose argv contained the marker. Re-run addressed by pid, which cannot self-match, the answer is the opposite: the environment is not in `argv` and `ps eww` prints none on macOS. **The probe reported on itself and read as a finding about the world** — which is this section's whole subject, reached by the author of the section while writing it.

## Anything that loads WordPress runs under `php82`

Not a bounding concern, but the same class of "the failure names the wrong thing". A scratch script against the live install must use Herd's `php82` binary. The global 8.4 CLI dies with a fatal error inside `uamswp-seopress` before any of this plugin's code executes, and the message names whichever file you were testing — so a five-minute investigation starts in the wrong place.

## The DRY line (original wording)

This file is the standing statement on **bounding local processes, attributing them, and killing them**. The per-run *tool* mechanics — `run_in_background`, `timeout`, `Monitor` — live in those tools' own descriptions; don't restate them here. The general form of the sweep section above — prove a probe could have returned something else before trusting that it did not — belongs to [`an-empty-result-is-not-evidence`](../rules/an-empty-result-is-not-evidence.md), and only the process-sweep instances are kept here; reading a command's status at all belongs to [`reading-exit-status`](../rules/reading-exit-status.md). It composes with [`github-api-budget`](../rules/github-api-budget.md), which owns choosing what to call rather than bounding the wait, and with [`worktrees`](../rules/worktrees.md), which owns why two sessions share one checkout in the first place.
