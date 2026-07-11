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
 * GraphQL type for enriched search result headings.
 *
 * @since 5.53.0
 */
class SearchHeadingType extends ObjectType
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
            'description' => 'An enriched heading within a Search Manager hit.',
        ]));
    }

    public static function getName(): string
    {
        return 'SearchManagerSearchHeading';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getFieldDefinitions(): array
    {
        return [
            'title' => ['name' => 'title', 'type' => Type::string(), 'description' => 'The heading title.'],
            'id' => ['name' => 'id', 'type' => Type::string(), 'description' => 'The heading anchor ID.'],
            'level' => ['name' => 'level', 'type' => Type::int(), 'description' => 'The heading level.'],
            'url' => ['name' => 'url', 'type' => Type::string(), 'description' => 'The heading URL.'],
            'snippet' => ['name' => 'snippet', 'type' => Type::string(), 'description' => 'The heading snippet.'],
        ];
    }

    /**
     * @inheritdoc
     */
    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        if (is_array($source)) {
            if ($resolveInfo->fieldName === 'level') {
                return isset($source['level']) && is_numeric($source['level']) ? (int)$source['level'] : null;
            }

            return GqlHelper::nullIfEmptyString($source[$resolveInfo->fieldName] ?? null);
        }

        return parent::resolve($source, $arguments, $context, $resolveInfo);
    }
}
