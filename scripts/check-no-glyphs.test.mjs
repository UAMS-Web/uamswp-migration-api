// Tests for scripts/check-no-glyphs.mjs, the gate job enforcing
// .claude/rules/no-emoji-in-durable-records.md (#117).
//
// Imports resolve to node: builtins and one first-party relative module, so the
// node-test job needs neither npm ci nor composer install — see
// .claude/skills/local-ci/SKILL.md.
//
// No literal glyph appears in this file. Every one is built with
// String.fromCodePoint, which is also what the rule asks of a reporting script:
// a source file carrying the character crashes on the legacy-code-page consumer
// the ban exists to protect.

import assert from 'node:assert/strict'
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join, resolve } from 'node:path'
import test from 'node:test'
import { fileURLToPath } from 'node:url'

import {
    buildPattern,
    extractGlyphClass,
    extractScope,
    main,
    parseCodePointClass,
    RULE_PATH,
    runControlArms,
    scanText,
} from './check-no-glyphs.mjs'

const REPO_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..')

const WARNING_SIGN = String.fromCodePoint(0x26A0)
const VARIATION_SELECTOR = String.fromCodePoint(0xFE0F)

const RULE_FIXTURE = [
    '# Rule — no emoji in durable records',
    '',
    '```bash',
    "GLYPH='[\\x{1F000}-\\x{1FAFF}\\x{2600}-\\x{27BF}\\x{2B00}-\\x{2BFF}\\x{FE0F}]'",
    '',
    'find .claude docs README.md CLAUDE.md -type f -print0 |',
    '  xargs -0 perl -CSD -ne \'...\'',
    '```',
].join('\n')

/** A throwaway tree with a rule file and whatever else a case needs. */
function fixtureRoot(files = {}) {
    const root = mkdtempSync(join(tmpdir(), 'glyph-audit-'))

    const write = (relativePath, contents) => {
        const absolute = join(root, relativePath)

        mkdirSync(dirname(absolute), { recursive: true })
        writeFileSync(absolute, contents)
    }

    write(RULE_PATH, RULE_FIXTURE)
    write('README.md', 'plain readme\n')
    write('CLAUDE.md', 'plain claude\n')
    write('docs/DECISIONS.md', 'plain decisions\n')

    Object.entries(files).forEach(([relativePath, contents]) => write(relativePath, contents))

    return root
}

/** main() reports on stdout; keep the test output readable. */
function quietly(run) {
    const { log, error } = console

    console.log = () => {}
    console.error = () => {}

    try {
        return run()
    } finally {
        console.log = log
        console.error = error
    }
}

test('extractGlyphClass reads the single assignment out of the rule', () => {
    assert.equal(
        extractGlyphClass(RULE_FIXTURE),
        '[\\x{1F000}-\\x{1FAFF}\\x{2600}-\\x{27BF}\\x{2B00}-\\x{2BFF}\\x{FE0F}]'
    )
})

test('extractGlyphClass refuses when the rule carries no assignment', () => {
    assert.throws(() => extractGlyphClass('# a rule with no audit block\n'), /found 0/)
})

test('extractGlyphClass refuses when the rule carries two, rather than picking one', () => {
    const ambiguous = `${RULE_FIXTURE}\nGLYPH='[\\x{2600}]'\n`

    assert.throws(() => extractGlyphClass(ambiguous), /found 2/)
})

test('extractScope reads the swept paths off the rule\'s find line', () => {
    assert.deepEqual(extractScope(RULE_FIXTURE), ['.claude', 'docs', 'README.md', 'CLAUDE.md'])
})

test('extractScope refuses when the rule has no find line', () => {
    assert.throws(() => extractScope("GLYPH='[\\x{2600}]'\n"), /found 0/)
})

test('extractScope refuses two find lines rather than silently taking the first', () => {
    // Taking the first would narrow the sweep without saying so, and a sweep
    // that quietly stopped reading files still reports clean.
    const ambiguous = `${RULE_FIXTURE}\nfind docs -type f -print0\n`

    assert.throws(() => extractScope(ambiguous), /found 2/)
})

test('parseCodePointClass turns the rule\'s class into explicit ranges', () => {
    assert.deepEqual(parseCodePointClass(extractGlyphClass(RULE_FIXTURE)), [
        { lo: 0x1F000, hi: 0x1FAFF },
        { lo: 0x2600, hi: 0x27BF },
        { lo: 0x2B00, hi: 0x2BFF },
        { lo: 0xFE0F, hi: 0xFE0F },
    ])
})

test('parseCodePointClass refuses a construct it cannot translate, rather than dropping it', () => {
    // A blind \x{ -> \u{ substitution would accept this and silently lose \w,
    // producing a pattern that still runs and under-reports.
    assert.throws(() => parseCodePointClass('[\\x{2600}\\w]'), /unsupported construct/)
})

test('parseCodePointClass refuses an inverted range', () => {
    assert.throws(() => parseCodePointClass('[\\x{27BF}-\\x{2600}]'), /inverted range/)
})

