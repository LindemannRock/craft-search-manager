<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\gql\types;

use craft\gql\base\ObjectType;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use lindemannrock\base\helpers\GqlHelper;

/**
 * GraphQL response type for Search Manager search queries.
 *
 * @since 5.53.0
 */
class SearchResponseType extends ObjectType
{
    public static function getType(): Type
    {
        $typeName = self::getName();
        if ($type = GqlEntityRegistry::getEntity($typeName)) {
            return $type;
        }

        return GqlEntityRegistry::createEntity($typeName, new self([
            'name' => $typeName,
            'fields' => self::class . '::getFieldDefinitions',
            'description' => 'A Search Manager search response.',
        ]));
    }

    public static function getName(): string
    {
        return 'SearchManagerSearchResponse';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getFieldDefinitions(): array
    {
        return [
            'hits' => [
                'name' => 'hits',
                'type' => Type::listOf(SearchHitType::getType()),
                'description' => 'The matching search hits.',
            ],
            'total' => [
                'name' => 'total',
                'type' => Type::int(),
                'description' => 'The total number of matching results.',
            ],
            'query' => [
                'name' => 'query',
                'type' => Type::string(),
                'description' => 'The search query.',
            ],
            'page' => [
                'name' => 'page',
                'type' => Type::int(),
                'description' => 'The zero-based page number.',
            ],
            'resultsLimit' => [
                'name' => 'resultsLimit',
                'type' => Type::int(),
                'description' => 'The requested result limit per page.',
            ],
            'totalPages' => [
                'name' => 'totalPages',
                'type' => Type::int(),
                'description' => 'The total number of result pages.',
            ],
            'redirect' => [
                'name' => 'redirect',
                'type' => Type::string(),
                'description' => 'The query-rule redirect URL, when matched.',
            ],
            'indices' => [
                'name' => 'indices',
                'type' => Type::listOf(IndexCountType::getType()),
                'description' => 'Per-index result counts for multi-index searches.',
            ],
            'meta' => [
                'name' => 'meta',
                'type' => SearchMetaType::getType(),
                'description' => 'Debug metadata, exposed only when allowed.',
            ],
            'error' => [
                'name' => 'error',
                'type' => Type::string(),
                'description' => 'A non-fatal error message, if applicable.',
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        if (is_array($source)) {
            return GqlHelper::nullIfEmptyString($source[$resolveInfo->fieldName] ?? null);
        }

        return parent::resolve($source, $arguments, $context, $resolveInfo);
    }
}
