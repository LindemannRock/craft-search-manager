# Requirements

## Server Requirements

| Requirement | Version |
|-------------|---------|
| PHP | 8.2+ |
| Craft CMS | 5.0+ |

## Dependencies

The following packages are installed automatically via Composer:

| Package | Purpose |
|---------|---------|
| `lindemannrock/craft-plugin-base` ^5.0 | Shared base plugin utilities (helpers, traits, layouts) |
| `lindemannrock/craft-logging-library` ^5.0 | Plugin logging and log viewer |
| `algolia/algoliasearch-client-php` ^4.0 | Algolia backend integration |
| `meilisearch/meilisearch-php` ^1.0 | Meilisearch backend integration |
| `typesense/typesense-php` ^4.0 | Typesense backend integration |

## Optional Backend Requirements

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

If you're not sure which backend to choose, start with **MySQL** or **File** — they work out of the box with zero additional setup. See [Backends](../feature-tour/backends.md) for a full comparison.
