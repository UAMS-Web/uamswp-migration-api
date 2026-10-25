#!/usr/bin/env node
// `npm run ci:local`: the shared local CI runner, with this repository's slot (#366).
//
// The shared runner (`.claude/skills/local-ci/local-ci.mjs`) does the work; this
// only places its `--branch` slot. Left to itself the runner puts the slot at
// `<primary>-ci`, a plain sibling of the primary checkout. Here the primary sits
// in `wp-content/plugins/`, so that sibling would be a second copy of this
// plugin, at another commit, on the development site's plugins screen. WordPress
// skips a directory whose name starts with a dot, so the slot is dotted.
//
// The slot sits beside the PRIMARY checkout, as the runner's own default does,
// so a run started from slot A, slot B or an ephemeral worktree under
// `.claude/worktrees/` resolves to the same one.
//
// Also drops `--fetch`, which the private runner this replaces took and the
// shared one does not: `--branch` always validates the remote head, so the flag
// has nothing left to do, and refusing it would break every saved invocation.
//
// Usage: npm run ci:local -- [shared runner options]
import { spawnSync } from 'node:child_process'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

import { primaryRootOf } from '../.claude/skills/local-ci/local-ci.mjs'

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..')

/** The slot every checkout of this repository on one machine shares. */
export const SLOT_NAME = '.uamswp-migration-api-local-ci'

/** The shared runner's arguments for this invocation's, with the slot beside `primaryRoot`. */
export function runnerArgs(args, primaryRoot) {
    const out = args.filter((arg) => arg !== '--fetch')

    if (out.includes('--branch') && !out.includes('--worktree-path')) {
        out.push('--worktree-path', join(primaryRoot, '..', SLOT_NAME))
    }

    return out
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    const runner = join(root, '.claude/skills/local-ci/local-ci.mjs')
    const r = spawnSync(process.execPath, [runner, ...runnerArgs(process.argv.slice(2), primaryRootOf(root))], { stdio: 'inherit' })

    // A runner killed by a signal has no status; report it the way a shell
    // would rather than as a pass.
    process.exitCode = r.status ?? (r.signal ? 128 + ({ SIGHUP: 1, SIGINT: 2, SIGTERM: 15 }[r.signal] ?? 0) : 1)
}
