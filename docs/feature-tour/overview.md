# Features overview

Search Manager gets your visitors to what they're looking for — fast, relevant results ranked with BM25, typo-tolerant matching, highlighted snippets, and autocomplete — without you standing up a separate search service.

It's a full-featured search plugin for Craft CMS: index your content into a search backend of your choice, then serve results through Twig, GraphQL, REST, or the built-in frontend search widget. Use it alongside Craft's native search, or let it answer front-end template `.search()` queries directly while Control Panel search stays on Craft's native engine.

> [!TIP]
> New to Search Manager? Jump straight to the [Next steps](#next-steps) at the bottom for a guided setup path.

## What you'll use it for

- Replace or augment Craft's native front-end search with BM25 relevance ranking, typo tolerance, and highlighted results
- Add search-as-you-type autocomplete backed by the same index
- Give visitors a ready-made, accessible search modal (CMD+K) instead of building your own front end
- Track what people search for, spot zero-result content gaps, and see performance metrics — no separate analytics service needed
- Run the same templates across MySQL, PostgreSQL, Redis, File, Algolia, Meilisearch, or Typesense, and switch backends per environment

## Core capabilities

- **[Multiple Backends](../backends/backends.md)** — Choose from 7 search backends: MySQL, PostgreSQL, Redis, File (all built-in), plus Algolia, Meilisearch, and Typesense. Switch backends per environment without changing your templates.

- **[Search Indices](indices.md)** — Define which content gets indexed, filter by section or entry type, and configure per-site indices for multi-language setups.

- **[Advanced Search](search-features.md)** — BM25 relevance ranking, phrase search, boolean operators, field-specific search, wildcards, per-term boosting, and fuzzy matching with typo tolerance.

- **[Highlighting & Snippets](highlighting.md)** — Highlight matched terms in results and show contextual excerpts around matches.

- **[Autocomplete](autocomplete.md)** — Search-as-you-type suggestions based on indexed terms, with separate caching and fuzzy matching.

- **[Query Rules](query-rules.md)** — Modify search behavior based on query patterns: synonyms, section/category/element boosting, and redirects.

- **[Promotions](promotions.md)** — Pin specific elements to fixed positions in search results for merchandising and editorial control.

- **[Analytics](analytics.md)** — Track searches, zero-hit queries, device info, geographic data, and performance metrics. Identify content gaps and optimize your search experience.

- **[Caching](caching.md)** — Multi-layer caching for search results, autocomplete, and device detection. Cache warming after rebuilds. File or Redis storage.

- **[Multi-Language](multi-language.md)** — Boolean operators and per-language stop words in 12 languages (en, de, fr, es, nl, it, pt, sv, da, no, ja, ar). Japanese requires a space-separated query because the built-in tokeniser doesn't segment CJK.

- **[Frontend search widget](../widget/overview.md)** — A CMD+K style search modal for your site's visitors, built as a web component. WCAG 2.1 AA compliant, keyboard navigable, with light/dark themes and click analytics. Not to be confused with the CP dashboard widgets below.

- **[Widget styles](../widget/styles.md)** — Reusable appearance presets for the frontend search widget. Define colors, spacing, and dimensions once and share across multiple widget configs. Manageable via CP or config file.

- **[API Keys](api-keys.md)** — Generate, scope, and revoke keys for the public search, autocomplete, and analytics tracking endpoints. Per-key restrictions include allowed indices, referrer patterns, hit caps, expiry, and rate limits. Full keys are shown once at creation; server keys remain hash-only, while public keys can store encrypted widget material.

- **[Privacy & Security](privacy-security.md)** — IP hashing with salt, subnet masking, async geo-lookup, bot filtering, and GDPR-friendly defaults.

- **[Utilities](utilities.md)** — CP tools for rebuilding indices, clearing storage by type (orphan cleanup), managing caches, and resetting analytics data.

## CP dashboard widgets

Separate from the frontend search widget above, Search Manager also provides four Craft CP dashboard widgets for at-a-glance analytics right on your Dashboard. Add them via **Dashboard > New Widget**.

| Widget | Description |
|--------|-------------|
| **Analytics Summary** | Overview of search volume, zero-hit rate, and performance metrics |
| **Top Searches** | Most popular search queries over a configurable date range |
| **Trending Searches** | Queries with increasing search volume |
| **Content Gaps** | Zero-result queries that indicate missing content |

All four dashboard widgets require the `searchManager:viewAnalytics` permission and can be scoped to **All Sites** or one selected editable site.

## What makes Search Manager different

**It's not just a wrapper.** The built-in backends (MySQL, PostgreSQL, Redis, File) implement full BM25 ranking, boolean operators, fuzzy matching, and all the search features directly — no external service required. The external backends (Algolia, Meilisearch, Typesense) use a unified API so your templates work identically regardless of backend.

**Analytics are built in.** You don't need a separate analytics service. Search Manager tracks queries, zero-hit rates, device info, performance, and even which query rules and promotions fired.

**Everything is configurable.** From BM25 tuning parameters to highlight tags to cache warming depth — every aspect of the search experience can be adjusted through the CP or config file.

## Next steps

If you're new to Search Manager, start here:

1. [Install the plugin](../get-started/installation.md)
2. [Choose a backend](../backends/backends.md)
3. [Create your first index](indices.md)
4. [Search from your templates](../template-guides/basic-search.md)
