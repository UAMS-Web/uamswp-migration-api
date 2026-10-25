#!/usr/bin/env node
/**
 * watch-github.mjs — emit one line per pull-request lifecycle event in this repository.
 *
 * Feeds the `local-ci` skill's merge gate: a session arms this under the harness `Monitor` tool
 * (`persistent: true`), and every stdout line becomes a notification that enqueues work. The gate
 * itself is unchanged — this only decides *when* it is asked to look.
 *
 * Events emitted (one per line, tab-free, newest state last):
 *
 *   READY    <repo>#<n>  opened ready   <title>  <url>
 *   DRAFT    <repo>#<n>  opened draft   <title>  <url>
 *   READY    <repo>#<n>  draft -> ready <title>  <url>
 *   DRAFT    <repo>#<n>  ready -> draft <title>  <url>
 *   PUSH     <repo>#<n>  pushed <sha>   <title>  <url>
 *   GONE     <repo>#<n>  merged|closed  <title>  <url>
 *   WATCH-ERROR  <repo>  <n> consecutive poll failures — <message>
 *
 * **This script reads and prints. It never writes.** Two `gh api` calls, both GET; no comment, no
 * commit status, no merge, no draft flip. What to do with an event is the reading session's
 * decision under `.claude/rules/pre-merge-check.md`, and keeping the watcher read-only is what
 * lets it be armed without granting it anything. In particular the gate's "do not return to a
 * pull request until its head moves" is satisfied by the PUSH event existing, not by this script
 * withholding anything.
 *
 * **Whether a red verdict flips the pull request to draft is deliberately not decided here.**
 * `uams-statamic` holds annotate-not-mutate; `wordpress-importer`'s gate uses the draft flag as
 * its hand-back. That fork is the operator's, and this watcher writes the flag in neither
 * direction, so either answer can be adopted without changing this file.
 *
 * The first poll seeds state silently — existing open pull requests are the queue's starting
 * position rather than events — so arming the watcher mid-stream does not replay history. Pass
 * `--emit-existing` to announce them instead.
 *
 * Pull requests are tracked by diffing the full open set, which is bounded and small here: this
 * repository has carried at most a handful of open pull requests at once.
 *
 * **Ported from `UAMS-Web/uams-statamic`'s `scripts/watch-github.mjs`** (blob `30f057cdc`, 29,400
 * bytes, carrying `c69be018f` "Emit a PUSH event when an open ready PR's head moves"), which is
 * the copy that already has the push re-entry `uams-statamic#2338` found missing. Its issue-watching
 * feed is **not** ported: it exists there for a catalog consumer this repository does not have, and
 * `.claude/`'s convention is that surface arrives at the point of need rather than ahead of it. See
 * `docs/DECISIONS.md` D-065 and #156.
 *
 * Uses the GitHub REST API (`gh api`) rather than `gh pr list`, which is GraphQL — the two have
 * separate rate-limit buckets, and a poll running every minute must not compete with the board
 * work `.claude/rules/github-api-budget.md` exists to protect.
 *
 * Usage:
 *   node scripts/watch-github.mjs
 *   node scripts/watch-github.mjs --interval 30 --emit-existing
 */

import { execFile } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { promisify } from 'node:util';

import {
    buildPattern,
    extractGlyphClass,
    parseCodePointClass,
    RULE_PATH,
    runControlArms,
    scanText,
} from './check-no-glyphs.mjs';

const execFileAsync = promisify(execFile);

/**
 * The `gh` invoker every fetch below goes through. main() swaps in whatever it is handed, so a test
 * can observe exactly which REST endpoints a flag combination requests without spawning `gh`.
 *
 * @var callable
 */
let execGh = execFileAsync;

/** The repository this watcher exists for. A single repo, unlike the sibling this is ported from. */
const DEFAULT_REPOS = ['UAMS-Web/uamswp-migration-api'];

/** Consecutive per-repo poll failures tolerated before the failure is surfaced as an event. */
const FAILURE_STREAK_BEFORE_ALERT = 3;

