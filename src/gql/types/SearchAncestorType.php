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
 * GraphQL type for indexed search-result breadcrumb ancestors.
 *
 * @since 5.53.0
 */
class SearchAncestorType extends ObjectType
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
            'description' => 'A breadcrumb ancestor for a Search Manager search hit.',
        ]));
    }

    public static function getName(): string
    {
        return 'SearchManagerSearchAncestor';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getFieldDefinitions(): array
    {
        return [
            'id' => ['name' => 'id', 'type' => Type::int(), 'description' => 'The ancestor element or folder ID.'],
            'title' => ['name' => 'title', 'type' => Type::string(), 'description' => 'The ancestor title or folder name.'],
        ];
    }

    /**
     * @inheritdoc
     */
    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        if (is_array($source)) {
            if ($resolveInfo->fieldName === 'id') {
                return isset($source['id']) && is_numeric($source['id']) ? (int)$source['id'] : null;
            }

            return GqlHelper::nullIfEmptyString($source[$resolveInfo->fieldName] ?? null);
        }

        return parent::resolve($source, $arguments, $context, $resolveInfo);
    }
}
