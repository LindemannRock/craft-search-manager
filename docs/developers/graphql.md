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
    index: "products"
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
      slug
      matchedIn
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
| `index` | `String` | Search one index handle. |
| `indices` | `[String]` | Search multiple index handles. Omit both `index` and `indices` to search all enabled indices. |
| `site` | `String` | Site handle filter. |
| `siteId` | `Int` | Site ID filter. `site` wins when both are provided. |
| `hitsPerPage` | `Int` | Defaults to `20`, capped at `200`. |
| `page` | `Int` | Zero-based page number. |
| `type` | `String` | Optional stable document-kind filter, for example `entry`, `product`, `variant`, `asset`, `category`, or `user`. |
| `filters` | `String` | Optional backend-specific filter expression. Requires a single `index`. |
| `language` | `String` | Optional language code for localized operators. |
| `source` | `String` | Analytics source. Defaults to `graphql`. |
| `platform` | `String` | Optional analytics platform label. |
| `appVersion` | `String` | Optional analytics app version label. |
| `skipAnalytics` | `Boolean` | Set to `true` to avoid recording a search analytics row. |
| `enrich` | `Boolean` | Set to `true` for title, URL, snippets, headings, and other enriched result data. |

Like the REST search endpoint, GraphQL search records analytics unless `skipAnalytics: true` is passed. This makes an executed search behave like a real frontend search while still letting typeahead or background callers opt out.

GraphQL exposes stable typed hit fields. Arbitrary indexed custom fields from custom transformers are not exposed directly because GraphQL requires a fixed schema; use enriched fields, `matchedIn`, `matchedTerms`, and normal Craft element queries when the frontend needs full custom field data. The REST API can return transformer-specific fields because JSON responses do not need a fixed GraphQL type.

Common hit fields:

| Field | Notes |
|-------|-------|
| `id` / `elementId` | Numeric Craft element ID. Use this for Craft element queries and URLs. |
| `backendId` | Search Manager's backend document ID, usually `{elementId}_{siteId}` for multi-site-safe documents. |
| `objectID` | Raw backend compatibility field. Algolia and Meilisearch use this as their primary key; Typesense keeps the primary key in its reserved `id` field. Prefer `elementId` and `backendId` in new code. |
| `siteId` / `site` | Site ID and site handle. |
| `elementType` | Stable lowercase document kind. Matches `type`. |
| `type` | Stable lowercase document kind used by Search Manager filters and widgets. Built-in values are `entry`, `product`, `variant`, `asset`, `category`, and `user`. |
| `section` | Human-readable Entry section name when indexed. |
| `sectionHandle` | Entry section handle when the hit is an Entry. |
| `sectionType` | Entry section type (`single`, `channel`, or `structure`) when the hit is an Entry. |
| `productTypeName` / `productTypeHandle` | Commerce product type metadata when returned by the indexed Product or Variant document. |
| `slug` | Indexed slug from `_slug`/`slug`. |
| `score` | Optional backend-specific relevance signal. Built-in backends use Search Manager BM25; Meilisearch and Typesense expose provider ranking values when available; Algolia may omit a comparable score; promoted results can be `null`. |
| `matchedIn` | Indexed fields that matched, such as `title` or `content`. |
| `matchedTerms` | Matched query terms grouped into `title` and `content` lists when the backend provides them. |
| `boosted` / `promoted` | Query-rule boost and promotion flags when present. |

Scores are useful for debug displays and single-backend ordering, but they are not portable across backend types. Do not compare an Algolia result's position or missing score directly against a Meilisearch, Typesense, or built-in backend score.

## Filters

Use `type` for portable document-kind filtering:

```graphql
query {
  searchManagerSearch(query: "test", index: "products", type: "entry") {
    total
    hits {
      title
      type
    }
  }
}
```

Use `filters` when you already have a backend-specific filter expression. Because filter syntax differs by backend, `filters` requires a single `index`.

```graphql
query {
  searchManagerSearch(
    query: "test"
    index: "products-typesense"
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

Custom transformer fields can still be searched or filtered by the selected backend when the backend is configured for them. GraphQL simply keeps the search response typed; use `elementId` and `siteId` for a follow-up Craft GraphQL element query when you need entry fields that are not part of the search hit type.

### Algolia

Use the Algolia-backed index handle. Algolia result order is the relevance signal; `score` may be `null` because Search Manager does not convert Algolia ranking metadata into a portable score.

```graphql
query {
  searchManagerSearch(
    query: "test"
    index: "test-algolia"
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
    index: "test-algolia"
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
    index: "meilisearch"
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
      description
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
    index: "meilisearch"
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
    index: "typesense"
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
    index: "typesense"
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
      description
      section
      type
      headings {
        title
        description
        url
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
| `hideResultsWithoutUrl` | `Boolean` | Exclude enriched results that do not have a URL. |

## Autocomplete

Use `searchManagerAutocomplete` for suggestions and lightweight result suggestions:

```graphql
query {
  searchManagerAutocomplete(
    query: "cof"
    index: "products"
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
| `index` | `String` | Query one index handle. |
| `indices` | `[String]` | Query multiple index handles. Omit both `index` and `indices` to query all enabled indices. |
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
