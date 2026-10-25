# uamswp-migration-api

A read-only WordPress plugin that serves raw, current content to the Statamic importer. It is the migration's data source, replacing hand-made WXR exports and plugin export files.

**This plugin is temporary.** It is deleted when the migration completes: no activation hook, exactly one network option written (a hashed token, deliberately not removed on uninstall — D-063), no tables created, no runtime Composer dependency.

<!-- uams-claude-skills:begin -->
## Shared skills and rules

The files listed here are synced from [UAMS-Web/uams-claude-skills](https://github.com/UAMS-Web/uams-claude-skills) (`shared/`) and are edited there, never here; a sync pull request brings changes into this repository. This section is generated; everything outside it belongs to this repository.

Each rule below is a standing instruction for every task in this repository. Its full text is delivered three times: `.claude/rules/<name>.md`, which Claude Code loads in every session; `.cursor/rules/<name>.mdc`, which Cursor always applies; and the skill `.agents/skills/rule-<name>/`, which Codex selects by its description. Before work a rule governs, read its full text. Skills are in `.claude/skills/` and, as the same files, in `.agents/skills/`, and load when their description matches the task.

Profiles for this repository: core, php, pest, wordpress-plugin, tests, quality, local-ci.

### Contributing

Follow [`CONTRIBUTING.md`](CONTRIBUTING.md) for the issue-to-merge path: a branch from `main` linked to the issue and a pull request to `main`. It also maps each branch to the environment its pull request's test plan is run on. Start each pull request body from [`.github/pull_request_template.md`](.github/pull_request_template.md).

### Rules

- `adversarial-review`: Adversarially verify before it reaches anyone else. Full text: [`.claude/rules/adversarial-review.md`](.claude/rules/adversarial-review.md).
- `american-english-prose`: American English for prose, and a dictionary entry that overrides it needs a stated reason. Full text: [`.claude/rules/american-english-prose.md`](.claude/rules/american-english-prose.md).
- `an-empty-result-is-not-evidence`: A result is not evidence until the instrument has been shown to tell the two answers apart. Full text: [`.claude/rules/an-empty-result-is-not-evidence.md`](.claude/rules/an-empty-result-is-not-evidence.md).
- `closing-a-ticket`: Finish a ticket where it is visible, not just where it builds. Full text: [`.claude/rules/closing-a-ticket.md`](.claude/rules/closing-a-ticket.md).
- `coordination-plumbing-stays-out-of-artifacts`: Keep session-coordination plumbing out of GitHub artifacts and documentation. Full text: [`.claude/rules/coordination-plumbing-stays-out-of-artifacts.md`](.claude/rules/coordination-plumbing-stays-out-of-artifacts.md).
- `cutting-releases`: Cut a release each business day at 10:00 Central when there is unreleased work. Full text: [`.claude/rules/cutting-releases.md`](.claude/rules/cutting-releases.md).
- `design-decision-forks`: Presenting design-decision forks (lead with a compare/contrast). Full text: [`.claude/rules/design-decision-forks.md`](.claude/rules/design-decision-forks.md).
- `filing-defects-across-repos`: File a defect in the repository that owns it, and claim it first when a race is likely. Full text: [`.claude/rules/filing-defects-across-repos.md`](.claude/rules/filing-defects-across-repos.md).
- `github-api-budget`: Spend the REST quota to preserve the GraphQL one. Full text: [`.claude/rules/github-api-budget.md`](.claude/rules/github-api-budget.md).
- `impersonal-voice-in-github-artifacts`: Write GitHub artifacts in an impersonal voice. Full text: [`.claude/rules/impersonal-voice-in-github-artifacts.md`](.claude/rules/impersonal-voice-in-github-artifacts.md).
- `keep-the-download-to-what-runs`: Keep a WordPress download to what the plugin runs. Full text: [`.claude/rules/keep-the-download-to-what-runs.md`](.claude/rules/keep-the-download-to-what-runs.md).
- `long-running-commands`: Bound every long-running command, and resolve what you kill. Full text: [`.claude/rules/long-running-commands.md`](.claude/rules/long-running-commands.md).
- `measurement-parity`: Mirror the *job*, not the command, before comparing to a baseline. Full text: [`.claude/rules/measurement-parity.md`](.claude/rules/measurement-parity.md).
- `no-em-dashes`: No em dashes in prose, docs, comments, or strings. Full text: [`.claude/rules/no-em-dashes.md`](.claude/rules/no-em-dashes.md).
- `no-emoji-in-durable-records`: No emoji in durable records. Full text: [`.claude/rules/no-emoji-in-durable-records.md`](.claude/rules/no-emoji-in-durable-records.md).
- `pre-merge-check`: Re-verify at the merge, not only at the build. Full text: [`.claude/rules/pre-merge-check.md`](.claude/rules/pre-merge-check.md).
- `reading-exit-status`: Read the result, not the exit code. Full text: [`.claude/rules/reading-exit-status.md`](.claude/rules/reading-exit-status.md).
- `supported-platforms`: MacOS and Windows are both supported; name the platform, write paths repo-relative. Full text: [`.claude/rules/supported-platforms.md`](.claude/rules/supported-platforms.md).
- `sync-pr-branch`: Sync every PR branch from `origin/main` before the gating run. Full text: [`.claude/rules/sync-pr-branch.md`](.claude/rules/sync-pr-branch.md).
- `worktrees`: Work from stable, pre-bootstrapped worktree slots (branch work never happens in the primary checkout). Full text: [`.claude/rules/worktrees.md`](.claude/rules/worktrees.md).

Skills: [`code-quality`](.claude/skills/code-quality/SKILL.md), [`decision-log`](.claude/skills/decision-log/SKILL.md), [`download-archive-check`](.claude/skills/download-archive-check/SKILL.md), [`github-wiki`](.claude/skills/github-wiki/SKILL.md), [`handed-back-label`](.claude/skills/handed-back-label/SKILL.md), [`local-ci`](.claude/skills/local-ci/SKILL.md), [`pcov-setup`](.claude/skills/pcov-setup/SKILL.md), [`pest-testing`](.claude/skills/pest-testing/SKILL.md), [`php-coding-standards`](.claude/skills/php-coding-standards/SKILL.md), [`php-documentation`](.claude/skills/php-documentation/SKILL.md), [`phpunit-to-pest`](.claude/skills/phpunit-to-pest/SKILL.md), [`security-audit`](.claude/skills/security-audit/SKILL.md), [`synced-files`](.claude/skills/synced-files/SKILL.md), [`tests`](.claude/skills/tests/SKILL.md), [`update-dependencies-ticket`](.claude/skills/update-dependencies-ticket/SKILL.md), [`upstream-patch-lifecycle`](.claude/skills/upstream-patch-lifecycle/SKILL.md), [`writing-comments`](.claude/skills/writing-comments/SKILL.md), [`writing-commits`](.claude/skills/writing-commits/SKILL.md), [`writing-issues`](.claude/skills/writing-issues/SKILL.md), [`writing-pull-requests`](.claude/skills/writing-pull-requests/SKILL.md), [`writing-release-notes`](.claude/skills/writing-release-notes/SKILL.md).

Pre-commit guard: if `.githooks/pre-commit` exists, run `git config core.hooksPath .githooks` once per clone, or the hook never runs. The local CI runner warns when this is missing.
<!-- uams-claude-skills:end -->

## Foundational context

- **WordPress plugin**, standalone. No application framework — no service container, no ORM, no framework test helpers.
- **PHP 8.2 is the supported floor** (production runs 8.2.30); development and the test suites run on **PHP 8.4** (D-092). **WordPress 6.8.7** in production.
- Runs on a **multisite network** or a **single-site install** (`news.uams.edu` is one). Every endpoint is site-scoped; on a single site `?site=` is refused with a `400`, and the token is stored in `wp_options`, the table `GET /options` serves (#14).
- Consumer is [`UAMS-Web/wordpress-importer`](https://github.com/UAMS-Web/wordpress-importer). Epic: [`wordpress-importer#358`](https://github.com/UAMS-Web/wordpress-importer/issues/358).
- Local development runs under Herd at `uamshealthmu.test`, with the plugin checked out into `wp-content/plugins/`.

## Why this exists

Three findings retired WXR as the importer's source, and nearly every design rule below follows from them:

1. **Large sites cannot export.** WordPress' exporter builds the whole XML in one request and does not complete on `uamshealth.com`. Pagination solves this by construction.
2. **WXR carries stale ACF values for `location` and `provider`.** Find-a-Doc disables `store_acf_values_in_core_meta`, so current values live only in ACF custom tables. `acf_get_value()` reads them with no write to production.
3. **WXR has no element for options.** Zero of 259 captured exports contain `<wp:option>`. Every site migrated to date silently dropped its settings.

## Design rules

These are load-bearing. Breaking one produces a well-formed response full of wrong data, which is this project's defining failure mode.

- **Raw, not curated.** Return stored values un-resolved and un-composed. No rendering, no system+local merge, no hardcoded section composition. The existing `wp/v2` syndication view is what happens otherwise, and it is lossy for migration. The one exception is the `identifiers` block, which adds plain-string identifiers beside the raw shapes and changes neither (D-104).
- **Read through each plugin's own API, never raw SQL.** `acf_get_value()` for ACF, `GFAPI` for Gravity Forms, the `Frm*` models for Formidable. The plugin's API is the only version-safe reader of its own schema.
- **Never resolve an ACF field type.** `acf_get_field_type()` returns a `stdClass` placeholder for `maskfield` and `null` for `button` on this network — two shapes, so a guard for either misses the other. `includes/ACF/FieldReader.php` dispatches on the field *definition* (does it carry `sub_fields`?) and never asks a type for behavior, which makes the tolerance structural. `tests/Unit/AcfApiGuardTest.php` holds `includes/` to an allowlist of four ACF functions. See `docs/DECISIONS.md` D-031.
- **For ACF that means the value functions, never the REST layer.** `acf_get_value()`, `acf_get_field_groups()` and `acf_get_fields()` only. Nothing in `includes/rest-api/` is reachable from this namespace — it matches `/wp/v2/…` routes exclusively — it cannot address options pages at all, and `acf_format_value_for_rest()` is unguarded enough to raise a fatal on this network. Do not call it, and do not change any `show_in_rest` setting. See `docs/DECISIONS.md` D-024.
- **Never filter underscore-prefixed keys.** ACF field-key references (`_fieldname`, `_options_<field>`), navigation (`_menu_item_*`), and featured images (`_thumbnail_id`) all live behind that prefix. A blanket `NOT LIKE '\_%'` filter silently breaks all three — it is what core's own network site-settings screen does, and copying it loses data.
- **Both meta shapes on every record.** `meta` carries raw flat postmeta exactly as WXR would serialize it, so existing importers run unchanged; `acf` carries structured values, the only shape available for `location` and `provider`.
- **Order by ID, never by date.** A migration pages through a live site while editors work. Date ordering lets a record move between pages mid-run and be read twice or skipped. IDs are immutable.
- **No anonymous access, ever.** The API serves unpublished content and raw site settings.
- **Every response says which site answered it**, in `X-UAMSWP-Site` (D-079, scope corrected by D-080). `?site=` is optional and an omitted one is served from whatever site handled the request, so without the header a consumer that dropped it received a well-formed `200` from another site with nothing to check against. A new controller inherits this from `Site`; it is not per-route work. Errors carry it too over HTTP — and a refusal names the site being **read**, since the switch runs before the auth gate. Internal dispatch (`rest_do_request()`, WP-CLI, the Feature suite) never fires `rest_post_dispatch`, so an error there is unstamped.

## Architecture

```
uamswp-migration-api.php   Plugin header, PHP 8.2 guard, hand-rolled autoloader
.github/workflows/ci.yml   The fourteen-job CI chain, and the `CI` check over it
.github/workflows/clear-handed-back.yml  Removes `handed-back` on a push (#330)
.github/workflows/assign-author.yml  Assigns a pull request and the issues it closes to its author (#332)
.githooks/pre-commit       Two commit gates: CI-parity co-change, unstaged conflict resolution
local-ci.json              The local mirror of ci.yml, run by the shared local CI runner
scripts/
  ci-local.mjs             `npm run ci:local`: the shared runner, with a dotted slot
  prepare-dependencies.mjs Installs in the runner's slot; refuses a stale tree elsewhere
  run-pest-suites.php      Creates the test database, runs the five Pest processes
  pest-suites.php          The five processes, held against `composer test` by CiParityTest
  check-no-vendor-dependency.php  Load guard: no Composer artifact at runtime
  check-floor-functions.php  Floor guard: no shipped call PHP 8.2 lacks behind a polyfill
  setup-git-hooks.php      Points core.hooksPath at .githooks
  audit-option-denylist.php  Reviews the denylist against a real options table
  verify-site-scoping.php  Proves `?site=` against the real dev network (#12)
  gen-release-notes.mjs    Release-note body for a range; `--repo` required (#309)
includes/
  ACF/FieldReader.php      The only route to ACF: discover, enumerate, read
  Content/DiscoveryPayload.php The WXR channel header, post types, taxonomies
  Content/OptionsPayload.php  The options table, denylist-filtered, plus ACF's view
  Content/PostPayload.php  One record, as a strict superset of a WXR `<item>`
  Content/IdentifiersPayload.php  The configured identifiers block (D-104)
  Plugin.php               Bootstrap, hook ordering, controller registry
  Auth.php                 Namespace gate + capability check + shared secret
  Site.php                 ?site=<id> resolution, switch and restore
  Config.php               Configuration loading, per-site filtering
  Pagination.php           Bounds, offset, stable ordering, count headers
  REST/Controller.php      Base controller; wires auth and the site param
  REST/IndexController.php Namespace index; reports what this deployment serves
  REST/DiscoveryController.php `GET /sites`, `/post-types`, `/taxonomies`
  REST/OptionsController.php `GET /options`
  REST/PostsController.php `GET /posts/<type>` and `/posts/<type>/<id>`
  REST/FormsController.php `GET /forms/gravity` and `/forms/gravity/<id>`
  REST/RedirectsController.php `GET /redirects/redirection` and `/redirects/pretty-links`
  REST/*Controller.php     One per remaining resource
config/defaults.php        Eligible post types, taxonomies, option denylist
tests/
  Unit/                    No WordPress. Pest 5, from the root Composer setup.
  Integration/             Its own Composer setup: Pest 3 / PHPUnit 11 (D-092)
    bootstrap.php          Boots WordPress; refuses to run before `composer setup:integration`
    wp-tests-config.php    Test database and network settings, all env-overridable
    Pest.php               Binds the WordPress test case to Feature/
    Feature/               Boots WordPress and dispatches real REST requests.
    Mutate/                Holds the mutate plugin's patches against its vendored copy
  Support/                 Test case, typed helpers, PHPUnit 9 class placeholders
```

**Hook ordering is deliberate and load-bearing:**

- `Site::switchForRequest()` on `rest_pre_dispatch` at **priority 10**
- `Auth::guardNamespace()` on `rest_pre_dispatch` at **priority 11**

Auth runs second so capability checks apply to the site being *read*, not the one handling the request. Do not reorder.

**The namespace is gated rather than each route** because core's auto-generated namespace index route has no permission callback; the gate also stops a new controller shipping an unauthenticated route.

**Machine-to-machine access needs a constant nobody sets by accident.** `Auth` takes either a WordPress user holding the capability on the site being read, or a shared secret in `UAMSWP_MIGRATION_API_SECRET`, presented as `X-UAMSWP-Migration-Key`. Until that constant is defined the secret path is inert, and **every failure returns an identical `401`** — unset, mismatched, mistyped and correctly-refused are indistinguishable. `README.md` carries the procedure and the three-request verification, because the person setting it is usually a human under time pressure rather than an agent; don't duplicate it here.

**Defining it does not affect the suite.** `tests/Integration/bootstrap.php` reads the live `wp-config.php` for `DB_NAME` alone — to refuse a run that would drop the development database — and never loads it as PHP. That is what lets `test:integration` and `test:integration:secret` be two processes: the branch where no secret is configured must authorize nothing, and it is reachable only while the constant is undefined.

**The autoloader stays hand-rolled.** Composer is dev-time only — the plugin must run on a stock WordPress install with no `vendor/` directory.

## Toolchain

```bash
npm run ci:local -- --branch <b>   # the full CI chain, in a worktree
composer ci              # phpstan, pint --test, pest — a SUBSET, see below
composer stan            # PHPStan at level max
composer setup:integration  # install tests/Integration's own setup, once per tree
composer test            # all five test processes
composer test:unit       # the suite that runs with no WordPress
composer test:integration    # the suite that boots WordPress, as a network
composer test:integration:single-site  # the same suite, as a single site
composer mutate          # mutation testing over the pure-logic classes
vendor/bin/pint          # format
composer test:refactor   # Rector preview (gating profile)
npm run spell            # cspell
```

- **`npm run ci:local` is the full local chain; `composer ci` is a subset of it.** It runs the shared local CI runner (`.claude/skills/local-ci/local-ci.mjs`) over the jobs in [`local-ci.json`](local-ci.json): the fourteen chained `ci.yml` jobs in the same cost order, plus the local-only `dependencies` job. With `--branch` it validates the remote head in the dotted slot `.uamswp-migration-api-local-ci`, so your checkout never moves. `composer ci` runs three of the jobs in place. Run the chain before opening a PR. See `.claude/skills/local-ci` and D-091, which records the move from this repository's own runner (#366).
- **`main` requires three checks, the same three the Statamic add-ons require: `CI`, `Attribution footer` and `Closing keywords`** (D-105). `CI` is the last job in `ci.yml`; it needs every other job and fails unless each one succeeded, so a skipped or cancelled job fails it. Add a new job to its `needs:` list in the same change; `CiParityTest` fails otherwise. The other two are the jobs of `check-attribution-footer.yml` and `check-closing-keyword-across-lines.yml`. The ruleset carrying them reaches this repository alone and layers on the two organization rulesets (no deletion or force push, and pull request required). The local chain does not gate a merge. Every job but `CI` carries `if: vars.RUN_REMOTE_CI != 'false'`: the organization's variable is scoped to private repositories and does not reach this one, so only a repository variable switches the chain off, and `CI` then fails rather than passing (D-101 and D-105).
- **Two Composer setups, one PHP.** `tests/Unit` runs on Pest 5 from the root; the suite that boots WordPress runs from `tests/Integration/` on Pest 3 / PHPUnit 11, because WordPress' test bootstrap needs `yoast/phpunit-polyfills`, which stops at PHPUnit 12 while Pest 5 needs 13. Both run on PHP 8.4 (`config.platform.php` is `8.4.1`). Nothing runs on the 8.2 floor except the `lint-floor` job, which parses every shipped file with it, and the `floor-functions` job, which fails on a shipped call to a function 8.2 lacks that only a development polyfill defines (D-093). See D-092. Staying on PHPUnit 11 rather than the shared standard's 9.6 is a documented exception that ends when the plugin is deleted (D-094).
- **`tests/Integration/Feature` needs a database**, created once with `mysql -e "CREATE DATABASE uamswp_migration_api_tests"`. It is dropped and rebuilt on every run; `tests/Integration/bootstrap.php` refuses a name matching the working install's.
- **`UAMSWP_MIGRATION_API_TESTS_GF_FIXTURES` runs the Gravity Forms fixture-parity test**, pointed at the importer's `src/Services/WordPressImport/Tests/Exports/GravityForms`. It imports a real committed export and asserts it reproduces through the API — fields, notifications, confirmations and conditional logic. Unset, it skips: those exports are production form definitions carrying real notification recipients, so they are not vendored here. See `docs/DECISIONS.md` D-043.
- **The suite loads Formidable Forms and Formidable Pro when the surrounding install has them**, and installs their tables itself — Formidable creates them on *activation*, and the bootstrap never activates anything, so it calls `FrmMigrate` once after WordPress is installed. Pro is not optional decoration: ~23% of the captured corpus uses Pro-tier field types, and child forms exist only because Pro creates them. `UAMSWP_MIGRATION_API_TESTS_FRM_FIXTURES` runs the fixture-parity test against the importer's committed exports. See `docs/DECISIONS.md` D-046.
- **The suite loads Gravity Forms when the surrounding install has it**, and skips the tests that need it when it does not — paid, not a Composer package, so CI never has it, exactly as with ACF PRO. It boots unaided and installs its own tables into the test database. `tests/Integration/Feature/Forms/GravityFormsGuardTest.php` reds a run that had it on disk and failed to load it, so the skip cannot become permanent by accident; that file and `GravityFormsEndpointTest.php` skip on opposite conditions so every branch is covered somewhere. Note the local chain does **not** take the plugin-absent branch — its worktree sits inside `wp-content/plugins`, so it finds the real plugin exactly as your checkout does; only remote CI takes that branch, and remote CI is gated off. See `docs/DECISIONS.md` D-044.
- **The suite loads ACF, preferring PRO.** The bootstrap takes ACF PRO from the surrounding install when it is there and the `wpackagist-plugin/advanced-custom-fields` dev dependency when it is not — so a developer's run gets `repeater` and `flexible_content` while CI, which can never have a paid plugin, still runs everything portable. A PRO-only test carries an explicit `skip()`, and `tests/Integration/Feature/ACF/AcfEditionGuardTest.php` reds a run that loaded free while PRO was on disk. See `docs/DECISIONS.md` D-030.
- **Never run two suites concurrently.** They share one test database; the collision produces failures that read exactly like real regressions.

- **PHPStan runs at `level: max`** with `bleedingEdge.neon`, over `includes/`, `config/` **and `tests/`**, and carries an explicit `phpVersion` range from the PHP 8.2 floor. With the suites on 8.4 that range, Rector's `withPhpVersion`, and the `lint-floor` and `floor-functions` jobs are what hold the floor: a construct 8.2 cannot parse never reaches a test runner that runs on 8.2. It also reads `tests/Support/stubs/pest-closure-this.stub`, which restores the `$this` binding Pest 3 declared for test closures. See `docs/DECISIONS.md` D-013, D-015 and D-092.
- **Pint uses the `laravel` preset**, which is PSR-12 plus that preset's house style. Deliberate and settled: it unwinds Yoda conditions, prefers short arrays, and strips `@package` tags, all against WordPress Coding Standards. See `docs/DECISIONS.md` D-014. Do not relitigate.
- **American spelling** in all first-party prose, comments, and identifiers, enforced by `cspell.json`'s `"language": "en"`. A `project-words.txt` entry that overrides it needs a stated reason — tool vocabulary a gate itself runs, or generated/verbatim-quoted data that must mirror an external vocabulary, are the two known legitimate categories; anything else is a spelling to fix, not an entry to add.

History: [.claude/history/AGENTS.md](.claude/history/AGENTS.md#toolchain).

## Working on this repo

- **Every issue carries exactly one of `afk` / `hitl`.** See `.claude/skills/writing-issues`.
- **Issues and PRs follow the house style** in `.claude/skills/writing-issues` and `.claude/skills/writing-pull-requests`. Bodies go through `--body-file`, never inline `--body`.
- **Merged head branches are never deleted**, because pull-request bodies link head-branch blob URLs. See D-077.
- **The project board** is `UAMS-Web/projects/1` (Statamic Migration MVP Development). Set Status, Priority, Size *and* Estimate, Phase, and Iteration — Estimate does not derive from Size.
- Work in this repo is Phase **`I: Importer & Test Imports`**.

## Skills and rules

**This file is the top of the instructions, not all of them.** The rules and skills listed under *Shared skills and rules* above are standing orders and procedures; read the rules that bear on a task before starting it, and open the skill for the task in hand. `CLAUDE.md` imports this file. What this repository adds to `pcov-setup` is in [`docs/mutation-testing.md`](docs/mutation-testing.md), and to `security-audit` in [`docs/security-review.md`](docs/security-review.md).

Both sets **describe this repo only** — no comparisons to other repositories, no guidance ahead of the thing it describes — and **are added at the point of need**, against what actually exists. [`filing-defects-across-repos`](.claude/rules/filing-defects-across-repos.md) governs where a defect lands when the fix is not this repo's, which is this repo's own decision.

Decisions that outlive their ticket are recorded in [`docs/DECISIONS.md`](docs/DECISIONS.md). Evidence behind this file is in [`.claude/history/AGENTS.md`](.claude/history/AGENTS.md), not auto-loaded.

## Running it locally

This is a WordPress plugin, so it needs a WordPress install to run in, and the verification scripts below need a multisite one. If you do not already have one, the team's [`wp-to-herd`](https://github.com/UAMS-Web/uams-claude-skills/tree/main/wp-to-herd) skill stands one up in Laravel Herd on Windows or macOS, from either a Local by Flywheel directory or a production backup, and covers the multisite case specifically. It lives in [`UAMS-Web/uams-claude-skills`](https://github.com/UAMS-Web/uams-claude-skills), installed opt-in through that repo's `install.sh`.

The development network that `scripts/verify-site-scoping.php` and `scripts/verify-taxonomy-coverage.php` expect is that install; both are listed in [`docs/known-gaps.md`](docs/known-gaps.md) as verifications that cannot run on CI.

## Known gaps

What the plugin does not yet do, or has not yet proven, is listed in [`docs/known-gaps.md`](docs/known-gaps.md): unverified readers, verifications that need the development network, and the boundaries guards hold. Read it before calling any behavior verified against production.
