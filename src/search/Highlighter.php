<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2025-2026 LindemannRock
 */

namespace lindemannrock\searchmanager\search;

use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * Highlighter
 *
 * Highlights search terms in text content and generates context snippets.
 *
 * Features:
 * - Highlights matched terms with HTML tags
 * - Generates contextual snippets around matches
 * - Handles phrases, wildcards, and multiple terms
 * - Configurable tag and snippet length
 *
 * @since 5.0.0
 */
class Highlighter
{
    use LoggingTrait;

    /**
     * @since 5.53.0
     */
    public const ALLOWED_TAGS = ['mark', 'em', 'strong', 'b', 'i', 'span'];

    /**
     * @var string HTML tag to wrap highlighted terms
     */
    private string $tag = 'mark';

    /**
     * @var string CSS class for highlighted terms
     */
    private string $class = '';

    /**
     * @var int Snippet length (characters around match)
     */
    private int $snippetLength = 200;

    /**
     * @var int Maximum number of snippets per field
     */
    private int $maxSnippets = 3;

    /**
     * Constructor
     *
     * @param array $config Configuration options
     */
    public function __construct(array $config = [])
    {
        $this->setLoggingHandle('search-manager');

        $tag = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', $config['tag'] ?? 'mark') ?: 'mark');
        $this->tag = in_array($tag, self::ALLOWED_TAGS, true) ? $tag : 'mark';
        $this->class = htmlspecialchars($config['class'] ?? '', ENT_QUOTES, 'UTF-8');
        $this->snippetLength = $config['snippetMaxLength'] ?? 200;
        $this->maxSnippets = $config['maxSnippets'] ?? 3;
    }

