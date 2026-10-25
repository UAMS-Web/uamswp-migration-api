// Unit tests for watch-github.mjs. Run: node --test scripts/watch-github.test.mjs
//
// Importing watch-github.mjs is side-effect-free: the entry-point guard at the foot of that file
// compares `import.meta.url` against `process.argv[1]`, so importing it here neither parses argv
// nor starts polling.
//
// The event matrix is driven through an injected `gh` invoker rather than a live repository. That
// is not only for speed — most of these assertions are about what must NOT be emitted, and a
// negative is not observable against a real repo without waiting for it to fail to happen.
import { describe, it, beforeEach } from 'node:test';
import assert from 'node:assert/strict';

import { createGlyphScanner, glyphDetail, isPush, main, parseArgs, pollAll, watchingEvent } from './watch-github.mjs';

/** Lines captured from the watcher's stdout during one test. */
let emitted = [];
let restoreLog = null;

/**
 * Capture `console.log` for the duration of a test.
 */
function captureLog() {
    emitted = [];
    const original = console.log;
    console.log = (line) => emitted.push(line);
    restoreLog = () => { console.log = original; };
}

/**
 * Build a fake `gh` invoker over a scripted sequence of open-pull-request payloads.
 *
 * Each call to the pulls collection endpoint shifts one payload off `pages`. A `pulls/{n}` lookup
 * answers from `dispositions`, which is how a GONE event's merged-vs-closed disposition is set.
 */
function fakeGh({ pulls = [], dispositions = {}, fail = false, comments = [], commentsFail = false, paths = [] } = {}) {
    const queue = [...pulls];
    const commentQueue = [...comments];

    return async (_gh, args) => {
        const path = args[1];

        paths.push(path);

        if (path.includes('issues/comments')) {
            if (commentsFail) {
                const error = new Error('gh: comment fetch failed');
                error.stderr = 'gh: comment fetch failed';
                throw error;
            }

            return { stdout: JSON.stringify(commentQueue.length ? commentQueue.shift() : []) };
        }

        if (fail) {
            const error = new Error('gh: authentication failed');
            error.stderr = 'gh: authentication failed\nsecond line should not be reported';
            throw error;
        }

        if (path.includes('/pulls/')) {
            const number = Number(path.split('/pulls/')[1]);

            return { stdout: `${dispositions[number] ?? 'closed'}\n` };
        }

        // A page walk asks repeatedly; once the script is exhausted, answer empty so the walk ends.
        const page = queue.length ? queue.shift() : [];

        return { stdout: JSON.stringify(page) };
    };
}

/** One open pull request, in the shape the REST endpoint returns. */
function pull({ number = 1, title = 'A change', draft = false, sha = 'a'.repeat(40), assignees = [], body = '' } = {}) {
    return {
        number,
        title,
        draft,
        html_url: `https://github.com/UAMS-Web/uamswp-migration-api/pull/${number}`,
        body,
        head: { sha },
        assignees: assignees.map((login) => ({ login })),
    };
}

/**
 * Seed the watcher, then run a second poll, against ONE stateful invoker.
 *
 * `main(['--once'])` seeds module state and returns, leaving `execGh` pointing at the fake; calling
 * the exported `pollAll()` afterwards runs a second poll through that same fake, which shifts its
 * next scripted payload. Calling `main()` twice instead would re-seed and report nothing, which is
 * the trap the first version of this file fell into: every transition test passed while asserting
 * an empty array.
 *
 * @param  {Array<object[]>}  polls  One payload per poll, in order.
 */
async function seedThenPoll(polls, { argv = [], dispositions = {} } = {}) {
    const exec = fakeGh({ pulls: polls, dispositions });

    await main(['--once', ...argv], { exec });

    const afterSeed = emitted.length;

    await pollAll();

    return emitted.slice(afterSeed);
}

beforeEach(() => {
    if (restoreLog) {
        restoreLog();
        restoreLog = null;
    }
});

