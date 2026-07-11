<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use lindemannrock\searchmanager\helpers\SearchElementAvailabilityHelper;
use lindemannrock\searchmanager\helpers\SearchFieldValueHelper;
use lindemannrock\searchmanager\helpers\SearchHeadingValueHelper;
use lindemannrock\searchmanager\helpers\SearchHitIdentityHelper;
use lindemannrock\searchmanager\helpers\SearchSiteScopeHelper;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;

/**
 * Enrichment Service
 *
 * Transforms raw search backend hits into enriched results with:
 * - Contextual snippets centered around matched terms
 * - Heading expansion for hierarchical display
 * - Thumbnail URLs
 * - Debug metadata
 * - Promoted/boosted flags
 *
 * @author    LindemannRock
 * @package   SearchManager
 * @since     5.39.0
 */
class EnrichmentService extends Component
{
    /**
     * @var array<string, SearchIndex|null> Request-local index lookup cache used while enriching one result set.
     */
    private array $tokenizeIndexLookupCache = [];

    /**
     * Enrich raw search results with snippets, headings, thumbnails, and metadata
     *
     * @param array $rawHits Raw hits from the search backend
     * @param string $query The search query
     * @param array $indexHandles Index handles that were searched
     * @param array $options Enrichment options:
     *   - snippetMode: 'early'|'balanced'|'deep' (default: 'balanced')
     *   - snippetLength: int (default: 150, min: 50, max: 1000)
     *   - showCodeSnippets: bool (default: false)
     *   - parseMarkdownSnippets: bool (default: false)
     *   - hideResultsWithoutUrl: bool (default: false)
     *   - includeDebugMeta: bool (default: false)
     *   - siteId: int|array|null (default: null)
     * @return array Enriched results array
     */
    public function enrichResults(array $rawHits, string $query, array $indexHandles, array $options = []): array
    {
        $this->tokenizeIndexLookupCache = [];
        $snippetMode = $this->resolveSnippetMode($options['snippetMode'] ?? 'balanced');
        $snippetLength = $this->resolveSnippetLength($options['snippetLength'] ?? 150);
        $showCodeSnippets = (bool) ($options['showCodeSnippets'] ?? false);
        $parseMarkdownSnippets = (bool) ($options['parseMarkdownSnippets'] ?? false);
        $hideResultsWithoutUrl = (bool) ($options['hideResultsWithoutUrl'] ?? false);
        $includeDebugMeta = (bool) ($options['includeDebugMeta'] ?? false);
        $includeQueryRuleDebug = (bool) ($options['includeQueryRuleDebug'] ?? false);
        $siteId = SearchSiteScopeHelper::scopedSiteId($options['siteId'] ?? null);

        // Batch-load every referenced element up front, grouped by element type
        // and site, so this loop does a map lookup instead of a getElementById()
        // call per hit (the enrich=1 N+1, worst at hitsPerPage=100).
        $currentSiteId = Craft::$app->getSites()->getCurrentSite()->id;
        $preloadedElements = $this->preloadElements($rawHits, $siteId, $indexHandles);

        $results = [];

        try {
            foreach ($rawHits as $hit) {
                $elementId = SearchHitIdentityHelper::elementId($hit);
                if ($elementId === null) {
                    continue;
                }

                // Per-hit siteId wins over the global option; a null site resolves to
                // the current site — matching the previous getElementById() call.
                $hitSiteId = isset($hit['siteId']) ? (int) $hit['siteId'] : ($siteId ?? $currentSiteId);

                // Preloaded with CP=any-status / public=live-only. Missing, deleted,
                // or non-live elements are absent here and skipped, exactly as a null
                // getElementById() result was.
                $element = $preloadedElements[$hitSiteId . ':' . $elementId] ?? null;

                if ($element === null) {
                    continue;
                }

                // Determine URL with proper priority:
                // 1. Transformer-provided custom URL from hit data
                // 2. Element's native URL
                // 3. cpEditUrl only for CP requests (never for frontend)
                $url = $hit['url'] ?? $element->url ?? null;
                if ($url === null && Craft::$app->getRequest()->getIsCpRequest()) {
                    $url = $element->cpEditUrl;
                }

                // Skip results without URL if hideResultsWithoutUrl is enabled
                if ($hideResultsWithoutUrl && $url === null) {
                    continue;
                }

                $documentType = strtolower((string)($hit['type'] ?? $hit['elementType'] ?? $this->documentTypeForElement($element)));
                $title = $this->resultTitle($hit, $element);
                $snippetDebug = $includeDebugMeta ? [] : null;
                $snippetData = $this->prepareHitSnippets(
                    $hit,
                    $query,
                    $hit['_index'] ?? ($indexHandles[0] ?? ''),
                    [
                        'snippetMode' => $snippetMode,
                        'snippetLength' => $snippetLength,
                        'showCodeSnippets' => $showCodeSnippets,
                        'parseMarkdownSnippets' => $parseMarkdownSnippets,
                        'title' => $title,
                        'url' => $url,
                        'documentType' => $documentType,
                    ],
                    $snippetDebug,
                );
                $result = [
                    'id' => $elementId,
                    'title' => $title,
                    'url' => $url,
                    'snippet' => $snippetData['snippet'],
                    'headings' => $snippetData['headings'],
                    'type' => $documentType,
                    'elementType' => $documentType,
                    'fields' => SearchFieldValueHelper::fieldsFromHit($hit),
                    'score' => $hit['score'] ?? null,
                ];

                $result = array_merge($result, $this->elementKindMetadata($hit, $element, $documentType));

                // Add index handle and backend for multi-index searches (debug only)
                if ($includeDebugMeta && !empty($hit['_index'])) {
                    $result['_index'] = $hit['_index'];
                    $backend = SearchManager::$plugin->backend->getBackendForIndex($hit['_index']);
                    if ($backend) {
                        $result['backend'] = $backend->getName();
                    }
                }

                // Add site info (for multi-site debugging)
                if ($element->siteId) {
                    $result['siteId'] = $element->siteId;
                    $site = Craft::$app->getSites()->getSiteById($element->siteId);
                    if ($site) {
                        $result['site'] = $site->handle;
                        $result['language'] = $site->language;
                    }
                }

                // Add thumbnail if available
                if (method_exists($element, 'getThumbUrl')) {
                    $result['thumbnail'] = $element->getThumbUrl(80);
                }

                foreach (['productType', 'productTypeHandle'] as $commerceKey) {
                    if (isset($hit[$commerceKey]) && $hit[$commerceKey] !== '') {
                        $result[$commerceKey] = $hit[$commerceKey];
                    }
                }

                // Add matched fields info (which fields contained the search query)
                if (!empty($hit['matchedIn'])) {
                    $result['matchedIn'] = $hit['matchedIn'];
                }

                if (!empty($hit['matchedTerms'])) {
                    $result['matchedTerms'] = $hit['matchedTerms'];
                }

                if (!empty($hit['matchedPhrases'])) {
                    $result['matchedPhrases'] = $hit['matchedPhrases'];
                }

                if ($includeDebugMeta && !empty($snippetDebug)) {
                    $result['_snippet'] = $snippetDebug;
                }

                // Add promoted flag (result was injected via promotion, not found via search)
                if (!empty($hit['promoted'])) {
                    $result['promoted'] = true;
                }

                // Add boosted flag (result score was boosted via query rule)
                if (!empty($hit['boosted'])) {
                    $result['boosted'] = true;
                }

                if ($includeQueryRuleDebug && isset($hit['_queryRuleDebug']) && is_array($hit['_queryRuleDebug'])) {
                    $result['_queryRuleDebug'] = $hit['_queryRuleDebug'];
                }

                $results[] = $result;
            }
        } finally {
            $this->tokenizeIndexLookupCache = [];
        }

        return $results;
    }

