#!/usr/bin/env node
//
// check-path-portability.mjs — stop a POSIX literal reaching the Windows gate.
//
// An assertion comparing a `node:path`-derived value against a raw POSIX one
// passes on macOS and fails on Windows. Every development machine here is
// macOS and the only Windows one is the gate, so the defect is authored
// freely, survives every local run, and is caught once, at the end, by the one
// participant that can see it (#245). #243 took three heads that way.
//
// THE DISCRIMINATOR IS PROVENANCE, AND PROVENANCE IS PER RETURN PATH.
//
// A probe keyed on "the expected side is a literal containing a separator"
// misses the defect that actually got through:
//
//     assert.equal(dirname(gate.runRoot), dirname(primaryRoot))
//
// No literal on either side. But the harder half is one level in: `gate` holds
// `runTarget({ preflight: false })`, and that function returns `repoRoot`
// VERBATIM on its pre-flight branch and `join(dirname(...))` on its gate one.
// Classifying the function reports `assert.equal(pre.runRoot, repoRoot)` — a
// value compared against itself — while requiring every branch to normalize
// misses the real defect. Both were measured against this tree before this
// file was written. Only the CALL-SITE ARGUMENT separates them, so the branch
// has to be selected before the property is read.
//
// WHY A REAL PARSER.
//
// That selection is not expressible in a regular expression, and the two
// attempts that preceded this one proved it: the first reported two false
// positives, the second reported none and also missed both real defects.
// `acorn` is a devDependency, added deliberately for this — the first direct
// dependency this package declares — because a lint assembled from regexes
// works for the shapes its author thought of and fails silently on the next
// one, which is this check's own argument turned on itself.
//
// `grep` is not used here, and would not be: on a macOS machine here it
// resolves to `ugrep`, a third variant that is neither GNU nor BSD. Measured,
// status read with nothing between the command and `$?`:
//
//     grep       'expect('          rc=0  prints 1   basic mode: ( is LITERAL
//     grep -E    'expect('          rc=2  stderr     mismatched ( , no stdout
//     grep -E -c 'foo(' | head -1   rc=0             the PIPE swallowed rc=2
//
// The last row is the one that bites: piped, the status vanishes and a clean
// `0` is indistinguishable from a pattern that never ran.
//
// EVERY OCCURRENCE IS REPORTED, NEVER THE FIRST.
//
// A failing assertion aborts the rest of its block, so the gate sees one
// defect per run and each fix reveals the next. A check that stopped at the
// first finding would reproduce the loop it exists to end.

import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import process from 'node:process'
import { fileURLToPath } from 'node:url'
import { parse } from 'acorn'

const ROOT = join(fileURLToPath(new URL('.', import.meta.url)), '..')

// Only the EQUALITY forms. A provenance mismatch makes an INEQUALITY assertion
// MORE likely to hold, never less, so reporting `notEqual` is a false positive
// by construction — measured as one against this tree.
const COMPARING = new Set(['equal', 'strictEqual', 'deepEqual', 'deepStrictEqual'])

const NORMALIZES = new Set(['join', 'resolve', 'normalize', 'toNamespacedPath'])
const SEPARATOR_FREE = new Set(['basename', 'extname', 'relative'])
const INHERITS = new Set(['dirname'])

export const PROVENANCE = {
    NORMALIZED: 'normalized',
    POSIX: 'posix',
    FREE: 'free',
    UNKNOWN: 'unknown',
}

const parseModule = (source) => parse(source, { ecmaVersion: 'latest', sourceType: 'module', locations: true })

/** Every node in the tree, depth-first. Acorn ships no walker in core. */
function* walk(node) {
    if (node === null || typeof node !== 'object') return
    if (typeof node.type === 'string') yield node

    for (const key of Object.keys(node)) {
        if (key === 'loc' || key === 'start' || key === 'end') continue
        const child = node[key]
        if (Array.isArray(child)) { for (const c of child) yield* walk(c) } else yield* walk(child)
    }
}

const calleeName = (node) => {
    if (!node || node.type !== 'CallExpression') return null
    if (node.callee.type === 'Identifier') return node.callee.name
    if (node.callee.type === 'MemberExpression' && node.callee.property.type === 'Identifier') {
        return node.callee.property.name
    }

    return null
}

