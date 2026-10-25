<?php

/**
 * Guard: no shipped file calls a function the PHP 8.2 floor lacks, where only a
 * polyfill in a development `vendor/` defines it.
 *
 * The floor is held three ways (D-092): PHPStan's `phpVersion.min`, Rector's
 * `withPhpVersion`, and `lint-floor`. PHPStan reports a function added after
 * 8.2 when the PHP it runs on has the function natively, because it then knows
 * the version that added it. It does not when the function is newer than that
 * PHP and a polyfill defines it: Composer's autoload files load the polyfill
 * while PHPStan boots, and the polyfill's definition is all PHPStan sees. With
 * PHPStan on 8.4, that is every PHP 8.5 function `symfony/polyfill-php85`
 * defines (`array_first`, `array_last`). The plugin ships no `vendor/` (D-012),
 * so on the floor such a call is a fatal error, and no suite runs on 8.2 to
 * find it (#399).
 *
 * So this runs ON the floor and asks the floor directly: it lists every
 * function a polyfill under `vendor/` or `tests/Integration/vendor/` defines,
 * finds each call to one of them in the files `lint-floor` checks, and reports
 * the calls whose function this PHP does not have natively. The polyfills are
 * read as text and never loaded, so `function_exists()` answers for the
 * running PHP alone.
 *
 * Usage:
 *
 *     php scripts/check-floor-functions.php [--any-php] [root]
 *
 * `root` defaults to the repository root and must be a git work tree: the files
 * checked are `git ls-files` with `lint-floor`'s exclusions, and a git failure
 * is reported with git's own message. The guard refuses to run on any PHP but
 * 8.2, because on a newer one every function that PHP has natively would pass.
 * `--any-php` lifts that refusal for the guard's own tests, which run on the
 * development PHP. Prints what it read first, then one line per call. Exits 0
 * when clean, 1 on a call, and 2 when it could not look: the wrong PHP, a git
 * failure, no files to check, or no polyfill to read.
 *
 * What it does NOT catch: a call built at run time (`$name()`,
 * `call_user_func($name)` with a computed name). A string literal naming the
 * function is reported, so `array_map('array_first', ...)` is caught.
 */

declare(strict_types=1);

/** The supported floor, as `major.minor`. composer.json's `require.php` is `^8.2`. */
const FLOOR_PHP = '8.2';

/**
 * Tracked PHP files, with the exclusions `lint-floor` applies.
 *
 * Git runs from an argument array through `proc_open()`, so no shell sees the
 * command: no redirect and no quoting that `cmd.exe` reads differently from a
 * POSIX shell (#416). Its standard error is read here rather than discarded, so
 * a failure is reported in git's own words.
 *
 * @return list<string>|string Paths relative to `$root`, or why git failed.
 */
function floorShippedFiles(string $root): array|string
{
    $command = ['git', '-C', $root, 'ls-files', '-z', '--', '*.php', ':!:vendor/**', ':!:tests/**', ':!:node_modules/**'];
    $pipes = [];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (! \is_resource($process)) {
        return 'git could not be started';
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    if ($status !== 0) {
        return 'git ls-files exited '.$status.($stderr === '' ? '' : ': '.$stderr);
    }

    return array_values(array_filter(explode("\0", $stdout), static fn (string $path): bool => $path !== ''));
}

/**
 * Every function a polyfill's bootstrap file defines, lowercased.
 *
 * @return array{files: list<string>, functions: array<string, true>}
 */
function floorPolyfilledFunctions(string $root): array
{
    $files = [];

    foreach (['vendor', 'tests/Integration/vendor'] as $vendor) {
        $found = glob($root.'/'.$vendor.'/*/*polyfill*/bootstrap*.php');

        foreach ($found === false ? [] : $found as $file) {
            $files[] = $file;
        }
    }

    $functions = [];

    foreach ($files as $file) {
        $source = (string) file_get_contents($file);

        if (preg_match_all('/^\s*function\s+(\w+)\s*\(/m', $source, $matches) > 0) {
            foreach ($matches[1] as $name) {
                $functions[strtolower($name)] = true;
            }
        }
    }

    return ['files' => $files, 'functions' => $functions];
}

/**
 * The global functions a file calls or names, with the line of each.
 *
 * @return list<array{name: string, line: int}>
 */
function floorFunctionReferences(string $source): array
{
    $tokens = token_get_all($source);
    $count = \count($tokens);
    $references = [];
    $ignorable = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
    // A name after one of these is a method, a constant, a class or a
    // declaration, never a call to a global function.
    // (cspell:ignore NULLSAFE: PHP's own token constant name.)
    $notACall = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST];

    for ($index = 0; $index < $count; $index++) {
        $token = $tokens[$index];

        if (! \is_array($token)) {
            continue;
        }

        [$id, $text, $line] = $token;

        if ($id === T_CONSTANT_ENCAPSED_STRING) {
            $references[] = ['name' => substr($text, 1, -1), 'line' => $line];

            continue;
        }

        if ($id !== T_STRING && $id !== T_NAME_FULLY_QUALIFIED) {
            continue;
        }

        $next = $index + 1;

        while ($next < $count && \is_array($tokens[$next]) && \in_array($tokens[$next][0], $ignorable, true)) {
            $next++;
        }

        $previous = $index - 1;

        while ($previous >= 0 && \is_array($tokens[$previous]) && \in_array($tokens[$previous][0], $ignorable, true)) {
            $previous--;
        }

        $previousId = $previous >= 0 && \is_array($tokens[$previous]) ? $tokens[$previous][0] : null;
        $isCall = $next < $count && $tokens[$next] === '(' && ! \in_array($previousId, $notACall, true);
        // `use function array_first;` imports the global function by name.
        $isImport = $previousId === T_FUNCTION && $previous > 0 && floorPreviousIsUse($tokens, $previous - 1);

        if ($isCall || $isImport) {
            $references[] = ['name' => ltrim($text, '\\'), 'line' => $line];
        }
    }

    return $references;
}

