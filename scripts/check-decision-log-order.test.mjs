// Tests for check-decision-log-order.mjs (#246).
//
// Run: node --test scripts/check-decision-log-order.test.mjs
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { mkdtempSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'
import { spawnSync } from 'node:child_process'

import { ENTRY, defaultLogPath, findDisorder, findAdjacent, parseEntries } from './check-decision-log-order.mjs'

const log = readFileSync(defaultLogPath(), 'utf8')

describe('check-decision-log-order', () => {
    it('is clean against the real log, and says how much it read', () => {
        const entries = parseEntries(log)

        // THE GATE ON THE CLEAN RESULT. A parse that matched nothing reports
        // "in order" just as loudly as a log that is genuinely in order, and
        // this repository has filed that failure against itself repeatedly.
        assert.ok(entries.length > 50, `read only ${entries.length} entries; a sweep over nothing reports clean`)

        assert.deepEqual(findDisorder(entries), [], 'the log is not in D-number order')
        assert.deepEqual(findAdjacent(entries), [], 'an entry heading sits directly under the line above it')
    })

    it('reports nothing for a log with no entries -- which the CLI treats as broken, not clean', () => {
        // The function itself cannot distinguish these; the CLI is where the
        // distinction is made, and this test pins WHY the CLI needs that branch.
        assert.deepEqual(parseEntries('# a log with no decisions\n'), [])
        assert.deepEqual(findDisorder([]), [])
    })

    describe('the shape this log actually uses, which is why the sibling check was not ported', () => {
        it('finds no dated `## ` heading, so a date-keyed check would match nothing here', () => {
            const dated = log.split('\n').filter((line) => /^## \d{4}-\d{2}-\d{2}/.test(line))
            const sections = log.split('\n').filter((line) => line.startsWith('## '))

            // Both arms: sections exist, and none of them is dated. Without the
            // first, "no dated headings" is equally true of an empty file.
            assert.ok(sections.length > 0, 'no `## ` headings at all -- the probe read nothing')
            assert.equal(dated.length, 0, 'a dated heading appeared; the ported check may now apply')
        })

        it('matches every entry on `### D-NNN`', () => {
            const headings = log.split('\n').filter((line) => line.startsWith('### '))

            assert.ok(headings.length > 50, `only ${headings.length} entry headings; the probe read nothing`)
            assert.equal(
                headings.filter((line) => ENTRY.test(line)).length,
                headings.length,
                'an entry heading did not match `### D-NNN`',
            )
        })
    })

    describe('it fails on disorder, which is the only thing that makes the clean result mean anything', () => {
        it('catches a lower number following a higher one', () => {
            const disorder = findDisorder(parseEntries('### D-004 — ours\n\n### D-003 — theirs\n'))

            assert.equal(disorder.length, 1)
            assert.equal(disorder[0].number, 3)
            assert.equal(disorder[0].previous, 4)
        })

        it('catches EXACTLY the shape `merge=union` produces', () => {
            // Union appends ours-then-theirs without ordering, so a branch that
            // took the higher number lands first. Reproduced against real git in
            // this pull request's verification; pinned here as a fixture so it
            // cannot regress unnoticed.
            const merged = [
                '### D-001 — first',
                '',
                '### D-002 — second',
                '',
                '### D-004 — ours',
                '',
                '### D-003 — theirs',
                '',
            ].join('\n')

            const disorder = findDisorder(parseEntries(merged))

            assert.equal(disorder.length, 1, 'the union-produced disorder was not caught')
            assert.equal(disorder[0].heading, '### D-003 — theirs')
        })

        it('catches the two entries this branch reordered, so the fix cannot silently revert', () => {
            const before = [
                '### D-065 — a',
                '### D-069 — b',
                '### D-068 — c',
                '### D-066 — d',
                '### D-067 — e',
            ].join('\n')

            assert.equal(findDisorder(parseEntries(before)).length, 2)
        })


        it('catches a DUPLICATE number, which is the likelier union outcome', () => {
            // Two authors each read the log, each take "the next number", and both
            // write D-075. Union concatenates rather than choosing, so the log ends
            // up with two. This needs no unusual timing -- it is what ordinary
            // concurrent work produces, and it was missing until a negative control
            // showed that inverting `<` to `<=` reddened nothing.
            const disorder = findDisorder(parseEntries('### D-074 — a\n\n### D-075 — ours\n\n### D-075 — theirs\n'))

            assert.equal(disorder.length, 1)
            assert.equal(disorder[0].kind, 'duplicate')
            assert.equal(disorder[0].heading, '### D-075 — theirs')
        })

        it('distinguishes the two kinds, because the remedies differ', () => {
            const both = findDisorder(parseEntries('### D-005 — a\n### D-005 — b\n### D-003 — c\n'))

            assert.deepEqual(both.map((entry) => entry.kind), ['duplicate', 'out-of-order'])
        })
        it('reports the line number, because a count does not say which entry to move', () => {
            const disorder = findDisorder(parseEntries('### D-002 — a\n\n\n### D-001 — b\n'))

            assert.equal(disorder[0].line, 4)
        })
    })

    describe('what it must NOT report', () => {
        it('accepts equal-then-greater and a gap, since numbers are not required to be contiguous', () => {
            assert.deepEqual(findDisorder(parseEntries('### D-001 — a\n### D-005 — b\n### D-074 — c\n')), [])
        })

        it('ignores a `D-` reference in prose, matching only a heading', () => {
            const text = '### D-010 — a\n\nSupersedes D-002, see D-001 and `### D-003` in a code span.\n\n### D-011 — b\n'

            assert.equal(parseEntries(text).length, 2)
            assert.deepEqual(findDisorder(parseEntries(text)), [])
        })

        it('ignores a deeper heading that merely starts with the same text', () => {
            assert.equal(parseEntries('#### D-001 — not an entry\n').length, 0)
        })
    })

    describe('a blank line before every heading, which is the OTHER thing union gets wrong (#260)', () => {
        it('reports a heading that follows a body line directly, with the line number', () => {
            const adjacent = findAdjacent(parseEntries('### D-001 — a\n\n- **Ref** — none.\n### D-002 — b\n'))

            assert.equal(adjacent.length, 1)
            assert.equal(adjacent[0].line, 4)
            assert.equal(adjacent[0].heading, '### D-002 — b')
        })

        it('reports a heading that follows another heading directly', () => {
            assert.equal(findAdjacent(parseEntries('### D-001 — a\n### D-002 — b\n')).length, 1)
        })

        it('does not report an entry on line 1, which has no line above it to be blank', () => {
            const entries = parseEntries('### D-001 — a\n\n### D-002 — b\n')

            assert.equal(entries[0].precededByBlank, null)
            assert.deepEqual(findAdjacent(entries), [])
        })

        it('does not report a blank line that carries only whitespace as missing -- it is not blank, and says so', () => {
            // A line of spaces is not a separator markdown recognizes as a
            // paragraph break in every renderer, and it is not what this log
            // writes. Pinned so the definition of "blank" is exact rather than
            // trimmed: `''`, nothing else.
            assert.equal(findAdjacent(parseEntries('### D-001 — a\n  \n### D-002 — b\n')).length, 1)
        })


        it('reads a CRLF checkout the same as an LF one, in both directions', () => {
            // `.gitattributes` forces LF on checkout, so this is a defect
            // tolerance rather than a supported shape -- but a check that reported
            // every entry over a line-ending problem would be read as noise, and
            // the ordering half already survived CRLF because `trim()` ate the CR.
            assert.deepEqual(findAdjacent(parseEntries('### D-001 — a\r\n\r\n### D-002 — b\r\n')), [])
            assert.equal(findAdjacent(parseEntries('### D-001 — a\r\n### D-002 — b\r\n')).length, 1)
        })

        it('leaves the ordering findings alone: a union fixture with its blank lines intact reports order only', () => {
            const merged = '### D-001 — first\n\n### D-002 — second\n\n### D-004 — ours\n\n### D-003 — theirs\n'
            const entries = parseEntries(merged)

            assert.equal(findDisorder(entries).length, 1)
            assert.deepEqual(findAdjacent(entries), [])
        })

        it('is what a REAL `merge=union` produces, so the fixture above is not the only evidence', () => {
            // Builds a throwaway repository carrying this repository's own
            // attribute line, appends a distinct entry on each of two branches
            // from one base, and merges. Both sides are clean before the merge
            // (the control); the merged file is not, and the finding lands on
            // the entry that was stitched in at the seam.
            const dir = mkdtempSync(join(tmpdir(), 'decision-log-union-'))

            // An EMPTY FILE, not the OS null device. `os.devNull` is `\\.\nul` on
            // Windows and Git for Windows refuses it as a config path -- `fatal:
            // unable to access '\\.\nul': Invalid argument` on the very first `git
            // init`, measured on the chain's Windows checkout when this test first
            // ran there. A real, empty file is a global config on every platform.
            // It lives inside `dir` so the `finally` below removes it too.
            const emptyConfig = join(dir, 'empty-gitconfig')

            writeFileSync(emptyConfig, '')

            const env = { ...process.env, GIT_CONFIG_NOSYSTEM: '1', GIT_CONFIG_GLOBAL: emptyConfig }
            const git = (...args) => {
                const run = spawnSync('git', [
                    '-c', 'user.name=probe', '-c', 'user.email=probe@example.invalid',
                    '-c', 'commit.gpgsign=false', '-c', 'core.autocrlf=false',
                    ...args,
                ], { cwd: dir, env, encoding: 'utf8' })

                assert.equal(run.status, 0, `git ${args.join(' ')} failed:\n${run.stderr}`)

                return run.stdout
            }
            const log = () => readFileSync(join(dir, 'docs', 'DECISIONS.md'), 'utf8')
            const append = (number, text) => writeFileSync(join(dir, 'docs', 'DECISIONS.md'), `${log()}\n### D-${number} — ${text}\n\n- **Ref** — ${text}.\n`)

            try {
                git('init', '-q')
                mkdirSync(join(dir, 'docs'))
                writeFileSync(join(dir, '.gitattributes'), 'docs/DECISIONS.md merge=union\n')
                writeFileSync(join(dir, 'docs', 'DECISIONS.md'), '# Log\n\n### D-001 — base\n\n- **Ref** — base.\n')
                git('add', '-A')
                git('commit', '-q', '-m', 'base')
                const base = git('rev-parse', 'HEAD').trim()

                git('checkout', '-q', '-b', 'first', base)
                append('002', 'first')
                git('commit', '-q', '-am', 'first')
                assert.deepEqual(findAdjacent(parseEntries(log())), [], 'the first branch is not clean BEFORE the merge; the probe is wrong')

                git('checkout', '-q', '-b', 'second', base)
                append('003', 'second')
                git('commit', '-q', '-am', 'second')
                assert.deepEqual(findAdjacent(parseEntries(log())), [], 'the second branch is not clean BEFORE the merge; the probe is wrong')

                git('merge', '-q', '--no-edit', 'first')

                const merged = log()

                assert.ok(!merged.includes('<<<<<<<'), 'union should have resolved this without markers')

                const adjacent = findAdjacent(parseEntries(merged))

                assert.equal(adjacent.length, 1, `expected exactly one entry stitched in without its blank line; the merged file was:\n${merged}`)
                assert.match(adjacent[0].heading, /^### D-00[23] /)
            } finally {
                rmSync(dir, { recursive: true, force: true })
            }
        })
    })
})
