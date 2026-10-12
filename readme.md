# Hiyori CLI

[![stable](https://img.shields.io/badge/PHP-^%208.2-blue.svg?style=flat)]() [![Discord Server](https://img.shields.io/discord/460491088004907029.svg?style=flat&logo=discord)](http://discord.jikan.moe)


Hiyori CLI is being developed to create and maintain [HiyoriDB](https://github.com/Hiyori-API/HiyoriDB) — a relational Anime and Manga Database.


> [!WARNING]
> This project is a work-in-progress and is not production ready.

## Process
Hiyori CLI has two main primary functions:
1. Ingesting transformed data based on the Hiyori Schema ingested from available sources
2. Combining the ingested data into HiyoriDB

Hiyori also collects relational mappings from other sources (if available) and uses that to populate `references` in the schema.
Supported references have parsers and are able to extract the ID of the entry for that source and store them in `reference_ids`.

WIP. 🚧

---

## Installation
1. Install PHP8.2+, MongoDB, MongoDB PHP Driver, Composer
2. `git clone https://github.com/Hiyori-API/hiyori-cli.git`
3. `cd hiyori-cli && composer install`
4. `chmod +x hiyori`
5. Configure `.env` with your API credentials (see `.env.example`)
5. Run commands


### Ingestion

You can use the cli tool as `./hiyori`, `php hiyori` or `php src/run.php`.

> [!NOTE]
> It's recommended to keep a 1-second delay between requests for sources to prevent rate-limiting.

#### Anime Ingestion

##### MyAnimeList (Official API v2)
```console
php hiyori ingest myanimelist --delay 1
```

##### Kitsu
```console
php hiyori ingest kitsu --delay 1
```

##### AniList
```console
php hiyori ingest anilist --delay 1
```

##### Ingestion Options
| Option | Description |
|--------|-------------|
| `--delay`, `-d` | Delay between requests in seconds (default: 1) |
| `--update`, `-u` | Update existing entries instead of skipping |
| `--resume-page`, `-r` | Resume from specific page number |

> [!NOTE]
> 1: General spoiler or Media spoiler tags are not ingested.
> 
> 2: The total entries shown will max out at `5000`. This is just a data issue with the data returned
> It will not affect the ingestion process as the value will be updated after paginating past `5000`
> You can find out more about this known-issue on [AniList's discord server](https://discord.com/channels/210521487378087947/281216402684116993/931028449081032724).


#### Manga Ingestion

##### MyAnimeList Manga (Official API v2)
```console
php hiyori ingest myanimelist_manga --delay 1
```

##### Kitsu Manga
```console
php hiyori ingest kitsu_manga --delay 1
```

##### AniList Manga
```console
php hiyori ingest anilist_manga --delay 1
```

> [!NOTE]
> Manga ingestion uses the same options as anime ingestion (`--delay`, `--update`, `--resume-page`)

---

### Combiner

> [!NOTE]
> Run the combiner after the ingestion of multiple sources are complete.

```console
php hiyori combine {base} --strategy {strategy}
```

- {base} the name of the source to dataset to use
- There are multiple combining strategies available. The default one is `relational_mapping`.

```console
// example
php hiyori combine myanimelist
// specify a strategy
php hiyori combine myanimelist --strategy relational_mapping
```

### Available Combining Strategies

#### Relational Mapping `--strategy relational_mapping`
This strategy simply checks for matching source IDs across available source datasets and combines values based off of those.

#### Relational Fuzzy `--strategy relational_fuzzy`
This strategy cross-checks all `reference_ids` across available source datasets in hopes to find a matching ID between sources.

#### Metadata Fuzzy `--strategy metadata_fuzzy`
This strategy compares other metadata across available source datasets.
The metadata properties are given weights and are cross checked using various algorithms (Levenshtein for titles, Jaccard for tags).
Based on this, a score is given. If the score is above a certain threshold, it is merged.

```console
php hiyori combine myanimelist --strategy metadata_fuzzy
```

---

## Utility Commands

### References
Returns all unique reference IDs from the combined organized collection.

```console
php hiyori references [--source mal] [--json] [-v]
```

### Weightage
Calculates ideal metadata field weights for fuzzy matching between two sources using ground-truth matches.

```console
php hiyori weightage myanimelist anilist [--sample 1000] [--json]
```

### Sweeper
Cleans up orphaned, duplicate, or incomplete entries from source collections.

```console
php hiyori sweep [--source myanimelist] [--dry-run] [--orphans] [--duplicates] [--incomplete] [--all]
```

### Export
Exports data from sources or combined DB in JSON, JSONL, or CSV format.

```console
php hiyori export [organized|myanimelist|anilist|kitsu] [--format json|jsonl|csv] [--output file.json] [--fields title,type,status] [--limit 100] [--pretty]
```

### Health Check
Health check for services and dependencies (MongoDB, external APIs, rate limiter, disk, memory).

```console
php hiyori health [--json] [--watch] [--interval 30]
```

### Metrics
Expose Prometheus metrics for scraping.

```console
php hiyori metrics
```

---

## Hiyori Schema
Common data with different representations like status (MAL: "Finished Airing", Anilist: "Finished") are transformed into a common value via Hiyori's Enums.

### Anime

| Property        | Data Type          | Remarks                                                            | Nullable |
|-----------------|--------------------|--------------------------------------------------------------------|----------|
| `title`         | _String_           | Entry's main title                                                 | ❌        |
| `synonyms`      | _Array of String_  | All other titles (combined from sources)                           | ❌        |
| `type`          | _String_           | Entry type [`tv`, `movie`, `ova`, `ona`, `special`, `music`]       | ✅        |
| `episodes`      | _Integer_          | Number of episodes (if any mentioned)                              | ✅        |
| `status`        | _String_           | Status of entry [`finished`, `airing`, `to_be_aired`, `cancelled`] | ✅        |
| `season`        | _Nullable String_  | Release Season [`winter`, `summer`, `spring`, `fall`]              | ✅        |
| `year`          | _Nullable Integer_ | Release Year                                                       | ✅        |
| `images`        | _Array of String_  | Default Image URLs (combined from sources)                         | ❌        |
| `reference_ids` | _Object_           | Parsed IDs from Supported References                               | ❌        |
| `references`    | _Array of String_  | Reference URLs (combined from sources)                             | ❌        |
| `tags`          | _Array of String_  | Genres/Tags (combined from sources)                                | ❌        |

### Manga

| Property        | Data Type          | Remarks                                                            | Nullable |
|-----------------|--------------------|--------------------------------------------------------------------|----------|
| `title`         | _String_           | Entry's main title                                                 | ❌        |
| `synonyms`      | _Array of String_  | All other titles (combined from sources)                           | ❌        |
| `type`          | _String_           | Entry type [`manga`, `novel`, `light_novel`, `one_shot`, `doujinshi`, `manhwa`, `manhua`, `oel`] | ✅        |
| `chapters`      | _Integer_          | Number of chapters (if any mentioned)                              | ✅        |
| `volumes`       | _Integer_          | Number of volumes (if any mentioned)                               | ✅        |
| `status`        | _String_           | Status of entry [`finished`, `publishing`, `not_yet_published`, `on_hiatus`, `discontinued`] | ✅        |
| `year`          | _Nullable Integer_ | Release Year                                                       | ✅        |
| `images`        | _Array of String_  | Default Image URLs (combined from sources)                         | ❌        |
| `reference_ids` | _Object_           | Parsed IDs from Supported References                               | ❌        |
| `references`    | _Array of String_  | Reference URLs (combined from sources)                             | ❌        |
| `tags`          | _Array of String_  | Genres/Tags (combined from sources)                                | ❌        |

---

### Supported References

The following URL type, if detected in an entry's `references`, will be parsed.

| Reference          | Shorthand (as returned) |
|--------------------|-------------------------|
| [Amazon Prime](https://github.com/Hiyori-API/hiyori-cli/blob/master/src/Models/Common/Identifiers/AmazonPrime.php)       | `ap`                    |
| [AniDB](https://github.com/Hiyori-API/hiyori-cli/blob/master/src/Models/Common/Identifiers/AniDB.php)              | `adb`                   |
| [AniList](https://github.com/Hiyori-API/hiyori-cli/blob/master/src/Models/Common/Identifiers/AniList.php)            | `al`                    |
| [AnimeNewsNetwork](https://github.com/Hiyori-API/hiyori-cli/blob/master/src/Models/Common/Identifiers/AnimeNewsNetwork.php)   | `ann`                   |
| [Bangumi](https://github.com/Hiyori-API/hiyori-cli/blob/master/src/Models/Common/Identifiers/Bangumi.php)            | `b`                     |
| [Crunchyroll](https://github.com/Hiyori-API/hiyori-cli/blob/master/src/Models/Common/Identifiers/Crunchyroll.php)        | `cr`                    |
| [Funimation](https://github.com/Hiyori-API/hiyori-cli/blob/master/src/Models/Common/Identifiers/Funimation.php)         | `fm`                    |
| [Kitsu](https://github.com/Hiyori-API/hiyori-cli/blob/master/src/Models/Common/Identifiers/Kitsu.php)              | `k`                     |
| [MyAnimeList](https://github.com/Hiyori-API/hiyori-cli/blob/master/src/Models/Common/Identifiers/MyAnimeList.php)        | `mal`                   |
| [Netflix](https://github.com/Hiyori-API/hiyori-cli/blob/master/src/Models/Common/Identifiers/Netflix.php)            | `nf`                    |
| [Syoboi](https://github.com/Hiyori-API/hiyori-cli/blob/master/src/Models/Common/Identifiers/Syoboi.php)             | `sb`                    |
| [Trakt](https://github.com/Hiyori-API/hiyori-cli/blob/master/src/Models/Common/Identifiers/Trakt.php)              | `t`                     |
| [TubiTV](https://github.com/Hiyori-API/hiyori-cli/blob/master/src/Models/Common/Identifiers/TubiTV.php)             | `ttv`                   |
| [TVTokyo](https://github.com/Hiyori-API/hiyori-cli/blob/master/src/Models/Common/Identifiers/TVTokyo.php)            | `tvt`                   |
| [Twitter](https://github.com/Hiyori-API/hiyori-cli/blob/master/src/Models/Common/Identifiers/Twitter.php)            | `tx`                    |
| [Wikipedia English](https://github.com/Hiyori-API/hiyori-cli/blob/master/src/Models/Common/Identifiers/WikipediaEN.php)  | `wpen`                  |
| [Wikipedia Japanese](https://github.com/Hiyori-API/hiyori-cli/blob/master/src/Models/Common/Identifiers/WikipediaJA.php) | `wpjp`                  |
| [YouTube](https://github.com/Hiyori-API/hiyori-cli/blob/master/src/Models/Common/Identifiers/YouTube.php)            | `yt`                    |

---

## Configuration

### Environment Variables (`.env`)

| Variable | Description | Required |
|----------|-------------|----------|
| `APP_ENV` | Environment (prod/dev/test) | Yes |
| `APP_DEBUG` | Debug mode (true/false) | Yes |
| `APP_VERSION` | Application version | Yes |
| `MONGODB_URL` | MongoDB connection string | Yes |
| `MAL_CLIENT_ID` | MyAnimeList API Client ID | For MAL ingestion |
| `MAL_CLIENT_SECRET` | MyAnimeList API Client Secret | For MAL ingestion |
| `RATE_LIMIT_MAX` | Max requests per window (default: 100) | No |
| `RATE_LIMIT_WINDOW` | Time window in seconds (default: 60) | No |
| `HTTP_CACHE` | Enable HTTP caching (true/false) | No |
| `HTTP_CACHE_PATH` | Cache directory path | No |
| `HTTP_CACHE_TTL` | Cache TTL in seconds | No |
| `PROGRESSBAR_FORMAT` | Progress bar format string | No |
| `PROGRESSBAR_REDRAW_FREQ` | Progress bar redraw frequency | No |

### Rate Limiting
Each source (myanimelist, anilist, kitsu, myanimelist_manga, anilist_manga, kitsu_manga) has its own token bucket with configurable max requests and time window. Circuit breaker opens if failure rate exceeds 50%.

---

## Docker Deployment

```bash
# Build and start services
docker-compose up -d

# Run ingestion
docker-compose exec hiyori php hiyori ingest myanimelist --delay 1

# Health check
docker-compose exec hiyori php hiyori health --json
```

---

## Testing & Quality

```bash
# Run tests
./vendor/bin/phpunit -c phpunit.xml.dist

# Static analysis
./vendor/bin/phpstan analyse src --level=5

# Code style
./vendor/bin/php-cs-fixer fix --dry-run --diff
```

---

## Roadmap

👉 [View Roadmap](https://github.com/orgs/Hiyori-API/projects/1)

## FAQ

<details>
<summary>Click to expand</summary>
  
##### Why does Hiyori Schema consist of metadata?
In the event an entry from a source does not contain relational IDs or mapping provided by sources, the metadata will be used to find the relation instead.

##### Will you add more to the metadata upon request?
We're only using metadata to create a relation score if there's no direct reference found between entries. There are no plans to add more metadata unless it can somehow help create a better relation score for unmapped entries.
</details>

---

> [!IMPORTANT]
> Hiyori is not affiliated with any sources. You are responsible for the usage of this tool. Please be respectful of the terms and conditions set by these sources.

---

## Live Dashboard

A live ops dashboard (React + shadcn/ui frontend, PHP backend) that monitors ingestion, validation, and combine in real time.

```console
# Serve the dashboard (UI + API + Prometheus metrics on one port)
php hiyori dashboard:serve --port 8080

# Optional access token
php hiyori dashboard:serve --port 8080 --token mysecret
```

Open <http://localhost:8080>. The SPA is served from `web/dist` (committed, no build step needed);
run `cd web && npm run build` after frontend changes.

### Endpoints

| Path | Purpose |
|------|---------|
| `/` | Dashboard SPA |
| `/api/status` | Aggregated snapshot (counts, jobs, db, controls, cache, health, validation, coverage, alerts, slow, schedule) |
| `/api/counts` | Per-collection counts |
| `/api/db` | DB size/index/docs stats |
| `/api/jobs` | Ingestion telemetry (per source: processed/saved/skipped/errors/speed/ETA) |
| `/api/health` | External API latency + Mongo + disk/mem |
| `/api/validate` | Recent validation violations |
| `/api/coverage` | Reference-shorthand coverage matrix per collection |
| `/api/benchmark` | Seeded 3-strategy comparison + latest combine |
| `/api/alerts` | Anomaly evaluation (circuit open, low tokens, API down); posts to `WEBHOOK_URL` when set |
| `/api/sample` | Random doc for the merge sampler |
| `/api/slow` | Request latency extremes (>2s) |
| `/api/schedule` | Scheduled-run info |
| `/api/action?job=&cmd=pause\|resume\|retry` | Control jobs |
| `/api/backup?run=1` | Trigger async mongodump |
| `/api/events` | SSE stream of status snapshots |
| `/metrics` | Prometheus text |

### Telemetry & control files

- `storage/state/ingest-<source>.json` — live ingestion status (written by the ingest loops)
- `storage/state/control-<source>.json` — `{"paused": true}` pauses that source (polls every second)
- `storage/state/validation-log.jsonl` — validation violation feed
- `storage/state/slow-requests.jsonl` — requests slower than 2s

### Webhook alerts

Set `WEBHOOK_URL` (and optionally `SCHEDULE_SOURCES`, `SCHEDULE_INTERVAL`) in `.env`; alerts are
posted once per identity per hour.

### PWA

The dashboard is installable (manifest + service worker) and falls back to cached data when offline.