/** Items per REST page. The endpoint's maximum, so a quiet repo costs exactly one request. */
const PAGE_SIZE = 100;

/**
 * Sanity ceiling on the page walk.
 *
 * The stop condition trusts the server to advance on `page=N`. A proxy that ignores the parameter
 * and re-serves page 1 would otherwise loop forever inside a long-running watcher, growing `items`
 * without ever throwing — so the failure-streak path never fires and no `WATCH-ERROR` is emitted.
 */
const MAX_PAGES = 100;

let options = null;
let prRepos = [];
let state = new Map();
let failureStreaks = new Map();
// The comment sweep is incremental: one request per poll keyed on ?since=, so its cost does
// not grow with the corpus. null means this repo has not been seeded yet.
let commentCursor = new Map();

// Built on first use rather than at import, so loading this module for a test has no side
// effect -- but still before anything is reported, which is what the control arms are for.
let glyphScanner = null;
let stopping = false;

const HELP = `watch-github.mjs — emit one line per pull-request lifecycle event

  --interval <seconds>   poll interval, minimum 10 (default 60)
  --repos <a,b>          repositories to watch (default UAMS-Web/uamswp-migration-api)
  --emit-existing        announce already-open pull requests on the first poll
  --once                 seed and exit, without entering the poll loop
  --help                 this text

Reads only. This script never comments, merges, or changes a pull request.
`;

/**
 * Parse `--flag value` / `--flag=value` / bare-boolean CLI arguments.
 *
 * @param  {string[]}  argv
 * @return {{interval: number, repos: string[], emitExisting: boolean, prRepos: string[], once: boolean, gh: string}}
 */
export function parseArgs(argv) {
    const options = {
        interval: 60,
        repos: DEFAULT_REPOS,
        emitExisting: false,
        once: false,
        gh: process.env.LOCAL_CI_GH ?? 'gh',
    };

    for (let index = 0; index < argv.length; index += 1) {
        const [flag, inlineValue] = argv[index].split(/=(.*)/s);
        const nextValue = () => inlineValue ?? argv[++index];

        switch (flag) {
            case '--interval':
                // Floored rather than rejected: a watcher is armed by hand, and a typo that
                // hammers the REST quota is worse than one that polls slightly slower than asked.
                options.interval = Math.max(10, Number(nextValue()));
                break;
            case '--repos':
                options.repos = nextValue().split(',').map((repo) => repo.trim()).filter(Boolean);
                break;
            case '--emit-existing':
                options.emitExisting = true;
                break;
            case '--once':
                options.once = true;
                break;
            case '--help':
                process.stdout.write(HELP);
                process.exit(0);
                break;
            default:
                throw new Error(`Unknown flag: ${flag}`);
        }
    }

    if (! Number.isFinite(options.interval)) {
        throw new Error('--interval must be a number.');
    }

    options.prRepos = options.repos;

    return options;
}

/**
 * Fetch every page of a REST collection endpoint, walking `?page=N` explicitly.
 *
 * `gh api --paginate` follows the `Link: rel="next"` header, which GitHub renders in numeric-ID
 * form (`repositories/946195283/pulls?page=2`). A Claude Code cloud session's GitHub proxy refuses
 * that shape — `Numeric-ID repository paths are not supported through this proxy (HTTP 403)` — so
 * `gh` writes page 1 and exits 1, and a pipe throws the exit code away. Requesting each page by
 * number keeps every URL in the `repos/{owner}/{repo}/...` form the proxy accepts.
 * `.claude/rules/github-api-budget.md` records the same trap.
 *
 * Pages are parsed unfiltered rather than through `--jq`, because a filter that drops items makes
 * a full page look short, which ends the walk early and truncates silently.
 *
 * @param  {string}  gh
 * @param  {string}  path  REST path with any query string, minus `per_page`/`page`.
 * @return {Promise<object[]>}
 */
