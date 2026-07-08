<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\web\Controller;
use lindemannrock\base\helpers\SlugHandleHelper;
use lindemannrock\logginglibrary\traits\LoggingTrait;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * API Keys Controller
 *
 * CP CRUD for the API key foundation that ships with slice 1.
 *
 * Permission model (per the locked design):
 *   manageApiKeys  — page access + view list/edit form (no mutations)
 *   createApiKeys  — POST a brand-new key
 *   editApiKeys    — POST changes to an existing key's metadata/restrictions
 *   revokeApiKeys  — DELETE a key
 *
 * Plaintext keys are shown exactly once: after a successful create, the
 * plaintext is stashed in the session flash and the operator is redirected
 * to the edit page, which reveals it via a copy-to-clipboard banner. Edit
 * of an existing key never reveals plaintext. Public keys may retain encrypted
 * key material for browser-rendered widget selection, but the CP never displays
 * a full key after creation. Server keys remain hash-only. Rotation is a
 * deliberate non-feature in slice 1; future follow-up may add a separate
 * `rotate` action.
 *
 * @since 5.46.0
 */
class ApiKeysController extends Controller
{
    use LoggingTrait;

    /**
     * Session flash key for stashing the just-generated plaintext between
     * the save redirect and the subsequent edit-page render.
     */
    private const FLASH_NEW_PLAINTEXT = 'sm.apiKey.newPlaintext';

    public function init(): void
    {
        parent::init();
        $this->setLoggingHandle('search-manager');
    }

    // =========================================================================
    // INDEX
    // =========================================================================