    /**
     * Build plain-text snippets from saved indexed hit data.
     *
     * @since 5.53.0
     * @return array{snippet: string|null, headings: list<array{title: string, id: string, level: int, url: string|null, snippet: string|null}>}
     */
    public function prepareHitSnippets(
        array $hit,
        string $query,
        string $indexHandle = '',
        array $options = [],
        ?array &$debugMeta = null,
    ): array {
        $snippetMode = $this->resolveSnippetMode((string)($options['snippetMode'] ?? 'balanced'));
        $snippetLength = $this->resolveSnippetLength((int)($options['snippetLength'] ?? 150));
        $showCodeSnippets = (bool)($options['showCodeSnippets'] ?? false);
        $parseMarkdownSnippets = (bool)($options['parseMarkdownSnippets'] ?? false);
        $matchedTerms = is_array($hit['matchedTerms'] ?? null) ? $hit['matchedTerms'] : null;
        $title = is_string($options['title'] ?? null)
            ? (string)$options['title']
            : $this->stringValueFromMixed($hit['title'] ?? ($hit['name'] ?? null));
        $url = is_string($options['url'] ?? null)
            ? (string)$options['url']
            : $this->stringValueFromMixed($hit['url'] ?? null);
        $documentType = is_string($options['documentType'] ?? null)
            ? strtolower((string)$options['documentType'])
            : strtolower((string)($hit['type'] ?? $hit['elementType'] ?? ''));

        $fieldSnippet = $this->buildFieldSnippet(
            $hit,
            $query,
            $matchedTerms,
            $indexHandle,
            $snippetMode,
            $snippetLength,
            $showCodeSnippets,
            $parseMarkdownSnippets,
            $debugMeta,
        );

        return [
            'snippet' => $fieldSnippet['snippet'],
            'headings' => $this->buildHeadingSnippets(
                $hit,
                $query,
                $indexHandle,
                $matchedTerms,
                $snippetMode,
                $snippetLength,
                $parseMarkdownSnippets,
                $title,
                $url !== '' ? $url : null,
                $documentType,
            ),
        ];
    }