async function fetchAllPages(gh, path) {
    const separator = path.includes('?') ? '&' : '?';
    const items = [];

    for (let page = 1; page <= MAX_PAGES; page += 1) {
        const { stdout } = await execGh(gh, [
            'api',
            `${path}${separator}per_page=${PAGE_SIZE}&page=${page}`,
        ], { maxBuffer: 16 * 1024 * 1024 });

        const body = stdout.trim();
        const parsed = body === '' ? [] : JSON.parse(body);

        // An object here is an error payload that still exited 0, not a page of results.
        if (! Array.isArray(parsed)) {
            throw new Error(`Expected an array from ${path} page ${page}, got ${typeof parsed}.`);
        }

        items.push(...parsed);

        // A short page is the last page, so a quiet repo costs exactly one request.
        if (parsed.length < PAGE_SIZE) {
            return items;
        }
    }

    // Throwing rather than returning what we have keeps a truncated result from passing for a
    // complete one, which is the whole point of the explicit walk. `pollRepo` counts it a failure.
    throw new Error(`Pagination exceeded ${MAX_PAGES} pages for ${path}; refusing to loop.`);
}

/**
 * Whether an open pull request's head moved between two polls.
 *
 * Pure and exported so the decision is testable without polling: the interesting cases are all
 * about what should NOT fire, and each is cheap to state directly and expensive to reproduce
 * against a live repository.
 *
 * Deliberately false when either side has no SHA. A projection recorded before this field existed
 * carries `undefined`, and a poll that could not read one carries `null` — treating either as
 * "changed" would announce a push for every open pull request at once on the first poll after an
 * upgrade.
 *
 * Also false while the pull request is a draft on either side. A draft is never enqueued, so a push
 * to one has nothing to re-enter; and a push coinciding with a draft flip is reported as the flip,
 * which is the larger fact.
 *
 * @param  {{draft: boolean, sha: ?string}}  before
 * @param  {{draft: boolean, sha: ?string}}  after
 * @return {boolean}
 */
export function isPush(before, after) {
    if (! before?.sha || ! after?.sha) {
        return false;
    }

    if (before.draft || after.draft) {
        return false;
    }

    return before.sha !== after.sha;
}

/**
 * Fetch every open pull request for one repository via the REST API.
 *
 * @param  {string}  gh
 * @param  {string}  repo
 * @return {Promise<Array<{number: number, title: string, draft: boolean, url: string, sha: ?string}>>}
 */
async function fetchOpenPulls(gh, repo) {
    const pulls = await fetchAllPages(gh, `repos/${repo}/pulls?state=open`);

    return pulls.map((pull) => ({
        number: pull.number,
        title: pull.title,
        draft: pull.draft,
        url: pull.html_url,
        // The head SHA is what makes a push visible. Without it the projection is identical
        // before and after an author pushes a fix, so a pull request handed back red never
        // re-enters and waits on someone saying so out of band — which is exactly what
        // happened on #144, twice, per D-062.
        sha: pull.head?.sha ?? null,
        // The list endpoint carries the full body, so the glyph sweep below costs no
        // extra request -- measured against #222, whose body is 5489 characters from
        // this call and 5489 from the single-pull fetch.
        body: pull.body ?? '',
    }));
}

/**
 * Resolve whether a pull request that left the open set was merged or closed.
 *
 * @param  {string}  gh
 * @param  {string}  repo
 * @param  {number}  number
 * @return {Promise<string>}
 */
async function resolveClosedState(gh, repo, number) {
    try {
        const { stdout } = await execGh(gh, [
            'api',
            `repos/${repo}/pulls/${number}`,
            '--jq',
            'if .merged then "merged" else "closed" end',
        ]);

        return stdout.trim() || 'closed';
    } catch {
        // A pull request that vanished from the open set did something; reporting the weaker
        // "closed" is better than dropping the event because one follow-up request failed.
        return 'closed';
    }
}

/**
 * Build the glyph scanner, asserting both control arms before it can report anything.
 *
 * The character class and its ranges are read out of `no-emoji-in-durable-records.md`, never
 * restated here -- the same anti-drift rule `check-no-glyphs.mjs` follows for the working tree.
 * If the two disagreed, neither a reader nor the gate could tell which was wrong.
 *
 * A failing arm THROWS rather than returning a clean result. An expression that cannot match and
 * a corpus with nothing in it produce byte-identical silence, so a sweep that reports "no glyphs"
 * without first proving it can find one converts an untested claim into a green check.
 *
 * @param  {string}  root
 * @return {function(string): Array<{line: number, codePoints: string[]}>}
 */
