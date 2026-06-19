<?php
/**
 * LindemannRock Search Manager
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
 * GraphQL type for Search Manager autocomplete result suggestions.
 *
 * @since 5.53.0
 */
class AutocompleteResultType extends ObjectType
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
            'description' => 'A Search Manager autocomplete result suggestion.',
        ]));
    }

    public static function getName(): string
    {
        return 'SearchManagerAutocompleteResult';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getFieldDefinitions(): array
    {
        return [
            'id' => ['name' => 'id', 'type' => Type::int(), 'description' => 'The element ID.'],
            'text' => ['name' => 'text', 'type' => Type::string(), 'description' => 'The suggestion label.'],
            'type' => ['name' => 'type', 'type' => Type::string(), 'description' => 'The suggestion type.'],
            'url' => ['name' => 'url', 'type' => Type::string(), 'description' => 'The suggestion URL, when available.'],
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
