# Rule — spend the REST quota to preserve the GraphQL one — history

The incident history behind [`github-api-budget`](../rules/github-api-budget.md): the evidence each instruction rests on.
Not auto-loaded. Read it when a rule is questioned, before re-arguing it.

## Why this is a standing order

Observed here on 2026-07-31 mid-session: `graphql 5040/5000` (already over) alongside `core 588/5000`, i.e. **4,412 REST calls still available** and issue work nonetheless at a full stop. The session had to re-derive the REST equivalents under time pressure. Board work genuinely had to wait for the reset; nothing else did.

## Reading the quota

From step 1, *Check before a batch, not after it fails*.

**`gh api rate_limit` does not track this account's GraphQL consumption.** It answers `used: 0, remaining: 5000` regardless, so it is not stale — it is inert, and any agreement with reality is a coincidence of a fresh quota. Both instruments read at the same instant on 2026-09-05, mid-outage:

```
endpoint   used 0      remaining 5000
header     used 5002   remaining 0      calls REFUSED
```

**Wait on the header's `X-Ratelimit-Reset`, and never on the endpoint's.** The header's value is fixed and counts down. The endpoint's is recomputed as `now + 3600` on every read, so it moves away exactly as fast as you approach it — measured here, it advanced 21 seconds across 21 seconds of wall clock and stood 3600 seconds out both times. A loop waiting on the endpoint's reset does not wait too long; it never terminates.

**The header's reset bounds the window it describes, not the next one.** Once it passes, the refilled quota is shared and can go quickly: measured the same evening, a window reset, refilled to 4,993, and was exhausted again inside about four minutes, after which the header reported a fresh reset an hour out. Wait for the reset once, then re-read. Do not treat one reading as a promise about the time after it, and never retry in a tight loop.

A served call answers with `"data"`. A refused one answers with `"errors"` and `"code":"graphql_rate_limit"`, under the same `HTTP 200`, beside headers that look no different:

```
HTTP/2.0 200 OK
{"errors":[{"type":"RATE_LIMIT","code":"graphql_rate_limit",
            "message":"API rate limit already exceeded for user ID …"}]}
X-Ratelimit-Remaining: 0
X-Ratelimit-Used: 5003
```

**The plausible inference runs the other way, and that is the dangerous one.** A `200` returned at the moment a mutation was refused reads as a header describing the state *after* a request that succeeded. A guard built on that treats a refusal as good — the direction this repository's defining failure mode already runs in.

**A refused request does not increment `Used`.** Bracketed across two refusals inside one exhausted window:

```
Used before            5003
gh project item-list   rc=1   REFUSED
Used after             5003
gh project field-list  rc=1   REFUSED
Used after two         5003
```

So `Used` counts work done rather than attempts. A caller retrying on refusal watches a completely static counter while making no progress, and the `Used` delta step 8 prescribes for measuring cost measures **served** calls only.

**Both readings are one account on one exhaustion event, 2026-09-05**, not reproduced on a second account or a second window. The probe above was executed on a healthy quota, so its served arm is verified here and its refused arm is quoted from that event rather than reproduced.

## Draft to ready

From step 2, *Know which `gh` commands are secretly GraphQL*.

**A second operation has no REST surface, and unlike the board it sits inside the build flow rather than beside it: flipping an open draft to ready.** `PATCH /repos/{o}/{r}/pulls/{n}` takes `title`, `body`, `state`, `base` and `maintainer_can_modify`, and `draft` is not among them. So REST honors `draft` when it **creates** a pull request and **silently ignores it on update**, returning the unchanged value with no error. **"No REST path" is the wrong summary and produces the wrong instinct** — the field is accepted, changes nothing, and reports success. The mutation that does work is `markPullRequestReadyForReview`.

**Bounds, because the two halves of this rest on different evidence.** The parameter list is GitHub's REST reference for *Update a pull request*, read 2026-09-09. The silent-ignore behavior was measured on this account in the two sibling `UAMS-Web` repositories rather than in this one, and only for the flip **to** ready — setting `draft: true` on a ready pull request is untested. **The create-call half is documented rather than measured, and the obvious local check does not establish it.** `#199`, `#203` and `#205` were each opened through `POST …/pulls` carrying `draft` in the JSON body and came back `draft: false` — which is equally what an ignored field would produce, since `false` is the default. Passing `draft: true` and getting a draft back is the reading that would discriminate, and nobody has taken it here.

## Pull requests in the REST issue endpoints

From step 3, *Use the REST equivalents for issue work*.