export function createGlyphScanner(root = resolve(dirname(fileURLToPath(import.meta.url)), '..')) {
    const ruleText = readFileSync(resolve(root, RULE_PATH), 'utf8');
    const ranges = parseCodePointClass(extractGlyphClass(ruleText));
    const pattern = buildPattern(ranges);
    const failures = runControlArms(ranges, pattern);

    if (failures.length > 0) {
        throw new Error(`glyph scanner control arms FAILED, refusing to report: ${failures.join('; ')}`);
    }

    return (body) => scanText(body ?? '', pattern);
}

/**
 * The GLYPH event's detail string for one body, or null when it is clean.
 *
 * Reports CODE POINTS rather than the characters themselves. Echoing a glyph crashes on exactly
 * the legacy-code-page consumer the ban exists to protect, and reports that crash as a short
 * result -- which is the failure mode this whole rule is written against.
 *
 * A body is MULTI-LINE, and that is the trap this must not fall into: a sweep here once reported
 * zero against a true ninety-one because it read bodies with `read -r` and took the first line of
 * each. `scanText` splits the whole body, and the test covers a glyph below the first line.
 *
 * @param  {function(string): Array<{line: number, codePoints: string[]}>}  scan
 * @param  {string}  body
 * @return {?string}
 */
export function glyphDetail(scan, body) {
    const findings = scan(body);

    if (findings.length === 0) {
        return null;
    }

    return findings.map(({ line, codePoints }) => `line ${line}: ${codePoints.join(' ')}`).join('; ');
}

/**
 * Print one event line.
 *
 * @param  {string}  kind
 * @param  {string}  subject
 * @param  {string}  transition
 * @param  {string}  detail
 * @return {void}
 */
function emit(kind, subject, transition, detail) {
    console.log(`${kind}  ${subject}  ${transition}  ${detail}`);
}

/**
 * Emit a GLYPH line when an enqueued pull request carries one in its body.
 *
 * Reported beside the enqueue rather than as a poll of its own, because the body is already in
 * hand: the list endpoint carries it in full, so this costs no extra request.
 *
 * Only for pull requests entering the queue. A DRAFT never enqueues, and a body edited after it
 * is already ready is not visible to this watcher at all -- see the ticket for that residue.
 *
 * @param  {string}  subject
 * @param  {{body: string}}  pull
 * @return {void}
 */
function emitGlyphs(subject, pull) {
    glyphScanner ??= createGlyphScanner();

    const detail = glyphDetail(glyphScanner, pull.body);

    if (detail !== null) {
        emit('GLYPH', subject, 'body carries a banned glyph', detail);
    }
}

/**
 * Short `owner/repo` -> `repo` label, so event lines stay readable.
 *
 * @param  {string}  repo
 * @return {string}
 */
function shortRepo(repo) {
    return repo.split('/').pop();
}

/**
 * Poll one repository once and emit every transition since the previous poll.
 *
 * @param  {string}  repo
 * @return {Promise<void>}
 */
/**
 * Sweep comments posted since the last poll, and report any carrying a banned glyph.
 *
 * ONE request per poll, keyed on `?since=`, so the cost is flat rather than growing with the
 * corpus. The endpoint covers comments on issues AND on pull requests, which is why there is
 * no second call: measured at 147 + 98 = 245 on this repository.
 *
 * The first poll SEEDS SILENTLY. D-071 decided against retrospective correction, so a comment
 * that predates the watcher is not this sweep's business; only what arrives after it is.
 *
 * @param  {string}  repo
 * @return {Promise<void>}
 */
