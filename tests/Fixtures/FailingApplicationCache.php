<?php
/**
 * Search Manager plugin for Craft CMS 5.x
 *
 * @link      https://lindemannrock.com
 * @copyright Copyright (c) 2026 LindemannRock
 */

declare(strict_types=1);

namespace lindemannrock\searchmanager\tests\Fixtures;

use yii\caching\Cache;

/**
 * Owned persistent cache double with selective generation-write failures.
 *
 * @since 5.55.0
 */
final class FailingApplicationCache extends Cache
{
    /** @var array<string, mixed> */
    private array $values = [];

    /** @var list<string> */
    public array $setKeys = [];

    /** @var list<string> */
    public array $deleteKeys = [];

    /** @var list<string> */
    public array $failingSetKeyFragments = [];

    public int $flushCalls = 0;

    public function set($key, $value, $duration = null, $dependency = null)
    {
        $key = (string)$key;
        $this->setKeys[] = $key;
        foreach ($this->failingSetKeyFragments as $fragment) {
            if (str_contains($key, $fragment)) {
                return false;
            }
        }

        return parent::set($key, $value, $duration, $dependency);
    }

    public function delete($key)
    {
        $this->deleteKeys[] = (string)$key;

        return parent::delete($key);
    }

    public function flush()
    {
        $this->flushCalls++;

        return parent::flush();
    }

    protected function getValue($key)
    {
        return $this->values[$key] ?? false;
    }

    protected function getValues($keys)
    {
        return array_map(fn(string $key): mixed => $this->getValue($key), $keys);
    }

    protected function setValue($key, $value, $duration)
    {
        $this->values[$key] = $value;

        return true;
    }

    protected function setValues($data, $duration)
    {
        foreach ($data as $key => $value) {
            $this->values[$key] = $value;
        }

        return [];
    }

    protected function addValue($key, $value, $duration)
    {
        if (array_key_exists($key, $this->values)) {
            return false;
        }
        $this->values[$key] = $value;

        return true;
    }

    protected function deleteValue($key)
    {
        unset($this->values[$key]);

        return true;
    }

    protected function flushValues()
    {
        $this->values = [];

        return true;
    }
}
