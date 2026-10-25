# Rule — read the result, not the exit code — history

The incident history behind [`reading-exit-status`](../rules/reading-exit-status.md): the evidence each instruction rests on.
Not auto-loaded. Read it when a rule is questioned, before re-arguing it.

## Why this is a standing order

**Why this is a standing order.** This repository's gate was reported green on a run where `spell` had FAILED, twice in one session, and both reports were retracted only because the summary was read afterward. Nothing about the number itself looked wrong. That is the shape of every failure below: a status that is real, correct, and about a different command than the one you meant.

## Three ways the number stops being the command's

Every block below was run as printed under both `bash` and `zsh`, and the output is verbatim. The two shells print the same thing everywhere except one section, which says so where it happens.

```bash
false;           echo "plain, the control    -> $?"
false | tail -1; echo "a pipeline            -> $?"
false;           echo "a substitution left of it $(basename /tmp/x) -> $?"

bash -c 'false; echo "the printed value     -> $?"'
echo "the LIST's own status -> $?"
```
```
plain, the control    -> 1
a pipeline            -> 0
a substitution left of it x -> 0
the printed value     -> 1
the LIST's own status -> 0
```

**The first line is the control and it is not optional.** Without it, a `1` proves nothing about the rows under it and a `0` cannot be attributed.

On the command-list case: This is the one that produced both false greens here, because a harness reports the list's status and the true number was two lines above it, on screen, unread.

## The boundary is a subshell, and it is positional

```bash
V=hello
false; echo "parameter expansion $V        -> $?"
false; echo "arithmetic $((1+1))           -> $?"
false; echo "`basename /tmp/x` backticks   -> $?"
false; echo "${U:-$(basename /tmp/x)} nested in a default -> $?"
false; echo "a substitution to the right   -> $? $(basename /tmp/x)"
```
```
parameter expansion hello        -> 1
arithmetic 2           -> 1
x backticks   -> 0
x nested in a default -> 0
a substitution to the right   -> 1 x
```

## A pipeline needs the whole array, captured first — and the array is named by the shell

**The array has a different name in each of the two shells a harness here runs, and the wrong name does not error; it expands to nothing.** Every other block in this file prints identical output under `bash` and `zsh`; this section is the one place they diverge, and the divergence is silent. Which shell a harness runs is a property of the machine rather than of this repository — `bash` on some machines, `zsh` on others — so establish which one you are in before reading a number out of it:

One of the two is a version and the other is `no`; a shell that prints `no` twice is not one this file has measured. On the machine these blocks were measured on it prints `zsh=5.9 bash=no`, so the `zsh` rows below are the harness shell itself and the `bash` rows are `bash 3.2.57` invoked explicitly.

**Under `bash`, the array is `PIPESTATUS`, zero-indexed.** Run under `bash 3.2.57`:

```bash
false | true; rc=$?; ps=${PIPESTATUS[0]};  echo "capture rc first  rc=$rc ps=$ps"
false | true; ps=${PIPESTATUS[0]}; rc=$?;  echo "capture ps first  rc=$rc ps=$ps"
false | true; st=("${PIPESTATUS[@]}");     echo "copy the array    all=${st[*]} first=${st[0]}"
```
```
capture rc first  rc=0 ps=0
capture ps first  rc=0 ps=1
copy the array    all=1 0 first=1
```

`$?` and `PIPESTATUS` are both reset by the next command — in `bash` an assignment counts as one — so capturing one destroys the other. Row two looks like the fix and is worse than row one: `ps` is right, and `rc` is now the status of the **assignment** — a zero that reads as a captured value and never was the pipeline's. **Copy the array in the first statement** and derive everything from the copy; `set -o pipefail` is ergonomics, not protection, since it folds the status into `$?` and inherits every hazard above.

**Under `zsh`, the array is `pipestatus`, lower case, and it is ONE-indexed.** The same three rows, with only the name and the index changed, run under `zsh 5.9`:

```bash
false | true; rc=$?; ps=${pipestatus[1]};  echo "capture rc first  rc=$rc ps=$ps"
false | true; ps=${pipestatus[1]}; rc=$?;  echo "capture ps first  rc=$rc ps=$ps"
false | true; st=("${pipestatus[@]}");     echo "copy the array    all=${st[*]} first=${st[1]}"
```
```
capture rc first  rc=0 ps=1
capture ps first  rc=0 ps=1
copy the array    all=1 0 first=1
```

Row one comes out right here, and that is a difference between the shells rather than a reason to prefer the form: in `zsh` an assignment leaves `pipestatus` alone, while a builtin or an external command repopulates it. The copy-first form is correct under both, and it is the one to write.

**The index is a second trap, after the name.** A `zsh` array's element `0` is empty, so `${pipestatus[0]}` or `${st[0]}` translated straight from the `bash` form expands to nothing — the same nothing the wrong name produces, reached one step later:

```
zsh   ${pipestatus[0]}   empty     ${st[0]}   empty
zsh   ${pipestatus[1]}   1         ${st[1]}   1
```

**In the wrong shell, the right-looking form expands to an empty string, and that is the whole hazard.** The `bash` block run under `zsh`, and the `zsh` block run under `bash`, each print every row with nothing after `ps=` — no error, no message, a line one character shorter than the documented one. The `bash` block under `zsh 5.9`:

```
capture rc first  rc=0 ps=
capture ps first  rc=0 ps=
copy the array    all= first=
```

