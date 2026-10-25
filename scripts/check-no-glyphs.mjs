#!/usr/bin/env node
//
// check-no-glyphs.mjs — enforce .claude/rules/no-emoji-in-durable-records.md
// against the working tree, so a pull request that adds a glyph goes red
// instead of green.
//
// The rule bans emoji and pictographic glyphs from durable prose and ships a
// working audit. Until this job existed nothing ran it: cspell checks words,
// not pictographs, so its silence was the whole of the gate's opinion (#117).
//
// THE EXPRESSION IS READ OUT OF THE RULE, NEVER RESTATED HERE.
//
// The rule defines the character class once, as a shell variable in its own
// audit block. This script parses that assignment. A second expression living
// here would drift from the rule, and when the two disagree neither the reader
// nor the gate can tell which one is wrong — so there is only ever one.
// The same goes for the scope: the directories swept are read from the rule's
// own `find` line rather than hardcoded.
//
// THE CONTROL ARMS RUN ON EVERY INVOCATION, AND A FAILING ARM IS A HARD ERROR.
//
// An audit that cannot match and an audit over clean prose produce byte-
// identical empty output. A job reporting "no glyphs" without first proving it
// can find one is worse than no job, because it converts an untested claim into
// a green check. So every parsed range is probed with a character it must
// match, clean ASCII prose is probed against a match it must not produce, and
// either failing exits 2 without reporting on the tree at all.
//
// FINDINGS ARE CODE POINTS, NEVER THE CHARACTER.
//
// The rule requires this of any reporting script: echoing the glyph crashes on
// exactly the legacy-code-page consumer the ban exists to protect, and reports
// the crash as a short result.
//
// EXIT CODES
//     0  scope swept, controls held, no glyph found
//     1  at least one glyph found — findings printed as file:line: U+XXXX
//     2  the instrument is broken: rule unreadable, expression or scope not understood, or a
//        control arm failed
//
// USAGE
//     node scripts/check-no-glyphs.mjs [--root <dir>]

import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'

export const RULE_PATH = '.claude/rules/no-emoji-in-durable-records.md'

// Never swept, whatever the rule's scope line resolves to. vendor/ and
// node_modules/ are third-party and explicitly out of scope in the rule; .git
// is not prose at all.
const SKIP_DIRECTORIES = new Set(['.git', 'node_modules', 'vendor'])

// Clean prose the negative arm asserts produces no match. ASCII only: anything
// above it risks being inside a banned range and inverting the arm's meaning.
const NEGATIVE_CONTROL = 'plain ASCII prose, punctuated normally: no pictographs here.'

/**
 * Pull the character class out of the rule's own audit block.
 *
 * Exactly one assignment must exist. Zero means the rule was rewritten and this
 * job is now guessing; more than one means it is ambiguous which is the real
 * expression. Both are instrument failures rather than clean results.
 */
export function extractGlyphClass(ruleText) {
    const matches = [...ruleText.matchAll(/^\s*GLYPH='([^']*)'/gm)]

    if (matches.length !== 1) {
        throw new Error(
            `expected exactly one GLYPH='...' assignment in ${RULE_PATH}, found ${matches.length}. `
            + 'The job reads its expression from the rule; it cannot run without it.'
        )
    }

    return matches[0][1]
}

/**
 * Pull the swept paths out of the rule's own `find` line.
 *
 * Hardcoding them here would drift from the rule exactly as a second regular
 * expression would.
 */
export function extractScope(ruleText) {
    const matches = [...ruleText.matchAll(/^\s*find\s+(.+?)\s+-type\s+f\b/gm)]

    // Exactly one, on the same reasoning as extractGlyphClass above. Taking the
    // first of several would pick a scope silently, and a sweep narrower than
    // the rule declares reports clean over the files it stopped reading.
    if (matches.length !== 1) {
        throw new Error(
            `expected exactly one \`find ... -type f\` line in ${RULE_PATH}, found ${matches.length}. `
            + 'The job reads its scope from the rule; it cannot run without it.'
        )
    }

    return matches[0][1].split(/\s+/).filter(Boolean)
}

/**
 * Translate the rule's Perl character class into explicit code-point ranges.
 *
 * Parsed rather than string-substituted on purpose. A blind `\x{` -> `\u{`
 * rewrite would silently mistranslate anything else the class picked up, and a
 * mistranslated pattern still runs — producing an empty result that looks like
 * a clean tree.
 */
export function parseCodePointClass(source) {
    if (!source.startsWith('[') || !source.endsWith(']')) {
        throw new Error(`expected a bracketed character class, got: ${source}`)
    }

    const body = source.slice(1, -1)
    const token = /\\x\{([0-9A-Fa-f]{1,6})\}(?:-\\x\{([0-9A-Fa-f]{1,6})\})?/y
    const ranges = []

    let index = 0

    while (index < body.length) {
        token.lastIndex = index

        const match = token.exec(body)

        if (match === null) {
            throw new Error(
                `unsupported construct at offset ${index} of the rule's character class: ${body.slice(index)}. `
                + 'Only \\x{...} code points and \\x{...}-\\x{...} ranges are understood.'
            )
        }

        const lo = Number.parseInt(match[1], 16)
        const hi = match[2] === undefined ? lo : Number.parseInt(match[2], 16)

        if (hi < lo) {
            throw new Error(`inverted range in the rule's character class: U+${match[1]} to U+${match[2]}`)
        }

        ranges.push({ lo, hi })
        index = token.lastIndex
    }

    if (ranges.length === 0) {
        throw new Error("the rule's character class parsed to no code points at all")
    }

    return ranges
}