    /**
     * Batch-load the Craft elements referenced by the raw hits, grouped by
     * element type and site, so {@see enrichResults()} can do one query per
     * group instead of a getElementById() call per hit.
     *
     * Behaviour matches the per-hit getElementById() call it replaces:
     * - CP requests load any status; public/API requests only live elements.
     * - Per-hit siteId wins over the global siteId; a null site resolves to the
     *   current site.
     * - Missing / deleted / wrong-status elements are simply absent from the
     *   returned map, so the caller skips them exactly as before.
     *
     * The element type comes from an explicit internal hit context when one is
     * present (promoted Commerce variants can be injected while a Product index
     * is active), then from each hit's index. Hits whose type can't be resolved
     * fall back to a per-element getElementById() so no result is silently
     * dropped.
     *
     * @param array $rawHits Raw hits from the search backend
     * @param int|null $siteId Global single-site option (per-hit siteId overrides it)
     * @param string[] $indexHandles Index handles for the search (single-index fallback)
     * @return array<string, \craft\base\ElementInterface> Map keyed by "siteId:elementId"
     */
    private function preloadElements(array $rawHits, ?int $siteId, array $indexHandles): array
    {
        $isCpRequest = Craft::$app->getRequest()->getIsCpRequest();
        $currentSiteId = Craft::$app->getSites()->getCurrentSite()->id;

        $fallbackHandle = $indexHandles[0] ?? '';

        // Resolve each unique index handle to its element type once. findByHandle()
        // rebuilds a model (config + DB) on every call, so resolving per hit would
        // be its own N+1 — resolve per distinct handle instead.
        $elementClassByHandle = [];
        foreach ($rawHits as $hit) {
            $handle = $hit['_index'] ?? $fallbackHandle;
            if ($handle !== '' && !array_key_exists($handle, $elementClassByHandle)) {
                $elementClassByHandle[$handle] = SearchIndex::findByHandle($handle)?->elementType;
            }
        }

        // group[elementClass][resolvedSiteId][elementId] = true
        $groups = [];
        // unresolved[resolvedSiteId][elementId] = true — element type unknown
        $unresolved = [];

        foreach ($rawHits as $hit) {
            $elementId = SearchHitIdentityHelper::elementId($hit);
            if ($elementId === null) {
                continue;
            }
            $resolvedSiteId = isset($hit['siteId']) ? (int) $hit['siteId'] : ($siteId ?? $currentSiteId);

            $explicitElementClass = is_string($hit['_elementType'] ?? null) ? $hit['_elementType'] : null;
            $handle = $hit['_index'] ?? $fallbackHandle;
            $elementClass = $explicitElementClass ?: ($handle !== '' ? ($elementClassByHandle[$handle] ?? null) : null);

            if ($elementClass !== null && is_subclass_of($elementClass, \craft\base\ElementInterface::class)) {
                $groups[$elementClass][$resolvedSiteId][$elementId] = true;
            } else {
                $unresolved[$resolvedSiteId][$elementId] = true;
            }
        }

        $map = [];

        /** @var class-string<\craft\base\ElementInterface> $elementClass */
        foreach ($groups as $elementClass => $bySite) {
            foreach ($bySite as $resolvedSiteId => $idSet) {
                /** @var \craft\elements\db\ElementQuery $query */
                $query = $elementClass::find()
                    ->id(array_keys($idSet));
                if ($isCpRequest) {
                    $query->status(null);
                } else {
                    SearchElementAvailabilityHelper::applyToQuery($query, $elementClass);
                }

                if (!SearchElementAvailabilityHelper::isSiteIndependent($elementClass)) {
                    $query->siteId($resolvedSiteId);
                }

                foreach ($query->all() as $element) {
                    $map[$resolvedSiteId . ':' . $element->id] = $element;
                }
            }
        }

        // Defensive fallback: hits whose index/element type couldn't be resolved
        // keep the original per-element load so behaviour is never lost.
        foreach ($unresolved as $resolvedSiteId => $idSet) {
            foreach (array_keys($idSet) as $elementId) {
                $element = Craft::$app->elements->getElementById($elementId, null, $resolvedSiteId);
                if ($element !== null && ($isCpRequest || SearchElementAvailabilityHelper::isSearchable($element))) {
                    $map[$resolvedSiteId . ':' . $element->id] = $element;
                }
            }
        }

        return $map;
    }

