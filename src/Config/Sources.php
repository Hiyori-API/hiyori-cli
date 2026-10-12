<?php

return [
    // Anime sources
    "myanimelist" => [
        "name" => "MyAnimeList",
        "table" => "myanimelist",
        "shorthand" => "mal",
        "json_id" => "id",
        "ingestion_service" => \Hiyori\Service\Ingestion\Source\MyAnimeListOfficialIngestion::class
    ],
    "kitsu" => [
        "name" => "Kitsu",
        "table" => "kitsu",
        "shorthand" => "k",
        "json_id" => "id",
        "ingestion_service" => \Hiyori\Service\Ingestion\Source\KitsuIngestion::class
    ],
    "anilist" => [
        "name" => "AniList",
        "table" => "anilist",
        "shorthand" => "al",
        "json_id" => "id",
        "ingestion_service" => \Hiyori\Service\Ingestion\Source\AniListIngestion::class
    ],

    // Manga sources
    "myanimelist_manga" => [
        "name" => "MyAnimeList Manga",
        "table" => "myanimelist_manga",
        "shorthand" => "mal_manga",
        "json_id" => "id",
        "ingestion_service" => \Hiyori\Service\Ingestion\Source\MyAnimeListMangaIngestion::class
    ],
    "kitsu_manga" => [
        "name" => "Kitsu Manga",
        "table" => "kitsu_manga",
        "shorthand" => "k_manga",
        "json_id" => "id",
        "ingestion_service" => \Hiyori\Service\Ingestion\Source\KitsuMangaIngestion::class
    ],
    "anilist_manga" => [
        "name" => "AniList Manga",
        "table" => "anilist_manga",
        "shorthand" => "al_manga",
        "json_id" => "id",
        "ingestion_service" => \Hiyori\Service\Ingestion\Source\AniListMangaIngestion::class
    ],
];
