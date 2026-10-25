<?php

/**
 * Guard: the plugin must run on a stock WordPress install with no `vendor/`.
 *
 * Composer is dev-time only here (D-012), and the autoloader is hand-rolled for
 * exactly that reason. Nothing enforces it: a single `require __DIR__.'/vendor/
 * autoload.php'` keeps working in every development checkout — where `vendor/`
 * is always present — and raises a fatal only in production, where it never is.
 * The failure has no local symptom at all, which is why it gets a gate rather
 * than a code review note.
 *
 * Two checks, both static and dependency-free (PHP's own tokenizer, no
 * `vendor/` of its own to load — this script has to run before `composer
 * install` in the CI chain):
 *
 *   A. No runtime file names a Composer artifact. Scans the plugin bootstrap,
 *      `includes/` and `config/` for a `vendor` path segment, an
 *      `autoload.php`, or a `Composer\` symbol.
 *
 *   B. The hand-rolled autoloader and Composer's PSR-4 map still agree. They
 *      are two independent statements of the same mapping, and only one of
 *      them is exercised locally: every test loads classes through
 *      `vendor/autoload.php`, so a `composer.json` edit that moves the
 *      prefix leaves the suite green and production dead. This asserts the
 *      bootstrap's namespace equals the PSR-4 prefix, that it names the same
 *      directory, and that every file under that directory declares the class
 *      its own path implies.
 *
 * Usage:
 *
 *     php scripts/check-no-vendor-dependency.php [root]
 *
 * `root` defaults to the repository root and exists so the guard's own tests
 * can drive it against a fixture. Exits 0 when clean, 1 with one line per
 * violation otherwise.
 *
 * What it does NOT catch, deliberately: a Composer class reached through a
 * fully dynamic name (`$class = 'Composer'.'\\Autoload'; new $class`). Static
 * analysis cannot see that, and nothing short of running the plugin against an
 * absent `vendor/` would — which needs a WordPress install, and so belongs in
 * the Feature suite rather than in the chain's first, dependency-free link.
 */

declare(strict_types=1);

/**
 * A single violation, ready to print.
 *
 * @phpstan-type Violation array{file: string, line: int, message: string}
 */
final class VendorGuardViolations
{
    /** @var list<array{file: string, line: int, message: string}> */
    private array $violations = [];

    public function add(string $file, int $line, string $message): void
    {
        $this->violations[] = ['file' => $file, 'line' => $line, 'message' => $message];
    }

    /** @return list<array{file: string, line: int, message: string}> */
    public function all(): array
    {
        return $this->violations;
    }
}

/**
 * Every `.php` file under a directory, sorted so output is stable run to run.
 *
 * @return list<string>
 */
