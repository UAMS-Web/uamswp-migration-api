// Tests for check-path-portability.mjs (#245).
//
// The real-tree case below is not only a test. `node --test scripts/*.test.mjs`
// is the chain's `node-test` job, so asserting the working tree is clean HERE
// is what makes the check gate every chain invocation -- without touching
// either the job list in `local-ci.json` or its mirror in `ci.yml`, which
// `CiParityTest` holds together.

import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import { PROVENANCE, classify, findFindings, sweptFiles } from './check-path-portability.mjs';
import { parse } from 'acorn';

const HERE = dirname(fileURLToPath(import.meta.url));
const readModule = (specifier) => {
    try {
        return readFileSync(join(HERE, specifier.replace(/^\.\//, '')), 'utf8');
    } catch {
        return null;
    }
};

// A miniature module with runTarget()'s real shape: one branch returning its
// input VERBATIM and one building through node:path. The whole difficulty of
// this check lives in telling those two apart, so the fixture carries both.
const MODULE = `
import { join, dirname, resolve } from 'node:path';

export function runTarget({ preflight, repoRoot, primaryRoot, worktreePathOpt = null }) {
    if (preflight) {
        return { mode: 'preflight', runRoot: repoRoot, createsWorktree: false }
    }

    return {
        mode: 'gate',
        runRoot: worktreePathOpt ? resolve(worktreePathOpt) : join(dirname(primaryRoot), '.x-ci'),
        createsWorktree: true,
    }
}
`;

const PRELUDE = `import { basename, dirname, join } from 'node:path';
import { runTarget } from './fixture.mjs';
const repoRoot = '/repo/uamswp-migration-api';
const primaryRoot = '/repo/uamswp-migration-api';
const gate = runTarget({ preflight: false, repoRoot, primaryRoot });
const pre = runTarget({ preflight: true, repoRoot, primaryRoot });
`;

const fixtureReader = (specifier) => (specifier === './fixture.mjs' ? MODULE : null);
const scan = (code) => findFindings(PRELUDE + code, '<fixture>', fixtureReader);

describe('check-path-portability', () => {
    it('is clean against the working tree, and says how much it read', () => {
        // THE GATE. A clean result has to be distinguishable from a sweep that
        // read nothing, so the denominator is asserted rather than the silence.
        // The SAME value the check sweeps, not a second copy of it. A copy is
        // how the two drifted: the check's list omitted this very file and the
        // test's list omitted it too, so neither could report the other wrong.
        const files = sweptFiles();

        assert.ok(
            files.includes('scripts/check-path-portability.test.mjs'),
            'the check must sweep its OWN test file -- the omission #249 was filed for',
        );

        let findings = [];
        let assertions = 0;

        for (const file of files) {
            const result = findFindings(readFileSync(join(HERE, '..', file), 'utf8'), file, readModule);
            assert.equal(result.parseError, null, `${file} must parse`);
            findings = findings.concat(result.findings);
            assertions += result.assertionLines;
        }

        // The denominator is answerable to the directory now, not to a list
        // living beside it. #249: a count derived from the thing being checked
        // cannot corroborate it.
        assert.equal(files.length, readdirSync(join(HERE)).filter((f) => f.endsWith('.test.mjs')).length,
            'the swept list must match what is on disk');
        assert.ok(assertions > 200, `read only ${assertions} assertions; a sweep over nothing reports clean`);
        assert.deepEqual(findings, [], 'a provenance mismatch reached the tree');
    });

    describe('the two defects #243 was handed back for', () => {
        it('catches a POSIX literal against a branch-selected node:path value', () => {
            const { findings } = scan(`assert.equal(gate.runRoot, '/repo/plugins/.x-ci');`);

            assert.equal(findings.length, 1);
            assert.equal(findings[0].actual, PROVENANCE.NORMALIZED);
            assert.equal(findings[0].expected, PROVENANCE.POSIX);
        });

        it('catches the dirname pair, which has no literal on either side', () => {
            // The shape that got through TWICE. `primaryRoot` holds the literal
            // several hops away and `dirname()` on both sides makes the pair
            // look symmetric; the left is join()-normalized and the right is not.
            const { findings } = scan('assert.equal(dirname(gate.runRoot), dirname(primaryRoot));');

            assert.equal(findings.length, 1);
        });
    });

    describe('the inert shapes, which must produce nothing', () => {
        it('ignores a regular-expression delimiter', () => {
            assert.equal(scan('assert.throws(fn, /found 0 files/);').findings.length, 0);
        });

        it('ignores a path passed as an argument rather than compared', () => {
            assert.equal(scan(`assert.equal(needsShell('/usr/bin/git'), false);`).findings.length, 0);
        });

        it('ignores a derivation that never touches node:path', () => {
            assert.equal(scan(`assert.equal(primaryCheckout(porcelain), '/plugins/x');`).findings.length, 0);
        });

        it('ignores a branch that returns its input verbatim', () => {
            // runTarget({ preflight: true }) hands back `repoRoot` untouched, so
            // this compares a value against itself. Reporting it was the false
            // positive that sank the first version of this check.
            assert.equal(scan('assert.equal(pre.runRoot, repoRoot);').findings.length, 0);
        });

        it('ignores an inequality, which a mismatch makes MORE true', () => {
            assert.equal(scan('assert.notEqual(gate.runRoot, repoRoot);').findings.length, 0);
        });

        it('ignores a separator-free expectation', () => {
            assert.equal(scan(`assert.equal(basename(gate.runRoot), '.x-ci');`).findings.length, 0);
        });
    });

    it('does NOT flag the tautology, and that is a documented limitation', () => {
        // Rebuilding the expectation with the helpers the code uses silences
        // this check -- both sides become NORMALIZED and agree on any platform.
        // It also passes if the derivation breaks, because both sides move
        // together. So it is a way to go green without fixing anything, and the
        // check cannot distinguish it from a correct comparison.
        //
        // Pinned rather than hidden: the failure message names it, and a future
        // change that starts reporting it should update this test deliberately.
        assert.equal(scan('assert.equal(gate.runRoot, join(dirname(primaryRoot), ".x-ci"));').findings.length, 0);
    });

    describe('classification', () => {
        const expression = (code) => parse(code, { ecmaVersion: 'latest', sourceType: 'module' }).body[0].expression;

        // The binding map is part of the context now (#250): a name is a
        // node:path helper because it was IMPORTED as one, not because it is
        // spelled that way. Building it by hand here mirrors what
        // collectPathBindings() produces for a real file.
        const context = {
            bindings: new Map(),
            imported: new Map(),
            pathBound: new Map([
                ['join', 'join'], ['resolve', 'resolve'], ['dirname', 'dirname'],
                ['basename', 'basename'],
                ['posixPath', 'posix'], ['win32Path', 'win32'],
            ]),
        };

        it('reads a ternary as its arms when they agree, and UNKNOWN when they do not', () => {
            // runTarget()'s gate branch is a ternary. Without this case the
            // whole branch read UNKNOWN and BOTH defects above went unreported.
            assert.equal(classify(expression('a ? join(x, y) : resolve(z)'), context), PROVENANCE.NORMALIZED);
            assert.equal(classify(expression(`a ? join(x, y) : '/raw'`), context), PROVENANCE.UNKNOWN);
        });

        it('treats a literal with no separator as incapable of disagreeing', () => {
            assert.equal(classify(expression(`'plain'`), context), PROVENANCE.FREE);
            assert.equal(classify(expression(`'/has/separators'`), context), PROVENANCE.POSIX);
        });

        it('takes an explicit posix or win32 namespace at its word', () => {
            assert.equal(classify(expression('posixPath.join(a, b)'), context), PROVENANCE.POSIX);
            assert.equal(classify(expression('win32Path.join(a, b)'), context), PROVENANCE.NORMALIZED);
        });
    });

    it('reports EVERY occurrence rather than stopping at the first', () => {
        // A failing assertion aborts the rest of its block, so the gate sees one
        // defect per run and each fix reveals the next. #243 took three heads
        // that way. A check that stopped at the first would reproduce the loop.
        const { findings } = scan(
            `assert.equal(gate.runRoot, '/a/b');\n`
            + `assert.equal(dirname(gate.runRoot), dirname(primaryRoot));\n`
            + `assert.equal(gate.runRoot, '/c/d');`,
        );

        assert.equal(findings.length, 3);
        assert.deepEqual(findings.map((f) => f.line).sort((a, b) => a - b).length, 3);
    });

    describe('node:path is resolved by BINDING, not by name (#250)', () => {
        // Same comparison, same arguments, only the import line differs. The
        // third arm is the defect: an alias was classified by its local name,
        // so the assertion it guarded was never examined and the file read
        // clean. The first two are the controls -- without them a zero on the
        // third is equally consistent with a fixture that could never fire.
        const scanSource = (src) => findFindings(src, '<fixture>', () => null).findings.length;
        const assertion = `import assert from 'node:assert';\n`;

        it('catches an aliased import, which the raw-name form missed', () => {
            const aliased = `import { join as pathJoin } from 'node:path';\n${assertion}`
                + `assert.equal(pathJoin('a', 'b'), '/a/b');`;

            assert.equal(scanSource(aliased), 1);
        });

        it('keeps catching the destructured and namespaced forms', () => {
            const destructured = `import { join } from 'node:path';\n${assertion}`
                + `assert.equal(join('a', 'b'), '/a/b');`;
            const namespaced = `import * as path from 'node:path';\n${assertion}`
                + `assert.equal(path.join('a', 'b'), '/a/b');`;
            const defaulted = `import nodePath from 'node:path';\n${assertion}`
                + `assert.equal(nodePath.join('a', 'b'), '/a/b');`;

            assert.equal(scanSource(destructured), 1);
            assert.equal(scanSource(namespaced), 1);
            assert.equal(scanSource(defaulted), 1);
        });

        it('does NOT flag a local function that merely shares a name', () => {
            // The converse, and the direction that costs more. Name-matching
            // could not tell this from node:path's join; binding resolution
            // can. A check that cries wolf gets silenced rather than fixed.
            const collision = `${assertion}function join(a, b) { return a + '/' + b }\n`
                + `assert.equal(join('a', 'b'), '/a/b');`;

            assert.equal(scanSource(collision), 0);
        });

        it('takes an explicit posix or win32 sub-namespace at its word', () => {
            const win32 = `import * as path from 'node:path';\n${assertion}`
                + `assert.equal(path.win32.join('a', 'b'), '/a/b');`;
            const posix = `import * as path from 'node:path';\n${assertion}`
                + `assert.equal(path.posix.join('a', 'b'), '/a/b');`;

            assert.equal(scanSource(win32), 1);
            assert.equal(scanSource(posix), 0, 'posix.join is POSIX on every platform');
        });
    });

    describe('the swept list is derived from disk (#249)', () => {
        it('includes this file, which the hardcoded list omitted', () => {
            assert.ok(sweptFiles().includes('scripts/check-path-portability.test.mjs'));
        });

        it('matches what is on disk rather than a list kept beside it', () => {
            const onDisk = readdirSync(HERE).filter((f) => f.endsWith('.test.mjs')).sort();

            assert.deepEqual(sweptFiles(), onDisk.map((f) => `scripts/${f}`));
        });

        it('CATCHES a violation planted in this file, which used to go unswept', () => {
            // The whole point of #249, and it needs both sides: the unplanted
            // file must be clean, or the finding is not attributable to the
            // plant. The file already imports `join`, and reusing it rather
            // than adding a second import matters -- a duplicate import makes
            // the source fail to PARSE, and findFindings then returns zero
            // findings with a parseError set. A probe reading only the count
            // sees that as clean. That is the mistake this check exists to
            // stop, and it caught the author making it.
            const own = readFileSync(join(HERE, 'check-path-portability.test.mjs'), 'utf8');
            const planted = own.replace(
                "describe('check-path-portability', () => {",
                "describe('check-path-portability', () => {\n    it('p', () => { assert.equal(join('a', 'b'), '/a/b'); });",
            );

            assert.notEqual(planted, own, 'the plant must actually change the source');

            const before = findFindings(own, 'scripts/check-path-portability.test.mjs', readModule);
            const after = findFindings(planted, 'scripts/check-path-portability.test.mjs', readModule);

            assert.equal(before.parseError, null);
            assert.equal(after.parseError, null, 'a planted violation must leave the source PARSEABLE');
            assert.equal(before.findings.length, 0, 'the real file is clean, so the finding is attributable');
            assert.equal(after.findings.length, 1);
        });
    });

    it('reports a parse failure rather than reporting clean', () => {
        // A file it cannot read and a file with nothing in it produce the same
        // empty finding list, so the difference has to be carried explicitly.
        const { parseError, findings } = findFindings('const = ;', '<broken>', fixtureReader);

        assert.ok(parseError, 'a syntax error must surface');
        assert.deepEqual(findings, []);
    });
});
