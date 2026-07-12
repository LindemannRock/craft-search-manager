# GraphQL

Search Manager exposes read-only GraphQL queries for headless sites, SPA frontends, and mobile apps that already use Craft's native GraphQL endpoint.

Use GraphQL when the frontend needs the same search and autocomplete behavior as the REST API, but you want the request to travel through a Craft GraphQL schema instead of a public action URL. No mutations are registered.

## Schema Permission

Enable **Query Search Manager data** on the GraphQL schema that will serve these requests. Site access is still controlled by Craft's normal schema site settings; if the schema cannot query a site, Craft will reject the request before Search Manager resolves it.

## Search

Use `searchManagerSearch` to run a backend search:

```graphql
query {
  searchManagerSearch(
    query: "coffee OR tea"
    indices: ["products"]
    site: "en"
    hitsPerPage: 10
    page: 0
    language: "en"
  ) {
    total
    page
    hitsPerPage
    totalPages
    hits {
      title
      url
      type
      score
      elementId
      backendId
      siteId
      site
      language
      source
      entrySection
      entrySectionHandle
      entrySectionType
      sectionType
      sectionId
      sectionTitle
      sectionLevel
      sectionAnchor
      sectionUrl
      sectionIndex
      ancestors {
        id
        title
      }
      level
      folderPath
      volume
      volumeHandle
      filename
      assetKind
      extension
      size
      width
      height
      categoryGroup
      categoryGroupHandle
      docCategory
      productType
      productTypeHandle
      categoryIds
      slug
      matchedIn
      matchedPhrases
      matchedTerms {
        title
        content
      }
      fields {
        handle
        value
        values
      }
      promoted
      boosted
      index
    }
  }
}
```

Search arguments:

| Argument | Type | Notes |
|----------|------|-------|
| `query` | `String!` | Search query. Advanced operators match the REST API. |
| `indices` | `[String]` | One or more index handles to search. Omit to search all enabled indices. |
| `site` | `String` | Site handle filter. |
| `siteId` | `Int` | Site ID filter. `site` wins when both are provided. |
| `hitsPerPage` | `Int` | Defaults to `20`, capped at `200`. |
| `page` | `Int` | Zero-based page number. |
| `type` | `String` | Optional stable document-kind filter, for example `entry`, `product`, `variant`, `asset`, `category`, or `user`. |
| `filters` | `String` | Optional backend-specific filter expression. Requires exactly one `indices` value. |
| `retrievableFields` | `[String]` | Optional custom field handles to return under `fields`. This can narrow each index's `retrievableFields` setting but cannot widen it. Pass `["*", "-wysiwyg"]` to return all fields except `wysiwyg`, or an empty list to return no custom fields. |
| `language` | `String` | Optional language code for localized operators. |
| `source` | `String` | Analytics source. Defaults to `graphql`. |
| `platform` | `String` | Optional analytics platform label. |
| `appVersion` | `String` | Optional analytics app version label. |
| `skipAnalytics` | `Boolean` | Set to `true` to avoid recording a search analytics row. |
| `snippetMode` | `String` | `early`, `balanced`, or `deep`. Defaults to `balanced`. |
| `snippetLength` | `Int` | Defaults to `150`, clamped to `50`–`1000`. |
| `showCodeSnippets` | `Boolean` | Include block-level code in snippets. Inline code text is always preserved. |
| `parseMarkdownSnippets` | `Boolean` | Clean Markdown markers from snippet display text without changing indexed content. |
| `highlightTag` | `String` | Reserved for client renderers. Indexed snippets are returned as plain text. |
| `highlightClass` | `String` | Reserved for client renderers. Indexed snippets are returned as plain text. |
| `hideResultsWithoutUrl` | `Boolean` | Exclude indexed results that do not have a URL. |

Like the REST search endpoint, GraphQL search records analytics unless `skipAnalytics: true` is passed. This makes an executed search behave like a real frontend search while still letting typeahead or background callers opt out.

GraphQL exposes retrievable custom field values through a typed key/value list because GraphQL cannot represent dynamic object keys. Each item in `fields` has the field `handle`, a flattened `value`, and `values` for list-valued indexed data. AutoTransformer adds Craft custom fields to the internal source map only when the field's **Use this field's values as search keywords** setting is enabled, including rich-text and body-source fields that also feed snippets, headings, and Split Sections; the index's `retrievableFields` setting decides which of those values are returned publicly. Exclusions use the same `-attr` convention as Algolia's `attributesToRetrieve`, so `["*", "-wysiwyg"]` returns all fields except `wysiwyg`.

