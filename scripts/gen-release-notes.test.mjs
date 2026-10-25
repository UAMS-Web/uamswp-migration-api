// Tests for gen-release-notes.mjs (#309).
//
// Run: node --test scripts/gen-release-notes.test.mjs
//
// Every test injects `exec`, so none runs git or reaches GitHub. The one run
// against real history -- v0.1.0..v0.2.0, read from its output -- is recorded
// on the pull request rather than repeated here, because it needs the network.
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'

import {
    bucket,
    cleanTitle,
    closingIssue,
    fixAcronyms,
    isVersionOnlyPatch,
    main,
    makeGitHub,
    replaceAmpersands,
    parseArgs,
    pathsAreTooling,
    render,
    subjectPull,
} from './gen-release-notes.mjs'

const REPO = 'UAMS-Web/uamswp-migration-api'

/** A stream stand-in that keeps what is written. */
function sink() {
    const chunks = []

    return { write: (s) => chunks.push(s), text: () => chunks.join('') }
}

/** An exec that records every call and answers from a table keyed on argv. */
function fakeExec(answers = {}) {
    const calls = []

    const exec = (cmd, args) => {
        calls.push([cmd, ...args])

        const key = [cmd, ...args].join(' ')

        for (const [pattern, out] of Object.entries(answers)) {
            if (key.includes(pattern)) {
                return { ok: true, out }
            }
        }

        return { ok: false, out: '' }
    }

    return { exec, calls }
}

/** A github stand-in for render(), answering from plain objects. */
function fakeGitHub({ pulls = {}, paths = {}, types = {} } = {}) {
    return {
        pull: (n) => pulls[n] ?? null,
        paths: (n) => paths[n] ?? [],
        issueType: (n) => types[n] ?? null,
    }
}

const baseOpts = { repo: REPO, lead: null, breaking: null, breakingItems: [], exclude: [], securityItems: [], verification: null, footer: null }

