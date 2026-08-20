<!--
  - SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

# AGENTS.md

DB Doctor is a Nextcloud admin app (`OCA\DBDoctor`, PHP ≥ 8.3, Nextcloud 33–35) that audits
the MySQL / MariaDB / PostgreSQL database behind a Nextcloud server against advisor rules,
grades the result A–F, and can apply an allow-listed set of runtime fixes. It is read-only by
default and admin-only.

## Commands

Frontend (Node ≥ 20, standalone — no server checkout needed):

```bash
npm ci
npm run build        # production build → committed js/ + css/
npm run dev          # development build (npm run watch to rebuild on change)
npm run lint         # eslint over src/ (lint:fix to autofix)
npm test             # vitest (only src/**/*.spec.ts)
npx vitest run src/utils/grade.spec.ts   # single frontend test file
```

PHP:

```bash
composer install
composer test        # phpunit --configuration tests/phpunit.xml
composer psalm       # static analysis — informational, see below
vendor/bin/phpunit -c tests/phpunit.xml tests/Unit/Service/ScoreTest.php   # single test class
vendor/bin/phpunit -c tests/phpunit.xml --filter testMethodName            # single test method
```

**PHP tests only run when the repo is checked out inside a Nextcloud server tree at
`<server>/apps/dbdoctor`.** `tests/bootstrap.php` resolves `OCP\` classes from
`../../../lib/public/` and optionally loads `../../../3rdparty/autoload.php`; CI
(`.github/workflows/ci.yml`) reproduces exactly this layout (PHP 8.3 + 8.4 matrix). Psalm runs
with `continue-on-error` because `NextcloudSchema` references private `OC\DB` classes that the
OCP stubs don't ship — don't chase those specific errors.

## Architecture

### Backend (`lib/`)

All HTTP routes are **OCS routes** (`appinfo/routes.php`, `/ocs/v2.php/apps/dbdoctor/api/v1`).
Layering is Controller → Service → `Db/` mapper; the four controllers
(`Check`, `Apply`, `Insights`, `Settings`) each re-check group-admin membership on top of the
admin-settings gate. Two tables: `dbdoctor_history` (runs; `results_json`, gzip+base64 above
32 KiB) and `dbdoctor_audit` (every apply attempt).

The **Advisory subsystem** (`lib/Advisory/`) is the core:

- `Rule` — immutable rule data: `formula` (numeric expression), `test` (failure predicate),
  issue/recommendation/justification templates, `requires` (needed snapshot keys), optional
  `ApplyDescriptor` and `detailsKey`.
- `RuleSet\Mysql` is ported from phpMyAdmin's advisor (upstream rule `id`s preserved for future
  syncs — see the REUSE.toml note on its licence); `RuleSet\Postgres` is original. Both mix in
  the `NextcloudSchemaRules` **trait** for the shared `nc.*` schema rules.
- `ExpressionEvaluator` — hand-written parser for the phpMyAdmin expression DSL. No `eval()`;
  identifiers must exist in the context map, functions come from a hard-coded whitelist,
  division by zero yields `INF` (phpMyAdmin compatibility).
- Evaluation path: `DatabaseProbe::snapshot()` builds an immutable `Snapshot`, whose
  `context()` (derived + variables + status, derived wins) is the expression context.
  `Service\Advisor::run()` evaluates each rule against it — skipping rules with missing
  `requires` keys, and skipping counter-derived rules while `Uptime_hours < 24` so fresh
  restarts don't produce false alarms. Every failure path degrades to a `skipped` result plus
  a log warning rather than throwing.

Key service boundaries: `DatabaseProbe` is the **only** gateway to the database — it detects
the flavour, honours the optional override connection (separate credentials, password
encrypted via `ICredentialsManager`, host restrictable via the
`dbdoctor.allowed_override_hosts` system config), and exposes `withConnection()` so flavour
detection and the operation happen on the same server. `NextcloudSchema` deliberately uses the
default Nextcloud connection instead (it answers questions about the Nextcloud schema, firing
the same events as `occ db:add-missing-*`). `HistoryService` persists runs (collapsed to one
row per flavour+hour), `Score` computes the grade, `RevertedFixService` diffs the audit log
against live values to spot fixes lost to a restart.

Non-HTTP entry points: `occ dbdoctor:check` (`Command/Check`, Nagios exit codes), the weekly
`ScheduledCheck` and daily `PruneHistory` background jobs, the `DatabaseHealth` setup check
(Settings → Overview), and `Notification/Notifier` for the health-declined notification.

**Security-critical:** `ApplyService::ALLOW_LIST_MYSQL` / `ALLOW_LIST_PGSQL` are hard-coded
constants gating what `SET GLOBAL` / `ALTER SYSTEM` may touch. Never derive them from rule
data or request input; variable names reach SQL only after passing the allow-list, and values
are shape-validated because those statements reject bind parameters. Mutating endpoints carry
`#[PasswordConfirmationRequired]` (mirrored client-side with `confirmPassword()`).

### Frontend (`src/` — Vue 3, Pinia, TypeScript)

`lib/Settings/Admin` provides initial state (`flavour`, `version`) and renders
`templates/admin.php` (a single mount div); `src/main.ts` mounts the SPA with Pinia and a
hash-history router (single route → `views/Dashboard.vue`, the one screen). `api/client.ts`
is a function-per-endpoint wrapper over `@nextcloud/axios` that forces JSON and unwraps the
OCS envelope with descriptive errors. Four setup-style Pinia stores: `checks` (latest run +
per-rule history cache), `latency` (1 Hz ping ring + EMA), `metrics` (3 s live-metric poll),
`settings` (override connection + audit rows). `latency` and `metrics` refcount their
subscribers and pause on `visibilitychange` — polling only runs while a consumer is mounted
and the tab is visible; keep that pattern for any new polling. Dialogs are lazy-loaded via
`defineAsyncComponent`, which is why they appear as separate chunks in `js/`.

