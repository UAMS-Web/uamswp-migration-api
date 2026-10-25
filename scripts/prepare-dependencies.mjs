#!/usr/bin/env node
// The `dependencies` job in local-ci.json: keep vendor/ and node_modules/ in line
// with the commit being validated (#366).
//
// The shared runner leaves installing to the repository. Its slot is reused from
// run to run, so without this a pull request that moves composer.lock is
// validated against the dependencies of whatever the slot held last, and passes
// against the wrong versions. That is the stale-tree failure `.claude/rules/worktrees.md`
// calls worse than a missing install, because nothing about it is red.
//
// Where it runs decides what it does:
//
//   - In a slot the shared runner created (its `local-ci-slot` marker is in the
//     slot's git directory), it installs: `composer install` at the root, then
//     `composer install` in tests/Integration, the Integration suite's own
//     setup (#375), then brings that setup's vendor/ patches in line with its
//     patches.lock.json, then `npm ci`. That tree is the runner's own and is
//     never hand-edited, which is what the private runner this replaces did on
//     every run.
//
// The patch step is the runner's own (`syncPatchedVendor`), run here rather
// than by its `prepare.patches`, because `prepare` runs before any job: it
// would `composer reinstall` the slot's OLD version of a package against the
// commit's NEW patch, and a branch that moves both together would then fail
// before anything ran. Installing first and re-syncing second is the order
// the private runner used. The patch record is dropped before either install
// (#412): an install that dies after re-extracting a patched package would
// otherwise leave a record vouching for a vendor/ that is no longer patched,
// and the next run would skip the reinstall that puts the patch back.
//   - Anywhere else (a developer's checkout, a work slot), it installs NOTHING.
//     Installing into someone's working tree is their decision, so it reports
//     what is out of date, names the command, and fails.
//
// Usage: node scripts/prepare-dependencies.mjs
import { spawnSync } from 'node:child_process'
import { existsSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

import { dropPatchRecord, installedPackages, PATCH_LOCK_RECORD, SLOT_MARKER, syncPatchedVendor } from '../.claude/skills/local-ci/local-ci.mjs'
import { needsShell, which, winQuote } from './spawn-helpers.mjs'

// The marker the shared runner writes into a slot's git directory, taken from
// the runner itself so the two cannot name different files.
export { SLOT_MARKER }

/** Read a text file, or null when it is absent. */
function readText(path) {
    return existsSync(path) ? readFileSync(path, 'utf8') : null
}

/**
 * Reinstall the packages whose patches differ from the ones vendor/ was last
 * brought in line with, then record the lock. Returns the exit status.
 */
export function syncPatches(root, reinstall = (packages) => run('composer', ['reinstall', ...packages, '--no-interaction', '--no-progress'], root)) {
    const installedJson = readText(join(root, 'vendor', 'composer', 'installed.json'))

    if (installedJson === null) {
        console.error('vendor/composer/installed.json is missing after composer install.')

        return 1
    }

    try {
        const packages = syncPatchedVendor({
            currentLock: readText(join(root, 'patches.lock.json')),
            recordedLock: readText(join(root, PATCH_LOCK_RECORD)),
            installed: installedPackages(installedJson),
            reinstall,
            writeRecord: (text) => writeFileSync(join(root, PATCH_LOCK_RECORD), text),
            removeRecord: () => rmSync(join(root, PATCH_LOCK_RECORD), { force: true }),
        })
        console.log(packages.length > 0 ? `Patches: reinstalled ${packages.join(' ')}.` : 'Patches: vendor/ matches patches.lock.json.')

        return 0
    } catch (error) {
        console.error(error.message)

        return 1
    }
}

/** Read a JSON file, or null when it is absent or not JSON. */
function readJson(path) {
    try {
        return JSON.parse(readFileSync(path, 'utf8'))
    } catch {
        return null
    }
}

/**
 * The packages composer.lock pins that vendor/ does not hold at that version and
 * reference. An absent or unreadable installed.json reports every locked package.
 *
 * @returns {string[]} one `name: wanted (installed)` line per mismatch
 */
export function composerDrift(lock, installed) {
    const pinned = [...(lock?.packages ?? []), ...(lock?.['packages-dev'] ?? [])]
    const have = new Map()

    for (const pkg of installed?.packages ?? []) {
        have.set(pkg.name, pkg)
    }

    const drift = []

    for (const pkg of pinned) {
        const got = have.get(pkg.name)
        const wantRef = pkg.dist?.reference ?? pkg.source?.reference ?? null
        const gotRef = got?.dist?.reference ?? got?.source?.reference ?? null

        if (!got) {
            drift.push(`${pkg.name}: ${pkg.version} (not installed)`)
        } else if (got.version !== pkg.version || wantRef !== gotRef) {
            drift.push(`${pkg.name}: ${pkg.version} (installed ${got.version})`)
        }
    }

    return drift
}

/**
 * The packages package-lock.json pins that node_modules/ does not hold at that
 * version, read from npm's own record of the tree (`node_modules/.package-lock.json`).
 *
 * @returns {string[]} one `path: wanted (installed)` line per mismatch
 */
export function npmDrift(lock, installed) {
    const drift = []
    const have = installed?.packages ?? {}

    for (const [path, pkg] of Object.entries(lock?.packages ?? {})) {
        if (path === '' || pkg.link) {
            continue
        }

        const got = have[path]

        if (!got) {
            drift.push(`${path}: ${pkg.version} (not installed)`)
        } else if (got.version !== pkg.version) {
            drift.push(`${path}: ${pkg.version} (installed ${got.version})`)
        }
    }

    return drift
}

/** The Integration suite's own Composer setup, beside the root's (#375). */
export const INTEGRATION_DIR = join('tests', 'Integration')

/** True when `root` is a worktree the shared runner created as its slot. */
export function isRunnerSlot(root, exec = spawnSync) {
    const r = exec('git', ['-C', root, 'rev-parse', '--absolute-git-dir'], { encoding: 'utf8' })

    return r.status === 0 && existsSync(join(r.stdout.trim(), SLOT_MARKER))
}

function run(cmd, args, cwd) {
    const exe = which(cmd)

    if (!exe) {
        console.error(`${cmd} is not on PATH.`)

        return 1
    }

    // Composer and npm are `.bat`/`.cmd` shims on Windows, which spawn cannot
    // start without a shell; spawn-helpers says when, and quotes for it.
    const r = needsShell(exe)
        ? spawnSync([exe, ...args].map(winQuote).join(' '), { cwd, stdio: 'inherit', shell: true })
        : spawnSync(exe, args, { cwd, stdio: 'inherit' })

    return r.status ?? 1
}

export function main(root = process.cwd(), { slot = isRunnerSlot, exec = run, sync = syncPatches } = {}) {
    const hasNodeLock = existsSync(join(root, 'package-lock.json'))

    if (slot(root)) {
        console.log('Runner slot: installing from composer.lock and package-lock.json.')

        // Only a completed patch sync below may write the record again.
        dropPatchRecord(join(root, INTEGRATION_DIR))

        for (const dir of [root, join(root, INTEGRATION_DIR)]) {
            if (exec('composer', ['install', '--no-interaction', '--no-progress', '--prefer-dist'], dir) !== 0) {
                console.error(`composer install failed in ${dir}.`)

                return 1
            }
        }

        if (sync(join(root, INTEGRATION_DIR)) !== 0) {
            return 1
        }

        if (hasNodeLock && exec('npm', ['ci', '--no-audit', '--no-fund'], root) !== 0) {
            console.error('npm ci failed.')

            return 1
        }

        return 0
    }

    const composer = ['', INTEGRATION_DIR].flatMap((dir) => composerDrift(
        readJson(join(root, dir, 'composer.lock')),
        readJson(join(root, dir, 'vendor/composer/installed.json')),
    ).map((line) => (dir === '' ? line : `${dir}: ${line}`)))
    const npm = hasNodeLock
        ? npmDrift(readJson(join(root, 'package-lock.json')), readJson(join(root, 'node_modules/.package-lock.json')))
        : []

    if (composer.length === 0 && npm.length === 0) {
        console.log('vendor/ and node_modules/ match the locks. Not a runner slot, so nothing was installed.')

        return 0
    }

    console.error('This tree is not the runner\'s slot, so nothing was installed, and its dependencies do not match the locks:')

    for (const line of [...composer.map((l) => `  composer  ${l}`), ...npm.map((l) => `  npm       ${l}`)]) {
        console.error(line)
    }

    console.error(`Run ${composer.length ? '`composer install && composer setup:integration`' : ''}${composer.length && npm.length ? ' and ' : ''}${npm.length ? '`npm ci`' : ''} here, then run again.`)

    return 1
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
    process.exitCode = main()
}
