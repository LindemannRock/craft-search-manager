<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\DateTimeHelper;
use lindemannrock\searchmanager\models\ApiKey;
use lindemannrock\searchmanager\models\SearchIndex;
use lindemannrock\searchmanager\SearchManager;
use yii\console\ExitCode;

/**
 * API Keys console commands
 *
 * Headless / CI bootstrap surface for the API Keys feature. The CP UI is the
 * normal admin path; this controller exists so automated provisioning can
 * create keys without a logged-in browser session.
 *
 * Example:
 *   php craft search-manager/api-keys/create \
 *     --name="Primary widget key" \
 *     --type=public \
 *     --indices=docs-en,blog-en \
 *     --referrers=example.com,*.example.com \
 *     --max-hits=50 \
 *     --rate-limit=120 \
 *     --valid-until=2027-12-31 \
 *     --disabled
 *
 * The plaintext key is written to stdout exactly once. The plugin's normal
 * logging path only ever sees the prefix (see ApiKey::save()).
 *
 * @since 5.46.0
 */
class ApiKeysController extends Controller
{
    /**
     * @var string Human-readable label for the key.
     */
    public string $name = '';

    /**
     * @var string Key type: `public` or `server`.
     */
    public string $type = ApiKey::TYPE_PUBLIC;

    /**
     * @var string Comma-separated index handles. Use `*` for "all indices".
     *   Empty string is valid only with `--disabled`, creating an incomplete
     *   draft key whose restrictions must be widened before it can be enabled.
     */
    public string $indices = '';

    /**
     * @var string Comma-separated allowed-referrer patterns.
     *   `example.com` for exact host, `*.example.com` for any subdomain depth.
     *   Empty string = all referrers allowed.
     */
    public string $referrers = '';

    /**
     * @var int|null Clamp on the `hitsPerPage` request parameter.
     */
    public ?int $maxHits = null;

    /**
     * @var int|null Per-key rate limit in requests per minute (slice 3).
     */
    public ?int $rateLimit = null;

    /**
     * @var string Optional expiry datetime in any format DateTimeHelper accepts.
     *   Empty string = never expires.
     */
    public string $validUntil = '';

