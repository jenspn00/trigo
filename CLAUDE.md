# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Trigo is a citizen-science bearing-triangulation tool: people at different locations aim their
phone (or a Bluetooth "gun" device) at an object in the sky, record GPS position + compass
heading + elevation angle, and a dashboard fuses everyone's bearing lines into a position/
altitude/speed/course estimate for the object. Danish is the working language throughout code
comments, UI text, and commit-worthy docs — keep new comments/UI text in Danish to match.

There is no build system, package manager, or test suite. This is plain PHP 8.1 (mysqli) +
static HTML/CSS/vanilla JS, deployed by uploading files directly to a cPanel host (see
`INSTALL.md`, which is the running changelog/deploy-notes file, not a static README — update it
when you ship a version that changes deployed files or DB schema).

## Commands

- Lint a single PHP file: `php -l some_file.php`
- Local dev server (serves this directory as-is): `php -S localhost:8000`
- Local dev DB config: copy `db_config.example.php` to `db_config.php` and fill in real
  credentials — `db_config.php` is gitignored and must never be committed. Every PHP entry
  point does `require_once 'db_config.php'` and expects it to define a connected `$mysqli`
  (mysqli instance) plus start output buffering settings; see that file for the exact contract.
- Apply DB schema changes: run `schema_migration.sql` once, by hand, in phpMyAdmin (or `mysql <
  schema_migration.sql`). There is no migration runner — new schema changes should be added as
  new numbered/named `.sql` files following the same pattern, not by editing old ones.
- Manual end-to-end test: open `simulator.php` in a browser. It inserts ~3 minutes of a simulated
  helicopter flight observed by 3 synthetic sessions directly into the `observations` table, then
  dumps a plaintext diagnostic report (row counts, timezone checks, a data sample). Load
  `dashboard.html` afterward and confirm the purple track card shows a plausible speed/course.
  There is no automated test runner — this simulator is the de facto regression test.

## Architecture

### Data flow

1. **Observation capture** — `index.html` (phone PWA) or `gun.html` (Web Bluetooth "gun"
   peripheral) reads GPS + compass heading + elevation, and POSTs JSON to `save_data.php`.
   - `index.html` supports both single-shot and **track-mode**: a long-press starts recording a
     bearing every ~0.8s, batched and sent in groups of 4 (remainder flushed via
     `navigator.sendBeacon` on page close) to avoid hammering the server with single-row inserts.
   - `save_data.php` accepts either shape: a single observation object, or
     `{ session_id, observations: [...] }` for batches. It also tolerates two different field-name
     dialects (`heading`/`elevation_angle` from `index.html` vs `azimuth`/`elevation` from
     `gun.html`) via `pickField()`. After responding to the client (using
     `fastcgi_finish_request()` or a manual `Connection: close` flush so the client isn't kept
     waiting), it does reverse-geocoding via Nominatim as a background step and writes the
     resolved address back onto the just-inserted rows plus a failsafe text log
     (`observation_log.txt`).
   - Every observation belongs to a `session_id` (a per-browser/device UUID-ish string), which is
     the unit dashboard fusion groups by — **not** IP or user account. Two observations from the
     same `session_id` are never intersected/fused together.

2. **Retrieval** — `dashboard.html` polls `fetch_log.php` (default last 5 minutes, `?minutes=`
   overridable) which reads straight from the `observations` table. There's also
   `sse_stream.php` (a long-poll `text/event-stream` endpoint, `id`-cursor based, one client per
   PHP worker via an infinite loop) and `track_objects.php` for alternative/legacy polling — check
   which one a given view actually wires up before assuming all three are live.

3. **Fusion (the core algorithm, in `dashboard.html`)** — this is where most of the interesting
   logic lives, entirely client-side in JS:
   - Raw pairwise bearing-line intersections (`findIntersection`) are still computed and drawn as
     a red "quick and dirty" layer, kept for comparison against the newer estimator.
   - **LSQ-fusion** (`computeFixes` → `lsqFix`): observations are bucketed into 5-second time
     bins; each bin with bearings from ≥2 distinct sessions produces one weighted least-squares
     fix (the point minimizing perpendicular distance to all bearing lines in that bin), with
     iterative outlier rejection, IRLS weighting by inverse squared distance, and a minimum
     angular-spread requirement (≥4°) below which no fix is emitted (bad geometry).
   - **Track-fit** (`fitTrack`): a constant-velocity model fit across ≥3 fixes spanning ≥8s,
     producing speed (km/h), course, and climb rate (m/min), rendered in the sidebar "track card"
     plus a 60s dead-reckoning projection arrow on the map.
   - Per-fix altitude is the noise-suppressed (MAD-based) median of `obsAlt + distance·tan(elevation)`
     across contributing observations.
   - Known limitation: the estimator assumes a single tracked object at a time — simultaneous
     objects will confuse fix computation. Spatial clustering of bearings per bin before LSQ is
     the planned next step (see `INSTALL.md`).
   - `helpers.php` and `get_intersections.php` contain a **server-side** (PHP) version of the flat-
     earth bearing-intersection math, used by `simulator.php`/other endpoints — this is
     independent of the client-side JS fusion code in `dashboard.html` and the two are not kept in
     sync automatically; a change to one's math (e.g. the `fmod` vs `%` fix in `helpers.php`)
     doesn't propagate to the other.

4. **PWA/offline** — `sw.js` uses network-first for HTML navigations (so deploys show up
   immediately on phones) and cache-first for everything else. Bumping the cache version
   (`CACHE_NAME`) is how you force clients to pick up non-HTML asset changes.

### Endpoint responsibility map

- `save_data.php` — write path (single + batch observations)
- `fetch_log.php` — primary read path for the dashboard (DB-backed; supersedes an older version
  that parsed the text log file)
- `sse_stream.php` — push-style read path via SSE, cursor on `id` not timestamp
- `track_objects.php`, `get_intersections.php` — earlier/alternate read+fuse endpoints; `helpers.php`
  holds the shared geo-math used by the PHP-side code
- `delete_object.php` — deletes from a separate `tracked_objects` table (distinct from
  `observations`)
- `getdata.php` — unrelated ESP8266 sensor ingestion endpoint that just appends to
  `sensor_data_log.txt`; not part of the trigo bearing pipeline
- `simulator.php` — synthetic data generator + diagnostic dump, used as the manual test harness

### Gotchas worth knowing before editing

- PHP 8.1 deprecates `%` on floats — use `fmod()` (see the comment at the top of `helpers.php`).
- `db_config.php` is required by nearly every PHP endpoint and is gitignored; `db_config.example.php`
  is a non-functional template, not a fallback — don't expect endpoints to run without a real,
  filled-in `db_config.php`.
- Schema evolves via hand-run `ALTER TABLE` scripts (`schema_migration.sql`), not a migration
  framework — several endpoints defensively `SHOW COLUMNS ... LIKE` before relying on a column
  (e.g. `address`, `session_id`) to stay compatible with databases that haven't been migrated yet.
  Follow that defensive pattern for any new optional column.
- `client session_id` is the trust boundary for "is this the same observer" — there's no auth.
