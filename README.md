# ConnectHistory for Flute CMS

English · [Русский](README.ru.md)

[![CI](https://github.com/Armatura-Create/ConnectHistory-Flute/actions/workflows/ci.yml/badge.svg)](https://github.com/Armatura-Create/ConnectHistory-Flute/actions/workflows/ci.yml)
[![Release](https://img.shields.io/github/v/release/Armatura-Create/ConnectHistory-Flute?logo=github&color=success)](https://github.com/Armatura-Create/ConnectHistory-Flute/releases/latest)
[![Downloads](https://img.shields.io/github/downloads/Armatura-Create/ConnectHistory-Flute/total?logo=github&color=success)](https://github.com/Armatura-Create/ConnectHistory-Flute/releases)

[![PHP](https://img.shields.io/badge/PHP-%E2%89%A5%208.2-777bb4?logo=php&logoColor=white)](https://www.php.net/)
[![Flute CMS](https://img.shields.io/badge/Flute%20CMS-%E2%89%A5%201.0.6-f97316)](https://github.com/Flute-CMS/cms)
[![License](https://img.shields.io/badge/license-GPL--3.0--or--later-blue)](LICENSE)

A player history and statistics section for the [Flute CMS](https://github.com/Flute-CMS/cms)
admin panel. It reads the database filled by the
[ConnectHistoryCS2](https://github.com/Armatura-Create/ConnectHistoryCS2) plugin
running on the game server.

The module is read-only: it creates no tables, writes nothing to the history and
needs no migrations.

## What it shows

**Overview** — headline metrics plus eight views in tabs: online over time, connects,
an hour × weekday heatmap, newcomers and returns, maps by playtime, disconnect reasons,
geography with connection quality, and server crashes.

**Sessions** — every connect row by row, filtered by server, period, player, map,
country, disconnect reason, state and duration. A group-by switch collapses the same
selection by player, map, country, day or disconnect reason. Sorting, search and CSV
export work on whatever the filters currently select.

**Players** — playtime, session counts, cohorts by first visit.

**Player card** — a lifetime combat summary (K/D, headshots, MVP, rounds, ping),
daily activity, nickname history, servers played on, favourite maps, how they usually
leave, recent sessions. With the `pii` permission — address history (one row per
address, not per session) and possible alt accounts by IP hash.

**Servers** — the registry, live online count, uptime and crashes. It also surfaces
servers whose public address the plugin could not determine.

**The "History: metric" widget** — a single number on any site page. Its settings pick
the metric (online now, unique players, connects, newcomers, peak online, average
session, hours played, retention, crashes), the server, the period, a custom caption
and an icon. The result is cached twice — the rendered HTML and the query itself —
so a widget on a busy page does not turn into a stream of database hits.

## Installation

1. Download the archive from the [releases page](https://github.com/Armatura-Create/ConnectHistory-Flute/releases)
   and unpack it into the panel's `app/Modules/`.
2. Admin panel → **Modules** → enable **ConnectHistory**.
3. Admin panel → **Servers** → your server → add a database connection with
   type **ConnectHistory**.
4. Set **ServerId** — the same number as in the plugin's `Settings.json` on that
   server — and the table prefix if it differs from `ch_`.

Multiple servers share one plugin database and are told apart by `ServerId`: add a
connection per panel server; the database name will be the same for all of them.

### Database user

The panel never writes:

```sql
CREATE USER 'ch_reader'@'%' IDENTIFIED BY '...';
GRANT SELECT ON connect_history.* TO 'ch_reader'@'%';
```

## Permissions

| Permission | Grants |
|---|---|
| `admin.connecthistory` | Access to every history section |
| `admin.connecthistory.pii` | `player_ip`, `city`, `ip_subnet` and alt-account lookup |

The second one is not "unhide a column". Without it personal fields never enter the
`SELECT` list and the alt-account query is not executed at all, so there is nowhere
for them to leak from.

## How it works

Three decisions everything else follows from:

**Aggregates are computed in SQL.** The module never selects raw sessions to count them
in PHP. Table screens hand the query to the platform, which paginates and applies
`LIMIT` itself. Page cost does not depend on how much history has piled up.

**Permissions decide the column list in the query**, not visibility in the markup.

**Player identity resolves through a chain with a guaranteed floor:** a Flute user with
a linked Steam account → the Steam Web API in one batch per page → the nickname stored
in the database plus a steamcommunity link. The last level always works and needs no
network, so a private or deleted profile cannot take the page down.

Why it is built this way — [docs/AUTOPSY.md](docs/AUTOPSY.md): eight failures of the
previous panel module, each traced to its root cause.

## Requirements

- Flute CMS >= 1.0.6 (CI verifies both 1.0.6 and 1.0.7)
- PHP >= 8.2
- The ConnectHistory plugin on the game server (DB schema version >= 1)

## Development

```bash
composer install
composer test     # tests without Flute: filters, bindings, identities, translations
composer lint     # code style (Pint, PHP 8.3+)
composer stan     # static analysis of the services
```

A separate CI job installs the real Flute and asserts that every `Flute\*` symbol the
module references exists there. That catches classes moving between CMS versions —
exactly what silently broke the previous module.

## License

GPL-3.0-or-later, same as Flute CMS.
