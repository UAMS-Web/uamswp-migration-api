# Security review in this repository

The shared [`security-audit`](../.claude/skills/security-audit/SKILL.md) skill carries the checklist of insecure code patterns, and the forms they take in this plugin. Read it first. This file holds only what is specific to this repository, which that skill does not carry. It replaces the local `security-patterns` skill (#367).

## Where the surfaces are

- **Access control, including reading a site the caller may not,** is the auth gate and site scoping: [`includes/Auth.php`](../includes/Auth.php) and [`includes/Site.php`](../includes/Site.php). The [`adversarial-review`](../.claude/rules/adversarial-review.md) rule names both as high-risk surfaces.
- **Leaking a credential through the options endpoint** is the option denylist in [`config/defaults.php`](../config/defaults.php). It is a denylist, so the failure direction is a credential served, not a value dropped.
- The design rules these rest on are in [`AGENTS.md`](../AGENTS.md).

## Patterns the shared skill names that do not occur here

The shared skill's checklist, and the domain finders in its [`security-audit.workflow.js`](../.claude/skills/security-audit/security-audit.workflow.js), name framework and front-end forms this plugin does not have. Each row was checked against `includes/`, `config/`, `scripts/`, `tests/`, the plugin file and `.github/`, at the commit that added this file:

| Pattern | Why it does not apply |
| --- | --- |
| Blade `{!! !!}`, Antlers raw output, Alpine `x-html`, Vue `v-html` | No Blade, Antlers or front-end framework |
| `.innerHTML`, `.outerHTML`, `.insertAdjacentHTML()`, `document.write()` | The plugin ships no browser JavaScript. Its Node scripts run in a terminal, not a page |
| `<script src>` without an `integrity` attribute | The plugin enqueues no scripts and prints no `<script>` tag |
| Laravel `Process::run()` and Symfony `Process::fromShellCommandline()` with a string | No Laravel. Symfony Process arrives only through the test tooling's own dependencies, and every use of it in `tests/` passes an array command |
| `Blade::render()` or an Antlers parse of a string | No template engine |
| Symfony `Yaml::parse()` with `Yaml::PARSE_OBJECT` | Nothing parses YAML, and the plugin loads no Composer dependency at runtime |
| Laravel `Crypt` and `encrypt()` | No Laravel |
| `Http::withoutVerifying()` and Guzzle's `'verify' => false` | No Laravel HTTP client or Guzzle |
| `env('KEY', '…')` defaults | No `env()` helper |

A row stops being true when the plugin gains the thing it names. Re-check the row then, rather than trusting this table.
