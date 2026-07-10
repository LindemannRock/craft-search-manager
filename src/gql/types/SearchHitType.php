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
use lindemannrock\searchmanager\helpers\SearchFieldValueHelper;
use lindemannrock\searchmanager\helpers\SearchHitIdentityHelper;

/**
 * GraphQL type for Search Manager search hits.
 *
 * @since 5.53.0
 */
class SearchHitType extends ObjectType
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
            'description' => 'A Search Manager search hit.',
        ]));
    }

    public static function getName(): string
    {
        return 'SearchManagerSearchHit';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getFieldDefinitions(): array
    {
        return [
            'id' => ['name' => 'id', 'type' => Type::int(), 'description' => 'The element ID.'],
            'objectID' => ['name' => 'objectID', 'type' => Type::string(), 'description' => 'The backend object ID.'],
            'backendId' => ['name' => 'backendId', 'type' => Type::string(), 'description' => 'The backend-native hit ID, which may be composite.'],
            'elementId' => ['name' => 'elementId', 'type' => Type::int(), 'description' => 'The element ID.'],
            'elementType' => ['name' => 'elementType', 'type' => Type::string(), 'description' => 'The stable lowercase document kind.'],
            'siteId' => ['name' => 'siteId', 'type' => Type::int(), 'description' => 'The site ID.'],
            'site' => ['name' => 'site', 'type' => Type::string(), 'description' => 'The site handle.'],
            'index' => ['name' => 'index', 'type' => Type::string(), 'description' => 'The source index handle.'],
            'title' => ['name' => 'title', 'type' => Type::string(), 'description' => 'The result title.'],
            'slug' => ['name' => 'slug', 'type' => Type::string(), 'description' => 'The result slug.'],
            'url' => ['name' => 'url', 'type' => Type::string(), 'description' => 'The result URL.'],
            'uri' => ['name' => 'uri', 'type' => Type::string(), 'description' => 'The result URI.'],
            'snippet' => ['name' => 'snippet', 'type' => Type::string(), 'description' => 'The query-centered match snippet, when there is a match to excerpt.'],
            'section' => ['name' => 'section', 'type' => Type::string(), 'description' => 'The Entry section name, when the hit is an Entry.'],
            'sectionHandle' => ['name' => 'sectionHandle', 'type' => Type::string(), 'description' => 'The Entry section handle, when the hit is an Entry.'],
            'sectionType' => ['name' => 'sectionType', 'type' => Type::string(), 'description' => 'The Entry section type, when the hit is an Entry.'],
            'ancestors' => [
                'name' => 'ancestors',
                'type' => Type::listOf(Type::nonNull(SearchAncestorType::getType())),
                'description' => 'Breadcrumb ancestors ordered from root to parent or containing folder.',
            ],
            'level' => ['name' => 'level', 'type' => Type::int(), 'description' => 'The structure depth for Entry and Category hits.'],
            'folderPath' => ['name' => 'folderPath', 'type' => Type::string(), 'description' => 'The canonical Craft folder path for public Asset hits.'],
            'volume' => ['name' => 'volume', 'type' => Type::string(), 'description' => 'The Asset volume name, when the hit is an Asset.'],
            'volumeHandle' => ['name' => 'volumeHandle', 'type' => Type::string(), 'description' => 'The Asset volume handle, when the hit is an Asset.'],
            'group' => ['name' => 'group', 'type' => Type::string(), 'description' => 'The Category group name, when the hit is a Category.'],
            'groupHandle' => ['name' => 'groupHandle', 'type' => Type::string(), 'description' => 'The Category group handle, when the hit is a Category.'],
            'productType' => ['name' => 'productType', 'type' => Type::string(), 'description' => 'The Commerce product type display name, when the hit is a Product or Variant.'],
            'productTypeHandle' => ['name' => 'productTypeHandle', 'type' => Type::string(), 'description' => 'The Commerce product type handle, when the hit is a Product or Variant.'],
            'fields' => [
                'name' => 'fields',
                'type' => Type::nonNull(Type::listOf(Type::nonNull(SearchFieldValueType::getType()))),
                'description' => 'Indexed custom field values for this hit.',
            ],
            'type' => ['name' => 'type', 'type' => Type::string(), 'description' => 'The stable lowercase document kind.'],
            'score' => ['name' => 'score', 'type' => Type::float(), 'description' => 'The result score.'],
            'matchedIn' => ['name' => 'matchedIn', 'type' => Type::listOf(Type::string()), 'description' => 'Indexed fields that matched the query.'],
            'matchedTerms' => ['name' => 'matchedTerms', 'type' => MatchedTermsType::getType(), 'description' => 'Matched query terms grouped by field.'],
            'promoted' => ['name' => 'promoted', 'type' => Type::boolean(), 'description' => 'Whether the hit was promoted.'],
            'boosted' => ['name' => 'boosted', 'type' => Type::boolean(), 'description' => 'Whether the hit was boosted.'],
            'position' => ['name' => 'position', 'type' => Type::int(), 'description' => 'The promoted position.'],
            'dateCreated' => ['name' => 'dateCreated', 'type' => Type::int(), 'description' => 'The indexed creation timestamp.'],
            'dateUpdated' => ['name' => 'dateUpdated', 'type' => Type::int(), 'description' => 'The indexed update timestamp.'],
            'thumbnail' => ['name' => 'thumbnail', 'type' => Type::string(), 'description' => 'The thumbnail URL, when enriched.'],
            'headings' => [
                'name' => 'headings',
                'type' => Type::listOf(SearchHeadingType::getType()),
                'description' => 'Enriched heading matches.',
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        if (is_array($source)) {
            $fieldName = $resolveInfo->fieldName;

            if ($fieldName === 'index') {
                return GqlHelper::nullIfEmptyString($source['_index'] ?? $source['index'] ?? null);
            }

            if ($fieldName === 'backendId') {
                $backendId = $source['backendId'] ?? $source['_backendId'] ?? null;
                if ($backendId === null) {
                    $backendId = SearchHitIdentityHelper::rawBackendId($source);
                }

                return GqlHelper::nullIfEmptyString($backendId !== null ? (string)$backendId : null);
            }

            if ($fieldName === 'slug') {
                return GqlHelper::nullIfEmptyString($source['slug'] ?? null);
            }

            if ($fieldName === 'site') {
                return GqlHelper::siteHandle(isset($source['siteId']) ? (int)$source['siteId'] : null);
            }

            if ($fieldName === 'elementId') {
                return SearchHitIdentityHelper::elementId($source);
            }

            if ($fieldName === 'id') {
                if (isset($source['id']) && is_numeric($source['id'])) {
                    return (int)$source['id'];
                }

                return isset($source['objectID']) && is_numeric($source['objectID']) ? (int)$source['objectID'] : null;
            }

            if ($fieldName === 'matchedIn') {
                $matchedIn = $source['matchedIn'] ?? [];

                return is_array($matchedIn) ? array_values(array_map('strval', $matchedIn)) : [];
            }

            if ($fieldName === 'matchedTerms') {
                return is_array($source['matchedTerms'] ?? null) ? $source['matchedTerms'] : null;
            }

            if ($fieldName === 'fields') {
                $fields = is_array($source['fields'] ?? null)
                    ? $source['fields']
                    : SearchFieldValueHelper::fieldsFromHit($source);

                return SearchFieldValueHelper::toGraphqlList($fields);
            }

            if ($fieldName === 'ancestors') {
                $ancestors = self::ancestorsFromSource($source['ancestors'] ?? null);

                return $ancestors !== [] ? $ancestors : null;
            }

            if ($fieldName === 'level') {
                return isset($source['level']) && is_numeric($source['level']) ? (int)$source['level'] : null;
            }

            return GqlHelper::nullIfEmptyString($source[$fieldName] ?? null);
        }

        return parent::resolve($source, $arguments, $context, $resolveInfo);
    }

    /**
     * @return array<int, array{id: int, title: string}>
     */
    private static function ancestorsFromSource(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $ancestors = [];
        foreach ($value as $ancestor) {
            if (!is_array($ancestor)) {
                continue;
            }

            $id = $ancestor['id'] ?? null;
            $title = GqlHelper::nullIfEmptyString($ancestor['title'] ?? null);
            if (!is_numeric($id) || $title === null) {
                continue;
            }

            $ancestors[] = [
                'id' => (int)$id,
                'title' => $title,
            ];
        }

        return $ancestors;
    }
}
