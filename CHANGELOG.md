# Changelog

## [5.36.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.35.2...v5.36.0) (2026-01-28)


### Features

* **backends, indices:** Add collision detection for backend and index handles ([5b0c4ac](https://github.com/LindemannRock/craft-search-manager/commit/5b0c4ac3d5d22e107314c3543126a8a8dd3145a2))
* **search:** Enhance site ID handling and stop words functionality ([bb92504](https://github.com/LindemannRock/craft-search-manager/commit/bb92504a4330055fcc9ae0db94e745d672973ef6))


### Bug Fixes

* **backends:** add entriesAvailable flag to index responses ([e7def18](https://github.com/LindemannRock/craft-search-manager/commit/e7def18ad908fc9a72a3771c2ce1de48bc1d01f4))

## [5.35.2](https://github.com/LindemannRock/craft-search-manager/compare/v5.35.1...v5.35.2) (2026-01-26)


### Bug Fixes

* **models:** ensure type-safe handling of siteId in SearchIndex model ([1256089](https://github.com/LindemannRock/craft-search-manager/commit/12560891b988b432b8ce2a877d83d3499b6b2727))

## [5.35.1](https://github.com/LindemannRock/craft-search-manager/compare/v5.35.0...v5.35.1) (2026-01-26)


### Bug Fixes

* **backends:** enhance document preparation for multi-site support ([ef4287b](https://github.com/LindemannRock/craft-search-manager/commit/ef4287b34e43df5fa5f51740f94c72e86b74cd7c))

## [5.35.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.34.1...v5.35.0) (2026-01-26)


### Features

* **indices:** add sync count functionality for backend indices ([981b015](https://github.com/LindemannRock/craft-search-manager/commit/981b015d7f92543d30cd85603273bdd5df0b021a))

## [5.34.1](https://github.com/LindemannRock/craft-search-manager/compare/v5.34.0...v5.34.1) (2026-01-26)


### Miscellaneous Chores

* remove unused document ([7d9d063](https://github.com/LindemannRock/craft-search-manager/commit/7d9d063845c6ff61b94ee738a131f50b6832f89e))

## [5.34.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.33.0...v5.34.0) (2026-01-26)


### Features

* **jobs:** add analytics cleanup job and enhance sync status job scheduling ([4708d82](https://github.com/LindemannRock/craft-search-manager/commit/4708d828565a12cc46e9e4cb14744d646c365be9))


### Bug Fixes

* **cache:** correct popular query threshold check and remove legacy widget template ([4325d48](https://github.com/LindemannRock/craft-search-manager/commit/4325d48369a51122fae302d21532c86d0f19d24b))
* **security:** replace unserialize with JSON and strip API meta exposure ([da51780](https://github.com/LindemannRock/craft-search-manager/commit/da517805f37831ec43e313f4c6d9a89587be900d))

## [5.33.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.32.0...v5.33.0) (2026-01-21)


### Features

* Add configurable geo IP provider settings with HTTPS support ([706dc9e](https://github.com/LindemannRock/craft-search-manager/commit/706dc9e32bb19d46359c29fa863c88e589da63c9))
* add security guardrails for public search endpoints ([69913a9](https://github.com/LindemannRock/craft-search-manager/commit/69913a9d7397aa8b28ca285845a73127a9a590d5))
* disable CSRF validation for analytics tracking endpoints to simplify frontend integration ([b917eea](https://github.com/LindemannRock/craft-search-manager/commit/b917eea8909f197b8196cb2dc1c52e239e8467d2))
* switch from PHP serialize() to JSON for file storage to enhance security and prevent object injection risks ([ebe3184](https://github.com/LindemannRock/craft-search-manager/commit/ebe31846d3cecb1d3e8a33396ed17e3e18109967))


### Bug Fixes

* enhance index validation logic to ensure both enabled and analytics settings are checked ([a6791ce](https://github.com/LindemannRock/craft-search-manager/commit/a6791ce098ed0fbcee703e402b68cc54cd855ef4))
* **security:** address multiple security vulnerabilities ([67a3d49](https://github.com/LindemannRock/craft-search-manager/commit/67a3d495a9b4041e36374b9b5552da664cfd1a2a))
* swap cache and interface links in settings layout ([1f88238](https://github.com/LindemannRock/craft-search-manager/commit/1f8823897c3a9edd7ab2f631ecfc5de6d709e5c9))
* update Redis configuration info box to include important notes on database isolation and managed hosting platforms ([c647a02](https://github.com/LindemannRock/craft-search-manager/commit/c647a02d0c59871d126536454c41bfe11fba7f44))

## [5.32.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.31.0...v5.32.0) (2026-01-20)


### Features

* add debug enhancements, loading indicator config, and spinner customization ([3143156](https://github.com/LindemannRock/craft-search-manager/commit/3143156774d72d747982ab97fd68abb324ce0c7b))
* add debug option to performSearch function ([084a54d](https://github.com/LindemannRock/craft-search-manager/commit/084a54d7a4fc28ef4b5a38b1e57a8bf80fa46305))
* Add SearchModalWidget with modular architecture and debug toolbar ([853d607](https://github.com/LindemannRock/craft-search-manager/commit/853d607e0cd910ae2a16456b2218554855a78866))
* add skipEntriesWithoutUrl, hideResultsWithoutUrl, and external backend improvements ([1c88bab](https://github.com/LindemannRock/craft-search-manager/commit/1c88bab5af66d1122188d931776f6b7a96177820))

## [5.31.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.30.0...v5.31.0) (2026-01-19)


### Features

* Add maxRecentSearches configuration for recent searches functionality ([a36ab2f](https://github.com/LindemannRock/craft-search-manager/commit/a36ab2f082d16f7be55d8956c59044e1223a12dd))
* Refactor highlighting settings to group fields within a toggleable section ([9a872e6](https://github.com/LindemannRock/craft-search-manager/commit/9a872e60f886a8902952f1d2dd9b36cfd3640da4))

## [5.30.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.29.0...v5.30.0) (2026-01-19)


### Features

* **a11y:** Enhance accessibility features and tests for search widget ([43e0690](https://github.com/LindemannRock/craft-search-manager/commit/43e06907766fc7eedfc6b33546484f025469153a))
* add deduplication for search results and improve result rendering in SearchWidget ([1180b19](https://github.com/LindemannRock/craft-search-manager/commit/1180b19e12377d81ce58f2697741121cad62b472))
* add highlighting feature to SearchWidget with customizable options ([ba05ed9](https://github.com/LindemannRock/craft-search-manager/commit/ba05ed9f85f2a5a4dd15e553cc2c12f51e11bffb))
* add muted color styles for search widget ([2b7dc77](https://github.com/LindemannRock/craft-search-manager/commit/2b7dc778d3c484384cb00de49ae724b3c0932d05))
* Add option to skip analytics tracking for internal operations ([b424e63](https://github.com/LindemannRock/craft-search-manager/commit/b424e631743f3f1617e39ae94a7c5fd7a8449d34))
* add search widget and fix backend prefix/autocomplete bugs ([a67d52d](https://github.com/LindemannRock/craft-search-manager/commit/a67d52df47d51f05e7762832a8c464d718b1f2f5))
* Add tooltip display for raw widget configuration in the widget view ([0930f6a](https://github.com/LindemannRock/craft-search-manager/commit/0930f6ad0079a70af91710341023f5db2c0fd028))
* Add view action and template for index details in IndicesController ([619c715](https://github.com/LindemannRock/craft-search-manager/commit/619c71541afc9e82c02809f406f8e59cc46920d2))
* Add widget management interface and enhance search widget functionality ([070db8e](https://github.com/LindemannRock/craft-search-manager/commit/070db8ede5e209564e0361b8bd4d56097e798fd2))
* Add widget view functionality and improve backend/widget settings handling ([5ac2670](https://github.com/LindemannRock/craft-search-manager/commit/5ac26704393e8c2fbd65510a88c909c6e7182a99))
* Enhance permissions for backend and widget configurations with user capability checks ([e79d39d](https://github.com/LindemannRock/craft-search-manager/commit/e79d39d16bcafbb90bd152d56e7e884a4c180ff2))
* Enhance widget configuration management by preventing deletion of default configs and improving error handling ([bedbf58](https://github.com/LindemannRock/craft-search-manager/commit/bedbf58445301780c54a98998cef4807e059b7b3))
* Implement auto-assignment of default backend and widget after deletion ([3e705da](https://github.com/LindemannRock/craft-search-manager/commit/3e705da25318819aa308f1de61d34d550c4d68f5))
* Implement widget management settings and update backend configuration references ([f32fb70](https://github.com/LindemannRock/craft-search-manager/commit/f32fb7068142cde63476d58145bb58981dd96829))
* Remove isDefault property from WidgetConfig and related logic ([434277f](https://github.com/LindemannRock/craft-search-manager/commit/434277ff6d2fda2171da3dea70d3303fda776fc0))
* Update column headers and sorting functionality for source in backend and widget templates ([1fc9c63](https://github.com/LindemannRock/craft-search-manager/commit/1fc9c631a8476a067da9cc6016b5f5b1f94c51da))
* Update default backend and widget handle descriptions for auto-assignment behavior ([4f48fa1](https://github.com/LindemannRock/craft-search-manager/commit/4f48fa1e32463cc9c47623ea5e0802ea3c132207))


### Bug Fixes

* Autocomplete for Redis/File backends and redesign utilities overview ([b6d5e9d](https://github.com/LindemannRock/craft-search-manager/commit/b6d5e9d659a80cbbcf8c0695555e76dbe293eef9))
* Update widget styles retrieval to use preview method for appearance customization ([59f432e](https://github.com/LindemannRock/craft-search-manager/commit/59f432e6935fd0f7673321ea0f8ad2075969a0dc))


### Miscellaneous Chores

* Add optional widget configuration section to README ([b736491](https://github.com/LindemannRock/craft-search-manager/commit/b7364916cbf83da444e4968b8ae696c0b30c254e))

## [5.29.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.28.2...v5.29.0) (2026-01-16)


### Features

* Add analytics column to indices table based on global settings ([604e99e](https://github.com/LindemannRock/craft-search-manager/commit/604e99e070a3fc4931022e71d7471adef4640fe2))
* Add autocomplete caching functionality with clear cache options in UI ([28076ef](https://github.com/LindemannRock/craft-search-manager/commit/28076efaa79ef1e83112b7c27702155e4bd9d562))
* add backend diagnostics and search testing tabs to the settings interface ([a299f98](https://github.com/LindemannRock/craft-search-manager/commit/a299f98dffe1ebfc5bc788814c639f334a472eb2))
* add backend handle management and enhance backend index listing functionality ([93582f8](https://github.com/LindemannRock/craft-search-manager/commit/93582f817590088595fb362ce3f5a63941bb2c71))
* add BackendVariableProxy for backend-specific queries and enhance SearchManagerVariable with withBackend method ([21e0ec5](https://github.com/LindemannRock/craft-search-manager/commit/21e0ec51585a349629aa56b1c77428ef30628979))
* Add comprehensive cache settings for autocomplete and cache warming functionality ([16ab3d1](https://github.com/LindemannRock/craft-search-manager/commit/16ab3d10e4c2dc91b594764d2d7134d79b70efc1))
* add functionality to list indices with their respective file sizes ([8f40c7e](https://github.com/LindemannRock/craft-search-manager/commit/8f40c7e61e6193d951e044905a7fc547c9dc1918))
* Add index-level analytics toggle to disable tracking per index ([6b113f2](https://github.com/LindemannRock/craft-search-manager/commit/6b113f288129da0e432a61e2e6c3a9971fbc813e))
* Add popular query threshold settings with select options in cache settings ([e5fb6a5](https://github.com/LindemannRock/craft-search-manager/commit/e5fb6a50be46dd6f122d266afca29fa036577bc5))
* Add query normalization for improved cache key generation and search count accuracy ([45ef300](https://github.com/LindemannRock/craft-search-manager/commit/45ef300078b110c79e9fb24a043f2dd769bf6fda))
* Enhance cache clearing by adding autocomplete cache clearing in indexing operations ([10206a5](https://github.com/LindemannRock/craft-search-manager/commit/10206a52a16928f6883ad95547a37febd5b8ad2a))
* Enhance caching with autocomplete support and cache warming functionality ([effe9cb](https://github.com/LindemannRock/craft-search-manager/commit/effe9cbad65801f799b3d1432d09058f4d834cbc))
* expand element type options in index settings to include Assets, Categories, and SmartLinks ([5d5d837](https://github.com/LindemannRock/craft-search-manager/commit/5d5d8375dde38d2c92fa603089c42d3f0fe3ba68))
* Implement cache warming functionality after index rebuild with configurable settings ([4c811d9](https://github.com/LindemannRock/craft-search-manager/commit/4c811d9ea89ebe1d452bc969b5ad667712a3a1da))
* Implement getTermsForAutocomplete method in storage classes for enhanced autocomplete functionality ([e31aec2](https://github.com/LindemannRock/craft-search-manager/commit/e31aec251f8090b90cb4cf3ce592784f24d1114c))
* Queue geo-location lookups for improved search performance ([1d1a2ea](https://github.com/LindemannRock/craft-search-manager/commit/1d1a2ea080a9d1f5e0f09beadc757ec5da959dec))


### Bug Fixes

* format numerical values for better readability in analytics templates ([ecc7929](https://github.com/LindemannRock/craft-search-manager/commit/ecc792957e281f6f684393efa85f020289fc9d3a))
* Redis env var resolution, indices table layout, and multi-site status sync ([9922a0b](https://github.com/LindemannRock/craft-search-manager/commit/9922a0b485e8580308b07fd1f10d657411d7578e))
* update cache location message to use searchHelper for dynamic path ([e07e992](https://github.com/LindemannRock/craft-search-manager/commit/e07e99287c996833bb85876063530fd6d4301a63))
* update filename generation to use lower display name instead of plural lower display name ([93f11ca](https://github.com/LindemannRock/craft-search-manager/commit/93f11ca43d2edc0331e217d28fee385194b4d58e))
* update hardcoded cache paths with PluginHelper for consistency ([9e46d07](https://github.com/LindemannRock/craft-search-manager/commit/9e46d0759df873127d5c14152e7aac3fd279b0a7))
* update PluginHelper bootstrap to include download permissions for logging ([301612b](https://github.com/LindemannRock/craft-search-manager/commit/301612bb10ed8ce887d1d9fbcb7096a022c811eb))


### Miscellaneous Chores

* update autocomplete cache path for consistency in README ([bb6b301](https://github.com/LindemannRock/craft-search-manager/commit/bb6b30178ba02444d48636afb9e341e2362eed41))

## [5.28.2](https://github.com/LindemannRock/craft-search-manager/compare/v5.28.1...v5.28.2) (2026-01-13)


### Bug Fixes

* backend handling to use defaultBackendHandle and improve compatibility checks ([daee11e](https://github.com/LindemannRock/craft-search-manager/commit/daee11e9aab9517cb8b7a64ae9cdd96328107300))

## [5.28.1](https://github.com/LindemannRock/craft-search-manager/compare/v5.28.0...v5.28.1) (2026-01-13)


### Bug Fixes

* backend creation logic to use defaultBackendHandle and improve fallback handling ([d40af39](https://github.com/LindemannRock/craft-search-manager/commit/d40af399029ff1bd40c37b42e0294dd8f8a70d30))

## [5.28.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.27.0...v5.28.0) (2026-01-13)


### Features

* Add configuredBackends system with granular permissions and per-index backend support ([c5104db](https://github.com/LindemannRock/craft-search-manager/commit/c5104db074c47e013a6de701add89406c4d93211))
* add cross-backend methods for Algolia, Meilisearch, and Typesense, including browse, multiple queries, and filter parsing ([c47bf42](https://github.com/LindemannRock/craft-search-manager/commit/c47bf4205b5507a6c19c06df28e6eb447ed464bc))

## [5.27.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.26.1...v5.27.0) (2026-01-12)


### Features

* add analytics count to cache clearing buttons and format displayed numbers ([fd2791a](https://github.com/LindemannRock/craft-search-manager/commit/fd2791a74d5a4e00aae75b4f369f051e973de629))
* add analytics summary, content gaps, top searches, and trending searches widgets ([403ba22](https://github.com/LindemannRock/craft-search-manager/commit/403ba22de3eedddec12092a666be64aa207c42b7))

## [5.26.1](https://github.com/LindemannRock/craft-search-manager/compare/v5.26.0...v5.26.1) (2026-01-11)


### Bug Fixes

* plugin name retrieval to use getFullName method ([da20354](https://github.com/LindemannRock/craft-search-manager/commit/da2035446a9ab38005e55aad9476bfb06860747f))

## [5.26.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.25.0...v5.26.0) (2026-01-10)


### Features

* Replace custom country name retrieval with GeoHelper utility ([c6e49fc](https://github.com/LindemannRock/craft-search-manager/commit/c6e49fc2f88e4249f21fdda76a7e023e0ee5e8a1))

## [5.25.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.24.0...v5.25.0) (2026-01-09)


### Features

* **analytics:** add per-tab export buttons with consistent filenames ([e28d68b](https://github.com/LindemannRock/craft-search-manager/commit/e28d68bdc68213a6ec6b835a436cd9499fb77a0c))
* enhance action type badge styling for improved visibility and clarity ([88a7005](https://github.com/LindemannRock/craft-search-manager/commit/88a7005894905ec07c995952ffb32e8d4880ecdb))
* enhance multi-index search with redirect handling and metadata aggregation ([0e50b74](https://github.com/LindemannRock/craft-search-manager/commit/0e50b74e067e449ea566a974a36ba5cf9f4b7029))
* enhance redirect functionality to support element-based redirects and improve UI for redirect type selection ([41974c7](https://github.com/LindemannRock/craft-search-manager/commit/41974c7dbf571a4877671757ae6874b485ffe0f9))
* enhance redirect handling to display element info in query results ([5d9849b](https://github.com/LindemannRock/craft-search-manager/commit/5d9849be597c0abc46a3d6702bc216ecbc4fd886))
* refine zero-result analytics to exclude handled searches and improve accuracy ([aff45c5](https://github.com/LindemannRock/craft-search-manager/commit/aff45c5f7fd8ee05c4ae1e19529195d99ab589a3))
* update redirect URL resolution to support optional site ID for accurate element URLs ([9a6cbc9](https://github.com/LindemannRock/craft-search-manager/commit/9a6cbc90cc6f7abc5de25f1fe2c3d7e54467a3f7))


### Bug Fixes

* update filename generation to use 'alltime' instead of 'all' for clarity ([cc1982c](https://github.com/LindemannRock/craft-search-manager/commit/cc1982ce429dcf0aed770f45edce8498225121f8))

## [5.24.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.23.0...v5.24.0) (2026-01-08)


### Features

* refactor permissions to use grouped nested structure with granular access control ([3774d8c](https://github.com/LindemannRock/craft-search-manager/commit/3774d8c123a43f8bc38c74710807d66368d24115))

## [5.23.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.22.0...v5.23.0) (2026-01-06)


### Features

* migrate to shared base plugin ([125dfff](https://github.com/LindemannRock/craft-search-manager/commit/125dfff6b442221498f28085af0a3ef16b0943a8))

## [5.22.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.21.2...v5.22.0) (2026-01-05)


### Features

* add backend filtering to indices for improved data management ([d837e1f](https://github.com/LindemannRock/craft-search-manager/commit/d837e1fa385c48559af9b75781ac6e06d3f7145c))
* add sorting functionality to indices, promotions, and query rules templates ([178b801](https://github.com/LindemannRock/craft-search-manager/commit/178b80130c67ae6301347ff92d567ef37397383a))
* enhance color coding for backend, source, and match types in indices, promotions, and query rules templates ([2f939c0](https://github.com/LindemannRock/craft-search-manager/commit/2f939c08b34a1f67db0885fc8b8d1414d8068b29))


### Bug Fixes

* backend filter logic and prepare for per-index backend overrides ([e5f5faf](https://github.com/LindemannRock/craft-search-manager/commit/e5f5faf97fadee2a4c7aab373603c09d863594b0))

## [5.21.2](https://github.com/LindemannRock/craft-search-manager/compare/v5.21.1...v5.21.2) (2026-01-05)


### Bug Fixes

* add tab-content class to analytics sections for improved styling ([a6df207](https://github.com/LindemannRock/craft-search-manager/commit/a6df207e1860f9860838cc508fac9acf30729baa))

## [5.21.1](https://github.com/LindemannRock/craft-search-manager/compare/v5.21.0...v5.21.1) (2026-01-04)


### Bug Fixes

* auto-indexing for config indices and add real-time document count tracking ([de90fa8](https://github.com/LindemannRock/craft-search-manager/commit/de90fa8e47b780a37a466ff215923e0f75079111))

## [5.21.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.20.2...v5.21.0) (2026-01-04)


### Features

* enhance indexing service to support Closure-based criteria for config indices ([b078f49](https://github.com/LindemannRock/craft-search-manager/commit/b078f496e2e19a9b7f348de9585064b396d63ca0))

## [5.20.2](https://github.com/LindemannRock/craft-search-manager/compare/v5.20.1...v5.20.2) (2025-12-20)


### Bug Fixes

* format numerical values in dashboard for improved readability ([5759a85](https://github.com/LindemannRock/craft-search-manager/commit/5759a855108af7dc975cf78d84ce3a262444b681))

## [5.20.1](https://github.com/LindemannRock/craft-search-manager/compare/v5.20.0...v5.20.1) (2025-12-20)


### Bug Fixes

* query rules table to rename 'Match Type' to 'Query Pattern' and add a new 'Match' column with color indicators ([3bbaa96](https://github.com/LindemannRock/craft-search-manager/commit/3bbaa96954573aa26dd1748b5cabd43865367e28))

## [5.20.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.19.0...v5.20.0) (2025-12-20)


### Features

* enhance promotions and query rules to support multi-pattern matching with commas ([c841691](https://github.com/LindemannRock/craft-search-manager/commit/c8416913a2b042edfe1fc84a79ba25e3cb74c374))

## [5.19.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.18.0...v5.19.0) (2025-12-20)


### Features

* update promotions handling to support null indexHandle for global promotions and improve UI placeholders ([a866fd2](https://github.com/LindemannRock/craft-search-manager/commit/a866fd2459012de42ebd3a7316a045af64dbe2a6))

## [5.18.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.17.0...v5.18.0) (2025-12-20)


### Features

* add title attribute to promotions and update related templates for better identification ([e2e2a8a](https://github.com/LindemannRock/craft-search-manager/commit/e2e2a8a9a43b365be2fd6f0ec33de4f38073fe5b))

## [5.17.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.16.0...v5.17.0) (2025-12-20)


### Features

* enhance Arabic language support with spelling variations for boolean operators ([8d8fa83](https://github.com/LindemannRock/craft-search-manager/commit/8d8fa83a8241b8edf6a663a1e2251536cff79330))

## [5.16.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.15.0...v5.16.0) (2025-12-20)


### Features

* add  all-sites search support for CP test page ([1989182](https://github.com/LindemannRock/craft-search-manager/commit/19891822081d649347b425b8a9fe2ca2659b018a))


### Bug Fixes

* objectID/elementId mismatch in promotions, query rules, and synonym handling ([014a9cd](https://github.com/LindemannRock/craft-search-manager/commit/014a9cd6bb7635ee9887947798826d1f140c9c90))

## [5.15.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.14.1...v5.15.0) (2025-12-19)


### Features

* Enhance language support in search storage and autocomplete services ([ea78e60](https://github.com/LindemannRock/craft-search-manager/commit/ea78e6089488abccc7b7d3f79b366aff4695fbbf))

## [5.14.1](https://github.com/LindemannRock/craft-search-manager/compare/v5.14.0...v5.14.1) (2025-12-19)


### Bug Fixes

* move index retrieval logic to a separate section in test.twig ([48ae33a](https://github.com/LindemannRock/craft-search-manager/commit/48ae33ade32e0b06fde5cabd0296a74474bc71e7))

## [5.14.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.13.0...v5.14.0) (2025-12-19)


### Features

* Add 'pgsql' to searchBackend options in Settings model ([7191b1b](https://github.com/LindemannRock/craft-search-manager/commit/7191b1bab2091ecee3d7ea6cbec6b1aa5def3ba9))

## [5.13.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.12.0...v5.13.0) (2025-12-19)


### Features

* Enhance test settings template to include siteId mapping for indices ([ce02eaf](https://github.com/LindemannRock/craft-search-manager/commit/ce02eafaeb36630e4e353a07d8debb02f506e242))

## [5.12.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.11.0...v5.12.0) (2025-12-19)


### Features

* Enhance autocomplete functionality with siteId parameter and update test template ([f9d8a8d](https://github.com/LindemannRock/craft-search-manager/commit/f9d8a8d610265a57af32bb9a68df134632fba73f))

## [5.11.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.10.0...v5.11.0) (2025-12-19)


### Features

* Add promotion and query rule testing actions to settings controller and update test template ([d8c4b57](https://github.com/LindemannRock/craft-search-manager/commit/d8c4b57c4356ae33c43701d96bee5c4d671e3c07))

## [5.10.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.9.0...v5.10.0) (2025-12-19)


### Features

* Add Promotions and Query Rules systems with analytics improvements ([64a3c67](https://github.com/LindemannRock/craft-search-manager/commit/64a3c67b6f5edb411142136f26a1c304760b43fa))
* Enhance dashboard with promotions and query rules statistics, and add analytics overview ([eeeb904](https://github.com/LindemannRock/craft-search-manager/commit/eeeb904fac9cc5c9e483102882f140939f0136ab))
* Update cache settings instructions and add human-readable duration for cache inputs ([3d33c5e](https://github.com/LindemannRock/craft-search-manager/commit/3d33c5e192f8b88ca9e76e9896fcd15e4054b3b3))


### Bug Fixes

* **ui:** boost multiplier spacing ([e347acb](https://github.com/LindemannRock/craft-search-manager/commit/e347acb48e57c403cc9904c90fb7fa3cb309302e))

## [5.9.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.8.0...v5.9.0) (2025-12-18)


### Features

* add expected element count calculation and update indices template ([2e8dc85](https://github.com/LindemannRock/craft-search-manager/commit/2e8dc851d090108298675b635151e84ae722b913))
* Add localized boolean operators for 5 languages ([ac6fe32](https://github.com/LindemannRock/craft-search-manager/commit/ac6fe3205fefad91b99ba6d2dbbc4e17ca7941e9))
* Add source detection, performance tab, and analytics improvements ([efd0d49](https://github.com/LindemannRock/craft-search-manager/commit/efd0d491d19348d29bfb94cf9db43b3438929da0))

## [5.8.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.7.0...v5.8.0) (2025-12-18)


### Features

* add unified autocomplete endpoint with suggestions and results ([35e7ddb](https://github.com/LindemannRock/craft-search-manager/commit/35e7ddbecee80e373f189631a0e9782fac2d4741))

## [5.7.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.6.0...v5.7.0) (2025-12-18)


### Features

* add type filtering and enrichment to search API ([85f8fc3](https://github.com/LindemannRock/craft-search-manager/commit/85f8fc316c7d4d09e81248eb2dee7f7bc5229cd2))
* derive element type from section handle in AutoTransformer ([8a26963](https://github.com/LindemannRock/craft-search-manager/commit/8a269630b6269d3781475aba855fd4463dde64ac))

## [5.6.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.5.9...v5.6.0) (2025-12-18)


### Features

* add rich autocomplete with element type detection ([1be13b5](https://github.com/LindemannRock/craft-search-manager/commit/1be13b563c2468dfae6a163d64462d8f8af1c9ee))

## [5.5.9](https://github.com/LindemannRock/craft-search-manager/compare/v5.5.8...v5.5.9) (2025-12-17)


### Bug Fixes

* Enable plugin name setting in configuration ([a24c9fa](https://github.com/LindemannRock/craft-search-manager/commit/a24c9fae0086b076280ba1c4b4b47f9730a9baa6))

## [5.5.8](https://github.com/LindemannRock/craft-search-manager/compare/v5.5.7...v5.5.8) (2025-12-17)


### Bug Fixes

* Update similarity threshold to improve fuzzy matching accuracy ([e473f01](https://github.com/LindemannRock/craft-search-manager/commit/e473f014aceee2d916af506a26d5304a131952dc))

## [5.5.7](https://github.com/LindemannRock/craft-search-manager/compare/v5.5.6...v5.5.7) (2025-12-17)


### Bug Fixes

* Enhance search functionality by using index's configured siteId and adding wildcard support ([5c9851e](https://github.com/LindemannRock/craft-search-manager/commit/5c9851e6452da3176dbc3709090b345763d9e72b))

## [5.5.6](https://github.com/LindemannRock/craft-search-manager/compare/v5.5.5...v5.5.6) (2025-12-17)


### Bug Fixes

* Make fuzzy matching limit configurable and fix n-gram settings save bug ([872cf0a](https://github.com/LindemannRock/craft-search-manager/commit/872cf0ab85d83da2956b7814c2e66ee44a6842f5))

## [5.5.5](https://github.com/LindemannRock/craft-search-manager/compare/v5.5.4...v5.5.5) (2025-12-17)


### Bug Fixes

* Limit results in similarity query to improve performance ([dc3572a](https://github.com/LindemannRock/craft-search-manager/commit/dc3572a79bc9b6ee9298ab67b9bd953c061fa47e))

## [5.5.4](https://github.com/LindemannRock/craft-search-manager/compare/v5.5.3...v5.5.4) (2025-12-17)


### Bug Fixes

* Use REPLACE INTO for document storage to handle duplicates and improve data integrity ([ffe33c4](https://github.com/LindemannRock/craft-search-manager/commit/ffe33c4ee4f23fa211884b81427d7468fccc3d7a))

## [5.5.3](https://github.com/LindemannRock/craft-search-manager/compare/v5.5.2...v5.5.3) (2025-12-17)


### Bug Fixes

* Prevent duplicate key errors and add comprehensive cleanup across all storage backends ([7a4fe0a](https://github.com/LindemannRock/craft-search-manager/commit/7a4fe0a2f1b6b4670c9c5acf0a40e777c11096ee))

## [5.5.2](https://github.com/LindemannRock/craft-search-manager/compare/v5.5.1...v5.5.2) (2025-12-17)


### Bug Fixes

* Clear storage now handles orphaned indices and resets metadata ([7050d63](https://github.com/LindemannRock/craft-search-manager/commit/7050d63b29b51d5d93c99156268d3632e011c8ff))

## [5.5.1](https://github.com/LindemannRock/craft-search-manager/compare/v5.5.0...v5.5.1) (2025-12-17)


### Bug Fixes

* Implement proper wildcard search and fix fuzzy matching across all backends ([07ccbad](https://github.com/LindemannRock/craft-search-manager/commit/07ccbad063fc50f6fdea0c6574717ab5ca166e7a))

## [5.5.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.4.0...v5.5.0) (2025-12-17)


### Features

* Enhance AutoTransformer to automatically handle all field types ([b09a4e5](https://github.com/LindemannRock/craft-search-manager/commit/b09a4e5b3d4bd37c2afa14eb81ab6435bbb1cb0a))
* Update transformer class instructions and examples in edit template; remove unused index copy template ([e3fddcc](https://github.com/LindemannRock/craft-search-manager/commit/e3fddcc75891fb1a9d14b28d6973f760749d79d1))

## [5.4.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.3.0...v5.4.0) (2025-12-17)


### Features

* Fix config index metadata sync and optimize SearchIndex model ([94dce2d](https://github.com/LindemannRock/craft-search-manager/commit/94dce2d347188e25dfa466c6370d1d10cb965f65))

## [5.3.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.2.2...v5.3.0) (2025-12-16)


### Features

* enhance index deletion process by clearing backend storage before database record removal ([4ab91e9](https://github.com/LindemannRock/craft-search-manager/commit/4ab91e9212648930101e9812b6fac00ee2d58f8e))


### Bug Fixes

* improve transformer selection logic to handle empty string cases ([9d31c48](https://github.com/LindemannRock/craft-search-manager/commit/9d31c48f5d59469a9efb0e3b75e418ea06ba5177))

## [5.2.2](https://github.com/LindemannRock/craft-search-manager/compare/v5.2.1...v5.2.2) (2025-12-16)


### Bug Fixes

* critical backend bugs and add PostgreSQL support ([e1e81fa](https://github.com/LindemannRock/craft-search-manager/commit/e1e81fa5b4b061db9d6cfc2780898a20db4360dc))

## [5.2.1](https://github.com/LindemannRock/craft-search-manager/compare/v5.2.0...v5.2.1) (2025-12-16)


### Bug Fixes

* improve analytics display and error handling for Chart.js loading ([9210a33](https://github.com/LindemannRock/craft-search-manager/commit/9210a3330b37f2ea68a597178436c8724f56b39f))

## [5.2.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.1.0...v5.2.0) (2025-12-16)


### Features

* add cache storage method and duration settings for different environments ([5838bb7](https://github.com/LindemannRock/craft-search-manager/commit/5838bb7fe31d71d9288be31152069f2120e69b7a))
* add cache storage method option to settings table ([b80d75e](https://github.com/LindemannRock/craft-search-manager/commit/b80d75e48e83c1283f9160e769e9ab9d74844b21))
* implement cache storage method selection and handling for Redis and file systems ([62c29b4](https://github.com/LindemannRock/craft-search-manager/commit/62c29b4ff650b45da468163339f44102f94632c0))

## [5.1.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.0.0...v5.1.0) (2025-12-16)


### Features

* update backend support in search functionality to include PostgreSQL ([2e7a467](https://github.com/LindemannRock/craft-search-manager/commit/2e7a46773c2668ca39360928ef4c8833de1cc90a))

## 5.0.0 (2025-12-16)


### Features

* initial Search Manager plugin implementation ([6b63c10](https://github.com/LindemannRock/craft-search-manager/commit/6b63c109a644871af7c3db96af1fad11707cadd1))
