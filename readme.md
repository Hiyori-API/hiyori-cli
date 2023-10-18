# Hiyori CLI
Hiyori CLI is being developed to create and maintain [HiyoriDB](https://github.com/Hiyori-API/HiyoriDB) — a relational Anime and Manga Database.

## Process
Hiyori CLI works by crawling sources, parsing the data, and transforming them into the Hiyori Schema which contains combined metadata gathered from sources.
This metadata is stored in MongoDB. When other source processes are run, then it focuses on updating the metadata with missing information.

Hiyori also collects relational mappings from other sources (if available) and uses that to populate `references` in the schema.
Supported references have parsers and are able to extract the ID of the entry for that source and store them in `reference_ids`.

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

### Supported References

The following URL type, if detected in an entry's `references`, will be parsed.

| Reference          | Shorthand (as returned) |
|--------------------|-------------------------|
| AniDB              | `anidb`                 |
| AnimeNewsNetwork   | `ann`                   |
| Crunchyroll        | `cr`                    |
| Funimation         | `fm`                    |
| MyAnimeList        | `mal`                   |
| Netflix            | `nf`                    |
| Syoboi             | `sb`                    |
| TubiTV             | `ttv`                   |
| Wikipedia English  | `wp_en`                 |
| Wikipedia Japanese | `wp_jp`                 |

## Roadmap
Right now the main focus will be to integrate the initial 3 sources and build a relational Anime DB.

### QOL
- [ ] Environment variables
- [ ] Resumable Support
- [ ] Logging
- [ ] Tests

### Feature
- [ ] Manga Relational DB

### Sources
- [x] MyAnimeList Integration via REST API
- [ ] Kitsu Integration via REST API
- [ ] AniList Integration via REST API


## WIP
This is work in progress.