#!/usr/bin/env node
// Generate a GitHub Release body for UAMS-Web/uamswp-migration-api, per the
// `writing-release-notes` skill (.claude/skills/writing-release-notes/SKILL.md).
//
// Ported from UAMS-Web/wordpress-importer's gen_release_notes.py (#309), and
// adapted to this repository in three ways the skill explains: `--repo` is
// REQUIRED rather than defaulted, the routing roots are this repository's own,
// and the body keeps the pre-release note, the verification section and the
// known-gaps pointer that v0.1.0 and v0.2.0 already carried.
//
// It reads first-parent history for a ref range and resolves each change to a
// linked, bucketed bullet, taking each pull request's title, labels, touched
// paths and closing-issue type live from the GitHub REST API. It needs `git`,
// `gh` and Node, and nothing from npm.
//
//   node scripts/gen-release-notes.mjs v0.2.0 main \
//       --repo UAMS-Web/uamswp-migration-api \
//       --lead "One-sentence milestone theme." \
//       --verification "npm run ci:local green on <sha>, all nine jobs." \
//       > notes.md

import { spawnSync } from 'node:child_process'
import { pathToFileURL } from 'node:url'

export const USAGE = `usage: gen-release-notes.mjs <prev-ref> <new-ref> --repo OWNER/NAME [options]

  <prev-ref>             previous tag, or "-" for the repository root
  <new-ref>              the commit being released (a ref, a tag or a sha)
  --repo OWNER/NAME      REQUIRED. The repository every bullet links into.
  --lead TEXT            one-sentence milestone lead (else a TODO placeholder)
  --breaking TEXT        impact and required action for the "**Breaking change**" callout
  --breaking-item TEXT   a "## Breaking changes" bullet (repeatable)
  --exclude N            pull request to leave out of the auto-buckets (repeatable)
  --security-item N      pull request to force into "## Security" (repeatable)
  --verification TEXT    the chain run at the tagged commit, for "## Verification at this tag"
  --footer TEXT          trailing italic line, for a retroactive tag`

// ---- arguments ----------------------------------------------------------

/**
 * Parse argv into options, or return { error } naming what is wrong.
 *
 * `--repo` has no default, and that is the point of it. The generator this was
 * ported from defaults to its own repository, and run from a sibling without
 * the flag it links every bullet to that repository's pull requests: real
 * numbers, unrelated changes, no error, and output that looks right.
 */
export function parseArgs(argv) {
    const opts = {
        positional: [],
        repo: null,
        lead: null,
        breaking: null,
        breakingItems: [],
        exclude: [],
        securityItems: [],
        verification: null,
        footer: null,
    }

    const single = { '--repo': 'repo', '--lead': 'lead', '--breaking': 'breaking', '--verification': 'verification', '--footer': 'footer' }
    const repeat = { '--breaking-item': 'breakingItems' }
    const numbers = { '--exclude': 'exclude', '--security-item': 'securityItems' }

    for (let i = 0; i < argv.length; i++) {
        const arg = argv[i]

        if (arg === '--help' || arg === '-h') {
            return { help: true }
        }

        if (arg in single || arg in repeat || arg in numbers) {
            const value = argv[i + 1]

            if (value === undefined) {
                return { error: `${arg} needs a value` }
            }

            i++

            if (arg in single) {
                opts[single[arg]] = value
            } else if (arg in repeat) {
                opts[repeat[arg]].push(value)
            } else {
                if (!/^\d+$/.test(value)) {
                    return { error: `${arg} takes a pull-request number, got "${value}"` }
                }

                opts[numbers[arg]].push(Number(value))
            }

            continue
        }

        if (arg.startsWith('--')) {
            return { error: `unknown option ${arg}` }
        }

        opts.positional.push(arg)
    }

    if (opts.positional.length !== 2) {
        return { error: 'expected exactly two refs: <prev-ref> <new-ref>' }
    }

    if (!opts.repo) {
        return { error: '--repo is required, e.g. --repo UAMS-Web/uamswp-migration-api. There is no default: a wrong one links every bullet to another repository\'s pull requests without any error.' }
    }

    if (!/^[\w.-]+\/[\w.-]+$/.test(opts.repo)) {
        return { error: `--repo must be OWNER/NAME, got "${opts.repo}"` }
    }

    ;[opts.prev, opts.next] = opts.positional

    return { opts }
}

