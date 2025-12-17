# Changelog

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
