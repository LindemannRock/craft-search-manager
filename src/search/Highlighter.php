<?php

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

        $this->tag = $config['tag'] ?? 'mark';
        $this->class = $config['class'] ?? '';
        $this->snippetLength = $config['snippetLength'] ?? 200;
        $this->maxSnippets = $config['maxSnippets'] ?? 3;
    }

    /**
     * Highlight terms in text
     *
     * @since 5.0.0
     * @param string $text Text to highlight
     * @param array $terms Search terms to highlight
     * @param bool $stripTags Strip HTML tags before highlighting
     * @return string Text with highlighted terms
     */
    public function highlight(string $text, array $terms, bool $stripTags = true): string
    {
        if (empty($text) || empty($terms)) {
            return $text;
        }

        // Strip HTML if requested
        if ($stripTags) {
            $text = strip_tags($text);
        }

        // Build opening tag
        $openTag = $this->class
            ? "<{$this->tag} class=\"{$this->class}\">"
            : "<{$this->tag}>";
        $closeTag = "</{$this->tag}>";

        // Highlight each term (case-insensitive)
        foreach ($terms as $term) {
            if (strlen($term) < 2) {
                continue; // Skip very short terms
            }

            // Use word boundaries for better matching
            $pattern = '/\b(' . preg_quote($term, '/') . ')\b/iu';
            $text = preg_replace($pattern, $openTag . '$1' . $closeTag, $text);
        }

        return $text;
    }

    /**
     * Generate snippets with highlighted terms
     *
     * @since 5.0.0
     * @param string $text Full text content
     * @param array $terms Search terms
     * @param bool $stripTags Strip HTML tags
     * @return array Array of snippet strings
     */
    public function generateSnippets(string $text, array $terms, bool $stripTags = true): array
    {
        if (empty($text) || empty($terms)) {
            return [];
        }

        // Strip HTML if requested
        if ($stripTags) {
            $text = strip_tags($text);
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
            $snippet = $this->highlight($snippet, $terms, false);

            $snippets[] = $snippet;
            $usedRanges[] = ['start' => $start, 'end' => $end];
        }

        // If no snippets generated, return beginning
        if (empty($snippets)) {
            $snippet = mb_substr($text, 0, $this->snippetLength) . '...';
            $snippet = $this->highlight($snippet, $terms, false);
            $snippets[] = $snippet;
        }

        return $snippets;
    }

    /**
     * Extract search terms from a ParsedQuery
     *
     * @since 5.0.0
     * @param ParsedQuery $parsed Parsed query object
     * @return array Array of terms to highlight
     */
    public function extractTermsFromParsedQuery(ParsedQuery $parsed): array
    {
        $terms = [];

        // Add regular terms
        $terms = array_merge($terms, $parsed->terms);

        // Add phrase words
        foreach ($parsed->phrases as $phrase) {
            $words = explode(' ', $phrase);
            $terms = array_merge($terms, $words);
        }

        // Add wildcard terms (without the *)
        $terms = array_merge($terms, $parsed->wildcards);

        // Add field filter terms
        foreach ($parsed->fieldFilters as $field => $fieldTerms) {
            $terms = array_merge($terms, $fieldTerms);
        }

        // Remove duplicates and empty values
        $terms = array_filter(array_unique($terms));

        return array_values($terms);
    }

    /**
     * Get configuration
     *
     * @since 5.0.0
     * @return array
     */
    public function getConfig(): array
    {
        return [
            'tag' => $this->tag,
            'class' => $this->class,
            'snippetLength' => $this->snippetLength,
            'maxSnippets' => $this->maxSnippets,
        ];
    }
}
