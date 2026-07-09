# Custom Transformers

Transformers convert Craft elements into searchable documents. When the index's transformer class is blank, Search Manager resolves a transformer from registered integration-specific transformers first, then falls back to `AutoTransformer`. Create a project-specific transformer class when an index needs a different document shape.

## Built-in Transformers

Search Manager includes these transformers out of the box:

| Transformer | Element Types | What It Indexes |
|------------|---------------|-----------------|
| `AutoTransformer` | Most element types | Default fallback. It indexes searchable attributes, custom fields, relations, Matrix/Table fields, rich text, and headings. It handles entries generically itself. |
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
| Extend `BaseTransformer` | You want full control over the indexed document | Recommended for most custom transformers. Includes common identity fields, stable `type` / `elementType` defaults, HTML stripping, excerpts, `_contentClean` finalization, and heading-level support. |
| Extend `AutoTransformer` | You want automatic extraction plus a few project fields | Call `parent::transform($element)` and then add, remove, or normalize fields. This keeps Search Manager's automatic field, relation, rich text, and heading extraction. |
| Implement `TransformerInterface` directly | You need a minimal advanced transformer | Supported, but Base helpers, `_contentClean` finalization, and heading-level behavior are not automatic unless you implement them yourself. |

The `supports(ElementInterface $element)` method is required by `TransformerInterface`, but it is not used as a safety gate for an index-specific configured transformer override. If an index points at your class, Search Manager uses that class for that index. Choose the class carefully and keep one transformer focused on the element type it is assigned to.

## Choosing an Extension Path

The extension path controls how much Search Manager does for you:

- **`BaseTransformer`** is for a full custom document shape. You decide which fields become searchable, so the indexed content can be much narrower than Search Manager's automatic documents. Starting with `$this->getCommonData($element)` gives you identity fields plus stable `type` and `elementType` values.
- **`AutoTransformer`** is for automatic extraction plus project-specific additions. Start with `parent::transform($element)`, then add fields such as brand, availability, or catalog metadata.
- **`TransformerInterface` directly** is an advanced/minimal path. It passes validation when the class is autoloadable and zero-argument constructible, but it does not receive BaseTransformer helpers, stable document-kind defaults, `_contentClean` finalization, or heading-level behavior unless you implement those pieces yourself.
- **`CommerceTransformer`** is built-in Commerce integration code. Product and Variant indices use it automatically when the transformer is blank. If you need a different Commerce document shape, prefer `BaseTransformer`, `AutoTransformer`, or `EVENT_AFTER_TRANSFORM`; extend `CommerceTransformer` only when you intentionally want to post-process its internal Commerce metadata output.

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
        // Start with common data (identity, type, elementType, site, title, URL, dates)
        $data = $this->getCommonData($element);

        // Add custom fields
        $data['content'] = $this->stripHtml($element->body ?? '');
        $data['excerpt'] = $this->getExcerpt($element->body ?? '', 200);
        $data['section'] = $element->section->handle;

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

        $data['brand'] = $element->brand->one()?->title ?? null;
        $data['availability'] = $element->inStock ? 'in-stock' : 'out-of-stock';

        return $data;
    }
}
```

## Custom Fields in API and GraphQL

The array returned by `transform()` is the indexed document. Search Manager sends that document to the selected backend, so custom fields such as `price`, `brand`, `latitude`, `availability`, or `vehicleModel` can be searched, filtered, sorted, and returned by the REST API depending on backend configuration.

For example, a transformer can add frontend-specific fields:

```php
$data['price'] = (float)($element->price ?? 0);
$data['brand'] = $element->brand->one()?->title ?? null;
$data['availability'] = $element->inStock ? 'in-stock' : 'out-of-stock';
```

Those fields are part of the indexed document and can appear in `/actions/search-manager/api/search` responses. This is useful when replacing older Scout, Algolia-only, or script-based indexing setups where custom code shaped records directly for a search provider.

GraphQL is different: `searchManagerSearch` exposes a stable typed result shape and does not dynamically expose arbitrary transformer fields. Use GraphQL search for identity and ranking fields such as `elementId`, `siteId`, `backendId`, `title`, `url`, `score`, and `matchedIn`; then query Craft's native GraphQL element fields with `elementId` and `siteId` when the frontend needs full entry data.

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
    'elementType' => 'entry',
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
| `type` and `elementType` | Yes | Stable lowercase document kind |
| `siteId` | Recommended | Required for multi-site to prevent ID collisions |

Using `$this->getCommonData($element)` includes all required fields automatically when extending `BaseTransformer`. Direct `TransformerInterface` implementations must return a complete document themselves, including `type` and `elementType`.

If you build your own array instead:

```php
public function transform(ElementInterface $element): array
{
    return [
        'id' => $element->id,
        'siteId' => $element->siteId,
        'type' => 'entry',
        'elementType' => 'entry',
        'title' => $element->title,
        // ... your custom fields
    ];
}
```

## Multi-Site Behavior

- **With `siteId`**: Documents use composite IDs (`123_1`) preventing collisions across sites
- **Without `siteId`**: Documents use simple IDs (`123`) — only safe for single-site setups

## Document Type Fields

Search Manager treats `type` and `elementType` as the same stable lowercase document kind. Built-in documents use:

- `entry`
- `product`
- `variant`
- `asset`
- `category`
- `user`

Do not put an Entry section handle or Entry section type in `type` or `elementType`. Keep section-specific metadata in separate fields:

```php
$data['type'] = 'entry';
$data['elementType'] = 'entry';
$data['section'] = $element->getSection()?->name;
$data['sectionHandle'] = $element->getSection()?->handle;
$data['sectionType'] = $element->getSection()?->type;
```

Commerce product type metadata follows the same rule: `type`/`elementType` stay `product` or `variant`, while product type details use `productTypeName` and `productTypeHandle`.

If your transformer extends `BaseTransformer` and starts with `$this->getCommonData($element)`, Search Manager sets these document-kind fields for Craft Entries, Categories, Assets, Users, and Commerce Products/Variants automatically.

After changing `type`, `elementType`, or related metadata in a transformer, rebuild the affected index so stored search documents match the current code.

If you intentionally index a custom element kind, set both fields to the same lowercase machine value:

```php
$data['elementType'] = 'custom-type';
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
        $data['category'] = $element->productCategory->one()?->title ?? '';
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
        $data['kind'] = $element->kind;
        $data['extension'] = $element->extension;
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
        $data['section'] = $element->section->handle;

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
