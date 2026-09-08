<?php

declare(strict_types=1);

use Hirale\Queue\Bus;
use Maho\Queue\QueueManager;
use Maho\Queue\Stamp\DedupeKeyStamp;

class Hirale_AsyncIndex_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const XML_PATH_PREFIX = 'hirale_asyncindex/settings/';
    public const REGISTRY_DRAIN_CONTEXT = 'hirale_asyncindex_drain_context';
    public const REGISTRY_FULL_REINDEX_CONTEXT = 'hirale_asyncindex_full_reindex_context';

    /** Suppresses a second drain while one is pending or processing. Maho only — hirale/queue v3 has no dispatch-time dedup. */
    public const DRAIN_DEDUPE_KEY = 'hirale_asyncindex_drain';

    /** Queue full-reindex batches land on when no override is configured; config.xml routes it off the fast pool. */
    public const QUEUE_FULL_REINDEX = 'full_reindex';

    private const DISPATCHER_MAHO = 'maho';
    private const DISPATCHER_HIRALE = 'hirale';
    private const DISPATCHER_NONE = 'none';

    /** Mirrors \Maho\Queue\Transport\DbTransport::DEFAULT_QUEUE, which is not loadable on OpenMage. */
    private const QUEUE_DEFAULT = 'default';

    public function isEnabled(): bool
    {
        return $this->getFlag('enabled');
    }

    public function isQueueEnabled(): bool
    {
        return $this->_resolveDispatcher() !== self::DISPATCHER_NONE;
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
     * Operator-configured queue name for full-reindex batches. Empty means the
     * platform default: a dedicated `full_reindex` queue on Maho, the routing
     * in the queue module's config.xml on OpenMage. Set a non-empty name to
     * isolate long-running batches from real-time drain work.
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
     * Dispatch a drain message onto whichever queue backend this install has.
     *
     * Multiple drains over an already-empty event table are idempotent
     * (Runner::drain returns immediately when no work is found), so the
     * dedupe key is an optimisation, not a correctness requirement.
     */
    public function enqueueDrain(
        string $reason,
        ?int $eventId = null,
        ?string $entity = null,
        ?string $type = null,
    ): bool {
        if (!$this->isEnabled()) {
            return false;
        }

        $dispatcher = $this->_resolveDispatcher();
        if ($dispatcher === self::DISPATCHER_NONE) {
            return false;
        }

        try {
            $this->_dispatch(
                $dispatcher,
                new Hirale_AsyncIndex_Message_DrainEventsMessage(
                    reason: $reason,
                    eventId: $eventId,
                    entity: $entity,
                    type: $type,
                ),
                dedupeKey: self::DRAIN_DEDUPE_KEY,
                enforceDedupe: !$this->isDrainContext(),
            );
            return true;
        } catch (Throwable $e) {
            $this->logException($e);
            return false;
        }
    }

    /**
     * Dispatch a full-reindex batch message. Batches of different runs must
     * never suppress each other, so these carry no dedupe key.
     */
    public function enqueueFullReindexBatch(int $runId, int $delaySeconds = 0): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $dispatcher = $this->_resolveDispatcher();
        if ($dispatcher === self::DISPATCHER_NONE) {
            return false;
        }

        try {
            $this->_dispatch(
                $dispatcher,
                new Hirale_AsyncIndex_Message_FullReindexBatchMessage($runId),
                $this->_fullReindexQueue($dispatcher),
                $delaySeconds,
            );
            return true;
        } catch (Throwable $e) {
            $this->logException($e);
            return false;
        }
    }

    /**
     * Which queue backend this install dispatches through. Maho's core queue
     * wins when the platform ships it, so a Maho store needs no third-party
     * queue package at all; hirale/queue remains the OpenMage backend.
     */
    private function _resolveDispatcher(): string
    {
        if ($this->_isMahoQueueAvailable()) {
            return self::DISPATCHER_MAHO;
        }

        if ($this->_isHiraleQueueAvailable()) {
            return self::DISPATCHER_HIRALE;
        }

        return self::DISPATCHER_NONE;
    }

    private function _isMahoQueueAvailable(): bool
    {
        if (!class_exists(QueueManager::class)) {
            return false;
        }

        $core = Mage::helper('core');

        return $core instanceof Mage_Core_Helper_Abstract && $core->isModuleEnabled('Maho_Queue');
    }

    /** Protected only so the unit suite can simulate an install with no queue package at all. */
    protected function _isHiraleQueueAvailable(): bool
    {
        return class_exists(Bus::class);
    }

    /**
     * Queue for full-reindex batches: the admin override when set, otherwise
     * a dedicated queue on Maho, where queue names are free-form and the
     * routing in config.xml keeps batches off the latency-sensitive pool.
     * hirale/queue only knows admin-declared queues, so an unset override
     * there has to fall back to that module's own routing.
     */
    private function _fullReindexQueue(string $dispatcher): ?string
    {
        $configured = $this->getFullReindexQueueName();
        if ($configured !== '') {
            return $configured;
        }

        return $dispatcher === self::DISPATCHER_MAHO ? self::QUEUE_FULL_REINDEX : null;
    }

    /**
     * @param ?string $queue         explicit queue name; null uses the backend's own default routing
     * @param ?string $dedupeKey     Maho only; hirale/queue v3 has no dispatch-time dedup
     * @param bool    $enforceDedupe false lets a handler continue its own chain past the dedupe check
     */
    private function _dispatch(
        string $dispatcher,
        object $message,
        ?string $queue = null,
        int $delaySeconds = 0,
        ?string $dedupeKey = null,
        bool $enforceDedupe = true,
    ): void {
        if ($dispatcher === self::DISPATCHER_MAHO) {
            $stamps = [];
            if ($dedupeKey !== null && !$enforceDedupe) {
                // The handler's own row is still processing under this key and
                // would swallow the continuation, yet the key has to stay on the
                // new message so outside dispatchers keep seeing the chain.
                $stamps[] = new DedupeKeyStamp($dedupeKey, enforce: false);
                $dedupeKey = null;
            }

            QueueManager::dispatch(
                message: $message,
                delaySeconds: $delaySeconds > 0 ? $delaySeconds : null,
                queue: $queue ?? self::QUEUE_DEFAULT,
                dedupeKey: $dedupeKey,
                stamps: $stamps,
            );
            return;
        }

        $stamps = $delaySeconds > 0
            ? [new \Symfony\Component\Messenger\Stamp\DelayStamp($delaySeconds * 1000)]
            : [];

        if ($queue !== null) {
            Bus::dispatchOnQueue($message, $queue, $stamps);
        } elseif ($delaySeconds > 0) {
            Bus::dispatchDelayed($message, $delaySeconds);
        } else {
            Bus::dispatch($message);
        }
    }

    /**
     * Acquire the mutual-exclusion lock that serializes drain / full-reindex
     * work. Dual-platform: OpenMage ships Mage_Index_Model_Lock (static
     * getInstance/setLock/releaseLock); Maho removed it in favour of
     * Mage_Core_Model_Lock (core/lock singleton, acquire/release). Non-blocking
     * — returns false when the lock is already held.
     */
    public function acquireIndexLock(string $name): bool
    {
        if (class_exists('Mage_Index_Model_Lock')) {
            return Mage_Index_Model_Lock::getInstance()->setLock($name);
        }

        return $this->_getCoreLock()->acquire($name);
    }

    public function releaseIndexLock(string $name): void
    {
        if (class_exists('Mage_Index_Model_Lock')) {
            Mage_Index_Model_Lock::getInstance()->releaseLock($name);
            return;
        }

        $this->_getCoreLock()->release($name);
    }

    private function _getCoreLock(): Mage_Core_Model_Lock
    {
        $lock = Mage::getSingleton('core/lock');
        if (!$lock instanceof Mage_Core_Model_Lock) {
            throw new RuntimeException('Maho core/lock model is unavailable.');
        }

        return $lock;
    }

    public function logException(Throwable $e): void
    {
        if (class_exists('Mage')) {
            Mage::logException($e);
        }
    }
}