`retrievableFields` is a payload and contract control, not a secrecy boundary. Searchable fields can still affect matching and snippets even when they are omitted from `fields`. Rebuild the index after changing retrievable fields so stored records and provider projections use the new allowlist.

GraphQL exposes breadcrumb context through `ancestors`, a list of `SearchManagerSearchAncestor` objects with `id` and `title`. The list is ordered from root to parent. Structure Entries and Categories can also expose `level`; public Assets can expose `folderPath`, Craft's canonical containing-folder path. Channel/Single Entries, Users, Commerce Products/Variants, source docs, and Assets without public URLs omit these fields.

GraphQL deliberately does not expose an `id` field on search hits. The old value was the Craft element ID, which is not unique across split hits because multiple records can share the same parent `elementId` while using different `backendId` values. Clients such as Apollo commonly auto-normalize cache objects by a field named `id`, so exposing a non-unique hit ID can corrupt cached results. Use `elementId`, `backendId`, and `siteId` together.

For split SourceDoc and AutoTransformer-family indices, GraphQL returns the same flat section hits as REST. Intro and heading section hits share `elementId` with the parent element, but each has a unique `backendId` and section metadata. `sectionType` is `intro`, `heading`, or `promoted-page`; `promoted-page` is used only for injected promotions on a split index. `total` counts section hits, `snippet` is generated only from the section's own indexed body, and `headings` is empty because the hit is already the section. Headingless elements in a split-enabled index remain normal page-mode hits.

Common hit fields:

| Field | Notes |
|-------|-------|
| `elementId` | Numeric Craft element ID. Use this for Craft element queries and URLs. Split section hits share this parent page identity. |
| `backendId` | Unique Search Manager backend document ID, usually `{elementId}_{siteId}` for page hits and `{elementId}_{siteId}_{sectionId}` for split section hits. Treat hits as unique by `backendId`. |
| `siteId` / `site` / `language` | Site ID, site handle, and site language from the indexed document. |
| `type` | Stable lowercase document kind used by Search Manager filters and widgets. Built-in values are `entry`, `product`, `variant`, `asset`, `category`, `user`, and `source-doc`. Split section hits keep the parent document kind, such as `entry` or `source-doc`. |
| Naming rule | Hit keys use Craft-native names; a kind prefix is used only where the bare word would be ambiguous within this contract (`entrySection*`, `assetKind`, `categoryGroup*`, `docCategory`). |
| `source` | Source name for SourceDoc and custom source-backed hits. |
| `entrySection` | Human-readable Entry section name when the hit is an Entry. |
| `entrySectionHandle` | Entry section handle when the hit is an Entry. |
| `entrySectionType` | Entry section type (`single`, `channel`, or `structure`) for normal Entry hits. |
| `sectionType` | Split hit type: `heading`, `intro`, or `promoted-page`. This field belongs only to split hits. |
| `sectionId` | Section identity within the parent element for split section hits. |
| `sectionTitle` | Parent page title for split `intro` / `promoted-page` hits, or heading title for split `heading` hits. |
| `sectionLevel` | Heading level for split `heading` hits; `null` for intro and promoted-page hits. |
| `sectionAnchor` | URL anchor for split heading hits; `null` for intro and promoted-page hits. |
| `sectionUrl` | Section URL, including the anchor when available. |
| `sectionIndex` | Zero-based section order within the parent element. |
| `ancestors` | Breadcrumb ancestors as `SearchManagerSearchAncestor` objects, ordered root to parent. Present for nested Structure Entries, nested Categories, and public Asset folders when indexed. |
| `level` | Structure depth for Entry and Category hits when indexed. |
| `folderPath` | Craft's canonical containing-folder path for public Asset hits when indexed. It uses folder path segments rather than folder display titles. |
| `volume` / `volumeHandle` | Asset volume metadata when the hit is an Asset. |
| `filename` | Asset filename when the hit is an Asset. |
| `assetKind` | Craft Asset kind when the hit is an Asset, for example `image`, `pdf`, `word`, `excel`, `video`, `audio`, `compressed`, or `unknown`. |
| `extension` | Asset file extension when the hit is an Asset. |
| `size` | Asset file size in bytes when the hit is an Asset. |
| `width` / `height` | Asset dimensions in pixels when the Asset has dimensions. Non-image/non-video Assets without dimensions resolve `null`. |
| `categoryGroup` / `categoryGroupHandle` | Category group metadata when the hit is a Category. |
| `docCategory` | Docs Manager navigation category when the hit is a SourceDoc. |
| `productType` / `productTypeHandle` | Commerce product type metadata when returned by the indexed Product or Variant document. |
| `categoryIds` | Related category element IDs indexed with the hit when available. |
| `fields` | Retrievable custom field values as `SearchManagerSearchFieldValue` objects with `handle`, `value`, and `values`. AutoTransformer adds Craft custom fields to the source map only when the field is marked searchable in Craft. |
| `slug` | Public indexed slug when the element or transformer provides one. Entries, Categories, Products, and SourceDoc hits resolve a non-empty slug; Asset hits resolve `null` because Craft Assets do not have element slugs. |
| `score` | Optional backend-specific relevance signal. Built-in backends use Search Manager BM25; Meilisearch and Typesense expose provider ranking values when available; Algolia may omit a comparable score; promoted results can be `null`. |
| `matchedIn` | Provider match-location metadata for indexed fields that matched the query. This can be populated even when `matchedTerms` is empty. |
| `matchedTerms` | Matched query terms grouped into stable `title` and `content` lists. Empty lists resolve as `[]`. |
| `matchedPhrases` | Exact phrases matched by phrase queries. Empty lists resolve as `[]`. |
| `snippet` | Match-centered plain-text excerpt from the best matching eligible custom field or indexed clean body; `null` when no eligible snippet source contains the query. |
| `headings` | Non-null list of `SearchManagerHeading` objects with `title`, `id`, `level`, `url`, and a query-centered plain-text `snippet` from that heading section when available. Split section hits return an empty list. |
| `boosted` / `promoted` | Query-rule boost and promotion flags when present. |