/**
 * Which local names are bound to `node:path`, and to what.
 *
 * CLASSIFYING BY THE RAW CALLEE NAME WAS THE DEFECT (#250). `import { join as
 * pathJoin }` produced a call this check did not recognize, so the assertion it
 * guarded went unexamined and the file reported clean -- measured across three
 * spellings of one assertion, where only the import line differed.
 *
 * Resolving the BINDING fixes the converse at the same time, and that half was
 * never measured before: a local `join()` of a repository's own, unrelated to
 * `node:path`, is no longer treated as a path helper. Name-matching could not
 * tell those apart, and the false positive is the more expensive direction --
 * a check that cries wolf is silenced rather than fixed.
 *
 * `*` marks a default or namespace import, where the property carries the
 * meaning instead: `nodePath.dirname` and `path.win32.join`.
 */
export function collectPathBindings(ast) {
    const bound = new Map()

    for (const node of walk(ast)) {
        if (node.type !== 'ImportDeclaration') continue
        if (node.source.value !== 'node:path' && node.source.value !== 'path') continue

        for (const spec of node.specifiers) {
            if (spec.type === 'ImportSpecifier') bound.set(spec.local.name, spec.imported.name)
            if (spec.type === 'ImportDefaultSpecifier' || spec.type === 'ImportNamespaceSpecifier') {
                bound.set(spec.local.name, '*')
            }
        }
    }

    return bound
}

/** The platform a `posix`/`win32` sub-namespace states outright. */
const platformOf = (canonical) => {
    if (canonical === 'win32') return PROVENANCE.NORMALIZED
    if (canonical === 'posix') return PROVENANCE.POSIX

    return null
}

/**
 * Resolve a call to the `node:path` helper it actually invokes.
 *
 * Returns the canonical name, or null when the callee is not bound to
 * `node:path` at all -- which is the case that must NOT be classified.
 */
function pathCall(node, pathBound) {
    if (!node || node.type !== 'CallExpression') return null
    const callee = node.callee

    // `pathJoin(a, b)` -- a bare identifier, bound or not.
    if (callee.type === 'Identifier') {
        const canonical = pathBound.get(callee.name)

        return canonical && canonical !== '*' ? { canonical, platform: null } : null
    }

    if (callee.type !== 'MemberExpression' || callee.property.type !== 'Identifier') return null
    const method = callee.property.name
    const object = callee.object

    // `nodePath.dirname(x)` / `posixPath.join(x)`
    if (object.type === 'Identifier') {
        const canonical = pathBound.get(object.name)
        if (!canonical) return null

        return { canonical: method, platform: platformOf(canonical) }
    }

    // `path.win32.join(x)`
    if (object.type === 'MemberExpression' && object.object.type === 'Identifier'
        && object.property.type === 'Identifier') {
        if (!pathBound.has(object.object.name)) return null

        return { canonical: method, platform: platformOf(object.property.name) }
    }

    return null
}

/** Top-level and block-scoped `const`/`let` bindings, name to init node. */
export function collectBindings(ast) {
    const bindings = new Map()

    for (const node of walk(ast)) {
        if (node.type !== 'VariableDeclarator' || node.id.type !== 'Identifier' || !node.init) continue
        if (!bindings.has(node.id.name)) bindings.set(node.id.name, node.init)
    }

    return bindings
}

/** Functions imported from a relative module, name to its declaration node. */
export function collectImportedFunctions(ast, readModule) {
    const imported = new Map()

    for (const node of walk(ast)) {
        if (node.type !== 'ImportDeclaration') continue
        const specifier = node.source.value
        if (typeof specifier !== 'string' || !specifier.startsWith('.')) continue

        const source = readModule(specifier)
        if (source === null) continue

        let moduleAst
        try { moduleAst = parseModule(source) } catch { continue }

        const modulePathBound = collectPathBindings(moduleAst)
        const declarations = new Map()
        for (const n of walk(moduleAst)) {
            if (n.type === 'FunctionDeclaration' && n.id) declarations.set(n.id.name, n)
        }

        for (const spec of node.specifiers) {
            if (spec.type !== 'ImportSpecifier') continue
            const original = spec.imported.name
            // The producer is stored WITH its own module's path bindings. A
            // function's `join` is whatever ITS file imported, not whatever
            // the file calling it did -- classifying a return value under the
            // caller's map is how the two can silently disagree.
            if (declarations.has(original)) {
                imported.set(spec.local.name, { fn: declarations.get(original), pathBound: modulePathBound })
            }
        }
    }

    return imported
}

/**
 * Pick the return statement a call reaches, given its arguments.
 *
 * Only the shape that actually occurs is handled: a destructured options
 * object whose boolean flag guards an early return. Anything else yields every
 * return, and a property whose provenance differs between them is UNKNOWN —
 * which reports nothing. That is the safe direction: a missed finding costs one
 * gate round trip, a false one costs the check its credibility.
 */