describe('parseArgs', () => {
    it('defaults to this repository and a 60s interval', () => {
        const options = parseArgs([]);

        assert.deepEqual(options.repos, ['UAMS-Web/uamswp-migration-api']);
        assert.equal(options.interval, 60);
        assert.equal(options.emitExisting, false);
    });

    it('accepts both --flag value and --flag=value', () => {
        assert.equal(parseArgs(['--interval', '30']).interval, 30);
        assert.equal(parseArgs(['--interval=45']).interval, 45);
    });

    it('floors the interval rather than rejecting it', () => {
        // A watcher is armed by hand. A typo that polls slightly slower than asked is better than
        // one that hammers the REST quota this repo shares with every other UAMS-Web session.
        assert.equal(parseArgs(['--interval', '1']).interval, 10);
        assert.equal(parseArgs(['--interval', '-5']).interval, 10);
    });

    it('refuses an unknown flag rather than ignoring it', () => {
        assert.throws(() => parseArgs(['--assignees', 'josh']), /Unknown flag/);
    });

    it('refuses --assignee, retired rather than silently ignored', () => {
        // A flag that once filtered the watched set and now does nothing is worse than one that
        // was never recognized: it reads as still working. #177 removed the filter because it
        // hid unassigned and other-developer pull requests from the gate; a caller who typed
        // --assignee out of habit needs to be told, not quietly watched over unfiltered.
        assert.throws(() => parseArgs(['--assignee', 'joshdaugherty']), /Unknown flag/);
    });
});

describe('isPush', () => {
    it('is true only when a ready pull request head actually moved', () => {
        assert.equal(isPush({ draft: false, sha: 'aaa' }, { draft: false, sha: 'bbb' }), true);
        assert.equal(isPush({ draft: false, sha: 'aaa' }, { draft: false, sha: 'aaa' }), false);
    });

    it('is false when either side has no SHA', () => {
        // A projection recorded before the field existed carries undefined; a poll that could not
        // read one carries null. Treating either as "changed" announces a push for every open
        // pull request at once on the first poll after an upgrade.
        assert.equal(isPush({ draft: false }, { draft: false, sha: 'bbb' }), false);
        assert.equal(isPush({ draft: false, sha: 'aaa' }, { draft: false, sha: null }), false);
    });

    it('is false while the pull request is a draft on either side', () => {
        // A draft is never enqueued, so a push to one has nothing to re-enter.
        assert.equal(isPush({ draft: true, sha: 'aaa' }, { draft: false, sha: 'bbb' }), false);
        assert.equal(isPush({ draft: false, sha: 'aaa' }, { draft: true, sha: 'bbb' }), false);
    });
});

