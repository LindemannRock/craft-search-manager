# Features Overview

Search Manager is a full-featured search solution for Craft CMS that replaces or augments Craft's built-in search with powerful relevance ranking, analytics, and a polished frontend experience.

> [!TIP]
> New to Search Manager? Jump straight to the [Next Steps](#next-steps) at the bottom for a guided setup path.

## What It Does

At its core, Search Manager indexes your Craft content into a search backend of your choice, then provides fast, relevant search results with features like BM25 ranking, typo tolerance, highlighting, and autocomplete.

You can use it alongside Craft's native search, or replace it entirely.

## Core Capabilities

- **[Multiple Backends](../backends/backends.md)** — Choose from 7 search backends: MySQL, PostgreSQL, Redis, File (all built-in), plus Algolia, Meilisearch, and Typesense. Switch backends per environment without changing your templates.

- **[Search Indices](indices.md)** — Define which content gets indexed, filter by section or entry type, and configure per-site indices for multi-language setups.

- **[Advanced Search](search-features.md)** — BM25 relevance ranking, phrase search, boolean operators, field-specific search, wildcards, per-term boosting, and fuzzy matching with typo tolerance.

- **[Highlighting & Snippets](highlighting.md)** — Highlight matched terms in results and show contextual excerpts around matches.

- **[Autocomplete](autocomplete.md)** — Search-as-you-type suggestions based on indexed terms, with separate caching and fuzzy matching.

- **[Query Rules](query-rules.md)** — Modify search behavior based on query patterns: synonyms, section/category/element boosting, result filtering, and redirects.

- **[Promotions](promotions.md)** — Pin specific elements to fixed positions in search results for merchandising and editorial control.

- **[Analytics](analytics.md)** — Track searches, zero-hit queries, device info, geographic data, and performance metrics. Identify content gaps and optimize your search experience.

- **[Caching](caching.md)** — Multi-layer caching for search results, autocomplete, and device detection. Cache warming after rebuilds. File or Redis storage.

- **[Multi-Language](multi-language.md)** — Five languages supported (English, Arabic, German, French, Spanish) with localized boolean operators and per-language stop words.

- **[Frontend Widget](../widget/overview.md)** — A CMD+K style search modal as a web component. Three widget types (modal, page, inline). WCAG 2.1 AA compliant, keyboard navigable, with light/dark themes and click analytics.

- **[Widget Styles](../widget/styles.md)** — Reusable appearance presets for the frontend widget. Define colors, spacing, and dimensions once and share across multiple widget configs. Manageable via CP or config file.

- **[Privacy & Security](privacy-security.md)** — IP hashing with salt, subnet masking, async geo-lookup, bot filtering, and GDPR-friendly defaults.

## Dashboard Widgets

Search Manager provides four Craft dashboard widgets for at-a-glance analytics. Add them via **Dashboard > New Widget**.

| Widget | Description |
|--------|-------------|
| **Analytics Summary** | Overview of search volume, zero-hit rate, and performance metrics |
| **Top Searches** | Most popular search queries over a configurable date range |
| **Trending Searches** | Queries with increasing search volume |
| **Content Gaps** | Zero-result queries that indicate missing content |

All widgets require the `searchManager:viewAnalytics` permission.

## What Makes Search Manager Different

**It's not just a wrapper.** The built-in backends (MySQL, PostgreSQL, Redis, File) implement full BM25 ranking, boolean operators, fuzzy matching, and all the search features directly — no external service required. The external backends (Algolia, Meilisearch, Typesense) use a unified API so your templates work identically regardless of backend.

**Analytics are built in.** You don't need a separate analytics service. Search Manager tracks queries, zero-hit rates, device info, performance, and even which query rules and promotions fired.

**Everything is configurable.** From BM25 tuning parameters to highlight tags to cache warming depth — every aspect of the search experience can be adjusted through the CP or config file.

## Next Steps

If you're new to Search Manager, start here:

1. [Install the plugin](../get-started/installation.md)
2. [Choose a backend](../backends/backends.md)
3. [Create your first index](indices.md)
4. [Search from your templates](../template-guides/basic-search.md)
