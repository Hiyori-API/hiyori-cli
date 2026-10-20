<?php

namespace Hiyori\Service;

use Hiyori\Service\Combiner\Strategy\FellegiSunterStrategy;
use Hiyori\Service\Combiner\Strategy\GraphMergeStrategy;
use Hiyori\Service\Combiner\Strategy\MetadataFuzzyStrategy;
use Hiyori\Service\Combiner\Strategy\MetadataMlStrategy;
use Hiyori\Service\Combiner\Strategy\RelationalFuzzyStrategy;
use Hiyori\Service\Combiner\Strategy\RelationalMappingStrategy;
use Hiyori\Service\Combiner\Strategy\SemanticRerankStrategy;

/**
 * The config schema of the combiner strategies.
 *
 * Every key a strategy reads with `getConfig()` is listed here, so a key that
 * is not in the schema is a typo and can be refused instead of being ignored.
 * The lists were derived by reading every `getConfig()` call in
 * `src/Service/Combiner/Strategy/` — a key that is read but not declared
 * would refuse a working command, and a key that is declared but never read
 * is dead weight.
 *
 * The schema lives here rather than on the strategy classes so that the
 * strategies themselves stay free of validation concerns.
 */
final class StrategyConfigKeys
{
    /**
     * Keys every strategy accepts.
     *
     * Two groups end up here:
     *
     * - keys more than one strategy reads (`collection`, `same_kind_candidates`,
     *   `threshold`, `weights`, `passthrough`, `title_floor`, the anchor
     *   keys, `resume`);
     * - keys the CLI itself produces for every run (`collection`, `resume`,
     *   `sweep`) or through a flag that is not tied to one strategy
     *   (`--threshold`, `--weights`, `--passthrough`, `--rejection-buffer`,
     *   `--rejection-buffer-floor`). The release pipeline always passes
     *   `--passthrough`, whatever strategy it is pointed at, so these cannot
     *   be tied to a single strategy.
     *
     * @var list<string>
     */
    public const SHARED = [
        'anchor_episodes',
        'anchor_rejection',
        'anchor_year_tolerance',
        'collection',
        'passthrough',
        'rejection_buffer',
        'rejection_buffer_floor',
        'resume',
        'same_kind_candidates',
        'sweep',
        'threshold',
        'title_floor',
        'weights',
    ];

    /**
     * The keys each strategy reads on top of {@see SHARED}.
     *
     * @var array<string, list<string>>
     */
    public const PER_STRATEGY = [
        RelationalMappingStrategy::NAME => [],
        RelationalFuzzyStrategy::NAME => [],
        MetadataFuzzyStrategy::NAME => [
            'rejection_buffer_collection',
        ],
        GraphMergeStrategy::NAME => [
            'link_threshold',
            'max_depth',
        ],
        FellegiSunterStrategy::NAME => [
            'agreement_cutoff',
            'm',
            'mu_file',
            'u',
        ],
        SemanticRerankStrategy::NAME => [
            'embedding_command',
            'embedding_dimensions',
            'embedding_model',
            'metadata_weight',
            'prefilter_threshold',
            'semantic_weight',
        ],
        MetadataMlStrategy::NAME => [
            'metadata_floor',
            'ml_model',
            'ml_threshold',
        ],
    ];

    /**
     * The keys the given strategy accepts.
     *
     * @return list<string>
     */
    public static function forStrategy(string $strategy): array
    {
        $keys = array_merge(self::SHARED, self::PER_STRATEGY[$strategy] ?? []);

        sort($keys);

        return array_values($keys);
    }

    /**
     * Every key any strategy accepts.
     *
     * Used when no strategy is selected yet: a key no strategy reads is still
     * a typo, even if the strategy that would have read it is not known yet.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        $keys = self::SHARED;
        foreach (self::PER_STRATEGY as $own) {
            $keys = array_merge($keys, $own);
        }

        sort($keys);

        return array_values(array_unique($keys));
    }
}
