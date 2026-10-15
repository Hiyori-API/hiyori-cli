<?php

namespace Hiyori\Requests\Kitsu;

use Hiyori\Requests\Entry;
use Hiyori\Requests\Request;

/**
 * Single Kitsu detail request that also embeds the relationships the legacy
 * code fetched with four separate HTTP calls (mappings, streaming links,
 * genres, categories). One request per entry instead of four.
 */
class KitsuEntryDetail extends Entry
{
    public const ANIME_ENTRYPOINT = 'https://kitsu.io/api/edge/anime/%d?include=mappings,streamingLinks,genres,categories';

    public const MANGA_ENTRYPOINT = 'https://kitsu.io/api/edge/manga/%d?include=mappings,genres,categories';

    public static function create(int &$id, string $kind = 'anime'): self
    {
        $self = new self();
        $endpoint = $kind === 'manga' ? self::MANGA_ENTRYPOINT : self::ANIME_ENTRYPOINT;

        $response = Request::fetch(sprintf($endpoint, $id), Request::GET)->toArray();
        $self->setData(self::hydrate($response));

        return $self;
    }

    /**
     * Fold JSON:API `included` resources into the shape KitsuBase expects.
     *
     * @param array<string, mixed> $response
     *
     * @return array<string, mixed>
     */
    public static function hydrate(array $response): array
    {
        $data = is_array($response['data'] ?? null) ? $response['data'] : [];
        $included = [];
        foreach (($response['included'] ?? []) as $resource) {
            if (!is_array($resource)) {
                continue;
            }
            $type = (string) ($resource['type'] ?? '');
            $id = (string) ($resource['id'] ?? '');
            if ($type !== '' && $id !== '') {
                $included[$type][$id] = $resource;
            }
        }

        $relationships = is_array($data['relationships'] ?? null) ? $data['relationships'] : [];
        $resolve = static function (string $relationship, string $resourceType) use ($relationships, $included): array {
            $ids = [];
            foreach (($relationships[$relationship]['data'] ?? []) as $ref) {
                if (is_array($ref) && isset($ref['id'])) {
                    $ids[] = (string) $ref['id'];
                }
            }
            $out = [];
            foreach ($ids as $id) {
                if (isset($included[$resourceType][$id])) {
                    $out[] = $included[$resourceType][$id];
                }
            }

            return $out;
        };

        return array_merge($data, [
            'external' => $resolve('mappings', 'mappings'),
            'streaming' => $resolve('streamingLinks', 'streamingLinks'),
            'genres' => $resolve('genres', 'genres'),
            'categories' => $resolve('categories', 'categories'),
        ]);
    }
}
