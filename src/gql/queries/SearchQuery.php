<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\gql\queries;

use craft\gql\base\Query;
use GraphQL\Type\Definition\Type;
use lindemannrock\base\helpers\GqlHelper;
use lindemannrock\searchmanager\gql\resolvers\SearchResolver;
use lindemannrock\searchmanager\gql\types\AutocompleteResponseType;
use lindemannrock\searchmanager\gql\types\SearchResponseType;

/**
 * GraphQL queries for Search Manager.
 *
 * @since 5.53.0
 */
class SearchQuery extends Query
{
    /**
     * @inheritdoc
     */
    public static function getQueries(bool $checkToken = true): array
    {
        if ($checkToken && !GqlHelper::canQuery('searchManager.all')) {
            return [];
        }

        return [
            'searchManagerSearch' => [
                'type' => SearchResponseType::getType(),
                'args' => [
                    'query' => [
                        'name' => 'query',
                        'type' => Type::nonNull(Type::string()),
                        'description' => 'The search query.',
                    ],
                    'index' => [
                        'name' => 'index',
                        'type' => Type::string(),
                        'description' => 'A single index handle to search.',
                    ],
                    'indices' => [
                        'name' => 'indices',
                        'type' => Type::listOf(Type::string()),
                        'description' => 'A list of index handles to search. Omit to search all enabled indices.',
                    ],
                    'site' => [
                        'name' => 'site',
                        'type' => Type::string(),
                        'description' => 'The site handle to filter results by.',
                    ],
                    'siteId' => [
                        'name' => 'siteId',
                        'type' => Type::int(),
                        'description' => 'The site ID to filter results by.',
                    ],
                    'hitsPerPage' => [
                        'name' => 'hitsPerPage',
                        'type' => Type::int(),
                        'description' => 'Maximum results per page. Defaults to 20 and is capped at 200.',
                    ],
                    'page' => [
                        'name' => 'page',
                        'type' => Type::int(),
                        'description' => 'Zero-based page number.',
                    ],
                    'type' => [
                        'name' => 'type',
                        'type' => Type::string(),
                        'description' => 'Optional element type filter.',
                    ],
                    'filters' => [
                        'name' => 'filters',
                        'type' => Type::string(),
                        'description' => 'Optional backend-specific filter expression. Requires a single index.',
                    ],
                    'language' => [
                        'name' => 'language',
                        'type' => Type::string(),
                        'description' => 'Optional language code for localized search operators.',
                    ],
                    'lang' => [
                        'name' => 'lang',
                        'type' => Type::string(),
                        'description' => 'Alias for language.',
                    ],
                    'source' => [
                        'name' => 'source',
                        'type' => Type::string(),
                        'description' => 'Optional analytics source identifier.',
                    ],
                    'platform' => [
                        'name' => 'platform',
                        'type' => Type::string(),
                        'description' => 'Optional analytics platform label.',
                    ],
                    'appVersion' => [
                        'name' => 'appVersion',
                        'type' => Type::string(),
                        'description' => 'Optional analytics app version label.',
                    ],
                    'skipAnalytics' => [
                        'name' => 'skipAnalytics',
                        'type' => Type::boolean(),
                        'description' => 'Whether to skip search analytics tracking.',
                    ],
                    'enrich' => [
                        'name' => 'enrich',
                        'type' => Type::boolean(),
                        'description' => 'Whether to enrich hits with titles, URLs, snippets, and headings.',
                    ],
                    'snippetMode' => [
                        'name' => 'snippetMode',
                        'type' => Type::string(),
                        'description' => 'Snippet positioning mode for enriched results.',
                    ],
                    'snippetLength' => [
                        'name' => 'snippetLength',
                        'type' => Type::int(),
                        'description' => 'Maximum snippet length for enriched results.',
                    ],
                    'showCodeSnippets' => [
                        'name' => 'showCodeSnippets',
                        'type' => Type::boolean(),
                        'description' => 'Whether enriched snippets may include code blocks.',
                    ],
                    'parseMarkdownSnippets' => [
                        'name' => 'parseMarkdownSnippets',
                        'type' => Type::boolean(),
                        'description' => 'Whether markdown should be parsed before generating snippets.',
                    ],
                    'hideResultsWithoutUrl' => [
                        'name' => 'hideResultsWithoutUrl',
                        'type' => Type::boolean(),
                        'description' => 'Whether enriched hits without URLs should be hidden.',
                    ],
                ],
                'resolve' => SearchResolver::class . '::resolveSearch',
                'description' => 'Runs a Search Manager search through the configured backend.',
            ],
            'searchManagerAutocomplete' => [
                'type' => AutocompleteResponseType::getType(),
                'args' => [
                    'query' => [
                        'name' => 'query',
                        'type' => Type::nonNull(Type::string()),
                        'description' => 'The partial search query.',
                    ],
                    'index' => [
                        'name' => 'index',
                        'type' => Type::string(),
                        'description' => 'A single index handle to use.',
                    ],
                    'indices' => [
                        'name' => 'indices',
                        'type' => Type::listOf(Type::string()),
                        'description' => 'A list of index handles to use. Omit to query all enabled indices.',
                    ],
                    'site' => [
                        'name' => 'site',
                        'type' => Type::string(),
                        'description' => 'The site handle to filter suggestions by.',
                    ],
                    'siteId' => [
                        'name' => 'siteId',
                        'type' => Type::int(),
                        'description' => 'The site ID to filter suggestions by.',
                    ],
                    'hitsPerPage' => [
                        'name' => 'hitsPerPage',
                        'type' => Type::int(),
                        'description' => 'Maximum suggestions/results. Defaults to 10 and is capped at 100.',
                    ],
                    'only' => [
                        'name' => 'only',
                        'type' => Type::string(),
                        'description' => 'Return only suggestions or results.',
                    ],
                    'type' => [
                        'name' => 'type',
                        'type' => Type::string(),
                        'description' => 'Optional element type filter for result suggestions.',
                    ],
                    'language' => [
                        'name' => 'language',
                        'type' => Type::string(),
                        'description' => 'Optional language code.',
                    ],
                    'lang' => [
                        'name' => 'lang',
                        'type' => Type::string(),
                        'description' => 'Alias for language.',
                    ],
                ],
                'resolve' => SearchResolver::class . '::resolveAutocomplete',
                'description' => 'Returns autocomplete suggestions and/or result suggestions.',
            ],
        ];
    }
}
