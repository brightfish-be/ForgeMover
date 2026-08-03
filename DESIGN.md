# DESIGN.md — Imitating the Laravel Forge look in Filament 5

Goal: make the ForgeMover admin panel feel like Laravel Forge's own UI. This document
translates the four reference screenshots (server detail, server settings, integration
toggles, deployments list) into Filament 5 building blocks and maps each pattern onto
the existing pages of this app.

## 1. The Forge design language

What the screenshots have in common:

- **Calm, flat, light.** Near-white page background (`gray-50`), white cards with a
  1px `gray-200` border, large radius (~12px), no visible shadows. Generous padding
  and row height; dividers (`gray-100/200`) instead of zebra striping.
- **Green as the only accent.** Teal-green for links ("Learn more", "Refresh"),
  success badges ("Connected") and active toggles. Status is otherwise communicated
  with small filled circle icons: green check / red cross / amber warning.
- **Quiet typography.** One sans font (Inter-like), medium-weight `gray-900` titles,
  `gray-500` metadata. Monospace only for commit hashes and branch chips.
- **Primary buttons are near-black**, not brand-colored ("New site").
- **Lists are cards, not grids.** Each record is a tall row inside one bordered card:
  identity tile on the left, name + repo subtitle, meta ("Deployed 4 hours ago")
  right-aligned in gray, and a `⋯` menu. No table header row.
- **Key-value sidebars.** Detail pages put facts in a plain (card-less) right column:
  a small bold section title, then `label value` rows.

## 2. Panel-wide setup

### 2.1 Colors, font, radius

`app/Providers/Filament/AdminPanelProvider.php`:

```php
use Filament\Support\Colors\Color;

$panel
    ->colors([
        'primary' => Color::Emerald,   // Forge's green accent (replaces Indigo)
        'gray' => Color::Zinc,         // cool neutral, matches Forge's grays
    ])
    ->font('Instrument Sans')          // already the app font in resources/css/app.css
    ->darkMode(false)                  // Forge is light-only; keeps the imitation honest
```

### 2.2 Custom theme

Create a panel theme so we can adjust what `->colors()` can't:

```bash
php artisan make:filament-theme admin
```

then register it: `->viteTheme('resources/css/filament/admin/theme.css')` and add the
path to `vite.config.js`. In `theme.css`:

```css
@import '../../../../vendor/filament/filament/resources/css/theme.css';

@source '../../../../app/Filament/**/*';
@source '../../../../resources/views/filament/**/*';

/* Flat cards: rounder, border instead of shadow */
.fi-section {
    border-radius: 0.75rem;
    box-shadow: none;
}

/*
 * Near-black primary buttons, like Forge's "New site". Filament 5 solid
 * buttons take BOTH bg and text from the color palette (`fi-bg-color-400`
 * + `fi-text-color-900` utility classes), so override both or the label
 * stays dark green on black.
 */
.fi-btn.fi-color-primary:not(.fi-outlined) {
    background-color: oklch(0.205 0 0); /* Color::Neutral 900 */
    color: #fff;
}

.fi-btn.fi-color-primary:not(.fi-outlined):hover {
    background-color: oklch(0.269 0 0); /* Color::Neutral 800 */
    color: #fff;
}
```

Secondary buttons next to a black primary get `->color('gray')` explicitly —
header actions default to primary, which would give you two black buttons.

Keep overrides in this file only — never inline style attributes in blades (the
existing `wizard.blade.php` inline styles should migrate here over time).

## 3. Pattern by pattern

### 3.1 Server detail header (screenshot 1, top)

Provider logo tile · server name · "Connected" pill · "App server" subtitle ·
black "New site" button on the right.

- Page: `app/Filament/Resources/Servers/Pages/ViewServer.php` (`ViewRecord`).
- Replace the default heading with a custom header view:

```php
public function getHeader(): ?View
{
    return view('filament.servers.header', ['server' => $this->record]);
}
```

`resources/views/filament/servers/header.blade.php`: flex row with

- a 56×56 rounded-xl tile — DigitalOcean/provider SVG, or fallback to a colored
  initial (same component as site tiles, §3.3);
- `<h1>` name + a `<x-filament::badge color="success" icon="fas-circle-check">`
  bound to `$server->reachable` ("Connected" / "Unreachable");
- muted subtitle from `$server->type` ("App server");
- `$this->getHeaderActions()` slot on the right: a primary (black, see §2.2)
  `Action::make('newSite')`, plus the existing `EditAction` demoted to the `⋯`
  overflow via `ActionGroup::make([...])`.

### 3.2 Main column + facts sidebar (screenshot 1, right)

Forge splits the page ~2/3 list, 1/3 card-less "Details" / "Networking" key-value
column. In Filament 5 this is one infolist schema in
`app/Filament/Resources/Servers/Schemas/ServerInfolist.php`:

