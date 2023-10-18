<?php

namespace Hiyori\Models\Common\Identifiers;


class Identifiers
{
    private array $ids = [];
    private array $links;

    public const PATTERN = "";

    /**
     * @param array $links
     */
    public function __construct(array $links = [])
    {
        $this->links = $links;
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


    private const availableIdentifierPatterns = [
        AniDB::class,
        AnimeNewsNetwork::class,
        Crunchyroll::class,
        Funimation::class,
        MyAnimeList::class,
        Netflix::class,
        Syoboi::class,
        TubiTV::class,
        WikipediaEN::class,
        WikipediaJA::class
    ];
    public static function parseId(string $url): array
    {
        foreach (self::availableIdentifierPatterns as $identifierPattern) {
            if (preg_match('~'.$identifierPattern::PATTERN.'~', $url, $parsedId)) {
                return [$identifierPattern, $parsedId[1]];
            }
        }

        return [null, null];
    }
}