export function returnsFor(fn, callArguments) {
    const returns = [...walk(fn.body)].filter((n) => n.type === 'ReturnStatement' && n.argument)
    const options = callArguments && callArguments[0]
    if (!options || options.type !== 'ObjectExpression') return returns

    const literals = new Map()
    for (const property of options.properties) {
        if (property.type !== 'Property' || property.key.type !== 'Identifier') continue
        if (property.value.type === 'Literal' && typeof property.value.value === 'boolean') {
            literals.set(property.key.name, property.value.value)
        }
    }
    if (literals.size === 0) return returns

    for (const statement of fn.body.body) {
        if (statement.type !== 'IfStatement' || statement.test.type !== 'Identifier') continue
        const flag = literals.get(statement.test.name)
        if (flag === undefined) continue

        const taken = flag
            ? [...walk(statement.consequent)].filter((n) => n.type === 'ReturnStatement' && n.argument)
            : returns.filter((r) => ![...walk(statement.consequent)].includes(r))

        if (taken.length > 0) return taken
    }

    return returns
}

/** The provenance of `<producer>(...).<property>`, branch selected. */
function propertyProvenance(producer, callArguments, property, context, depth) {
    const found = []
    // The producer's module, not the caller's.
    const inner = { ...context, pathBound: producer.pathBound }

    for (const statement of returnsFor(producer.fn, callArguments)) {
        if (statement.argument.type !== 'ObjectExpression') continue
        for (const entry of statement.argument.properties) {
            if (entry.type !== 'Property' || entry.key.type !== 'Identifier') continue
            if (entry.key.name === property) found.push(classify(entry.value, inner, depth + 1))
        }
    }

    if (found.length === 0) return PROVENANCE.UNKNOWN

    return found.every((p) => p === found[0]) ? found[0] : PROVENANCE.UNKNOWN
}

/** What an expression was built from. */
export function classify(node, context, depth = 0) {
    if (!node || depth > 6) return PROVENANCE.UNKNOWN
    const { bindings, imported } = context
    const pathBound = context.pathBound ?? new Map()

    if (node.type === 'Literal') {
        return typeof node.value === 'string' && node.value.includes('/')
            ? PROVENANCE.POSIX
            : PROVENANCE.FREE
    }

    if (node.type === 'CallExpression') {
        const resolved = pathCall(node, pathBound)

        // Not bound to `node:path` at all -- including a local function that
        // merely SHARES a name with one. Unknown rather than assumed.
        if (!resolved) return PROVENANCE.UNKNOWN

        // An explicit `posix`/`win32` sub-namespace overrides the helper: a
        // `posix.join` normalizes to forward slashes on every platform.
        if (resolved.platform && !SEPARATOR_FREE.has(resolved.canonical)) return resolved.platform

        if (NORMALIZES.has(resolved.canonical)) return PROVENANCE.NORMALIZED
        if (SEPARATOR_FREE.has(resolved.canonical)) return PROVENANCE.FREE
        if (INHERITS.has(resolved.canonical)) return classify(node.arguments[0], context, depth + 1)

        return PROVENANCE.UNKNOWN
    }

    // A ternary is one value with two derivations. `runTarget()` builds its
    // gate `runRoot` as `worktreePathOpt ? resolve(...) : join(...)`, and
    // without this case that whole branch reads UNKNOWN and the defect the
    // check exists for goes unreported -- measured, not anticipated.
    if (node.type === 'ConditionalExpression') {
        const left = classify(node.consequent, context, depth + 1)
        const right = classify(node.alternate, context, depth + 1)

        return left === right ? left : PROVENANCE.UNKNOWN
    }

    // A template or a concatenation carries whatever its parts carry; a POSIX
    // part anywhere makes the whole string POSIX.
    if (node.type === 'TemplateLiteral') {
        const parts = [
            ...node.quasis.map((q) => (q.value.cooked ?? '').includes('/') ? PROVENANCE.POSIX : PROVENANCE.FREE),
            ...node.expressions.map((e) => classify(e, context, depth + 1)),
        ]
        if (parts.includes(PROVENANCE.NORMALIZED)) return PROVENANCE.NORMALIZED
        if (parts.includes(PROVENANCE.POSIX)) return PROVENANCE.POSIX

        return PROVENANCE.FREE
    }

    if (node.type === 'BinaryExpression' && node.operator === '+') {
        const left = classify(node.left, context, depth + 1)
        const right = classify(node.right, context, depth + 1)
        if (left === PROVENANCE.NORMALIZED || right === PROVENANCE.NORMALIZED) return PROVENANCE.NORMALIZED
        if (left === PROVENANCE.POSIX || right === PROVENANCE.POSIX) return PROVENANCE.POSIX

        return PROVENANCE.UNKNOWN
    }

    if (node.type === 'Identifier' && bindings.has(node.name)) {
        return classify(bindings.get(node.name), context, depth + 1)
    }

    // `gate.runRoot` — resolve `gate` to its producer call, select the branch
    // that call's arguments reach, and read the property from THAT return.
    if (node.type === 'MemberExpression' && node.object.type === 'Identifier'
        && node.property.type === 'Identifier' && !node.computed) {
        const init = bindings.get(node.object.name)
        if (init && init.type === 'CallExpression' && init.callee.type === 'Identifier') {
            const producer = imported.get(init.callee.name)
            if (producer) return propertyProvenance(producer, init.arguments, node.property.name, context, depth)
        }
    }

    return PROVENANCE.UNKNOWN
}