Scores are useful for debug displays and single-backend ordering, but they are not portable across backend types. Do not compare an Algolia result's position or missing score directly against a Meilisearch, Typesense, or built-in backend score.

Asset documents add `assetKind` and `extension` to searchable content at indexing time, so a query such as `pricing pdf` can match PDF assets. Filename is not added a second time because Craft assets normally use the filename as the asset title, and Search Manager already indexes titles.

## Filters

Use `type` for portable document-kind filtering:

```graphql
query {
  searchManagerSearch(query: "test", indices: ["products"], type: "entry") {
    total
    hits {
      title
      type
    }
  }
}
```

Use `filters` when you already have a backend-specific filter expression. Because filter syntax differs by backend, `filters` requires exactly one `indices` value.

```graphql
query {
  searchManagerSearch(
    query: "test"
    indices: ["products-typesense"]
    filters: "type:=`entry` && siteId:=`1`"
  ) {
    total
    hits {
      title
      matchedIn
      matchedTerms {
        title
        content
      }
    }
  }
}
```

Backend filter examples:

| Backend | `filters` example |
|---------|-------------------|
| Algolia | `type:"entry" AND siteId:1` |
| Meilisearch | `type = "entry" AND siteId = 1` |
| Typesense | `type:=\`entry\` && siteId:=\`1\`` |

## Backend Examples

The GraphQL response shape stays the same across backends. The index handle selects the backend, and backend-specific differences mainly show up in `score`, filter syntax, and raw provider behavior that GraphQL intentionally keeps behind typed fields.

Custom transformer fields can still be searched or filtered by the selected backend when the backend is configured for them. Values intended for API consumers should be written to the transformer's `_fields` map and allowed by the index's `retrievableFields` setting so REST returns them as a `fields` object and GraphQL returns them as `fields { handle value values }`.

### Algolia

Use the Algolia-backed index handle. Algolia result order is the relevance signal; `score` may be `null` because Search Manager does not convert Algolia ranking metadata into a portable score.

```graphql
query {
  searchManagerSearch(
    query: "test"
    indices: ["test-algolia"]
    hitsPerPage: 5
    skipAnalytics: true
  ) {
    total
    hits {
      elementId
      siteId
      backendId
      title
      url
      score
      matchedIn
    }
  }
}
```

For Algolia filters, use Algolia filter syntax and make sure custom filter fields are configured in Algolia `attributesForFaceting`. Search Manager automatically configures the built-in filter fields it owns, such as `siteId` and `type`.

```graphql
query {
  searchManagerSearch(
    query: "test"
    indices: ["test-algolia"]
    filters: "type:\"entry\" AND siteId:1"
    hitsPerPage: 5
    skipAnalytics: true
  ) {
    total
    hits {
      title
      type
      siteId
      url
    }
  }
}
```

### Meilisearch

Use the Meilisearch-backed index handle. When Meilisearch returns `_rankingScore`, Search Manager maps it to `score`; the value reflects Meilisearch ranking rules, not Search Manager BM25.

```graphql
query {
  searchManagerSearch(
    query: "test"
    indices: ["meilisearch"]
    hitsPerPage: 5
    skipAnalytics: true
  ) {
    total
    hits {
      elementId
      siteId
      backendId
      title
      snippet
      url
      score
      matchedIn
    }
  }
}
```

