# Custom Transformers

Transformers convert Craft elements into searchable documents. When the index's transformer class is blank, Search Manager resolves a transformer from registered integration-specific transformers first, then falls back to `AutoTransformer`. Create a project-specific transformer class when an index needs a different document shape.

## Built-in Transformers

Search Manager includes these transformers out of the box:

| Transformer | Element Types | What It Indexes |
|------------|---------------|-----------------|
| `AutoTransformer` | Most element types | Default fallback. It indexes searchable attributes, custom fields marked searchable in Craft, relations, Matrix/Table fields, rich text, and headings. It handles entries generically itself. |
| `DocsManagerTransformer` | Docs Manager (`SourceDoc`) | Full page content, headings, description, and keywords. Auto-selected when [Docs Manager](https://lindemannrock.com/plugins/docs-manager) is installed. |
| `CommerceTransformer` | Craft Commerce Products and Variants | Product and variant metadata, product type name/handle, variant SKUs, variant titles, option labels/values, and parent product data for variants. Auto-selected when Craft Commerce is installed and the index targets Product or Variant elements. |

### Transformer Resolution Order

When indexing an element, Search Manager resolves the transformer in this order:

1. **Index-specific transformer** — if a `transformer` class is set on the index config
2. **Registered transformer** — matched by element type (e.g. `DocsManagerTransformer` for `SourceDoc`, `CommerceTransformer` for Commerce Product/Variant elements)
3. **AutoTransformer** — fallback that works with any element type

In most cases, you don't need to specify a transformer. Leave the field blank for the automatic path above. Set `transformer` / `transformerClass` manually only when you need a project-specific transformer class such as `modules\search\transformers\ProductTransformer`. Use your own project or module namespace; the example namespace is not required.

> [!NOTE]
> `CommerceTransformer` is a built-in integration transformer for Craft Commerce. Post-processing its `parent::transform()` output is technically possible, but it is not the recommended public extension base. For Commerce customization, prefer `BaseTransformer`, `AutoTransformer`, or transform events unless you intentionally accept coupling to Search Manager's built-in Commerce document shape.

## When You Need a Custom Transformer

You need a custom transformer when you want to:
- Index custom fields (body content, categories, tags, etc.)
- Add computed data (reading time, popularity score, etc.)
- Control which fields are searchable
- Format data differently for search

For entries, start with the automatic path. Add a project-specific transformer only when you need fields or metadata that the automatic document does not provide.

## Extension Contract

A configured transformer class must be:

- **Autoloadable** by Craft/PHP. Put the class in a project module, plugin, or Composer-autoloaded namespace.
- **Constructible without arguments.** Search Manager instantiates the configured class with `new $transformerClass()`, so required constructor dependencies are not supported.
- **A `TransformerInterface` implementation.** Extending `BaseTransformer` or `AutoTransformer` satisfies this automatically.

Choose one of these extension models:

| Model | Use When | Notes |
|-------|----------|-------|
| Extend `BaseTransformer` | You want full control over the indexed document | Recommended for most custom transformers. Includes common identity fields, a stable `type` default, optional hierarchy/path metadata helpers, HTML stripping, prose-only body helpers, excerpts, and heading-level support. |
| Extend `AutoTransformer` | You want automatic extraction plus a few project fields | Call `parent::transform($element)` and then add, remove, or normalize fields. This keeps Search Manager's automatic field, relation, rich text, and heading extraction. |
| Implement `TransformerInterface` directly | You need a minimal advanced transformer | Supported, but Base helpers, prose-only body handling, and heading-level behavior are not automatic unless you implement them yourself. |

The `supports(ElementInterface $element)` method is required by `TransformerInterface`, but it is not used as a safety gate for an index-specific configured transformer override. If an index points at your class, Search Manager uses that class for that index. Choose the class carefully and keep one transformer focused on the element type it is assigned to.

## Choosing an Extension Path

The extension path controls how much Search Manager does for you:

- **`BaseTransformer`** is for a full custom document shape. You decide which fields become searchable, so the indexed content can be much narrower than Search Manager's automatic documents. Starting with `$this->getCommonData($element)` gives you identity fields plus the stable `type` value.
- **`AutoTransformer`** is for automatic extraction plus project-specific additions. Start with `parent::transform($element)`, then add fields such as brand, availability, or catalog metadata.
- **`TransformerInterface` directly** is an advanced/minimal path. It passes validation when the class is autoloadable and zero-argument constructible, but it does not receive BaseTransformer helpers, stable document-kind defaults, prose-only body handling, or heading-level behavior unless you implement those pieces yourself.
- **`CommerceTransformer`** is built-in Commerce integration code. Product and Variant indices use it automatically when the transformer is blank. If you need a different Commerce document shape, prefer `BaseTransformer`, `AutoTransformer`, or `EVENT_AFTER_TRANSFORM`; extend `CommerceTransformer` only when you intentionally want to post-process its internal Commerce metadata output.

Split Sections follows the same transformer-family boundary. An index can split rich-text sections when its resolved transformer is `AutoTransformer` or a subclass, including project-level subclasses such as `modules\search\transformers\ProductTransformer`. The section slicer uses the automatic searchable rich-text field sources, keeps non-section prose on the intro record, and keeps fields from crossing into each other. Transformers that extend `BaseTransformer` directly keep full control over their document shape, but they do not opt into automatic rich-text section slicing.

## Creating a Transformer

Create a class that extends `BaseTransformer`:

```php
<?php

namespace modules\search\transformers;

use craft\base\ElementInterface;
use craft\elements\Entry;
use lindemannrock\searchmanager\transformers\BaseTransformer;

class ProductTransformer extends BaseTransformer
{
    protected function getElementType(): string
    {
        return Entry::class;
    }

    public function transform(ElementInterface $element): array
    {
        // Start with common data (identity, type, site, title, URL, dates)
        $data = $this->getCommonData($element);

        // Add custom fields
        $data['content'] = $this->stripHtml($element->body ?? '');
        $data['excerpt'] = $this->getExcerpt($element->body ?? '', 200);
        $section = $element->getSection();
        $data['entrySection'] = $section?->name;
        $data['entrySectionHandle'] = $section?->handle;
        $data['entrySectionType'] = $section?->type;

        return $data;
    }
}
```

## Registering a Transformer

Assign your transformer to an index in `config/search-manager.php`:

```php
'indices' => [
    'entries-en' => [
        'name' => 'Entries (English)',
        'elementType' => \craft\elements\Entry::class,
        'siteId' => 1,
        'transformer' => \modules\search\transformers\ProductTransformer::class,
    ],
],
```

The same class can be entered in the Control Panel's Transformer Class field. If the class is missing, does not implement `TransformerInterface`, or requires constructor arguments, the index will fail validation before it is saved.

### Extending AutoTransformer

Use `AutoTransformer` when the automatic document is mostly correct and you only need to add project-specific fields:

```php
<?php

namespace modules\search\transformers;

use craft\base\ElementInterface;
use lindemannrock\searchmanager\transformers\AutoTransformer;

class ProductTransformer extends AutoTransformer
{
    public function transform(ElementInterface $element): array
    {
        $data = parent::transform($element);

        $data['_fields']['brand'] = $element->brand->one()?->title ?? null;
        $data['_fields']['availability'] = $element->inStock ? 'in-stock' : 'out-of-stock';

        return $data;
    }
}
```

## Custom Fields in API and GraphQL

The array returned by `transform()` is the indexed document. Search Manager sends that document to the selected backend, so custom fields such as `price`, `brand`, `latitude`, `availability`, or `vehicleModel` can be searched, filtered, and sorted depending on backend configuration. Returning those values in public REST and GraphQL hits is controlled separately by the index's `retrievableFields` setting.

For automatic documents, `AutoTransformer` includes Craft custom fields only when the field's **Use this field's values as search keywords** setting is enabled. Rich-text and body-source fields are still mirrored into `_fields` when searchable, even though they also feed snippets, headings, and Split Sections. Fields with that setting disabled are excluded from the searchable content and from the internal `_fields` map.

For values that may be returned to API and GraphQL consumers as custom field data, write them to `_fields`:

```php
$data['_fields']['price'] = (string)($element->price ?? 0);
$data['_fields']['brand'] = $element->brand->one()?->title ?? null;
$data['_fields']['availability'] = $element->inStock ? 'in-stock' : 'out-of-stock';
```

Those values appear in `/actions/search-manager/api/search` as `hit.fields.price`, `hit.fields.brand`, and `hit.fields.availability` only when allowed by `retrievableFields`. GraphQL exposes the same data as a typed list:

```graphql
fields {
  handle
  value
  values
}
```

Keep Search Manager metadata at the top level. Fields such as `title`, `url`, `entrySection`, `source`, `productType`, and `score` have reserved response meanings, while `_fields` is the collision-safe namespace for user-defined field handles.

`retrievableFields` accepts `['*']` for all stored public field values, `['*', '-wysiwyg']` for all fields except `wysiwyg`, `[]` for none, or an explicit field-handle allowlist. Exclusions use the same `-attr` convention as Algolia's `attributesToRetrieve` and are valid only alongside `*`. The setting controls the public `fields` payload. Searchable field values can still affect matching and snippets through private search/snippet sources, so do not treat it as a secrecy boundary. Rebuild the index after changing retrievable fields so stored records and provider projections use the new allowlist.

Provider-specific setup still applies to custom transformer fields:

- **Algolia** — add custom filter fields to `attributesForFaceting`; configure ranking, replicas, and searchable attributes in Algolia.
- **Meilisearch** — configure custom fields as filterable or sortable attributes when you use them in filters or sorts.
- **Typesense** — include custom searchable fields in `query_by`, and define/filter/sort fields according to your Typesense schema needs.

## BaseTransformer Methods

### `getCommonData(ElementInterface $element)`

Returns a base array with standard element fields:

```php
[
    'objectID' => 123,
    'id' => 123,
    'elementId' => 123,
    'backendId' => '123_1',
    'type' => 'entry',
    'title' => 'My Entry',
    'url' => 'https://example.com/my-entry',
    'siteId' => 1,
    'dateCreated' => 1706112000,  // Unix timestamp
    'dateUpdated' => 1706112000,
]
```

Always start with `$this->getCommonData($element)` to ensure required fields and stable document-kind metadata are present.

### `stripHtml(?string $html)`

Strips HTML tags, decodes entities, and normalizes whitespace:

```php
$text = $this->stripHtml($element->body);
// "<p>Hello <strong>world</strong></p>" → "Hello world"
```

### `getExcerpt(string $content, int $length = 200)`

Strips HTML and truncates to a maximum length:

```php
$excerpt = $this->getExcerpt($element->body, 300);
// First 300 characters of clean text, with "..." if truncated
```

### `getElementType()`

Return the fully qualified class name of the element type this transformer handles:

```php
protected function getElementType(): string
{
    return Entry::class;
}
```

## Required Fields

Your `transform()` method **must** return an array containing:

| Field | Required | Source |
|-------|----------|--------|
| `id`, `elementId`, or `objectID` | Yes | Craft element identifier |
| `type` | Yes | Stable lowercase document kind |
| `siteId` | Recommended | Required for multi-site to prevent ID collisions |

Using `$this->getCommonData($element)` includes all required fields automatically when extending `BaseTransformer`. Direct `TransformerInterface` implementations must return a complete document themselves, including `type`.

If you build your own array instead:

```php
public function transform(ElementInterface $element): array
{
    return [
        'id' => $element->id,
        'siteId' => $element->siteId,
        'type' => 'entry',
        'title' => $element->title,
        // ... your custom fields
    ];
}
```

## Multi-Site Behavior

- **With `siteId`**: Documents use composite IDs (`123_1`) preventing collisions across sites
- **Without `siteId`**: Documents use simple IDs (`123`) — only safe for single-site setups

## Document Type Fields

`type` is the public document classification key. Search Manager filters and facets target `type`, and custom transformers may set it to a project-specific lowercase machine value such as `recipe`. Built-in documents use:

- `entry`
- `product`
- `variant`
- `asset`
- `category`
- `user`

Do not put an Entry section handle or Entry section type in `type`. Keep section-specific metadata in separate fields:

```php
$data['type'] = 'entry';
$data['entrySection'] = $element->getSection()?->name;
$data['entrySectionHandle'] = $element->getSection()?->handle;
$data['entrySectionType'] = $element->getSection()?->type;
```

Commerce product type metadata follows the same rule: `type` stays `product` or `variant`, while product type details use `productType` and `productTypeHandle`.

Non-entry Craft elements also keep their own metadata fields. Source-backed custom elements can use `source`; Assets use `volume`, `volumeHandle`, `filename`, `assetKind`, `extension`, and `size`, plus `width` and `height` when dimensions exist; Categories use `categoryGroup` and `categoryGroupHandle`; Users do not get fake `source` or Entry section metadata.

Hierarchy and path context is also top-level kind metadata. `AutoTransformer` writes it at index time for Structure Entries (`ancestors`, `level`), Categories (`ancestors`, `level`), and public Assets (`ancestors`, `folderPath`). Channel/Single Entries, Users, Commerce Products/Variants, source docs, and private-volume Assets omit these keys. `folderPath` is Craft's canonical folder path string, not a join of folder display titles.

If your transformer extends `AutoTransformer`, the built-in source/entry-section/category-group/volume and hierarchy/path metadata is included automatically. If your transformer extends `BaseTransformer` directly and wants the same hierarchy contract, merge `$this->getHierarchyMetadata($element)` into the indexed document at transform time. Do not resolve ancestors or folder chains later while presenting search results.

After changing `type` or related metadata in a transformer, rebuild the affected index so stored search documents match the current code. Adding or changing hierarchy/path metadata also requires a full reindex because the values are stored in each backend document.

If you intentionally index a custom document classification, set `type` to that lowercase machine value:

```php
$data['type'] = 'custom-type';
```

## Examples

### Product Transformer

```php
class ProductTransformer extends BaseTransformer
{
    protected function getElementType(): string
    {
        return Entry::class;
    }

    public function transform(ElementInterface $element): array
    {
        $data = $this->getCommonData($element);

        $data['content'] = $this->stripHtml($element->description ?? '');
        $data['excerpt'] = $this->getExcerpt($element->description ?? '', 150);
        $data['price'] = (float)($element->price ?? 0);
        $data['productCategory'] = $element->productCategory->one()?->title ?? '';
        $data['inStock'] = (bool)$element->inStock;
        $data['sku'] = $element->sku ?? '';

        // Add tags as a flat string for searching
        $tags = $element->tags->all();
        $data['tags'] = implode(' ', array_map(fn($t) => $t->title, $tags));

        return $data;
    }
}
```

### Asset Transformer

```php
use craft\elements\Asset;

class AssetTransformer extends BaseTransformer
{
    protected function getElementType(): string
    {
        return Asset::class;
    }

    public function transform(ElementInterface $element): array
    {
        $data = $this->getCommonData($element);

        $data['filename'] = $element->filename;
        $data['assetKind'] = $element->kind;
        $data['extension'] = $element->extension;
        $volume = $element->getVolume();
        $data['volume'] = $volume?->name;
        $data['volumeHandle'] = $volume?->handle;
        $data['alt'] = $element->alt ?? '';
        $data['content'] = $this->stripHtml($element->description ?? '');

        return $data;
    }
}
```

### Transformer with Relations

```php
class ArticleTransformer extends BaseTransformer
{
    protected function getElementType(): string
    {
        return Entry::class;
    }

    public function transform(ElementInterface $element): array
    {
        $data = $this->getCommonData($element);

        $data['content'] = $this->stripHtml($element->body ?? '');
        $data['excerpt'] = $this->getExcerpt($element->body ?? '', 200);
        $section = $element->getSection();
        $data['entrySection'] = $section?->name;
        $data['entrySectionHandle'] = $section?->handle;
        $data['entrySectionType'] = $section?->type;

        // Related categories
        $categories = $element->categories->all();
        $data['categories'] = array_map(fn($c) => $c->title, $categories);

        // Author info
        $author = $element->author;
        $data['author'] = $author?->fullName ?? '';

        // Custom computed field
        $wordCount = str_word_count(strip_tags($element->body ?? ''));
        $data['readingTime'] = max(1, (int)ceil($wordCount / 200));

        return $data;
    }
}
```

## Modifying Data via Events

You can also modify indexed data without a custom transformer by using the `EVENT_AFTER_TRANSFORM` event. See [Events](events.md) for details.
