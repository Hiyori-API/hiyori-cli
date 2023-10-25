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

> [!NOTE]
> It's recommended to keep a 1-second delay between requests for sources to prevent rate-limiting.

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
> 1: General spoiler or Media spoiler tags are not ingested.
> 
> 2: The total entries shown will max out at `5000`. This is just a data issue with the data returned
> It will not affect the ingestion process as the value will be updated after paginating past `5000`
> You can find out more about this known-issue on [AniList's discord server](https://discord.com/channels/210521487378087947/281216402684116993/931028449081032724).



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

#### Relational Mapping `--strategy relational_mapping`
This strategy simply checks for matching source IDs across available source datasets and combines values based off of those.

#### Relational Fuzzy `--strategy relational_fuzzy`
This strategy cross-checks all `reference_ids` across available source datasets in hopes to find a matching ID between sources.


#### Metadata Fuzzy
> [!WARNING]
> This is not yet implemented.

This strategy compares other metadata across available source datasets.
The metadata properties are given weights and are cross checked using various algorithms.
Based on this, a score is given. If the score is above a certain threshold, it is merged. 

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
