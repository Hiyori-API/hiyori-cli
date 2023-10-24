<?php

namespace Hiyori\Models\Common\Identifiers;


class Identifiers
{
    private array $ids = [];
    private array $links;

    public const PATTERN = "";

    private const availableIdentifiers = [
        AmazonPrime::class,
        AniDB::class,
        AniList::class,
        AnimeNewsNetwork::class,
        Bangumi::class,
        Crunchyroll::class,
        Funimation::class,
        Kitsu::class,
        MyAnimeList::class,
        Netflix::class,
        Syoboi::class,
        Trakt::class,
        TubiTV::class,
        WikipediaEN::class,
        WikipediaJA::class,
        YouTube::class
    ];

    /**
     * @param array $links
     */
    public function __construct(array $links = [])
    {
        $this->links = $links;
    }

    public static function pairsToLinks(array $pairs = []): array
    {
        $links = [];
        foreach ($pairs as $source) {
            foreach (self::availableIdentifiers as $identifier) {
                if (str_contains($identifier::ENTRY_URL, $source[0])) {
                    $links[] = sprintf($identifier::ENTRY_URL, $source[1]);
                }
            }
        }

        return $links;
    }


    public function getModel(): array
    {
        foreach ($this->links as $link) {
            list($identifier, $value) = self::parseId($link);

            if (!is_null($value)) {
                $this->ids[$identifier::SHORTHAND] = (new $identifier())->setId($value)->getId();
            }
        }

        return $this->ids;
    }



    public static function parseId(string $url): array
    {
        foreach (self::availableIdentifiers as $identifier) {
            if (preg_match('~'.$identifier::PATTERN.'~', $url, $parsedId)) {
                return [$identifier, $parsedId[1]];
            }
        }

        return [null, null];
    }
}