# SCD Standings & Scenarios

A WordPress plugin for SaudiOpenData.com: a live, Arabic-first tournament
standings hub with FIFA tie-break rules, qualification probability
indicators, a what-if scenario generator, knockout bracket projections,
per-team "possible opponents" pages, and a no-reload Alpine.js simulator.
Ships with a one-click FIFA World Cup 2026 demo dataset.

## Requirements

- WordPress 6.0+
- PHP 8.0+
- No required external services. API-Football is optional, used only if
  you want automated syncing instead of (or alongside) manual data entry.

## Installation

1. Upload the `scd-standings` folder to `wp-content/plugins/` (works as a
   plain zip upload - no `composer install` required; see
   [Architecture](#architecture) for why) and activate it.
2. Go to **SCD Standings** in the WP admin menu.
3. Click **Seed World Cup 2026 demo data** to get 8 groups, 32 teams, and
   two already-played matchdays per group - enough to see standings,
   qualification status, the what-if simulator, and the knockout bracket
   projection immediately. Safe to click again; it no-ops if already
   seeded.
4. Visit `/world-cup-2026/` on the front end.

To enter your own tournament instead, use **SCD Standings → Import Data** to
add groups, teams and matches by hand, or configure an API-Football key
under **SCD Standings → Settings** for automated syncing.

## Frontend URLs

| Page | URL |
|---|---|
| Tournament hub (groups + bracket) | `/{tournament}/` |
| Group standings + what-if simulator | `/{tournament}/{group}/` |
| Team profile + possible opponents | `/{tournament}/{group}/{team}/` |
| Match + result scenarios | `/{tournament}/{match}/` |
| One scenario's narrative + impact | `/{tournament}/scenarios/{scenario}/` |

All of these can be overridden by a theme: drop a same-named file in
`your-theme/scd-standings/{template}.php` (see `Frontend\TemplateLoader`).

## REST API (`/wp-json/scd/v1`)

All `GET` routes are public and read from cache; `POST /simulate` is the
only route that computes on request (it never touches the database).

- `GET /tournaments`
- `GET /tournaments/{slug}`
- `GET /tournaments/{slug}/standings?group={slug}`
- `GET /tournaments/{slug}/bracket`
- `GET /tournaments/{slug}/teams/{team_slug}/opponents?round={round}`
- `GET /matches/{id}/scenarios`
- `POST /simulate` — `{ group_id, results: [{match_id, home_score, away_score}] }`

## Architecture

- **CPT-shell + custom-table hybrid.** `scd_tournament`/`scd_team`/
  `scd_match`/`scd_scenario` CPTs carry only the editorial/SEO layer
  (title, permalink, featured image); the `wp_scd_*` custom tables
  (`includes/Infrastructure/Database/Schema.php`) are the system of
  record. Every row's `post_id` column must point at a real post, or
  `Frontend\Rewrites` 404s the corresponding URL.
- **Domain layer is pure PHP**, with no WordPress calls, so it's unit
  tested directly (`tests/Unit`, run with `vendor/bin/phpunit`):
  `StandingsCalculator`, the FIFA `TieBreak\FifaRuleset`,
  `QualificationStatusResolver`, `BracketProjector`, `OpponentFinder`,
  `OutcomeEnumerator` + `ScenarioEngine`, `NarrativeGenerator`.
- **Recalculation pipeline** (`Jobs\RecalculationPipeline`) runs after any
  match result or manual override changes: standings/qualification per
  group → bracket projection → scenario generation (creates/reuses a
  `scd_scenario` post per match outcome) → override application → cache
  purge. Debounced via a 5-minute transient lock so a burst of changes
  only triggers one recalculation.
- **Sync** (`Jobs\SyncJob`) runs on a configurable WP-Cron interval
  (`scd_sync_interval_minutes`, default 60, floor 15 - sized around
  API-Football's 100 requests/day free tier) via a provider abstraction
  (`Infrastructure\Providers\ProviderInterface`) so other data sources can
  be added without touching the pipeline.
- **No composer dependency at runtime.** `scd-standings.php` loads
  `vendor/autoload.php` if present, otherwise falls back to a hand-written
  PSR-4 autoloader (`includes/autoload-fallback.php`), so the plugin works
  as a plain zip upload. Composer is only used for the dev-only PHPUnit
  suite.
- **SEO** (`SEO\SeoModule`) defers to Yoast/RankMath for title/meta/
  canonical when one is active, but always emits schema.org JSON-LD
  (the group-archive route is the one thing neither plugin can get right,
  since it has no backing post) and noindexes "what if" scenario pages
  once their match has actually finished.
- **Frontend** is server-rendered PHP templates (`templates/*.php`) plus a
  locally-vendored Alpine.js (`assets/js/vendor/alpine.min.js` - no CDN
  dependency) for the live-updating widgets, all talking to the `scd/v1`
  REST API.

## Development

```bash
composer install
vendor/bin/phpunit
```

The PHPUnit suite covers the Domain layer only (pure PHP, no WordPress
bootstrap needed beyond a stubbed `ABSPATH` in `tests/bootstrap.php`).
