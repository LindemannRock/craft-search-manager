# Custom Transformers

Transformers convert Craft elements into searchable documents. Search Manager automatically selects the best transformer based on element type. Custom transformers let you override this and control exactly what data gets indexed.

## Built-in Transformers

Search Manager includes these transformers out of the box:

| Transformer | Element Types | What It Indexes |
|------------|---------------|-----------------|
| `AutoTransformer` | Entries, Assets, Categories, Users | All custom fields, relational fields, Matrix blocks, Table fields. Default for most element types. |
| `EntryTransformer` | Entries | Everything from `AutoTransformer` plus entry-specific metadata: `section`, `sectionName`, `entryType`, `slug`, `postDate`, `authorName`. Use when you need to filter or boost by section/type. |
| `DocsManagerTransformer` | Docs Manager (`SourceDoc`) | Full page content, headings, description, and keywords. Auto-selected when [Docs Manager](https://lindemannrock.com/plugins/docs-manager) is installed. |

### Transformer Resolution Order

When indexing an element, Search Manager resolves the transformer in this order:

1. **Index-specific transformer** — if a `transformer` class is set on the index config
2. **Registered transformer** — matched by element type (e.g. `DocsManagerTransformer` for `SourceDoc`)
3. **AutoTransformer** — fallback that works with any element type

In most cases, you don't need to specify a transformer — the right one is selected automatically.

## When You Need a Custom Transformer

You need a custom transformer when you want to:
- Index custom fields (body content, categories, tags, etc.)
- Add computed data (reading time, popularity score, etc.)
- Control which fields are searchable
- Format data differently for search

## Creating a Transformer

Create a class that extends `BaseTransformer`:

```php
<?php

namespace modules\transformers;

use craft\base\ElementInterface;
use craft\elements\Entry;
use lindemannrock\searchmanager\transformers\BaseTransformer;

class EntryTransformer extends BaseTransformer
{
    protected function getElementType(): string
    {
        return Entry::class;
    }

    public function transform(ElementInterface $element): array
    {
        // Start with common data (id, objectID, siteId, title, url, dates)
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
        'transformer' => \modules\transformers\EntryTransformer::class,
    ],
],
```

## BaseTransformer Methods

### `getCommonData(ElementInterface $element)`

Returns a base array with standard element fields:

```php
[
    'objectID' => 123,
    'id' => 123,
    'title' => 'My Entry',
    'url' => 'https://example.com/my-entry',
    'siteId' => 1,
    'dateCreated' => 1706112000,  // Unix timestamp
    'dateUpdated' => 1706112000,
]
```

Always start with `$this->getCommonData($element)` to ensure required fields are present.

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
| `id` or `objectID` | Yes | Element identifier |
| `siteId` | Recommended | Required for multi-site to prevent ID collisions |

Using `$this->getCommonData($element)` includes all required fields automatically.

If you build your own array instead:

```php
public function transform(ElementInterface $element): array
{
    return [
        'id' => $element->id,
        'siteId' => $element->siteId,
        'title' => $element->title,
        // ... your custom fields
    ];
}
```

## Multi-Site Behavior

- **With `siteId`**: Documents use composite IDs (`123_1`) preventing collisions across sites
- **Without `siteId`**: Documents use simple IDs (`123`) — only safe for single-site setups

## Element Type Detection

The `type` field in API responses is auto-detected from the entry's section handle (singularized). You can override it:

```php
$data['elementType'] = 'custom-type';
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
