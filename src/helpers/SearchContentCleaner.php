<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\helpers;

/**
 * Cleans indexed HTML content and tracks prose-only text for _contentClean.
 *
 * @since 5.53.0
 */
class SearchContentCleaner
{
    /**
     * @var string[]
     */
    private array $codeFreeParts = [];

    /**
     * @var string[]
     */
    private array $fullParts = [];

    public function stripHtml(?string $html): string
    {
        if (!$html) {
            return '';
        }

        $text = strip_tags($this->addBlockBoundaries($html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text);

        $result = trim((string)$text);

        if (stripos($html, '<pre') !== false) {
            $this->codeFreeParts[] = $this->stripHtmlWithoutCode($html);
            $this->fullParts[] = $result;
        }

        return $result;
    }

    public function stripHtmlWithoutCode(?string $html): string
    {
        if (!$html) {
            return '';
        }

        $text = (string)preg_replace('/<pre[^>]*>.*?<\/pre>/is', ' ', $html);
        $text = strip_tags($this->addBlockBoundaries($text));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string)preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    public function cleanBody(?string $html): string
    {
        if (!$html) {
            return '';
        }

        $html = (string)preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html);
        $html = (string)preg_replace('/<pre\b[^>]*>.*?<\/pre>/is', ' ', $html);
        $html = (string)preg_replace('/<h[1-6]\b[^>]*>.*?<\/h[1-6]>/is', ' ', $html);
        $text = strip_tags($this->addBlockBoundaries($html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string)preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * Clean body text while preserving block-level code content.
     *
     * @since 5.53.0
     */
    public function cleanBodyWithCode(?string $html): string
    {
        if (!$html) {
            return '';
        }

        $html = (string)preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $html);
        $html = (string)preg_replace('/<h[1-6]\b[^>]*>.*?<\/h[1-6]>/is', ' ', $html);
        $html = (string)preg_replace('/<\/pre>/i', '</pre> ', $html);
        $text = strip_tags($this->addBlockBoundaries($html));
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string)preg_replace('/\s+/', ' ', $text);

        return trim($text);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function finalize(array $data): array
    {
        if (!empty($this->codeFreeParts)) {
            $cleanContent = implode(' ', $this->codeFreeParts);
            $cleanContent = trim((string)preg_replace('/\s+/', ' ', $cleanContent));

            $fullContent = implode(' ', $this->fullParts);
            $fullContent = trim((string)preg_replace('/\s+/', ' ', $fullContent));

            if (!empty($cleanContent) && $cleanContent !== $fullContent) {
                $data['_contentClean'] = $cleanContent;
            }
        }

        $this->reset();

        return $data;
    }

    public function reset(): void
    {
        $this->codeFreeParts = [];
        $this->fullParts = [];
    }

    private function addBlockBoundaries(string $html): string
    {
        $html = (string)preg_replace('/<br\s*\/?>/i', ' ', $html);

        return (string)preg_replace('/<\/(?:h[1-6]|p|div|li|td|th|blockquote|section|article|button)>/i', ' ', $html);
    }
}