    /**
     * Reference implementation for the new cross-plugin CP table index pattern
     * (pending dedicated pattern-session decision + base docs).
     *
     * Shape: controller owns query-param parsing, allowlist validation, filter,
     * sort, and pagination. The Twig template stays presentational — it renders
     * the already-sliced collection plus the filter/sort state passed in.
     *
     * Small in-memory datasets (this) and large DB-backed datasets share the
     * same orchestration shape; only the filter mechanism differs (array_filter
     * vs SQL WHERE).
     */
    public function actionIndex(): Response
    {
        $this->requirePermission('searchManager:manageApiKeys');

        $request = Craft::$app->getRequest();

        // ---- Param parsing + allowlist validation -------------------------
        $typeFilter = (string)$request->getQueryParam('type', 'all');
        $validTypes = ['all', ApiKey::TYPE_PUBLIC, ApiKey::TYPE_SERVER];
        if (!in_array($typeFilter, $validTypes, true)) {
            $typeFilter = 'all';
        }

        $statusFilter = (string)$request->getQueryParam('status', 'all');
        $validStatuses = ['all', 'enabled', 'disabled'];
        if (!in_array($statusFilter, $validStatuses, true)) {
            $statusFilter = 'all';
        }

        // 64-char cap on user input as a defensive clamp against runaway payloads.
        $search = trim((string)$request->getQueryParam('search', ''));
        if (mb_strlen($search) > 64) {
            $search = mb_substr($search, 0, 64);
        }

        $validSortFields = ['name', 'handle', 'status', 'type', 'allowedIndices', 'validUntil', 'lastUsedAt'];
        $sort = (string)$request->getParam('sort', 'name');
        if (!in_array($sort, $validSortFields, true)) {
            $sort = 'name';
        }
        $dir = strtolower((string)$request->getParam('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        // ---- Load + filter ------------------------------------------------
        // Type filter is a column lookup (fast SQL). Status filter is the
        // `enabled` boolean — small dataset → cheap to filter in PHP after load.
        $keys = $typeFilter === 'all'
            ? ApiKey::findAll()
            : ApiKey::findAll($typeFilter);

        // Cached before filter narrows the collection so the beforeTable
        // "no API keys yet" info-box renders correctly regardless of the
        // current filter state. Mirrors backends' `$hasAnyBackends` shape.
        $hasAnyKeys = !empty($keys);

        if ($statusFilter === 'enabled') {
            $keys = array_values(array_filter($keys, fn(ApiKey $k): bool => $k->enabled));
        } elseif ($statusFilter === 'disabled') {
            $keys = array_values(array_filter($keys, fn(ApiKey $k): bool => !$k->enabled));
        }

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $keys = array_values(array_filter($keys, function(ApiKey $k) use ($needle): bool {
                return str_contains(mb_strtolower($k->name), $needle)
                    || str_contains(mb_strtolower($k->handle), $needle)
                    || str_contains(mb_strtolower($k->keyPrefix), $needle);
            }));
        }

        // ---- Sort + paginate ----------------------------------------------
        $keys = $this->sortKeys($keys, $sort, $dir);

        // Total count is computed after filtering so the pager reflects the
        // visible subset, not the underlying table.
        $totalCount = count($keys);
        $page = max(1, (int)$request->getParam('page', 1));
        $limit = max(1, (int)SearchManager::$plugin->getSettings()->itemsPerPage);
        $offset = ($page - 1) * $limit;
        $keys = array_slice($keys, $offset, $limit);

        return $this->renderTemplate('search-manager/api-keys/index', [
            'keys' => $keys,
            'typeFilter' => $typeFilter,
            'statusFilter' => $statusFilter,
            'search' => $search,
            'sort' => $sort,
            'dir' => $dir,
            'hasAnyKeys' => $hasAnyKeys,
            'page' => $page,
            'limit' => $limit,
            'totalCount' => $totalCount,
            'canCreate' => Craft::$app->getUser()->checkPermission('searchManager:createApiKeys'),
            'canEdit' => Craft::$app->getUser()->checkPermission('searchManager:editApiKeys'),
            'canRevoke' => Craft::$app->getUser()->checkPermission('searchManager:revokeApiKeys'),
        ]);
    }

    // =========================================================================
    // EDIT (new + existing share this action)
    // =========================================================================

    public function actionEdit(?int $keyId = null, ?ApiKey $apiKey = null): Response
    {
        $this->requirePermission('searchManager:manageApiKeys');

        $isNew = ($keyId === null);

        // When a save action re-renders due to validation errors it passes an
        // already-populated $apiKey through; in all other cases load or build.
        if ($apiKey === null) {
            if ($isNew) {
                $apiKey = new ApiKey();
                $apiKey->type = ApiKey::TYPE_PUBLIC;
            } else {
                $apiKey = ApiKey::findById($keyId);
                if ($apiKey === null) {
                    throw new NotFoundHttpException(Craft::t('search-manager', 'API key not found'));
                }
            }
        }

        $title = $isNew
            ? Craft::t('search-manager', 'New API Key')
            : Craft::t('search-manager', 'Edit API Key');

        // Pull the plaintext stashed during a fresh create (one-shot reveal).
        // Craft's session->getFlash() consumes the value on read.
        $newPlaintext = Craft::$app->getSession()->getFlash(self::FLASH_NEW_PLAINTEXT);

        return $this->renderTemplate('search-manager/api-keys/edit', [
            'apiKey' => $apiKey,
            'isNew' => $isNew,
            'title' => $title,
            'allIndices' => SearchIndex::findAll(),
            'newPlaintext' => is_string($newPlaintext) ? $newPlaintext : null,
            'canCreate' => Craft::$app->getUser()->checkPermission('searchManager:createApiKeys'),
            'canEdit' => Craft::$app->getUser()->checkPermission('searchManager:editApiKeys'),
            'canRevoke' => Craft::$app->getUser()->checkPermission('searchManager:revokeApiKeys'),
        ]);
    }

    // =========================================================================
    // SAVE
    // =========================================================================

    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $keyId = $request->getBodyParam('keyId') !== null
            ? (int)$request->getBodyParam('keyId')
            : null;
        $isNew = ($keyId === null);

        $this->requirePermission($isNew ? 'searchManager:createApiKeys' : 'searchManager:editApiKeys');

        if ($isNew) {
            $apiKey = new ApiKey();
            $apiKey->type = $this->resolveType($request->getBodyParam('type'));

            // Generate the plaintext + hash + prefix once. Plaintext goes to
            // session flash for the reveal on redirect. Public keys also store
            // encrypted material for widget rendering; server keys stay hash-only.
            $generated = SearchManager::$plugin->apiKeys->generateKey($apiKey->type);
            $apiKey->keyHash = $generated['hash'];
            $apiKey->encryptedKey = $apiKey->type === ApiKey::TYPE_PUBLIC
                ? SearchManager::$plugin->apiKeys->encryptPlaintextKey($generated['plaintext'])
                : null;
            $apiKey->keyPrefix = $generated['prefix'];
        } else {
            $apiKey = ApiKey::findById($keyId);
            if ($apiKey === null) {
                throw new NotFoundHttpException(Craft::t('search-manager', 'API key not found'));
            }
            // Type is locked once generated — it's encoded in the keyPrefix and
            // changing it here would create a mismatch the next time a request
            // arrives. Form hides the field on edit; this is defence in depth.
        }

        $this->populateRestrictionsFromRequest($apiKey, $request);
        if ($isNew && $apiKey->handle !== '') {
            $apiKey->handle = SlugHandleHelper::makeUnique('{{%searchmanager_api_keys}}', 'handle', $apiKey->handle);
        }

        if (!$isNew && !$this->guardApiKeyWidgetDependenciesForSave($apiKey)) {
            Craft::$app->getSession()->setError(Craft::t('search-manager', 'Couldn’t save API key'));
            Craft::$app->getUrlManager()->setRouteParams([
                'apiKey' => $apiKey,
                'keyId' => $keyId,
            ]);
            return null;
        }

        if (!$apiKey->save()) {
            Craft::$app->getSession()->setError(Craft::t('search-manager', 'Couldn’t save API key'));
            // Re-render the form with the unsaved model so errors surface
            // beside their fields. Craft's runAction routes to the same view.
            Craft::$app->getUrlManager()->setRouteParams([
                'apiKey' => $apiKey,
                'keyId' => $keyId,
            ]);
            return null;
        }

        if ($isNew) {
            Craft::$app->getSession()->setFlash(self::FLASH_NEW_PLAINTEXT, $generated['plaintext']);
            Craft::$app->getSession()->setNotice(Craft::t('search-manager', 'API key created'));
        } else {
            Craft::$app->getSession()->setNotice(Craft::t('search-manager', 'API key saved'));
        }

        // New keys always land back on the edit page so the one-time
        // plaintext reveal banner has somewhere to render. Existing keys
        // honour the posted redirect so the Save / Save-and-continue
        // dropdown in the template controls the destination.
        if ($isNew) {
            return $this->redirect('search-manager/api-keys/edit/' . $apiKey->id);
        }

        return $this->redirectToPostedUrl($apiKey);
    }

