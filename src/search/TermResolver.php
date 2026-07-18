<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\search;

use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\search\storage\StorageInterface;

/**
 * TermResolver
 *
 * Layer-2 of the shared search/autocomplete core: the single term-resolution
 * policy. Given one query token, returns every index term it should match —
 * exact, prefix (opt-in, for completing a token being typed), and fuzzy — with
 * one similarity threshold and one candidate bound for both surfaces.
 *
 * Fuzzy is a two-tier EXPANDER, not a fallback:
 * - every token resolves exact + a small top-K fuzzy expansion
 *   ({@see self::FUZZY_EXPANSION_LIMIT}) even when exact matches exist, so a
 *   rare singular ("tool" in 1 doc) no longer suppresses its variants ("tools")
 * - a token with ZERO exact matches keeps full fallback breadth
 *   (maxFuzzyCandidates) for typo recovery
 *
 * Each fuzzy entry carries its Jaccard similarity and a pre-computed `weight`
 * (similarity × {@see self::FUZZY_WEIGHT}) so Layer-3 scoring keeps exact
 * matches ranked first without re-deriving the policy.
 *
 * @since 5.53.0
 */
final class TermResolver
{
    use LoggingTrait;

    public const MATCH_EXACT = 'exact';
    public const MATCH_PREFIX = 'prefix';
    public const MATCH_FUZZY = 'fuzzy';

    /**
     * Top-K fuzzy candidates kept when the token also has exact matches.
     * Deliberately small: expansion adds recall, the cap protects precision.
     */
    private const FUZZY_EXPANSION_LIMIT = 3;

    /**
     * Score multiplier applied to fuzzy-matched terms (via each entry's
     * `weight`) so exact matches always outrank fuzzy-only matches.
     */
    private const FUZZY_WEIGHT = 0.4;

    private const DEFAULT_PREFIX_LIMIT = 10;

    private StorageInterface $storage;
    private NgramGenerator $ngramGenerator;
    private float $similarityThreshold;
    private int $maxFuzzyCandidates;
    private bool $enableFuzzy;

    /**
     * @param StorageInterface $storage Storage of the index being resolved against
     * @param array $config Supported keys (same semantics as SearchEngine's config):
     *   - ngramSizes: int[]|string  N-gram sizes (default [2, 3])
     *   - similarityThreshold: float  Base fuzzy threshold (default 0.25)
     *   - maxFuzzyCandidates: int  Fallback breadth for zero-exact tokens (default 100)
     *   - enableFuzzy: bool  Engine-wide fuzzy switch (default true)
     */
    public function __construct(StorageInterface $storage, array $config = [])
    {
        $this->setLoggingHandle('search-manager');
        $this->storage = $storage;

        $ngramSizes = $config['ngramSizes'] ?? [2, 3];
        if (is_string($ngramSizes)) {
            $ngramSizes = array_map('intval', explode(',', $ngramSizes));
        }
        $this->ngramGenerator = new NgramGenerator($ngramSizes);

        $this->similarityThreshold = (float)($config['similarityThreshold'] ?? 0.25);
        $this->maxFuzzyCandidates = (int)($config['maxFuzzyCandidates'] ?? 100);
        $this->enableFuzzy = (bool)($config['enableFuzzy'] ?? true);
    }

    /**
     * Resolve one query token to the index terms it should match.
     *
     * Result order: exact first, then prefix completions (frequency-ranked),
     * then fuzzy candidates (similarity-ranked). Terms are unique; when a term
     * qualifies via several match types the strongest one wins
     * (exact > prefix > fuzzy).
     *
     * @param string $token Query token (normalized defensively; pass Layer-1 tokens)
     * @param int $siteId Site to resolve within
     * @param array $options Supported keys:
     *   - includePrefix: bool  Add prefix completions (autocomplete last token; default false)
     *   - prefixLimit: int  Max prefix completions (default 10)
     *   - language: ?string  Language filter for prefix completions
     * @return array<int, array{term: string, matchType: string, similarity: float, weight: float}>
     */
    public function resolve(string $token, int $siteId, array $options = []): array
    {
        $token = TermNormalizer::normalize(trim($token));

        if ($token === '') {
            return [];
        }

        /** @var array<string, array{term: string, matchType: string, similarity: float, weight: float}> $resolved */
        $resolved = [];

        // Tier 1: exact match
        $exactDocs = $this->storage->getTermDocuments($token, $siteId);
        $hasExact = !empty($exactDocs);

        if ($hasExact) {
            $resolved[$token] = [
                'term' => $token,
                'matchType' => self::MATCH_EXACT,
                'similarity' => 1.0,
                'weight' => 1.0,
            ];
        }

        // Tier 2 (opt-in): prefix completions for a token still being typed
        if (!empty($options['includePrefix'])) {
            $prefixLimit = max(1, (int)($options['prefixLimit'] ?? self::DEFAULT_PREFIX_LIMIT));
            $language = isset($options['language']) && is_string($options['language'])
                ? $options['language']
                : null;

            // Over-fetch so entries already resolved as exact don't eat the limit.
            $prefixTerms = $this->storage->getTermsForAutocomplete($siteId, $language, $prefixLimit * 2, $token);
            $added = 0;

            foreach ($prefixTerms as $term => $frequency) {
                $term = (string)$term;

                // Defensive re-check: some storages return a broader pool.
                if (!str_starts_with($term, $token) || isset($resolved[$term])) {
                    continue;
                }

                $resolved[$term] = [
                    'term' => $term,
                    'matchType' => self::MATCH_PREFIX,
                    'similarity' => 1.0,
                    'weight' => 1.0,
                ];

                if (++$added >= $prefixLimit) {
                    break;
                }
            }
        }

        // Tier 3: fuzzy — expander when exact matches exist, full fallback breadth otherwise
        if ($this->enableFuzzy) {
            $ngrams = $this->ngramGenerator->generate($token);

            if (!empty($ngrams)) {
                $adaptiveThreshold = $this->ngramGenerator->getAdaptiveThreshold($token, $this->similarityThreshold);
                $fuzzyLimit = $hasExact ? self::FUZZY_EXPANSION_LIMIT : $this->maxFuzzyCandidates;

                // Head-room so the token itself (similarity 1.0) can't consume a slot.
                $candidates = $this->storage->getTermsByNgramSimilarity(
                    $ngrams,
                    $siteId,
                    $adaptiveThreshold,
                    $fuzzyLimit + 1,
                );

                $added = 0;
                foreach ($candidates as $term => $similarity) {
                    $term = (string)$term;

                    if (
                        mb_strlen($term) < FuzzyMatcher::MIN_CANDIDATE_LENGTH
                        || $term === $token
                        || isset($resolved[$term])
                    ) {
                        continue;
                    }

                    $resolved[$term] = [
                        'term' => $term,
                        'matchType' => self::MATCH_FUZZY,
                        'similarity' => (float)$similarity,
                        'weight' => (float)$similarity * self::FUZZY_WEIGHT,
                    ];

                    if (++$added >= $fuzzyLimit) {
                        break;
                    }
                }
            }
        }

        $this->logDebug('Resolved token', [
            'token' => $token,
            'site_id' => $siteId,
            'has_exact' => $hasExact,
            'resolved_count' => count($resolved),
        ]);

        return array_values($resolved);
    }
}
