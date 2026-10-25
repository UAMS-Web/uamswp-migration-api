<?php

declare(strict_types=1);

/*
 * Drives `scripts/check-no-vendor-dependency.php` against throwaway fixture
 * plugins, asserting on the exit code and the names it prints — the contract CI
 * actually depends on.
 *
 * The guard exists because the invariant it protects has no local symptom: the
 * plugin's autoloader is hand-rolled so it runs on a stock WordPress install
 * with no `vendor/` (D-012), and a `require __DIR__.'/vendor/autoload.php'`
 * keeps working in every development checkout and is fatal only in production. A
 * guard for an invisible failure is itself easy to break invisibly, so each
 * violation it is supposed to catch gets a fixture here — a guard that has
 * stopped catching anything still exits 0, which reads exactly like a clean
 * repository.
 *
 * Driven as a subprocess rather than by including the script: it is a CLI entry
 * point that calls `exit()`, and its exit code is what the chain reads.
 */

/**
 * Run the guard against a fixture root.
 *
 * @return array{0: int, 1: string} The exit code and the combined output.
 */
function vendorGuardRun(string $root): array
{
    $script = \dirname(__DIR__, 2).'/scripts/check-no-vendor-dependency.php';

    $command = escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($root).' 2>&1';

    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    return [$exitCode, implode("\n", $output)];
}

/**
 * Build a minimal, valid fixture plugin and return its root.
 *
 * Deliberately not this plugin's own names: the guard locates the bootstrap by
 * its `Plugin Name:` header and reads the prefix out of composer.json, so a
 * fixture using different ones proves it is reading rather than assuming.
 */
