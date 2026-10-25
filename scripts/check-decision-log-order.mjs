#!/usr/bin/env node
// Holds `docs/DECISIONS.md` in D-number order (#246).
//
// WHY THIS EXISTS AT ALL. The log is append-only at its tail, so two tickets
// resolving concurrently always collide there and neither branch is wrong.
// `.gitattributes` gives the file `merge=union`, which resolves that merge
// without a conflict -- and union appends ours-then-theirs, so it can land two
// entries in the wrong order. The driver removes the conflict; this catches what
// the driver gets wrong. Neither half is any use without the other.
//
// WHAT "ORDER" MEANS HERE, measured rather than ported. `uams-statamic`'s
// equivalent keys on `^## (\d{4}-\d{2}-\d{2})`, and this log has EIGHT `##`
// headings, none carrying a date -- they are topical ("Data contract",
// "Toolchain"). A check ported verbatim would have matched nothing and passed
// silently on the whole file, which is the failure this repository files against
// itself most often. The entries are `### D-NNN` instead, and every one of the 74
// on `main` matches that shape.

import { readFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

export const ENTRY = /^### D-(\d+)\b/

/**
 * Every decision entry in the log, in the order the file lists them.
 */
export function parseEntries(text) {
    const entries = []

    // Split on either ending: the ordering check was already tolerant of a CRLF
    // checkout because `trim()` ate the \r, and the blank-line check must not be
    // the half that turns a line-ending defect into 75 findings.
    const lines = text.split(/\r?\n/)

    lines.forEach((line, index) => {
        const match = ENTRY.exec(line)

        if (match) {
            entries.push({
                number: Number(match[1]),
                line: index + 1,
                heading: line.trim(),
                // `null` when there IS no preceding line, which only line 1 can say.
                precededByBlank: index === 0 ? null : lines[index - 1] === '',
            })
        }
    })

    return entries
}

/**
 * Every place the sequence does not advance: a lower number following a higher
 * one, or the SAME number twice.
 *
 * THE DUPLICATE CASE IS THE LIKELIER OF THE TWO and was missing from the first
 * version of this file. Both arise from `merge=union`, but by different routes:
 * union appends ours-then-theirs without ordering, so a branch that took the
 * higher number lands first -- and separately, two authors who each read the log
 * and take "the next number" both write D-075, which union then concatenates
 * into a log with two of them. The second needs no unusual timing at all; it is
 * what concurrent work normally produces.
 *
 * It was found by a negative control rather than by reasoning: inverting `<` to
 * `<=` reddened no test, which meant nothing distinguished the two -- the tests
 * had no duplicate fixture because the implementation had no duplicate case.
 *
 * Reported as PAIRS with a kind, rather than as a count: "2 problems" does not
 * say which entry to move, and the remedy differs -- a duplicate needs renumbering
 * while a mis-ordering needs moving.
 */
export function findDisorder(entries) {
    const disorder = []

    for (let i = 1; i < entries.length; i++) {
        const previous = entries[i - 1].number

        if (entries[i].number === previous) {
            disorder.push({ ...entries[i], previous, kind: 'duplicate' })
        } else if (entries[i].number < previous) {
            disorder.push({ ...entries[i], previous, kind: 'out-of-order' })
        }
    }

    return disorder
}

/**
 * Every entry whose heading sits directly on the line above it -- adjacent to
 * it, with no blank line between (#260).
 *
 * THIS IS THE OTHER THING `merge=union` GETS WRONG, and it is cosmetic where
 * the ordering is not. Union stitches ours-then-theirs at the boundary of the
 * conflicting hunk, and the blank line that separated the last body line of
 * one entry from the heading of the next sits exactly on that boundary, so it
 * is dropped: the next `### D-NNN` lands directly under a `- **Ref**` line.
 * Reproduced against real git in this file's tests, not only in a fixture.
 *
 * It is NOT a correctness gap for the ordering check: `ENTRY` matches per line
 * regardless of what precedes it, so a swallowed blank line hides nothing from
 * `findDisorder`. It is reported separately, with its own remedy, for the same
 * reason duplicate and out-of-order are: "3 problems" does not say what to do.
 *
 * An entry on line 1 has no preceding line and is not reported; nothing else
 * is exempt. The first entry of this repository's log is on line 13 with a
 * blank line above it, so "the first entry has none by construction" is not
 * true of this file and is deliberately not assumed.
 */
export function findAdjacent(entries) {
    return entries.filter((entry) => entry.precededByBlank === false)
}

export function defaultLogPath() {
    return join(dirname(dirname(fileURLToPath(import.meta.url))), 'docs', 'DECISIONS.md')
}

function main() {
    const path = process.argv[2] ?? defaultLogPath()
    const entries = parseEntries(readFileSync(path, 'utf8'))

    // A SWEEP OVER NOTHING REPORTS CLEAN, so zero entries is a broken check
    // rather than a tidy log. This is the arm that makes the clean result mean
    // something, and it is the one a reader is most tempted to leave out.
    if (entries.length === 0) {
        console.error(`check-decision-log-order: read ${path} and found NO \`### D-NNN\` entries.`)
        console.error('That is a check that cannot fail, not a log in order. Has the heading format changed?')
        process.exit(1)
    }

    const disorder = findDisorder(entries)
    const adjacent = findAdjacent(entries)

    if (disorder.length > 0) {
        const label = disorder.length === 1 ? '1 entry is' : `${disorder.length} entries are`

        console.error(`check-decision-log-order: ${label} out of sequence in ${path}\n`)

        for (const entry of disorder) {
            const relation = entry.kind === 'duplicate' ? 'REPEATS' : 'follows'

            console.error(`  line ${entry.line}: D-${String(entry.number).padStart(3, '0')} ${relation} D-${String(entry.previous).padStart(3, '0')}`)
            console.error(`    ${entry.heading}`)
        }

        console.error('\nA DUPLICATE needs renumbering and a mis-ordering needs moving, so the two')
        console.error('are named separately above. The number itself is the reference other documents')
        console.error('cite, so prefer moving an entry to renumbering one wherever both would do.')
    }

    if (adjacent.length > 0) {
        const label = adjacent.length === 1 ? '1 entry is' : `${adjacent.length} entries are`

        console.error(`${disorder.length > 0 ? '\n' : ''}check-decision-log-order: ${label} not preceded by a blank line in ${path}\n`)

        for (const entry of adjacent) {
            console.error(`  line ${entry.line}: D-${String(entry.number).padStart(3, '0')} follows line ${entry.line - 1} directly`)
            console.error(`    ${entry.heading}`)
        }

        console.error('\nInsert a blank line above each. This is what a `merge=union` boundary looks')
        console.error('like after the merge: the separator sat on the seam and was dropped. A missing')
        console.error('blank line mis-orders nothing, so fix the spacing and leave the numbers alone.')
    }

    if (disorder.length > 0 || adjacent.length > 0) {
        process.exit(1)
    }

    console.log(`check-decision-log-order: ${entries.length} entries, D-${String(entries[0].number).padStart(3, '0')} to D-${String(entries[entries.length - 1].number).padStart(3, '0')}, in order and separated.`)
}

if (import.meta.url === pathToFileURL(process.argv[1]).href) {
    main()
}
