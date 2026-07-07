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
 * GraphQL type for Search Manager search metadata.
 *
 * @since 5.53.0
 */
class SearchMetaType extends ObjectType
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
            'description' => 'Search Manager debug metadata.',
        ]));
    }

    public static function getName(): string
    {
        return 'SearchManagerSearchMeta';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getFieldDefinitions(): array
    {
        return [
            'cached' => ['name' => 'cached', 'type' => Type::boolean(), 'description' => 'Whether results came from cache.'],
            'took' => ['name' => 'took', 'type' => Type::float(), 'description' => 'Backend execution time in milliseconds.'],
            'cacheEnabled' => ['name' => 'cacheEnabled', 'type' => Type::boolean(), 'description' => 'Whether search caching is enabled.'],
            'cacheDriver' => ['name' => 'cacheDriver', 'type' => Type::string(), 'description' => 'The cache driver.'],
            'backend' => ['name' => 'backend', 'type' => Type::string(), 'description' => 'The backend name.'],
            'synonymsExpanded' => ['name' => 'synonymsExpanded', 'type' => Type::boolean(), 'description' => 'Whether query synonyms were expanded.'],
            'expandedQueries' => ['name' => 'expandedQueries', 'type' => Type::listOf(Type::string()), 'description' => 'Expanded query strings.'],
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