/** Every provenance conflict in one file. */
export function findFindings(source, file = '<source>', readModule = () => null) {
    let ast
    try {
        ast = parseModule(source)
    } catch (error) {
        return { findings: [], assertionLines: 0, parseError: error.message }
    }

    const context = {
        bindings: collectBindings(ast),
        imported: collectImportedFunctions(ast, readModule),
        pathBound: collectPathBindings(ast),
    }
    const findings = []
    let assertionLines = 0

    for (const node of walk(ast)) {
        if (node.type !== 'CallExpression' || node.callee.type !== 'MemberExpression') continue
        if (node.callee.object.type !== 'Identifier' || node.callee.object.name !== 'assert') continue
        if (node.callee.property.type !== 'Identifier') continue

        assertionLines++
        if (!COMPARING.has(node.callee.property.name) || node.arguments.length < 2) continue

        const actual = classify(node.arguments[0], context)
        const expected = classify(node.arguments[1], context)
        const pair = new Set([actual, expected])

        if (pair.has(PROVENANCE.NORMALIZED) && pair.has(PROVENANCE.POSIX)) {
            findings.push({
                file,
                line: node.loc.start.line,
                assertion: `assert.${node.callee.property.name}`,
                actual,
                expected,
            })
        }
    }

    return { findings, assertionLines, parseError: null }
}

// --- the two-sided control ------------------------------------------------
//
// An expression that cannot match and a tree with nothing to find produce
// byte-identical empty output. Both arms run on every invocation and either
// failing exits 2 without reporting on the tree at all.
//
// The control supplies its OWN module, so it does not depend on the tree it is
// about to police — a control that breaks when its subject changes is not one.

const CONTROL_MODULE = `
import { join, dirname } from 'node:path';

export function runTarget({ preflight, repoRoot, primaryRoot }) {
    if (preflight) {
        return { mode: 'preflight', runRoot: repoRoot, createsWorktree: false }
    }

    return { mode: 'gate', runRoot: join(dirname(primaryRoot), 'x'), createsWorktree: true }
}
`

const readControlModule = (specifier) => (specifier === './control.mjs' ? CONTROL_MODULE : null)

const PRELUDE = `import { join, dirname, basename } from 'node:path';
import { runTarget } from './control.mjs';
const repoRoot = '/repo/uamswp-migration-api';
const primaryRoot = '/repo/uamswp-migration-api';
const gate = runTarget({ preflight: false, repoRoot, primaryRoot });
const pre = runTarget({ preflight: true, repoRoot, primaryRoot });
`

const CONTROL_POSITIVE = [
    // #243's FIRST hand-back: a normalized value against a POSIX literal.
    `${PRELUDE}assert.equal(join(a, b), '/repo/plugins');`,
    // #243's SECOND: no literal on either side, and symmetric-looking.
    `${PRELUDE}assert.equal(dirname(gate.runRoot), dirname(primaryRoot));`,
    // The branch-selected property itself, which is the hard case.
    `${PRELUDE}assert.equal(gate.runRoot, '/repo/plugins/.x-ci');`,
]

