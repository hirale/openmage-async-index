<?php

declare(strict_types=1);

use Hirale\Queue\Bus;

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
        // v3 check: the Bus class autoloads when hirale/queue is installed.
        return class_exists(Bus::class);
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
     * Operator-configured queue name for full-reindex batches. Empty means
     * use the routing in the queue module's config.xml (default queue). Set
     * a non-empty name to route FullReindexBatchMessage onto a dedicated
     * queue and isolate long-running batches from real-time drain work.
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
     * Dispatch a drain message via the queue bus.
     *
     * v3 does NOT coalesce drain dispatches — multiple drains over an
     * already-empty event table are idempotent (Runner::drain returns
     * immediately when no work is found).
     */
    public function enqueueDrain(
        string $reason,
        ?int $eventId = null,
        ?string $entity = null,
        ?string $type = null,
    ): bool {
        if (!$this->isEnabled() || !$this->isQueueEnabled()) {
            return false;
        }
        try {
            Bus::dispatch(new Hirale_AsyncIndex_Message_DrainEventsMessage(
                reason: $reason,
                eventId: $eventId,
                entity: $entity,
                type: $type,
            ));
            return true;
        } catch (Throwable $e) {
            $this->logException($e);
            return false;
        }
    }

    /**
     * Dispatch a full-reindex batch message. If the admin has set a non-empty
     * `full_reindex_queue`, routes onto that queue; otherwise uses the routing
     * in the queue module's config.xml.
     */
    public function enqueueFullReindexBatch(int $runId, int $delaySeconds = 0): bool
    {
        if (!$this->isEnabled() || !$this->isQueueEnabled()) {
            return false;
        }
        try {
            $message   = new Hirale_AsyncIndex_Message_FullReindexBatchMessage($runId);
            $queueName = $this->getFullReindexQueueName();
            $stamps    = $delaySeconds > 0
                ? [new \Symfony\Component\Messenger\Stamp\DelayStamp($delaySeconds * 1000)]
                : [];

            if ($queueName !== '') {
                Bus::dispatchOnQueue($message, $queueName, $stamps);
            } elseif ($delaySeconds > 0) {
                Bus::dispatchDelayed($message, $delaySeconds);
            } else {
                Bus::dispatch($message);
            }
            return true;
        } catch (Throwable $e) {
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
}