Build config is intentionally minimal: `vite.config.mjs` is just
`createAppConfig({ main: './src/main.ts' })` from `@nextcloud/vite-config`, which handles the
Nextcloud conventions (output naming, `js/`/`css/` targets, `.license` sidecars, externals).
Extend via the wrapper's options instead of hand-rolling Vite settings. The eslint flat config
documents its deliberately disabled rules inline — match those conventions rather than
re-enabling them.

## Repo conventions

- **`js/` and `css/` are committed build output.** Any change under `src/` must ship the
  rebuilt bundles (`npm run build`) in the same commit, or the app keeps serving the old code.
  Stale hashed chunks from earlier builds accumulate — prune them when regenerating.
  `l10n/` files land automatically from the Transifex sync; never hand-edit them.
- Commits follow **conventional commits** with a scope (`feat(advisor): …`, `fix(apply): …`)
  and an explanatory body describing the why. Trailer rules are in the policy section below;
  DCO sign-off comes from the human contributor.
- **REUSE/SPDX:** every new file needs an SPDX header in the comment style of its language —
  copyright line `<year> Nextcloud GmbH and Nextcloud contributors`, licence
  `AGPL-3.0-or-later` (copy the header from any neighbouring file); files
  that can't carry one get an annotation in `REUSE.toml`. `lib/Advisory/RuleSet/Mysql.php`
  additionally retains the phpMyAdmin copyright — keep that intact when touching it.

## Nextcloud Contribution Policy

All contributions generated or assisted by this agent must fully comply with:

- **[AI Contribution Policy](https://github.com/nextcloud/.github/blob/master/AI_POLICY.md)** - the primary reference for AI-specific rules, covering disclosure, author accountability, communication, security, licensing, code quality, and autonomous agent behavior.
- **[Contribution Guidelines](https://github.com/nextcloud/.github/blob/master/CONTRIBUTING.md)** - covering testing requirements, the Developer Certificate of Origin (DCO), license headers, conventional commits, and translations. These apply in full to all contributions regardless of how they were produced.

### What this agent must always do

- Add an `Assisted-by: AGENT_NAME:MODEL_VERSION` git trailer to every commit containing AI-assisted content.
- Ensure every pull request includes a disclosure of AI tool use in the PR description.
- Produce focused, scoped pull requests that address exactly one concern. Do not touch unrelated files or introduce incidental refactors.
- Verify all dependencies against actual package registries before suggesting them. Do not use hallucinated or unverified package names.
- Write code comments that document the code, never the process that produced it:
  - Comments describe what the code does - method signatures, behavior, and constraints the code itself cannot express (e.g. a non-obvious invariant or workaround).
  - Never add comments that document progress, decisions, or changes (e.g. "changed X to Y", "as requested", "this fixes ...", "previously this did ..."). That belongs in the commit message or PR discussion; in the code it goes stale and becomes misleading.
  - Do not narrate self-explanatory code. If the code is readable without a comment, omit the comment.
  - Keep comments brief - short and simple, matching the comment density of the surrounding code.
- Reuse existing helper functions and utilities instead of re-implementing their logic inline. When fixing a flawed pattern, fix every occurrence of it across the changed code, not only the instance that was pointed out.
- Run permission and access-control checks before the operation they guard, never after it and never only in the UI layer.
- When adding or changing user-facing functionality, wire it up in every context where the affected component is used - the default authenticated view, public share pages, and embedded contexts such as the Smart Picker and reference widgets. When emitting new events, verify that every consumer of the component subscribes to and handles them.
- Explicitly inform the contributor when any action they are about to take, or have taken, would violate the AI Contribution Policy or the Contribution Guidelines. Do not silently proceed. State which rule is at risk and what the contributor should do instead.
- Warn the contributor if a pull request is growing too large. A PR approaching several thousand lines of changed code is a signal that it should be split into smaller, focused PRs. Suggest a logical split before the PR is opened, not after.
- Recommend opening a ticket for discussion before starting implementation whenever a feature or change is sufficiently complex - for example when it touches multiple subsystems, requires architectural decisions, or the right approach is not yet clear. A ticket allows maintainers and the contributor to align on direction before code is written, avoiding wasted effort on a PR that may be rejected or require fundamental rework.

### What this agent must never do

- Open issues, submit pull requests, post review comments, or send security reports autonomously. Every contribution must be reviewed and submitted by a human.
- Add `Signed-off-by` tags to commits. Only the human contributor can certify the Developer Certificate of Origin.
- Generate or submit security reports without independent human verification. Report verified vulnerabilities via [HackerOne](https://hackerone.com/nextcloud), not as GitHub issues.
- Write PR descriptions, review comments, or issue reports on behalf of the contributor. These must be in the contributor's own words.
- Fully automate the resolution of issues labeled [`good first issue`](https://github.com/issues?q=org%3Anextcloud+label%3A%22good+first+issue%22) or similar beginner-friendly labels.
- Submit code that has not been reviewed and cleaned up by the contributor. Dead code, redundant logic, excessive comments, malformed or garbled characters (e.g. `�` replacement characters), and unrelated changes must be removed before submission.