For Meilisearch filters, use Meilisearch filter syntax. Custom fields must be filterable in Meilisearch before they can be used in `filters`.

```graphql
query {
  searchManagerSearch(
    query: "test"
    indices: ["meilisearch"]
    filters: "type = \"entry\" AND siteId = 1"
    hitsPerPage: 5
    skipAnalytics: true
  ) {
    total
    hits {
      title
      type
      siteId
      score
    }
  }
}
```

### Typesense

Use the Typesense-backed index handle. When Typesense returns a text-match value, Search Manager maps it to `score`; the value reflects Typesense ranking settings, not Search Manager BM25.

```graphql
query {
  searchManagerSearch(
    query: "test"
    indices: ["typesense"]
    hitsPerPage: 5
    skipAnalytics: true
  ) {
    total
    hits {
      elementId
      siteId
      backendId
      title
      slug
      url
      score
      matchedIn
    }
  }
}
```

For Typesense filters, use Typesense filter syntax. Typesense also requires searchable fields to be included in `query_by`; Search Manager defaults to `title`, `content`, and `url`.

```graphql
query {
  searchManagerSearch(
    query: "test"
    indices: ["typesense"]
    filters: "type:=`entry` && siteId:=`1`"
    hitsPerPage: 5
    skipAnalytics: true
  ) {
    total
    hits {
      title
      type
      siteId
      score
    }
  }
}
```

## Indexed Snippets

GraphQL search returns the same index-backed hit shape as the REST API. Snippets, headings, and fields are derived from indexed document data; GraphQL does not hydrate Craft elements while shaping public results.

```graphql
query {
  searchManagerSearch(
    query: "installation"
    indices: ["docs", "articles"]
    site: "en"
    snippetLength: 180
    hideResultsWithoutUrl: true
    skipAnalytics: true
  ) {
    total
    hits {
      title
      url
      snippet
      source
      entrySection
      type
      headings {
        title
        id
        level
        url
        snippet
      }
    }
  }
}
```

Snippet arguments:

| Argument | Type | Notes |
|----------|------|-------|
| `snippetMode` | `String` | `early`, `balanced`, or `deep`. Defaults to `balanced`. |
| `snippetLength` | `Int` | Defaults to `150`, clamped to `50`–`1000`. |
| `showCodeSnippets` | `Boolean` | Include block-level code in snippets. Inline code text is always preserved. |
| `parseMarkdownSnippets` | `Boolean` | Clean Markdown markers from snippet display text without changing indexed content. |
| `highlightTag` | `String` | Reserved for client renderers. Indexed snippets are returned as plain text. |
| `highlightClass` | `String` | Reserved for client renderers. Indexed snippets are returned as plain text. |
| `hideResultsWithoutUrl` | `Boolean` | Exclude indexed results that do not have a URL. |

`snippet` and `headings.snippet` are plain text. Apply highlighting in the frontend. The top-level `snippet` is derived from eligible searchable custom field values, then from the indexed clean body.

## Autocomplete

Use `searchManagerAutocomplete` for suggestions and lightweight result suggestions:

```graphql
query {
  searchManagerAutocomplete(
    query: "cof"
    indices: ["products"]
    site: "en"
    hitsPerPage: 8
  ) {
    suggestions
    results {
      id
      siteId
      text
      type
      url
    }
  }
}
```

Autocomplete arguments:

| Argument | Type | Notes |
|----------|------|-------|
| `query` | `String!` | Partial search query. |
| `indices` | `[String]` | One or more index handles to query. Omit to query all enabled indices. |
| `site` | `String` | Site handle filter. |
| `siteId` | `Int` | Site ID filter. `site` wins when both are provided. |
| `hitsPerPage` | `Int` | Defaults to `10`, capped at `100`. |
| `only` | `String` | Use `suggestions` or `results` to limit the response. |
| `type` | `String` | Optional element type filter for result suggestions. |
| `language` | `String` | Optional language code. |

Autocomplete does not record search analytics.
When multiple indices return the same element suggestion, Search Manager keeps the first result per `siteId`, element `id`, and `type`.

## Multi-Index Counts

When a search spans multiple indices, request `indices` to see per-index totals:

```graphql
query {
  searchManagerSearch(query: "sale", indices: ["products", "articles"], site: "en") {
    total
    indices {
      index
      total
    }
  }
}
```

## No Match

If no results are found, Search Manager returns an empty hit list:

```json
{
  "data": {
    "searchManagerSearch": {
      "total": 0,
      "hits": []
    }
  }
}
```

Invalid or disabled explicit index handles also return an empty result set rather than falling back to all indices.
