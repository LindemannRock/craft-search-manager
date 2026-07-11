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
      id
      objectID
      title
      url
      type
      score
      elementId
      siteId
      elementType
      section
      sectionHandle
      sectionType
      ancestors {
        id
        title
      }
      level
      folderPath
      volume
      volumeHandle
      group
      groupHandle
      productType
      productTypeHandle
      slug
      matchedIn
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
| `language` | `String` | Optional language code for localized operators. |
| `source` | `String` | Analytics source. Defaults to `graphql`. |
| `platform` | `String` | Optional analytics platform label. |
| `appVersion` | `String` | Optional analytics app version label. |
| `skipAnalytics` | `Boolean` | Set to `true` to avoid recording a search analytics row. |
| `enrich` | `Boolean` | Set to `true` for title, URL, snippets, headings, and other enriched result data. |

Like the REST search endpoint, GraphQL search records analytics unless `skipAnalytics: true` is passed. This makes an executed search behave like a real frontend search while still letting typeahead or background callers opt out.

GraphQL exposes custom field values through a typed key/value list because GraphQL cannot represent dynamic object keys. Each item in `fields` has the field `handle`, a flattened `value`, and `values` for list-valued indexed data. AutoTransformer includes Craft custom fields in this list only when the field's **Use this field's values as search keywords** setting is enabled.

GraphQL exposes breadcrumb context through `ancestors`, a list of `SearchManagerSearchAncestor` objects with `id` and `title`. The list is ordered from root to parent. Structure Entries and Categories can also expose `level`; public Assets can expose `folderPath`, Craft's canonical containing-folder path. Channel/Single Entries, Users, Commerce Products/Variants, source docs, and Assets without public URLs omit these fields until a full reindex writes source-backed values.

Common hit fields:

| Field | Notes |
|-------|-------|
| `id` / `elementId` | Numeric Craft element ID. Use this for Craft element queries and URLs. |
| `backendId` | Search Manager's backend document ID, usually `{elementId}_{siteId}` for multi-site-safe documents. |
| `objectID` | Raw backend compatibility field. Algolia and Meilisearch use this as their primary key; Typesense keeps the primary key in its reserved `id` field. Prefer `elementId` and `backendId` in new code. |
| `siteId` / `site` | Site ID and site handle. |
| `elementType` | Stable lowercase document kind. Matches `type`. |
| `type` | Stable lowercase document kind used by Search Manager filters and widgets. Built-in values are `entry`, `product`, `variant`, `asset`, `category`, and `user`. |
| `section` | Human-readable Entry section name when the hit is an Entry. Assets, Categories, Users, Products, and Variants do not use this field. |
| `sectionHandle` | Entry section handle when the hit is an Entry. |
| `sectionType` | Entry section type (`single`, `channel`, or `structure`) when the hit is an Entry. |
| `ancestors` | Breadcrumb ancestors as `SearchManagerSearchAncestor` objects, ordered root to parent. Present for nested Structure Entries, nested Categories, and public Asset folders when indexed. |
| `level` | Structure depth for Entry and Category hits when indexed. |
| `folderPath` | Craft's canonical containing-folder path for public Asset hits when indexed. It uses folder path segments rather than folder display titles. |
| `volume` / `volumeHandle` | Asset volume metadata when the hit is an Asset. |
| `group` / `groupHandle` | Category group metadata when the hit is a Category. |
| `productType` / `productTypeHandle` | Commerce product type metadata when returned by the indexed Product or Variant document. |
| `fields` | Searchable custom field values as `SearchManagerSearchFieldValue` objects with `handle`, `value`, and `values`. AutoTransformer includes Craft custom fields only when the field is marked searchable in Craft. |
| `slug` | Public indexed slug when the element or transformer provides one. |
| `score` | Optional backend-specific relevance signal. Built-in backends use Search Manager BM25; Meilisearch and Typesense expose provider ranking values when available; Algolia may omit a comparable score; promoted results can be `null`. |
| `matchedIn` | Indexed fields that matched, such as `title` or `content`. |
| `matchedTerms` | Matched query terms grouped into `title` and `content` lists when the backend provides them. |
| `snippet` | Match-centered plain-text excerpt from the best matching eligible custom field or indexed clean body; `null` when no eligible snippet source contains the query. |
| `headings` | List of `SearchManagerHeading` objects with `title`, `id`, `level`, `url`, and a query-centered plain-text `snippet` from that heading section when available. |
| `boosted` / `promoted` | Query-rule boost and promotion flags when present. |

Scores are useful for debug displays and single-backend ordering, but they are not portable across backend types. Do not compare an Algolia result's position or missing score directly against a Meilisearch, Typesense, or built-in backend score.

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
      id
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

Custom transformer fields can still be searched or filtered by the selected backend when the backend is configured for them. Values intended for API consumers should be written to the transformer's `_fields` map so REST returns them as a `fields` object and GraphQL returns them as `fields { handle value values }`.

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
      id
      elementId
      siteId
      backendId
      objectID
      title
      url
      score
      matchedIn
    }
  }
}
```

For Algolia filters, use Algolia filter syntax and make sure custom filter fields are configured in Algolia `attributesForFaceting`. Search Manager automatically configures the built-in filter fields it owns, such as `siteId`, `elementType`, and `type`.

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
    enrich: true
    skipAnalytics: true
  ) {
    total
    hits {
      id
      elementId
      siteId
      backendId
      objectID
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
      id
      elementId
      siteId
      backendId
      objectID
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

## Enriched Search

Set `enrich: true` when the frontend needs ready-to-render result data:

```graphql
query {
  searchManagerSearch(
    query: "installation"
    indices: ["docs", "articles"]
    site: "en"
    enrich: true
    snippetLength: 180
    hideResultsWithoutUrl: true
    skipAnalytics: true
  ) {
    total
    hits {
      id
      title
      url
      snippet
      section
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

Enrichment arguments:

| Argument | Type | Notes |
|----------|------|-------|
| `snippetMode` | `String` | `early`, `balanced`, or `deep`. Defaults to `balanced`. |
| `snippetLength` | `Int` | Defaults to `150`, clamped to `50`–`1000`. |
| `showCodeSnippets` | `Boolean` | Include code block content in snippets. |
| `parseMarkdownSnippets` | `Boolean` | Parse markdown before generating snippets. |
| `highlightTag` | `String` | Reserved for client renderers. Enriched snippets are returned as plain text. |
| `highlightClass` | `String` | Reserved for client renderers. Enriched snippets are returned as plain text. |
| `hideResultsWithoutUrl` | `Boolean` | Exclude enriched results that do not have a URL. |

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
