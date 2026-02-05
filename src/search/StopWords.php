<?php

namespace lindemannrock\searchmanager\search;

use lindemannrock\logginglibrary\traits\LoggingTrait;

/**
 * StopWords
 *
 * Manages stop words filtering for search indexing and querying.
 * Stop words are common words that don't add semantic value to search results.
 *
 * @since 5.0.0
 */
class StopWords
{
    use LoggingTrait;

    /**
     * @var array Loaded stop words
     */
    private array $stopWords = [];

    /**
     * @var string Language code
     */
    private string $language;

    /**
     * Constructor
     *
     * @param string $language Language code (default: 'en')
     */
    public function __construct(string $language = 'en')
    {
        $this->setLoggingHandle('search-manager');
        $this->language = $language;
        $this->loadStopWords();
    }

    /**
     * Load stop words from language file with fallback chain
     *
     * Priority:
     * 1. User's config: config/search-manager/stopwords/{language}.php
     * 2. Plugin default: src/search/stopwords/{language}.php
     * 3. User's generic: config/search-manager/stopwords/{lang}.php (ar-sa → ar)
     * 4. Plugin generic: src/search/stopwords/{lang}.php
     * 5. Empty array (no filtering)
     *
     * @return void
     */
    private function loadStopWords(): void
    {
        $stopWordsFile = $this->findStopWordsFile($this->language);

        if (!$stopWordsFile) {
            $this->logWarning('Stop words file not found', [
                'language' => $this->language,
            ]);
            $this->stopWords = [];
            return;
        }

        $this->stopWords = require $stopWordsFile;

        $this->logDebug('Loaded stop words', [
            'language' => $this->language,
            'file' => $stopWordsFile,
            'count' => count($this->stopWords),
        ]);
    }

    /**
     * Find stop words file with fallback chain
     *
     * @param string $language Language code
     * @return string|null File path or null if not found
     */
    private function findStopWordsFile(string $language): ?string
    {
        // 1. Check user's config directory first
        $userFile = \Craft::getAlias('@config/search-manager/stopwords/' . $language . '.php');
        if ($userFile && file_exists($userFile)) {
            return $userFile;
        }

        // 2. Check plugin's default file
        $pluginFile = __DIR__ . '/stopwords/' . $language . '.php';
        if (file_exists($pluginFile)) {
            return $pluginFile;
        }

        // 3. Try generic language code if regional variant (ar-sa → ar)
        if (str_contains($language, '-')) {
            $genericLang = substr($language, 0, 2);

            // 3a. User's generic
            $userGeneric = \Craft::getAlias('@config/search-manager/stopwords/' . $genericLang . '.php');
            if ($userGeneric && file_exists($userGeneric)) {
                $this->logDebug('Falling back to generic language', [
                    'requested' => $language,
                    'fallback' => $genericLang,
                    'file' => $userGeneric,
                ]);
                return $userGeneric;
            }

            // 3b. Plugin's generic
            $pluginGeneric = __DIR__ . '/stopwords/' . $genericLang . '.php';
            if (file_exists($pluginGeneric)) {
                $this->logDebug('Falling back to generic language', [
                    'requested' => $language,
                    'fallback' => $genericLang,
                    'file' => $pluginGeneric,
                ]);
                return $pluginGeneric;
            }
        }

        // 4. No stop words file found
        return null;
    }

    /**
     * Filter stop words from an array of tokens
     *
     * @since 5.0.0
     * @param array $tokens Array of tokens to filter
     * @param bool|null $enabled Override enable setting (null = use global setting)
     * @return array Filtered tokens (preserving original keys)
     */
    public function filter(array $tokens, ?bool $enabled = null): array
    {
        // Check if stop words are enabled (allow override)
        if ($enabled === null) {
            // Get from settings if available
            if (class_exists('\lindemannrock\searchmanager\SearchManager') &&
                \lindemannrock\searchmanager\SearchManager::$plugin) {
                $settings = \lindemannrock\searchmanager\SearchManager::$plugin->getSettings();
                $enabled = $settings->enableStopWords ?? true;
            } else {
                $enabled = true; // Default to enabled
            }
        }

        // If disabled, return tokens unchanged
        if (!$enabled) {
            return $tokens;
        }

        $originalCount = count($tokens);

        $filtered = array_filter($tokens, function($token) {
            return !$this->isStopWord($token);
        });

        $removedCount = $originalCount - count($filtered);

        if ($removedCount > 0) {
            $this->logDebug('Filtered stop words', [
                'original_count' => $originalCount,
                'removed_count' => $removedCount,
                'remaining_count' => count($filtered),
            ]);
        }

        return $filtered;
    }

    /**
     * Check if a token is a stop word
     *
     * @since 5.0.0
     * @param string $token Token to check
     * @return bool True if the token is a stop word
     */
    public function isStopWord(string $token): bool
    {
        return in_array(mb_strtolower($token, 'UTF-8'), $this->stopWords, true);
    }

    /**
     * Get all loaded stop words
     *
     * @since 5.0.0
     * @return array Array of stop words
     */
    public function getStopWords(): array
    {
        return $this->stopWords;
    }

    /**
     * Get the count of stop words
     *
     * @since 5.0.0
     * @return int Number of stop words
     */
    public function getCount(): int
    {
        return count($this->stopWords);
    }
}
