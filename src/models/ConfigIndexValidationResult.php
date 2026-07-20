<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\models;

/**
 * Structured result from config-index validation.
 *
 * @since 5.54.0
 */
final class ConfigIndexValidationResult
{
    public const SEVERITY_ERROR = 'error';
    public const SEVERITY_WARNING = 'warning';

    public const STATUS_ABSENT = 'absent';
    public const STATUS_PRESENT = 'present';
    public const STATUS_INVALID_SECTION = 'invalid-section';
    public const STATUS_INVALID_ROOT = 'invalid-root';
    public const STATUS_LOAD_FAILURE = 'load-failure';

    /** @var list<array{severity: string, handle: string|null, key: string, message: string, emphasis: string|null}> */
    private array $findings = [];

    /** @var list<string> */
    private array $runtimeOnlyCriteriaHandles = [];

    public function __construct(public readonly string $sectionStatus)
    {
    }

    public function addFinding(
        ?string $handle,
        string $severity,
        string $key,
        string $message,
        ?string $emphasis = null,
    ): void {
        $this->findings[] = [
            'severity' => $severity,
            'handle' => $handle,
            'key' => $key,
            'message' => $message,
            'emphasis' => $emphasis,
        ];
    }

    /** @return list<array{severity: string, handle: string|null, key: string, message: string, emphasis: string|null}> */
    public function getFindings(): array
    {
        return $this->findings;
    }

    /**
     * @return list<array{
     *   handle: string|null,
     *   severity: string,
     *   findings: list<array{severity: string, handle: string|null, key: string, message: string, emphasis: string|null}>
     * }>
     */
    public function getFindingGroups(): array
    {
        $groups = [];
        foreach ($this->findings as $finding) {
            $groupKey = $finding['handle'] === null
                ? 'config'
                : 'index:' . $finding['handle'];

            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'handle' => $finding['handle'],
                    'severity' => self::SEVERITY_WARNING,
                    'findings' => [],
                ];
            }

            $groups[$groupKey]['findings'][] = $finding;
            if ($finding['severity'] === self::SEVERITY_ERROR) {
                $groups[$groupKey]['severity'] = self::SEVERITY_ERROR;
            }
        }

        return array_values($groups);
    }

    public function markCriteriaRuntimeOnly(string $handle): void
    {
        if (!in_array($handle, $this->runtimeOnlyCriteriaHandles, true)) {
            $this->runtimeOnlyCriteriaHandles[] = $handle;
        }
    }

    /** @return list<string> */
    public function getRuntimeOnlyCriteriaHandles(): array
    {
        return $this->runtimeOnlyCriteriaHandles;
    }

    /**
     * @return list<array{severity: string, handle: string|null, key: string, message: string, emphasis: string|null}>
     */
    public function getFindingsForHandle(string $handle, bool $includeGlobal = true): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn(array $finding): bool => $finding['handle'] === $handle
                || ($includeGlobal && $finding['handle'] === null),
        ));
    }

    public function hasErrors(?string $handle = null): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding['severity'] !== self::SEVERITY_ERROR) {
                continue;
            }

            if ($handle === null || $finding['handle'] === null || $finding['handle'] === $handle) {
                return true;
            }
        }

        return false;
    }
}
