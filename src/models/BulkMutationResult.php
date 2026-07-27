<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\models;

use Craft;
use yii\base\Model;

/**
 * Normalized identifiers and observable outcomes for a bulk mutation.
 *
 * @since 5.54.0
 */
final class BulkMutationResult
{
    /**
     * @var list<int>
     */
    private array $identifiers = [];

    private int $count = 0;

    private int $skipped = 0;

    /**
     * @var list<string>
     */
    private array $errors = [];

    private function __construct()
    {
    }

    public static function fromIdentifiers(mixed $rawIdentifiers): self
    {
        $result = new self();

        if (!is_array($rawIdentifiers) || !array_is_list($rawIdentifiers)) {
            $result->addError(Craft::t('search-manager', 'Invalid data type'));
            return $result;
        }

        $identifiers = [];
        foreach ($rawIdentifiers as $identifier) {
            if (is_int($identifier)) {
                $normalized = $identifier;
            } elseif (is_string($identifier) && ctype_digit($identifier)) {
                $normalized = filter_var(
                    $identifier,
                    FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 1]],
                );
            } else {
                $result->addError(Craft::t('search-manager', 'Invalid data type'));
                return $result;
            }

            if (!is_int($normalized) || $normalized < 1) {
                $result->addError(Craft::t('search-manager', 'Invalid data type'));
                return $result;
            }

            $identifiers[$normalized] = $normalized;
        }

        $result->identifiers = array_values($identifiers);
        return $result;
    }

    /**
     * @return list<int>
     */
    public function identifiers(): array
    {
        return $this->identifiers;
    }

    public function canMutate(): bool
    {
        return $this->errors === [];
    }

    public function addSuccess(int $count = 1): void
    {
        $this->count += $count;
    }

    public function addSkip(int $count = 1): void
    {
        $this->skipped += $count;
    }

    public function addError(string $error): void
    {
        $error = trim($error);
        if ($error !== '') {
            $this->errors[] = $error;
        }
    }

    public function addNamedError(string $resourceName, string $error): void
    {
        $this->addError(sprintf('%s: %s', $resourceName, $error));
    }

    public function addModelErrors(string $resourceName, Model $model, string $fallback): void
    {
        $errors = $model->getErrorSummary(true);
        if ($errors === []) {
            $this->addNamedError($resourceName, $fallback);
            return;
        }

        foreach ($errors as $error) {
            $this->addNamedError($resourceName, $error);
        }
    }

    public function count(): int
    {
        return $this->count;
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * @return array{status: 'success'|'partial'|'failure', success: bool, count: int, skipped: int, errors: list<string>}
     */
    public function toArray(): array
    {
        $status = match (true) {
            $this->count === 0 => 'failure',
            $this->errors !== [] => 'partial',
            default => 'success',
        };

        return [
            'status' => $status,
            'success' => $status === 'success',
            'count' => $this->count,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
        ];
    }
}