**These endpoints return pull requests; the `gh` wrappers do not.** `repos/{o}/{r}/issues` and `search/issues` both return pull requests alongside issues, distinguished by a `pull_request` key, while `gh issue list` filters them out. The difference is silent, and it defeats the duplicate check [`writing-issues`](../skills/writing-issues/SKILL.md) requires before filing: run through the wrapper, the check reports the ticket and conceals the pull request implementing it. Measured here on `emoji` — the REST form returned issues #91, #100 and #102 **and** pull requests #101 and #105; the wrapper form can return neither pull request at all (#102).

## What a squash merge composes from

From step 3.

**What it composes from is a repository setting, and on this one it is the pull-request title and BODY — not the branch commits.** Measured across the five most recent squash merges on `main`, with the branch side checked too:

```
d9845bf17  PR #203   first body line   Closes #111.
39c1c1f88  PR #202                     Closes #98. …
cf736966c  PR #201                     Closes #119. …
f1b3a58b2  PR #199                     Closes #117.
a0e35cb5f  PR #198                     Closes #95.

branch commits behind #199 and #203   0 closing directives
```

**That inverts the obvious check.** An enumeration that reads branch commit messages finds nothing on any of those merges, and every one of them closed an issue. A clean result from that command is what a branch carrying no directives looks like *and* what this configuration looks like when the directive is in the body — indistinguishable, per [`an-empty-result-is-not-evidence`](../rules/an-empty-result-is-not-evidence.md).

**Which route acts is open, and the comfortable answer would be to pick one.** Four closes checked across three repositories on 2026-09-05 all carried `commit_id: null`, the body route. Two older records carry a commit id: `uams-statamic#1992` (2026-08-13) and `wordpress-importer#570` (2026-07-15). Either both routes are live and something not yet isolated decides which fires, or the behavior changed between August and September, or the older records are wrong. Nothing available here separates the three.

## Closing-keyword parsing

From step 3, the four parsing details.

- **A newline is whitespace to GitHub, and a per-line read cannot cross one.** A line ENDING in a closing keyword, followed by a line BEGINNING with `#N`, is a live directive: GitHub's parser treats the newline as the whitespace the pattern already allows, and closes the issue. The blindness is entirely in `-n` -- the keyword and the reference are never in the same string -- which is why the enumerations above slurp. **Measured in `uams-statamic` on 2026-09-14 and filed there as `uams-statamic#2464`:** a squash message whose line ended `closed` and whose next line began `#2241` closed `#2241`, although the pull request declined to close it and its `closingIssuesReferences` was empty. *The two are different code paths.* Swept here over the last 120 commits on `main`, the shape occurs once, in `349b74ede0e` -- and that instance proves nothing either way, because its target had been closed 27 days earlier and there was nothing left to close.
- **Code formatting around the KEYWORD disarms it, and the pattern above does not know that.** A directive inside a code span or a fenced block is inert -- GitHub does not link it -- so a hit there is not a finding. Measured on 2026-09-11 by reading `closingIssuesReferences` on already-merged pull requests, which needs no write and closes nothing:

  ```
  uamswp-migration-api#205   1 directive in prose, 13 in spans or a fence   links only the prose one
  wordpress-importer#1036    line 1 is `Closes #1013.`, keyword in a span   links NOTHING
  wordpress-importer#1009    `#1002` in backticks plus a bare "fixed #962"    links #962 AND #1005
  ```

  **The last row carries two facts and both are load-bearing.** It links **two** issues, which kills "only the first directive counts" -- the explanation that survives every reading available inside this repository, because no body here has two plain-prose directives naming different issues. And its `#1002` in backticks sits on the same line as the bare keyword that did fire, so a span disarms only when it encloses **the keyword**, never by being nearby. An indexing lag is excluded rather than assumed away: the empty fields have read empty since 2026-09-09, and the lag is seconds.

  **So the enumeration over-reports on this repository's own documentation.** Run against `#205` -- the pull request that ADDED this control -- it returns three directives that would appear to close `#2`, `#3` and `#4`, and all three are inert. Read what the pattern returns; do not count it. A check that cries wolf on the document describing it is read as noise, which is the failure [`reading-exit-status`](../rules/reading-exit-status.md) names.

## Paginated read-back

From step 4, *Dependencies and sub-issues have REST endpoints*.

**Read them back paginated, not with a bare `GET`.** `…/dependencies/blocked_by` and `…/sub_issues` are ordinary REST collections and truncate at the default page size like any other — measured against `wordpress-importer#589`, which carries 32 edges: a bare `GET` returns exactly 30, `--paginate` returns all 32. That is the same failure step 6 already covers for `--paginate | wc -l`; nothing here is a new hazard, only a second endpoint family it applies to.

## Truncated pagination through the proxy

