# Odds Aggregator

A WordPress plugin that compares live odds across roughly ten bookmakers and
renders them as a contextual table inside any post or page.

- **Modular data sources.** A scraper, a licensed API and offline sample data
  all sit behind one interface and are swapped from a dropdown. Adding a source
  touches no existing class.
- **Admin control.** Choose which bookmakers appear, which markets are offered,
  and the outbound link and logo for each bookmaker.
- **Gutenberg block.** Pick an event, then add, remove and reorder bookmakers
  for that block alone.
- **Three odds formats.** Fractional, decimal and American, switchable by the
  reader with no page reload and no request.
- **Built for performance.** Rendering a page performs no outbound HTTP at all.

[DOCS.md](DOCS.md) covers the architecture, the hook reference and how to
extend the plugin.

## Where each requirement is implemented

| Requirement | Implementation |
|---|---|
| Fetch live odds by scraping an odds comparison site | [`OddscheckerProvider`](src/Providers/OddscheckerProvider.php), [`OddscheckerParser`](src/Providers/OddscheckerParser.php) |
| Modular and scalable for more bookmakers | [`ProviderInterface`](src/Providers/ProviderInterface.php), [`BookmakerRegistry`](src/Domain/BookmakerRegistry.php), both filterable |
| Admin: select which bookmakers to display | *Bookmakers* tab, [`SettingsPage`](src/Admin/SettingsPage.php) |
| Admin: choose the markets to display | *Markets* tab, [`MarketRegistry`](src/Domain/MarketRegistry.php) |
| Admin: control and update bookmaker links | *Bookmakers* tab, one outbound URL per bookmaker |
| Gutenberg block for contextual comparison | [`blocks/odds-table`](blocks/odds-table), rendered by [`OddsTableRenderer`](src/Blocks/OddsTableRenderer.php) |
| Block: dynamically add or remove bookmakers | Inspector controls in [`edit.js`](blocks/odds-table/edit.js) |
| Convert between fractional, decimal and American | [`OddsConverter`](src/Odds/OddsConverter.php), mirrored in [`view.js`](blocks/odds-table/view.js) |
| Caching and performance optimisation | [`OddsCache`](src/Cache/OddsCache.php), [`OddsRepository`](src/Odds/OddsRepository.php), [`CacheWarmer`](src/Cache/CacheWarmer.php) |
| Handle rate limits and keep data accurate | [`RateLimiter`](src/Http/RateLimiter.php), [`RetryPolicy`](src/Http/RetryPolicy.php), [`AbstractProvider`](src/Providers/AbstractProvider.php) |
| Coding standards | WordPress Coding Standards, `composer lint` |
| Actions, hooks and filters | Hook reference in [DOCS.md](DOCS.md#9-hook-reference) |
| Inline comments and a separate documentation file | [DOCS.md](DOCS.md) |
| Design patterns | Singleton, Factory, Strategy, Observer, Registry, Value Object — see below |

## Requirements

| | |
|---|---|
| PHP | 8.2 or newer |
| WordPress | 6.3 or newer |
| Node | 22.22.2 or newer (see `.nvmrc`) |
| Composer | 2.x |
| Docker | only for the bundled local environment |

## Setup

```bash
composer install     # PHP dependencies and the autoloader
nvm use              # switches to the Node version in .nvmrc
npm ci               # build toolchain
npm run build        # compiles the block into build/
```

To run it against a throwaway WordPress install:

```bash
npm run env:start    # http://localhost:8888  (admin / password)
npm run env:stop
```

The plugin is already active there and starts on **sample data**, which works
offline with nothing to configure.

For live odds, get a free API key from
[the-odds-api.com](https://the-odds-api.com/), then on the *General* tab choose
**The Odds API**, paste the key and pick a region. The key is verified as you
save: if it cannot return odds the change is rejected and your working source is
kept.

## Using it

**Odds Aggregator** gets its own top-level menu in the admin sidebar, split into
four tabs.

- *General* — a status panel showing the active source, whether the last fetch
  errored and how much API credit is left, with a **Test connection** button;
  then the provider, the API key, the bookmaker region and the odds format pages
  open in.
- *Bookmakers* — tick the bookmakers available site-wide and set the outbound
  (affiliate) URL and a logo for each. No logos ship with the plugin, since
  bundling third-party trademarks is not the plugin's to do; a bookmaker without
  one is drawn as a coloured monogram derived from its id.
- *Markets* — enable Match Result (1X2), Over/Under 2.5 Goals and Both Teams to
  Score.
- *Performance* — cache windows, the upstream fetch interval and the outbound
  rate limit.

Each tab saves independently: saving one never clears another, and editing a
bookmaker can no longer change your data source.

Changing the provider is verified before it is applied, and so is a new API key
or region. If the chosen source cannot return odds the change is rejected with
an explanation and your working configuration is kept, so the front end never
goes blank. A source that answers but has nothing to quote yet is reported as a
warning rather than a refusal, because a licensed feed legitimately returns
nothing between seasons.

### In the editor

Add the **Odds Table** block, then in the sidebar choose an event, narrow the
markets, and add, remove or reorder bookmakers for that block. The preview is
produced by the same PHP that renders the front end, so the editor and the
published page cannot drift apart.

| Attribute | Default | Meaning |
|---|---|---|
| `eventId` | `''` | Event to price |
| `markets` | `[]` | Empty means every enabled market |
| `bookmakers` | `[]` | Empty means every enabled bookmaker; order is respected |
| `format` | `''` | Empty means the site default |
| `highlightBest` | `true` | Mark the best price per outcome |
| `showSwitcher` | `true` | Show the format switcher |

Block choices are always intersected with the site-wide settings, so a bookmaker
disabled in settings cannot be resurrected by an old block.

### On the front end

- **Format switching** between decimal, fractional and American. The arithmetic
  runs client-side over values already in the markup, so there is no refetch,
  and the choice is remembered for the next page.
- **Sorting.** Clicking a column heading sorts by it, descending first, because
  the question is usually who pays most. A second click reverses it and a third
  restores the order the editor arranged.
- **The best price per outcome** is tinted and badged, counting only the
  bookmakers that block actually displays.
- **Colours come from the active theme.** The block reads the theme's palette,
  so it follows any theme and any of its style variations, dark ones included.
- **Narrow screens** get one card per bookmaker rather than a sideways scroll.

Outbound links carry `rel="nofollow sponsored noopener"`.

## Data sources

| Provider | Default | Notes |
|---|---|---|
| Sample data | **yes** | Deterministic fixtures. Works offline, needs no key. |
| Oddschecker | no | Scraper, implemented as specified. Blocked by Cloudflare in practice. |
| The Odds API | no | Licensed feed, and what the demo runs on. Needs a free API key. |

### Oddschecker — the scraper

Implemented as the brief specifies. It does **not** parse table markup:
Oddschecker renders its league page server-side and embeds the data it rendered
from as JSON, so the parser reads that embedded state instead. One GET of the
league page yields every upcoming match with the 1X2 price of every bookmaker,
and the parsed page is reused for 60 seconds — so listing events and pricing all
of them costs one request, not one per event.

`tests/Unit/OddscheckerParserTest.php` runs it against a saved copy of a real
Oddschecker page, so the parser is verified against genuine markup rather than
something invented for the test.

Two limits, stated plainly:

- **The league page carries the Match Result market only.** Over/Under and BTTS
  are not on it, so this source returns no rows for them. Market selection is
  therefore demonstrated on The Odds API, which carries both.
- **The site sits behind Cloudflare and now answers server-side requests with
  HTTP 403**, and scraping is contrary to its terms of service either way.

### The Odds API — the live source

That 403 is the kind of obstacle the brief asks to be solved, and the answer
here is architectural rather than adversarial: every source sits behind
`ProviderInterface`, so replacing a blocked scraper with a licensed feed changes
a dropdown, not a line of rendering code.

One request returns every upcoming match with every bookmaker's prices, so
listing events and pricing all of them costs one request. On the `us` region it
supplies DraftKings, FanDuel, BetMGM, BetRivers, BetOnline.ag, BetUS, Bovada,
LowVig.ag and MyBookie.ag. Caesars and Fanatics need a paid plan, and bet365 and
Borgata are not offered at all, so those rows stay empty. Over/Under appears
only where a bookmaker quotes the 2.5 line; many quote 3.0 instead.

### Credits and the free tier

The free plan allows 500 credits a month and one request costs *markets ×
regions*. With two markets in one region that is 2 credits per refresh, so the
upstream fetch interval — not the cron cadence — governs spend:

| Interval | Refreshes a month | Credits | Fits 500 |
|---|---|---|---|
| 5 minutes | 8,640 | 17,280 | no |
| 1 hour | 720 | 1,440 | no |
| **3 hours** (default) | 240 | 480 | yes |

Set it on the *Performance* tab, and lower it only on a paid plan. A second
region doubles the cost of every refresh; disabling Over/Under halves it. A
rejected request is remembered for five minutes, so a revoked key cannot spend
the month's allowance on retries.

## How it works

Two paths lead to the cache, and only one of them reaches the network.

**A page view** runs `render_callback` → `OddsTableRenderer::render()` →
`OddsRepository::odds()` and stops at the cache. Inside the fresh window that is
a plain hit. Past it the stored payload is still served while a refresh is
queued, because stale odds beat an empty table and beat blocking a render.
Only on a genuine miss does a single process take a lock and fetch.

**The cron warmer** runs every five minutes: `CacheWarmer::run()` →
`OddsRepository::refresh()` → `ProviderInterface::fetch_odds()`, and writes what
comes back into the cache.

So a normal page view performs no outbound HTTP at all. The warmer keeps the
cache populated and readers always read the cache.

**Patterns, and the problem each one solves:**

| Pattern | Class | Why |
|---|---|---|
| Singleton | `Plugin` | One reachable entry point for hooks, cron and REST, without a global |
| Factory | `ProviderFactory` | Callers ask for a source by id and never name a concrete class |
| Strategy | `ProviderInterface` | Scraper, API client and fixtures are interchangeable |
| Observer | `odds_aggregator_odds_updated` | Consumed by the plugin itself, so it is load-bearing rather than decorative |
| Registry | `BookmakerRegistry`, `MarketRegistry` | Adding a bookmaker or market is a data change |
| Value Object | `src/Domain/` | Immutable and free of WordPress calls, which is what makes them unit-testable |

**Performance**, in order of importance: stale-while-revalidate serving,
single-flight locking so one expiry does not send every visitor upstream,
cron warming, and version-based cache invalidation that orphans the whole set in
one write. DOCS.md §5 goes into each.

## Tests

```bash
composer test     # 123 tests
composer lint     # WordPress Coding Standards
```

They run as plain PHPUnit with **no WordPress installation required**, which is
possible only because the domain and conversion layers have no WordPress
dependencies.

Coverage worth calling out:

- **Odds conversion** against a table of known bookmaker prices, including round
  trips through all three formats and every invalid-input case.
- **Both live providers** against saved upstream responses. For The Odds API
  that includes the request economy: one upstream request has to serve the event
  list *and* every event's prices, a market list has to be encoded exactly once,
  and a newly entered key must never be validated against a payload the previous
  key paid for.
- **The settings sanitiser**, including that saving one tab never clears
  another, and that pasting an API key and selecting the source that needs it
  works as a single save.
- **The block renderer**: that prices come out in the format the block was set
  to rather than being patched up by JavaScript afterwards, and that the
  best-price badge never lands inside the element the format switcher
  overwrites.

## Commands

```bash
composer lint        # WordPress Coding Standards
composer lint:fix    # auto-fix what can be fixed
composer test        # PHPUnit

npm run build        # production build
npm start            # watch mode
npm run env:start    # start WordPress
npm run env:stop     # stop WordPress
npm run env:cli -- <wp-cli command>
```

## Demo

> **To be filled before submission.**
>
> - URL: `<demo URL>`
> - Sign in: `<user>` / `<password>`

The odds table is on the demo home page. The settings screen is under **Odds
Aggregator** in the admin sidebar.

## Licence

GPL-2.0-or-later.
