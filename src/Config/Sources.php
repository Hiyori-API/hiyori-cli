<?php

return [
    "myanimelist" => [
        "name" => "MyAnimeList",
        "table" => "myanimelist",
        "shorthand" => "mal",
        "json_id" => "mal_id",
        "ingestion_service" => \Hiyori\Service\Ingestion\Source\MyAnimeListIngestion::class
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
];