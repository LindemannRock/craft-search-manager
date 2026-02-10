# Search Manager for Craft CMS

[![Latest Version](https://img.shields.io/packagist/v/lindemannrock/craft-search-manager.svg)](https://packagist.org/packages/lindemannrock/craft-search-manager)
[![Craft CMS](https://img.shields.io/badge/Craft%20CMS-5.0%2B-orange.svg)](https://craftcms.com/)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-blue.svg)](https://php.net/)
[![Logging Library](https://img.shields.io/badge/Logging%20Library-5.0%2B-green.svg)](https://github.com/LindemannRock/craft-logging-library)
[![License](https://img.shields.io/packagist/l/lindemannrock/craft-search-manager.svg)](LICENSE)

Advanced multi-backend search management for Craft CMS with BM25 ranking, analytics, caching, query rules, promotions, and a frontend search widget.

## License

This is a commercial plugin licensed under the [Craft License](https://craftcms.github.io/license/). It will be available on the [Craft Plugin Store](https://plugins.craftcms.com) soon. See [LICENSE.md](LICENSE.md) for details.

## ⚠️ Pre-Release

This plugin is in active development and not yet available on the Craft Plugin Store. Features and APIs may change before the initial public release.

## Features

- **7 Search Backends** — MySQL, PostgreSQL, Redis, File (built-in), plus Algolia, Meilisearch, Typesense
- **BM25 Ranking** — Industry-standard relevance scoring with configurable parameters
- **Search Operators** — Phrase search, NOT, wildcards, field-specific, per-term boosting, boolean operators
- **Fuzzy Matching** — Typo tolerance with n-gram similarity
- **Multi-Language** — 5 languages (EN, AR, DE, FR, ES) with localized boolean operators and stop words
- **Highlighting & Snippets** — Highlight matched terms and show contextual excerpts
- **Autocomplete** — Search-as-you-type suggestions with separate caching
- **Query Rules** — Synonyms, section/category/element boosting, filtering, redirects
- **Promotions** — Pin elements to fixed positions in search results
- **Analytics** — Track queries, devices, geo-location, performance, content gaps
- **Caching** — Multi-layer caching with cache warming after rebuilds
- **Frontend Widget** — CMD+K search modal (WCAG 2.1 AA, keyboard navigation, theming)
- **Native Search Replacement** — Optionally replace Craft's built-in search
- **REST API** — Search and autocomplete endpoints for headless/mobile apps
- **Privacy-First** — IP hashing, subnet masking, async geo-lookup, GDPR-friendly

## Requirements

- PHP 8.2+
- Craft CMS 5.0+
- LindemannRock Logging Library ^5.0 (installed automatically)

## Installation

```bash
composer require lindemannrock/craft-search-manager && php craft plugin/install search-manager
```

After installation, generate the IP hash salt for analytics:

```bash
php craft search-manager/security/generate-salt
```

## Documentation

Full documentation is available in the [docs](docs/) folder.

## Support

For bugs and feature requests, please use the GitHub issue tracker.
