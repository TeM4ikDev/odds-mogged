# Odds Aggregator — developer documentation

Architecture, extension points and the reasoning behind the design decisions.
For installation and usage see [README.md](README.md).

---

## 1. Layout

```
odds-aggregator.php        Bootstrap: constants, guarded autoloader, activation hooks
blocks/odds-table/         Block source (JS/SCSS), compiled into build/
build/                     Compiled block assets (committed)
src/
  Plugin.php               Singleton: builds services, registers hooks
  Domain/                  Immutable value objects and the two registries
  Odds/                    Conversion maths, display formats, cached repository
  Providers/               Data sources behind one interface, plus the factory
  Http/                    Transport, rate limiting, retry/backoff
  Cache/                   Two-tier cache and the WP-Cron warmer
  Admin/                   Tabbed settings screen, typed settings access
  Rest/                    Editor-facing REST endpoints
  Blocks/                  Block registration and server-side rendering
tests/Unit/                PHPUnit tests (no WordPress required)
tests/fixtures/            Saved upstream pages the parsers are tested against
```

Everything under `src/` is PSR-4 (`OddsAggregator\` → `src/`), which is why
filenames are `ClassName.php` rather than the WordPress `class-name.php`
convention; the `WordPress.Files.FileName` sniff is excluded for that reason.

---

## 2. Request flow

Rendering a page containing the block:

```
render_callback
  └── OddsTableRenderer::render()
        ├── OddsRepository::find_event()
        └── OddsRepository::odds()
              ├── fresh cache hit  → return, no network
              ├── stale cache hit  → return, queue a background refresh
              └── miss             → take a lock, fetch once, store
                                       └── ProviderFactory::create_or_fallback()
                                             └── ProviderInterface::fetch_odds()
```

The important property: **a normal page view performs no outbound HTTP**. The
cron warmer keeps the cache populated, and readers always read the cache.

---

## 3. Design patterns

These are used because they solve a problem here, not to tick a box.

### Singleton — `Plugin`

One instance, reachable from anywhere WordPress hands control back (hooks,
cron callbacks, REST handlers) without a global. It is deliberately thin: it
builds services lazily and registers hooks, and holds no logic of its own, so
nothing testable is trapped inside a singleton.

```php
\OddsAggregator\Plugin::instance()->repository();
```

### Factory — `ProviderFactory`

Callers ask for a provider by id and never name a concrete class:

```php
$provider = $factory->create( 'oddschecker' );   // throws on unknown id
$provider = $factory->create_or_fallback();      // degrades to sample data
```

`create_or_fallback()` is what the front end uses: a misconfigured or
key-less provider yields sample data rather than a fatal error or an empty
page.

### Strategy — `ProviderInterface`

Scraper, API client and fixture provider are interchangeable. Adding a source
touches no existing class.

### Observer — `odds_aggregator_odds_updated`

Fired after every successful refresh, and consumed by the plugin itself
(`Plugin::on_odds_updated()` drops derived cache entries), so the event is a
load-bearing extension point rather than a decorative `do_action`.

### Registry — `BookmakerRegistry`, `MarketRegistry`

Filterable catalogues. Adding a bookmaker or a market is a data change.

### Value Object — `Domain/`

`Event`, `Market`, `Outcome`, `Bookmaker`, `Odds`, `OddsLine` are immutable and
free of WordPress calls, which is what makes them unit-testable without a
WordPress install. `Bookmaker::with_url()` returns a copy rather than mutating.

---

## 4. Odds conversion

`OddsConverter` is pure static maths. **Decimal is canonical**: providers
normalise to decimal on the way in, and conversion happens only at the display
edge. Chaining conversions would accumulate rounding error, so it never
happens internally.

```
fractional → decimal   n/d + 1
decimal → american     d >= 2 ? (d-1)*100 : -100/(d-1)
american → decimal     a > 0 ? a/100 + 1 : 100/|a| + 1
decimal → fractional   closest conventional fraction, reduced by GCD
```

Decimal → fractional is the only non-obvious one. A price like 1.9091 is not
exactly any simple fraction, so the converter tries each denominator
bookmakers actually use (1, 2, 3 … 66, 100) and keeps the closest match,
requiring a *strictly* better error to replace the incumbent. Because the list
is ascending, ties go to the simpler fraction: 1.50 renders as `1/2`, never
`2/4`.

Invalid input throws `InvalidArgumentException`: a decimal of 1.0 or less pays
nothing, a zero denominator is not a price, and American odds between -100 and
+100 do not exist.

The same algorithm is mirrored in `blocks/odds-table/view.js` so the front-end
switcher produces identical output. The two implementations are intentionally
kept small and side by side; the known-values table in
`tests/Unit/OddsConverterTest.php` is the shared reference.

---

## 5. Performance

Three mechanisms, in order of importance.

**Stale-while-revalidate.** `OddsCache` holds two windows: *fresh* (60s by
default) and *stale* (600s). Inside the fresh window a read is a plain hit.
Past it the payload is still served — stale odds beat an empty table and beat
blocking a page render on a scrape — while a refresh is queued through
WP-Cron. Only past the stale window does a read miss.

**Single-flight locking.** On a genuine miss, `acquire_lock()` ensures one
process fetches while concurrent requests return empty and pick the data up on
their next view. Without it, an expiry under traffic sends every visitor
upstream at once.

**Cron warming.** `CacheWarmer` refreshes a bounded batch every five minutes,
so in practice the cache is never cold and nobody ever waits. Five minutes is
below the fifteen WPCS prefers; that is deliberate and documented at the call
site — odds move quickly, and outbound volume stays bounded by the rate limiter
and the batch size regardless of frequency.

**Provider-level payload reuse.** A metered feed needs a fourth mechanism. The
caches above are keyed per event, so against a feed that charges per request
they would still pay once per event. `OddsApiProvider` therefore keeps the whole
upstream payload - every event, every bookmaker - behind one key, in a static
per-request memo plus a transient, and serves both the event list and every
event's prices from it. One request per TTL, whatever the cron cadence does:
measured end to end, listing 21 matches and pricing all of them costs 2 credits
rather than 44. That makes the payload TTL, not the cron schedule, the thing
that governs spend, which is why it is a setting of its own on the Performance
tab. A failure is remembered for five minutes as well, so a revoked key cannot
spend a month's allowance on retries.

Cache storage prefers a persistent object cache and falls back to transients.
Invalidation is **version-based**: `flush()` bumps a stored counter that
namespaces every key, orphaning the whole set in one write instead of tracking
and deleting keys individually.

Other measures: block assets load only on pages containing the block (standard
`block.json` behaviour); the front end makes no REST call; the format switcher
recomputes from `data-decimal` attributes already in the markup rather than
refetching; and settings live in a single option row.

---

## 6. Rate limits and data accuracy

**Rate limiting.** `RateLimiter` is a token bucket persisted in a transient, so
the limit holds across PHP requests rather than resetting each time. A burst is
allowed (useful when the warmer refreshes several events at once) while the
sustained rate stays capped.

**Backoff.** `RetryPolicy` retries only genuinely transient failures (408, 429,
500, 502, 503, 504, and transport errors) with exponential backoff and **full
jitter**. An upstream `Retry-After` header is obeyed as an instruction. Jitter
matters: without it, every site that gets limited at the same moment retries at
the same moment.

**Accuracy.** Upstream bookmaker names are normalised through an alias map
(`whill`, `willhill`, `William Hill` → `williamhill`). Any value that does not
parse into a plausible price — outside 1.01 to 1000 — is dropped as a parse
error rather than displayed, enforced in `Odds`'s constructor so an implausible
price cannot exist as an object. Every price carries its fetch timestamp, and
the table prints "Odds updated N ago", because stale odds are useful only when
the reader knows they are stale.

**How The Odds API provider reads data.** The odds endpoint returns every
upcoming match for the sport, each with a `bookmakers[]` array of `markets[]` of
`outcomes[]`. Decimal prices are requested directly (`oddsFormat=decimal`),
which is the plugin's canonical format, so nothing is converted on the way in.

The cache key is an md5 of the sport, the regions, the market list and a hash of
the API key. Each part earns its place: the market list because a different list
is a different response, and the API key because the settings screen validates a
key by fetching events - a payload the previous key paid for would report any
typo as a working key. The market list comes from the markets enabled site-wide
rather than from whatever a single caller asked for, because one market set means
one key and therefore one paid request serving every caller. The trade-off is
that a block asking for a market disabled site-wide gets no rows.

Upstream bookmaker titles go through the same alias map as everywhere else, so
"BetOnline.ag" arrives as `betonlineag`. Over/Under is accepted only at the 2.5
line; books quoting 3.0 are dropped rather than mislabelled. BTTS is not on this
endpoint at all, so if it is the only enabled market the provider still asks for
the cheapest one, or there would be no events to choose from.

Request cost is markets times regions in credits, and the free plan allows 500 a
month. The remaining balance is returned on every response but
`HttpClient::get_json()` keeps only the body, so the plugin cannot display it;
see section 13.

**How the Oddschecker scraper reads data.** Oddschecker renders its pages
server-side and embeds the data it rendered from as JSON in the HTML, so the
scraper never parses table markup:

| Script tag | Contents |
|---|---|
| `<script id="cx-island-data">` | React Query state. Matches are in the query whose key starts with `matches-coupon`, under `state.data.subevents[]`. |
| `<script id="cx-bookmakers-store">` | Bookmaker directory: code → brand (`U1` → FanDuel). |

Each match carries `markets[]` → `bets[]` (outcome, `genericName` HOME / DRAW /
AWAY) → `odds[]` (`bookmakerCode`, `decimal`, `fractional`, `american`,
`status`). `OddscheckerParser` extracts this, keeping only `ACTIVE` prices;
`OddscheckerProvider` maps codes to bookmaker ids (`J3` → bet365, `U1` →
fanduel, …) and `betTypeId 1` to the 1X2 market.

**Market coverage.** The listing page carries the Match Result market only, so
this provider returns nothing for Over/Under or BTTS however they are
configured. Extending it would need a match-level page, which cannot currently
be fetched to work from. Market selection is therefore exercised against The
Odds API, which carries both 1X2 and Over/Under.

One GET of the league page (default
`https://www.oddschecker.com/us/soccer/premier-league`) returns every upcoming
match with every bookmaker's 1X2 price. The parsed page is kept for 60 seconds
(per-request memo plus a transient), so listing events and pricing all of them
costs one upstream request, not one per event. The listing page carries 1X2
only; Over/Under and BTTS rows are therefore empty for this provider and the
block leaves those tables out.

**Scraping reality.** Oddschecker is behind Cloudflare and now answers plain
server-side requests with HTTP 403: the parser finds no data block, throws
`ProviderException`, and the repository serves the last cached odds. Scraping is
also contrary to Oddschecker's terms of service. The provider is kept as a
worked example of the scraping plumbing, not as a usable source;
`OddsApiProvider` is what the plugin actually runs on.

---

## 7. The admin screen

A top-level menu (`admin.php?page=odds-aggregator`, `dashicons-chart-line`,
`manage_options`) split into four tabs: **General**, **Bookmakers**,
**Markets**, **Performance**. Each is a separate Settings API page registered
against the *same* option, selected by `?tab=`.

### The tab contract

A tabbed screen posts only the fields on the tab being saved, so a sanitiser
that rebuilt the whole option from the submission would wipe every other tab.
Two rules make that safe:

1. `Settings::tab_fields()` maps each tab to the fields it owns. A unit test
   asserts the map partitions every key in `Settings::defaults()` exactly once,
   so a new setting cannot be added without being assigned a tab.
2. The form posts a hidden `_tab`. Without it, "this tab was not submitted" and
   "every checkbox on this tab was unticked" are indistinguishable - both send
   nothing - and clearing a checkbox group would be impossible.

`Settings::sanitize()` therefore starts from the stored value and overlays only
the submitted tab's fields. An unrecognised `_tab` falls back to treating every
field as submitted, which is the pre-tabs behaviour and keeps programmatic
writes working.

A welcome side effect: the Bookmakers tab no longer posts `provider`, so
editing a bookmaker can no longer change the data source.

### Changing the data source is verified

`SettingsPage::guard_provider_change()` hooks `pre_update_option_*` and probes
the chosen provider once. A source that cannot return odds is rejected, the
previous one is kept, and the reason is shown. This exists because selecting a
dead source used to be silently destructive: the event list emptied, every odds
table blanked, and nothing said why.

Three details matter:

- **It probes the values being saved, not the stored ones.**
  `pre_update_option` fires before the row is written, so building the provider
  from the database would validate the configuration being replaced. Pasting an
  API key and selecting the source that needs it is one save, and it used to be
  rejected as unconfigured. `Settings::with_overrides()` and
  `ProviderFactory::for_settings()` exist for this.
- **A new key or region is probed too**, not just a new provider id, because
  that is the only way to tell an administrator whether their key works. The
  probe costs credits exactly when the key or region changed, which is also
  exactly when the cache key changed, so it cannot be served from cache. Any
  other edit on the tab still pays for nothing. If the probe fails while the
  provider is unchanged, the key and region are rolled back rather than the
  provider: leaving broken credentials under a selected source would blank the
  front end with nothing on screen to explain it.
- **An empty event list is a warning, not a refusal.** A licensed feed returns
  nothing between seasons, and rejecting a valid key for that would be wrong.
  Only `ProviderException` and an unavailable provider are hard failures.

Note that `settings_errors()` must be called explicitly in the page body. Core
prints those messages automatically only on its own options screens, via
`options-head.php`; a top-level `admin.php` page gets no such treatment, and
the messages would otherwise expire unseen in a 30-second transient.

### Adding a setting

1. Add it to `Settings::defaults()`.
2. Add its key to the owning tab in `Settings::tab_fields()`.
3. Add a `case` to `Settings::sanitize_field()` and an `add_settings_field()`
   call against that tab's page slug.

Skipping step 2 fails the partition test rather than silently losing data.

Two settings are maps keyed by bookmaker id rather than scalars:
`bookmaker_urls` and `bookmaker_logos`. They sanitise identically - every value
through `esc_url_raw()`, empty values dropped - so they share one `case` in
`Settings::sanitize_field()`. A blank entry means "not set", and the catalogue
falls back: to the bookmaker's own homepage for a URL, and for a logo to the
icon bundled in `assets/logos/`, or a generated monogram when none ships.

Icons are served from the site's own copy of the plugin rather than hotlinked,
so a visitor's browser contacts no third party and nothing breaks if an icon
service disappears. A bookmaker gains an icon by dropping a file in that
directory and naming it in the third slot of `BookmakerRegistry::DEFAULTS`.

---

## 8. Extending

### Add a bookmaker

```php
add_filter( 'odds_aggregator_bookmakers', function ( array $bookmakers ): array {
    $bookmakers['newbook'] = new \OddsAggregator\Domain\Bookmaker(
        'newbook',
        'New Book',
        'https://example.test/affiliate'
    );

    return $bookmakers;
} );
```

Then map whatever the upstream source calls it:

```php
add_filter( 'odds_aggregator_bookmaker_aliases', function ( array $aliases ): array {
    $aliases['newbooksportsbook'] = 'newbook';

    return $aliases;
} );
```

### Add a market

Outcome count is not assumed anywhere, so a two-way or five-way market needs no
other changes:

```php
add_filter( 'odds_aggregator_markets', function ( array $markets ): array {
    $markets['dc'] = new \OddsAggregator\Domain\Market(
        'dc',
        __( 'Double Chance', 'my-theme' ),
        array(
            new \OddsAggregator\Domain\Outcome( 'hd', '1X' ),
            new \OddsAggregator\Domain\Outcome( 'ha', '12' ),
            new \OddsAggregator\Domain\Outcome( 'da', 'X2' ),
        )
    );

    return $markets;
} );
```

Your provider then needs to return prices keyed by those outcome keys.

### Add a provider

Implement `ProviderInterface` (or extend `AbstractProvider` for the
normalisation helpers, or `AbstractScraperProvider` for fetch-and-parse
plumbing), then register a builder:

```php
add_filter( 'odds_aggregator_providers', function ( array $builders, $settings, $http ): array {
    $builders['myfeed'] = static fn() => new \My\Plugin\MyFeedProvider( $http, $settings->api_key() );

    return $builders;
}, 10, 3 );
```

It appears in the settings dropdown automatically.

### React to refreshed odds

```php
add_action( 'odds_aggregator_odds_updated', function ( array $lines, string $event_id, string $provider ) {
    // Record a price history, push a notification, warm another cache…
}, 10, 3 );
```

---

## 9. Hook reference

### Actions

| Hook | Arguments | Fires |
|---|---|---|
| `odds_aggregator_loaded` | `Plugin $plugin` | After the plugin boots. |
| `odds_aggregator_odds_updated` | `OddsLine[] $lines`, `string $event_id`, `string $provider_id` | After a successful refresh. |
| `odds_aggregator_warm_cache` | — | Cron: refresh a batch. |
| `odds_aggregator_refresh_event` | `string $event_id`, `string[] $market_keys` | Cron: refresh one event. |

### Filters

| Hook | Arguments | Purpose |
|---|---|---|
| `odds_aggregator_bookmakers` | `Bookmaker[]`, `array $url_overrides` | Bookmaker catalogue. |
| `odds_aggregator_markets` | `Market[]` | Market catalogue. |
| `odds_aggregator_bookmaker_aliases` | `array $aliases` | Upstream name → bookmaker id. |
| `odds_aggregator_providers` | `array $builders`, `Settings`, `HttpClient` | Registered data sources. |
| `odds_aggregator_user_agent` | `string $agent` | Default outbound User-Agent. |
| `odds_aggregator_oddsapi_sport` | `string $sport` | Sport key queried at The Odds API. |
| `odds_aggregator_oddschecker_url` | `string $url` | League page the scraper reads. |
| `odds_aggregator_oddschecker_bookmakers` | `array $codes` | Oddschecker code → bookmaker id. |
| `odds_aggregator_oddschecker_headers` | `array $headers` | Request headers sent to Oddschecker. |

---

## 10. REST API

Namespace `odds-aggregator/v1`. All endpoints require `edit_posts`: they exist
for the block editor, and the front end deliberately does not call them.

| Endpoint | Returns |
|---|---|
| `GET /events` | Events the active provider can quote. |
| `GET /markets` | Markets enabled site-wide. |
| `GET /bookmakers` | Bookmakers, each flagged `enabled`. |
| `GET /odds?event=<id>&markets=<csv>` | Cached prices for one event. |

---

## 11. The block

`odds-aggregator/odds-table`, dynamic (`save()` returns `null`), so a published
page always reflects current cached odds rather than markup frozen at save
time. The editor preview uses `ServerSideRender` against the same PHP renderer,
so editor and front end cannot drift.

| Attribute | Type | Default | Meaning |
|---|---|---|---|
| `eventId` | string | `''` | Event to price. |
| `markets` | string[] | `[]` | Empty means every enabled market. |
| `bookmakers` | string[] | `[]` | Empty means every enabled bookmaker; order is respected. |
| `format` | string | `''` | Empty means the site default. |
| `highlightBest` | boolean | `true` | Mark the best price per outcome. |
| `showSwitcher` | boolean | `true` | Show the format switcher. |

Block-level choices are always intersected with the site-wide settings, so a
bookmaker disabled in settings cannot be resurrected by an old block.

Output is a real `<table>` per market with a `<caption>` and `scope`
attributes. The best-price comparison runs over the bookmakers **actually
displayed**, so a block showing a subset still highlights a winner.

The identity cell is the outbound link, carrying
`rel="nofollow sponsored noopener"`. The logo is decorative with an empty
`alt`, so the link's accessible name is the bookmaker's name.

Three details in the markup exist because of the front-end script:

- Prices sit in `span.oa-odds` with the raw decimal in `data-decimal`. The
  switcher replaces that element's text content wholesale, so nothing may be
  nested inside it. The best-price badge and the screen-reader label are
  siblings for that reason.
- Column headings are buttons carrying `data-column`, and the cell holds
  `aria-sort`. The sort arrow is drawn from `aria-sort` in CSS, so it cannot
  disagree with what assistive technology is told.
- Each row carries `data-order` with the position the editor arranged, so
  sorting is a reader's temporary override rather than a permanent one.

Colours are taken from the theme's `theme.json` presets rather than chosen in
the stylesheet, so the block follows any theme and any of its style variations,
dark ones included.

---

## 12. Testing and quality

```bash
composer test     # 126 unit tests
composer lint     # WPCS
```

Tests run without WordPress: `tests/bootstrap.php` defines the functions the
pure classes touch. That is possible only because the domain and conversion
layers have no WordPress dependencies - a design constraint worth keeping.

The stub set now reaches the transport (`wp_remote_get` and the
`wp_remote_retrieve_*` readers, `add_query_arg`, `home_url`, `wp_rand`), because
some properties are only observable through it. `wp_remote_get` records a call
count and the requested URLs, which is what lets a test assert that one upstream
request served every event, and that a market list was encoded once rather than
twice. `add_settings_error` collects notices so the settings guard can be tested
by reading them back.

Note for Windows checkouts: with `core.autocrlf=true` git rewrites every file to
CRLF while the repository stores LF, and `Generic.Files.LineEndings` then fails
on files nobody touched. That is a checkout artefact rather than a code problem;
a `.gitattributes` pinning `eol=lf` would settle it.

Two WPCS sniffs are excluded in `phpcs.xml.dist`, both documented inline:
`EscapeOutput.ExceptionNotEscaped` (exception messages are logged, never
echoed) and `ValidVariableName.UsedPropertyNotSnakeCase` (properties on PHP's
own `DOMNode` are not ours to rename).

---

## 13. Deliberately out of scope

Named here so their absence reads as a decision rather than an oversight:

- **Odds history** — needs a custom table and a retention policy.
- **Live polling / WebSockets** — the cron cadence is the cheap 90% of it.
- **Circuit breaker** - the retry policy covers transient failure, and a
  rejected payload is negative-cached for five minutes; a real breaker would be
  the next step for a provider that fails persistently.
- **Showing the remaining API credit** - The Odds API reports it on every
  response, but `HttpClient::get_json()` returns only the decoded body.
  Surfacing it, and refusing to fetch near the cap, needs a transport method
  that hands back headers alongside the body. It is the obvious next safety net
  on a 500-credit allowance.
- **React-based admin** — the Settings API is the right weight for this surface.
- **CI** — `composer lint && composer test` is the whole pipeline.
- **Multi-sport** — the model is sport-agnostic; only the fixtures and the
  scraper paths are football-specific.