test('parseCodePointClass refuses anything that is not a bracketed class', () => {
    assert.throws(() => parseCodePointClass('\\x{2600}'), /bracketed character class/)
})

test('the control arms hold for the rule\'s own expression', () => {
    const ranges = parseCodePointClass(extractGlyphClass(RULE_FIXTURE))

    assert.deepEqual(runControlArms(ranges, buildPattern(ranges)), [])
})

test('the positive arm catches a pattern that cannot match what it claims to', () => {
    const ranges = parseCodePointClass(extractGlyphClass(RULE_FIXTURE))
    // The declared class, against a pattern that only covers one of its ranges:
    // exactly the shape of a translation that works at one end.
    const failures = runControlArms(ranges, /[\u{2600}-\u{27BF}]/gu)

    assert.ok(failures.length > 0)
    assert.match(failures[0], /^positive arm: U\+1F000/)
})

test('the negative arm catches a pattern that matches everything', () => {
    const failures = runControlArms([{ lo: 0x2600, hi: 0x2600 }], /[\s\S]/gu)

    assert.deepEqual(failures, ['negative arm: the built pattern matches clean ASCII prose, so it matches everything'])
})

test('scanText reports code points with one-based line numbers', () => {
    const ranges = parseCodePointClass(extractGlyphClass(RULE_FIXTURE))
    const text = `first line\nwarning ${WARNING_SIGN}${VARIATION_SELECTOR} here\nthird line\n`

    assert.deepEqual(scanText(text, buildPattern(ranges)), [
        { line: 2, codePoints: ['U+26A0', 'U+FE0F'] },
    ])
})

test('scanText finds nothing in clean prose', () => {
    const ranges = parseCodePointClass(extractGlyphClass(RULE_FIXTURE))

    assert.deepEqual(scanText('ordinary prose, no pictographs\n', buildPattern(ranges)), [])
})

test('main passes on a tree with no glyph', () => {
    const root = fixtureRoot()

    try {
        assert.equal(quietly(() => main(['--root', root], process.cwd())), 0)
    } finally {
        rmSync(root, { recursive: true, force: true })
    }
})

test('main fails on a glyph planted anywhere in the declared scope', () => {
    const root = fixtureRoot({
        'docs/DECISIONS.md': `D-001 — a decision ${WARNING_SIGN} with a glyph\n`,
    })

    try {
        assert.equal(quietly(() => main(['--root', root], process.cwd())), 1)
    } finally {
        rmSync(root, { recursive: true, force: true })
    }
})

test('main fails on a glyph in a nested .claude file, not only at the top level', () => {
    const root = fixtureRoot({
        '.claude/skills/writing-commits/SKILL.md': `a skill ${WARNING_SIGN}\n`,
    })

    try {
        assert.equal(quietly(() => main(['--root', root], process.cwd())), 1)
    } finally {
        rmSync(root, { recursive: true, force: true })
    }
})

test('main refuses when every file in scope was skipped, rather than reporting clean', () => {
    // The skip is a safety valve for a binary landing in docs/. If it ever eats
    // the whole scope the job has read nothing, and "no glyphs found" over
    // nothing is exactly the empty result the rule this job enforces distrusts.
    const nul = String.fromCharCode(0)
    const root = fixtureRoot({
        'README.md': `readme${nul}\n`,
        'CLAUDE.md': `claude${nul}\n`,
        'docs/DECISIONS.md': `decisions${nul}\n`,
        // Still parseable as the rule; only the SWEEP skips it.
        [RULE_PATH]: `${RULE_FIXTURE}\n${nul}\n`,
    })

    try {
        assert.equal(quietly(() => main(['--root', root], process.cwd())), 2)
    } finally {
        rmSync(root, { recursive: true, force: true })
    }
})

test('main reports the instrument broken, not the tree clean, when the rule is unreadable', () => {
    const root = mkdtempSync(join(tmpdir(), 'glyph-audit-missing-rule-'))

    try {
        assert.equal(quietly(() => main(['--root', root], process.cwd())), 2)
    } finally {
        rmSync(root, { recursive: true, force: true })
    }
})

test('main reports the instrument broken when the scope names something absent', () => {
    const root = mkdtempSync(join(tmpdir(), 'glyph-audit-missing-scope-'))

    mkdirSync(join(root, dirname(RULE_PATH)), { recursive: true })
    writeFileSync(join(root, RULE_PATH), RULE_FIXTURE)

    try {
        // The rule's scope names docs, README.md and CLAUDE.md; none exist here.
        assert.equal(quietly(() => main(['--root', root], process.cwd())), 2)
    } finally {
        rmSync(root, { recursive: true, force: true })
    }
})

test('the repository\'s own tree is clean, which is what keeps the gate honest on main', () => {
    assert.equal(quietly(() => main(['--root', REPO_ROOT], process.cwd())), 0)
})
