# CLAUDE.md

Flute CMS module: the "Player history and statistics" admin section, built on top of
the database filled by the [ConnectHistory](https://github.com/Armatura-Create/ConnectHistoryCS2)
game-server plugin.

The module is **read-only**. It creates no tables, writes nothing to the history,
defines no Cycle entities and needs no migrations: the database belongs to the plugin,
and the plugin owns its schema.

## Commands

```bash
composer install
composer test        # PHPUnit without Flute
composer lint        # Pint (PSR-12), needs PHP 8.3+
composer fix         # Pint with autofix
composer stan        # PHPStan level 5 over the services, without Flute
composer stan:flute  # PHPStan against the real CMS (needs a clone in .flute/)
```

To get the CMS locally for the full analysis:

```bash
git clone --depth 1 --branch v1.0.6 https://github.com/Flute-CMS/cms.git .flute
cd .flute && composer install --no-scripts && cd ..
composer stan:flute
php tests/compat/assert-flute-classes.php --verbose
```

## The decision everything else follows from

**Aggregates are computed in SQL, never in PHP.**

The predecessor module (`ConnectionStats`) selected every session in the period and
grouped them with a `foreach`. On a 180-day window that took the admin panel down.
Hence the rule: methods of `HistoryRepository` that serve analytics return the result
of a `GROUP BY`, not rows. Table screens hand a `SelectQuery` straight to
`LayoutFactory::table()`, and the platform does pagination, sorting and `LIMIT`
on the database side.

The consequence: page cost does not depend on how much history has accumulated.

## Architecture

```
Providers/ConnectHistoryProvider.php   registration: views, scss, mod driver, admin package
Admin/Drivers/                         the "ConnectHistory" connection type in Servers
Admin/Package/                         routes, permissions, menu, admin stylesheet
Admin/Package/Screens/                 five screens plus the shared ResolvesHistory trait
Services/HistoryRepository.php         EVERY SQL statement in the module
Services/SessionFilter.php             GET -> normalised filter (pure, no Flute)
Services/ServerBinding.php             parses a connection's additional (pure, no Flute)
Services/PlayerIdentityService.php     steamid64 -> display name and avatar
Services/Format.php                    time, duration, numbers, avatars — shared by
                                       screens, views and the widget
Widgets/StatWidget.php                 one configurable number for any site page
```

### Data path

```
GET parameters
  -> SessionFilter::fromArray()          normalisation and window bounds
  -> HistoryRepository::for($serverId)   "panel server -> plugin server" binding
  -> SelectQuery | array of aggregates
  -> LayoutFactory::table/chart, metricsRow()
  -> dataCallback -> PlayerIdentityService   identities for ONE page, in one batch
```

## Invariants that are easy to break

- **`boot()` only registers; `Screen::mount()` reads.** The provider runs on every
  request to the panel, including ones that never open this section, so it must not
  touch the database. `mount()` also runs after `Screen::$permission` has been checked.
- **Permissions decide the `SELECT` list, not visibility in the markup.** Without
  `admin.connecthistory.pii` the columns `player_ip`, `city`, `ip_subnet`, `ip_hash`
  never enter the query and the alt-account lookup is not executed at all. Hiding
  personal data in Blade is protection you can only see in the markup.
- **Never select raw rows in order to count them in PHP.** See the decision above.
  The one exception is the grouped mode of the Sessions screen: Cycle's
  `SelectQuery::count()` ignores `GROUP BY`, so the platform paginator would count
  sessions instead of groups. There the aggregate runs with a hard
  `connecthistory.max_groups` cap, and what reaches PHP is groups, not sessions.
- **The selection window is always bounded.** `SessionFilter` clamps the period
  (`max_period_days`), and an explicit date cannot widen it past that bound.
- **Times in the database are UTC.** Compare against "now" only through
  `UTC_TIMESTAMP()`: `NOW()` returns the MySQL session's timezone. Output goes through
  `Format::time()`, the single place where UTC leaves the data.
- **Hour and weekday are computed in the PANEL timezone, not UTC.** `HOUR(taken_at)`
  on UTC values yields Greenwich hours: for Moscow the activity map shifts by three
  hours, and that map is what people schedule events by. The offset is added in SQL
  (`+ INTERVAL n MINUTE`) rather than via `CONVERT_TZ`, which needs the MySQL timezone
  tables that a typical host does not have.
- **`steamid64` is a string the whole way**, route parameter included. A 64-bit number
  loses precision in a PHP int on a 32-bit build.
- **Player identity has a guaranteed floor.** `PlayerIdentityService::merge()` must
  return a filled record for EVERY requested SteamID, whatever Flute and Steam answer.
  A private profile is an ordinary branch, not an exception — that is exactly what
  brought the previous module down.
- **Steam is queried once per page.** Identity resolution lives in
  `Table::dataCallback`, which runs AFTER pagination. Calling it inside a column
  renderer would mean one request per row.
- **Avatars are rendered only through `Format::avatar()`.** Steam returns an absolute
  URL; a site user's avatar is a relative path in the database that must go through
  `asset()`. Printing the raw value breaks the image for exactly half the players.
- **Columns added by newer plugin schema versions are queried conditionally.**
  `spectator_seconds` exists only from version 3, and asking for a missing column
  kills the WHOLE query — updating the module without updating the plugin would cost
  the user an entire section. Presence is checked through `information_schema` and
  cached for an hour (the schema changes when the game server restarts).
- **Card times are computed FROM SESSIONS, not from `ch_players.total_seconds`.**
  The latter is "playtime" as the plugin defines it: with
  `Collect.CountSpectatorTime = false` spectator time is already subtracted, and the
  panel cannot know about that setting, so it cannot label the number honestly. From
  sessions all three values follow unambiguously and always add up:
  connected = in game + out of game.
- **A route parameter only arrives on the FIRST render, and a property survives the
  re-render only if it is SIGNED.** Filters re-render the component through yoyo, and
  that request carries no path. Core restores public properties in `Screen::boot()`
  from the request body — but the only ones that reach the body are those core printed
  as hidden inputs in `base.blade.php`, i.e. exactly `resolveSignedProperties()`.
  Auto-detection covers properties named `id` or `*Id` typed int|string and NOTHING
  else, so `steamid64` must be declared in `signedProperties()` by hand. Miss that and
  the identifier goes empty on the first filter click: the card answers "not found"
  and the server selector — narrowed further down `mount()` — falls back to listing
  every server, with no way back. Signed properties are nulled when the HMAC fails, so
  they must be nullable. Covered by `ScreenStateTest`.
- **Read the request with `request()->input()`, never `request()->query->all()`.**
  A yoyo re-render arrives as a POST: the filter values sit in the body, and the query
  string is empty. Reading only the query silently resets every filter on the second
  render. `input()` merges route attributes, query and body — the same source core
  screens read.
- **A filter must only offer choices that lead somewhere.** The server selector on the
  player card lists only servers the player actually has sessions on, and a selection
  that no longer applies is dropped rather than kept — otherwise the card goes empty
  and the selector, missing that option, cannot bring the user back.
- **A mirrored connection is the mirror's address, not the player's.** A mirror
  proxies traffic, so `player_ip`, `country_iso` and `city` describe the mirror. The
  addresses are configured per binding (Servers -> server -> ConnectHistory ->
  "Mirror IPs") and matched exactly — a mirror is a fixed host, and subnet matching
  only adds ways to mislabel someone else's row. Mirror sessions are excluded from
  alt-account detection on BOTH sides of the join: otherwise everyone behind the
  mirror looks like an alt of everyone. Exclusion works through `player_ip` only —
  `ip_hash` is salted by the plugin and the panel does not have the salt.
- **A scope filter narrows the WHOLE screen.** If the metrics stay global while the
  table narrows, the numbers on one screen stop adding up and nothing on the page
  reveals why.
- **Only ONE platform table per screen.** `Layouts\Table` takes the page number and
  the sort column from the shared `page` and `sort` GET parameters, so a second table
  starts paging and sorting along with the first. Short reference lists are rendered
  as views (`LayoutFactory::view`); they need no pagination and are already capped by
  `LIMIT` in SQL.
- **Cache keys must not contain `{}()/\@:`** — those characters are reserved by PSR-6,
  and Symfony Cache THROWS on them rather than ignoring the key. The cost is
  asymmetric: where the exception is caught there simply is no cache and every visit
  hits the database; where it is not, the widget shows "data unavailable". The
  separator is a dot. Covered by `CacheKeyTest`.
- **A broken cache must reach the log.** Falling back to "compute directly" keeps the
  section alive, but a silent fallback hides the very thing the cache was added for.
- **`Widget::render()` may neither throw nor return null.**
  `WidgetController::saveSettings()` calls it immediately after saving and puts the
  result in the JSON response: an exception there is caught and the form HTML is
  returned instead of JSON, which the front end reads as "saving does not work", while
  null yields an empty widget with no explanation. Every problem is shown as a card
  with text.
- **A widget's category must come from the core list** (general, users, user, content,
  media, other, payments, admin, stats, system, social): it is translated through the
  `page.categories.<category>` key, and an invented one renders as the key itself.
