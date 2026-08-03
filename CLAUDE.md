# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

ForgeMover manages the Brightfish Laravel Forge / DigitalOcean server fleet and relocates Laravel sites between Forge servers. Laravel 13 + Filament v5 (PHP 8.3), port of a legacy single-file dashboard. The heavy lifting is done by shell scripts (`relocate-laravel.sh`, bashew-style) that live outside this app — by default in `../bin` relative to the app root (`FORGEMOVER_BIN_PATH` overrides, see `config/forgemover.php`).

## Commands

```bash
composer dev                          # serve + queue:listen + pail + vite in one terminal
php artisan test                      # Pest (unit + feature + Filament panel smoke tests)
php artisan test --filter=TestName    # single test
vendor/bin/pint                       # REQUIRED before committing
npm run build                         # Vite assets

php artisan forgemover:setup          # interactive first-run setup (or web wizard at /setup)
php artisan forgemover:sync           # pull servers + sites from Forge API synchronously
php artisan forgemover:probe [server] # ssh-probe server facts
```

Production-style running: `php artisan serve --port=8600` + `php artisan queue:work --timeout=7200` + `php artisan schedule:work` (or `gloncher gloncher.ini`). Tests use in-memory SQLite and the sync queue (see `phpunit.xml`); the app itself uses SQLite with database queue/cache.

## Architecture

**Filament v5 admin panel** at `/admin` (`app/Providers/Filament/AdminPanelProvider.php`) is the entire UI: resources for Servers, Sites, Relocations, plus the custom `RelocationWizard` page and a `Packages` page. Font Awesome icons via `owenvoke/blade-fontawesome`.

**Relocation domain** — the core feature. A `Relocation` = (source server, site, target server) with 8 steps defined in `app/Enums/RelocationStep.php`:

- 4 **script steps** (`collect → transfer → restore → verify`) run `relocate-laravel.sh` via the queued `RunRelocationStep` job (timeout 7200s, 1 try). Output streams to a per-run log file under the relocation's log directory. **bashew scripts always exit 0** — success is detected by the `"<step> done"` marker appearing in the log, not the exit code.
- 4 **manual steps** (`preflight`, `forge_site`, `deploy_test`, `cutover`) are checklists: `RelocationCheck` rows keyed `<step>` or `<step>:<subcheck>` (sub-check keys come from `RelocationStep::subChecks()`). The `forge_site` check's note stores the temp domain; ticking `cutover` sets `finished_at`.

`app/Services/RelocationRunner.php` owns the rules: one running step at a time, delete/retarget guards, and retarget's partial reset (source-side progress kept, target-side reset). `app/Services/WizardData.php` assembles everything the wizard page shows — step cards, auto pass/warn/fail verdicts from cached probes, env template, DNS and DB info.

**Services layer** (`app/Services/`) — all external I/O lives here:

- `ForgeClient` — hand-rolled Forge **API v2** client (org-based, cursor pagination); the official `laravel/forge-sdk` only supports v1 and is not used for fleet sync. `ForgeSync` upserts `Server`/`Site` from it.
- `RemoteProbe` — read-only ssh probes. Uses local `~/.ssh/config` host aliases with BatchMode/passwordless keys; **server names must exactly match ssh host aliases**. It explicitly sets `HOME` and resolves full binary paths because the web server runs with a stripped PATH.
- `DnsService` (shells out to `dig`), `FactsService` (server facts + PHP EOL data), `EnvKeyClassifier` (pure logic — classifies .env keys as rotate/target-specific/carry-over for preflight), `EnvWriter`, `SetupStatus`.
- Probe results are cached with TTLs from `config/forgemover.php` (`forgemover.ttl.*`).

**Settings**: two-tier. Secrets/config in `.env` (`FORGE_API_TOKEN`, `FORGEMOVER_*` — see the ForgeMover block in `.env.example`), plus the `Setting` model as a DB key-value store for runtime-discovered values (`forge_org`, `dns_account:*`).

**Setup flow**: `SetupStatus` is the single source of truth for "is the app ready" — shared by the `forgemover:setup` CLI command and the `/setup` web wizard (`SetupController` + `GuardSetup` middleware). `/` redirects to `/admin` or `/setup` based on it.

**Background work**: everything slow is queued (`app/Jobs/`). Schedules live in `routes/console.php`: Forge sync every 6h, server fact probes hourly, site-type detection daily.

## UI design

`DESIGN.md` describes the target look for the admin panel — an imitation of Laravel Forge's UI — and maps each pattern (theme tokens, header-less list rows, aside settings sections, toggle cards, sub-navigation) to specific Filament 5 components and files in this app. Consult it before building or restyling any Filament page.

## Conventions

- Models use `$guarded = []`; enums (`RelocationStep`, `RunState`) are string-backed and hold their own domain logic (ordering, labels, done markers).
- Run `vendor/bin/pint` before every commit and `php artisan test` before pushing.