    /**
     * Whether the value is a space-separated list of plain CSS class tokens.
     *
     * Shared by the Settings and WidgetStyle validators so both highlight
     * class fields enforce the same format the widget's client-side
     * normalizeClassTokens applies.
     *
     * @since 5.53.0
     */
    public static function isValidClassTokenList(string $value): bool
    {
        foreach (preg_split('/\s+/', $value) ?: [] as $token) {
            if ($token === '' || preg_match('/^[A-Za-z0-9_-]+$/', $token) !== 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Highlight terms in text
     *
     * @param string $text Text to highlight
     * @param array $terms Search terms to highlight
     * @param bool $stripTags Strip HTML tags before highlighting
     * @param array $queryTerms Eligible raw query terms used to identify prefix extensions
     * @return string Text with highlighted terms
     * @since 5.54.0 Added word-start prefix painting and raw-query term derivation.
     */
    public function highlight(string $text, array $terms, bool $stripTags = true, array $queryTerms = []): string
    {
        if (empty($text) || empty($terms)) {
            return $text;
        }

        $text = $stripTags ? strip_tags($text) : $text;

        // Build opening tag
        $openTag = $this->class
            ? "<{$this->tag} class=\"{$this->class}\">"
            : "<{$this->tag}>";
        $closeTag = "</{$this->tag}>";

        $queryTerms = $queryTerms !== [] ? $queryTerms : $terms;
        $ranges = $this->findHighlightRanges($text, $terms, $queryTerms);
        if ($ranges === []) {
            return $stripTags ? htmlspecialchars($text, ENT_QUOTES, 'UTF-8') : $text;
        }

        $result = '';
        $cursor = 0;
        foreach ($ranges as $range) {
            $before = substr($text, $cursor, $range['start'] - $cursor);
            $match = substr($text, $range['start'], $range['end'] - $range['start']);
            if ($stripTags) {
                $before = htmlspecialchars($before, ENT_QUOTES, 'UTF-8');
                $match = htmlspecialchars($match, ENT_QUOTES, 'UTF-8');
            }

            $result .= $before . $openTag . $match . $closeTag;
            $cursor = $range['end'];
        }

        $remainder = substr($text, $cursor);
        $result .= $stripTags ? htmlspecialchars($remainder, ENT_QUOTES, 'UTF-8') : $remainder;

        return $result;
    }

    /**
     * Generate snippets with highlighted terms
     *
     * @param string $text Full text content
     * @param array $terms Search terms
     * @param bool $stripTags Strip HTML tags
     * @param array $queryTerms Eligible raw query terms used to identify prefix extensions
     * @return array Array of snippet strings
     * @since 5.54.0 Added raw-query term derivation for prefix painting.
     */
    public function generateSnippets(string $text, array $terms, bool $stripTags = true, array $queryTerms = []): array
    {
        if (empty($text) || empty($terms)) {
            return [];
        }

        // Sanitize HTML to prevent XSS (htmlspecialchars is safer than strip_tags)
        if ($stripTags) {
            $text = htmlspecialchars(strip_tags($text), ENT_QUOTES, 'UTF-8');
        }

        $snippets = [];
        $textLower = mb_strtolower($text);

        // Find positions of all term matches
        $matches = [];
        foreach ($terms as $term) {
            if (strlen($term) < 2) {
                continue;
            }

            $termLower = mb_strtolower($term);
            $offset = 0;

            while (($pos = mb_strpos($textLower, $termLower, $offset)) !== false) {
                $matches[] = [
                    'term' => $term,
                    'pos' => $pos,
                    'len' => mb_strlen($term),
                ];
                $offset = $pos + 1;
            }
        }

        // No matches found
        if (empty($matches)) {
            // Return beginning of text as fallback
            return [mb_substr($text, 0, $this->snippetLength) . '...'];
        }

        // Sort matches by position
        usort($matches, fn($a, $b) => $a['pos'] <=> $b['pos']);

        // Generate snippets around matches
        $usedRanges = [];
        foreach ($matches as $match) {
            if (count($snippets) >= $this->maxSnippets) {
                break;
            }

            $pos = $match['pos'];

            // Check if this position is already covered by a snippet
            $alreadyCovered = false;
            foreach ($usedRanges as $range) {
                if ($pos >= $range['start'] && $pos <= $range['end']) {
                    $alreadyCovered = true;
                    break;
                }
            }

            if ($alreadyCovered) {
                continue;
            }

            // Calculate snippet boundaries
            $start = max(0, $pos - ($this->snippetLength / 2));
            $end = min(mb_strlen($text), $pos + ($this->snippetLength / 2));

            // Adjust to word boundaries
            if ($start > 0) {
                // Find previous space
                $spacePos = mb_strrpos(mb_substr($text, 0, $start), ' ');
                if ($spacePos !== false) {
                    $start = $spacePos + 1;
                }
            }

            if ($end < mb_strlen($text)) {
                // Find next space
                $spacePos = mb_strpos($text, ' ', $end);
                if ($spacePos !== false) {
                    $end = $spacePos;
                }
            }

            // Extract snippet
            $snippet = mb_substr($text, $start, $end - $start);

            // Add ellipsis
            if ($start > 0) {
                $snippet = '...' . $snippet;
            }
            if ($end < mb_strlen($text)) {
                $snippet .= '...';
            }

            // Highlight terms in snippet
            $snippet = $this->highlight($snippet, $terms, false, $queryTerms);

            $snippets[] = $snippet;
            $usedRanges[] = ['start' => $start, 'end' => $end];
        }

        // If no snippets generated, return beginning
        if (empty($snippets)) {
            $snippet = mb_substr($text, 0, $this->snippetLength) . '...';
            $snippet = $this->highlight($snippet, $terms, false, $queryTerms);
            $snippets[] = $snippet;
        }

        return $snippets;
    }

    /**
     * Painting contract: exact and typo terms paint whole words, strict raw-query
     * prefix extensions paint only at word starts, and mid-word text never paints.
     *
     * @param array $terms Eligible matched terms
     * @param array $queryTerms Eligible raw query terms
     * @return list<array{start: int, end: int, type: string}>
     */
    private function findHighlightRanges(string $text, array $terms, array $queryTerms): array
    {
        $words = $this->textWords($text);
        if ($words === []) {
            return [];
        }

        $termTokens = $this->normalizedTermTokens($terms);
        $rawTokens = $this->eligibleRawQueryTokens($queryTerms, $termTokens);
        $rawExact = array_fill_keys($rawTokens, true);
        $prefixExtensions = [];

        foreach ($termTokens as $tokens) {
            if (count($tokens) !== 1 || isset($rawExact[$tokens[0]])) {
                continue;
            }
            foreach ($rawTokens as $rawToken) {
                if ($this->isStrictPrefix($rawToken, $tokens[0])) {
                    $prefixExtensions[$tokens[0]] = true;
                    break;
                }
            }
        }

        $ranges = [];
        foreach ($termTokens as $tokens) {
            if (count($tokens) === 1 && isset($prefixExtensions[$tokens[0]])) {
                continue;
            }

            $tokenCount = count($tokens);
            $lastStart = count($words) - $tokenCount;
            for ($i = 0; $i <= $lastStart; ++$i) {
                $matches = true;
                foreach ($tokens as $offset => $token) {
                    if ($words[$i + $offset]['normalized'] !== $token) {
                        $matches = false;
                        break;
                    }
                }
                if ($matches) {
                    $ranges[] = [
                        'start' => $words[$i]['start'],
                        'end' => $words[$i + $tokenCount - 1]['end'],
                        'type' => 'whole',
                    ];
                }
            }
        }

        foreach ($rawTokens as $rawToken) {
            foreach ($words as $word) {
                if (!$this->isStrictPrefix($rawToken, $word['normalized'])) {
                    continue;
                }
                $ranges[] = [
                    'start' => $word['start'],
                    'end' => $word['start'] + $this->prefixByteLength($word['text'], $rawToken),
                    'type' => 'prefix',
                ];
            }
        }

        usort($ranges, static function(array $a, array $b): int {
            if ($a['start'] !== $b['start']) {
                return $a['start'] <=> $b['start'];
            }

            $length = ($b['end'] - $b['start']) <=> ($a['end'] - $a['start']);
            return $length !== 0 ? $length : ($a['type'] === 'whole' ? -1 : 1);
        });

        $resolved = [];
        $lastEnd = -1;
        foreach ($ranges as $range) {
            if ($range['start'] >= $lastEnd) {
                $resolved[] = $range;
                $lastEnd = $range['end'];
            }
        }

        return $resolved;
    }

    /**
     * @return list<array{text: string, normalized: string, start: int, end: int}>
     */
    private function textWords(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}\p{M}_]+/u', $text, $matches, PREG_OFFSET_CAPTURE);
        $words = [];
        foreach ($matches[0] as $match) {
            $word = (string)$match[0];
            $start = (int)$match[1];
            $words[] = [
                'text' => $word,
                'normalized' => TermNormalizer::normalize($word),
                'start' => $start,
                'end' => $start + strlen($word),
            ];
        }

        return $words;
    }

    /**
     * @param array $terms
     * @return list<list<string>>
     */
    private function normalizedTermTokens(array $terms): array
    {
        $normalized = [];
        $seen = [];
        foreach ($terms as $term) {
            if (!is_string($term)) {
                continue;
            }
            preg_match_all('/[\p{L}\p{N}\p{M}_]+/u', TermNormalizer::normalize($term), $matches);
            $tokens = array_values(array_filter($matches[0], static fn(string $token): bool => mb_strlen($token) >= 2));
            if ($tokens === []) {
                continue;
            }
            $key = implode("\0", $tokens);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $normalized[] = $tokens;
            }
        }

        return $normalized;
    }