// ---- prose helpers ------------------------------------------------------

/** Replace '&' with 'and', with an Oxford comma when it closes a comma list. */
export function replaceAmpersands(s) {
    return s.replace(/\s*&\s*/g, (match, offset) => (s.slice(0, offset).includes(',') ? ', and ' : ' and '))
}

const SKIP_CI_RE = /\s*\[\s*(?:skip[\s-]*ci|ci[\s-]*skip|no[\s-]*ci)\s*\]/gi

/** Strip build-directive litter like '[skip ci]' and collapse whitespace. */
export function scrub(s) {
    return s.replace(SKIP_CI_RE, '').replace(/\s{2,}/g, ' ').trim()
}

const ACRONYMS = {
    // No `rest`: it would turn "the rest of" into "the REST of" in prose.
    wordpress: 'WordPress', acf: 'ACF', wxr: 'WXR', api: 'API', php: 'PHP', phpstan: 'PHPStan',
    ci: 'CI', cli: 'CLI', json: 'JSON', pcov: 'PCOV', graphql: 'GraphQL', statamic: 'Statamic',
    composer: 'Composer', rector: 'Rector', url: 'URL',
}
// A word joined to a larger token is part of something a reader types -- the
// Composer directive `@php`, the script `ci:local`, the package
// `uams-web/wordpress-importer` -- so it keeps its case, exactly as a code span
// does. Joined means preceded by `@ : / - .`, or followed by `-` or `/`, or by
// `:` or `.` with a word character straight after. A trailing `:` or `.` before
// a space is punctuation, so "the api." and "php: all" are still cased (#318).
const ACRONYM_RE = new RegExp(
    `(?<![@:/.\\-])\\b(${Object.keys(ACRONYMS).join('|')})\\b(?![-/]|[:.]\\w)`,
    'gi',
)