describe('seeding', () => {
    it('is silent on the first poll, so arming mid-stream replays no history', async () => {
        captureLog();

        await main(['--once'], { exec: fakeGh({ pulls: [[pull({ number: 7 })]] }) });

        assert.deepEqual(emitted, []);
    });

    it('announces the already-open set with --emit-existing', async () => {
        captureLog();

        await main(['--once', '--emit-existing'], {
            exec: fakeGh({ pulls: [[pull({ number: 7 }), pull({ number: 8, draft: true })]] }),
        });

        assert.equal(emitted.length, 2);
        assert.match(emitted[0], /^READY {2}uamswp-migration-api#7 {2}already open, ready/);
        assert.match(emitted[1], /^DRAFT {2}uamswp-migration-api#8 {2}already open, draft/);
    });
});

describe('the event matrix', () => {
    it('reports a pull request opened ready, and one opened draft', async () => {
        captureLog();

        const events = await seedThenPoll([
            [],
            [pull({ number: 3 }), pull({ number: 4, draft: true })],
        ]);

        assert.equal(events.length, 2);
        assert.match(events[0], /^READY {2}uamswp-migration-api#3 {2}opened ready {2}A change {2}https:/);
        assert.match(events[1], /^DRAFT {2}uamswp-migration-api#4 {2}opened draft/);
    });

    it('reports draft -> ready', async () => {
        captureLog();

        const events = await seedThenPoll([
            [pull({ number: 5, draft: true })],
            [pull({ number: 5, draft: false })],
        ]);

        assert.equal(events.length, 1);
        assert.match(events[0], /^READY {2}uamswp-migration-api#5 {2}draft -> ready/);
    });

    it('reports ready -> draft', async () => {
        captureLog();

        const events = await seedThenPoll([
            [pull({ number: 5, draft: false })],
            [pull({ number: 5, draft: true })],
        ]);

        assert.equal(events.length, 1);
        assert.match(events[0], /^DRAFT {2}uamswp-migration-api#5 {2}ready -> draft/);
    });

    it('reports a push to an open ready pull request, naming the new head', async () => {
        captureLog();

        const events = await seedThenPoll([
            [pull({ number: 6, sha: 'a'.repeat(40) })],
            [pull({ number: 6, sha: 'b'.repeat(40) })],
        ]);

        assert.equal(events.length, 1);
        assert.match(events[0], /^PUSH {2}uamswp-migration-api#6 {2}pushed bbbbbbbbb {2}/);
    });

    it('emits nothing when a ready pull request is unchanged', async () => {
        // The steady state, and the one that matters most: a watcher that re-announces an
        // unchanged pull request every minute re-enters the queue forever, which is the exact
        // behavior "do not return to it until its head moves" forbids.
        captureLog();

        const events = await seedThenPoll([
            [pull({ number: 6, sha: 'a'.repeat(40) })],
            [pull({ number: 6, sha: 'a'.repeat(40) })],
        ]);

        assert.deepEqual(events, []);
    });

    it('reports a push that coincides with a draft flip as the flip alone', async () => {
        // The flip is the larger fact and re-enters the queue on its own, so reporting both
        // would enqueue the same pull request twice off one poll.
        captureLog();

        const events = await seedThenPoll([
            [pull({ number: 7, draft: true, sha: 'a'.repeat(40) })],
            [pull({ number: 7, draft: false, sha: 'b'.repeat(40) })],
        ]);

        assert.equal(events.length, 1);
        assert.match(events[0], /draft -> ready/);
        assert.equal(events.some((line) => line.startsWith('PUSH')), false);
    });

    it('reports a merged pull request as GONE, with its disposition', async () => {
        captureLog();

        const events = await seedThenPoll(
            [[pull({ number: 8 })], []],
            { dispositions: { 8: 'merged' } },
        );

        assert.equal(events.length, 1);
        assert.match(events[0], /^GONE {2}uamswp-migration-api#8 {2}merged/);
    });

    it('queues an unassigned pull request, with no flags', async () => {
        // #177: the watcher no longer filters by assignee, so an unassigned pull request — the
        // ordinary state of one nobody has claimed yet — is queued exactly like any other.
        captureLog();

        const events = await seedThenPoll([
            [],
            [pull({ number: 9, assignees: [] })],
        ]);

        assert.equal(events.length, 1);
        assert.match(events[0], /^READY {2}uamswp-migration-api#9 {2}opened ready/);
    });

    it('queues a pull request assigned to another account identically to an unassigned one', async () => {
        // #177: assignment no longer routes the watcher's attention — one unfiltered gate watches
        // every open pull request in the repository regardless of who it is assigned to.
        captureLog();

        const events = await seedThenPoll([
            [],
            [pull({ number: 9, assignees: ['someone-else'] })],
        ]);

        assert.equal(events.length, 1);
        assert.match(events[0], /^READY {2}uamswp-migration-api#9 {2}opened ready/);
    });

    it('reports a merged or closed pull request as GONE regardless of who it was assigned to', async () => {
        // The reassignment case GONE used to be told apart from (UNASSIGNED) no longer exists:
        // an open pull request no longer appearing in the unfiltered fetch can only mean it left
        // the open set, so GONE is the only disposition left to report.
        captureLog();

        const events = await seedThenPoll(
            [[pull({ number: 9, assignees: ['someone-else'] })], []],
            { dispositions: { 9: 'closed' } },
        );

        assert.equal(events.length, 1);
        assert.match(events[0], /^GONE {2}uamswp-migration-api#9 {2}closed/);
    });

    it('never enqueues a draft: an opened draft is noted, and a draft push is silent', async () => {
        captureLog();

        const events = await seedThenPoll([
            [pull({ number: 10, draft: true, sha: 'a'.repeat(40) })],
            [pull({ number: 10, draft: true, sha: 'b'.repeat(40) })],
        ]);

        assert.deepEqual(events, [], 'a push to a draft has nothing to re-enter');
    });
});

describe('watchingEvent', () => {
    it('says "1 repo" rather than "1 repos", which reads as a bug in the watcher', async () => {
        captureLog();

        await main(['--once'], { exec: fakeGh({ pulls: [[pull()]] }) });

        const watching = watchingEvent(60);

        assert.match(watching.subject, /1 repo$/);
        assert.equal(watching.transition, 'every 60s');
        assert.match(watching.detail, /uamswp-migration-api:1/);
    });
});

describe('failure reporting', () => {
    it('alerts immediately when the very first poll fails, rather than waiting out the streak', async () => {
        // A repo that fails its first poll never seeded, so it is a setup error — auth, a typo, a
        // permission — not a blip, and waiting three polls to say so wastes the difference.
        captureLog();

        await main(['--once'], { exec: fakeGh({ fail: true }) });

        assert.equal(emitted.length, 1);
        assert.match(emitted[0], /^WATCH-ERROR {2}uamswp-migration-api {2}1 consecutive poll failures/);
    });

    it('reports only the first line of the failure, so one event stays one line', async () => {
        captureLog();

        await main(['--once'], { exec: fakeGh({ fail: true }) });

        assert.equal(emitted[0].includes('second line should not be reported'), false);
        assert.equal(emitted[0].split('\n').length, 1);
    });
});

describe('the comment sweep at poll time', () => {
    const ROBOT = String.fromCodePoint(0x1F916);

    /** One comment, in the shape the REST collection returns. */
    const comment = ({ number = 5, id = 900, body = '', kind = 'issues' } = {}) => ({
        id,
        body,
        html_url: `https://github.com/UAMS-Web/uamswp-migration-api/${kind}/${number}#issuecomment-${id}`,
    });

    it('seeds silently, so a comment older than the watcher is never reported', async () => {
        // D-071 decided against retrospective correction. A comment that predates the watcher
        // is not this sweep's business, and seeding is what keeps it out.
        captureLog();

        await main(['--once'], {
            exec: fakeGh({
                pulls: [[]],
                comments: [[comment({ body: `an old one ${ROBOT}` })]],
            }),
        });

        assert.equal(emitted.some((line) => line.startsWith('GLYPH')), false);
    });

    // THE RECORD-BOUNDARY CASE, on the comment surface. Every dirty comment measured on #221
    // carried its glyph below the first line; a first-line reader calls the corpus clean.
    it('reports a glyph BELOW the first line of a comment, naming the code points and the number', async () => {
        captureLog();

        const body = ['A first line that is perfectly ordinary.', '', `and then ${ROBOT}`].join('\n');
        const exec = fakeGh({
            pulls: [[], []],
            comments: [[comment({ number: 131, id: 5622721127, body })]],
        });

        await main(['--once'], { exec });
        const afterSeed = emitted.length;
        await pollAll();

        const glyph = emitted.slice(afterSeed).find((line) => line.startsWith('GLYPH'));

        assert.ok(glyph !== undefined, 'a glyph on line 3 of a comment must be reported');
        assert.match(glyph, /uamswp-migration-api#131/);
        assert.match(glyph, /line 3: U\+1F916/);
        assert.ok(! glyph.includes(ROBOT), 'the report must carry code points, never the character');
    });

    it('reports nothing for a clean comment', async () => {
        captureLog();

        const exec = fakeGh({
            pulls: [[], []],
            comments: [[comment({ body: 'ordinary prose, no pictographs' })]],
        });

        await main(['--once'], { exec });
        const afterSeed = emitted.length;
        await pollAll();

        assert.equal(emitted.slice(afterSeed).some((line) => line.startsWith('GLYPH')), false);
    });

    it('asks for one incremental page keyed on since, so the cost does not grow with the corpus', async () => {
        captureLog();

        const paths = [];
        const exec = fakeGh({ pulls: [[], []], comments: [[], []], paths });

        await main(['--once'], { exec });
        await pollAll();

        const sweeps = paths.filter((path) => path.includes('issues/comments'));

        assert.equal(sweeps.length, 1, `one sweep after the seed, got ${sweeps.length}`);
        assert.match(sweeps[0], /since=/);
        assert.match(sweeps[0], /per_page=100/);
    });

    it('leaves the cursor alone when the sweep fails, so the window is re-read rather than skipped', async () => {
        captureLog();

        const paths = [];
        const exec = fakeGh({ pulls: [[], [], []], comments: [[], [], []], commentsFail: true, paths });

        await main(['--once'], { exec });
        await pollAll();
        const firstSweep = paths.filter((path) => path.includes('issues/comments')).pop();
        await pollAll();
        const secondSweep = paths.filter((path) => path.includes('issues/comments')).pop();

        assert.equal(firstSweep, secondSweep, 'a failed sweep must re-read the same window');
    });

    it('does not disturb the pull-request events', async () => {
        captureLog();

        const exec = fakeGh({
            pulls: [[], [pull({ number: 60, sha: 'a'.repeat(40) })]],
            comments: [[comment({ body: `noise ${ROBOT}` })]],
        });

        await main(['--once'], { exec });
        const afterSeed = emitted.length;
        await pollAll();
        const events = emitted.slice(afterSeed);

        assert.ok(events.some((line) => line.startsWith('READY')), 'the opened-ready event must still fire');
        assert.ok(events.some((line) => line.startsWith('GLYPH')), 'and the comment sweep alongside it');
    });
});

describe('the queue-time glyph sweep', () => {
    // Built with String.fromCodePoint rather than written literally, for the reason the rule
    // gives about reporting scripts: a source file carrying the character crashes on exactly
    // the legacy-code-page consumer the ban exists to protect.
    const WARNING_SIGN = String.fromCodePoint(0x26A0);
    const ROBOT = String.fromCodePoint(0x1F916);

    it('reports a glyph as code points, never as the character', () => {
        const scan = createGlyphScanner();
        const detail = glyphDetail(scan, `a body ${WARNING_SIGN} here`);

        assert.match(detail, /U\+26A0/);
        assert.ok(! detail.includes(WARNING_SIGN), 'the detail must not echo the glyph itself');
    });

    it('returns null for a clean body', () => {
        const scan = createGlyphScanner();

        assert.equal(glyphDetail(scan, 'ordinary prose, no pictographs'), null);
    });

    it('returns null for an empty or missing body', () => {
        const scan = createGlyphScanner();

        assert.equal(glyphDetail(scan, ''), null);
        assert.equal(glyphDetail(scan, null), null);
    });

    // THE RECORD-BOUNDARY CASE. A body sweep here once reported ZERO against a true ninety-one,
    // because it read bodies with `read -r` and took the first line of each. A corpus control
    // proves the sweep reached the bodies and says nothing about whether it reads them whole.
    it('finds a glyph BELOW the first line, which is the failure that reported zero against ninety-one', () => {
        const scan = createGlyphScanner();
        const body = [
            'Closes #1.',
            '',
            'A perfectly ordinary first line, and several after it.',
            '',
            `Generated with something ${ROBOT}`,
        ].join('\n');

        const detail = glyphDetail(scan, body);

        assert.ok(detail !== null, 'a glyph on line 5 must be found');
        assert.match(detail, /line 5: U\+1F916/);
    });

    it('reports every line that carries one, not only the first', () => {
        const scan = createGlyphScanner();
        const detail = glyphDetail(scan, `${WARNING_SIGN} one\nclean\n${ROBOT} three`);

        assert.match(detail, /line 1: U\+26A0/);
        assert.match(detail, /line 3: U\+1F916/);
    });

    it('reads its expression from the rule, so a rule it cannot find is an error not a clean sweep', () => {
        assert.throws(() => createGlyphScanner('/nonexistent-root-for-this-test'), /ENOENT|cannot run/);
    });

    // THE ENQUEUE-COVERAGE CASE. The six tests above exercise the scanner and say nothing about
    // WHICH events reach it, which is how a PUSH went unswept while every one of them passed.
    it('sweeps a pull request opened ready', async () => {
        captureLog();

        const events = await seedThenPoll([
            [],
            [pull({ number: 33, body: `opened with ${ROBOT}` })],
        ]);

        assert.ok(events.some((line) => line.startsWith('READY')), 'the open must still report');

        const glyph = events.find((line) => line.startsWith('GLYPH'));

        assert.ok(glyph !== undefined, 'an opened-ready pull request must be swept');
        assert.match(glyph, /uamswp-migration-api#33/);
        assert.match(glyph, /U\+1F916/);
    });

    it('sweeps a pull request on draft -> ready', async () => {
        captureLog();

        const events = await seedThenPoll([
            [pull({ number: 34, draft: true, body: `held back ${ROBOT}` })],
            [pull({ number: 34, draft: false, body: `held back ${ROBOT}` })],
        ]);

        const glyph = events.find((line) => line.startsWith('GLYPH'));

        assert.ok(glyph !== undefined, 'a draft becoming ready enqueues, so it must be swept');
        assert.match(glyph, /uamswp-migration-api#34/);
    });

    it('sweeps the existing queue under --emit-existing, and not a draft in it', async () => {
        captureLog();

        await main([
            '--once', '--emit-existing',
        ], {
            exec: fakeGh({
                pulls: [[
                    pull({ number: 35, body: `already open ${ROBOT}` }),
                    pull({ number: 36, draft: true, body: `a draft ${ROBOT}` }),
                ]],
            }),
        });

        const glyphs = emitted.filter((line) => line.startsWith('GLYPH'));

        assert.equal(glyphs.length, 1, `exactly the ready one is swept, got ${glyphs.length}`);
        assert.match(glyphs[0], /uamswp-migration-api#35/);
    });

    it('sweeps a pull request that was already open when the watcher was armed', async () => {
        // The first poll seeds SILENTLY, so this pull request never emits READY and never gets
        // the opened-ready sweep. A push is the only sweep it can ever receive.
        captureLog();

        const dirty = ['Closes #1.', '', `Generated with something ${ROBOT}`].join('\n');

        const events = await seedThenPoll([
            [pull({ number: 30, sha: 'a'.repeat(40), body: dirty })],
            [pull({ number: 30, sha: 'b'.repeat(40), body: dirty })],
        ]);

        assert.ok(events.some((line) => line.startsWith('PUSH')), 'the push must still report');

        const glyph = events.find((line) => line.startsWith('GLYPH'));

        assert.ok(glyph !== undefined, 'a push re-enters the queue and must be swept');
        assert.match(glyph, /uamswp-migration-api#30/);
        assert.match(glyph, /line 3: U\+1F916/);
    });

    it('sweeps the body the push brings, not the one the watcher first saw', async () => {
        captureLog();

        const events = await seedThenPoll([
            [pull({ number: 31, sha: 'a'.repeat(40), body: 'clean at open' })],
            [pull({ number: 31, sha: 'b'.repeat(40), body: `line one\nedited to add ${ROBOT}` })],
        ]);

        const glyph = events.find((line) => line.startsWith('GLYPH'));

        assert.ok(glyph !== undefined, 'a body edited after opening must still be caught');
        assert.match(glyph, /line 2: U\+1F916/);
    });

    it('never sweeps a push to a draft, which does not enqueue', async () => {
        captureLog();

        const events = await seedThenPoll([
            [pull({ number: 32, draft: true, sha: 'a'.repeat(40), body: `draft ${ROBOT}` })],
            [pull({ number: 32, draft: true, sha: 'b'.repeat(40), body: `draft ${ROBOT}` })],
        ]);

        assert.equal(events.some((line) => line.startsWith('GLYPH')), false);
        assert.equal(events.some((line) => line.startsWith('PUSH')), false);
    });
});