**An empty string is not a status, and the guards that would ordinarily read one treat it as zero.** Measured under `zsh 5.9` with `ps` empty:

```
echo "exit=$ps"                    exit=              reads as nothing to report
[ "$ps" -eq 0 ]                    rc=0, silent       a PASS
if [ "$ps" -ne 0 ]; then FAIL      else branch        a PASS
(( ps == 0 ))                      true               a PASS, and under bash too
[ "${ps:-0}" = 0 ]                 true               the default manufactures the PASS
[ "$ps" = 0 ]                      rc=1               the one that fails safe, and only by accident
```

`bash` refuses `[ "" -eq 0 ]` with `integer expression expected` and exit `2`; `zsh` returns `0` and says nothing. Under `set -u` both shells refuse the wrong name loudly — `parameter not set`, `unbound variable` — but nothing in this repository sets it, and the harness measured here does not (`u` is absent from its `$-`). So this is the failure the rest of this file describes, one step further in: not a real number belonging to a different command, but no number at all, in a place where a reader checks the value rather than its length.

## A worked example in a durable record is run, not written

Issue and pull-request bodies here carry commands, and [`pre-merge-check`](../rules/pre-merge-check.md) requires a written finding before every merge. Two properties, both learned by getting them wrong:

**It extracts only fences tagged as shell, and that is the whole correctness of it.** An extraction keyed on the bare fence executes a document's *displayed output* as though it were commands — and this file's output blocks are full of unquoted arrows, because that is what the rows being explained look like. Run that way against this very file, the audit creates files named `0` and `1` from lines like `plain, the control    -> 1`, and reports them alongside the one file that is real. The prose would be correct, the examples would be correct, and the instrument would be wrong about both.

That matters more than two stray files. Nearly every document under `.claude/` pairs a command block with an output block — it is the house style, and it is what makes these rules legible — so an audit keyed on the bare fence reports a leak on almost anything it is pointed at. **A check that cries wolf is read as noise, and a real leak then passes inside it.** This is the same lesson as the paragraph below, one level out: there the instrument was a regex written in the author's own frame, here it is a shell that cannot tell an illustration from an instruction. The fence is the document already saying which is which; the audit only has to read it.

That is not hypothetical. On 2026-09-09 a session running the pre-fix audit across every file under `.claude/` reached `skills/coverage-driver/SKILL.md`, whose PCOV build procedure sets `HERD="$HOME/Library/Application Support/Herd/config/php/84"` and then copies a `pcov.so` over the live one. Fourteen seconds after the sweep began, the extension was overwritten with an unsigned Homebrew build and every default-PHP process on that machine began dying with `Killed: 9`, exit 137, zero bytes of output — across three repositories and every session on the host, for roughly half an hour, while several of them measured an outage none of them had caused.

**The fence fix would not have prevented it.** That block is `bash`-tagged and correctly so. What prevents it is scope: this audit is for a worked example in a durable record you are about to publish — one block, which you wrote, whose commands you already know. A sweep over documentation is a different activity wearing the same command, and the failure it produces is not a stray file in a scratch directory but whatever those commands actually do.

**Do not audit your own output with a pattern.** Two probes written here to find exactly these defects both reported clean and were both wrong: the first excluded every line containing an arrow, which is where the defect lives; the second stripped the arrow before searching for a redirection, deleting the operator it was hunting. Both were written by the author of the material, in the same frame, so both encoded the same assumption. A third pattern would have too. The shell decides which rows are commands, and that is the only opinion that counts.

## Original wording of passages condensed in place

These paragraphs were shortened in [`reading-exit-status.md`](../rules/reading-exit-status.md) rather than moved. Their full wording as it stood before the move is kept here, in file order.

- **A pipeline.** `$?` is the *last* element's status. `tail` succeeded, so the pipeline reports success while `false` failed.

- **A command list.** The printed value is true; the **list's** status is the trailing `echo`'s. This is the one that produced both false greens here, because a harness reports the list's status and the true number was two lines above it, on screen, unread.

A scan that looks for a leading `$(` on reporting lines misses both of the middle two.

**For a durable record, depend on neither name.** The invocation at the top of this file already shows the form that needs no array — a redirection, and nothing after it, so `$?` is the command's own. Where a pipeline is genuinely needed and the block must run as printed for whoever reads it next, run it under a named shell rather than the ambient one: `bash -c '…'` pins the shell.

Note the control writes its probe and its blocks into directories it creates, never into the working directory. That is not fastidiousness: this control lives inside a document the audit is run against, so a control that littered the caller's directory would be reported as a leak by the very check it exists to validate.

**The audit EXECUTES what it extracts. Point it only at the block you are about to publish, never at a tree of documentation.** Tagging the fence fixed the audit's inability to tell an illustration from an instruction. It does not make the instructions safe: a `bash`-tagged block is extracted precisely because it *is* a command, and this repository's own documentation contains commands that install things.

This file owns **reading and reporting a command's status**. Bounding a long run and killing what it leaves behind is [`long-running-commands`](../rules/long-running-commands.md); neither the bound nor the process attribution is restated here. Whether a claim survives scrutiny at all is [`adversarial-review`](../rules/adversarial-review.md); what a pull-request body must contain is [`writing-pull-requests`](../skills/writing-pull-requests/SKILL.md). The general form this file's own pipeline case is one instance of — prove a probe could have returned something other than what it did, before trusting that it did not — is [`an-empty-result-is-not-evidence`](../rules/an-empty-result-is-not-evidence.md).
