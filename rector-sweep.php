<?php

declare(strict_types=1);

// Sweep profile: every rule in rector.php, including the judgment-call ones the
// gating profile skips. Two lines so the profiles cannot drift.
define('UAMS_RECTOR_SWEEP', true);

return require __DIR__.'/rector.php';