From step 6, *Never trust a `--paginate` count you piped somewhere*.

```bash
gh api --paginate '…/issues?state=open&labels=afk&per_page=100' --jq '…' | wc -l
# 100 — pipeline exit 0, because `wc` succeeded. The real answer was 126.
```

## Original wording of passages condensed in place

These paragraphs were shortened in [`github-api-budget.md`](../rules/github-api-budget.md) rather than moved. Their full wording as it stood before the move is kept here, in file order.

GitHub meters **REST and GraphQL separately** — 5,000 points per hour each, tracked independently. Prefer the **REST** endpoint for anything REST can do, and reserve GraphQL for the work that has no alternative. In practice that means one thing above all: **Projects V2 is GraphQL-only**, so every GraphQL call spent on an issue is a call the board may not have when you need it.

**Why this is a standing order.** The two quotas do not fail together, but `gh`'s ergonomic commands make it look as though they do. `gh issue create`, `gh issue edit`, and even resolving `--assignee @me` are all GraphQL-backed, so when the GraphQL quota is gone every one of them errors identically — while REST sits untouched. Observed here on 2026-07-31 mid-session: `graphql 5040/5000` (already over) alongside `core 588/5000`, i.e. **4,412 REST calls still available** and issue work nonetheless at a full stop. The session had to re-derive the REST equivalents under time pressure. Board work genuinely had to wait for the reset; nothing else did.

The board matters here: [`writing-issues`](../skills/writing-issues/SKILL.md) files every new ticket onto `UAMS-Web/projects/1` and sets six fields on it, all GraphQL. **The quota is metered per user across the whole organization, not per repository**, so it can be gone before you make a single call — exhaustion is a condition you inherit as often as one you cause.

**The commonest way to waste it is verifying writes that already succeeded.** A successful `item-edit` (exit 0) *is* the confirmation. Re-reading the board to check is expensive, and the board is organization-level: `gh project item-list --limit 2000` returns every item from every repository on it, so filtering that result by issue *number* alone silently matches another repository's issue that happens to share the number. Filter by repository, or don't read at all.

A reading from the endpoint establishes nothing about whether the next GraphQL call will be served.

**A refused GraphQL call returns HTTP 200** with the error in the response body. Nothing in the status line suggests consulting the headers, and an exit-code check sees success.

**The headers arrive on that refused call exactly as on a served one, so a reading of them establishes neither outcome.** `Remaining: 0` does not mean this call failed, and it does not mean it succeeded — the headers describe the quota, never the request. **Only the response body distinguishes them**, and one probe shows both at once:

**Read that probe's output, never its exit status** — the pipeline exits `0` either way, because `grep` matches on both arms and a pipeline reports its last element ([`reading-exit-status`](../rules/reading-exit-status.md)). The probe is deliberately shaped to be read rather than tested: a check written around its exit code reproduces, one level in, exactly the mistake this paragraph is about.

**The meter is the account's, not this session's.** Several sessions draw on one bucket, so a pre-check reserves nothing and can be stale by the time you act on it. **Verify a write by reading it back**, never by having checked the quota beforehand — that matters most for the board, where a refused mutation still returns and reporting the intent as the outcome is how a card is recorded as moved when it did not move.

2. **Know which `gh` commands are secretly GraphQL.** `gh issue create`, `gh issue edit`, `gh issue close`, `gh issue list --search`, the `@me` handle lookup, and **all** of `gh project *`. The last has no REST surface at all: when GraphQL is exhausted, board work is blocked until the header's reset — bounded and knowable rather than indefinite — and that is the resource worth protecting.

**So an exhausted GraphQL quota can strand a finished branch as a draft.** Building, pushing, opening and merging are all REST and all keep working; the one step between them that does not is making the pull request ready.

**Avoid the transition rather than budgeting for it.** `draft` *is* a field on the REST create call, so a pull request that is already finished when it is opened can be opened ready and never needs the flip at all.

[`.claude/settings.json`](../settings.json) carries an **empty `allow` list** — nothing here is auto-approved, so quote these commands as the shell requires. Before adding entries, know two things. Permission rules are **case-sensitive literal prefix matches on the raw command text**, so quoting any part of a prefix defeats the rule silently. And a rule scoped to the `repos/` namespace permits far more than the table below — `-f private=false`, a `git/refs` force-update, `/transfer`, `branches/{b}/protection` — while `gh` accepts its method flag anywhere, so `gh api repos/…/git/refs/heads/main -X DELETE` matches a `POST`-shaped prefix and no deny entry. An allowlist is a convenience, never a security boundary; branch protection is that boundary.