const CONTROL_NEGATIVE = [
    `${PRELUDE}assert.throws(fn, /found 0 files/);`,
    `${PRELUDE}assert.equal(needsShell('/usr/bin/git'), false);`,
    `${PRELUDE}assert.equal(primaryCheckout(porcelain), '/plugins/x');`,
    `${PRELUDE}assert.equal(basename(gate.runRoot), '.x-ci');`,
    `${PRELUDE}assert.equal(join(a, b), join(c, d));`,
    `${PRELUDE}assert.notEqual(gate.runRoot, repoRoot);`,
    // The pre-flight branch returns its input VERBATIM, so this compares a
    // value against itself. It is the false positive that killed version one.
    `${PRELUDE}assert.equal(pre.runRoot, repoRoot);`,
]

function runControls() {
    const failures = []

    CONTROL_POSITIVE.forEach((source, i) => {
        const { findings, parseError } = findFindings(source, `<positive-${i}>`, readControlModule)
        if (parseError) failures.push(`positive arm ${i} did not parse: ${parseError}`)
        else if (findings.length === 0) failures.push(`positive arm ${i} matched NOTHING`)
    })

    CONTROL_NEGATIVE.forEach((source, i) => {
        const { findings, parseError } = findFindings(source, `<negative-${i}>`, readControlModule)
        if (parseError) failures.push(`negative arm ${i} did not parse: ${parseError}`)
        else if (findings.length > 0) failures.push(`negative arm ${i} matched, and must not`)
    })

    return failures
}

/**
 * The files to sweep, READ FROM DISK rather than listed here.
 *
 * A hardcoded list omitted this check's OWN test file (#249), and nothing
 * noticed: a violation planted there produced zero findings with the file and
 * assertion counts unchanged, so the omission was silent in both directions.
 *
 * The count is the sharper half. The run reports how many files and assertions
 * it read, which is meant to distinguish a clean sweep from an empty one -- and
 * the denominator was computed from the same wrong list. **A number derived
 * from the thing being checked cannot corroborate it.** Deriving the list makes
 * the denominator answerable to the directory instead.
 *
 * Exported so the test consumes this value rather than keeping a second copy in
 * step by hand, which is how the two drifted in the first place.
 */
export function sweptFiles(root = ROOT) {
    return readdirSync(join(root, 'scripts'))
        .filter((name) => name.endsWith('.test.mjs'))
        .sort()
        .map((name) => `scripts/${name}`)
}

function main() {
    const controlFailures = runControls()

    if (controlFailures.length > 0) {
        process.stderr.write('\ncheck-path-portability: THE CONTROL ARMS FAILED.\n\n')
        for (const f of controlFailures) process.stderr.write(`  ${f}\n`)
        process.stderr.write('\nNothing was reported about the tree: a check that cannot find\n')
        process.stderr.write('a planted defect has no opinion worth having.\n\n')
        process.exit(2)
    }

    let findings = []
    let assertionLines = 0
    let filesRead = 0

    for (const rel of sweptFiles()) {
        let source
        try {
            source = readFileSync(join(ROOT, rel), 'utf8')
        } catch {
            process.stderr.write(`\ncheck-path-portability: cannot read ${rel}.\n`)
            process.stderr.write('A sweep over a file it could not open reports clean.\n\n')
            process.exit(2)
        }

        const result = findFindings(source, rel, (specifier) => {
            try {
                return readFileSync(join(ROOT, 'scripts', specifier.replace(/^\.\//, '')), 'utf8')
            } catch {
                return null
            }
        })

        if (result.parseError) {
            process.stderr.write(`\ncheck-path-portability: ${rel} did not parse: ${result.parseError}\n\n`)
            process.exit(2)
        }

        filesRead++
        findings = findings.concat(result.findings)
        assertionLines += result.assertionLines
    }

    const scope = `${filesRead} files, ${assertionLines} assertions`

    if (findings.length === 0) {
        process.stdout.write(`check-path-portability: clean (${scope}, controls passed)\n`)

        return
    }

    process.stderr.write('\ncheck-path-portability: provenance mismatch in an assertion.\n\n')
    for (const f of findings) {
        process.stderr.write(`  ${f.file}:${f.line}  ${f.assertion}\n`)
        process.stderr.write(`    actual is ${f.actual}, expected is ${f.expected}\n\n`)
    }
    process.stderr.write(`Read ${scope}; ${findings.length} finding(s), all of them reported.\n`)
    process.stderr.write('Build the expectation with `basename` or `relative`, which cannot carry\n')
    process.stderr.write('a separator, or state the platform with `posix.` / `win32.`. Rebuilding\n')
    process.stderr.write('it with the same helper the code uses passes everywhere AND passes if\n')
    process.stderr.write('the derivation breaks.\n\n')
    process.exit(1)
}

if (process.argv[1] && import.meta.url === new URL(`file://${process.argv[1]}`).href) {
    main()
}