    /**
     * @param array $queryTerms
     * @param list<list<string>> $termTokens
     * @return list<string>
     */
    private function eligibleRawQueryTokens(array $queryTerms, array $termTokens): array
    {
        $matchedTokens = [];
        foreach ($termTokens as $tokens) {
            if (count($tokens) === 1) {
                $matchedTokens[] = $tokens[0];
            }
        }

        $rawTokens = [];
        foreach ($this->normalizedTermTokens($queryTerms) as $tokens) {
            if (count($tokens) !== 1) {
                continue;
            }
            $rawToken = $tokens[0];
            foreach ($matchedTokens as $matchedToken) {
                if ($rawToken === $matchedToken || $this->isStrictPrefix($rawToken, $matchedToken)) {
                    $rawTokens[$rawToken] = true;
                    break;
                }
            }
        }

        return array_keys($rawTokens);
    }

    private function isStrictPrefix(string $prefix, string $word): bool
    {
        return mb_strlen($word) > mb_strlen($prefix) && str_starts_with($word, $prefix);
    }

    private function prefixByteLength(string $word, string $normalizedPrefix): int
    {
        $characters = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $normalized = '';
        $prefix = '';
        $targetLength = mb_strlen($normalizedPrefix);
        $reachedTarget = false;

        foreach ($characters as $character) {
            $characterNormalized = TermNormalizer::normalize($character);
            if ($reachedTarget && $characterNormalized !== '') {
                break;
            }

            $prefix .= $character;
            $normalized .= $characterNormalized;
            $reachedTarget = mb_strlen($normalized) >= $targetLength;
        }

        return strlen($prefix);
    }

    /**
     * Extract search terms from a ParsedQuery
     *
     * Scope contract: unscoped terms paint both fields, scoped terms paint only
     * their matching field, and no eligible terms paint nothing.
     *
     * @param ParsedQuery $parsed Parsed query object
     * @param string|null $field Optional display-field scope (`title` or `content`)
     * @return array Array of terms to highlight
     * @since 5.54.0 Added the optional display-field scope.
     */
    public function extractTermsFromParsedQuery(ParsedQuery $parsed, ?string $field = null): array
    {
        $terms = $parsed->terms;

        // Add phrase words
        foreach ($parsed->phrases as $phrase) {
            $words = explode(' ', $phrase);
            $terms = array_merge($terms, $words);
        }

        // Add wildcard terms (without the *)
        $terms = array_merge($terms, $parsed->wildcards);

        if ($field === null) {
            // Preserve the legacy scope-blind output exactly when no display
            // field is requested.
            foreach ($parsed->fieldFilters as $fieldTerms) {
                $terms = array_merge($terms, $fieldTerms);
            }
        } else {
            // QueryParser keeps field-filter values in regular terms so they
            // participate in scoring. Remove those duplicated scoped values,
            // then add back only the values eligible for this display field.
            $scopedTermCounts = [];
            foreach ($parsed->fieldFilters as $fieldTerms) {
                foreach ($fieldTerms as $term) {
                    $scopedTermCounts[$term] = ($scopedTermCounts[$term] ?? 0) + 1;
                }
            }

            $unscopedTerms = [];
            foreach ($terms as $term) {
                if (($scopedTermCounts[$term] ?? 0) > 0) {
                    --$scopedTermCounts[$term];
                    continue;
                }
                $unscopedTerms[] = $term;
            }

            $terms = array_merge($unscopedTerms, $parsed->fieldFilters[$field] ?? []);
        }

        // Remove duplicates and empty values
        $terms = array_filter(array_unique($terms));

        return array_values($terms);
    }

    /**
     * Get configuration
     *
     * @return array
     */
    public function getConfig(): array
    {
        return [
            'tag' => $this->tag,
            'class' => $this->class,
            'snippetMaxLength' => $this->snippetLength,
            'maxSnippets' => $this->maxSnippets,
        ];
    }
}
