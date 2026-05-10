<?php

declare(strict_types=1);

class Hirale_AsyncIndex_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const XML_PATH_PREFIX = 'hirale_asyncindex/settings/';
    public const REGISTRY_DRAIN_CONTEXT = 'hirale_asyncindex_drain_context';
    public const REGISTRY_FULL_REINDEX_CONTEXT = 'hirale_asyncindex_full_reindex_context';
    public const CACHE_KICK_PENDING = 'hirale_asyncindex_kick_pending';

    public function isEnabled(): bool
    {
        return $this->getFlag('enabled');
    }

    public function isQueueEnabled(): bool
    {
        try {
            $helper = Mage::helper('hirale_queue');
            return is_object($helper) && method_exists($helper, 'getRedis');
        } catch (Throwable $e) {
            return false;
        }
    }

    public function shouldRunAsync(): bool
    {
        return $this->isEnabled() && $this->isQueueEnabled() && !$this->isAsyncContext();
    }

    public function isAsyncContext(): bool
    {
        return $this->isDrainContext() || $this->isFullReindexContext();
    }

    public function isDrainContext(): bool
    {
        return (bool) Mage::registry(self::REGISTRY_DRAIN_CONTEXT);
    }

    public function isFullReindexContext(): bool
    {
        return (bool) Mage::registry(self::REGISTRY_FULL_REINDEX_CONTEXT);
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function withDrainContext(callable $callback)
    {
        if ($this->isDrainContext()) {
            return $callback();
        }

        Mage::register(self::REGISTRY_DRAIN_CONTEXT, true);
        try {
            return $callback();
        } finally {
            Mage::unregister(self::REGISTRY_DRAIN_CONTEXT);
        }
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    public function withFullReindexContext(callable $callback)
    {
        if ($this->isFullReindexContext()) {
            return $callback();
        }

        Mage::register(self::REGISTRY_FULL_REINDEX_CONTEXT, true);
        try {
            return $callback();
        } finally {
            Mage::unregister(self::REGISTRY_FULL_REINDEX_CONTEXT);
        }
    }

    public function getFlag(string $field): bool
    {
        return Mage::getStoreConfigFlag(self::XML_PATH_PREFIX . $field);
    }

    public function getInt(string $field, int $default, int $minimum = 1): int
    {
        $value = (int) Mage::getStoreConfig(self::XML_PATH_PREFIX . $field);
        if ($value < $minimum) {
            return $default;
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function enqueueDrain(array $payload = [], bool $force = false): bool
    {
        $payload['action'] ??= 'drain_events';

        if (!$this->isEnabled() || !$this->isQueueEnabled()) {
            return false;
        }

        if (!$force && !$this->markKickPending()) {
            return false;
        }

        return $this->enqueueTask($payload, [
            'timeout' => $this->getInt('max_runtime_seconds', 45) + 15,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     */
    public function enqueueTask(array $payload, array $options = []): bool
    {
        if (!$this->isEnabled() || !$this->isQueueEnabled()) {
            return false;
        }

        try {
            $task = Mage::getModel('hirale_queue/task');
            if (!is_object($task) || !method_exists($task, 'addTask')) {
                return false;
            }

            $queueOptions = [
                'retry_count' => $this->getInt('max_attempts', 3),
                'retry_delay' => $this->getInt('retry_delay', 60, 0),
                'timeout' => $this->getInt('full_max_runtime_seconds', 45) + 15,
            ];

            $queueOptions = array_replace($queueOptions, $options);
            if (array_key_exists('max_attempts', $queueOptions)) {
                $queueOptions['retry_count'] = $queueOptions['max_attempts'];
            }

            $task->addTask(
                Hirale_AsyncIndex_Model_QueueHandler::class,
                $payload,
                (int) $queueOptions['retry_count'],
                (int) $queueOptions['retry_delay'],
                (int) $queueOptions['timeout'],
            );

            return true;
        } catch (Throwable $e) {
            $this->logException($e);
            return false;
        }
    }

    public function markKickPending(): bool
    {
        try {
            if (Mage::app()->loadCache(self::CACHE_KICK_PENDING)) {
                return false;
            }

            Mage::app()->saveCache(
                '1',
                self::CACHE_KICK_PENDING,
                [],
                $this->getInt('coalesce_ttl_seconds', 10),
            );
        } catch (Throwable $e) {
            return true;
        }

        return true;
    }

    public function clearKickPending(): void
    {
        try {
            Mage::app()->removeCache(self::CACHE_KICK_PENDING);
        } catch (Throwable $e) {
            $this->logException($e);
        }
    }

    public function logException(Throwable $e): void
    {
        if (class_exists('Mage')) {
            Mage::logException($e);
        }
    }
}
