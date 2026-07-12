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
use lindemannrock\searchmanager\helpers\SearchHeadingValueHelper;
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
            'backendId' => ['name' => 'backendId', 'type' => Type::string(), 'description' => 'The unique backend-native hit ID. Split section hits share element id but keep distinct backend ids.'],
            'elementId' => ['name' => 'elementId', 'type' => Type::int(), 'description' => 'The element ID.'],
            'elementType' => ['name' => 'elementType', 'type' => Type::string(), 'description' => 'The stable lowercase document kind.'],
            'siteId' => ['name' => 'siteId', 'type' => Type::int(), 'description' => 'The site ID.'],
            'site' => ['name' => 'site', 'type' => Type::string(), 'description' => 'The site handle.'],
            'language' => ['name' => 'language', 'type' => Type::string(), 'description' => 'The indexed site language.'],
            'index' => ['name' => 'index', 'type' => Type::string(), 'description' => 'The source index handle.'],
            'title' => ['name' => 'title', 'type' => Type::string(), 'description' => 'The result title.'],
            'slug' => ['name' => 'slug', 'type' => Type::string(), 'description' => 'The result slug.'],
            'url' => ['name' => 'url', 'type' => Type::string(), 'description' => 'The result URL.'],
            'uri' => ['name' => 'uri', 'type' => Type::string(), 'description' => 'The result URI.'],
            'snippet' => ['name' => 'snippet', 'type' => Type::string(), 'description' => 'The query-centered match snippet, when there is a match to excerpt.'],
            'source' => ['name' => 'source', 'type' => Type::string(), 'description' => 'The source name for SourceDoc and custom source-backed hits.'],
            'entrySection' => ['name' => 'entrySection', 'type' => Type::string(), 'description' => 'The Entry section name, when the hit is an Entry.'],
            'entrySectionHandle' => ['name' => 'entrySectionHandle', 'type' => Type::string(), 'description' => 'The Entry section handle, when the hit is an Entry.'],
            'entrySectionType' => ['name' => 'entrySectionType', 'type' => Type::string(), 'description' => 'The Entry section type, when the hit is an Entry.'],
            'sectionType' => ['name' => 'sectionType', 'type' => Type::string(), 'description' => 'The section-hit type (heading, intro, or promoted-page) for split hits.'],
            'sectionId' => ['name' => 'sectionId', 'type' => Type::string(), 'description' => 'The section document ID within the parent element, when this is a split section hit.'],
            'sectionTitle' => ['name' => 'sectionTitle', 'type' => Type::string(), 'description' => 'The heading title for a split section hit.'],
            'sectionLevel' => ['name' => 'sectionLevel', 'type' => Type::int(), 'description' => 'The heading level for a split section hit.'],
            'sectionAnchor' => ['name' => 'sectionAnchor', 'type' => Type::string(), 'description' => 'The URL anchor for a split section hit.'],
            'sectionUrl' => ['name' => 'sectionUrl', 'type' => Type::string(), 'description' => 'The URL for the split section hit, including its anchor when available.'],
            'sectionIndex' => ['name' => 'sectionIndex', 'type' => Type::int(), 'description' => 'The zero-based section order within the parent element.'],
            'ancestors' => [
                'name' => 'ancestors',
                'type' => Type::listOf(Type::nonNull(SearchAncestorType::getType())),
                'description' => 'Breadcrumb ancestors ordered from root to parent or containing folder.',
            ],
            'level' => ['name' => 'level', 'type' => Type::int(), 'description' => 'The structure depth for Entry and Category hits.'],
            'folderPath' => ['name' => 'folderPath', 'type' => Type::string(), 'description' => 'The canonical Craft folder path for public Asset hits.'],
            'volume' => ['name' => 'volume', 'type' => Type::string(), 'description' => 'The Asset volume name, when the hit is an Asset.'],
            'volumeHandle' => ['name' => 'volumeHandle', 'type' => Type::string(), 'description' => 'The Asset volume handle, when the hit is an Asset.'],
            'filename' => ['name' => 'filename', 'type' => Type::string(), 'description' => 'The Asset filename, when the hit is an Asset.'],
            'assetKind' => ['name' => 'assetKind', 'type' => Type::string(), 'description' => 'The Craft Asset kind, when the hit is an Asset.'],
            'extension' => ['name' => 'extension', 'type' => Type::string(), 'description' => 'The Asset file extension, when the hit is an Asset.'],
            'size' => ['name' => 'size', 'type' => Type::int(), 'description' => 'The Asset file size in bytes, when the hit is an Asset.'],
            'width' => ['name' => 'width', 'type' => Type::int(), 'description' => 'The Asset width in pixels, when the Asset has dimensions.'],
            'height' => ['name' => 'height', 'type' => Type::int(), 'description' => 'The Asset height in pixels, when the Asset has dimensions.'],
            'categoryGroup' => ['name' => 'categoryGroup', 'type' => Type::string(), 'description' => 'The Category group name, when the hit is a Category.'],
            'categoryGroupHandle' => ['name' => 'categoryGroupHandle', 'type' => Type::string(), 'description' => 'The Category group handle, when the hit is a Category.'],
            'docCategory' => ['name' => 'docCategory', 'type' => Type::string(), 'description' => 'The Docs Manager navigation category, when the hit is a SourceDoc.'],
            'productType' => ['name' => 'productType', 'type' => Type::string(), 'description' => 'The Commerce product type display name, when the hit is a Product or Variant.'],
            'productTypeHandle' => ['name' => 'productTypeHandle', 'type' => Type::string(), 'description' => 'The Commerce product type handle, when the hit is a Product or Variant.'],
            'categoryIds' => [
                'name' => 'categoryIds',
                'type' => Type::listOf(Type::nonNull(Type::int())),
                'description' => 'Related category element IDs indexed with this hit.',
            ],
            'fields' => [
                'name' => 'fields',
                'type' => Type::nonNull(Type::listOf(Type::nonNull(SearchFieldValueType::getType()))),
                'description' => 'Indexed custom field values for this hit.',
            ],
            'type' => ['name' => 'type', 'type' => Type::string(), 'description' => 'The stable lowercase document kind.'],
            'score' => ['name' => 'score', 'type' => Type::float(), 'description' => 'The result score.'],
            'matchedIn' => [
                'name' => 'matchedIn',
                'type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
                'description' => 'Provider match-location metadata for indexed fields that matched the query.',
            ],
            'matchedTerms' => [
                'name' => 'matchedTerms',
                'type' => Type::nonNull(MatchedTermsType::getType()),
                'description' => 'Matched query terms grouped by field.',
            ],
            'matchedPhrases' => [
                'name' => 'matchedPhrases',
                'type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
                'description' => 'Exact phrases matched by phrase queries.',
            ],
            'promoted' => ['name' => 'promoted', 'type' => Type::boolean(), 'description' => 'Whether the hit was promoted.'],
            'boosted' => ['name' => 'boosted', 'type' => Type::boolean(), 'description' => 'Whether the hit was boosted.'],
            'position' => ['name' => 'position', 'type' => Type::int(), 'description' => 'The promoted position.'],
            'dateCreated' => ['name' => 'dateCreated', 'type' => Type::int(), 'description' => 'The indexed creation timestamp.'],
            'dateUpdated' => ['name' => 'dateUpdated', 'type' => Type::int(), 'description' => 'The indexed update timestamp.'],
            'headings' => [
                'name' => 'headings',
                'type' => Type::nonNull(Type::listOf(Type::nonNull(SearchHeadingType::getType()))),
                'description' => 'Indexed heading matches.',
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
                return GqlHelper::nullIfEmptyString(is_scalar($source['slug'] ?? null) ? (string)$source['slug'] : null);
            }

            if ($fieldName === 'elementId') {
                return SearchHitIdentityHelper::elementId($source);
            }

            if ($fieldName === 'matchedIn') {
                $matchedIn = $source['matchedIn'] ?? [];

                return is_array($matchedIn) ? array_values(array_map('strval', $matchedIn)) : [];
            }

            if ($fieldName === 'matchedTerms') {
                $matchedTerms = is_array($source['matchedTerms'] ?? null) ? $source['matchedTerms'] : [];

                return [
                    'title' => is_array($matchedTerms['title'] ?? null) ? $matchedTerms['title'] : [],
                    'content' => is_array($matchedTerms['content'] ?? null) ? $matchedTerms['content'] : [],
                ];
            }

            if ($fieldName === 'matchedPhrases') {
                $matchedPhrases = $source['matchedPhrases'] ?? [];

                return is_array($matchedPhrases) ? array_values(array_map('strval', $matchedPhrases)) : [];
            }

            if ($fieldName === 'categoryIds') {
                $categoryIds = $source['categoryIds'] ?? [];
                if (!is_array($categoryIds)) {
                    return [];
                }

                return array_values(array_map('intval', array_filter($categoryIds, 'is_numeric')));
            }

            if ($fieldName === 'fields') {
                if (is_array($source['fields'] ?? null)) {
                    $fields = $source['fields'];
                } elseif (is_object($source['fields'] ?? null)) {
                    $fields = get_object_vars($source['fields']);
                } else {
                    $fields = SearchFieldValueHelper::fieldsFromHit($source);
                }

                return SearchFieldValueHelper::toGraphqlList($fields);
            }

            if ($fieldName === 'headings') {
                $headings = is_array($source['headings'] ?? null)
                    ? $source['headings']
                    : SearchHeadingValueHelper::toPublicList(
                        is_array($source['_matchedHeadings'] ?? null)
                            ? $source['_matchedHeadings']
                            : (is_array($source['_headings'] ?? null) ? $source['_headings'] : []),
                        is_string($source['url'] ?? null) ? $source['url'] : null,
                    );

                return $headings;
            }

            if ($fieldName === 'ancestors') {
                $ancestors = self::ancestorsFromSource($source['ancestors'] ?? null);

                return $ancestors !== [] ? $ancestors : null;
            }

            if ($fieldName === 'level') {
                return isset($source['level']) && is_numeric($source['level']) ? (int)$source['level'] : null;
            }

            if ($fieldName === 'sectionLevel' || $fieldName === 'sectionIndex') {
                return isset($source[$fieldName]) && is_numeric($source[$fieldName]) ? (int)$source[$fieldName] : null;
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
