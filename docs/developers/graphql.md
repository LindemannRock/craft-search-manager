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
| `type` | `String` | Optional element type filter. |
| `filters` | `String` | Optional backend-specific filter expression. Requires a single `index`. |
| `language` | `String` | Optional language code for localized operators. |
| `source` | `String` | Analytics source. Defaults to `graphql`. |
| `platform` | `String` | Optional analytics platform label. |
| `appVersion` | `String` | Optional analytics app version label. |
| `skipAnalytics` | `Boolean` | Set to `true` to avoid recording a search analytics row. |
| `enrich` | `Boolean` | Set to `true` for title, URL, snippets, headings, and other enriched result data. |

Like the REST search endpoint, GraphQL search records analytics unless `skipAnalytics: true` is passed. This makes an executed search behave like a real frontend search while still letting typeahead or background callers opt out.

GraphQL exposes stable typed hit fields. Arbitrary indexed custom fields are not exposed directly because GraphQL requires a fixed schema; use enriched fields, `matchedIn`, `matchedTerms`, and normal Craft element queries when the frontend needs full custom field data.

Common hit fields:

| Field | Notes |
|-------|-------|
| `id` / `elementId` | Numeric Craft element ID. Use this for Craft element queries and URLs. |
| `backendId` | Search Manager's backend document ID, usually `{elementId}_{siteId}` for multi-site-safe documents. |
| `objectID` | Raw backend compatibility field. Algolia and Meilisearch use this as their primary key; Typesense keeps the primary key in its reserved `id` field. Prefer `elementId` and `backendId` in new code. |
| `siteId` / `site` | Site ID and site handle. |
| `elementType` | Indexed element type or section handle, depending on backend/index data. |
| `type` | Search result type used by Search Manager filters and widgets. |
| `section` | Human-readable section/type label when indexed. |
| `slug` | Indexed slug from `_slug`/`slug`. |
| `score` | Optional backend-specific relevance signal. Built-in backends use Search Manager BM25; Meilisearch and Typesense expose provider ranking values when available; Algolia may omit a comparable score; promoted results can be `null`. |
| `matchedIn` | Indexed fields that matched, such as `title` or `content`. |
| `matchedTerms` | Matched query terms grouped into `title` and `content` lists when the backend provides them. |
| `boosted` / `promoted` | Query-rule boost and promotion flags when present. |

Scores are useful for debug displays and single-backend ordering, but they are not portable across backend types. Do not compare an Algolia result's position or missing score directly against a Meilisearch, Typesense, or built-in backend score.

## Filters

Use `type` for portable element-type filtering:

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
    filters: "elementType:=`entry` && siteId:=`1`"
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
| Algolia | `elementType:"entry" AND siteId:1` |
| Meilisearch | `elementType = "entry" AND siteId = 1` |
| Typesense | `elementType:=\`entry\` && siteId:=\`1\`` |

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