// A code span, backticks included, non-greedy so adjacent spans stay apart.
const CODE_SPAN_RE = /`[^`]*`/g

/**
 * Apply acronym casing to prose, leaving code spans byte-identical.
 *
 * A code span is an identifier a reader copies -- a path, a handle, a
 * package -- and changing its case corrupts it: the source measured
 * `uams-web/wordpress-importer` becoming `uams-web/WordPress-importer`.
 */
export function fixAcronyms(s) {
    const cased = (text) => text.replace(ACRONYM_RE, (m) => ACRONYMS[m.toLowerCase()] ?? m)
    let out = ''
    let last = 0

    for (const span of s.matchAll(CODE_SPAN_RE)) {
        out += cased(s.slice(last, span.index)) + span[0]
        last = span.index + span[0].length
    }

    return out + cased(s.slice(last))
}

/** Normalize a raw title into a house-style bullet title. */
export function cleanTitle(t) {
    let title = t.replace(/^(feat|fix|perf|chore|docs|build|ci|test|style|refactor)(\([^)]*\))?:\s*/, '')
    title = title.replace(/\s*\((?:merge (?:after|before) #\d+)\)/gi, '')
    title = title.replace(/\s*\(#\d+(?:\s*[,&–-]\s*#?\d+)*\)/g, '')
    title = fixAcronyms(title.trim())

    return title && /[a-z]/.test(title[0]) ? title[0].toUpperCase() + title.slice(1) : title
}

// ---- routing ------------------------------------------------------------

const SECURITY_WORDS = /\b(xss|ssrf|csrf|xxe|redos|egress|nonce|impersonate|impersonation|sanitize|sanitized|sanitizing|sanitization|anonymous|denylist)\b/i

// Labels that name a bucket outright. `development` names no section.
export const MAINTENANCE_LABELS = new Set(['build', 'documentation', 'local-ci', 'phpstan-cleanup', 'test-flake'])
export const SECURITY_LABELS = new Set(['security'])

// A change confined to these top-level paths is tooling, tests or prose, whatever
// its title says. What ships to WordPress is `includes/`, `config/` and the plugin
// header, and those are deliberately absent: a change touching any of them is
// product work. `tests/` IS here, unlike the importer this was ported from, because
// nothing under it ships -- a change confined to tests changes no response.
export const TOOLING_ROOTS = new Set([
    '.agents', '.claude', '.cursor', '.github', '.githooks', 'scripts', 'docs', 'tests',
    'AGENTS.md', 'CLAUDE.md', 'README.md', 'cspell.json', 'project-words.txt',
    'composer.json', 'composer.lock', 'package.json', 'package-lock.json',
    'phpstan.neon.dist', 'phpunit.xml', 'pint.json', 'rector.php', 'rector-sweep.php',
    '.gitattributes', '.gitignore', '.editorconfig',
])

// The plugin header ships, so it is not a tooling root. A change confined to its
// `Version:` line is a release bump, though, and serves no different response.
export const PLUGIN_HEADER = 'uamswp-migration-api.php'

const VERSION_LINE = /^\s*\*\s*Version:\s*\S+\s*$/

/**
 * True when a unified diff changes the `Version:` header line and nothing else.
 * A missing patch is false: GitHub omits it for a large file, and an unread
 * change is product work until shown otherwise. The files endpoint's patch
 * starts at the first `@@` hunk, so every `+` or `-` line is a changed line.
 */
export function isVersionOnlyPatch(patch) {
    if (typeof patch !== 'string') {
        return false
    }

    const changed = patch.split('\n').filter((line) => /^[+-]/.test(line))

    return changed.length > 0 && changed.every((line) => VERSION_LINE.test(line.slice(1)))
}

/**
 * True when every file touched sits under a tooling or prose root, or is the
 * plugin header with only its `Version:` line changed. An entry is a path, or a
 * `{ filename, patch }` pair as the pull request files endpoint returns it.
 */
export function pathsAreTooling(paths) {
    const files = paths
        .map((p) => (typeof p === 'string' ? { filename: p, patch: undefined } : p))
        .filter((f) => f && f.filename)

    return files.length > 0 && files.every((f) => TOOLING_ROOTS.has(f.filename.split('/')[0])
        || (f.filename === PLUGIN_HEADER && isVersionOnlyPatch(f.patch)))
}

/**
 * Route a change to one of: sec | maintenance | fix | new. First match wins.
 *
 * The order is the source's, and each step's reason is in the skill: a forced
 * security item, a security label, the security word list, a repair verb
 * (before paths, because a fix to a README is still a fix), a tooling label,
 * tooling-only paths, the closing issue's type, then title heuristics.
 */
export function bucket(title, { labels = [], paths = [], forcedSecurity = false, issueType = null } = {}) {
    const lowerLabels = new Set(labels.map((l) => String(l).toLowerCase()))
    const t = title.trim()
    const tl = t.toLowerCase()

    if (forcedSecurity) {
        return 'sec'
    }

    if ([...lowerLabels].some((l) => SECURITY_LABELS.has(l))) {
        return 'sec'
    }

    if (SECURITY_WORDS.test(t) || tl.includes('ssl verification') || tl.includes('auth gate') || tl.includes('unauthenticated')) {
        return 'sec'
    }

    if (/^(Fix|Resolve|Repair|Prevent|Guard|Restore|Correct|Harden|Stop|Avoid)\b/.test(t)) {
        return 'fix'
    }

    if ([...lowerLabels].some((l) => MAINTENANCE_LABELS.has(l))) {
        return 'maintenance'
    }

    if (pathsAreTooling(paths)) {
        return 'maintenance'
    }

    if (issueType === 'Bug') {
        return 'fix'
    }

    if (issueType === 'Feature') {
        return 'new'
    }

    if (/^(Migrate|Document|Adopt|Refactor|Refresh|Rework|Bump|Reformat|Consolidate|Deduplicate|Record|Port)\b/.test(t)) {
        return 'maintenance'
    }

    if (/\btests?\b|\bcspell\b|spell check|ci parity|local-ci|\bcoverage\b|mutation|\bmutants?\b|pcov|\bskill\b|worktree|\brule\b/.test(tl)) {
        return 'maintenance'
    }

    return 'new'
}

// Changes with no changelog value -- never rendered.
export const GLOBAL_SKIP = [/^Merge branch /i, /^Merge remote-tracking/i]

// GitHub's own closing keywords, which `writing-pull-requests` requires in a body.
const CLOSES_RE = /\b(?:close[sd]?|fix(?:e[sd])?|resolve[sd]?)\s+#(\d+)\b/i

/** The issue number a body closes, or null. */
export function closingIssue(body) {
    const m = CLOSES_RE.exec(body ?? '')

    return m ? Number(m[1]) : null
}

// ---- the GitHub and git reads ---------------------------------------------

function run(cmd, args) {
    const r = spawnSync(cmd, args, { encoding: 'utf8' })

    return { ok: r.status === 0, out: r.stdout ?? '' }
}

/**
 * Everything read from GitHub, over REST only. `gh pr view` is GraphQL-backed
 * and fails on an exhausted GraphQL budget; see `.claude/rules/github-api-budget.md`.
 * A failed read degrades to no signal rather than a wrong one.
 */
export function makeGitHub(repo, exec = run) {
    const cache = new Map()

    return {
        pull(num) {
            if (!cache.has(num)) {
                const r = exec('gh', ['api', `repos/${repo}/pulls/${num}`, '--jq', '{title, body, labels: [.labels[].name]}'])
                let pull = null

                if (r.ok) {
                    try {
                        pull = JSON.parse(r.out)
                    } catch {
                        pull = null
                    }
                }

                cache.set(num, pull)
            }

            return cache.get(num)
        },
        paths(num) {
            // --paginate: a pull request touching more than 30 files returns one page
            // otherwise, and a short list silently reads as "confined to tooling".
            // The plugin header's patch travels with its name so a Version-line-only
            // bump can be told apart from a real change. Only that file's: every
            // patch for a 184-file sync is over 2 MB, past spawnSync's 1 MB buffer,
            // and the failed read then reads as no signal at all.
            const jq = `.[] | {filename, patch: (if .filename == "${PLUGIN_HEADER}" then .patch else null end)} | tojson`
            const r = exec('gh', ['api', '--paginate', `repos/${repo}/pulls/${num}/files`, '--jq', jq])

            if (!r.ok) {
                return []
            }

            // All or nothing: a dropped line would shorten the list, and a short list
            // can read as "confined to tooling".
            try {
                return r.out.split('\n').filter((line) => line.trim()).map((line) => JSON.parse(line))
            } catch {
                return []
            }
        },
        issueType(num) {
            const r = exec('gh', ['api', `repos/${repo}/issues/${num}`, '--jq', '.type.name // ""'])

            return r.ok && r.out.trim() ? r.out.trim() : null
        },
    }
}

/** First-parent [sha, subject] pairs for a range, oldest last as git prints them. */
export function readLog(prev, next, exec = run) {
    const range = ['-', '', 'root'].includes(prev) ? next : `${prev}..${next}`
    const r = exec('git', ['log', range, '--first-parent', '--format=%H%x1f%s'])

    if (!r.ok) {
        return null
    }

    return r.out.split('\n').filter((line) => line.includes('\x1f')).map((line) => line.split('\x1f', 2))
}

// ---- rendering -----------------------------------------------------------

/** The pull request a subject names: `Merge pull request #N`, else the last `#N`. */
export function subjectPull(subject) {
    const merge = /^Merge pull request #(\d+)/.exec(subject)

    if (merge) {
        return Number(merge[1])
    }

    const nums = [...subject.matchAll(/#(\d+)/g)]

    return nums.length ? Number(nums.at(-1)[1]) : null
}

export const PRERELEASE_NOTE = 'Marked pre-release. Nothing pins this repository: `wordpress-importer` reads it over HTTP rather than as a package.'
export const KNOWN_GAPS_NOTE = 'Carried in `docs/known-gaps.md` rather than repeated here.'

/** Render the body from a log and the options. `github` is makeGitHub()'s shape. */
export function render(log, opts, github) {
    const excluded = new Set([
        ...opts.exclude,
        ...opts.breakingItems.flatMap((item) => [...item.matchAll(/#(\d+)/g)].map((m) => Number(m[1]))),
    ])
    const forced = new Set(opts.securityItems)
    const buckets = { new: [], fix: [], sec: [], maintenance: [] }

    for (const [sha, rawSubject] of log) {
        const subject = rawSubject.trim()

        if (!subject || GLOBAL_SKIP.some((re) => re.test(subject))) {
            continue
        }

        const num = subjectPull(subject)

        // Before the read, so an excluded pull request costs no API call.
        if (num && excluded.has(num)) {
            continue
        }

        const pull = num ? github.pull(num) : null

        let title
        let link
        let signals = {}

        if (pull && pull.title) {
            title = pull.title
            link = `[#${num}](https://github.com/${opts.repo}/pull/${num})`

            const issue = closingIssue(pull.body)

            signals = {
                labels: pull.labels ?? [],
                paths: github.paths(num),
                issueType: issue ? github.issueType(issue) : null,
                forcedSecurity: forced.has(num),
            }
        } else {
            title = subject.replace(/\s*\(#\d+[^)]*\)\s*$/, '').trim()
            link = `[\`${sha.slice(0, 7)}\`](https://github.com/${opts.repo}/commit/${sha})`
        }

        const display = scrub(cleanTitle(title))

        if (!display) {
            continue
        }

        const bullet = `- ${replaceAmpersands(display)} ${link}`
        const target = buckets[bucket(title, signals)]

        if (!target.includes(bullet)) {
            target.push(bullet)
        }
    }

    const parts = [opts.lead ? replaceAmpersands(opts.lead) : 'TODO: one-sentence milestone lead.']

    if (opts.breaking) {
        parts.push('', replaceAmpersands(`**Breaking change** — ${opts.breaking}`))
    }

    parts.push('', PRERELEASE_NOTE)

    const section = (heading, items) => {
        if (items.length) {
            parts.push('', `## ${heading}`, ...items)
        }
    }

    section('Breaking changes', opts.breakingItems.map((item) => `- ${replaceAmpersands(item)}`))
    section("What's new", buckets.new)
    section("What's fixed", buckets.fix)
    section('Security', buckets.sec)
    section('Maintenance and tooling', buckets.maintenance)

    parts.push('', '## Verification at this tag', opts.verification ?? 'TODO: the `npm run ci:local` run: the head it validated, the jobs, and the merge commit tagged.')
    parts.push('', '## Known gaps', KNOWN_GAPS_NOTE)

    if (opts.footer) {
        parts.push('', `_${opts.footer}_`)
    }

    return parts.join('\n').trimEnd() + '\n'
}

export function main(argv = process.argv.slice(2), { exec = run, stdout = process.stdout, stderr = process.stderr } = {}) {
    const parsed = parseArgs(argv)

    if (parsed.help) {
        stdout.write(USAGE + '\n')

        return 0
    }

    if (parsed.error) {
        stderr.write(`gen-release-notes: ${parsed.error}\n\n${USAGE}\n`)

        return 2
    }

    const { opts } = parsed
    const log = readLog(opts.prev, opts.next, exec)

    if (log === null) {
        stderr.write(`gen-release-notes: git log failed for ${opts.prev}..${opts.next}\n`)

        return 1
    }

    stdout.write(render(log, opts, makeGitHub(opts.repo, exec)))

    return 0
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    process.exitCode = main()
}