function vendorGuardPhpFiles(string $directory): array
{
    if (! is_dir($directory)) {
        return [];
    }

    $files = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $entry) {
        if ($entry instanceof SplFileInfo && $entry->isFile() && $entry->getExtension() === 'php') {
            $files[] = $entry->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * Path relative to the scanned root, for readable output.
 */
function vendorGuardRelative(string $path, string $root): string
{
    $prefix = rtrim($root, '/').'/';

    return str_starts_with($path, $prefix) ? substr($path, \strlen($prefix)) : $path;
}

/**
 * The plugin bootstrap: the file at the root carrying a `Plugin Name:` header.
 *
 * Located rather than named, so the guard keeps working if the file is renamed
 * and so its own fixtures do not have to reproduce this plugin's filename.
 */
function vendorGuardBootstrap(string $root): ?string
{
    $candidates = glob(rtrim($root, '/').'/*.php');

    foreach ($candidates === false ? [] : $candidates as $candidate) {
        $head = (string) file_get_contents($candidate, false, null, 0, 4096);

        if (preg_match('/^\s*\*\s*Plugin Name:/m', $head) === 1) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Check A — no runtime file may name a Composer artifact.
 */
function vendorGuardScanForComposerArtifacts(string $file, string $root, VendorGuardViolations $violations): void
{
    $tokens = token_get_all((string) file_get_contents($file));
    $relative = vendorGuardRelative($file, $root);

    foreach ($tokens as $token) {
        if (! \is_array($token)) {
            continue;
        }

        [$id, $text, $line] = [$token[0], $token[1], $token[2]];

        // String literals, including the interpolated pieces of a double-quoted
        // string, so `"{$dir}/vendor/autoload.php"` is caught as well.
        if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
            // `vendor` as a whole path segment — a variable named `$vendor_id`
            // or a string reading "vendor support" is not what this is about.
            if (preg_match('#(^|[/\\\\\'"])vendor([/\\\\\'"]|$)#', $text) === 1) {
                $violations->add($relative, $line, 'names a `vendor` path segment, which does not exist in production');

                continue;
            }

            if (str_contains($text, 'autoload.php')) {
                $violations->add($relative, $line, 'names `autoload.php`; the plugin loads its own classes from `spl_autoload_register`');
            }

            continue;
        }

        // Composer's own runtime classes, however they are referenced.
        if (($id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED) && preg_match('/^\\\\?Composer\\\\/', $text) === 1) {
            $violations->add($relative, $line, "references the Composer class `{$text}`");
        }
    }
}

/**
 * The namespace and the first type declared in a file, if any.
 *
 * @return array{namespace: string, type: ?string}
 */
function vendorGuardDeclaration(string $file): array
{
    $tokens = token_get_all((string) file_get_contents($file));
    $namespace = '';
    $type = null;
    $count = \count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (! \is_array($token)) {
            continue;
        }

        // Only the first `namespace` is the declaration. PHP 8 permits reserved
        // words as method names, so `public function namespace(): string` also
        // yields a T_NAMESPACE — and a scan that kept reading would take the
        // return type as the namespace. Anything but a name immediately after
        // the keyword therefore ends the attempt rather than continuing.
        if ($token[0] === T_NAMESPACE && $namespace === '') {
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];

                if (\is_array($next) && $next[0] === T_WHITESPACE) {
                    continue;
                }

                if (\is_array($next) && ($next[0] === T_NAME_QUALIFIED || $next[0] === T_STRING)) {
                    $namespace = $next[1];
                }

                break;
            }

            continue;
        }

        if ($type === null && \in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
            for ($j = $i + 1; $j < $count; $j++) {
                $next = $tokens[$j];

                if (\is_array($next) && $next[0] === T_STRING) {
                    $type = $next[1];

                    break;
                }

                if (\is_array($next) && $next[0] === T_WHITESPACE) {
                    continue;
                }

                // `class` used as anything other than a declaration keyword —
                // `Foo::class`, an anonymous class — declares no named type.
                break;
            }
        }
    }

    return ['namespace' => $namespace, 'type' => $type];
}

/**
 * Composer's `autoload.psr-4` map, prefix => directory.
 *
 * @return array<string, string>
 */
function vendorGuardPsr4Map(string $root): array
{
    $manifest = rtrim($root, '/').'/composer.json';

    if (! is_readable($manifest)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($manifest), true);

    if (! \is_array($decoded) || ! isset($decoded['autoload']) || ! \is_array($decoded['autoload'])) {
        return [];
    }

    $psr4 = $decoded['autoload']['psr-4'] ?? null;

    if (! \is_array($psr4)) {
        return [];
    }

    $map = [];

    foreach ($psr4 as $prefix => $directory) {
        if (\is_string($prefix) && \is_string($directory)) {
            $map[$prefix] = $directory;
        }
    }

    return $map;
}

/**
 * Check B — the hand-rolled autoloader and Composer's PSR-4 map still agree.
 *
 * @param  array<string, string>  $psr4
 */