```php
use Filament\Schemas\Components\{Grid, Group, Section};

$schema->components([
    Grid::make(3)->schema([
        Group::make([ /* sites card, relation manager stays below */ ])
            ->columnSpan(2),
        Group::make([
            Section::make('Details')->schema([
                TextEntry::make('forge_id')->label('ID'),
                TextEntry::make('type')->label('Type'),
                TextEntry::make('db_version')->label('Database Type'),
                TextEntry::make('region')->label('Region'),
                TextEntry::make('php_default')->label('PHP'),
                TextEntry::make('os_version')->label('Ubuntu'),   // keep EOL badge/tooltip
                TextEntry::make('created_at')->label('Created')->date('M j, Y'),
            ]),
            Section::make('Networking')->schema([
                TextEntry::make('ip_address')->label('Public IP'),
                TextEntry::make('private_ip')->label('Private IP'),
            ]),
        ])->columnSpan(1),
    ]),
]);
```

Two Filament 5 gotchas, learned the hard way:

- **The root schema defaults to 2 columns** — without `$schema->columns(1)` the
  outer `Grid::make(3)` gets squeezed into half the content width and the inline
  labels collide with their values. Same applies to forms using aside sections.
- **`extraAttributes()` lands on a wrapper div** (`.fi-sc-section`), not the
  `.fi-section` element itself — target it with a descendant selector:

```css
.sidebar-plain .fi-section {
    background-color: transparent;
    border: none;
    border-radius: 0;
    box-shadow: none;
    --tw-ring-shadow: 0 0 #0000;
}

.sidebar-plain .fi-section .fi-section-header,
.sidebar-plain .fi-section .fi-section-content {
    padding-inline: 0;
}
```

Entries use `->inlineLabel()` so each row reads `Label  Value` like Forge. The
current 4-column "Server"/"Facts" sections fold into this layout: identity facts go
to the sidebar, health facts (disk, uptime, reachability) fit a stat strip above the
sites list.

### 3.3 Recent sites list (screenshot 1, main column)

This is the signature Forge pattern: header-less rows in one card. Filament tables do
this with **layout columns** — when a `Split` is the top-level column, the header row
disappears automatically. Apply to `app/Filament/Resources/Sites/Tables/SitesTable.php`
(used by both the Sites list and `SitesRelationManager`):

```php
use Filament\Tables\Columns\Layout\{Split, Stack};
use Filament\Support\Enums\FontWeight;

$table
    ->columns([
        Split::make([
            ViewColumn::make('tile')->view('filament.tables.columns.site-tile')
                ->grow(false),
            Stack::make([
                TextColumn::make('name')->weight(FontWeight::SemiBold)
                    ->searchable(),
                TextColumn::make('repository')->color('gray')->size('sm')
                    ->formatStateUsing(fn (Site $r) => trim("{$r->repository}:{$r->branch} · PHP {$r->php_version}", ' ·')),
            ])->space(1),
            TextColumn::make('site_type')->badge()->color('success')
                ->grow(false),                              // "laravel" / "finix" pills
            TextColumn::make('deployed_at')->since()->color('gray')
                ->placeholder('Never deployed')
                ->prefix('Deployed ')
                ->alignEnd(),
        ]),
    ])
    ->recordActions([
        ActionGroup::make([ /* existing actions */ ]),      // renders as the ⋯ menu
    ])
    ->recordUrl(fn (Site $record) => /* site view url */)
    ->paginated([10, 25, 50]);
```

`resources/views/filament/tables/columns/site-tile.blade.php` renders the colored
initial tile: a 40×40 `rounded-lg` div, first letter of the site name, background
hue derived from `crc32($name) % 360` at low saturation (Forge shows pastel
green/orange/blue tiles). One blade partial, reused by the server header (§3.1).

### 3.4 Settings page with left nav (screenshot 2)

Forge's Settings uses a **left sub-navigation** (General / SSH / Network / Redis) —
Filament resources support exactly this. In `ServerResource`:

```php
use Filament\Pages\Enums\SubNavigationPosition;

protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Start;

public static function getRecordSubNavigation(Page $page): array
{
    return $page->generateNavigationItems([
        ViewServer::class,      // overview (screenshot 1)
        EditServer::class,      // "General" settings
        // future: ManageServerSsh::class, ManageServerNetwork::class, …
    ]);
}
```

Each additional group becomes a cheap `EditRecord`-style page — no custom routing.

> **Note:** dropped on the server pages by choice — with only two record pages the
> header's Edit/View buttons cover navigation, and the reclaimed left column lets
> the sites list start flush left. Reach for sub-navigation only when a resource
> grows to three or more record pages (General / SSH / Network-style settings).

**The field rows** (bold label + gray helper text left, input right) are Filament's
*aside* sections. In `app/Filament/Resources/Servers/Schemas/ServerForm.php`, wrap
each field (or tight field group) in:

