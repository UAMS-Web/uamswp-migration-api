/**
 * spawn-helpers.mjs — resolve a command and decide how it has to be spawned.
 *
 * Three helpers that every script shelling out to this package's toolchain needs, kept in one
 * place because the rule they encode is security-driven rather than stylistic and a second copy
 * would drift silently. `local-ci.mjs` carried private versions of two of them and inlined the
 * third; `worktree-slots.mjs` needs the same decision for `git` and `composer`.
 *
 * Node refuses to spawn `.cmd` / `.bat` directly (CVE-2024-27980). On Windows this toolchain
 * straddles that line — Herd exposes PHP as `php.BAT` and Composer as `composer.bat`, while
 * `git.exe` and `node.exe` are real executables — so a caller has to know which kind it holds.
 * Get it wrong and the failure points somewhere else entirely: a bare `spawnSync ENOENT`, which
 * reads as "PHP is not installed" on a machine where PHP is plainly on `PATH`.
 *
 * The three are a set, not three conveniences. `which` resolves the extension, `needsShell` reads
 * it, and `winQuote` repairs the quoting that `shell: true` costs you — using any one without the
 * others reintroduces the bug. The pairing at a call site is:
 *
 *     const exe = which(file) ?? file;
 *     const shell = needsShell(exe);
 *     spawnSync(shell ? winQuote(exe) : exe, shell ? args.map(winQuote) : args, { shell, … });
 */

import { statSync } from 'node:fs';
import { delimiter, join } from 'node:path';

const isWindows = process.platform === 'win32';

/**
 * Whether a resolved executable has to run through a shell.
 *
 * Takes a resolved path, not a bare command name — the extension is the whole signal, and a bare
 * name carries none. See `which`.
 *
 * @param  string  exe
 * @return bool
 */
export function needsShell(exe) {
    return isWindows && /\.(cmd|bat)$/i.test(exe);
}

/**
 * Quote an argument for a Windows shell invocation, per the CreateProcess argv rules.
 *
 * Only needed when `needsShell` said yes: `shell: true` hands the whole command line to `cmd.exe`
 * as text, so an argument holding a space or a quote has to survive being re-parsed. The empty
 * string is the case worth stating, because it has no character to notice — unquoted it simply
 * vanishes from the command line, silently shifting every argument after it by one position.
 *
 * `local-ci.mjs`'s private predecessor quoted only on whitespace, on the stated assumption that
 * "our tokens never embed quotes". That held for three fixed argv arrays; it does not hold for a
 * shared helper handed branch names and filesystem paths.
 *
 * @param  string  arg
 * @return string
 */
export function winQuote(arg) {
    if (arg === '') {
        return '""';
    }

    if (!/[\s"]/.test(arg)) {
        return arg;
    }

    return `"${arg.replace(/(\\*)"/g, '$1$1\\"').replace(/(\\*)$/, '$1$1')}"`;
}

/**
 * Locate an executable on PATH, honoring PATHEXT on Windows. Returns an absolute path, or null.
 *
 * Resolving the absolute path is what lets `needsShell` see the real extension, so this is the
 * step that makes the shim visible at all rather than a lookup convenience.
 *
 * Candidates are accepted only when they are regular **files**. `local-ci.mjs`'s predecessor used
 * `existsSync`, which is true for a directory — so a directory named `php` anywhere on `PATH`
 * would have been returned as the PHP binary.
 *
 * The extension in the returned path carries PATHEXT's case, not the filesystem's — a `.cmd` on
 * disk comes back as `.CMD` — because a case-insensitive `statSync` accepts the candidate as
 * built. Harmless (Windows resolves either, and `needsShell` matches case-insensitively), but do
 * not compare the result against a literal path.
 *
 * @param  string  command
 * @return ?string
 */
export function which(command) {
    const dirs = (process.env.PATH || '').split(delimiter).filter(Boolean);
    const exts = isWindows
        ? (process.env.PATHEXT || '.COM;.EXE;.BAT;.CMD').split(';').filter(Boolean)
        : [''];

    for (const dir of dirs) {
        for (const ext of exts) {
            const candidate = join(dir, command + ext);

            try {
                if (statSync(candidate).isFile()) {
                    return candidate;
                }
            } catch {
                // Not here — keep looking.
            }
        }
    }

    return null;
}
