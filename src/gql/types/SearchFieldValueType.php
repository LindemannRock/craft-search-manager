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
 * GraphQL type for indexed custom field values.
 *
 * @since 5.53.0
 */
class SearchFieldValueType extends ObjectType
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
            'description' => 'An indexed custom field value within a Search Manager hit.',
        ]));
    }

    public static function getName(): string
    {
        return 'SearchManagerSearchFieldValue';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getFieldDefinitions(): array
    {
        return [
            'handle' => ['name' => 'handle', 'type' => Type::nonNull(Type::string()), 'description' => 'The custom field handle.'],
            'value' => ['name' => 'value', 'type' => Type::string(), 'description' => 'The field value flattened to a string.'],
            'values' => ['name' => 'values', 'type' => Type::listOf(Type::nonNull(Type::string())), 'description' => 'The field value as a list when the indexed value contains multiple strings.'],
        ];
    }

    /**
     * @inheritdoc
     */
    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        if (is_array($source)) {
            if ($resolveInfo->fieldName === 'values') {
                return is_array($source['values'] ?? null) ? $source['values'] : [];
            }

            return GqlHelper::nullIfEmptyString($source[$resolveInfo->fieldName] ?? null);
        }

        return parent::resolve($source, $arguments, $context, $resolveInfo);
    }
}
