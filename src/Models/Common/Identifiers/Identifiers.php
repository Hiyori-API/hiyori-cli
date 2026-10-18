<?php

namespace Hiyori\Models\Common\Identifiers;

class Identifiers
{
    private array $ids = [];

    private array $links;

    public const PATTERN = '';

    private const availableIdentifiers = [
        AmazonPrime::class,
        AniDB::class,
        AniList::class,
        AniListManga::class,
        AnimeNewsNetwork::class,
        Bangumi::class,
        Crunchyroll::class,
        Funimation::class,
        Kitsu::class,
        KitsuManga::class,
        MyAnimeList::class,
        MyAnimeListManga::class,
        Netflix::class,
        Syoboi::class,
        Trakt::class,
        TubiTV::class,
        TVTokyo::class,
        Twitter::class,
        WikipediaEN::class,
        WikipediaJA::class,
        YouTube::class,
    ];

    /**
     * @param array $links
     */
    public function __construct(array $links = [])
    {
        $this->links = $links;
    }

    /**
     * Turn `[host, id]` pairs scraped from a source's external links into
     * canonical URLs for this entry.
     *
     * `$kind` matters: every source has an anime and a manga URL shape, and a
     * host like `myanimelist.net` is a substring of both. Without the filter a
     * manga's MAL link produced an anime URL and vice versa, putting the other
     * kind's id into `reference_ids`.
     */
    public static function pairsToLinks(array $pairs = [], string $kind = 'anime'): array
    {
        $wantsManga = $kind === 'manga';
        $links = [];
        foreach ($pairs as $source) {
            if (!isset($source[0], $source[1])) {
                continue;
            }
            foreach (self::availableIdentifiers as $identifier) {
                $isMangaIdentifier = str_ends_with($identifier::SHORTHAND, '_manga');
                if ($isMangaIdentifier !== $wantsManga) {
                    continue;
                }
                if (str_contains($identifier::ENTRY_URL, (string) $source[0])) {
                    $links[] = sprintf($identifier::ENTRY_URL, $source[1]);
                }
            }
        }

        return $links;
    }

    public function getModel(): array
    {
        foreach ($this->links as $link) {
            [$identifier, $value] = self::parseId($link);

            if (!is_null($value)) {
                $this->ids[$identifier::SHORTHAND] = (new $identifier())->setId($value)->getId();
            }
        }

        return $this->ids;
    }

    public static function parseId(string $url): array
    {
        foreach (self::availableIdentifiers as $identifier) {
            if (preg_match('~' . $identifier::PATTERN . '~', $url, $parsedId)) {
                return [$identifier, $parsedId[1]];
            }
        }

        return [null, null];
    }
}
