<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

namespace lindemannrock\searchmanager\controllers;

use Craft;
use craft\web\Response;

/**
 * Shared all-or-nothing controller helper for bulk delete actions.
 *
 * @since 5.53.0
 */
trait BulkDeleteTrait
{
    /**
     * @param array<mixed> $ids
     * @param callable(mixed): ?object $resolve
     * @param callable(object): ?string $vet
     * @param callable(object): bool $delete
     */
    protected function bulkDeleteAllOrNothing(array $ids, callable $resolve, callable $vet, callable $delete): Response
    {
        $entities = [];
        $errors = [];

        foreach ($ids as $id) {
            $entity = $resolve($id);
            if ($entity === null) {
                continue;
            }

            $error = $vet($entity);
            if ($error !== null) {
                $errors[] = $error;
                continue;
            }

            $entities[] = $entity;
        }

        if ($errors !== []) {
            return $this->bulkDeleteErrorResponse($errors);
        }

        $count = 0;
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            foreach ($entities as $entity) {
                if ($delete($entity)) {
                    $count++;
                    continue;
                }

                $errors[] = Craft::t('search-manager', 'Unknown error');
            }

            if ($errors !== []) {
                $transaction->rollBack();
                return $this->bulkDeleteErrorResponse($errors);
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $this->bulkDeleteJsonResponse(['success' => true, 'count' => $count]);
    }

    /**
     * @param list<string> $errors
     */
    private function bulkDeleteErrorResponse(array $errors): Response
    {
        return $this->bulkDeleteJsonResponse([
            'success' => false,
            'error' => implode(' ', $errors),
            'errors' => $errors,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function bulkDeleteJsonResponse(array $data): Response
    {
        $response = $this->asJson($data);
        if (!$response instanceof Response) {
            throw new \RuntimeException('Unexpected JSON response type.');
        }

        return $response;
    }
}