    /**
     * Build the public snippet from indexed custom fields and explicit clean body prose.
     *
     * The stored flattened content bag intentionally remains off-limits here
     * because it includes title, slugs, SKUs, and other native identity values.
     *
     * @return array{snippet: string|null}
     */
    private function buildFieldSnippet(
        array $hit,
        string $query,
        ?array $matchedTerms,
        string $indexHandle,
        string $snippetMode,
        int $snippetLength,
        bool $showCodeSnippets,
        bool $parseMarkdownSnippets,
        ?array &$debugMeta = null,
    ): array {
        $terms = $this->resolveFieldSnippetTerms($hit, $matchedTerms, $query, $indexHandle);
        $fieldValues = SearchFieldValueHelper::fieldsFromHit($hit);
        $best = null;

        foreach ($fieldValues as $handle => $value) {
            if (!is_string($handle) || $handle === '') {
                continue;
            }

            $text = $this->fieldValueToString($value);
            if ($text === '' || !$this->isEligibleSnippetField($handle, $text, $showCodeSnippets, $parseMarkdownSnippets)) {
                continue;
            }

            $plainText = $this->htmlToPlainText($text, $showCodeSnippets, $parseMarkdownSnippets);
            $matchedFieldTerms = $this->matchedTermsForText($plainText, $terms);
            if ($matchedFieldTerms === []) {
                continue;
            }

            $plainSnippet = $this->findSnippet($plainText, $matchedFieldTerms, $snippetLength, $snippetMode);
            if ($plainSnippet === null) {
                continue;
            }

            $candidate = $this->snippetCandidate(
                handle: $handle,
                snippet: $plainSnippet,
                matchedTerms: $matchedFieldTerms,
                source: 'fields',
                position: $this->firstTermPosition($plainText, $matchedFieldTerms),
                preferred: $this->isPreferredSnippetHandle($handle),
                fullContentLength: mb_strlen($plainText),
            );

            if ($best === null || $this->compareSnippetCandidate($candidate, $best) < 0) {
                $best = $candidate;
            }
        }

        $bodyText = $this->bodySnippetText($hit, $parseMarkdownSnippets);
        if ($bodyText !== '') {
            $matchedBodyTerms = $this->matchedTermsForText($bodyText, $terms);
            if ($matchedBodyTerms !== []) {
                $plainSnippet = $this->findSnippet($bodyText, $matchedBodyTerms, $snippetLength, $snippetMode);
                if ($plainSnippet !== null) {
                    $candidate = $this->snippetCandidate(
                        handle: 'body',
                        snippet: $plainSnippet,
                        matchedTerms: $matchedBodyTerms,
                        source: 'body',
                        position: $this->firstTermPosition($bodyText, $matchedBodyTerms),
                        preferred: true,
                        fullContentLength: mb_strlen($bodyText),
                    );

                    if ($best === null || $this->compareSnippetCandidate($candidate, $best) < 0) {
                        $best = $candidate;
                    }
                }
            }
        }

        $this->setSnippetDebugMeta(
            $debugMeta,
            is_array($best) ? (string)$best['source'] : 'none',
            $snippetMode,
            $best !== null ? (string)$best['handle'] : 'none',
            is_array($best) ? (int)$best['fullContentLength'] : null,
        );

        return [
            'snippet' => is_array($best) ? (string)$best['snippet'] : null,
        ];
    }

    private function bodySnippetText(array $hit, bool $parseMarkdownSnippets): string
    {
        $body = $this->stringValueFromMixed($hit['_bodyClean'] ?? null);
        if ($body === '') {
            return '';
        }

        return $this->htmlToPlainText($body, false, $parseMarkdownSnippets);
    }

    private function isEligibleSnippetField(
        string $handle,
        string $value,
        bool $showCodeSnippets,
        bool $parseMarkdownSnippets,
    ): bool {
        if ($this->isNoisyProseFieldHandle($handle)) {
            return false;
        }

        $plain = $this->htmlToPlainText((string)$value, $showCodeSnippets, $parseMarkdownSnippets);
        return $this->looksLikeProse($plain, $this->isPreferredSnippetHandle($handle));
    }

    private function isNoisyProseFieldHandle(string $handle): bool
    {
        return preg_match('/(?:^|_|\b)(title|slug|uri|url|handle|sku|code|id|type|variant|option|price|stock|status|enabled|available|weight|height|width|color|size|email|phone|date)(?:$|_|\b)/i', $handle) === 1;
    }

