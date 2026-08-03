# ForgeMover

![](assets/unsplash.mover.jpg)

Modern Laravel 13 port of the ForgeOps single-file dashboard: manage your
Laravel Forge / DigitalOcean fleet and relocate Laravel sites between servers.

- **Filament v5** admin panel (`/admin`): servers, sites, relocations, package-credential
  copying, fleet dashboard — Font Awesome icons throughout.
- **Queued jobs** for everything long-running: the 4 script steps of a site relocation
  (`collect → transfer → restore → verify`, driven by the repo's `bin/relocate-laravel.sh`),
  ssh fact probes and Forge API syncs.
- **Scheduled tasks**: hourly server probes, 6-hourly Forge sync, daily site-type detection.
- **Relocation wizard**: the 8-step guided flow (preflight → forge site → collect → transfer →
  restore → deploy test → verify → cutover) with auto-checked preconditions (PHP on target,
  DNS TTL, storage:link, live DNS cutover), live log streaming and run history.

## First run

```bash
cd laravel
composer install && npm install && npm run build
php artisan forgemover:setup     # fool-proof interactive setup
```

…or start the stack and open <http://127.0.0.1:8600/setup> for the web wizard.
Both check the required binaries (`ssh`, `dig`, `curl`, …), validate the Forge API token
live against the API before saving it, create the admin user and run the first sync.

## Running

```bash
# using https://github.com/pforret/gloncher
gloncher gloncher.ini    # web + queue worker + scheduler in one terminal
```

or the three processes by hand: `php artisan serve --port=8600`,
`php artisan queue:work --timeout=7200` and `php artisan schedule:work`.

## Configuration

See the `ForgeMover` block in `.env.example`. Secrets: `FORGE_API_TOKEN` (validated at
setup), `FORGE_ORG` (auto-detected for single-org tokens). ssh access uses `~/.ssh/config`
host aliases with passwordless keys — server names must match those aliases. The
operational scripts stay in the repo root `bin/` (override with `FORGEMOVER_BIN_PATH`).

## Tests

```bash
php artisan test    # Pest: unit + feature + Filament panel smoke tests
vendor/bin/pint     # before committing
```
