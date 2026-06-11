# Changelog

## [5.48.2](https://github.com/LindemannRock/craft-search-manager/compare/v5.48.1...v5.48.2) (2026-06-11)


### Fixed

* **i18n:** align filter casing and terminology ([91776a0](https://github.com/LindemannRock/craft-search-manager/commit/91776a03b248ba21f2736c4f01a437294e39433b))
* **search:** keep metadata unchanged for missing-document deletes ([0e1ee1e](https://github.com/LindemannRock/craft-search-manager/commit/0e1ee1e7bd842ca188ff53e964b302688bb26eed))
* **widget:** ignore stale search responses without aborting requests ([dded753](https://github.com/LindemannRock/craft-search-manager/commit/dded7532a66286f12d935ed6475ffa4dd753799e))
* **widget:** improve search request handling and promoted badge styling ([5ee3dc7](https://github.com/LindemannRock/craft-search-manager/commit/5ee3dc726e7ea297bf41326a6028ac6a3a2c295a))

## [5.48.1](https://github.com/LindemannRock/craft-search-manager/compare/v5.48.0...v5.48.1) - 2026-06-09


### Fixed

* ensure metadata increment does not drop below minimum value ([f2518a7](https://github.com/LindemannRock/craft-search-manager/commit/f2518a7745367bd0fe4abb592d02df5ed6e069ab))
* **i18n:** correct API key phrasing in German, French, and Italian translations ([2205bd7](https://github.com/LindemannRock/craft-search-manager/commit/2205bd75ca15d3ecdd5de430256ed17a577ee995))
* **i18n:** correct phrasing in cache clearing confirmation messages ([56af083](https://github.com/LindemannRock/craft-search-manager/commit/56af083c193c4f05999de10471a4a6940822174e))
* **search:** update document metadata on deletion if terms exist ([5817e13](https://github.com/LindemannRock/craft-search-manager/commit/5817e13c46cb610c96e64bcc26b5bb8e0550a02c))


### Changed

* **search:** batch BM25 title-term lookups to fix per-document N+1 ([b42e3d4](https://github.com/LindemannRock/craft-search-manager/commit/b42e3d40480b19ea71aeedd474da53c90b3cff78))
* **search:** batch element hydration in enrichResults ([5911c7e](https://github.com/LindemannRock/craft-search-manager/commit/5911c7e62a28abf2108373faf2261fe2f86845c9))
* **search:** batch fuzzy-fallback candidate doc lookups and reuse in scoring ([436aab5](https://github.com/LindemannRock/craft-search-manager/commit/436aab540487f83d4b297d1148b48c07af25d5fb))

## [5.48.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.47.0...v5.48.0) - 2026-06-07


### Added

* add Postman collection download to settings test page ([7bd83ef](https://github.com/LindemannRock/craft-search-manager/commit/7bd83efcca5021940f96e0a808b4d36d2a3ef413))


### Fixed

* plugin credit in edit templates ([5820234](https://github.com/LindemannRock/craft-search-manager/commit/5820234a352afa88d7fe943e16735bbac49b385f))

## [5.47.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.46.0...v5.47.0) - 2026-06-07


### Added

* add act-static-analysis script for CI integration ([93e632e](https://github.com/LindemannRock/craft-search-manager/commit/93e632ed3726916eb7629d5f220525721935bb80))
* add backend management permissions and navigation ([01b300f](https://github.com/LindemannRock/craft-search-manager/commit/01b300fc088187103b750cfa638e04ac0ec66bc0))
* add dateCreated and dateUpdated properties to SearchIndex model ([f0061a1](https://github.com/LindemannRock/craft-search-manager/commit/f0061a121f5ca24914b7278fd520479c81538b48))
* add handle option for scoped rebuild and clear operations ([bb9c403](https://github.com/LindemannRock/craft-search-manager/commit/bb9c403a442730f206c1c84732e73f60fe55d219))
* add ID and source display for index details ([10b6232](https://github.com/LindemannRock/craft-search-manager/commit/10b623237db708a145befd712a92ce868b3957a0))
* add ID display for index details and enhance source representation ([1a858df](https://github.com/LindemannRock/craft-search-manager/commit/1a858df8c9a6b94eae5e9147e0a6fb91c4a63624))
* add ID display for promotions in edit view ([8af6768](https://github.com/LindemannRock/craft-search-manager/commit/8af676892ab0235465d3fd0b54a55dc07f9ea5e9))
* add ID display for query rules in edit view ([786e42e](https://github.com/LindemannRock/craft-search-manager/commit/786e42e241173cb198794341a1cc84ea47b3ee1f))
* add method to return file backend storage path display ([2c4039f](https://github.com/LindemannRock/craft-search-manager/commit/2c4039f84884018ab0e98c16a2bceff8ccf5fdda))
* add navigation link for managing settings ([444239a](https://github.com/LindemannRock/craft-search-manager/commit/444239a42fe85cff9bc3ff969c0328b751620ca3))
* add plugin credit component to edit templates ([0a1ed1c](https://github.com/LindemannRock/craft-search-manager/commit/0a1ed1c38e4010638e30748d0b110d0605b9c7eb))
* add plugin handle to device detection configuration ([92db65c](https://github.com/LindemannRock/craft-search-manager/commit/92db65cf27442e6d7240ab329442c2effa7478a5))
* add save and continue editing functionality in API keys form ([db4009b](https://github.com/LindemannRock/craft-search-manager/commit/db4009becbbe39f17ac5e37d53f89e1f0d6a8ce7))
* add storage path display for file backend settings ([1a39a33](https://github.com/LindemannRock/craft-search-manager/commit/1a39a33c43044a0308a65c81f425c90d174635b6))
* **analytics:** add API key attribution breakdown for analytics data ([a241a63](https://github.com/LindemannRock/craft-search-manager/commit/a241a63b4e207b1603297493213b5a2cf47491b2))
* **analytics:** format local analytics datetime for API/export payloads ([051f4cd](https://github.com/LindemannRock/craft-search-manager/commit/051f4cd272bec887043ac627f6c1c075f4f74895))
* **analytics:** sync visible tab based on active tab selection ([6c7fee6](https://github.com/LindemannRock/craft-search-manager/commit/6c7fee6dd2bd3b175d81bc17760b069a284bf0f2))
* **api:** enforce API key validation and referrer restrictions ([d7b6f99](https://github.com/LindemannRock/craft-search-manager/commit/d7b6f99aa9bca3c670ba21627c88e23523cb2b68))
* **api:** enforce per-key request rate limit for API keys ([18e0aa1](https://github.com/LindemannRock/craft-search-manager/commit/18e0aa16b4f4bea81392d42b295f8fec62312d86))
* **api:** require API key for public search, autocomplete, and tracking endpoints ([7793ac1](https://github.com/LindemannRock/craft-search-manager/commit/7793ac1041ede4f189de7e98dadcf017b9548059))
* **cli:** add HelpController for cli command assistance ([6798c87](https://github.com/LindemannRock/craft-search-manager/commit/6798c8796a30aa5f40c69edad85d8694edf9a40f))
* **edit:** add ID display for backend and improve source representation ([bd9b0bf](https://github.com/LindemannRock/craft-search-manager/commit/bd9b0bf26d82460471d60f566ca45afd8ec7838e))
* expand default date range options for analytics ([205d024](https://github.com/LindemannRock/craft-search-manager/commit/205d024a7c9aa9e459996b17b61e4545847bf6d1))
* **helpers:** add FileBackendStoragePathHelper for storage path management ([715c3f9](https://github.com/LindemannRock/craft-search-manager/commit/715c3f901edc3755d58e9ee6c58d0d8de74eb095))
* **i18n:** add API access and key requirement translations ([930545f](https://github.com/LindemannRock/craft-search-manager/commit/930545f8f69d8395b1f92a710091c8b5ae58f515))
* **i18n:** add new translation keys for user notifications ([cdb8cfe](https://github.com/LindemannRock/craft-search-manager/commit/cdb8cfe153bbfa7f01100c765b101a6d37f0764a))
* **i18n:** add new translation keys for user settings ([7adbe06](https://github.com/LindemannRock/craft-search-manager/commit/7adbe06195c3e5f4345eccd8562ee2551a350cd1))
* **i18n:** add new translation keys for various actions across locales ([43984a6](https://github.com/LindemannRock/craft-search-manager/commit/43984a6fcace75863781d89983208e65a7f09ede))
* **i18n:** add new translations strings ([2aeead2](https://github.com/LindemannRock/craft-search-manager/commit/2aeead27a54308a865e0d28ad717efc2ef1cfc26))
* **i18n:** add new validation messages for storage paths ([f37c2ee](https://github.com/LindemannRock/craft-search-manager/commit/f37c2eea044b8af1857d90769ead4df819996df3))
* **i18n:** add required field messages for Redis cache configuration ([93015d5](https://github.com/LindemannRock/craft-search-manager/commit/93015d5ee7e9fe0e041ebe17dbfb5c85cffc060e))
* **i18n:** add translation for phone synonyms placeholder ([25b0eaa](https://github.com/LindemannRock/craft-search-manager/commit/25b0eaad1dfe1c1b4284bb4a12b1be7bb57752ee))
* **i18n:** add translations for quick operator test buttons and table headers ([da3b8ae](https://github.com/LindemannRock/craft-search-manager/commit/da3b8ae0f64905f59583c3db20d286a4e3a33797))
* **i18n:** add unique handle validation message in multiple languages ([4a1c88c](https://github.com/LindemannRock/craft-search-manager/commit/4a1c88c477d1432f1eada37d4a79981f01be7df0))
* **i18n:** add user-facing error messages for not found resources ([854a456](https://github.com/LindemannRock/craft-search-manager/commit/854a4567c074e5ea0bfb1201a31d380603e164a3))
* **i18n:** rename 'Max hits per page' to 'Max hits' and 'Rate limit (requests per minute)' to 'Rate limit' across multiple languages ([fec0d53](https://github.com/LindemannRock/craft-search-manager/commit/fec0d53ea2376420d0fbb65d955df624938713b4))
* **jobs:** refresh index counts after batch sync drain completion ([1100d82](https://github.com/LindemannRock/craft-search-manager/commit/1100d82d8a92d8294d0a08e28fbc342dd01980f4))
* **jobs:** schedule initial run time for sync and cleanup jobs ([38ee150](https://github.com/LindemannRock/craft-search-manager/commit/38ee15015ee9631121f710ede8fbb2e96fb80c1d))
* **search:** add error handling and display for search failures ([0ebe587](https://github.com/LindemannRock/craft-search-manager/commit/0ebe5871edb7d5b545e32bb9087570a443d860bb))
* **search:** add siteIdFilter method to scope results by site ([88dc7f2](https://github.com/LindemannRock/craft-search-manager/commit/88dc7f28d8b93592440dfea01d5ad2b770b10f3d))
* **search:** add siteIdFilter method to scope results by site ([dfabd0f](https://github.com/LindemannRock/craft-search-manager/commit/dfabd0fb4b66f8e9d430a485fe342b90cefff1fe))
* **search:** add siteIdFilter method to scope search results by site ([90cf703](https://github.com/LindemannRock/craft-search-manager/commit/90cf70304b8a2066bee76277c53694a859d567cf))
* **settings:** add link to analytics settings in device detection caching message ([232ea44](https://github.com/LindemannRock/craft-search-manager/commit/232ea44757946c2ccd8b252ca792b66c3195da9c))
* **settings:** add require API Key setting for public endpoints ([bfa1a4b](https://github.com/LindemannRock/craft-search-manager/commit/bfa1a4b35ff2ce66451a5ef225c54a2f1c7f34cb))
* **settings:** add requireApiKey setting for API key enforcement ([fe68697](https://github.com/LindemannRock/craft-search-manager/commit/fe6869720dc3e6a40611fb8c44c0ec23619754f0))
* **storage:** replace base path resolution with FileBackendStoragePathHelper ([4456f9b](https://github.com/LindemannRock/craft-search-manager/commit/4456f9b011c1a692c9c787dfff93e53a766cfb1e))
* **tests:** add ApiKeyAnalyticsAttributionTest for analytics key tracking ([81903d2](https://github.com/LindemannRock/craft-search-manager/commit/81903d22fef626c7b7933b185c1997c1b7449053))
* **tests:** add build verification tests for SearchModalWidget ([30b5e07](https://github.com/LindemannRock/craft-search-manager/commit/30b5e0791d35309cf0f2f69ff90bc78ceef271b9))
* **tests:** add tests for API key attribution in search analytics ([35f3d25](https://github.com/LindemannRock/craft-search-manager/commit/35f3d25437790551657ed7040e1537e63f7e7484))
* validate unique handle for database-backed search indices ([953d8e3](https://github.com/LindemannRock/craft-search-manager/commit/953d8e31bd06063f29e879c71cdc4d1740c1d050))
* **widget:** add ID and type display for widget configurations ([315fd69](https://github.com/LindemannRock/craft-search-manager/commit/315fd69e10599db238b1eaef453195b113badad5))


### Fixed

* clear search cache for promotions based on index handle ([c0dd894](https://github.com/LindemannRock/craft-search-manager/commit/c0dd8943d005cd03e8aebc8653bba521e27cc580))
* **controllers:** ensure unique handle assignment for backends, indices, and widgets ([a1832b5](https://github.com/LindemannRock/craft-search-manager/commit/a1832b5021cfad047651f9bcdf9b24a66b41a1c2))
* escape HTML in Highlighter to prevent XSS vulnerabilities ([ca8fa4a](https://github.com/LindemannRock/craft-search-manager/commit/ca8fa4a833f0a167cfdd5bc31c484dfc3d4302ca))
* handle JSON response for widget config deletion errors ([24f875b](https://github.com/LindemannRock/craft-search-manager/commit/24f875b10af6876abde2b993627f7cb77ff9489d))
* **i18n:** correct 'New API key' label to 'New API Key' in API keys template ([ca20104](https://github.com/LindemannRock/craft-search-manager/commit/ca201042e79b28126022e5304735e322cfdc21dd))
* **i18n:** correct caching terminology in Spanish translations ([a51ea8a](https://github.com/LindemannRock/craft-search-manager/commit/a51ea8a115607eb5b1442391f804b25726408482))
* **i18n:** correct empty message translation for indices ([1346d7b](https://github.com/LindemannRock/craft-search-manager/commit/1346d7b402a444710a68e1343f343e6989e5a502))
* **i18n:** correct Portuguese translations for browser and OS terms ([c819c7a](https://github.com/LindemannRock/craft-search-manager/commit/c819c7a3b45d614fb4073fec079fcf29b17eeb28))
* **i18n:** correct punctuation in Japanese translation strings ([784540c](https://github.com/LindemannRock/craft-search-manager/commit/784540caedd443a9856f45a80047fd47c12203a9))
* **i18n:** correct status label from 'Active' to 'Enabled' in API keys ([fd94b54](https://github.com/LindemannRock/craft-search-manager/commit/fd94b54f020344f8a8d148d11102c908ad92c962))
* **i18n:** correct style deletion messages across multiple locales ([9fde26b](https://github.com/LindemannRock/craft-search-manager/commit/9fde26b4a971d486d69947c33253cfcd040a6db5))
* **i18n:** update deletion message for widget styles ([f251072](https://github.com/LindemannRock/craft-search-manager/commit/f251072b9d9a460bc9bbcd604c6724b78bc53239))
* **promotions:** clear all search caches on promotion save and delete ([766a208](https://github.com/LindemannRock/craft-search-manager/commit/766a20878bb37d4688a730c78b22fb10eb44c346))
* **search:** handle non-JSON error responses in search error message ([41261f8](https://github.com/LindemannRock/craft-search-manager/commit/41261f81715d08d87e5a1e298debc7516f733160))
* **settings:** add validation for indexPrefix to allow only alphanumeric characters, underscores, and hyphens ([fa036c9](https://github.com/LindemannRock/craft-search-manager/commit/fa036c9e43f4e8061fe22178c628de68821f204a))
* **settings:** clarify API key requirement description in settings model ([a8f3f9d](https://github.com/LindemannRock/craft-search-manager/commit/a8f3f9dc103663eeda28f8d9d5f3eb23d15c21f8))
* **settings:** ensure posted settings are cast to array and validate correctly ([ee81f3e](https://github.com/LindemannRock/craft-search-manager/commit/ee81f3efa34fbb2946b124dc7e1370f4403d22c8))
* **url:** normalize control characters in unsafe navigation URL check ([9d30284](https://github.com/LindemannRock/craft-search-manager/commit/9d302848798eb7633e3babf86e68f5cb17e6e4b8))

## [5.46.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.45.0...v5.46.0) - 2026-05-22


### Added

* add attribute labels for various settings in Search Manager ([438082e](https://github.com/LindemannRock/craft-search-manager/commit/438082e01b8341cac55811d56cedf6ffca61898e))
* add pre-commit hook for ECS and PHPStan code quality checks ([95ec7aa](https://github.com/LindemannRock/craft-search-manager/commit/95ec7aa4a60c0ed823fb1150c46520d886ea0c43))
* **analytics:** add log category to analytics settings ([99bf488](https://github.com/LindemannRock/craft-search-manager/commit/99bf4886a38d162b81def411d900b109fb490352))
* **analytics:** add log category to geo configuration ([fcf5777](https://github.com/LindemannRock/craft-search-manager/commit/fcf577760af49465e0f0a866fe3a49a6be8373de))
* **analytics:** update search analytics to use indexSearches instead of searches ([6798586](https://github.com/LindemannRock/craft-search-manager/commit/67985865140c0fa8390bcba4c8a6de86912bf7a6))
* **api-keys:** add API key management functionality ([546edab](https://github.com/LindemannRock/craft-search-manager/commit/546edab75539e1b3508213cfb8822ccdb24144ef))
* **criteria:** consolidate criteria matching in SearchIndex model ([68088b3](https://github.com/LindemannRock/craft-search-manager/commit/68088b34e04f5fb1417505f279e7728ae40e8fef))
* **i18n:** add API key management strings to translation file ([d7f281b](https://github.com/LindemannRock/craft-search-manager/commit/d7f281b9325a5bdd61732251158e90c8775d6dfd))
* **i18n:** add new keys for index searches in multiple languages ([30fbc5b](https://github.com/LindemannRock/craft-search-manager/commit/30fbc5b5469a20ed45a48347ae8b280df1a87307))
* **i18n:** add new keys for sync management in multiple languages ([7defc3d](https://github.com/LindemannRock/craft-search-manager/commit/7defc3ddcac80bbdf68313819e965409bf704ca4))
* **i18n:** add translation issue template for reporting translations ([b5cba94](https://github.com/LindemannRock/craft-search-manager/commit/b5cba94edd941aaf9592007ad3f2fff99e834f06))
* **i18n:** translate batch sync, last-indexed debounce, and export-limit strings ([5532194](https://github.com/LindemannRock/craft-search-manager/commit/553219490c8191fa6cfccfab24e0fa8735bd0ce0))
* **i18n:** update sync failure messages to pending syncs in multiple languages ([50a2262](https://github.com/LindemannRock/craft-search-manager/commit/50a22624964a284cc284c9421b881051ae4b8ef6))
* **indexing:** add lastIndexedDebounceSeconds setting for automatic metadata updates ([6d05743](https://github.com/LindemannRock/craft-search-manager/commit/6d0574306da4c297446840297008fe384a220ba5))
* **pending-syncs:** add Pending Syncs management interface and functionality ([f1a532d](https://github.com/LindemannRock/craft-search-manager/commit/f1a532dcd9b61c322e786a6915c5c4b4f851b9b6))
* **pending-syncs:** add Pending Syncs management template and functionality ([e0ee614](https://github.com/LindemannRock/craft-search-manager/commit/e0ee6140d55352f98be9d7da39b8f8139a574aa3))
* **pending-syncs:** update Pending Syncs management and UI elements ([908bdf0](https://github.com/LindemannRock/craft-search-manager/commit/908bdf08e6efd79a920be97cfbc35d67e161406b))
* **search:** add cache telemetry to search tracking for analytics ([2334f9e](https://github.com/LindemannRock/craft-search-manager/commit/2334f9e7e8da96e401ebf56cc260c2fe898dca3d))
* **sync-status:** implement L3 buffer for status sync job and queue entries ([669bf72](https://github.com/LindemannRock/craft-search-manager/commit/669bf72c97692587a536f05c4c1c154c948ad88a))
* **sync:** add integration tests for pending sync pipeline ([f0375f4](https://github.com/LindemannRock/craft-search-manager/commit/f0375f4054ec945200bbca0a0d7460720794016a))
* **sync:** enhance pending sync processing and indexing efficiency ([5a8f64c](https://github.com/LindemannRock/craft-search-manager/commit/5a8f64c20070f7a0fbacb17cc258750955c14183))
* **sync:** implement batch processing for pending sync rows ([651a7ac](https://github.com/LindemannRock/craft-search-manager/commit/651a7aca42b0558ae330ac95054ea1082c84df42))
* **widgets:** implement in-memory filtering, sorting, and pagination for widget configurations and styles ([af17842](https://github.com/LindemannRock/craft-search-manager/commit/af178423cc9cdf711da6e9722b3d2401dc6cd4df))


### Fixed

* **core:** enhance boolean parsing in ConfigParser ([dc18bb8](https://github.com/LindemannRock/craft-search-manager/commit/dc18bb894a431ce013964b1b4bb5a0dd3c2ab655))
* correct error messages for various actions in controllers ([026c06b](https://github.com/LindemannRock/craft-search-manager/commit/026c06b0e72cd607be0d110e5efa772ee42bfd8e))
* **i18n:** align 3-plugin shared translations + clean up orphan/period/convention drift ([2a26c46](https://github.com/LindemannRock/craft-search-manager/commit/2a26c463a3726ba7f913f3c8bcfc793946f2d665))
* **i18n:** correct API Key translations in multiple languages ([c7a369e](https://github.com/LindemannRock/craft-search-manager/commit/c7a369e624b830f89212947ae26ead487805e436))
* **i18n:** correct ellipsis in search placeholders and error messages ([67ac36a](https://github.com/LindemannRock/craft-search-manager/commit/67ac36a1aeee529bc7087ac1df2136239e16a262))
* **i18n:** correct index rebuild and deletion messages for consistency ([664ef04](https://github.com/LindemannRock/craft-search-manager/commit/664ef0465ac4aa116d8aef8f2fe129385dcb332a))
* **i18n:** correct Swedish translations for cache and search terms ([9bfcb39](https://github.com/LindemannRock/craft-search-manager/commit/9bfcb39959080866a2a5bbc20f707a6a0e4272f8))
* **i18n:** remove deprecated plugin settings from translations ([82b5ffd](https://github.com/LindemannRock/craft-search-manager/commit/82b5ffd10f8f543bb5706c04829096a66fc43e69))
* **i18n:** remove deprecated translation for number of items per page ([2ee28c4](https://github.com/LindemannRock/craft-search-manager/commit/2ee28c4f93a8f4cba469c5afd1e5cdca98b48809))
* **i18n:** translate API Keys feature, clean up orphan/period drift across 12 languages ([69c4fe7](https://github.com/LindemannRock/craft-search-manager/commit/69c4fe7a050caacf1b41959c16490e68387212f1))
* **i18n:** translate API Keys feature, clean up orphan/period drift across 12 languages ([0726808](https://github.com/LindemannRock/craft-search-manager/commit/0726808b76734437b784bda83bf06d696ec67cd2))

## [5.45.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.44.1...v5.45.0) - 2026-05-06


### Features

* **translations:** update copyright year and add new translation keys for settings ([0c9d212](https://github.com/LindemannRock/craft-search-manager/commit/0c9d212c85f6f8a465516cf566ab15c9161b5f3e))


### Bug Fixes

* apply config overrides through shared settings helper ([786d46b](https://github.com/LindemannRock/craft-search-manager/commit/786d46b831bcaf39ece1cc24843324876e5d665d))
* **translations:** correct translations for various languages ([27c5923](https://github.com/LindemannRock/craft-search-manager/commit/27c59230b3f5e2aa609f27f2140352a1761fbce8))
* **widgetConfig:** add missing analytics settings to search modal ([64bb5b4](https://github.com/LindemannRock/craft-search-manager/commit/64bb5b4508e3cec41721128d450839036cc940d4))
* **WidgetConfigService:** correct styleHandle key in widget config ([0c6e5d1](https://github.com/LindemannRock/craft-search-manager/commit/0c6e5d166a71f6df14d8506ec372cdbf811edc54))


### Miscellaneous Chores

* update version annotations across multiple files ([0f59020](https://github.com/LindemannRock/craft-search-manager/commit/0f59020ea77bd757d76073983cb5e0fb12c454d7))

## [5.44.1](https://github.com/LindemannRock/craft-search-manager/compare/v5.44.0...v5.44.1) - 2026-04-18


### Bug Fixes

* **IndexingService:** run stale-doc cleanup when element matches zero indices ([35a0845](https://github.com/LindemannRock/craft-search-manager/commit/35a0845df892069a2b6ca79df8b1a087d816cc30))

## [5.44.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.43.1...v5.44.0) - 2026-04-17


### Features

* Add 12-language translation support with 1,250 keys across EN, DE, FR, NL, ES, AR, IT, PT, JA, SV, DA, NO ([577625d](https://github.com/LindemannRock/craft-search-manager/commit/577625d1a0daa42475ed16cd2a4dc88e7f8212ca))
* **stopwords:** add stop word lists for Danish, Italian, Japanese, Dutch, Norwegian, Portuguese, and Swedish ([827593e](https://github.com/LindemannRock/craft-search-manager/commit/827593e411c00a27b58040d77d01a24fe2cd2be2))


### Bug Fixes

* **edit.twig:** update Redis connection handling and messaging ([c494308](https://github.com/LindemannRock/craft-search-manager/commit/c494308cb393a514f2c3d1538fc356474822e914))
* **IndexingService:** clean up stale documents from non-matching indices ([6718f3e](https://github.com/LindemannRock/craft-search-manager/commit/6718f3e480895d2bc65dc5df9d7afa519f11d96c))
* **QueryParser:** support localized operators in all 12 shipped languages ([ed75206](https://github.com/LindemannRock/craft-search-manager/commit/ed752063249ecf3b49a486cedca3585707d5097e))
* **release-please:** drop PAT requirement for release-please — use built-in GITHUB_TOKEN ([58d5c0f](https://github.com/LindemannRock/craft-search-manager/commit/58d5c0f16f8f64ba4ed5d3a87906e6c0e233d25a))
* **templates:** translate hardcoded strings in CP editors ([d33ef5e](https://github.com/LindemannRock/craft-search-manager/commit/d33ef5ecb9dd7034a3da94245ab96264042c8adb))
* **TermNormalizer:** improve diacritic handling and recomposition ([2a22f7c](https://github.com/LindemannRock/craft-search-manager/commit/2a22f7c2439c6552729e000cba6ee2a0de21adbc))

## [5.43.1](https://github.com/LindemannRock/craft-search-manager/compare/v5.43.0...v5.43.1) - 2026-04-05


### Bug Fixes

* **icon:** update icon color to match branding guidelines ([ef633af](https://github.com/LindemannRock/craft-search-manager/commit/ef633af85e9e6233a49cedf2d546ee5057e3097a))
* read-only settings page for admin changes ([84e748f](https://github.com/LindemannRock/craft-search-manager/commit/84e748f7f736c4c1adf8290e8cb656b97ac5ab8e))
* update install experience text to use Craft translation ([90706dd](https://github.com/LindemannRock/craft-search-manager/commit/90706dd2bec29aca1991bb439a7989aeb4519b06))

## [5.43.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.42.0...v5.43.0) - 2026-03-17


### Features

* **analytics:** streamline IP processing using AnalyticsIpHelper ([956795a](https://github.com/LindemannRock/craft-search-manager/commit/956795a77710b29559eb90d5ce3bde9c4dc93632))

## [5.42.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.41.0...v5.42.0) - 2026-03-17


### Features

* **assets:** update build scripts and add package-lock.json ([6c01c78](https://github.com/LindemannRock/craft-search-manager/commit/6c01c78f96de86dc915490be86f2c4acf4a39d1f))
* **searchmanager:** add installation experience configuration ([229239b](https://github.com/LindemannRock/craft-search-manager/commit/229239b1e514e2ec992e1a8d5d3e5855d02ab582))
* **searchwidget:** update version and license, remove minified file check ([791e4b0](https://github.com/LindemannRock/craft-search-manager/commit/791e4b040f5e76f5f965c6e765c4bea66bbebe9d))


### Bug Fixes

* **settings:** remove redundant submit button from settings forms ([9a53d00](https://github.com/LindemannRock/craft-search-manager/commit/9a53d001392a6a6f00213e06ed7cda9a5b2f6cef))


### Miscellaneous Chores

* **searchwidget:** update dependencies in package.json and package-lock.json ([9a48204](https://github.com/LindemannRock/craft-search-manager/commit/9a482041bf836f25f19cc937f223e82546b3dd56))

## [5.41.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.40.2...v5.41.0) - 2026-03-04


### Features

* **controller:** add widget type options and style error handling ([bb30573](https://github.com/LindemannRock/craft-search-manager/commit/bb3057346b048d40222b8f6fec226f5506b2b5d1))
* **model:** enhance query rule validation ([bb30573](https://github.com/LindemannRock/craft-search-manager/commit/bb3057346b048d40222b8f6fec226f5506b2b5d1))
* **model:** enhance widget configuration validation ([bb30573](https://github.com/LindemannRock/craft-search-manager/commit/bb3057346b048d40222b8f6fec226f5506b2b5d1))
* **model:** implement settings schema validation for backends ([bb30573](https://github.com/LindemannRock/craft-search-manager/commit/bb3057346b048d40222b8f6fec226f5506b2b5d1))
* **model:** improve settings validation in Settings ([bb30573](https://github.com/LindemannRock/craft-search-manager/commit/bb3057346b048d40222b8f6fec226f5506b2b5d1))
* **model:** validate backend handle and heading levels in SearchIndex ([bb30573](https://github.com/LindemannRock/craft-search-manager/commit/bb3057346b048d40222b8f6fec226f5506b2b5d1))


### Bug Fixes

* **jobs:** implement RetryableJobInterface in job classes ([4e53a87](https://github.com/LindemannRock/craft-search-manager/commit/4e53a873963323e184359a742e496855f7224b73))
* **jobs:** implement RetryableJobInterface in job classes ([52ace2d](https://github.com/LindemannRock/craft-search-manager/commit/52ace2d08a0cc4fc156aa76ae2c58c03477ac886))
* **template:** ensure proper error handling in widget behavior settings ([bb30573](https://github.com/LindemannRock/craft-search-manager/commit/bb3057346b048d40222b8f6fec226f5506b2b5d1))
* **template:** improve error handling in settings and edit templates ([bb30573](https://github.com/LindemannRock/craft-search-manager/commit/bb3057346b048d40222b8f6fec226f5506b2b5d1))


### Miscellaneous Chores

* **gitignore:** update node_modules entry to exclude root directory ([e12a6bf](https://github.com/LindemannRock/craft-search-manager/commit/e12a6bfb325956b2527127e8460e854565b0a3ce))

## [5.40.2](https://github.com/LindemannRock/craft-search-manager/compare/v5.40.1...v5.40.2) - 2026-02-24


### Bug Fixes

* **RebuildIndexJob:** implement canRetry method for job retries ([f42b6e9](https://github.com/LindemannRock/craft-search-manager/commit/f42b6e9c6f40c6ae239fe8066fed374b63a93d63))

## [5.40.1](https://github.com/LindemannRock/craft-search-manager/compare/v5.40.0...v5.40.1) - 2026-02-24


### Bug Fixes

* **RebuildIndexJob:** update getTtr method documentation for clarity ([a4949f9](https://github.com/LindemannRock/craft-search-manager/commit/a4949f9f21d1b94b07d1ce44514f4360737bcb22))

## [5.40.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.39.1...v5.40.0) - 2026-02-24


### Features

* **TermNormalizer:** add shared Unicode-aware text normalization ([bc4798d](https://github.com/LindemannRock/craft-search-manager/commit/bc4798d822b534929d2a13b76a8d3c4e233ce264))

## [5.39.1](https://github.com/LindemannRock/craft-search-manager/compare/v5.39.0...v5.39.1) - 2026-02-24


### Bug Fixes

* **EnrichmentService:** use per-hit siteId for element retrieval ([392001c](https://github.com/LindemannRock/craft-search-manager/commit/392001cb931589d68e2ca6c06c3e3f3bc0213cbd))
* **SettingsController:** respect configured siteId for search options ([518022f](https://github.com/LindemannRock/craft-search-manager/commit/518022f0a3e233858134287d209e7a15edab9825))
* **Tokenizer:** normalize text processing for consistent indexing ([c59dd54](https://github.com/LindemannRock/craft-search-manager/commit/c59dd54311a5abc0a3fb0dfa079be2f063253ab8))

## [5.39.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.38.0...v5.39.0) - 2026-02-22


### Features

* add dedicated widget style permissions and fix nested permission pattern ([eef2f96](https://github.com/LindemannRock/craft-search-manager/commit/eef2f963bb6dd946d83cbef49cf0a14397719c52))
* add hierarchical result display for search widget ([381a0be](https://github.com/LindemannRock/craft-search-manager/commit/381a0be187501619bc6a9ba78ef89a3ae47fd722))
* add prose-only snippets (_contentClean) and rename allowCodeSnippets to showCodeSnippets ([7c8e746](https://github.com/LindemannRock/craft-search-manager/commit/7c8e746e13ccb50d4fdfddda7e7a6ec41212427d))
* **analytics:** add recent searches and unhandled data loading ([885b429](https://github.com/LindemannRock/craft-search-manager/commit/885b429d3f0cc0b82b2d8e3d013b29e6e00153c5))
* merge search endpoints, add enrichment service, search/transform ([5f53fd5](https://github.com/LindemannRock/craft-search-manager/commit/5f53fd527d0c20fe4951b80da4fc53b47ac47d52))
* **plugin-docs:** add support for PluginDoc indexing and transformation ([d893b3e](https://github.com/LindemannRock/craft-search-manager/commit/d893b3e3bc68fbaf64cdacbaaadc9d7a30166c8d))
* **searchwidget:** add query parameter handling and destination page highlighting ([610a9a6](https://github.com/LindemannRock/craft-search-manager/commit/610a9a66c835cdd0c3fec96d345e682cb49b5eeb))
* **searchwidget:** enhance styling configuration for header and input ([237c056](https://github.com/LindemannRock/craft-search-manager/commit/237c0566f90bf3b41c99330cef5c4a7d712b67c2))
* server-side matched terms, always-on document data, heading extraction, ([fa46c76](https://github.com/LindemannRock/craft-search-manager/commit/fa46c76ce6fe9297506bad5876a02c6ba221ac9c))
* smart query parsing for standalone highlighter, enrichment test controls, and widget CSS isolation ([32b51b2](https://github.com/LindemannRock/craft-search-manager/commit/32b51b2d77191dddf8f628e824b97c5a45938e38))
* **widget:** enhance search functionality with new settings and UI updates ([5ecb74c](https://github.com/LindemannRock/craft-search-manager/commit/5ecb74c4671c8522c8790a5175f0386a1755a775))


### Bug Fixes

* harden export CSRF protection, CSS injection sanitization, and docs-manager rename ([6b124b7](https://github.com/LindemannRock/craft-search-manager/commit/6b124b7e4cec54916eab1d0a4b857af12fc01adb))
* Remove confirmation prompt for index rebuild action ([cb66de0](https://github.com/LindemannRock/craft-search-manager/commit/cb66de003bf905aba455f69e5339ea9124fc6a78))
* **styles:** escape collision handles in warning message ([781eb77](https://github.com/LindemannRock/craft-search-manager/commit/781eb77ee5b76f5b41f3a60cae55cd3ff8935006))


### Miscellaneous Chores

* add .gitattributes with export-ignore for Packagist distribution ([ab013e6](https://github.com/LindemannRock/craft-search-manager/commit/ab013e689924dd8c0ee0769d4c5b68ded92d7b69))
* Update license information in LICENSE.md and composer.json ([d224ab4](https://github.com/LindemannRock/craft-search-manager/commit/d224ab4a0feda8097b9194e86b82b2681e95c0df))

## [5.38.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.37.0...v5.38.0) - 2026-02-07


### Features

* Enhance storage path validation and analytics permissions ([85a45c4](https://github.com/LindemannRock/craft-search-manager/commit/85a45c406ddd2a43f2b9ece79d0f69801bcc5482))


### Bug Fixes

* **AnalyticsService:** replace local date and hour expressions with DateFormatHelper methods ([5907b02](https://github.com/LindemannRock/craft-search-manager/commit/5907b02721d1a348281a89028f9f6ea5fad3ed6a))

## [5.37.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.36.0...v5.37.0) - 2026-02-05


### Features

* **actionButton:** add quick actions menu for indices, promotions, query rules, and settings ([e4b7c37](https://github.com/LindemannRock/craft-search-manager/commit/e4b7c3751d42e9488556c82ce91c5f352590d6d4))


### Bug Fixes

* **autocomplete:** results not searching all sites when siteId omitted ([392019c](https://github.com/LindemannRock/craft-search-manager/commit/392019c2fe0b38b75b55a5d85b8dac1c0b1e3d67))
* **index:** enhance collision handle warnings with bold formatting ([6aed492](https://github.com/LindemannRock/craft-search-manager/commit/6aed492e2f33fc68dde98368f1d88b71ddabd33a))
* **SearchManager:** [@since](https://github.com/since) version in getCpSections method to 5.37.0 ([572dcee](https://github.com/LindemannRock/craft-search-manager/commit/572dcee6d679e5b391c11d653a31824eaffc9beb))
* **UtilitiesController:** add handle collision check before clearing caches ([8f79631](https://github.com/LindemannRock/craft-search-manager/commit/8f7963178d54e374ad595654450e094ab2e6bb82))
* **UtilitiesController:** add handle collision check before rebuilding indices ([712e8c6](https://github.com/LindemannRock/craft-search-manager/commit/712e8c6d5620ba9fe323357261d34499171c8a90))


### Miscellaneous Chores

* **dependencies:** Remove matomo/device-detector from composer.json ([c1c70eb](https://github.com/LindemannRock/craft-search-manager/commit/c1c70eb446158799c33e941be405ca63f6a6786e))
* **package:** update package metadata and versioning ([f7a4dfb](https://github.com/LindemannRock/craft-search-manager/commit/f7a4dfb602247ba6ae742c68ad5cac16ac085f07))

## [5.36.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.35.2...v5.36.0) - 2026-01-28


### Features

* **backends, indices:** Add collision detection for backend and index handles ([5b0c4ac](https://github.com/LindemannRock/craft-search-manager/commit/5b0c4ac3d5d22e107314c3543126a8a8dd3145a2))
* **search:** Enhance site ID handling and stop words functionality ([bb92504](https://github.com/LindemannRock/craft-search-manager/commit/bb92504a4330055fcc9ae0db94e745d672973ef6))


### Bug Fixes

* **backends:** add entriesAvailable flag to index responses ([e7def18](https://github.com/LindemannRock/craft-search-manager/commit/e7def18ad908fc9a72a3771c2ce1de48bc1d01f4))

## [5.35.2](https://github.com/LindemannRock/craft-search-manager/compare/v5.35.1...v5.35.2) - 2026-01-26


### Bug Fixes

* **models:** ensure type-safe handling of siteId in SearchIndex model ([1256089](https://github.com/LindemannRock/craft-search-manager/commit/12560891b988b432b8ce2a877d83d3499b6b2727))

## [5.35.1](https://github.com/LindemannRock/craft-search-manager/compare/v5.35.0...v5.35.1) - 2026-01-26


### Bug Fixes

* **backends:** enhance document preparation for multi-site support ([ef4287b](https://github.com/LindemannRock/craft-search-manager/commit/ef4287b34e43df5fa5f51740f94c72e86b74cd7c))

## [5.35.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.34.1...v5.35.0) - 2026-01-26


### Features

* **indices:** add sync count functionality for backend indices ([981b015](https://github.com/LindemannRock/craft-search-manager/commit/981b015d7f92543d30cd85603273bdd5df0b021a))

## [5.34.1](https://github.com/LindemannRock/craft-search-manager/compare/v5.34.0...v5.34.1) - 2026-01-26


### Miscellaneous Chores

* remove unused document ([7d9d063](https://github.com/LindemannRock/craft-search-manager/commit/7d9d063845c6ff61b94ee738a131f50b6832f89e))

## [5.34.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.33.0...v5.34.0) - 2026-01-26


### Features

* **jobs:** add analytics cleanup job and enhance sync status job scheduling ([4708d82](https://github.com/LindemannRock/craft-search-manager/commit/4708d828565a12cc46e9e4cb14744d646c365be9))


### Bug Fixes

* **cache:** correct popular query threshold check and remove legacy widget template ([4325d48](https://github.com/LindemannRock/craft-search-manager/commit/4325d48369a51122fae302d21532c86d0f19d24b))
* **security:** replace unserialize with JSON and strip API meta exposure ([da51780](https://github.com/LindemannRock/craft-search-manager/commit/da517805f37831ec43e313f4c6d9a89587be900d))

## [5.33.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.32.0...v5.33.0) - 2026-01-21


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

## [5.32.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.31.0...v5.32.0) - 2026-01-20


### Features

* add debug enhancements, loading indicator config, and spinner customization ([3143156](https://github.com/LindemannRock/craft-search-manager/commit/3143156774d72d747982ab97fd68abb324ce0c7b))
* add debug option to performSearch function ([084a54d](https://github.com/LindemannRock/craft-search-manager/commit/084a54d7a4fc28ef4b5a38b1e57a8bf80fa46305))
* Add SearchModalWidget with modular architecture and debug toolbar ([853d607](https://github.com/LindemannRock/craft-search-manager/commit/853d607e0cd910ae2a16456b2218554855a78866))
* add skipEntriesWithoutUrl, hideResultsWithoutUrl, and external backend improvements ([1c88bab](https://github.com/LindemannRock/craft-search-manager/commit/1c88bab5af66d1122188d931776f6b7a96177820))

## [5.31.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.30.0...v5.31.0) - 2026-01-19


### Features

* Add maxRecentSearches configuration for recent searches functionality ([a36ab2f](https://github.com/LindemannRock/craft-search-manager/commit/a36ab2f082d16f7be55d8956c59044e1223a12dd))
* Refactor highlighting settings to group fields within a toggleable section ([9a872e6](https://github.com/LindemannRock/craft-search-manager/commit/9a872e60f886a8902952f1d2dd9b36cfd3640da4))

## [5.30.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.29.0...v5.30.0) - 2026-01-19


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

## [5.29.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.28.2...v5.29.0) - 2026-01-16


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

## [5.28.2](https://github.com/LindemannRock/craft-search-manager/compare/v5.28.1...v5.28.2) - 2026-01-13


### Bug Fixes

* backend handling to use defaultBackendHandle and improve compatibility checks ([daee11e](https://github.com/LindemannRock/craft-search-manager/commit/daee11e9aab9517cb8b7a64ae9cdd96328107300))

## [5.28.1](https://github.com/LindemannRock/craft-search-manager/compare/v5.28.0...v5.28.1) - 2026-01-13


### Bug Fixes

* backend creation logic to use defaultBackendHandle and improve fallback handling ([d40af39](https://github.com/LindemannRock/craft-search-manager/commit/d40af399029ff1bd40c37b42e0294dd8f8a70d30))

## [5.28.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.27.0...v5.28.0) - 2026-01-13


### Features

* Add configuredBackends system with granular permissions and per-index backend support ([c5104db](https://github.com/LindemannRock/craft-search-manager/commit/c5104db074c47e013a6de701add89406c4d93211))
* add cross-backend methods for Algolia, Meilisearch, and Typesense, including browse, multiple queries, and filter parsing ([c47bf42](https://github.com/LindemannRock/craft-search-manager/commit/c47bf4205b5507a6c19c06df28e6eb447ed464bc))

## [5.27.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.26.1...v5.27.0) - 2026-01-12


### Features

* add analytics count to cache clearing buttons and format displayed numbers ([fd2791a](https://github.com/LindemannRock/craft-search-manager/commit/fd2791a74d5a4e00aae75b4f369f051e973de629))
* add analytics summary, content gaps, top searches, and trending searches widgets ([403ba22](https://github.com/LindemannRock/craft-search-manager/commit/403ba22de3eedddec12092a666be64aa207c42b7))

## [5.26.1](https://github.com/LindemannRock/craft-search-manager/compare/v5.26.0...v5.26.1) - 2026-01-11


### Bug Fixes

* plugin name retrieval to use getFullName method ([da20354](https://github.com/LindemannRock/craft-search-manager/commit/da2035446a9ab38005e55aad9476bfb06860747f))

## [5.26.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.25.0...v5.26.0) - 2026-01-10


### Features

* Replace custom country name retrieval with GeoHelper utility ([c6e49fc](https://github.com/LindemannRock/craft-search-manager/commit/c6e49fc2f88e4249f21fdda76a7e023e0ee5e8a1))

## [5.25.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.24.0...v5.25.0) - 2026-01-09


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

## [5.24.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.23.0...v5.24.0) - 2026-01-08


### Features

* refactor permissions to use grouped nested structure with granular access control ([3774d8c](https://github.com/LindemannRock/craft-search-manager/commit/3774d8c123a43f8bc38c74710807d66368d24115))

## [5.23.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.22.0...v5.23.0) - 2026-01-06


### Features

* migrate to shared base plugin ([125dfff](https://github.com/LindemannRock/craft-search-manager/commit/125dfff6b442221498f28085af0a3ef16b0943a8))

## [5.22.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.21.2...v5.22.0) - 2026-01-05


### Features

* add backend filtering to indices for improved data management ([d837e1f](https://github.com/LindemannRock/craft-search-manager/commit/d837e1fa385c48559af9b75781ac6e06d3f7145c))
* add sorting functionality to indices, promotions, and query rules templates ([178b801](https://github.com/LindemannRock/craft-search-manager/commit/178b80130c67ae6301347ff92d567ef37397383a))
* enhance color coding for backend, source, and match types in indices, promotions, and query rules templates ([2f939c0](https://github.com/LindemannRock/craft-search-manager/commit/2f939c08b34a1f67db0885fc8b8d1414d8068b29))


### Bug Fixes

* backend filter logic and prepare for per-index backend overrides ([e5f5faf](https://github.com/LindemannRock/craft-search-manager/commit/e5f5faf97fadee2a4c7aab373603c09d863594b0))

## [5.21.2](https://github.com/LindemannRock/craft-search-manager/compare/v5.21.1...v5.21.2) - 2026-01-05


### Bug Fixes

* add tab-content class to analytics sections for improved styling ([a6df207](https://github.com/LindemannRock/craft-search-manager/commit/a6df207e1860f9860838cc508fac9acf30729baa))

## [5.21.1](https://github.com/LindemannRock/craft-search-manager/compare/v5.21.0...v5.21.1) - 2026-01-04


### Bug Fixes

* auto-indexing for config indices and add real-time document count tracking ([de90fa8](https://github.com/LindemannRock/craft-search-manager/commit/de90fa8e47b780a37a466ff215923e0f75079111))

## [5.21.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.20.2...v5.21.0) - 2026-01-04


### Features

* enhance indexing service to support Closure-based criteria for config indices ([b078f49](https://github.com/LindemannRock/craft-search-manager/commit/b078f496e2e19a9b7f348de9585064b396d63ca0))

## [5.20.2](https://github.com/LindemannRock/craft-search-manager/compare/v5.20.1...v5.20.2) - 2025-12-20


### Bug Fixes

* format numerical values in dashboard for improved readability ([5759a85](https://github.com/LindemannRock/craft-search-manager/commit/5759a855108af7dc975cf78d84ce3a262444b681))

## [5.20.1](https://github.com/LindemannRock/craft-search-manager/compare/v5.20.0...v5.20.1) - 2025-12-20


### Bug Fixes

* query rules table to rename 'Match Type' to 'Query Pattern' and add a new 'Match' column with color indicators ([3bbaa96](https://github.com/LindemannRock/craft-search-manager/commit/3bbaa96954573aa26dd1748b5cabd43865367e28))

## [5.20.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.19.0...v5.20.0) - 2025-12-20


### Features

* enhance promotions and query rules to support multi-pattern matching with commas ([c841691](https://github.com/LindemannRock/craft-search-manager/commit/c8416913a2b042edfe1fc84a79ba25e3cb74c374))

## [5.19.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.18.0...v5.19.0) - 2025-12-20


### Features

* update promotions handling to support null indexHandle for global promotions and improve UI placeholders ([a866fd2](https://github.com/LindemannRock/craft-search-manager/commit/a866fd2459012de42ebd3a7316a045af64dbe2a6))

## [5.18.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.17.0...v5.18.0) - 2025-12-20


### Features

* add title attribute to promotions and update related templates for better identification ([e2e2a8a](https://github.com/LindemannRock/craft-search-manager/commit/e2e2a8a9a43b365be2fd6f0ec33de4f38073fe5b))

## [5.17.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.16.0...v5.17.0) - 2025-12-20


### Features

* enhance Arabic language support with spelling variations for boolean operators ([8d8fa83](https://github.com/LindemannRock/craft-search-manager/commit/8d8fa83a8241b8edf6a663a1e2251536cff79330))

## [5.16.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.15.0...v5.16.0) - 2025-12-20


### Features

* add  all-sites search support for CP test page ([1989182](https://github.com/LindemannRock/craft-search-manager/commit/19891822081d649347b425b8a9fe2ca2659b018a))


### Bug Fixes

* objectID/elementId mismatch in promotions, query rules, and synonym handling ([014a9cd](https://github.com/LindemannRock/craft-search-manager/commit/014a9cd6bb7635ee9887947798826d1f140c9c90))

## [5.15.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.14.1...v5.15.0) - 2025-12-19


### Features

* Enhance language support in search storage and autocomplete services ([ea78e60](https://github.com/LindemannRock/craft-search-manager/commit/ea78e6089488abccc7b7d3f79b366aff4695fbbf))

## [5.14.1](https://github.com/LindemannRock/craft-search-manager/compare/v5.14.0...v5.14.1) - 2025-12-19


### Bug Fixes

* move index retrieval logic to a separate section in test.twig ([48ae33a](https://github.com/LindemannRock/craft-search-manager/commit/48ae33ade32e0b06fde5cabd0296a74474bc71e7))

## [5.14.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.13.0...v5.14.0) - 2025-12-19


### Features

* Add 'pgsql' to searchBackend options in Settings model ([7191b1b](https://github.com/LindemannRock/craft-search-manager/commit/7191b1bab2091ecee3d7ea6cbec6b1aa5def3ba9))

## [5.13.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.12.0...v5.13.0) - 2025-12-19


### Features

* Enhance test settings template to include siteId mapping for indices ([ce02eaf](https://github.com/LindemannRock/craft-search-manager/commit/ce02eafaeb36630e4e353a07d8debb02f506e242))

## [5.12.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.11.0...v5.12.0) - 2025-12-19


### Features

* Enhance autocomplete functionality with siteId parameter and update test template ([f9d8a8d](https://github.com/LindemannRock/craft-search-manager/commit/f9d8a8d610265a57af32bb9a68df134632fba73f))

## [5.11.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.10.0...v5.11.0) - 2025-12-19


### Features

* Add promotion and query rule testing actions to settings controller and update test template ([d8c4b57](https://github.com/LindemannRock/craft-search-manager/commit/d8c4b57c4356ae33c43701d96bee5c4d671e3c07))

## [5.10.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.9.0...v5.10.0) - 2025-12-19


### Features

* Add Promotions and Query Rules systems with analytics improvements ([64a3c67](https://github.com/LindemannRock/craft-search-manager/commit/64a3c67b6f5edb411142136f26a1c304760b43fa))
* Enhance dashboard with promotions and query rules statistics, and add analytics overview ([eeeb904](https://github.com/LindemannRock/craft-search-manager/commit/eeeb904fac9cc5c9e483102882f140939f0136ab))
* Update cache settings instructions and add human-readable duration for cache inputs ([3d33c5e](https://github.com/LindemannRock/craft-search-manager/commit/3d33c5e192f8b88ca9e76e9896fcd15e4054b3b3))


### Bug Fixes

* **ui:** boost multiplier spacing ([e347acb](https://github.com/LindemannRock/craft-search-manager/commit/e347acb48e57c403cc9904c90fb7fa3cb309302e))

## [5.9.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.8.0...v5.9.0) - 2025-12-18


### Features

* add expected element count calculation and update indices template ([2e8dc85](https://github.com/LindemannRock/craft-search-manager/commit/2e8dc851d090108298675b635151e84ae722b913))
* Add localized boolean operators for 5 languages ([ac6fe32](https://github.com/LindemannRock/craft-search-manager/commit/ac6fe3205fefad91b99ba6d2dbbc4e17ca7941e9))
* Add source detection, performance tab, and analytics improvements ([efd0d49](https://github.com/LindemannRock/craft-search-manager/commit/efd0d491d19348d29bfb94cf9db43b3438929da0))

## [5.8.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.7.0...v5.8.0) - 2025-12-18


### Features

* add unified autocomplete endpoint with suggestions and results ([35e7ddb](https://github.com/LindemannRock/craft-search-manager/commit/35e7ddbecee80e373f189631a0e9782fac2d4741))

## [5.7.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.6.0...v5.7.0) - 2025-12-18


### Features

* add type filtering and enrichment to search API ([85f8fc3](https://github.com/LindemannRock/craft-search-manager/commit/85f8fc316c7d4d09e81248eb2dee7f7bc5229cd2))
* derive element type from section handle in AutoTransformer ([8a26963](https://github.com/LindemannRock/craft-search-manager/commit/8a269630b6269d3781475aba855fd4463dde64ac))

## [5.6.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.5.9...v5.6.0) - 2025-12-18


### Features

* add rich autocomplete with element type detection ([1be13b5](https://github.com/LindemannRock/craft-search-manager/commit/1be13b563c2468dfae6a163d64462d8f8af1c9ee))

## [5.5.9](https://github.com/LindemannRock/craft-search-manager/compare/v5.5.8...v5.5.9) - 2025-12-17


### Bug Fixes

* Enable plugin name setting in configuration ([a24c9fa](https://github.com/LindemannRock/craft-search-manager/commit/a24c9fae0086b076280ba1c4b4b47f9730a9baa6))

## [5.5.8](https://github.com/LindemannRock/craft-search-manager/compare/v5.5.7...v5.5.8) - 2025-12-17


### Bug Fixes

* Update similarity threshold to improve fuzzy matching accuracy ([e473f01](https://github.com/LindemannRock/craft-search-manager/commit/e473f014aceee2d916af506a26d5304a131952dc))

## [5.5.7](https://github.com/LindemannRock/craft-search-manager/compare/v5.5.6...v5.5.7) - 2025-12-17


### Bug Fixes

* Enhance search functionality by using index's configured siteId and adding wildcard support ([5c9851e](https://github.com/LindemannRock/craft-search-manager/commit/5c9851e6452da3176dbc3709090b345763d9e72b))

## [5.5.6](https://github.com/LindemannRock/craft-search-manager/compare/v5.5.5...v5.5.6) - 2025-12-17


### Bug Fixes

* Make fuzzy matching limit configurable and fix n-gram settings save bug ([872cf0a](https://github.com/LindemannRock/craft-search-manager/commit/872cf0ab85d83da2956b7814c2e66ee44a6842f5))

## [5.5.5](https://github.com/LindemannRock/craft-search-manager/compare/v5.5.4...v5.5.5) - 2025-12-17


### Bug Fixes

* Limit results in similarity query to improve performance ([dc3572a](https://github.com/LindemannRock/craft-search-manager/commit/dc3572a79bc9b6ee9298ab67b9bd953c061fa47e))

## [5.5.4](https://github.com/LindemannRock/craft-search-manager/compare/v5.5.3...v5.5.4) - 2025-12-17


### Bug Fixes

* Use REPLACE INTO for document storage to handle duplicates and improve data integrity ([ffe33c4](https://github.com/LindemannRock/craft-search-manager/commit/ffe33c4ee4f23fa211884b81427d7468fccc3d7a))

## [5.5.3](https://github.com/LindemannRock/craft-search-manager/compare/v5.5.2...v5.5.3) - 2025-12-17


### Bug Fixes

* Prevent duplicate key errors and add comprehensive cleanup across all storage backends ([7a4fe0a](https://github.com/LindemannRock/craft-search-manager/commit/7a4fe0a2f1b6b4670c9c5acf0a40e777c11096ee))

## [5.5.2](https://github.com/LindemannRock/craft-search-manager/compare/v5.5.1...v5.5.2) - 2025-12-17


### Bug Fixes

* Clear storage now handles orphaned indices and resets metadata ([7050d63](https://github.com/LindemannRock/craft-search-manager/commit/7050d63b29b51d5d93c99156268d3632e011c8ff))

## [5.5.1](https://github.com/LindemannRock/craft-search-manager/compare/v5.5.0...v5.5.1) - 2025-12-17


### Bug Fixes

* Implement proper wildcard search and fix fuzzy matching across all backends ([07ccbad](https://github.com/LindemannRock/craft-search-manager/commit/07ccbad063fc50f6fdea0c6574717ab5ca166e7a))

## [5.5.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.4.0...v5.5.0) - 2025-12-17


### Features

* Enhance AutoTransformer to automatically handle all field types ([b09a4e5](https://github.com/LindemannRock/craft-search-manager/commit/b09a4e5b3d4bd37c2afa14eb81ab6435bbb1cb0a))
* Update transformer class instructions and examples in edit template; remove unused index copy template ([e3fddcc](https://github.com/LindemannRock/craft-search-manager/commit/e3fddcc75891fb1a9d14b28d6973f760749d79d1))

## [5.4.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.3.0...v5.4.0) - 2025-12-17


### Features

* Fix config index metadata sync and optimize SearchIndex model ([94dce2d](https://github.com/LindemannRock/craft-search-manager/commit/94dce2d347188e25dfa466c6370d1d10cb965f65))

## [5.3.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.2.2...v5.3.0) - 2025-12-16


### Features

* enhance index deletion process by clearing backend storage before database record removal ([4ab91e9](https://github.com/LindemannRock/craft-search-manager/commit/4ab91e9212648930101e9812b6fac00ee2d58f8e))


### Bug Fixes

* improve transformer selection logic to handle empty string cases ([9d31c48](https://github.com/LindemannRock/craft-search-manager/commit/9d31c48f5d59469a9efb0e3b75e418ea06ba5177))

## [5.2.2](https://github.com/LindemannRock/craft-search-manager/compare/v5.2.1...v5.2.2) - 2025-12-16


### Bug Fixes

* critical backend bugs and add PostgreSQL support ([e1e81fa](https://github.com/LindemannRock/craft-search-manager/commit/e1e81fa5b4b061db9d6cfc2780898a20db4360dc))

## [5.2.1](https://github.com/LindemannRock/craft-search-manager/compare/v5.2.0...v5.2.1) - 2025-12-16


### Bug Fixes

* improve analytics display and error handling for Chart.js loading ([9210a33](https://github.com/LindemannRock/craft-search-manager/commit/9210a3330b37f2ea68a597178436c8724f56b39f))

## [5.2.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.1.0...v5.2.0) - 2025-12-16


### Features

* add cache storage method and duration settings for different environments ([5838bb7](https://github.com/LindemannRock/craft-search-manager/commit/5838bb7fe31d71d9288be31152069f2120e69b7a))
* add cache storage method option to settings table ([b80d75e](https://github.com/LindemannRock/craft-search-manager/commit/b80d75e48e83c1283f9160e769e9ab9d74844b21))
* implement cache storage method selection and handling for Redis and file systems ([62c29b4](https://github.com/LindemannRock/craft-search-manager/commit/62c29b4ff650b45da468163339f44102f94632c0))

## [5.1.0](https://github.com/LindemannRock/craft-search-manager/compare/v5.0.0...v5.1.0) - 2025-12-16


### Features

* update backend support in search functionality to include PostgreSQL ([2e7a467](https://github.com/LindemannRock/craft-search-manager/commit/2e7a46773c2668ca39360928ef4c8833de1cc90a))

## 5.0.0 - 2025-12-16


### Features

* initial Search Manager plugin implementation ([6b63c10](https://github.com/LindemannRock/craft-search-manager/commit/6b63c109a644871af7c3db96af1fad11707cadd1))