- **Menu items are returned WITHOUT a `key`.** `AdminPackageFactory` routes them by
  that: an item with a key expects to find that key in the panel's
  `config('admin-menu')` and, failing to, drops into a nameless "leftovers" section as
  a flat list; an item without a key from a module lands in its own module section,
  which the sidebar expands as a separate level.
- **A select filter must name itself in its first option.** The core `Filters` template
  renders `label` for buttonGroup, input, dateRange and checkbox, but NOT for select —
  without a descriptive option a row of identical "Choose an option" dropdowns appears.
  Hence `'' => 'All maps'` with `allowEmpty: false`.
- **"Nothing selected" does not mean "show everything".** One plugin database serves
  several game servers (the `server_id` column) and the panel only knows the ones that
  are bound to it. So with a SINGLE binding the statistics are scoped to that server,
  and the explicit "All servers" choice appears only when there are several
  (`HistoryRepository::for`). Otherwise data from neighbouring servers writing to the
  same database would leak in — servers the panel cannot show separately anyway.
- **`url()` returns `UrlSupport`, not a string.** Blade and arrays coerce it, but an
  argument typed `string` (`Button::href`, `BreadcrumbService::add`) needs an explicit
  cast or it is a TypeError at runtime.
- **Admin stylesheets are registered by the PACKAGE, not the provider.**
  `ModuleServiceProvider::loadScss()` puts the file in the "main" asset group — the
  site theme — where the panel never loads it; the panel needs
  `AbstractAdminPackage::registerScss()` with the "admin" group. Hence the split:
  `connecthistory.scss` for the site widget, `admin.scss` for the panel screens.
