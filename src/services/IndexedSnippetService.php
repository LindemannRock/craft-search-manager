<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\services;

use lindemannrock\searchmanager\helpers\SearchContentCleaner;
use lindemannrock\searchmanager\helpers\SearchFieldValueHelper;
use lindemannrock\searchmanager\helpers\SearchHeadingValueHelper;
use lindemannrock\searchmanager\helpers\SnippetOptionsHelper;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\search\StopWords;
use lindemannrock\searchmanager\search\Tokenizer;
use lindemannrock\searchmanager\SearchManager;
use yii\base\Component;

/**
 * Builds public snippets and heading matches from indexed hit data only.
 *
 * @since 5.54.0
 */
class IndexedSnippetService extends Component
{
    /**
     * @var array<string, SearchIndex|null> Request-local index lookup cache used while tokenizing result sets.
     */
    private array $tokenizeIndexLookupCache = [];

    /**
     * @param array<string, mixed> $hit
     * @param array<string, mixed> $options
     * @return array{snippet: string|null, headings: list<array{title: string, id: string, level: int, url: string|null, snippet: string|null}>}
     */
    public function prepareHitSnippets(
        array $hit,
        string $query,
        string $indexHandle = '',
        array $options = [],
        ?array &$debugMeta = null,
    ): array {
        $snippetMode = SnippetOptionsHelper::normalizeMode($options['snippetMode'] ?? SnippetOptionsHelper::DEFAULT_MODE);
        $snippetLength = SnippetOptionsHelper::normalizeLength($options['snippetLength'] ?? SnippetOptionsHelper::DEFAULT_LENGTH);
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
            : strtolower((string)($hit['type'] ?? ''));

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
                $showCodeSnippets,
                $parseMarkdownSnippets,
                $title,
                $url !== '' ? $url : null,
                $documentType,
            ),
        ];
    }

    /**
     * @param array<string, mixed> $hit
     * @param array<string, mixed>|null $matchedTerms
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
        $fieldValues = SearchFieldValueHelper::snippetFieldsFromHit($hit);
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

        $bodyText = $this->bodySnippetText($hit, $showCodeSnippets, $parseMarkdownSnippets);
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

        if ($best === null && $this->isSplitSectionHit($hit) && $bodyText !== '') {
            $plainSnippet = $this->leadingSnippet($bodyText, $snippetLength);
            if ($plainSnippet !== null) {
                $best = $this->snippetCandidate(
                    handle: 'sectionBody',
                    snippet: $plainSnippet,
                    matchedTerms: [],
                    source: 'section-body-fallback',
                    position: 0,
                    preferred: true,
                    fullContentLength: mb_strlen($bodyText),
                );
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

    /**
     * @param array<string, mixed> $hit
     */
    private function bodySnippetText(array $hit, bool $showCodeSnippets, bool $parseMarkdownSnippets): string
    {
        $body = '';
        if ($showCodeSnippets) {
            $body = $this->stringValueFromMixed($hit['_sectionBodyWithCode'] ?? null);
            if ($body === '') {
                $body = $this->stringValueFromMixed($hit['_bodyWithCode'] ?? null);
            }
        }
        if ($body === '') {
            $body = $this->stringValueFromMixed($hit['_bodyClean'] ?? null);
        }
        if ($body === '') {
            return '';
        }

        return $this->htmlToPlainText($body, $showCodeSnippets, $parseMarkdownSnippets);
    }

    /**
     * @param array<string, mixed> $hit
     */
    private function isSplitSectionHit(array $hit): bool
    {
        return isset($hit['sectionType']) && is_string($hit['sectionType']) && trim($hit['sectionType']) !== '';
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

        $plain = $this->htmlToPlainText($value, $showCodeSnippets, $parseMarkdownSnippets);

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
     * @param array<string, mixed> $hit
     * @param array<string, mixed>|null $matchedTerms
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
            $terms = array_merge($terms, is_array($matchedTerms['_bodyClean'] ?? null) ? $matchedTerms['_bodyClean'] : []);
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

    /**
     * @param array<string, mixed> $hit
     */
    private function shouldExposeFullBodyHeadings(array $hit, string $documentType): bool
    {
        if ($documentType !== 'source-doc' || $this->stringValueFromMixed($hit['_bodyClean'] ?? null) === '') {
            return false;
        }

        $matchedIn = $hit['matchedIn'] ?? [];

        return is_array($matchedIn) && (in_array('content', $matchedIn, true) || in_array('_bodyClean', $matchedIn, true));
    }

    /**
     * @param array<string, mixed> $hit
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
        bool $showCodeSnippets,
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
            $this->bodySnippetText($hit, $showCodeSnippets, $parseMarkdownSnippets),
            $headingSnippetTerms,
            $snippetMode,
            $snippetLength,
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

            if ($snippet === null && $headingText !== '') {
                $snippet = $this->leadingSnippet($headingText, $snippetLength);
            }

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

    private function htmlToPlainText(string $html, bool $includeCode, bool $parseMarkdownSnippets): string
    {
        $html = (string) preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html);

        if (!$includeCode) {
            $html = SearchContentCleaner::removeMarkdownFencedCode($html);
            $html = (string) preg_replace('/<pre\b[^>]*>.*?<\/pre>/is', ' ', $html);
        } elseif ($parseMarkdownSnippets) {
            $html = SearchContentCleaner::unwrapMarkdownFencedCode($html);
            $html = SearchContentCleaner::addBlockBoundaries($html);
        }

        $html = $this->normalizeInlineCodeHtml($html);
        $shouldCleanMarkdown = $parseMarkdownSnippets && !$this->containsStructuralHtml($html);
        $text = strip_tags(SearchContentCleaner::addBlockBoundaries($html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = $shouldCleanMarkdown ? SearchContentCleaner::cleanMarkdownPlainText($text, SearchContentCleaner::MARKDOWN_CODE_UNWRAP) : $text;
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    private function normalizeInlineCodeHtml(string $html): string
    {
        return (string) preg_replace('/<\/?code\b[^>]*>/i', '', $html);
    }

    private function containsStructuralHtml(string $text): bool
    {
        return preg_match('/<\/?(?:h[1-6]|p|div|ul|ol|li|table|thead|tbody|tfoot|tr|td|th|blockquote|section|article)\b[^>]*>/i', $text) === 1;
    }

    /**
     * @param list<string> $terms
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
        if ($textLength <= $maxLength) {
            return $text;
        }

        $mode = strtolower($mode);
        if ($mode === 'early') {
            $lead = 10;
        } elseif ($mode === 'deep') {
            $lead = 90;
        } else {
            $lead = 40;
        }

        $start = max(0, $pos - $lead);
        $end = min($textLength, $start + $maxLength);

        if ($end === $textLength) {
            $start = max(0, $end - $maxLength);
        }

        [$start, $end] = $this->adjustSnippetToWordBoundaries($text, $start, $end, $maxLength, $pos, $lead);
        $snippet = mb_substr($text, $start, $end - $start);

        $prefix = $start > 0 ? '...' : '';
        $suffix = $end < $textLength ? '...' : '';

        return $prefix . trim($snippet) . $suffix;
    }

    private function leadingSnippet(string $text, int $maxLength): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }

        $textLength = mb_strlen($text);
        if ($textLength <= $maxLength) {
            return $text;
        }

        $snippet = mb_substr($text, 0, $maxLength);
        $lastSpace = mb_strrpos($snippet, ' ');
        if ($lastSpace !== false) {
            $snippet = mb_substr($snippet, 0, (int) $lastSpace);
        }

        $snippet = trim($snippet);
        if ($snippet === '') {
            $snippet = trim(mb_substr($text, 0, $maxLength));
        }

        return $snippet . '...';
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function adjustSnippetToWordBoundaries(
        string $text,
        int $start,
        int $end,
        int $maxLength,
        int $matchPos,
        int $lead,
    ): array {
        $textLength = mb_strlen($text);

        $before = mb_substr($text, 0, $start);
        $lastSpace = mb_strrpos($before, ' ');
        if ($lastSpace !== false) {
            $start = (int) $lastSpace + 1;
        }

        $after = mb_substr($text, $end);
        $nextSpace = mb_strpos($after, ' ');
        if ($nextSpace !== false) {
            $end = $end + (int) $nextSpace;
        }

        if (($end - $start) > $maxLength) {
            $start = max(0, $matchPos - $lead);
            $end = min($textLength, $start + $maxLength);
        }

        if ($end === $textLength) {
            $start = max(0, $end - $maxLength);
        }

        return [$start, $end];
    }

    private function stringValueFromMixed(mixed $value): string
    {
        return is_scalar($value) ? trim((string)$value) : '';
    }

    /**
     * @param array<string, mixed> $hit
     * @param array<string, mixed>|null $matchedTerms
     * @return list<string>
     */
    private function resolveTitleMatchTerms(
        array $hit,
        ?array $matchedTerms,
        string $query,
        string $indexHandle,
    ): array {
        $matchedTerms = $matchedTerms ?? ($hit['matchedTerms'] ?? null);
        if (!empty($matchedTerms['title']) && is_array($matchedTerms['title'])) {
            return array_values(array_unique($matchedTerms['title']));
        }

        return $this->tokenizeQueryTerms($query, $indexHandle);
    }

    /**
     * @param array<string, mixed> $hit
     * @param array<string, mixed>|null $matchedTerms
     * @return list<string>
     */
    private function resolveHeadingMatchTerms(
        array $hit,
        ?array $matchedTerms,
        string $query,
        string $indexHandle,
    ): array {
        $matchedTerms = $matchedTerms ?? ($hit['matchedTerms'] ?? null);
        $terms = [];
        if (!empty($matchedTerms['content']) && is_array($matchedTerms['content'])) {
            $terms = array_merge($terms, $matchedTerms['content']);
        }
        if (!empty($matchedTerms['_bodyClean']) && is_array($matchedTerms['_bodyClean'])) {
            $terms = array_merge($terms, $matchedTerms['_bodyClean']);
        }
        if ($terms !== []) {
            return array_values(array_unique($terms));
        }

        return $this->tokenizeQueryTerms($query, $indexHandle);
    }

    /**
     * @param list<string> $terms
     */
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
     * @return string[]
     */
    private function tokenizeQueryTerms(string $query, string $indexHandle = ''): array
    {
        $tokenizer = new Tokenizer();
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
            $stopWords = new StopWords($language);
            $terms = $stopWords->filter($terms);
        }

        return array_values(array_unique($terms));
    }
}