    // =========================================================================
    // DELETE / REVOKE
    // =========================================================================

    public function actionDelete(?int $keyId = null): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('searchManager:revokeApiKeys');

        $request = Craft::$app->getRequest();
        $acceptsJson = $request->getAcceptsJson();

        $keyId ??= (int)$request->getBodyParam('keyId');
        if (!$keyId) {
            throw new NotFoundHttpException(Craft::t('search-manager', 'API key not found'));
        }

        $apiKey = ApiKey::findById($keyId);
        if ($apiKey === null) {
            throw new NotFoundHttpException(Craft::t('search-manager', 'API key not found'));
        }

        $usedConfigs = SearchManager::$plugin->widgetConfigs->findConfigsUsingApiKeyHandle($apiKey->handle);
        if ($usedConfigs !== []) {
            $errorMessage = Craft::t('search-manager', 'This API key is used by widget configs ({widgets}). Reassign or remove it from those widgets before deleting it.', [
                'widgets' => SearchManager::$plugin->widgetConfigs->formatWidgetDependencyNames($usedConfigs),
            ]);
            if ($acceptsJson) {
                return $this->asJson(['success' => false, 'error' => $errorMessage]);
            }
            Craft::$app->getSession()->setError($errorMessage);
            return $this->redirect('search-manager/api-keys');
        }

