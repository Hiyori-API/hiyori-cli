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
3. `git clone https://github.com/Hiyori-API/hiyori-cli.git`
4. `cd hiyori-cli && composer install`
5. `chmod +x hiyori`
5. Run commands


### Ingestion

You can use the cli tool as `./hiyori`, `php hiyori` or `php src/run.php`.

#### MyAnimeList Ingestion
```console
php hiyori ingest myanimelist --delay 1
```

#### Kitsu Ingestion
```console
php hiyori ingest kitsu --delay 1
```

#### AniList Ingestion
```console
php hiyori ingest anilist --delay 1
```

> [!NOTE]
> It's recommended to keep a 1-second delay between requests for sources to prevent rate-limiting.

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
// specify a stratgegy
php hiyori combine myanimelist --strategy relational_mapping
```

### Available Combining Strategies

#### Relational Mapping
This strategy simply checks for matching source IDs across available source datasets and combines values based off of those.

#### Relational Fuzzy
This strategy cross-checks all `reference_ids` across available source datasets in hopes to find a matching ID between sources.

> [!WARNING]
> Work in progress


#### Metadata Fuzzy*
> [!WARNING]
> This is a long-running process

*Name subject to change

This strategy compares other metadata across available source datasets.
The metadata properties are given weights and are cross checked using various algorithms.
Based on this, a score is given. If the score is above a certain threshold, it is merged. 

> [!WARNING]
> Work in progress


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
TBD.

---

### Supported References

The following URL type, if detected in an entry's `references`, will be parsed.

| Reference          | Shorthand (as returned) |
|--------------------|-------------------------|
| Amazon Prime       | `ap`                    |
| AniDB              | `adb`                   |
| AniList            | `al`                    |
| AnimeNewsNetwork   | `ann`                   |
| Crunchyroll        | `cr`                    |
| Funimation         | `fm`                    |
| Kitsu              | `k`                     |
| MyAnimeList        | `mal`                   |
| Netflix            | `nf`                    |
| Syoboi             | `sb`                    |
| Trakt              | `t`                     |
| TubiTV             | `ttv`                   |
| Wikipedia English  | `wpen`                  |
| Wikipedia Japanese | `wpjp`                  |

---

## Roadmap
Right now the main focus is to integrate the initial 3 sources and build a combined relational Anime metadata DB.

### Quality of Life
- [ ] Tests
- [ ] Sweepers - Remove dead entries (or create a separate collection of them)

### Feature
- [ ] [Ingestion: Allow re-try of failed requests](https://github.com/Hiyori-API/hiyori-cli/issues/9)
- [ ] [Ingestion: Alow resume](https://github.com/Hiyori-API/hiyori-cli/issues/13)
- [ ] [Caching](https://github.com/Hiyori-API/hiyori-cli/issues/12)
- [ ] [Ingestion: Allow flag for metadata update](https://github.com/Hiyori-API/hiyori-cli/issues/3)
- [x] [EV for secrets](https://github.com/Hiyori-API/hiyori-cli/issues/4)
- [ ] [Logging](https://github.com/Hiyori-API/hiyori-cli/issues/6)
- [x] [Tool: Combiner](https://github.com/Hiyori-API/hiyori-cli/issues/10)
- [ ] [Tool: Export](https://github.com/Hiyori-API/hiyori-cli/issues/11)
- [ ] Manga Relational DB
- [x] Dependency Injection / Service Containers


### Sources
- [x] MyAnimeList Integration via REST API
- [x] Kitsu Integration via REST API
- [x] AniList Integration via REST API

## FAQ
**Why does Hiyori Schema consist of metadata?**

In the event an entry from a source does not contain relational IDs or mapping provided by sources, the metadata will be used to find the relation instead.


> [!IMPORTANT]
> Hiyori is not affiliated with any sources. You are responsible for the usage of this tool. Please be respectful towards the terms and conditions set by these sources.