```php
Section::make('Name')
    ->description('The name used to identify your server.')
    ->aside()
    ->schema([
        TextInput::make('name')->hiddenLabel()->required(),
    ]),
Section::make('Size')
    ->description('The amount of resources allocated to your server.')
    ->aside()
    ->schema([
        TextEntry::make('size')->hiddenLabel(),   // read-only rows mix infolist entries in
    ])
    ->afterHeader([
        Action::make('refresh')->link(),          // Forge's inline green "Refresh"
    ]),
```

One `Section::aside()` per row reproduces the screenshot almost verbatim, including
the divider between rows (sections stack with gaps; a `settings-stack` wrapper class
can collapse them into one card with internal dividers if preferred).

### 3.5 Integration toggle cards (screenshot 3)

Icon · title · description · "Learn more" link · toggle pinned right. This is the
existing `app/Filament/Pages/Packages.php` page's natural layout. Two options:

- **Schema-based** (preferred): give the page a schema of sections, one per
  integration:

```php
Section::make('Sentry')
    ->description('Sentry provides error monitoring and tracing for Laravel applications.')
    ->icon('fas-bug')                 // or a brand SVG via a custom icon component
    ->afterHeader([
        Toggle::make('integrations.sentry')->hiddenLabel()->live(),
    ])
    ->schema([
        TextEntry::make('learn_more')->hiddenLabel()
            ->state('Learn more')->url('https://sentry.io')->openUrlInNewTab()
            ->color('success'),
    ]),
```

- **Blade-based**: in `packages.blade.php`, a stack of `<x-filament::section>` using
  the `afterHeader` slot for the pinned control. The toggle blade component is
  `<x-filament::toggle state="$wire.entangle('copyComposer')" />` — its `state`
  prop is an Alpine expression, so `$wire.entangle()` gives two-way Livewire
  binding. `<x-filament::callout>` covers the compromised-server warning.

The green active state of the toggle comes free once `primary` is Emerald (§2.1).

### 3.6 Deployments / runs list (screenshot 4)

Heading + gray "Settings" button, then one card: search field on top, rows of
status-dot · mono hash · message · right-aligned meta · `⋯`. In this app the
equivalent is the **relocation run history** (and any future deployments table).
Same header-less `Split` recipe as §3.3:

```php
$table
    ->columns([
        Split::make([
            IconColumn::make('state')->grow(false)
                ->icon(fn (RunState $state) => match ($state) {
                    RunState::Success => 'fas-circle-check',
                    RunState::Failed => 'fas-circle-xmark',
                    default => 'fas-circle-notch',
                })
                ->color(fn (RunState $state) => match ($state) {
                    RunState::Success => 'success',
                    RunState::Failed => 'danger',
                    default => 'gray',
                }),
            TextColumn::make('commit_hash')->fontFamily(FontFamily::Mono)
                ->limit(7)->grow(false),
            TextColumn::make('message')->color('gray')->limit(60)->searchable(),
            TextColumn::make('finished_at')->since()->color('gray')->alignEnd()
                ->description(fn ($record) => "from {$record->branch}", position: 'above'),
        ]),
    ])
    ->recordActions([ActionGroup::make([/* view log, retry */])])
    ->searchPlaceholder('Search');
```

The `main` branch chip inside the meta text is a small `<x-filament::badge
color="gray">` with mono font — worth a tiny blade partial (`branch-chip`) since it
recurs.

## 4. Where each pattern lands in this codebase

| Forge pattern | Screenshot | File(s) to change |
|---|---|---|
| Panel colors/font/theme | all | `AdminPanelProvider`, new `resources/css/filament/admin/theme.css`, `vite.config.js` |
| Server header w/ badge + black button | 1 | `ViewServer`, new `filament/servers/header.blade.php` |
| Main + key-value sidebar | 1 | `ServerInfolist` |
| Header-less site rows w/ tiles | 1 | `SitesTable` (feeds list page + `SitesRelationManager`), new `site-tile.blade.php` |
| Left sub-nav settings | 2 | `ServerResource` (sub-navigation), `EditServer` |
| Aside field rows | 2 | `ServerForm` (also applicable to `RelocationForm`) |
| Toggle cards | 3 | `Packages` page / `packages.blade.php` |
| Status-dot run list | 4 | relocation run history table (wizard page / `RelocationsTable`) |

## 5. Suggested implementation order

1. **Theme foundation** (§2) — colors, font, flat cards, black primary buttons.
   Biggest visual payoff, zero structural risk.
2. **Sites list rows** (§3.3) — the most recognizable Forge pattern; validates the
   `Split`/`Stack` + tile approach used again in step 5.
3. **Server view** (§3.1 + §3.2) — header blade + sidebar infolist.
4. **Settings** (§3.4) — sub-navigation + aside sections on `ServerForm`.
5. **Runs list** (§3.6), then **Packages toggles** (§3.5).

After each step: `npm run build`, check `/admin` visually, run
`php artisan test` (the `PanelSmokeTest` catches schema/page regressions) and
`vendor/bin/pint` before committing.