        if (!$apiKey->delete()) {
            $errorMessage = Craft::t('search-manager', 'Couldn’t revoke API key');
            if ($acceptsJson) {
                return $this->asJson(['success' => false, 'error' => $errorMessage]);
            }
            Craft::$app->getSession()->setError($errorMessage);
            return $this->redirect('search-manager/api-keys');
        }

        $successMessage = Craft::t('search-manager', 'API key revoked');
        if ($acceptsJson) {
            // Caller (e.g. row-action JS using Craft.sendActionRequest) handles
            // its own reload; returning a redirect would force the AJAX client
            // to render the index server-side just to throw it away.
            return $this->asJson(['success' => true, 'message' => $successMessage]);
        }
        Craft::$app->getSession()->setNotice($successMessage);
        return $this->redirect('search-manager/api-keys');
    }

    // =========================================================================
    // BULK ACTIONS
    // =========================================================================

    public function actionBulkEnable(): ?Response
    {
        return $this->runBulkSetEnabled(true);
    }

    public function actionBulkDisable(): ?Response
    {
        return $this->runBulkSetEnabled(false);
    }

    public function actionBulkDelete(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('searchManager:revokeApiKeys');

        $ids = $this->parseBulkIds(Craft::$app->getRequest()->getBodyParam('ids', []));
        $usedConfigs = $this->findWidgetConfigsUsingApiKeyIds($ids, false);
        if ($usedConfigs !== []) {
            return $this->respondToBulkResult(
                0,
                '',
                Craft::t('search-manager', 'Some selected API keys are used by widget configs ({widgets}). Reassign or remove them from those widgets before deleting them.', [
                    'widgets' => SearchManager::$plugin->widgetConfigs->formatWidgetDependencyNames($usedConfigs),
                ]),
            );
        }

        $deleted = SearchManager::$plugin->apiKeys->bulkDelete($ids);

        return $this->respondToBulkResult(
            $deleted,
            Craft::t('search-manager', '{count, plural, =1{1 API key revoked} other{# API keys revoked}}', ['count' => $deleted]),
            Craft::t('search-manager', 'Couldn’t revoke API keys'),
        );
    }

    private function runBulkSetEnabled(bool $enabled): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('searchManager:editApiKeys');

        $ids = $this->parseBulkIds(Craft::$app->getRequest()->getBodyParam('ids', []));
        if (!$enabled) {
            $usedConfigs = $this->findWidgetConfigsUsingApiKeyIds($ids, true);
            if ($usedConfigs !== []) {
                return $this->respondToBulkResult(
                    0,
                    '',
                    Craft::t('search-manager', 'Some selected API keys are used by widget configs ({widgets}). Reassign or remove them from those widgets before disabling them.', [
                        'widgets' => SearchManager::$plugin->widgetConfigs->formatWidgetDependencyNames($usedConfigs),
                    ]),
                );
            }
        }

        $affected = SearchManager::$plugin->apiKeys->bulkSetEnabled($ids, $enabled);

        $message = $enabled
            ? Craft::t('search-manager', '{count, plural, =1{1 API key enabled} other{# API keys enabled}}', ['count' => $affected])
            : Craft::t('search-manager', '{count, plural, =1{1 API key disabled} other{# API keys disabled}}', ['count' => $affected]);

        return $this->respondToBulkResult(
            $affected,
            $message,
            $enabled
                ? Craft::t('search-manager', 'Couldn’t enable API keys')
                : Craft::t('search-manager', 'Couldn’t disable API keys'),
        );
    }

    /**
     * @param array<mixed>|mixed $raw
     * @return int[]
     */
    private function parseBulkIds(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $ids = [];
        foreach ($raw as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $ids[] = (int) $value;
            }
        }
        return array_values(array_unique($ids));
    }

    private function respondToBulkResult(int $count, string $successMessage, string $emptyMessage): Response
    {
        $acceptsJson = Craft::$app->getRequest()->getAcceptsJson();

        if ($count > 0) {
            if ($acceptsJson) {
                return $this->asJson(['success' => true, 'count' => $count, 'message' => $successMessage]);
            }
            Craft::$app->getSession()->setNotice($successMessage);
            return $this->redirect('search-manager/api-keys');
        }

        if ($acceptsJson) {
            return $this->asJson(['success' => false, 'count' => 0, 'error' => $emptyMessage]);
        }
        Craft::$app->getSession()->setError($emptyMessage);
        return $this->redirect('search-manager/api-keys');
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function resolveType(mixed $raw): string
    {
        $value = is_string($raw) ? $raw : '';
        return in_array($value, ApiKey::TYPES, true) ? $value : ApiKey::TYPE_PUBLIC;
    }

    private function guardApiKeyWidgetDependenciesForSave(ApiKey $apiKey): bool
    {
        if ($apiKey->id === null || $apiKey->type !== ApiKey::TYPE_PUBLIC) {
            return true;
        }

        $existingApiKey = ApiKey::findById($apiKey->id);
        $dependencyHandle = $existingApiKey?->handle ?: $apiKey->handle;
        $usedConfigs = SearchManager::$plugin->widgetConfigs->findConfigsUsingApiKeyHandle($dependencyHandle);
        if ($usedConfigs === []) {
            return true;
        }

        $widgets = SearchManager::$plugin->widgetConfigs->formatWidgetDependencyNames($usedConfigs);
        if ($existingApiKey !== null && $existingApiKey->handle !== $apiKey->handle) {
            $apiKey->addError('handle', Craft::t('search-manager', 'This API key is used by widget configs ({widgets}). Reassign or remove it from those widgets before changing the handle.', [
                'widgets' => $widgets,
            ]));
        }

        if (!$apiKey->enabled) {
            $apiKey->addError('enabled', Craft::t('search-manager', 'This API key is used by widget configs ({widgets}). Reassign or remove it from those widgets before disabling it.', [
                'widgets' => $widgets,
            ]));
        }

        if ($apiKey->validUntil !== null && !$apiKey->isStillValid()) {
            $apiKey->addError('validUntil', Craft::t('search-manager', 'This API key is used by widget configs ({widgets}). Reassign or remove it from those widgets before expiring it.', [
                'widgets' => $widgets,
            ]));
        }

        $brokenConfigs = SearchManager::$plugin->widgetConfigs->findConfigsBrokenByApiKeyScope($apiKey);
        if ($brokenConfigs !== []) {
            $apiKey->addError('allowedIndices', Craft::t('search-manager', 'Selected widget indices must remain allowed by this API key ({widgets}).', [
                'widgets' => SearchManager::$plugin->widgetConfigs->formatWidgetDependencyNames($brokenConfigs),
            ]));
        }

        return !$apiKey->hasErrors();
    }

    /**
     * @param int[] $ids
     * @return \lindemannrock\searchmanager\models\WidgetConfig[]
     */
    private function findWidgetConfigsUsingApiKeyIds(array $ids, bool $publicOnly): array
    {
        $configsByKey = [];
        foreach ($ids as $id) {
            $apiKey = ApiKey::findById($id);
            if ($apiKey === null || ($publicOnly && $apiKey->type !== ApiKey::TYPE_PUBLIC)) {
                continue;
            }

            foreach (SearchManager::$plugin->widgetConfigs->findConfigsUsingApiKeyHandle($apiKey->handle) as $config) {
                $configsByKey[$config->source . ':' . ($config->id ?? $config->handle)] = $config;
            }
        }

        return array_values($configsByKey);
    }

    /**
     * Pull restriction fields from POST body into the model. Centralised so
     * create and edit normalize the same way and the parsing is testable
     * by exercising actionSave once.
     */
    private function populateRestrictionsFromRequest(ApiKey $apiKey, \craft\web\Request $request): void
    {
        $apiKey->name = trim((string)$request->getBodyParam('name', ''));
        $apiKey->handle = SlugHandleHelper::normalizeSlug(
            (string)$request->getBodyParam('handle'),
            $apiKey->name,
        );
        $apiKey->enabled = (bool)$request->getBodyParam('enabled', true);

        // Indices: "All indices" toggle (allowAll=1) → ['*']. Otherwise an
        // array of explicit handles. The form makes these mutually exclusive
        // on the client; this is the server-side enforcement.
        if ((bool)$request->getBodyParam('allowAllIndices', false)) {
            $apiKey->allowedIndices = [ApiKey::ALL_INDICES];
        } else {
            $rawIndices = $request->getBodyParam('allowedIndices', []);
            $apiKey->allowedIndices = is_array($rawIndices)
                ? array_values(array_filter(array_map('strval', $rawIndices), fn($h) => $h !== ''))
                : [];
        }

        // Referrers: textarea → array. Trim, lowercase, drop blanks. Pattern
        // shape is checked by the model's validateReferrerPatterns rule.
        $rawReferrers = (string)$request->getBodyParam('allowedReferrers', '');
        $referrers = [];
        foreach (preg_split('/\r\n|\r|\n/', $rawReferrers) ?: [] as $line) {
            $trimmed = strtolower(trim($line));
            if ($trimmed !== '') {
                $referrers[] = $trimmed;
            }
        }
        $apiKey->allowedReferrers = array_values(array_unique($referrers));

        // Optional numeric fields — empty input means null (no restriction).
        $apiKey->maxHitsPerPage = $this->parseOptionalInt($request->getBodyParam('maxHitsPerPage'));
        $apiKey->rateLimit = $this->parseOptionalInt($request->getBodyParam('rateLimit'));

        // Optional expiry — Craft's datetime picker submits an array {date, time}
        // or a single string. Use Craft's helper for consistent parsing.
        $apiKey->validUntil = \craft\helpers\DateTimeHelper::toDateTime($request->getBodyParam('validUntil')) ?: null;
    }

    private function parseOptionalInt(mixed $raw): ?int
    {
        if ($raw === null || $raw === '' || (is_string($raw) && trim($raw) === '')) {
            return null;
        }
        if (!is_numeric($raw)) {
            return null;
        }
        return (int)$raw;
    }

    /**
     * Sort the loaded keys array in PHP. Small dataset → array-side sort is fine.
     * Switch to SQL-side ORDER BY if the list ever balloons past a few hundred keys.
     *
     * @param ApiKey[] $keys
     * @return ApiKey[]
     */
    private function sortKeys(array $keys, string $sort, string $dir): array
    {
        $multiplier = $dir === 'desc' ? -1 : 1;

        usort($keys, function(ApiKey $a, ApiKey $b) use ($sort, $multiplier): int {
            $cmp = match ($sort) {
                'status' => strcmp($a->getStatus(), $b->getStatus()),
                'handle' => strcasecmp($a->handle, $b->handle),
                'type' => strcmp($a->type, $b->type),
                'allowedIndices' => count($a->allowedIndices) <=> count($b->allowedIndices),
                'validUntil' => $this->compareNullableDates($a->validUntil, $b->validUntil),
                'lastUsedAt' => $this->compareNullableDates($a->lastUsedAt, $b->lastUsedAt),
                default => strcasecmp($a->name, $b->name),
            };

            // Stable tie-break by name so equal primary keys don't shuffle
            // between requests — keeps pagination predictable.
            if ($cmp === 0 && $sort !== 'name') {
                $cmp = strcasecmp($a->name, $b->name);
            }

            return $cmp * $multiplier;
        });

        return $keys;
    }

    /**
     * Null-aware datetime comparison. Null sorts AFTER non-null in ascending
     * order ("Never" / "—" feels like a high value at the bottom), keeping
     * keys with real dates surfaced first.
     */
    private function compareNullableDates(?\DateTime $a, ?\DateTime $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return 1;
        }
        if ($b === null) {
            return -1;
        }
        return $a <=> $b;
    }
}
