// Unit tests for spawn-helpers.mjs. Run: node --test scripts/spawn-helpers.test.mjs
//
// Importing spawn-helpers.mjs is side-effect-free — it declares three pure-ish functions and
// reads `process.platform` once, so this import never spawns anything.
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { mkdirSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { delimiter, join } from 'node:path';

import { needsShell, which, winQuote } from './spawn-helpers.mjs';

const isWindows = process.platform === 'win32';

describe('needsShell', () => {
    it('is true only for a Windows .cmd or .bat shim', () => {
        // The whole point is the extension, so assert against the platform rather than
        // hard-coding one answer — this file has to be correct on both.
        assert.equal(needsShell('C:\\bin\\composer.bat'), isWindows);
        assert.equal(needsShell('C:\\bin\\npm.CMD'), isWindows);
    });

    it('is false for a real executable on any platform', () => {
        assert.equal(needsShell('C:\\bin\\git.exe'), false);
        assert.equal(needsShell('/usr/bin/git'), false);
    });
});

describe('winQuote', () => {
    it('quotes the empty string, which would otherwise vanish from the command line', () => {
        assert.equal(winQuote(''), '""');
    });

    it('leaves a token with no space or quote untouched', () => {
        assert.equal(winQuote('--jobs'), '--jobs');
        assert.equal(winQuote('pint,phpstan'), 'pint,phpstan');
    });

    it('quotes a token containing a space', () => {
        assert.equal(winQuote('C:\\GitHub Repos\\x'), '"C:\\GitHub Repos\\x"');
    });

    it('escapes an embedded quote rather than truncating the argument', () => {
        // The predecessor in local-ci.mjs quoted only on whitespace, so this case passed
        // through unescaped and broke the re-parse.
        assert.equal(winQuote('a"b'), '"a\\"b"');
    });

    it('doubles trailing backslashes so the closing quote is not escaped away', () => {
        assert.equal(winQuote('C:\\a b\\'), '"C:\\a b\\\\"');
    });
});

describe('which', () => {
    it('returns null for a command that is not on PATH', () => {
        assert.equal(which('definitely-not-a-real-binary-xyzzy'), null);
    });

    it('finds a regular file on PATH and returns its absolute path', (t) => {
        const dir = mkdtempSync(join(tmpdir(), 'which-file-'));
        const name = isWindows ? 'probebin.EXE' : 'probebin';
        writeFileSync(join(dir, name), '');
        t.after(() => { process.env.PATH = original; });

        const original = process.env.PATH;
        process.env.PATH = dir;

        assert.equal(which('probebin'), join(dir, name));
    });

    it('ignores a DIRECTORY with the command name — the bug in the predecessor', (t) => {
        // local-ci.mjs's private which() tested candidates with existsSync, which is true for a
        // directory, so a directory named `php` on PATH resolved as the PHP binary.
        const dir = mkdtempSync(join(tmpdir(), 'which-dir-'));
        mkdirSync(join(dir, isWindows ? 'probedir.EXE' : 'probedir'));
        t.after(() => { process.env.PATH = original; });

        const original = process.env.PATH;
        process.env.PATH = dir;

        assert.equal(which('probedir'), null);
    });

    it('skips an unreadable or missing PATH entry rather than throwing', (t) => {
        const dir = mkdtempSync(join(tmpdir(), 'which-skip-'));
        const name = isWindows ? 'probelast.EXE' : 'probelast';
        writeFileSync(join(dir, name), '');
        t.after(() => { process.env.PATH = original; });

        const original = process.env.PATH;
        process.env.PATH = [join(dir, 'does-not-exist'), '', dir].join(delimiter);

        assert.equal(which('probelast'), join(dir, name));
    });
});