function vendorGuardCheckAutoloaderAgreement(
    string $root,
    ?string $bootstrap,
    array $psr4,
    VendorGuardViolations $violations
): void {
    if ($psr4 === []) {
        $violations->add('composer.json', 0, 'declares no `autoload.psr-4` map, so there is nothing for the plugin autoloader to agree with');

        return;
    }

    if ($bootstrap === null) {
        $violations->add('composer.json', 0, 'no plugin bootstrap (a root `.php` file with a `Plugin Name:` header) was found to check the autoloader against');

        return;
    }

    $bootstrapRelative = vendorGuardRelative($bootstrap, $root);
    $bootstrapSource = (string) file_get_contents($bootstrap);
    $bootstrapNamespace = vendorGuardDeclaration($bootstrap)['namespace'];

    foreach ($psr4 as $prefix => $directory) {
        $trimmedPrefix = trim($prefix, '\\');
        $trimmedDirectory = trim($directory, '/');

        // The bootstrap derives its prefix from `__NAMESPACE__`, so the two
        // agree only while the file's own namespace is the PSR-4 prefix.
        if ($bootstrapNamespace !== $trimmedPrefix) {
            $violations->add(
                $bootstrapRelative,
                0,
                "declares namespace `{$bootstrapNamespace}`, but composer.json maps `{$prefix}` — the plugin autoloader keys off `__NAMESPACE__`, so it would never match a class Composer resolves"
            );
        }

        // …and its path expression is a literal, so the directory has to appear.
        if (! str_contains($bootstrapSource, $trimmedDirectory.'/')) {
            $violations->add(
                $bootstrapRelative,
                0,
                "never names `{$trimmedDirectory}/`, but composer.json maps `{$prefix}` there — Composer would load these classes locally and the plugin autoloader would not find them in production"
            );

            continue;
        }

        foreach (vendorGuardPhpFiles(rtrim($root, '/').'/'.$trimmedDirectory) as $file) {
            $relative = vendorGuardRelative($file, $root);
            $withinPrefix = substr($relative, \strlen($trimmedDirectory) + 1, -\strlen('.php'));
            $expected = $trimmedPrefix.'\\'.str_replace('/', '\\', $withinPrefix);

            $declaration = vendorGuardDeclaration($file);

            if ($declaration['type'] === null) {
                continue;
            }

            $actual = $declaration['namespace'] === ''
                ? $declaration['type']
                : $declaration['namespace'].'\\'.$declaration['type'];

            if ($actual !== $expected) {
                $violations->add(
                    $relative,
                    0,
                    "declares `{$actual}`, but its path resolves to `{$expected}` — the plugin autoloader maps class names to paths and would never reach it"
                );
            }
        }
    }
}

// --- run --------------------------------------------------------------------

$root = rtrim($argv[1] ?? \dirname(__DIR__), '/');

if (! is_dir($root)) {
    fwrite(STDERR, "check-no-vendor-dependency: '{$root}' is not a directory.\n");

    exit(1);
}

$violations = new VendorGuardViolations;
$bootstrap = vendorGuardBootstrap($root);

$runtimeFiles = array_merge(
    $bootstrap === null ? [] : [$bootstrap],
    vendorGuardPhpFiles($root.'/includes'),
    vendorGuardPhpFiles($root.'/config'),
);

foreach ($runtimeFiles as $file) {
    vendorGuardScanForComposerArtifacts($file, $root, $violations);
}

vendorGuardCheckAutoloaderAgreement($root, $bootstrap, vendorGuardPsr4Map($root), $violations);

$found = $violations->all();

if ($found === []) {
    fwrite(STDOUT, 'No vendor/ dependency: '.\count($runtimeFiles)." runtime files scanned, autoloader agrees with composer.json.\n");

    exit(0);
}

fwrite(STDERR, "\nThis plugin must run on a stock WordPress install with no vendor/ directory (docs/DECISIONS.md D-012).\n\n");

foreach ($found as $violation) {
    fwrite(STDERR, sprintf(
        "  %s%s %s\n",
        $violation['file'],
        $violation['line'] > 0 ? ':'.$violation['line'] : '',
        $violation['message']
    ));
}

fwrite(STDERR, "\n");

exit(1);