async function sweepComments(repo) {
    const previous = commentCursor.get(repo) ?? null;
    const now = new Date().toISOString();

    if (previous === null) {
        commentCursor.set(repo, now);

        return;
    }

    let comments;

    try {
        const { stdout } = await execGh(options.gh, [
            'api',
            `repos/${repo}/issues/comments?per_page=${PAGE_SIZE}&since=${encodeURIComponent(previous)}`,
        ], { maxBuffer: 16 * 1024 * 1024 });

        const body = stdout.trim();

        comments = body === '' ? [] : JSON.parse(body);
    } catch {
        // A failed sweep leaves the cursor where it was, so the next poll re-reads the window
        // rather than skipping it. Silent by design: pollRepo already reports a broken watcher,
        // and a second alert on the same outage is noise.
        return;
    }

    if (! Array.isArray(comments)) {
        return;
    }

    for (const comment of comments) {
        const detail = glyphDetail(glyphScanner ??= createGlyphScanner(), comment.body);

        if (detail === null) {
            continue;
        }

        const url = comment.html_url ?? '';
        const number = url.match(/\/(?:issues|pull)\/(\d+)/)?.[1] ?? '?';

        emit('GLYPH', `${shortRepo(repo)}#${number}`, 'comment carries a banned glyph', `${detail}  ${url}`);
    }

    commentCursor.set(repo, now);
}
async function pollRepo(repo) {
    let pulls;

    try {
        pulls = await fetchOpenPulls(options.gh, repo);
    } catch (error) {
        const streak = failureStreaks.get(repo) + 1;
        failureStreaks.set(repo, streak);

        // Surface a persistently broken watcher — silence is indistinguishable from calm, and a
        // gate that stopped being told about pull requests looks exactly like a quiet afternoon.
        // A repo failing its very first poll never seeded at all, so alert immediately rather than
        // waiting out the streak: that is a setup error (auth, typo, access), not a blip.
        const neverSeeded = state.get(repo) === null;

        if (neverSeeded || streak === FAILURE_STREAK_BEFORE_ALERT || streak % 30 === 0) {
            const message = (error.stderr || error.message || '').split('\n')[0].slice(0, 200);
            emit('WATCH-ERROR', shortRepo(repo), `${streak} consecutive poll failures`, message);
        }

        return;
    }

    failureStreaks.set(repo, 0);

    const previous = state.get(repo);
    const current = new Map(pulls.map((pull) => [pull.number, {
        draft: pull.draft,
        title: pull.title,
        url: pull.url,
        sha: pull.sha,
        // The state map is what every event handler below reads, NOT the fetch projection.
        // A field missing here is invisible: the glyph sweep read `pull.body` off this object,
        // found undefined, and reported clean on every pull request ever passed to it -- a
        // check that cannot fail, which is the exact false green this repository is written
        // against. Anything an event needs has to be carried through HERE as well.
        body: pull.body,
    }]));

    // First successful poll: seed without replaying history unless explicitly asked.
    if (previous === null) {
        state.set(repo, current);

        if (options.emitExisting) {
            for (const [number, pull] of current) {
                emit(
                    pull.draft ? 'DRAFT' : 'READY',
                    `${shortRepo(repo)}#${number}`,
                    pull.draft ? 'already open, draft' : 'already open, ready',
                    `${pull.title}  ${pull.url}`,
                );

                if (! pull.draft) {
                    emitGlyphs(`${shortRepo(repo)}#${number}`, pull);
                }
            }
        }

        return;
    }

    for (const [number, pull] of current) {
        const before = previous.get(number);
        const subject = `${shortRepo(repo)}#${number}`;
        const detail = `${pull.title}  ${pull.url}`;

        if (before === undefined) {
            emit(pull.draft ? 'DRAFT' : 'READY', subject, pull.draft ? 'opened draft' : 'opened ready', detail);

            if (! pull.draft) {
                emitGlyphs(subject, pull);
            }

            continue;
        }

        if (before.draft && ! pull.draft) {
            emit('READY', subject, 'draft -> ready', detail);
            emitGlyphs(subject, pull);
        } else if (! before.draft && pull.draft) {
            emit('DRAFT', subject, 'ready -> draft', detail);
        } else if (isPush(before, pull)) {
            // A push to an already-open, already-ready pull request. This is how a red hand-back
            // comes back: the author fixes and pushes, and without this nothing wakes the gate,
            // so re-entry depends on someone saying so. D-062 records both times that happened.
            emit('PUSH', subject, `pushed ${pull.sha.slice(0, 9)}`, detail);

            // A push re-enters the queue, so it is swept like every other enqueue. This is the
            // path that matters most in the gate's normal mode: the first poll seeds SILENTLY,
            // so a pull request already open when the watcher was armed never emits READY at
            // all, and a push is the only sweep it can ever get. isPush() is already false when
            // either side is a draft, so this needs no guard of its own.
            emitGlyphs(subject, pull);
        }
    }

    for (const [number, pull] of previous) {
        if (current.has(number)) {
            continue;
        }

        const disposition = await resolveClosedState(options.gh, repo, number);

        emit('GONE', `${shortRepo(repo)}#${number}`, disposition, `${pull.title}  ${pull.url}`);
    }

    state.set(repo, current);
}

