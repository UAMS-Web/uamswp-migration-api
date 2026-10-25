<?php

/**
 * Placeholders for PHPUnit classes that PHPUnit 10 removed.
 *
 * WordPress' test suite ships `includes/phpunit6/compat.php`, which aliases a set
 * of PHPUnit 5-era names (`PHPUnit_Framework_*`) to their namespaced replacements.
 * Five of those replacements no longer exist, so `class_alias()` emits a warning
 * for each one every time the Feature suite boots.
 *
 * The warnings are harmless — nothing in this plugin's tests, or in core's test
 * suite as this plugin uses it, ever touches the aliased names. They are worth
 * removing anyway: five warnings on every run is how a sixth, real one gets
 * scrolled past.
 *
 * Declaring the names here gives `class_alias()` a target. Each declaration is
 * guarded, so a future PHPUnit that reinstates any of them wins instead of
 * colliding.
 *
 * `composer.json` excludes this file from the classmap. It declares `PHPUnit\*`
 * classes inside a directory mapped to `UAMSWP\MigrationAPI\Tests\`, which is a
 * PSR-4 violation by construction — without the exclusion,
 * `composer dump-autoload --strict-psr` fails. It is required explicitly by
 * `tests/Integration/bootstrap.php`, so it never needed autoloading.
 *
 * @see https://github.com/wp-phpunit/wp-phpunit/blob/master/includes/phpunit6/compat.php
 */

declare(strict_types=1);

namespace PHPUnit\Framework;

if (! \interface_exists(TestListener::class, false)) {
    /** Removed in PHPUnit 10 in favour of the event system. */
    interface TestListener {}
}

if (! \class_exists(Warning::class, false)) {
    /** Removed in PHPUnit 10. */
    class Warning {}
}

namespace PHPUnit\Framework\Error;

if (! \class_exists(Deprecated::class, false)) {
    /** Removed in PHPUnit 10, along with the rest of the Error hierarchy. */
    class Deprecated {}
}

if (! \class_exists(Notice::class, false)) {
    /** Removed in PHPUnit 10, along with the rest of the Error hierarchy. */
    class Notice {}
}

if (! \class_exists(Warning::class, false)) {
    /** Removed in PHPUnit 10, along with the rest of the Error hierarchy. */
    class Warning {}
}
