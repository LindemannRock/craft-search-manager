<?php

namespace lindemannrock\searchmanager\services;

use Craft;
use craft\base\Component;
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
     *   - siteId: int|null (default: null)
     * @return array Enriched results array
     * @since 5.39.0
     */
    public function enrichResults(array $rawHits, string $query, array $indexHandles, array $options = []): array
    {
        $snippetMode = $this->resolveSnippetMode($options['snippetMode'] ?? 'balanced');
        $snippetLength = $this->resolveSnippetLength($options['snippetLength'] ?? 150);
        $showCodeSnippets = (bool) ($options['showCodeSnippets'] ?? false);
        $parseMarkdownSnippets = (bool) ($options['parseMarkdownSnippets'] ?? false);
        $hideResultsWithoutUrl = (bool) ($options['hideResultsWithoutUrl'] ?? false);
        $includeDebugMeta = (bool) ($options['includeDebugMeta'] ?? false);
        $siteId = isset($options['siteId']) ? (int) $options['siteId'] : null;

        $results = [];

        foreach ($rawHits as $hit) {
            $elementId = $hit['id'] ?? $hit['objectID'] ?? null;
            if (!$elementId) {
                continue;
            }
            // Cast to int for getElementById (search backends may return strings)
            $elementId = (int) $elementId;

            // Try to get the actual element for URL and additional data
            // For public (non-CP) requests, only return live elements to prevent
            // disabled/expired content from appearing in search results
            $criteria = Craft::$app->getRequest()->getIsCpRequest() ? [] : ['status' => 'live'];
            $element = Craft::$app->elements->getElementById($elementId, null, $siteId, $criteria);

            if ($element === null) {
                // Element might have been deleted or is not live, skip it
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

            $snippetDebug = $includeDebugMeta ? [] : null;
            $description = $this->getDescription(
                $hit,
                $element,
                $query,
                $hit['matchedTerms'] ?? null,
                $hit['_index'] ?? ($indexHandles[0] ?? ''),
                $snippetMode,
                $snippetLength,
                $showCodeSnippets,
                $parseMarkdownSnippets,
                $snippetDebug,
            );

            $result = [
                'id' => $elementId,
                'title' => $hit['title'] ?? $element->title ?? 'Untitled',
                'url' => $url,
                'description' => $description,
                'descriptionSafe' => $description !== null ? \craft\helpers\Html::encode($description) : null,
                'section' => $hit['section'] ?? $this->getSectionName($element),
                'type' => $hit['type'] ?? $element::displayName(),
                'score' => $hit['score'] ?? null,
            ];

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

            // Pass through hierarchy data for hierarchical display
            // Only expand headings when the match is in the title or a heading
            // (like Algolia DocSearch — content-only matches show snippets, not headings)
            $headings = $hit['_headings'] ?? null;

            if (!empty($headings)) {
                $result['_headings'] = $headings;

                $indexHandleForMatch = $hit['_index'] ?? ($indexHandles[0] ?? '');
                $titleMatchTerms = $this->resolveTitleMatchTerms(
                    $hit,
                    $hit['matchedTerms'] ?? null,
                    $query,
                    $indexHandleForMatch,
                    $element,
                );
                $headingMatchTerms = $this->resolveHeadingMatchTerms(
                    $hit,
                    $hit['matchedTerms'] ?? null,
                    $query,
                    $indexHandleForMatch,
                    $element,
                );

                $title = (string) $result['title'];
                $titleMatches = !empty($title) && $this->textHasAnyTerm($title, $titleMatchTerms);

                if ($titleMatches) {
                    // Title matches: show all headings for navigation
                    $result['_matchedHeadings'] = array_values($headings);
                } else {
                    // Only include headings that actually contain the query
                    $matchedHeadings = array_filter($headings, function($h) use ($headingMatchTerms) {
                        return !empty($h['text']) && $this->textHasAnyTerm($h['text'], $headingMatchTerms);
                    });
                    if (!empty($matchedHeadings)) {
                        $result['_matchedHeadings'] = array_values($matchedHeadings);
                    }
                }
            }

            $category = $hit['category'] ?? null;
            if (!empty($category)) {
                $result['category'] = $category;
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

            $results[] = $result;
        }

        return $results;
    }

    /**
     * Get description from hit or element
     *
     * When a query is provided, generates a contextual snippet centered around
     * the first occurrence of the query in the content (like Algolia DocSearch).
     * Falls back to the first 150 chars if the query isn't found in the text.
     */
    private function getDescription(
        array $hit,
        mixed $element,
        string $query,
        ?array $matchedTerms,
        string $indexHandle,
        string $snippetMode,
        int $snippetLength,
        bool $showCodeSnippets,
        bool $parseMarkdownSnippets,
        ?array &$debugMeta = null,
    ): ?string {
        // Collect candidate text sources in priority order
        $candidates = [];
        $snippetCandidates = [];
        $snippetFrom = 'fallback';
        $fullContentLen = null;

        if (!empty($hit['description'])) {
            $candidates[] = $hit['description'];
            $snippetCandidates[] = $hit['description'];
        }
        if (!empty($hit['excerpt'])) {
            $candidates[] = $hit['excerpt'];
            $snippetCandidates[] = $hit['excerpt'];
        }
        // Resolve which content field to use for snippets:
        // prose-only _contentClean when code snippets are disabled, full content otherwise
        $snippetContentField = (!$showCodeSnippets && !empty($hit['_contentClean'])) ? '_contentClean' : 'content';

        if (!empty($hit[$snippetContentField])) {
            $candidates[] = $this->htmlToPlainText($hit[$snippetContentField], false, $parseMarkdownSnippets);
            $snippetCandidates[] = $this->htmlToPlainText($hit[$snippetContentField], false, $parseMarkdownSnippets);
        }

        // Try element fields (short description fields first)
        if ($element !== null) {
            $descriptionFields = ['description', 'excerpt', 'summary', 'intro', 'teaser'];
            foreach ($descriptionFields as $fieldHandle) {
                if (isset($element->$fieldHandle) && !empty($element->$fieldHandle)) {
                    $value = $element->$fieldHandle;
                    if (is_string($value) || $value instanceof \Stringable) {
                        $plain = $this->htmlToPlainText((string) $value, false, $parseMarkdownSnippets);
                        $candidates[] = $plain;
                        $snippetCandidates[] = $plain;
                    }
                }
            }
        }

        if (empty($candidates)) {
            return null;
        }

        $snippetSource = $this->resolveSnippetSource($hit);
        $snippetTerms = $this->resolveSnippetTerms($hit, $matchedTerms, $query, $indexHandle, $element, $snippetSource);

        // If we have terms, find a contextual snippet containing the match
        if (!empty($snippetTerms)) {
            // If the match is in content, prioritize full-content snippets (avoid pre-truncated excerpts)
            if ($snippetSource === 'content') {
                // Use stored content from documentData (Algolia-like: self-contained, no extra queries)
                $fullContent = !empty($hit[$snippetContentField]) ? $this->htmlToPlainText($hit[$snippetContentField], false, $parseMarkdownSnippets) : null;
                $fullContentLen = $fullContent !== null ? mb_strlen($fullContent) : null;
                if ($fullContent !== null) {
                    $snippet = $this->findSnippet($fullContent, $snippetTerms, $snippetLength, $snippetMode);
                    if ($snippet !== null) {
                        $snippetFrom = 'content';
                        $this->setSnippetDebugMeta($debugMeta, $snippetSource, $snippetMode, $snippetFrom, $fullContentLen);
                        return $snippet;
                    }
                }

                // If allowed, try code snippets when content matched
                if ($showCodeSnippets) {
                    $codeSnippet = $this->findCodeSnippet($element, $snippetTerms, $snippetLength);
                    if ($codeSnippet !== null) {
                        $snippetFrom = 'code';
                        $this->setSnippetDebugMeta($debugMeta, $snippetSource, $snippetMode, $snippetFrom, $fullContentLen);
                        return $codeSnippet;
                    }
                }
            } else {
                // Fall back to short candidates (description, excerpt)
                foreach ($snippetCandidates as $text) {
                    $snippet = $this->findSnippet($text, $snippetTerms, $snippetLength, $snippetMode);
                    if ($snippet !== null) {
                        $snippetFrom = 'short';
                        $this->setSnippetDebugMeta($debugMeta, $snippetSource, $snippetMode, $snippetFrom, $fullContentLen);
                        return $snippet;
                    }
                }

                // If still not found, try full content from documentData
                $fullContent = !empty($hit[$snippetContentField]) ? $this->htmlToPlainText($hit[$snippetContentField], false, $parseMarkdownSnippets) : null;
                $fullContentLen = $fullContent !== null ? mb_strlen($fullContent) : null;
                if ($fullContent !== null) {
                    $snippet = $this->findSnippet($fullContent, $snippetTerms, $snippetLength, $snippetMode);
                    if ($snippet !== null) {
                        $snippetFrom = 'content';
                        $this->setSnippetDebugMeta($debugMeta, $snippetSource, $snippetMode, $snippetFrom, $fullContentLen);
                        return $snippet;
                    }
                }
            }
        }

        // Fallback: return the first candidate truncated
        $snippetFrom = 'fallback';
        $this->setSnippetDebugMeta($debugMeta, $snippetSource, $snippetMode, $snippetFrom, $fullContentLen);
        return $this->truncate(trim($candidates[0]), $snippetLength);
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
     * Find a concise snippet from code blocks when query terms match code
     *
     * @return string|null Snippet line from code blocks
     */
    private function findCodeSnippet(mixed $element, array $terms, int $maxLength = 150): ?string
    {
        if ($element === null) {
            return null;
        }

        $htmlBlocks = [];

        if ($element instanceof \lindemannrock\docsmanager\elements\SourceDoc) {
            if (!empty($element->htmlContent)) {
                $htmlBlocks[] = $element->htmlContent;
            }
        }

        if ($element instanceof \craft\base\Element && $element->getFieldLayout()) {
            foreach ($element->getFieldLayout()->getCustomFields() as $field) {
                try {
                    $value = $element->getFieldValue($field->handle);
                    if (is_string($value) || $value instanceof \Stringable) {
                        $stringValue = (string) $value;
                        if (str_contains($stringValue, '<code') || str_contains($stringValue, '<pre')) {
                            $htmlBlocks[] = $stringValue;
                        }
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        if (empty($htmlBlocks)) {
            return null;
        }

        foreach ($htmlBlocks as $html) {
            $codeBlocks = $this->extractCodeBlocks($html);
            foreach ($codeBlocks as $code) {
                $snippet = $this->findCodeLineSnippet($code, $terms, $maxLength);
                if ($snippet !== null) {
                    return $snippet;
                }
            }
        }

        return null;
    }

    /**
     * Extract raw code blocks from HTML
     *
     * @return string[] Raw code strings
     */
    private function extractCodeBlocks(string $html): array
    {
        $blocks = [];
        $patterns = [
            '/<pre\\b[^>]*>(.*?)<\\/pre>/is',
            '/<code\\b[^>]*>(.*?)<\\/code>/is',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                foreach ($matches[1] as $block) {
                    $text = strip_tags($block);
                    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    $text = str_replace(["\r\n", "\r"], "\n", $text);
                    $blocks[] = trim($text);
                }
            }
        }

        return array_values(array_filter($blocks));
    }

    /**
     * Find a matching line in code and return a compact snippet
     */
    private function findCodeLineSnippet(string $code, array $terms, int $maxLength = 150): ?string
    {
        $lines = preg_split('/\\n+/', $code) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $pos = false;
            foreach ($terms as $term) {
                $pos = mb_stripos($line, $term);
                if ($pos !== false) {
                    break;
                }
            }

            if ($pos === false) {
                continue;
            }

            $lineLength = mb_strlen($line);
            if ($lineLength <= $maxLength) {
                return $line;
            }

            $start = max(0, $pos - (int) ($maxLength / 2));
            $end = min($lineLength, $start + $maxLength);
            if ($end === $lineLength) {
                $start = max(0, $end - $maxLength);
            }

            $snippet = mb_substr($line, $start, $end - $start);
            $prefix = $start > 0 ? '...' : '';
            $suffix = $end < $lineLength ? '...' : '';

            return $prefix . trim($snippet) . $suffix;
        }

        return null;
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

    /**
     * Get section/type name for grouping
     */
    private function getSectionName(mixed $element): string
    {
        // For entries, get the section name (use explicit getter to avoid throw on deleted sections)
        if ($element instanceof \craft\elements\Entry) {
            return $element->getSection()?->name ?? 'Entries';
        }

        // For other elements, use the display name
        return $element::displayName();
    }

    /**
     * Truncate text to a maximum length
     */
    private function truncate(string $text, int $maxLength): string
    {
        $text = trim($text);

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 3) . '...';
    }

    /**
     * Resolve snippet terms based on matched terms or query tokenization
     *
     * @return string[] Terms sorted by length desc
     */
    private function resolveSnippetTerms(
        array $hit,
        ?array $matchedTerms,
        string $query,
        string $indexHandle,
        mixed $element,
        string $snippetSource = 'content',
    ): array {
        $terms = [];
        $matchedTerms = $matchedTerms ?? ($hit['matchedTerms'] ?? null);

        if (!empty($matchedTerms)) {
            if ($snippetSource === 'content' && !empty($matchedTerms['content'])) {
                $terms = $matchedTerms['content'];
            } elseif ($snippetSource === 'title' && !empty($matchedTerms['title'])) {
                $terms = $matchedTerms['title'];
            } else {
                $terms = array_merge($matchedTerms['content'] ?? [], $matchedTerms['title'] ?? []);
            }
        }

        if (empty($terms) && !empty($query)) {
            $terms = $this->tokenizeQueryTerms($query, $indexHandle, $element);
        }

        // Prepend matched phrases so findSnippet centers around phrase text first.
        // This ensures the snippet window includes the phrase (when present in content),
        // allowing the frontend to highlight both the full phrase and individual terms.
        $phrases = $hit['matchedPhrases'] ?? [];
        if (!empty($phrases)) {
            $terms = array_merge($phrases, $terms);
        }

        $terms = array_values(array_unique(array_filter($terms, fn($t) => is_string($t) && $t !== '')));
        usort($terms, fn($a, $b) => mb_strlen($b) - mb_strlen($a));

        return $terms;
    }

    private function resolveSnippetSource(array $hit): string
    {
        if (!empty($hit['matchedIn']) && is_array($hit['matchedIn'])) {
            if (in_array('content', $hit['matchedIn'], true)) {
                return 'content';
            }
            if (in_array('title', $hit['matchedIn'], true)) {
                return 'title';
            }
        }

        return 'content';
    }

    private function resolveTitleMatchTerms(
        array $hit,
        ?array $matchedTerms,
        string $query,
        string $indexHandle,
        mixed $element,
    ): array {
        $matchedTerms = $matchedTerms ?? ($hit['matchedTerms'] ?? null);
        if (!empty($matchedTerms['title'])) {
            return array_values(array_unique($matchedTerms['title']));
        }

        return $this->tokenizeQueryTerms($query, $indexHandle, $element);
    }

    private function resolveHeadingMatchTerms(
        array $hit,
        ?array $matchedTerms,
        string $query,
        string $indexHandle,
        mixed $element,
    ): array {
        $matchedTerms = $matchedTerms ?? ($hit['matchedTerms'] ?? null);
        if (!empty($matchedTerms['content'])) {
            return array_values(array_unique($matchedTerms['content']));
        }

        return $this->tokenizeQueryTerms($query, $indexHandle, $element);
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
    private function tokenizeQueryTerms(string $query, string $indexHandle = '', mixed $element = null): array
    {
        $tokenizer = new \lindemannrock\searchmanager\search\Tokenizer();
        $terms = $tokenizer->tokenize($query);

        $settings = SearchManager::$plugin->getSettings();
        $disableStopWords = false;
        $language = null;

        if (!empty($indexHandle)) {
            $searchIndex = SearchIndex::findByHandle($indexHandle);
            if ($searchIndex && !empty($searchIndex->language)) {
                $language = $searchIndex->language;
            }
            if ($searchIndex) {
                $disableStopWords = (bool) $searchIndex->disableStopWords;
            }
        }

        if ($language === null) {
            $siteId = null;
            if ($element instanceof \craft\base\Element) {
                $siteId = $element->siteId ?? null;
            }
            if ($siteId) {
                $site = Craft::$app->getSites()->getSiteById($siteId);
                if ($site && !empty($site->language)) {
                    $language = strtolower(substr($site->language, 0, 2));
                }
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