**Omitting `commit_message` makes GitHub compose one, and the composed text is parsed for closing keywords.** So the merge call prescribed above closes issues from a surface that is not the pull-request body, and nothing in the merge response reports which one acted.

**So check both surfaces, and do not treat a control on either as covering the other.** `-f commit_message=…` replaces the composed text and leaves the body untouched; editing the body leaves an already-composed message untouched. **Which one fired is decidable after the fact from the close event's `commit_id`** — a close driven by a commit carries that commit's id, a close driven by the pull-request link carries `null`.

**Enumerate the body — the surface measured to fire here.** Runnable as printed; substitute the pull request you are merging:

**And the branch commits, which this configuration does not fold in but another repository's will**, from a tree holding the branch:

**`HEAD` rather than a literal `origin/<branch>` placeholder, deliberately: `<` is a redirection**, so the placeholder form is not runnable as printed — bash reads a file named `branch` and the pipeline still reports whatever `perl` exited with. Validating a branch you are not on takes the same command with that branch's remote-tracking ref in place of `HEAD`.

**And a second arm, because the first cannot reach the shape below it.** A keyword ending one line, a reference beginning the next:

That must print exactly `closed #2241`, on one line. **Run it with `-ne` in place of `-0777 -ne` and it prints nothing** -- that is the whole of the fourth detail below, and the arm exists so the slurped form is shown to see what the per-line form cannot before either is trusted. **It is `perl` rather than `grep` for the reason [`no-emoji-in-durable-records`](../rules/no-emoji-in-durable-records.md) gives about its own sweep: the macOS default is not the GNU one.** Word boundaries are where it bites — `[[:<:]]` is BSD-only, `\b` is not guaranteed on a strict BSD grep, and a form verified on one machine can silently match nothing on another.

**Four parsing details defeat a check by eye. Three are in those controls; the remaining one is the one no control can show, because the pattern does not model it:**

- **The keyword must precede each number.** `Closes #80, #81` closes **only** #80 while reading as though it closed both. The pattern matches `Closes #80` and not `#81`, which is exactly what GitHub does with it.

- **Negation does not disarm it.** `does not close #N` and `not a fix for #N` each carry a keyword immediately followed by a reference, and GitHub does not parse the negation. That is why the control carries one, and why its expected output includes `close #2`.

5. **Always pass Markdown from a file** — `-F body=@body.md`. Issue and PR bodies are dense with backticks, `$`, `!`, and fenced blocks that the shell mangles as an inline argument, and the failure is intermittent enough to look fine until it isn't. This is the same reason [`writing-issues`](../skills/writing-issues/SKILL.md) mandates `--body-file`.

6. **Never trust a `--paginate` count you piped somewhere.** `gh api --paginate` follows GitHub's `Link: rel="next"`, which GitHub renders in numeric-ID form (`repositories/<numeric-id>/issues?page=2`). A Claude Code cloud session's GitHub proxy refuses that shape — `Numeric-ID repository paths are not supported through this proxy (HTTP 403)` — so `gh` prints page 1, exits 1, and a pipe throws the exit code away:

A truncated result is otherwise **indistinguishable from a complete one**. Use `set -o pipefail` so the failure is at least visible, and prefer walking `&page=N` explicitly until a short page — every URL then stays in the `repos/{owner}/{repo}/…` form the proxy accepts, on any machine. Count the **raw** page length, not a filtered one — a `--jq` filter that drops items makes a full page look short and ends the walk early.

7. **Do not contort a genuinely graph-shaped read into many REST calls.** One GraphQL query that replaces a dozen round-trips is still the right call when you need related data across many objects — the point is to stop spending GraphQL on *single-object issue mutations* that REST handles for free, not to ban it. `gh project item-list` / `field-list` remain the way to read board state.

8. **The dominant routine cost is a wide board listing.** One `gh project item-list --limit 2000` was measured at roughly 1,700 of the 5,000 hourly points, issued to read the field and option ids that [`writing-issues`](../skills/writing-issues/SKILL.md) already records. Read them from the skill instead; that costs nothing. Measure any future cost as a delta in `X-Ratelimit-Used` between two response headers — an endpoint delta is always zero and reports every call as free.

This file is the standing statement on **which API surface to spend**. The project-board *mechanics* — field ids, single-select option ids, the `item-add` / `item-edit` recipe, the triage defaults — live in [`writing-issues`](../skills/writing-issues/SKILL.md) and are not repeated here; that skill's board section is the thing this rule protects the budget for. Every skill's `gh` usage inherits this as a standing order rather than restating it. It composes with [`long-running-commands`](../rules/long-running-commands.md), which owns bounding a wait rather than choosing what to call.
