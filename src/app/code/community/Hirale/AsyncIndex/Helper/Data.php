<?php

declare(strict_types=1);

class Hirale_AsyncIndex_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const XML_PATH_PREFIX = 'hirale_asyncindex/settings/';
    public const REGISTRY_DRAIN_CONTEXT = 'hirale_asyncindex_drain_context';
    public const REGISTRY_FULL_REINDEX_CONTEXT = 'hirale_asyncindex_full_reindex_context';

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

    /**
     * Operator-configured queue name that full-reindex tasks should publish
     * onto. Empty (default) means use the queue's default queue. Set this to
     * isolate long-running batches from real-time drain work — but be sure to
     * also add the queue name to the queue module's `queues_csv` so a worker
     * actually polls it.
     */
    public function getFullReindexQueueName(): string
    {
        return trim((string) Mage::getStoreConfig(self::XML_PATH_PREFIX . 'full_reindex_queue'));
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
     * Enqueue a drain task. Multiple calls within the same coalesce window
     * collapse onto a single queue job via a stable, time-bucketed job_id;
     * `$force` skips that dedup and is used by the drain runner's own
     * continuation, which must always fire even if it falls in the same bucket
     * as the drain that just completed.
     *
     * @param array<string, mixed> $payload
     */
    public function enqueueDrain(array $payload = [], bool $force = false): bool
    {
        $payload['action'] ??= 'drain_events';

        if (!$this->isEnabled() || !$this->isQueueEnabled()) {
            return false;
        }

        $options = [
            'timeout' => $this->getInt('max_runtime_seconds', 45) + 15,
        ];
        if (!$force) {
            $options['job_id'] = $this->_drainJobId();
        }

        return $this->enqueueTask($payload, $options);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options Queue::enqueue options — queue, delay, max_attempts, retry_delay, timeout, job_id, metadata
     */
    public function enqueueTask(array $payload, array $options = []): bool
    {
        if (!$this->isEnabled() || !$this->isQueueEnabled()) {
            return false;
        }

        try {
            $queue = Mage::getModel('hirale_queue/queue');
            if (!is_object($queue) || !method_exists($queue, 'enqueue')) {
                return false;
            }

            $defaults = [
                'max_attempts' => $this->getInt('max_attempts', 3),
                'retry_delay' => $this->getInt('retry_delay', 60, 0),
                'timeout' => $this->getInt('full_max_runtime_seconds', 45) + 15,
            ];

            $queue->enqueue(
                Hirale_AsyncIndex_Model_QueueHandler::class,
                $payload,
                array_replace($defaults, $options),
            );

            return true;
        } catch (Throwable $e) {
            if ($this->_isDuplicateJobId($e)) {
                return false;
            }
            $this->logException($e);
            return false;
        }
    }

    public function logException(Throwable $e): void
    {
        if (class_exists('Mage')) {
            Mage::logException($e);
        }
    }

    private function _drainJobId(): string
    {
        $bucket = $this->getInt('coalesce_ttl_seconds', 10);
        return 'hirale_asyncindex_drain_' . (int) floor(time() / $bucket);
    }

    private function _isDuplicateJobId(Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'Duplicate entry') || str_contains($message, 'SQLSTATE[23000]');
    }
}