    /**
     * @var bool Create the key in a disabled state. Default is enabled.
     */
    public bool $disabled = false;

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'create') {
            $options[] = 'name';
            $options[] = 'type';
            $options[] = 'indices';
            $options[] = 'referrers';
            $options[] = 'maxHits';
            $options[] = 'rateLimit';
            $options[] = 'validUntil';
            $options[] = 'disabled';
        }

        return $options;
    }

    /**
     * Map CLI option names with hyphens to their PHP property camelCase forms.
     */
    public function optionAliases(): array
    {
        return [
            'max-hits' => 'maxHits',
            'rate-limit' => 'rateLimit',
            'valid-until' => 'validUntil',
        ];
    }

    /**
     * Create a new API key.
     *
     * Outputs the plaintext key once and exits. The plaintext is never
     * logged to the plugin's normal log channel — only stdout in this
     * console context, which the operator explicitly opted into.
     */
    public function actionCreate(): int
    {
        if (trim($this->name) === '') {
            $this->stderr("--name is required.\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        if (!in_array($this->type, ApiKey::TYPES, true)) {
            $this->stderr("--type must be 'public' or 'server' (got '{$this->type}').\n", Console::FG_RED);
            return ExitCode::USAGE;
        }

        $apiKey = new ApiKey();
        $apiKey->name = trim($this->name);
        $apiKey->type = $this->type;
        $apiKey->enabled = !$this->disabled;
        $apiKey->allowedIndices = $this->parseIndices($this->indices);
        $apiKey->allowedReferrers = $this->parseReferrers($this->referrers);
        $apiKey->maxHitsPerPage = $this->maxHits;
        $apiKey->rateLimit = $this->rateLimit;

        $invalidIndices = $this->unknownIndexHandles($apiKey->allowedIndices);
        if (!empty($invalidIndices)) {
            $this->stderr("Unknown index handle(s): " . implode(', ', $invalidIndices) . "\n", Console::FG_RED);
            return ExitCode::DATAERR;
        }

        if ($this->validUntil !== '') {
            $parsed = DateTimeHelper::toDateTime($this->validUntil);
            if ($parsed === false) {
                $this->stderr("--valid-until could not be parsed as a datetime: '{$this->validUntil}'.\n", Console::FG_RED);
                return ExitCode::USAGE;
            }
            $apiKey->validUntil = $parsed;
        }

        $generated = SearchManager::$plugin->apiKeys->generateKey($apiKey->type);
        $apiKey->keyHash = $generated['hash'];
        $apiKey->encryptedKey = $apiKey->type === ApiKey::TYPE_PUBLIC
            ? SearchManager::$plugin->apiKeys->encryptPlaintextKey($generated['plaintext'])
            : null;
        $apiKey->keyPrefix = $generated['prefix'];

        if (!$apiKey->save()) {
            $this->stderr("Couldn't save API key. Validation errors:\n", Console::FG_RED);
            foreach ($apiKey->getErrors() as $field => $messages) {
                foreach ($messages as $msg) {
                    $this->stderr("  {$field}: {$msg}\n", Console::FG_RED);
                }
            }
            return ExitCode::DATAERR;
        }

        $this->stdout("✓ API key created.\n\n", Console::FG_GREEN);

        $this->stdout("  Key ID:           ", Console::FG_GREY);
        $this->stdout("{$apiKey->id}\n");
        $this->stdout("  Name:             ", Console::FG_GREY);
        $this->stdout("{$apiKey->name}\n");
        $this->stdout("  Type:             ", Console::FG_GREY);
        $this->stdout("{$apiKey->type}\n");
        $this->stdout("  Prefix:           ", Console::FG_GREY);
        $this->stdout("{$apiKey->keyPrefix}\n");
        $this->stdout("  Allowed indices:  ", Console::FG_GREY);
        $this->stdout($apiKey->allowsAllIndices()
            ? "All indices (*)\n"
            : (empty($apiKey->allowedIndices) ? "(none — key is not usable yet)\n" : implode(', ', $apiKey->allowedIndices) . "\n"));
        $this->stdout("  Allowed referrers:", Console::FG_GREY);
        $this->stdout(' ' . (empty($apiKey->allowedReferrers) ? "Any\n" : implode(', ', $apiKey->allowedReferrers) . "\n"));
        $this->stdout("  Max hits per page:", Console::FG_GREY);
        $this->stdout(' ' . ($apiKey->maxHitsPerPage !== null ? "{$apiKey->maxHitsPerPage}\n" : "(endpoint default)\n"));
        $this->stdout("  Rate limit:       ", Console::FG_GREY);
        $this->stdout($apiKey->rateLimit !== null ? "{$apiKey->rateLimit} RPM\n" : "(unlimited)\n");
        $this->stdout("  Valid until:      ", Console::FG_GREY);
        $this->stdout($apiKey->validUntil !== null
            ? $apiKey->validUntil->format('Y-m-d H:i') . "\n"
            : "Never\n");
        $this->stdout("  Enabled:          ", Console::FG_GREY);
        $this->stdout($apiKey->enabled ? "yes\n" : "no (disabled at creation)\n");

        if ($apiKey->enabled && $apiKey->type === ApiKey::TYPE_PUBLIC && empty($apiKey->allowedReferrers)) {
            $this->stdout("\nWarning: this enabled public key has no referrer restrictions.\n", Console::FG_YELLOW);
        }

        $this->stdout("\n🔑 Plaintext key — copy this now, it will never be shown again:\n\n", Console::FG_YELLOW);
        $this->stdout("    {$generated['plaintext']}\n\n", Console::FG_GREEN);
        $this->stdout("Search Manager stores only a hash. If you lose this value you will need to create a new key.\n", Console::FG_GREY);

        return ExitCode::OK;
    }

    /**
     * Parse the `--indices` CLI value into a list of handles, honouring the
     * `*` wildcard shortcut and stripping whitespace from CSV entries.
     *
     * @return string[]
     */
    private function parseIndices(string $raw): array
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return [];
        }
        if ($trimmed === ApiKey::ALL_INDICES) {
            return [ApiKey::ALL_INDICES];
        }
        return array_values(array_filter(
            array_map('trim', explode(',', $trimmed)),
            fn(string $h): bool => $h !== '',
        ));
    }

    /**
     * Parse `--referrers` CSV into the same normalised form the CP textarea
     * produces (trim, lowercase, dedupe, drop blanks).
     *
     * @return string[]
     */
    private function parseReferrers(string $raw): array
    {
        $items = array_map(
            fn(string $r): string => strtolower(trim($r)),
            explode(',', $raw),
        );
        return array_values(array_unique(array_filter($items, fn(string $r): bool => $r !== '')));
    }

    /**
     * Return explicit index handles that do not resolve to a configured index.
     *
     * @param string[] $handles
     * @return string[]
     */
    private function unknownIndexHandles(array $handles): array
    {
        if (empty($handles) || in_array(ApiKey::ALL_INDICES, $handles, true)) {
            return [];
        }

        $knownHandles = array_fill_keys(
            array_map(static fn(SearchIndex $index): string => $index->handle, SearchIndex::findAll()),
            true,
        );

        $unknown = array_values(array_filter(
            $handles,
            static fn(string $handle): bool => !isset($knownHandles[$handle])
        ));

        return $unknown;
    }
}
