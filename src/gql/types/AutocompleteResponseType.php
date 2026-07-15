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
 * GraphQL response type for Search Manager autocomplete queries.
 *
 * @since 5.53.0
 */
class AutocompleteResponseType extends ObjectType
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
            'description' => 'A Search Manager autocomplete response.',
        ]));
    }

    public static function getName(): string
    {
        return 'SearchManagerAutocompleteResponse';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getFieldDefinitions(): array
    {
        return [
            'suggestions' => [
                'name' => 'suggestions',
                'type' => Type::listOf(Type::string()),
                'description' => 'Term suggestions.',
            ],
            'results' => [
                'name' => 'results',
                'type' => Type::listOf(AutocompleteResultType::getType()),
                'description' => 'Element result suggestions.',
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