const escapeCodePoint = (codePoint) => `\\u{${codePoint.toString(16).toUpperCase()}}`

export function buildPattern(ranges) {
    const body = ranges
        .map(({ lo, hi }) => (lo === hi ? escapeCodePoint(lo) : `${escapeCodePoint(lo)}-${escapeCodePoint(hi)}`))
        .join('')

    return new RegExp(`[${body}]`, 'gu')
}

/**
 * Prove the pattern can find something before trusting that it did not.
 *
 * Both bounds of every range, because a translation error at one end is exactly
 * the kind that leaves the other end working and the result plausible.
 */
export function runControlArms(ranges, pattern) {
    const failures = []

    for (const { lo, hi } of ranges) {
        for (const codePoint of new Set([lo, hi])) {
            pattern.lastIndex = 0

            if (!pattern.test(String.fromCodePoint(codePoint))) {
                failures.push(`positive arm: U+${codePoint.toString(16).toUpperCase().padStart(4, '0')} is in the rule's class but the built pattern does not match it`)
            }
        }
    }

    pattern.lastIndex = 0

    if (pattern.test(NEGATIVE_CONTROL)) {
        failures.push('negative arm: the built pattern matches clean ASCII prose, so it matches everything')
    }

    return failures
}

/** Findings for one file's text, as code points with their line numbers. */
export function scanText(text, pattern) {
    const findings = []

    text.split(/\r?\n/).forEach((line, offset) => {
        pattern.lastIndex = 0

        const codePoints = [...line.matchAll(pattern)].map(
            ([character]) => `U+${character.codePointAt(0).toString(16).toUpperCase().padStart(4, '0')}`
        )

        if (codePoints.length > 0) {
            findings.push({ line: offset + 1, codePoints })
        }
    })

    return findings
}

function collectFiles(root, target, files) {
    const absolute = resolve(root, target)

    let stats

    try {
        stats = statSync(absolute)
    } catch {
        throw new Error(`the rule's scope names ${target}, which does not exist under ${root}`)
    }

    if (stats.isFile()) {
        files.push(absolute)

        return
    }

    for (const entry of readdirSync(absolute, { withFileTypes: true })) {
        if (entry.isDirectory() && SKIP_DIRECTORIES.has(entry.name)) {
            continue
        }

        collectFiles(root, relative(root, join(absolute, entry.name)), files)
    }
}

export function main(argv = process.argv.slice(2), cwd = process.cwd()) {
    const rootFlag = argv.indexOf('--root')
    const root = rootFlag === -1 ? cwd : resolve(cwd, argv[rootFlag + 1])

    let ranges
    let pattern
    let scope

    try {
        const ruleText = readFileSync(resolve(root, RULE_PATH), 'utf8')

        ranges = parseCodePointClass(extractGlyphClass(ruleText))
        pattern = buildPattern(ranges)
        scope = extractScope(ruleText)
    } catch (error) {
        console.error(`glyph audit cannot run: ${error.message}`)

        return 2
    }

    const controlFailures = runControlArms(ranges, pattern)

    if (controlFailures.length > 0) {
        console.error('glyph audit control arms FAILED — refusing to report on the tree:')
        controlFailures.forEach((failure) => console.error(`  ${failure}`))

        return 2
    }

    console.log(`Controls held: ${ranges.length} range(s) matched at both bounds, clean prose unmatched.`)
    console.log(`Scope, read from ${RULE_PATH}: ${scope.join(' ')}`)

    const files = []

    try {
        scope.forEach((target) => collectFiles(root, target, files))
    } catch (error) {
        console.error(`glyph audit cannot run: ${error.message}`)

        return 2
    }

    // A sweep over nothing reports clean. Say how many files were read so an
    // empty finding list is provably empty rather than merely silent.
    if (files.length === 0) {
        console.error(`glyph audit cannot run: the rule's scope (${scope.join(' ')}) matched no files under ${root}`)

        return 2
    }

    const findings = []
    const skipped = []

    for (const file of files) {
        const buffer = readFileSync(file)

        // Visible, never silent: a skipped file is announced, because a quiet
        // skip is how a real finding disappears.
        if (buffer.includes(0)) {
            skipped.push(relative(root, file))

            continue
        }

        scanText(buffer.toString('utf8'), pattern).forEach(({ line, codePoints }) => {
            findings.push(`${relative(root, file)}:${line}: ${codePoints.join(' ')}`)
        })
    }

    skipped.forEach((file) => console.log(`Skipped (contains a NUL byte, not prose): ${file}`))

    const checked = files.length - skipped.length

    // The same reason the empty-scope case above exits 2: a sweep that read
    // nothing reports clean, and clean is indistinguishable from correct.
    if (checked === 0) {
        console.error(`glyph audit cannot run: all ${files.length} file(s) in scope were skipped, so nothing was read`)

        return 2
    }

    console.log(`Files checked: ${checked}`)

    if (findings.length > 0) {
        console.error('')
        console.error('Glyphs found in durable records — see .claude/rules/no-emoji-in-durable-records.md:')
        findings.forEach((finding) => console.error(`  ${finding}`))
        console.error('')
        console.error('Replace each with the sentence it was abbreviating. Code points are printed')
        console.error('rather than the characters themselves, per the rule.')

        return 1
    }

    console.log('No glyphs found.')

    return 0
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
    process.exit(main())
}
