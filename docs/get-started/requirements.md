# Requirements

## System Requirements

| Requirement | Version |
|-------------|---------|
| Craft CMS | 5.0+ |
| PHP | 8.2+ |

## Search Backends

Depending on which search backend you choose, you may need additional infrastructure:

| Backend | Requirement |
|---------|-------------|
| MySQL | No additional requirements (uses Craft's database) |
| PostgreSQL | No additional requirements (uses Craft's database) |
| File | No additional requirements (stores data in `@storage/runtime/`) |
| Redis | PHP Redis extension (`ext-redis`) |
| Algolia | PHP cURL extension, Algolia account |
| Meilisearch | Running Meilisearch server |
| Typesense | Running Typesense server |

If you're not sure which backend to choose, start with **MySQL** or **File** — they work out of the box with zero additional setup. See [Backends](../backends/backends.md) for a full comparison.

## Dependencies

Composer pulls these packages automatically. Craft plugin dependencies also need to be installed in the Control Panel.

| Package | Version | Purpose |
|---------|---------|---------|
| [lindemannrock/craft-plugin-base](https://github.com/LindemannRock/craft-plugin-base) | ^5.0 | Shared base plugin utilities (helpers, traits, layouts) |
| [lindemannrock/craft-logging-library](https://github.com/LindemannRock/craft-logging-library) | ^5.0 | Optional — install in CP for log viewing |
| [algolia/algoliasearch-client-php](https://github.com/algolia/algoliasearch-client-php) | ^4.0 | Algolia backend integration |
| [meilisearch/meilisearch-php](https://github.com/meilisearch/meilisearch-php) | ^1.0 | Meilisearch backend integration |
| [typesense/typesense-php](https://github.com/typesense/typesense-php) | ^4.0 | Typesense backend integration |