/**
 * Poll every watched repository once.
 *
 * @return {Promise<void>}
 */
export async function pollAll() {
    for (const repo of prRepos) {
        await pollRepo(repo);
        await sweepComments(repo);
    }
}

/**
 * `N repo` / `N repos` — this watcher normally runs against one repository, and `1 repos` reads as
 * a bug in the watcher itself.
 *
 * @param  {number}  count
 * @return {string}
 */
function repoCount(count) {
    return `${count} repo${count === 1 ? '' : 's'}`;
}

/**
 * Compose the startup summary line.
 *
 * It names the seeded open-pull-request count per repository, because a watcher that seeded zero
 * and one that cannot see anything are otherwise indistinguishable until the silence is noticed.
 *
 * @param  {number}  interval
 * @return {{subject: string, transition: string, detail: string}}
 */
export function watchingEvent(interval) {
    const seedCounts = prRepos.map((repo) => `${shortRepo(repo)}:${state.get(repo)?.size ?? '?'}`);

    return {
        subject: `PRs on ${repoCount(prRepos.length)}`,
        transition: `every ${interval}s`,
        detail: `open PRs at start — ${seedCounts.join(', ')}`,
    };
}

/**
 * Parse argv, seed state, then poll until stopped.
 *
 * @param  {string[]}  argv
 * @param  {{exec?: callable}}  dependencies  Overrides the `gh` invoker, for tests.
 * @return {Promise<void>}
 */
export async function main(argv, dependencies = {}) {
    try {
        options = parseArgs(argv);
    } catch (error) {
        process.stderr.write(`${error.message}\n\n${HELP}`);
        process.exit(2);
    }

    execGh = dependencies.exec ?? execFileAsync;

    prRepos = options.prRepos;
    state = new Map(prRepos.map((repo) => [repo, null]));
    failureStreaks = new Map(prRepos.map((repo) => [repo, 0]));
    commentCursor = new Map(prRepos.map((repo) => [repo, null]));

    await pollAll();

    if (options.once) {
        return;
    }

    const watching = watchingEvent(options.interval);

    emit('WATCHING', watching.subject, watching.transition, watching.detail);

    while (! stopping) {
        await new Promise((resolve) => setTimeout(resolve, options.interval * 1000));

        await pollAll();
    }
}

// Run only as the entry script, so importing this file for the helpers exported above does not
// parse argv or start polling. process.argv[1] is the entry script; comparing its file URL to
// import.meta.url distinguishes the two cross-platform. argv[1] is absent under `node -e` and the
// REPL, where pathToFileURL would throw on undefined — so presence is checked first.
if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    // Registered here rather than inside main(), which the test calls repeatedly: process-wide
    // handlers belong to the process that owns the CLI, and one pair per main() call would
    // accumulate until Node warns about a listener leak.
    for (const signal of ['SIGINT', 'SIGTERM']) {
        process.on(signal, () => {
            stopping = true;
            process.exit(0);
        });
    }

    await main(process.argv.slice(2));
}
