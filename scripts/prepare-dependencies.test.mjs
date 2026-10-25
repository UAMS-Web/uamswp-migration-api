// Tests for prepare-dependencies.mjs (#366).
//
// Run: node --test scripts/prepare-dependencies.test.mjs
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { existsSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

import { PATCH_LOCK_RECORD } from '../.claude/skills/local-ci/local-ci.mjs'
import { composerDrift, INTEGRATION_DIR, isRunnerSlot, main, npmDrift, SLOT_MARKER, syncPatches } from './prepare-dependencies.mjs'

const pkg = (name, version, reference = 'abc') => ({ name, version, dist: { reference } })

describe('composerDrift', () => {
    it('reports nothing when vendor/ holds exactly what the lock pins', () => {
        const lock = { packages: [pkg('a/a', '1.0.0')], 'packages-dev': [pkg('b/b', '2.0.0')] }

        assert.deepEqual(composerDrift(lock, { packages: [pkg('a/a', '1.0.0'), pkg('b/b', '2.0.0')] }), [])
    })

    it('reports a version, a reference, or a package that is missing', () => {
        const lock = { packages: [pkg('a/a', '1.1.0'), pkg('c/c', '1.0.0', 'new')], 'packages-dev': [pkg('b/b', '2.0.0')] }
        const installed = { packages: [pkg('a/a', '1.0.0'), pkg('c/c', '1.0.0', 'old')] }

        assert.deepEqual(composerDrift(lock, installed), [
            'a/a: 1.1.0 (installed 1.0.0)',
            'c/c: 1.0.0 (installed 1.0.0)',
            'b/b: 2.0.0 (not installed)',
        ])
    })

    it('reads an absent installed.json as nothing installed', () => {
        assert.deepEqual(composerDrift({ packages: [pkg('a/a', '1.0.0')] }, null), ['a/a: 1.0.0 (not installed)'])
    })
})

describe('npmDrift', () => {
    const lock = { packages: { '': { name: 'root' }, 'node_modules/x': { version: '1.0.0' }, 'node_modules/l': { link: true } } }

    it('reports nothing when node_modules/ matches, skipping the root and links', () => {
        assert.deepEqual(npmDrift(lock, { packages: { 'node_modules/x': { version: '1.0.0' } } }), [])
    })

    it('reports a wrong version and a missing package', () => {
        assert.deepEqual(npmDrift(lock, { packages: { 'node_modules/x': { version: '0.9.0' } } }), ['node_modules/x: 1.0.0 (installed 0.9.0)'])
        assert.deepEqual(npmDrift(lock, null), ['node_modules/x: 1.0.0 (not installed)'])
    })
})

describe('isRunnerSlot', () => {
    it('is true only when the git directory carries the runner\'s marker', () => {
        const gitDir = mkdtempSync(join(tmpdir(), 'prepare-deps-'))
        const exec = () => ({ status: 0, stdout: `${gitDir}\n` })

        try {
            assert.equal(isRunnerSlot('/any', exec), false)

            mkdirSync(gitDir, { recursive: true })
            writeFileSync(join(gitDir, SLOT_MARKER), '')

            assert.equal(isRunnerSlot('/any', exec), true)
            assert.equal(isRunnerSlot('/any', () => ({ status: 128, stdout: '' })), false)
        } finally {
            rmSync(gitDir, { recursive: true, force: true })
        }
    })
})

describe('syncPatches', () => {
    const lock = (patch) => JSON.stringify({ _hash: patch, patches: { 'a/a': [{ description: 'd', url: `patches/${patch}.patch` }] } })

    function slot() {
        const root = mkdtempSync(join(tmpdir(), 'prepare-patches-'))
        mkdirSync(join(root, 'vendor', 'composer'), { recursive: true })
        writeFileSync(join(root, 'vendor', 'composer', 'installed.json'), JSON.stringify({ packages: [{ name: 'a/a' }] }))

        return root
    }

    it('reinstalls the patched package when the lock moved, then records it', () => {
        const root = slot()
        const calls = []

        try {
            writeFileSync(join(root, 'patches.lock.json'), lock('new'))
            writeFileSync(join(root, PATCH_LOCK_RECORD), lock('old'))

            assert.equal(syncPatches(root, (packages) => (calls.push(packages), 0)), 0)
            assert.deepEqual(calls, [['a/a']])
            assert.equal(readFileSync(join(root, PATCH_LOCK_RECORD), 'utf8'), lock('new'))

            // The control: nothing moved, so nothing is reinstalled.
            assert.equal(syncPatches(root, (packages) => (calls.push(packages), 0)), 0)
            assert.equal(calls.length, 1)
        } finally {
            rmSync(root, { recursive: true, force: true })
        }
    })

    it('fails and records nothing when the reinstall fails', () => {
        const root = slot()

        try {
            writeFileSync(join(root, 'patches.lock.json'), lock('new'))

            assert.equal(syncPatches(root, () => 1), 1)
            assert.equal(existsSync(join(root, PATCH_LOCK_RECORD)), false)
        } finally {
            rmSync(root, { recursive: true, force: true })
        }
    })
})

describe('main in a runner slot', () => {
    const lock = JSON.stringify({ _hash: 'h', patches: { 'a/a': [{ description: 'd', url: 'patches/h.patch' }] } })

    // A slot whose Integration vendor/ holds a record from an earlier, successful sync.
    function slot() {
        const root = mkdtempSync(join(tmpdir(), 'prepare-main-'))
        const integration = join(root, INTEGRATION_DIR)
        mkdirSync(join(integration, 'vendor', 'composer'), { recursive: true })
        writeFileSync(join(integration, 'vendor', 'composer', 'installed.json'), JSON.stringify({ packages: [{ name: 'a/a' }] }))
        writeFileSync(join(integration, 'patches.lock.json'), lock)
        writeFileSync(join(integration, PATCH_LOCK_RECORD), lock)

        return { root, record: join(integration, PATCH_LOCK_RECORD) }
    }

    for (const failing of ['.', INTEGRATION_DIR]) {
        it(`drops the patch record when composer install fails in ${failing}`, () => {
            const { root, record } = slot()

            try {
                const exec = (cmd, args, dir) => (dir === join(root, failing) ? 1 : 0)

                assert.equal(main(root, { slot: () => true, exec, sync: () => assert.fail('no sync after a failed install') }), 1)
                assert.equal(existsSync(record), false)
            } finally {
                rmSync(root, { recursive: true, force: true })
            }
        })
    }

    it('writes the record again after a successful install and patch sync', () => {
        const { root, record } = slot()
        const reinstalled = []

        try {
            const sync = (dir) => syncPatches(dir, (packages) => (reinstalled.push(packages), 0))

            assert.equal(main(root, { slot: () => true, exec: () => 0, sync }), 0)
            assert.equal(readFileSync(record, 'utf8'), lock)
            // The record was dropped first, so the sync reinstalled the patched package.
            assert.deepEqual(reinstalled, [['a/a']])
        } finally {
            rmSync(root, { recursive: true, force: true })
        }
    })
})