describe('--repo', () => {
    it('is refused when absent, before git or gh is ever run', () => {
        const { exec, calls } = fakeExec()
        const out = sink()
        const err = sink()

        const code = main(['v0.1.0', 'v0.2.0', '--lead', 'x'], { exec, stdout: out, stderr: err })

        assert.equal(code, 2)
        assert.match(err.text(), /--repo is required/)
        assert.equal(out.text(), '', 'a refused run must write no notes, or a redirect captures a partial body')
        assert.deepEqual(calls, [], 'nothing may be read before the refusal')
    })

    it('is refused when it is not OWNER/NAME', () => {
        assert.match(parseArgs(['a', 'b', '--repo', 'uamswp-migration-api']).error, /OWNER\/NAME/)
    })

    // The control for the refusal above: the same arguments WITH the flag run.
    // Without it, a parser that refused everything would pass the test above.
    it('is accepted when present, and the run proceeds to git', () => {
        const { exec, calls } = fakeExec({ 'git log': '' })
        const code = main(['v0.1.0', 'v0.2.0', '--repo', REPO], { exec, stdout: sink(), stderr: sink() })

        assert.equal(code, 0)
        assert.deepEqual(calls[0].slice(0, 3), ['git', 'log', 'v0.1.0..v0.2.0'])
    })

    it('decides every link in the body, and no other repository appears', () => {
        const body = render(
            [['a'.repeat(40), 'Serve a thing (#12)'], ['b'.repeat(40), 'A direct commit']],
            { ...baseOpts, repo: 'Some/Other' },
            fakeGitHub({ pulls: { 12: { title: 'Serve a thing', body: '', labels: [] } } }),
        )

        const links = [...body.matchAll(/https:\/\/github\.com\/([^/]+\/[^/]+)\//g)].map((m) => m[1])

        assert.equal(links.length, 2)
        assert.deepEqual([...new Set(links)], ['Some/Other'])
    })
})

describe('arguments', () => {
    it('needs exactly two refs', () => {
        assert.match(parseArgs(['v0.1.0', '--repo', REPO]).error, /exactly two refs/)
    })

    it('refuses an unknown option rather than ignoring it', () => {
        assert.match(parseArgs(['a', 'b', '--repo', REPO, '--bogus', 'x']).error, /unknown option --bogus/)
    })

    it('takes pull-request numbers only for --exclude and --security-item', () => {
        assert.match(parseArgs(['a', 'b', '--repo', REPO, '--exclude', '#12']).error, /pull-request number/)
        assert.deepEqual(parseArgs(['a', 'b', '--repo', REPO, '--exclude', '12', '--exclude', '13']).opts.exclude, [12, 13])
    })

    it('treats "-" as the repository root', () => {
        const { exec, calls } = fakeExec({ 'git log': '' })

        main(['-', 'v0.1.0', '--repo', REPO], { exec, stdout: sink(), stderr: sink() })

        assert.deepEqual(calls[0].slice(0, 3), ['git', 'log', 'v0.1.0'])
    })
})

describe('routing', () => {
    it('routes a change confined to tooling or tests to maintenance', () => {
        assert.equal(bucket('Name the condition the credential remedy depends on', { paths: ['CLAUDE.md'] }), 'maintenance')
        assert.equal(bucket('Date the verification', { paths: ['tests/Unit/SpellJobZeroFileTest.php'] }), 'maintenance')
    })

    it('never treats a change touching what ships as tooling', () => {
        for (const path of ['includes/Auth.php', 'config/defaults.php', 'uamswp-migration-api.php']) {
            assert.equal(pathsAreTooling(['tests/Feature/AuthTest.php', path]), false, path)
        }
    })

    it('routes a shared-skill sync under .agents or .cursor to maintenance (#385)', () => {
        assert.equal(pathsAreTooling(['.agents/skills/rule-worktrees/SKILL.md', '.cursor/rules/worktrees.mdc', '.claude/rules/worktrees.md']), true)
        assert.equal(bucket('Sync shared skills', { paths: ['.agents/skills/tests/SKILL.md'] }), 'maintenance')
        assert.equal(bucket('Sync shared rules', { paths: ['.cursor/rules/x.mdc'] }), 'maintenance')
    })

    it('does not read an empty path list as tooling', () => {
        assert.equal(pathsAreTooling([]), false)
    })

    describe('the plugin header (#385)', () => {
        const bump = [
            '@@ -4,7 +4,7 @@',
            '  * Plugin Name:       UAMSWP Migration API',
            '- * Version:           0.5.1',
            '+ * Version:           0.6.0',
            '  * Requires at least: 6.4',
        ].join('\n')
        const header = (patch) => ({ filename: 'uamswp-migration-api.php', patch })

        it('routes a change confined to the Version: line to maintenance', () => {
            assert.equal(isVersionOnlyPatch(bump), true)
            assert.equal(bucket('Bump plugin version to 0.6.0', { paths: [header(bump)] }), 'maintenance')
            assert.equal(bucket('Serve a release', { paths: [header(bump), 'docs/DECISIONS.md'] }), 'maintenance')
        })

        it('still routes any other change to the header as product work', () => {
            const other = bump.replace('  * Requires at least: 6.4', '- * Requires PHP:      8.2\n+ * Requires PHP:      8.4')

            assert.equal(isVersionOnlyPatch(other), false)
            assert.equal(bucket('Serve a release', { paths: [header(other)] }), 'new')
            assert.equal(isVersionOnlyPatch('@@ -40,1 +40,1 @@\n-require __DIR__ . \'/a.php\';\n+require __DIR__ . \'/b.php\';'), false)
        })

        it('reads a header with no patch, or a bare path, as product work', () => {
            assert.equal(bucket('Serve a release', { paths: [header(undefined)] }), 'new')
            assert.equal(bucket('Serve a release', { paths: ['uamswp-migration-api.php'] }), 'new')
            assert.equal(isVersionOnlyPatch(''), false)
        })
    })

    it('files a repair as a fix even when its paths are prose', () => {
        assert.equal(bucket('Correct the cost figure', { paths: ['.claude/rules/x.md'] }), 'fix')
    })

    it('lets a label route with no path signal', () => {
        assert.equal(bucket('Serve a thing', { labels: ['documentation'], paths: ['includes/X.php'] }), 'maintenance')
    })

    it('routes a security label or a forced item to Security, ahead of everything', () => {
        assert.equal(bucket('Serve a thing', { labels: ['security'] }), 'sec')
        assert.equal(bucket('Fix a thing', { forcedSecurity: true, paths: ['README.md'] }), 'sec')
    })

    it('uses the closing issue type only after labels and paths have said nothing', () => {
        assert.equal(bucket('Serve a field', { issueType: 'Bug', paths: ['includes/X.php'] }), 'fix')
        assert.equal(bucket('Serve a field', { issueType: 'Bug', paths: ['scripts/x.mjs'] }), 'maintenance')
        assert.equal(bucket('Serve a field', { issueType: 'Task', paths: ['includes/X.php'] }), 'new')
    })

    it('still lists a change with no signal at all', () => {
        assert.equal(bucket('Serve a field'), 'new')
    })
})

describe('titles', () => {
    it('strips a Conventional-Commit prefix and a trailing pull-request reference', () => {
        assert.equal(cleanTitle('fix: serve a thing (#12)'), 'Serve a thing')
    })

    it('cases acronyms in prose but leaves a code span byte-identical', () => {
        assert.equal(fixAcronyms('Read acf through the api, not `acf_format_value_for_rest()`'),
            'Read ACF through the API, not `acf_format_value_for_rest()`')
        assert.equal(fixAcronyms('Pin `uams-web/wordpress-importer`'), 'Pin `uams-web/wordpress-importer`')
    })

    it('leaves a word that is part of a larger token byte-identical (#318)', () => {
        assert.equal(fixAcronyms('Prefix the mutate script with @php, so its children launch'),
            'Prefix the mutate script with @php, so its children launch')
        assert.equal(fixAcronyms('Run ci:local before merging'), 'Run ci:local before merging')
        assert.equal(fixAcronyms('Pin uams-web/wordpress-importer and php-stubs/wordpress-stubs'),
            'Pin uams-web/wordpress-importer and php-stubs/wordpress-stubs')
        assert.equal(fixAcronyms('Read config/api.php'), 'Read config/api.php')
    })

    it('still cases the same words in plain prose, including at the end of a sentence', () => {
        assert.equal(fixAcronyms('Run php against wordpress through the api'),
            'Run PHP against WordPress through the API')
        assert.equal(fixAcronyms('Serve it through the api.'), 'Serve it through the API.')
        assert.equal(fixAcronyms('The api, the cli and php: all cased'), 'The API, the CLI and PHP: all cased')
    })

    it('does not turn prose "rest" into REST', () => {
        assert.equal(fixAcronyms('Serve the rest of the fields'), 'Serve the rest of the fields')
    })

    it('replaces an ampersand, with an Oxford comma when it closes a list', () => {
        assert.equal(replaceAmpersands('Posts & terms'), 'Posts and terms')
        assert.equal(replaceAmpersands('Posts, terms & options'), 'Posts, terms, and options')
    })

    it('finds the pull request a squash subject names', () => {
        assert.equal(subjectPull('Serve a thing (#197)'), 197)
        assert.equal(subjectPull('Merge pull request #5 from UAMS-Web/x'), 5)
        assert.equal(subjectPull('A direct commit'), null)
    })

    it('reads every GitHub closing keyword, and not a bare reference', () => {
        for (const kw of ['Closes', 'closed', 'Fixes', 'fix', 'Resolves', 'resolved']) {
            assert.equal(closingIssue(`${kw} #42.`), 42, kw)
        }

        assert.equal(closingIssue('Follow-up to #42.'), null)
    })
})

describe('the files read', () => {
    it('carries each patch with its filename, and reads a malformed page as no signal', () => {
        const line = (o) => JSON.stringify(o)
        const ok = fakeExec({ 'pulls/9/files': `${line({ filename: 'a.php', patch: '+x' })}\n${line({ filename: 'b.png', patch: null })}\n` })

        assert.deepEqual(makeGitHub(REPO, ok.exec).paths(9), [{ filename: 'a.php', patch: '+x' }, { filename: 'b.png', patch: null }])

        // Every patch of a large sync overflows spawnSync's buffer; ask for the header's only.
        const jq = ok.calls[0].at(-1)

        assert.match(jq, /\.filename == "uamswp-migration-api\.php" then \.patch else null/)

        const bad = fakeExec({ 'pulls/9/files': `${line({ filename: '.claude/x.md', patch: '+x' })}\nnot json\n` })

        assert.deepEqual(makeGitHub(REPO, bad.exec).paths(9), [])
    })
})

describe('the body', () => {
    const log = [
        ['a'.repeat(40), 'Serve a field (#12)'],
        ['b'.repeat(40), 'Document the gate (#13)'],
        ['c'.repeat(40), 'Merge branch main into x'],
    ]
    const github = fakeGitHub({
        pulls: {
            12: { title: 'Serve a field', body: 'Closes #1.', labels: [] },
            13: { title: 'Document the gate', body: '', labels: [] },
        },
        paths: { 12: ['includes/X.php'], 13: ['.claude/rules/x.md'] },
        types: { 1: 'Feature' },
    })

    it('keeps the house shape v0.1.0 and v0.2.0 already have', () => {
        const body = render(log, { ...baseOpts, lead: 'A lead.', verification: 'Green on abc.' }, github)

        assert.equal(body, [
            'A lead.',
            '',
            'Marked pre-release. Nothing pins this repository: `wordpress-importer` reads it over HTTP rather than as a package.',
            '',
            "## What's new",
            `- Serve a field [#12](https://github.com/${REPO}/pull/12)`,
            '',
            '## Maintenance and tooling',
            `- Document the gate [#13](https://github.com/${REPO}/pull/13)`,
            '',
            '## Verification at this tag',
            'Green on abc.',
            '',
            '## Known gaps',
            'Carried in `docs/known-gaps.md` rather than repeated here.',
            '',
        ].join('\n'))
    })

    it('puts the breaking callout in its own paragraph and leaves an itemized pull request out of the buckets', () => {
        const body = render(log, {
            ...baseOpts,
            lead: 'A lead.',
            breaking: 'the importer must read `id` as a string.',
            breakingItems: [`Change \`id\` to a string [#12](https://github.com/${REPO}/pull/12)`],
        }, github)

        assert.match(body, /^A lead\.\n\n\*\*Breaking change\*\* — the importer must read `id` as a string\.\n\n/)
        assert.match(body, /## Breaking changes\n- Change `id` to a string/)
        assert.equal((body.match(/pull\/12\)/g) ?? []).length, 1, '#12 must appear once, in the breaking item only')
    })

    it('links a direct commit by its short sha', () => {
        const body = render([['d'.repeat(40), 'A direct commit']], baseOpts, fakeGitHub())

        assert.match(body, new RegExp(`- A direct commit \\[\`ddddddd\`\\]\\(https://github.com/${REPO}/commit/${'d'.repeat(40)}\\)`))
    })

    it('leaves a TODO rather than inventing a lead or a verification', () => {
        const body = render([], baseOpts, fakeGitHub())

        assert.match(body, /^TODO: one-sentence milestone lead\./)
        assert.match(body, /## Verification at this tag\nTODO:/)
    })
})
