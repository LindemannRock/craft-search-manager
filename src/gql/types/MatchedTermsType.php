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

/**
 * GraphQL type for matched search terms grouped by source field.
 *
 * @since 5.53.0
 */
class MatchedTermsType extends ObjectType
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
            'description' => 'Search Manager matched terms grouped by indexed field.',
        ]));
    }

    public static function getName(): string
    {
        return 'SearchManagerMatchedTerms';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getFieldDefinitions(): array
    {
        return [
            'title' => [
                'name' => 'title',
                'type' => Type::listOf(Type::string()),
                'description' => 'Terms matched in title fields.',
            ],
            'content' => [
                'name' => 'content',
                'type' => Type::listOf(Type::string()),
                'description' => 'Terms matched in content fields.',
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        if (is_array($source)) {
            $value = $source[$resolveInfo->fieldName] ?? [];

            return is_array($value) ? array_values(array_map('strval', $value)) : [];
        }

        return parent::resolve($source, $arguments, $context, $resolveInfo);
    }
}
