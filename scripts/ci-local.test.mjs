// Tests for ci-local.mjs (#366).
//
// Run: node --test scripts/ci-local.test.mjs
import { describe, it } from 'node:test'
import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdtempSync, realpathSync, rmSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

import { primaryRootOf } from '../.claude/skills/local-ci/local-ci.mjs'
import { runnerArgs, SLOT_NAME } from './ci-local.mjs'

describe('runnerArgs', () => {
    it('places a --branch slot in the dotted sibling', () => {
        assert.deepEqual(runnerArgs(['--branch', 'x'], '/p/repo'), ['--branch', 'x', '--worktree-path', join('/p/repo', '..', SLOT_NAME)])
        assert.match(SLOT_NAME, /^\./, 'WordPress lists a directory without a leading dot as a plugin')
    })

    it('leaves an in-place run alone, since the runner refuses --worktree-path without --branch', () => {
        assert.deepEqual(runnerArgs(['--dry-run'], '/p/repo'), ['--dry-run'])
    })

    it('keeps a --worktree-path the caller chose', () => {
        assert.deepEqual(runnerArgs(['--branch', 'x', '--worktree-path', '/elsewhere'], '/p/repo'), ['--branch', 'x', '--worktree-path', '/elsewhere'])
    })

    it('drops --fetch, which the shared runner would refuse', () => {
        assert.deepEqual(runnerArgs(['--branch', 'x', '--fetch'], '/p/repo').includes('--fetch'), false)
    })

    it('puts the slot beside the primary when invoked from a linked worktree', () => {
        const dir = realpathSync(mkdtempSync(join(tmpdir(), 'ci-local-')))
        const git = (cwd, ...args) => {
            const r = spawnSync('git', ['-C', cwd, ...args], { encoding: 'utf8' })
            assert.equal(r.status, 0, r.stderr)
        }
        try {
            const primary = join(dir, 'repo')
            git(dir, 'init', '-q', 'repo')
            git(primary, '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '--allow-empty', '-m', 'x')
            const linked = join(primary, '.claude', 'worktrees', 't1')
            git(primary, 'worktree', 'add', '-q', '--detach', linked)

            // The control: from the linked worktree itself the slot would land
            // inside .claude/worktrees/, a second slot per ephemeral tree.
            assert.notEqual(join(linked, '..', SLOT_NAME), join(primary, '..', SLOT_NAME))
            assert.deepEqual(
                runnerArgs(['--branch', 'x'], primaryRootOf(linked)),
                ['--branch', 'x', '--worktree-path', join(primary, '..', SLOT_NAME)],
            )
        } finally {
            rmSync(dir, { recursive: true, force: true })
        }
    })
})
