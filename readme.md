# Hiyori CLI
Hiyori CLI is being developed to create and maintain [HiyoriDB](https://github.com/Hiyori-API/HiyoriDB) — a relational Anime and Manga Database.

## Process
Hiyori CLI has two main primary functions.
1. Ingesting transformed data based on the Hiyori Schema ingested from available sources
2. Combining the ingested data into HiyoriDB

Hiyori also collects relational mappings from other sources (if available) and uses that to populate `references` in the schema.
Supported references have parsers and are able to extract the ID of the entry for that source and store them in `reference_ids`.

WIP. 🚧

---

## Installation
1. Install PHP8.1+, MongoDB, MongoDB PHP Driver, Composer
3. `git clone https://github.com/Hiyori-API/hiyori-cli.git`
4. `cd hiyori-cli && composer install`
5. `chmod +x hiyori`
5. Run commands

## Usage
![image](https://github.com/Hiyori-API/hiyori-cli/assets/9166451/8357ef11-22fd-4492-93c4-737648ece7d7)

### Ingestion

You can use the cli tool as `./hiyori` or `php hiyori`.

#### MyAnimeList Ingestion
```sh
php hiyori indexer:anime Hiyori\\Sources\\MyAnimeList\\MyAnimeListIngestion --delay 1
```

#### Kitsu Ingestion
```sh
php hiyori indexer:anime Hiyori\\Sources\\Kitsu\\KitsuIngestion --delay 1
```

#### AniList Ingestion
```sh
php hiyori indexer:anime Hiyori\\Sources\\Kitsu\\KitsuIngestion --delay 1
```

It's recommended to keep a 1-second delay between requests for the MyAnimeList source.

### Combiner
🚧 WIP

---

## Hiyori Schema
Common data with different representations like status (MAL: "Finished Airing", Anilist: "Finished") are transformed into a common value via Hiyori's Enums.

### Anime

| Property        | Data Type         | Remarks                                    |
|-----------------|-------------------|--------------------------------------------|
| `title`         | _String_          | Entry's main title                         |
| `synonyms`      | _Array of String_ | All other titles (combined from sources)   |
| `type`          | _String_          | Entry type [Hiyori Enum]                   |
| `episodes`      | _Nullable Integer_ | Number of episodes (if any mentioned)      |
| `status`        | _String_          | Status of entry [Hiyori Enum]              |
| `status`        | _Nullable String_ | Release Season [Hiyori Enum]               |
| `year`          | _Nullable Integer_ | Release Year [Hiyori Enum]                 |
| `images`        | _Array of String_ | Default Image URLs (combined from sources) |
| `reference_ids` | _Object_          | Parsed IDs from Supported References       |
| `references`    | _Array of String_ | Reference URLs (combined from sources)     |
| `tags`          | _Array of String_ | Genres/Tags (combined from sources)        |

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
Right now the main focus will be to integrate the initial 3 sources and build a relational Anime metadata DB.

### QOL
- [ ] Allow to update entry (if it exists) instead of skipping during ingestion
- [ ] Environment variables
- [ ] Logging
- [ ] Tests
- [ ] Sweepers

### Feature
- [ ] Manga Relational DB
- [ ] Resumable Support
- [ ] Package Sources instead (Symfony Services/Containers perhaps)
- [ ] Caching
- [ ] Dependency Injection


### Sources
- [x] MyAnimeList Integration via REST API
- [x] Kitsu Integration via REST API
- [ ] AniList Integration via REST API

## FAQ
**Why does Hiyori Schema consist of metadata?**

In the event an entry from a source does not contain relational IDs or mapping provided by sources, the metadata will be used to find the relation instead.

## WIP
This is a work in progress. 


# Disclaimer
- Hiyori is not affiliated with any sources
- You are responsible for the usage of this tool. Please be respectful towards the terms and conditions set by these sources.