function vendorGuardFixture(): string
{
    $root = sys_get_temp_dir().'/vendor-guard-'.bin2hex(random_bytes(6));

    mkdir($root.'/includes/Nested', 0777, true);

    file_put_contents($root.'/composer.json', json_encode([
        'name' => 'acme/widget',
        'autoload' => ['psr-4' => ['Acme\\Widget\\' => 'includes/']],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    file_put_contents($root.'/acme-widget.php', <<<'PHP'
        <?php

        /**
         * Plugin Name: Acme Widget
         */

        declare(strict_types=1);

        namespace Acme\Widget;

        spl_autoload_register(
            static function (string $class): void {
                $prefix = __NAMESPACE__.'\\';

                if (! str_starts_with($class, $prefix)) {
                    return;
                }

                $relative = substr($class, strlen($prefix));
                $path = __DIR__.'/includes/'.str_replace('\\', '/', $relative).'.php';

                if (is_readable($path)) {
                    require_once $path;
                }
            }
        );
        PHP);

    file_put_contents($root.'/includes/Thing.php', "<?php\n\nnamespace Acme\\Widget;\n\nclass Thing {}\n");
    file_put_contents($root.'/includes/Nested/Deep.php', "<?php\n\nnamespace Acme\\Widget\\Nested;\n\nfinal class Deep {}\n");

    return $root;
}

/**
 * Recursively delete a fixture directory.
 */
function vendorGuardCleanup(string $root): void
{
    $remove = static function (string $path) use (&$remove): void {
        foreach (scandir($path) ?: [] as $entry) {
            if (\in_array($entry, ['.', '..'], true)) {
                continue;
            }

            $item = $path.\DIRECTORY_SEPARATOR.$entry;

            if (is_dir($item)) {
                /** @var callable(string): void $remove */
                $remove($item);

                continue;
            }

            @unlink($item);
        }

        @rmdir($path);
    };

    $remove($root);
}

it('passes on this repository as it stands', function (): void {
    // The guard has to be green on `main`, or it is a gate nobody can land
    // behind — and a gate people route around is removed within a month.
    [$exitCode, $output] = vendorGuardRun(\dirname(__DIR__, 2));

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('autoloader agrees with composer.json');
});

it('passes on a well-formed fixture plugin', function (): void {
    $root = vendorGuardFixture();

    try {
        [$exitCode] = vendorGuardRun($root);

        expect($exitCode)->toBe(0);
    } finally {
        vendorGuardCleanup($root);
    }
});

it('fails when a runtime file requires the Composer autoloader', function (): void {
    // The exact edit the guard exists for, and the one PR #16 could only verify
    // by hand.
    $root = vendorGuardFixture();

    try {
        file_put_contents(
            $root.'/includes/Thing.php',
            "<?php\n\nnamespace Acme\\Widget;\n\nrequire_once __DIR__.'/../vendor/autoload.php';\n\nclass Thing {}\n"
        );

        [$exitCode, $output] = vendorGuardRun($root);

        expect($exitCode)->toBe(1)
            // The guard reports the offending path with the OS-native separator
            // (DIRECTORY_SEPARATOR), which is correct for a message a person reads
            // on that platform -- so the expectation is converted to match rather
            // than the guard's real output being normalized away.
            ->and($output)->toContain(str_replace('/', \DIRECTORY_SEPARATOR, 'includes/Thing.php'))
            ->and($output)->toContain('vendor')
            // The message has to explain why, or the fix looks like deleting the guard.
            ->and($output)->toContain('does not exist in production');
    } finally {
        vendorGuardCleanup($root);
    }
});

it('fails when a runtime file references a Composer class', function (): void {
    // The subtler shape of the same dependency — no path, so a `vendor/` string
    // search would miss it.
    $root = vendorGuardFixture();

    try {
        file_put_contents(
            $root.'/includes/Thing.php',
            "<?php\n\nnamespace Acme\\Widget;\n\nclass Thing\n{\n    public function loader(): ?\\Composer\\Autoload\\ClassLoader\n    {\n        return null;\n    }\n}\n"
        );

        [$exitCode, $output] = vendorGuardRun($root);

        // Asserted one segment short of the full class name on purpose.
        // `StringClassNameToClassConstantRector` rewrites a resolvable class
        // name in a string to `ClassLoader::class` — which would add a
        // `use Composer\Autoload\ClassLoader` import to the test asserting this
        // repo does not depend on Composer at runtime. A namespace is not a
        // class, so the rule leaves this alone.
        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Composer\\Autoload');
    } finally {
        vendorGuardCleanup($root);
    }
});

it('leaves a `vendor` substring that is not a path segment alone', function (): void {
    // The guard has to be usable. Flagging the word wherever it appears would
    // make it noise, and a noisy gate is one people learn to bypass.
    $root = vendorGuardFixture();

    try {
        file_put_contents(
            $root.'/includes/Thing.php',
            "<?php\n\nnamespace Acme\\Widget;\n\nclass Thing\n{\n    public const LABEL = 'vendor support contact';\n\n    public const KEY = 'vendor_id';\n}\n"
        );

        [$exitCode] = vendorGuardRun($root);

        expect($exitCode)->toBe(0);
    } finally {
        vendorGuardCleanup($root);
    }
});

it('fails when composer.json moves the PSR-4 directory and the bootstrap does not follow', function (): void {
    // The divergence that has no test symptom at all: every test loads classes
    // through vendor/autoload.php, so Composer's map is the one exercised
    // locally and the hand-rolled one is exercised only in production.
    $root = vendorGuardFixture();

    try {
        file_put_contents($root.'/composer.json', json_encode([
            'name' => 'acme/widget',
            'autoload' => ['psr-4' => ['Acme\\Widget\\' => 'src/']],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        [$exitCode, $output] = vendorGuardRun($root);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('never names `src/`');
    } finally {
        vendorGuardCleanup($root);
    }
});

it('fails when a class sits at a path its own name does not resolve to', function (): void {
    // The plugin autoloader maps a class name to a path and stops. A class the
    // path convention cannot reach is not found at runtime — and, because
    // nothing else in the plugin references it until something does, the first
    // symptom is a fatal on a production request.
    $root = vendorGuardFixture();

    try {
        file_put_contents($root.'/includes/Nested/Deep.php', "<?php\n\nnamespace Acme\\Widget;\n\nfinal class Deep {}\n");

        [$exitCode, $output] = vendorGuardRun($root);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Acme\\Widget\\Deep')
            ->and($output)->toContain('Acme\\Widget\\Nested\\Deep');
    } finally {
        vendorGuardCleanup($root);
    }
});

it('does not mistake a method named `namespace` for a namespace declaration', function (): void {
    // PHP 8 permits reserved words as method names, so `public function
    // namespace(): string` yields a T_NAMESPACE token. A scan that kept reading
    // past it took the return type as the namespace — which is how this guard
    // first reported includes/REST/Controller.php as declaring `string\Controller`.
    $root = vendorGuardFixture();

    try {
        file_put_contents(
            $root.'/includes/Thing.php',
            "<?php\n\nnamespace Acme\\Widget;\n\nclass Thing\n{\n    public function namespace(): string\n    {\n        return 'acme/v1';\n    }\n}\n"
        );

        [$exitCode, $output] = vendorGuardRun($root);

        expect($exitCode)->toBe(0)
            ->and($output)->not->toContain('string\\Thing');
    } finally {
        vendorGuardCleanup($root);
    }
});