- **A chart's caption equals the name of its aggregate.** There must be no computation
  between the query and the chart where the meaning could drift from the label: the
  predecessor drew a cumulative sum under the heading "Connection history".

## Configuration

`Resources/config/connecthistory.php` — cache TTLs, the period cap, the group cap,
page size, the "server went quiet" threshold.

The database connection is configured in the panel's Servers section rather than here:
a connection with the `ConnectHistory` mod, whose `additional` holds the plugin's
`server_id` and the table prefix. It is read by exactly one method,
`ServerBinding::readAdditional()`, whose result has the same shape for any input,
malformed JSON included.

## Translations

`Resources/lang/{ru,en}/connecthistory.php`. A key that is missing from the file is
printed by Flute as-is — the user sees `connecthistory.sessions.column_map` instead of
a caption, and nothing crashes. So `TranslationKeysTest` compares three sets: the keys
used in the code, in `ru` and in `en`, and checks that placeholders match. Keys built
by concatenation are listed in that test's `DYNAMIC_KEYS` — add a tab, add it there.

## Tests and CI

Tests run **without Flute**: they contain only logic that does not depend on the CMS.
Everything that extends a Flute class (screens, widget, provider, installer) is checked
by the separate `flute-compat` job: it clones the target CMS version, asserts that every
`Flute\*` symbol the module references exists, and runs PHPStan against the real classes.

Why: the predecessor died from a class moving between Flute versions and learned about
it in production. The full account of its eight failures is in [docs/AUTOPSY.md](docs/AUTOPSY.md);
it also explains why the architecture is what it is.

Flute is installed by cloning rather than `composer require`: `flute-cms/cms` is a
`project`-type package that does not install as a library, and its dependencies clash
with the module's development tools.

`stubs/Makeable.stub` is required and is not cosmetic. In Flute itself
`Makeable::make()` is declared `: self`, which inside a trait resolves to `Field` — so
`Button::make()` and `Tab::make()` are statically typed as `Field` and **the entire
chain after them goes unchecked**: neither method existence nor argument types. While
an `ignoreErrors` entry stood in place of the stub, `Button::href(UrlSupport)` instead
of `string` reached production through it. The stub declares the same method as
`static` and restores the checking.

## Versions

The version lives in `module.json` and must match the release tag — `release.yml`
verifies that before building the archive.

The supported CMS range is stated in two places: `module.json` (`dependencies.flute`)
and the `flute` matrix in `ci.yml`. The lower bound must be in the matrix, otherwise
the promise "works with 1.0.6" is backed by nothing.
