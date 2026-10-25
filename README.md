# uamswp-migration-api

Read-only migration API for the UAMS WordPress network. Serves raw, current content to the [Statamic importer](https://github.com/UAMS-Web/wordpress-importer) in place of hand-made WordPress XML (WXR) exports and plugin export files.

**Status: built.** Every endpoint this README describes is served, and the plugin ships as versioned pre-releases. The migration it serves is tracked by [`wordpress-importer#358`](https://github.com/UAMS-Web/wordpress-importer/issues/358) (epic).

## Why this exists

The importer originally sourced from WXR exports. Three findings retired that approach:

1. **Large sites cannot export.** WordPress' exporter builds the entire XML in one request; on `uamshealth.com` it does not reliably complete. Pagination solves this by construction.
2. **WXR carries stale ACF values for `location` and `provider`.** Find-a-Doc disables `store_acf_values_in_core_meta`, so those values live only in ACF custom database tables while postmeta sits frozen at whatever it held when the filter was enabled. Reading through `acf_get_value()` returns current values with no write to production.
3. **WXR has no element for options.** Zero of 259 captured exports contain `<wp:option>`. Every site migrated to date has silently dropped its settings.

Form definitions (Gravity Forms, Formidable) and MinervaKB configuration have the same defect from a different direction — they come from export files a human produces by hand, stale the moment they are committed.

## Design principles

- **Strict superset.** Everything a WXR export emits, this API emits — mapped element for element — plus the gaps above.
- **Raw, not curated.** Values are returned un-resolved and un-composed. No rendering, no system+local merge, no hardcoded section composition. The existing `wp/v2` syndication view is what happens otherwise, and it is lossy for migration.
- **Read through each plugin's own API, never raw SQL.** `acf_get_value()` for ACF, `GFAPI` for Gravity Forms, the `Frm*` models for Formidable. The plugin's API is the only version-safe reader of its own schema.
- **Never filter underscore-prefixed keys.** ACF field-key references (`_fieldname`, `_options_<field>`), navigation (`_menu_item_*`) and featured images (`_thumbnail_id`) all live behind that prefix. A blanket `NOT LIKE '\_%'` filter silently breaks all three.
- **Both meta shapes on every record.** `meta` carries raw flat postmeta exactly as WXR would serialize it, so existing importers run unchanged; `acf` carries structured values, which is the only shape available for `location` and `provider`.

## Scope

Post types, taxonomy terms, options, authors, attachments, Gravity Forms / Formidable / Ninja Forms definitions, and Redirection / Pretty Links redirect rules — network-wide, configuration-driven, paginated, authenticated, serving non-`publish` statuses and incremental re-pulls via `modified_after`.

## Giving a consumer access

Every route needs one of three credentials: a token issued from this plugin's own admin page, a WordPress user holding the capability on the site being read, or a shared secret in `wp-config.php`. There is no anonymous path — the API serves unpublished content and raw site settings.

**Use the token.** It is what this migration deploys, and it is the only one of the three that is scoped to a single network, revocable on its own, and carries no WordPress identity at all. The shared secret is one value across every network with no per-consumer revocation; an application password would grant `manage_network_options` — write-capable network administration — in order to perform a read.

**Read this part before anything else: every failure returns an identical `401`.** An unset constant, a mismatched value, a typo, and a correctly-refused anonymous request are indistinguishable from the outside. That is not a flaw — the API declines to tell an unauthenticated caller which of those it is — but it means a misconfigured install looks exactly like a working one that you forgot to send a key to, and you cannot debug it by staring at the response.

### Issue a token from the admin page

**Settings -> Migration API token**, in the network admin on a multisite (`/wp-admin/network/settings.php?page=uamswp-migration-api-token`) and under Settings on a single-site install. It needs `manage_network_options` on a multisite and `manage_options` otherwise — the page reads the same expression the API does, so the two cannot drift.

Press **Generate token**. The token is displayed once, on that page, in the response to the press. It is not stored and not recoverable: only a SHA-256 hash of it is written. If you lose it, generate another.

Paste it into the consumer's configuration for that source site. It travels in the same `X-UAMSWP-Migration-Key` header as the shared secret below, so a consumer already configured for one needs no change to accept the other.

**Regenerating keeps the old token working for an hour.** That window exists so a rotation is not a synchronized edit: press regenerate, then update the consumer at any point before it closes, with no refused request in between. The page shows the previous token's identifier and when it stops being accepted. Filter `uamswp_migration_api_token_rotation_window` to change the duration; zero means no overlap.

The page shows an identifier for the current token — not the token itself — so that when several networks are in play you can tell which credential a consumer holds without having the credential.

### The shared secret, for the path that predates the token

Set the constant in `wp-config.php`, **above** the `/* That's all, stop editing! */` line:

```php
define( 'UAMSWP_MIGRATION_API_SECRET', '…' );
```

Generate it with something that is not a word, and never let it reach a repository, a ticket, a chat log or your shell history:

```bash
openssl rand -hex 32
```

The consumer presents it as the `X-UAMSWP-Migration-Key` header.

### Verify with three requests, not one

Whichever credential you set, verify it the same way — `KEY` below is the token or the shared secret.

```bash
SITE=https://example.test
KEY=…

curl -s -o /dev/null -w 'correct key : %{http_code}\n' -H "X-UAMSWP-Migration-Key: $KEY"        "$SITE/wp-json/uamswp-migration/v1/"
curl -s -o /dev/null -w 'no header   : %{http_code}\n'                                          "$SITE/wp-json/uamswp-migration/v1/"
curl -s -o /dev/null -w 'wrong key   : %{http_code}\n' -H "X-UAMSWP-Migration-Key: definitely-wrong" "$SITE/wp-json/uamswp-migration/v1/"
```

Expect `200`, `401`, `401`.

**If `curl` fails on certificate verification, do not reach for `-k`.** It is `--insecure`, and the first of those three requests carries the key — so an operator who points these at a real host and keeps the flag hands the credential to whatever answers, authenticated or not. The commands above deliberately carry no such flag: they work against a local `.test` host because its certificate is trusted in the system keychain, which is the same reason a TLS failure elsewhere is telling you something real. Fix the trust store, or use a host whose certificate validates.

**The third request is the load-bearing one.** A guard that accepted any non-empty header would pass the first two and look perfect — the wrong-key check is the only one that distinguishes *"a key was accepted"* from *"the right key was required"*. `tests/Integration/Feature/SharedSecretTest.php` asserts the same thing (`refuses a wrong, empty or absent secret even when one is configured`), so the behavior is covered in CI as well as verifiable by hand.

### What answering the authentication filter means for a site with its own gate

The plugin reports `true` to `rest_authentication_errors` for a request that presents a recognized credential on a route in this namespace (#290). **That is a report, not an authorization** — `Auth::guardNamespace()` still decides, on the site actually being read, and no WordPress user is signed in by it: `is_user_logged_in()` stays false for the whole request.

**Why it has to say anything at all.** That filter's contract has three values, and silence is not neutral. Returning nothing leaves `null`, which means *no authentication method was attempted* — untrue of a request carrying `X-UAMSWP-Migration-Key`, and a plugin that front-gates the REST API reads `null` and refuses before routing. Measured on a network site that front-gates its REST API with a force-login plugin: a valid network token was refused there while the same token read four other sites on the same network, because that plugin answered first and the plugin had told WordPress nothing.

**The consequence to weigh before deploying to a gated site.** A site whose REST API is closed to the public now has a second credential that gets past that gate — for this namespace only, and only with a valid token or secret. That is the point of the change rather than a side effect, but it is worth stating plainly:

```
before   a front-gated site refused the token; the API was unreachable there
after    a valid token reaches this namespace's routes on that site
          -- every other route on the site is refused exactly as before
          -- an invalid or absent credential is refused exactly as before
          -- another gate's decision, either way, is never overruled
```

**If that is not wanted on a given site, the lever is the credential, not the filter.** Rotate or stop issuing the network token and the path closes with it; there is no configuration in this plugin that makes it answer `null` while still accepting the credential elsewhere.

Two things worth knowing before you set it:

- **Defining it does not affect the test suite.** `tests/Integration/bootstrap.php` reads the live `wp-config.php` for exactly one value — `DB_NAME`, to refuse a run that would drop the development database — and never loads it as PHP.
- **`composer test` runs a third process specifically because the constant is undefined there.** The branch where no secret is configured must authorize nothing, however plausible the presented header, and it is only reachable while the constant stays unset. See `AGENTS.md` and `docs/DECISIONS.md` D-009.

## Running the tests

The suite has two halves. `tests/Unit` runs in a process with no WordPress at all; `tests/Integration/Feature` boots real WordPress through `wp-phpunit` and dispatches real REST requests. `composer test` runs both, plus a third process covering the shared-secret branch that only exists when its constant is undefined.

```bash
composer install
composer setup:integration
mysql -u root -h 127.0.0.1 -e "CREATE DATABASE IF NOT EXISTS uamswp_migration_api_tests DEFAULT CHARACTER SET utf8mb4;"
composer test
```

ACF comes with `composer setup:integration` — the free copy, pinned to the same minor as the PRO the network runs. If this checkout sits inside a WordPress install that has ACF PRO alongside it, the suite loads that instead and picks up the PRO-only field types (`repeater`, `flexible_content`, `clone`); otherwise those few tests skip and say so. See `docs/DECISIONS.md` D-030.

That is the whole setup. WordPress is located from this checkout's own position inside `wp-content/plugins/`, and the database defaults suit a stock Herd MySQL, so there is nothing machine-specific to configure. Override any of it with `WP_TESTS_ABSPATH`, `WP_TESTS_DB_NAME`, `WP_TESTS_DB_USER`, `WP_TESTS_DB_PASSWORD` or `WP_TESTS_DB_HOST`.

**The test database is dropped and rebuilt on every run.** `tests/Integration/bootstrap.php` refuses to start if the name matches the database the surrounding WordPress install uses, because the installer drops every table it finds with no prompt and no dry run.

| Command | What it runs |
| --- | --- |
| `composer test` | Both suites, then the shared-secret process |
| `composer test:unit` | Only the WordPress-free suite |
| `composer test:integration` | Only the suite that boots WordPress |
| `composer mutate` | Mutation testing over the pure-logic classes |
| `composer ci` | PHPStan, Pint, then all of the above |

The suites run on **PHP 8.4**; the plugin supports **PHP 8.2**, the oldest version production runs. `tests/Unit` runs on Pest 5 from the root setup. The suite that boots WordPress has its own Composer setup in `tests/Integration/` (Pest 3 and PHPUnit 11), installed by `composer setup:integration`, because WordPress' test bootstrap needs a polyfill library that does not support the PHPUnit Pest 5 requires. The CI chain's `lint-floor` job parses every shipped file with PHP 8.2, and its `floor-functions` job fails on a shipped call to a function PHP 8.2 lacks that only a development polyfill defines. See `docs/DECISIONS.md` D-092.

## Running CI

```bash
npm install
npm run ci:local -- --branch <branch>
```

That runs the shared local CI runner over [`local-ci.json`](local-ci.json): the same eleven checks GitHub runs (the load guard, the PHP 8.2 floor lint, `composer validate`, the PHP 8.2 check for polyfilled functions, the glyph audit, cspell, Pint, Rector, PHPStan, Pest and Node's own test runner), plus three local-only checks, as a cost-ordered chain that stops at the first failure. The pushed branch is checked out into the dotted sibling worktree `.uamswp-migration-api-local-ci`, so the checkout you are working in never moves, and the `pest` job uses its own `uamswp_migration_api_ci` database so it cannot collide with a suite you are running by hand. The first run in a new worktree needs `composer install` and `composer setup:integration` there once; after that, the runner installs from the locks on every run.

`composer ci` is a **subset**: three of the checks, run in place. Use it while iterating; use the chain before opening a pull request.

The same jobs run on GitHub Actions on every pull request, and `main` requires three checks: `CI`, which fails unless every job in `ci.yml` succeeded, `Attribution footer` and `Closing keywords`. The local chain is for validating a branch before it is pushed; it does not gate a merge. `.github/workflows/ci.yml` and `local-ci.json` are hand-maintained mirrors: a pre-commit hook and `tests/Unit/CiParityTest.php` keep them in step.

## Temporary by design

This plugin exists for the WordPress → Statamic migration. When that completes it is deactivated and deleted — one plugin removed, rather than dead code unpicked from `UAMSWP-Find-a-Doc`.