    private function looksLikeProse(string $text, bool $preferred): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }

        if (preg_match('/^(?:https?:\/\/|\/|[A-Z0-9][A-Z0-9_-]{2,})$/i', $text) === 1) {
            return false;
        }

        if ($preferred) {
            return true;
        }

        return str_word_count($text) >= 4 || mb_strlen($text) >= 40;
    }

    private function isPreferredSnippetHandle(string $handle): bool
    {
        return in_array($handle, [
            'description',
            'excerpt',
            'summary',
            'intro',
            'teaser',
            'body',
            'copy',
            'caption',
        ], true);
    }

    private function fieldValueToString(mixed $value): string
    {
        if (is_array($value)) {
            return implode(' ', array_filter(array_map(
                static fn(mixed $item): string => is_scalar($item) ? trim((string)$item) : '',
                $value,
            )));
        }

        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * @return list<string>
     */
    private function resolveFieldSnippetTerms(
        array $hit,
        ?array $matchedTerms,
        string $query,
        string $indexHandle,
    ): array {
        $terms = [];
        $matchedTerms = $matchedTerms ?? ($hit['matchedTerms'] ?? null);

        if (is_array($matchedTerms)) {
            $terms = array_merge($terms, is_array($matchedTerms['content'] ?? null) ? $matchedTerms['content'] : []);
        }

        $phrases = $hit['matchedPhrases'] ?? [];
        if (is_array($phrases)) {
            $terms = array_merge($terms, $phrases);
        }

        if ($query !== '') {
            $terms = array_merge($terms, $this->tokenizeQueryTerms($query, $indexHandle));
        }

        $terms = array_values(array_unique(array_filter($terms, fn($term): bool => is_string($term) && $term !== '')));
        usort($terms, fn($a, $b) => mb_strlen($b) - mb_strlen($a));

        return $terms;
    }

    /**
     * @param list<string> $terms
     * @return list<string>
     */
    private function matchedTermsForText(string $text, array $terms): array
    {
        $matched = [];
        foreach ($terms as $term) {
            if ($term === '' || mb_stripos($text, $term) === false) {
                continue;
            }

            $matched[] = $term;
        }

        return array_values(array_unique($matched));
    }

    /**
     * @param list<string> $terms
     */
    private function firstTermPosition(string $text, array $terms): int
    {
        $positions = [];
        foreach ($terms as $term) {
            $pos = mb_stripos($text, $term);
            if ($pos !== false) {
                $positions[] = (int)$pos;
            }
        }

        return $positions !== [] ? min($positions) : PHP_INT_MAX;
    }

    /**
     * Sort lower-is-better: more terms, preferred handles, then earlier match.
     *
     * @param list<string> $matchedTerms
     * @return array{handle: string, snippet: string, matchedTerms: list<string>, score: int, position: int, preferred: bool, source: string, fullContentLength: int}
     */
    private function snippetCandidate(
        string $handle,
        string $snippet,
        array $matchedTerms,
        string $source,
        int $position,
        bool $preferred,
        int $fullContentLength,
    ): array {
        return [
            'handle' => $handle,
            'snippet' => $snippet,
            'matchedTerms' => $matchedTerms,
            'score' => count($matchedTerms),
            'position' => $position,
            'preferred' => $preferred,
            'source' => $source,
            'fullContentLength' => $fullContentLength,
        ];
    }

    /**
     * @param array{score: int, preferred: bool, position: int} $a
     * @param array{score: int, preferred: bool, position: int} $b
     */
    private function compareSnippetCandidate(array $a, array $b): int
    {
        $score = $b['score'] <=> $a['score'];
        if ($score !== 0) {
            return $score;
        }

        $preferred = (int)$b['preferred'] <=> (int)$a['preferred'];
        if ($preferred !== 0) {
            return $preferred;
        }

        return $a['position'] <=> $b['position'];
    }

    private function shouldExposeFullBodyHeadings(array $hit, string $documentType): bool
    {
        if ($documentType !== 'source-doc' || $this->stringValueFromMixed($hit['_bodyClean'] ?? null) === '') {
            return false;
        }

        $matchedIn = $hit['matchedIn'] ?? [];

        return is_array($matchedIn) && in_array('content', $matchedIn, true);
    }

    /**
     * @param array<string, mixed>|null $matchedTerms
     * @return list<array{title: string, id: string, level: int, url: string|null, snippet: string|null}>
     */
    private function buildHeadingSnippets(
        array $hit,
        string $query,
        string $indexHandle,
        ?array $matchedTerms,
        string $snippetMode,
        int $snippetLength,
        bool $parseMarkdownSnippets,
        string $title,
        ?string $url,
        string $documentType,
    ): array {
        $headings = is_array($hit['_headings'] ?? null)
            ? $hit['_headings']
            : (is_array($hit['headings'] ?? null) ? $hit['headings'] : []);

        if ($headings === []) {
            return [];
        }

        $titleMatchTerms = $this->resolveTitleMatchTerms($hit, $matchedTerms, $query, $indexHandle);
        $headingMatchTerms = $this->resolveHeadingMatchTerms($hit, $matchedTerms, $query, $indexHandle);
        $headingSnippetTerms = $this->resolveFieldSnippetTerms($hit, $matchedTerms, $query, $indexHandle);
        $titleMatches = $title !== '' && $this->textHasAnyTerm($title, $titleMatchTerms);
        $preparedHeadings = $this->headingsWithDynamicSnippets(
            array_values($headings),
            $this->bodySnippetText($hit, $parseMarkdownSnippets),
            $headingSnippetTerms,
            $snippetMode,
            $snippetLength,
            $parseMarkdownSnippets,
        );

        if ($titleMatches || $this->shouldExposeFullBodyHeadings($hit, $documentType)) {
            return SearchHeadingValueHelper::toPublicList($preparedHeadings, $url);
        }

        $matchedHeadings = array_filter($preparedHeadings, function($heading) use ($headingMatchTerms): bool {
            if (!is_array($heading)) {
                return false;
            }

            $text = $this->stringValueFromMixed($heading['text'] ?? ($heading['title'] ?? null));

            return $text !== '' && $this->textHasAnyTerm($text, $headingMatchTerms);
        });

        return $matchedHeadings !== []
            ? SearchHeadingValueHelper::toPublicList(array_values($matchedHeadings), $url)
            : [];
    }

    /**
     * @param array<int, mixed> $headings
     * @param list<string> $terms
     * @return list<array<string, mixed>>
     */
    private function headingsWithDynamicSnippets(
        array $headings,
        string $bodyText,
        array $terms,
        string $snippetMode,
        int $snippetLength,
        bool $parseMarkdownSnippets,
    ): array {
        $sections = $bodyText !== '' ? $this->headingSectionsFromBody($headings, $bodyText) : [];
        $preparedHeadings = [];

        foreach ($headings as $i => $heading) {
            if (!is_array($heading)) {
                continue;
            }

            $prepared = $heading;
            $headingText = $sections[$i] ?? '';
            $matchedTerms = $headingText !== '' ? $this->matchedTermsForText($headingText, $terms) : [];
            $snippet = $matchedTerms !== []
                ? $this->findSnippet($headingText, $matchedTerms, $snippetLength, $snippetMode)
                : null;

            if ($snippet !== null) {
                $prepared['snippet'] = $snippet;
            } else {
                unset($prepared['snippet'], $prepared['description']);
            }

            $preparedHeadings[] = $prepared;
        }

        return $preparedHeadings;
    }

    /**
     * @param array<int, mixed> $headings
     * @return array<int, string>
     */
    private function headingSectionsFromBody(array $headings, string $bodyText): array
    {
        $sections = [];
        $positions = [];
        $cursor = 0;

        foreach ($headings as $i => $heading) {
            if (!is_array($heading)) {
                continue;
            }

            $title = $this->stringValueFromMixed($heading['text'] ?? ($heading['title'] ?? null));
            if ($title === '') {
                continue;
            }

            $position = $this->findHeadingTitlePosition($bodyText, $title, $cursor);
            if ($position === null) {
                $position = $this->findHeadingTitlePosition($bodyText, $title, 0);
            }
            if ($position === null) {
                continue;
            }

            $positions[$i] = [
                'start' => $position,
                'contentStart' => $position + mb_strlen($title),
            ];
            $cursor = $position + mb_strlen($title);
        }

        $indexes = array_keys($positions);
        foreach ($indexes as $offset => $i) {
            $nextIndex = $indexes[$offset + 1] ?? null;
            $start = $positions[$i]['contentStart'];
            $end = $nextIndex !== null ? $positions[$nextIndex]['start'] : mb_strlen($bodyText);
            $section = trim(mb_substr($bodyText, $start, max(0, $end - $start)));

            if ($section !== '') {
                $sections[$i] = $section;
            }
        }

        return $sections;
    }

    private function findHeadingTitlePosition(string $bodyText, string $title, int $offset): ?int
    {
        $position = mb_stripos($bodyText, $title, $offset);
        while ($position !== false) {
            $position = (int)$position;
            if ($this->isHeadingTitleBoundary($bodyText, $position, mb_strlen($title))) {
                return $position;
            }

            $position = mb_stripos($bodyText, $title, $position + mb_strlen($title));
        }

        return null;
    }

    private function isHeadingTitleBoundary(string $bodyText, int $position, int $length): bool
    {
        $before = $position > 0 ? mb_substr($bodyText, $position - 1, 1) : '';
        $afterPosition = $position + $length;
        $after = $afterPosition < mb_strlen($bodyText) ? mb_substr($bodyText, $afterPosition, 1) : '';

        return !$this->isWordCharacter($before) && !$this->isWordCharacter($after);
    }

    private function isWordCharacter(string $char): bool
    {
        return $char !== '' && preg_match('/[\pL\pN]/u', $char) === 1;
    }

    private function setSnippetDebugMeta(
        ?array &$debugMeta,
        string $snippetSource,
        string $snippetMode,
        string $snippetFrom,
        ?int $fullContentLen,
    ): void {
        if ($debugMeta === null) {
            return;
        }

        $debugMeta['snippetSource'] = $snippetSource;
        $debugMeta['snippetMode'] = $snippetMode;
        $debugMeta['snippetFrom'] = $snippetFrom;
        $debugMeta['fullContentLength'] = $fullContentLen;
    }

    /**
     * Convert HTML to clean plain text for snippets
     *
     * Strips code blocks, scripts, styles, then tags, decodes entities,
     * and normalizes whitespace.
     */
    private function htmlToPlainText(string $html, bool $includeCode, bool $parseMarkdownSnippets): string
    {
        $html = $this->maybeParseMarkdown($html, $parseMarkdownSnippets);

        // Always remove scripts and styles (content and all)
        $html = (string) preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html);

        // Optionally remove code blocks
        if (!$includeCode) {
            $html = (string) preg_replace('/<(pre|code)\b[^>]*>.*?<\/\1>/is', ' ', $html);
        }

        // Strip remaining tags
        $text = strip_tags($html);

        // Decode HTML entities (&lt; &gt; &amp; &quot; etc.)
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Normalize whitespace
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    private function maybeParseMarkdown(string $text, bool $parseMarkdownSnippets): string
    {
        if (!$parseMarkdownSnippets) {
            return $text;
        }

        // If it already looks like HTML, skip
        if (preg_match('/<[^>]+>/', $text)) {
            return $text;
        }

        if (!$this->looksLikeMarkdown($text)) {
            return $text;
        }

        return (string) \yii\helpers\Markdown::process($text, 'gfm-comment');
    }

    private function looksLikeMarkdown(string $text): bool
    {
        if (preg_match('/^#{1,6}\s+/m', $text)) {
            return true;
        }
        if (preg_match('/^```/m', $text)) {
            return true;
        }
        if (preg_match('/\[[^\]]+\]\([^)]+\)/', $text)) {
            return true;
        }
        if (preg_match('/^\s*[-*+]\s+/m', $text)) {
            return true;
        }
        if (preg_match('/^\s*\|.+\|\s*$/m', $text) && preg_match('/^\s*\|?\s*:?-+:?\s*(\|\s*:?-+:?\s*)+\|?\s*$/m', $text)) {
            return true;
        }

        return false;
    }

    /**
     * Find a contextual snippet centered around the query in the text
     *
     * @return string|null Snippet with surrounding context, or null if query not found
     */
    private function findSnippet(string $text, array $terms, int $maxLength = 150, string $mode = 'balanced'): ?string
    {
        $text = trim($text);
        if (empty($text)) {
            return null;
        }

        $pos = false;
        foreach ($terms as $term) {
            $pos = mb_stripos($text, $term);
            if ($pos !== false) {
                break;
            }
        }

        if ($pos === false) {
            return null;
        }

        $textLength = mb_strlen($text);

        // If the text fits within maxLength, return it all
        if ($textLength <= $maxLength) {
            return $text;
        }

        // Bias the snippet so the match appears early (avoid trailing-only matches)
        $mode = strtolower($mode);
        if ($mode === 'early') {
            $lead = 10;
        } elseif ($mode === 'deep') {
            // Deep: show more context before the match
            $lead = 90;
        } else {
            // Balanced: a bit more context before the match
            $lead = 40;
        }

        $start = max(0, $pos - $lead);
        $end = min($textLength, $start + $maxLength);

        if ($end === $textLength) {
            $start = max(0, $end - $maxLength);
        }

        [$start, $end] = $this->adjustSnippetToWordBoundaries($text, $start, $end, $maxLength, $pos, $lead);
        $snippet = mb_substr($text, $start, $end - $start);

        // Add ellipsis if we're not at the boundaries
        $prefix = $start > 0 ? '...' : '';
        $suffix = $end < $textLength ? '...' : '';

        return $prefix . trim($snippet) . $suffix;
    }

    private function adjustSnippetToWordBoundaries(
        string $text,
        int $start,
        int $end,
        int $maxLength,
        int $matchPos,
        int $lead,
    ): array {
        $textLength = mb_strlen($text);

        // Move start to previous whitespace
        $before = mb_substr($text, 0, $start);
        $lastSpace = mb_strrpos($before, ' ');
        if ($lastSpace !== false) {
            $start = (int) $lastSpace + 1;
        }

        // Move end to next whitespace
        $after = mb_substr($text, $end);
        $nextSpace = mb_strpos($after, ' ');
        if ($nextSpace !== false) {
            $end = $end + (int) $nextSpace;
        }

        // If boundary expansion exceeds maxLength, keep match near the start
        if (($end - $start) > $maxLength) {
            $start = max(0, $matchPos - $lead);
            $end = min($textLength, $start + $maxLength);
        }

        if ($end === $textLength) {
            $start = max(0, $end - $maxLength);
        }

        return [$start, $end];
    }

    /**
     * Resolve and validate snippet mode
     */
    private function resolveSnippetMode(string $mode): string
    {
        $mode = strtolower(trim($mode));
        $allowed = ['early', 'balanced', 'deep'];
        return in_array($mode, $allowed, true) ? $mode : 'balanced';
    }

    /**
     * Resolve and clamp snippet length
     */
    private function resolveSnippetLength(int $length): int
    {
        if ($length < 50) {
            return 50;
        }

        if ($length > 1000) {
            return 1000;
        }

        return $length;
    }

    private function resultTitle(array $hit, ElementInterface $element): string
    {
        $hitTitle = $this->stringValueFromMixed($hit['title'] ?? null);
        if ($hitTitle !== '') {
            return $hitTitle;
        }

        if ($element instanceof \craft\elements\User) {
            foreach (['fullName', 'username', 'email'] as $property) {
                $value = $this->stringValueFromMixed($element->{$property} ?? null);
                if ($value !== '') {
                    return $value;
                }
            }

            return $element->id !== null ? '#' . $element->id : '';
        }

        $elementTitle = $this->stringValueFromMixed($element->title ?? null);

        return $elementTitle !== '' ? $elementTitle : 'Untitled';
    }

    private function stringValueFromMixed(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function elementKindMetadata(array $hit, ElementInterface $element, string $documentType): array
    {
        return match ($documentType) {
            'entry' => $this->entryMetadata($hit, $element),
            'asset' => $this->assetMetadata($hit, $element),
            'category' => $this->categoryMetadata($hit, $element),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function entryMetadata(array $hit, ElementInterface $element): array
    {
        $section = $element instanceof \craft\elements\Entry ? $element->getSection() : null;

        return $this->filterElementKindMetadata([
            'section' => $this->stringValueFromMixed($hit['section'] ?? null) ?: $section?->name,
            'sectionHandle' => $this->stringValueFromMixed($hit['sectionHandle'] ?? null) ?: $section?->handle,
            'sectionType' => $this->stringValueFromMixed($hit['sectionType'] ?? null) ?: $section?->type,
            'ancestors' => $this->ancestorsFromHit($hit['ancestors'] ?? null),
            'level' => $this->integerValueFromMixed($hit['level'] ?? null),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function assetMetadata(array $hit, ElementInterface $element): array
    {
        $volume = $element instanceof \craft\elements\Asset ? $element->getVolume() : null;

        return $this->filterElementKindMetadata([
            'volume' => $this->stringValueFromMixed($hit['volume'] ?? null) ?: $volume?->name,
            'volumeHandle' => $this->stringValueFromMixed($hit['volumeHandle'] ?? null) ?: $volume?->handle,
            'ancestors' => $this->ancestorsFromHit($hit['ancestors'] ?? null),
            'folderPath' => $this->stringValueFromMixed($hit['folderPath'] ?? null),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryMetadata(array $hit, ElementInterface $element): array
    {
        $group = $element instanceof \craft\elements\Category ? $element->getGroup() : null;

        return $this->filterElementKindMetadata([
            'group' => $this->stringValueFromMixed($hit['group'] ?? null) ?: $group?->name,
            'groupHandle' => $this->stringValueFromMixed($hit['groupHandle'] ?? null) ?: $group?->handle,
            'ancestors' => $this->ancestorsFromHit($hit['ancestors'] ?? null),
            'level' => $this->integerValueFromMixed($hit['level'] ?? null),
        ]);
    }

    private function integerValueFromMixed(mixed $value): ?int
    {
        return is_numeric($value) ? (int)$value : null;
    }

    /**
     * @return array<int, array{id: int, title: string}>
     */
    private function ancestorsFromHit(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ancestors = [];
        foreach ($value as $ancestor) {
            if (!is_array($ancestor)) {
                continue;
            }

            $id = $ancestor['id'] ?? null;
            $title = $this->stringValueFromMixed($ancestor['title'] ?? null);
            if (!is_numeric($id) || $title === '') {
                continue;
            }

            $ancestors[] = [
                'id' => (int)$id,
                'title' => $title,
            ];
        }

        return $ancestors;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function filterElementKindMetadata(array $metadata): array
    {
        return array_filter($metadata, static function(mixed $value): bool {
            if ($value === null || $value === '') {
                return false;
            }

            return !is_array($value) || $value !== [];
        });
    }

    private function documentTypeForElement(ElementInterface $element): string
    {
        if ($element instanceof \craft\elements\Entry) {
            return 'entry';
        }

        if (is_a($element, \lindemannrock\searchmanager\helpers\CommerceElementTypeHelper::productElementType())) {
            return 'product';
        }

        if (is_a($element, \lindemannrock\searchmanager\helpers\CommerceElementTypeHelper::variantElementType())) {
            return 'variant';
        }

        if ($element instanceof \craft\elements\Category) {
            return 'category';
        }

        if ($element instanceof \craft\elements\Asset) {
            return 'asset';
        }

        if ($element instanceof \craft\elements\User) {
            return 'user';
        }

        return strtolower($element::displayName());
    }

    private function resolveTitleMatchTerms(
        array $hit,
        ?array $matchedTerms,
        string $query,
        string $indexHandle,
    ): array {
        $matchedTerms = $matchedTerms ?? ($hit['matchedTerms'] ?? null);
        if (!empty($matchedTerms['title'])) {
            return array_values(array_unique($matchedTerms['title']));
        }

        return $this->tokenizeQueryTerms($query, $indexHandle);
    }

    private function resolveHeadingMatchTerms(
        array $hit,
        ?array $matchedTerms,
        string $query,
        string $indexHandle,
    ): array {
        $matchedTerms = $matchedTerms ?? ($hit['matchedTerms'] ?? null);
        if (!empty($matchedTerms['content'])) {
            return array_values(array_unique($matchedTerms['content']));
        }

        return $this->tokenizeQueryTerms($query, $indexHandle);
    }

    private function textHasAnyTerm(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if ($term === '') {
                continue;
            }
            if (mb_stripos($text, $term) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tokenize query using the same rules as the search engine
     *
     * @return string[] Tokens
     */
    private function tokenizeQueryTerms(string $query, string $indexHandle = ''): array
    {
        $tokenizer = new \lindemannrock\searchmanager\search\Tokenizer();
        $terms = $tokenizer->tokenize($query);

        $settings = SearchManager::$plugin->getSettings();
        $disableStopWords = false;
        $language = null;

        if (!empty($indexHandle)) {
            if (!array_key_exists($indexHandle, $this->tokenizeIndexLookupCache)) {
                $this->tokenizeIndexLookupCache[$indexHandle] = SearchIndex::findByHandle($indexHandle);
            }
            $searchIndex = $this->tokenizeIndexLookupCache[$indexHandle];
            if ($searchIndex && !empty($searchIndex->language)) {
                $language = $searchIndex->language;
            }
            if ($searchIndex) {
                $disableStopWords = (bool) $searchIndex->disableStopWords;
            }
        }

        $language = $language ?: 'en';

        if (($settings->enableStopWords ?? true) && !$disableStopWords) {
            $stopWords = new \lindemannrock\searchmanager\search\StopWords($language);
            $terms = $stopWords->filter($terms);
        }

        return array_values(array_unique($terms));
    }
}