/**
 * Whether the significant token at or before `$index` is `use`.
 *
 * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
 */
function floorPreviousIsUse(array $tokens, int $index): bool
{
    while ($index >= 0 && \is_array($tokens[$index]) && \in_array($tokens[$index][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
        $index--;
    }

    return $index >= 0 && \is_array($tokens[$index]) && $tokens[$index][0] === T_USE;
}

/**
 * Run the guard and return its exit code.
 *
 * @param  list<string>  $arguments  The command-line arguments after the script name.
 */
function floorFunctionsMain(array $arguments): int
{
    $anyPhp = \in_array('--any-php', $arguments, true);
    $positional = array_values(array_filter($arguments, static fn (string $argument): bool => ! str_starts_with($argument, '--')));
    $root = rtrim($positional[0] ?? \dirname(__DIR__), '/');
    $running = PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;

    if (! $anyPhp && $running !== FLOOR_PHP) {
        fwrite(STDERR, 'check-floor-functions: running on PHP '.$running.', not the '.FLOOR_PHP.' floor. Every function this PHP has natively would pass, so it does not look.'.PHP_EOL);

        return 2;
    }

    $files = floorShippedFiles($root);
    $polyfills = floorPolyfilledFunctions($root);

    // The denominators come first, so a run that stops early still shows what it had read.
    echo 'PHP '.PHP_VERSION.'; '.(\is_array($files) ? \count($files) : 0).' files checked; '.\count($polyfills['functions']).' functions defined by '.\count($polyfills['files']).' polyfill files.'.PHP_EOL;

    if (\is_string($files)) {
        fwrite(STDERR, 'check-floor-functions: could not list the tracked PHP files under '.$root.': '.$files.PHP_EOL);

        return 2;
    }

    if ($files === []) {
        fwrite(STDERR, 'check-floor-functions: git lists no tracked PHP files under '.$root.'.'.PHP_EOL);

        return 2;
    }

    if ($polyfills['functions'] === []) {
        fwrite(STDERR, 'check-floor-functions: no polyfill found under vendor/ or tests/Integration/vendor/. Install the dependencies first; with nothing to read, a clean result would mean nothing.'.PHP_EOL);

        return 2;
    }

    $violations = 0;

    foreach ($files as $file) {
        foreach (floorFunctionReferences((string) file_get_contents($root.'/'.$file)) as $reference) {
            $name = strtolower($reference['name']);

            if (isset($polyfills['functions'][$name]) && ! \function_exists($name)) {
                echo $file.':'.$reference['line'].': '.$reference['name'].'() is not in PHP '.$running.'; only a polyfill in a development vendor/ defines it.'.PHP_EOL;
                $violations++;
            }
        }
    }

    echo $violations === 0 ? 'No shipped file calls a polyfilled function the floor lacks.'.PHP_EOL : $violations.' call(s) to functions the floor lacks.'.PHP_EOL;

    return $violations === 0 ? 0 : 1;
}

exit(floorFunctionsMain(\array_slice($argv, 1)));
