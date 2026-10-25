<?php

declare(strict_types=1);

namespace UAMSWP\MigrationAPI\Tests\Support;

/**
 * A class that records having been woken.
 *
 * `OptionsPayload` decodes option values to look inside them, and it decodes
 * them with `allowed_classes: false` so that inspecting a value cannot
 * construct anything. Plain `unserialize()` instantiates whatever class the
 * string names and runs its `__wakeup()` — on values a denylist exists
 * precisely because nobody has audited them.
 *
 * A comment asserting that flag is not evidence. This is: give a real class a
 * visible side effect, store one serialized, and show the side effect never
 * fires. Remove `allowed_classes` from `decoded()` and the test goes red.
 */
final class WakeupProbe
{
    /**
     * Set by `__wakeup()`, and by nothing else.
     */
    public static bool $woke = false;

    /**
     * Named so the nested-key rule withholds the option carrying it, which is
     * what keeps `value()` — which *does* construct — off this value entirely.
     */
    public string $api_secret = '';

    public function __wakeup(): void
    {
        self::$woke = true;
    }
}
