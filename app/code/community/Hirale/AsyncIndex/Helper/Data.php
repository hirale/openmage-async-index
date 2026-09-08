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

    /** Per-run prefix: batches of different runs must never suppress each other. */
    public const FULL_RUN_DEDUPE_PREFIX = 'hirale_asyncindex_full_run_';

    /** Queue drain messages land on, so a host can route them without moving core's shared default queue. */
    public const QUEUE_DRAIN = 'index_drain';

    /** Keeps async index noise out of system.log on both platforms. */
    public const LOG_FILE = 'asyncindex.log';

    /**
     * How long a backend lets a worker hold one message before it acts on it.
     *
     * Maho: \Maho\Queue\Transport\DbTransport::ABANDONED_AFTER_SECONDS. Track
     * that constant if core changes it. hirale/queue: the Symfony Redis
     * transport's redeliver_timeout, which it leaves at the 3600s default.
     */
    public const MAHO_CLAIM_TIMEOUT_SECONDS = 300;
    public const HIRALE_CLAIM_TIMEOUT_SECONDS = 3600;

    /** Warn this far short of the timeout, so the warning arrives before the deadline does. */
    public const CLAIM_TIMEOUT_MARGIN_SECONDS = 60;

    /**
     * Fired once per drain and once per full-reindex batch, after the index
     * lock is released. Hosts bind an observer here to invalidate whatever
     * they cache on top of the index — nothing else in the async path does,
     * and the synchronous purge core runs on save already happened, back when
     * the index was still stale.
     */
    public const EVENT_REINDEX_AFTER = 'hirale_asyncindex_reindex_after';

    /**
     * Entities the built-in cache fallback knows a tag for. Everything else
     * still reaches observers; only this module's own cleanCache is limited.
     */
    private const CACHE_TAG_ENTITIES = [
        'catalog_product' => 'catalog_product',
        'catalog_category' => 'catalog_category',
    ];

    /**
     * Syslog severities, the one log level both platforms accept. Mage's own
     * log-level constants are unusable here: OpenMage declares none at all, and
     * on Maho they are Monolog enum cases rather than ints. A plain int is what
     * both then handle — Mage_Core_Model_Logger::convertLogLevel maps 5 and 6
     * to Notice and Info, OpenMage compares it against dev/log/max_level.
     */
    public const LOG_LEVEL_WARNING = 4;
    public const LOG_LEVEL_NOTICE = 5;
    public const LOG_LEVEL_INFO = 6;

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
     *
     * @param bool $dedupe false dispatches without a key at all. The reconciler
     *                     needs that: a worker killed mid-drain leaves its row
     *                     processing for the five minutes it takes the queue to
     *                     call the claim abandoned, and an enforced key would
     *                     suppress the very dispatch meant to recover from it.
     *                     Its own once-a-minute schedule is the rate limit.
     */
    public function enqueueDrain(
        string $reason,
        ?int $eventId = null,
        ?string $entity = null,
        ?string $type = null,
        bool $dedupe = true,
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
                $this->_drainQueue($dispatcher),
                dedupeKey: $dedupe ? self::DRAIN_DEDUPE_KEY : null,
                enforceDedupe: !$this->isDrainContext(),
            );
            return true;
        } catch (Throwable $e) {
            $this->logException($e);
            return false;
        }
    }

    /**
     * Dispatch a full-reindex batch message. The key is per run, so batches of
     * different runs never suppress each other while the once-a-minute
     * reconciler and the batch chain both keep enqueueing the active one.
     *
     * @param bool $continuation true when the handler is continuing its own run
     */
    public function enqueueFullReindexBatch(int $runId, int $delaySeconds = 0, bool $continuation = false): bool
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
                self::fullRunDedupeKey($runId),
                !$continuation,
            );
            return true;
        } catch (Throwable $e) {
            $this->logException($e);
            return false;
        }
    }

    public static function fullRunDedupeKey(int $runId): string
    {
        return self::FULL_RUN_DEDUPE_PREFIX . $runId;
    }

    /**
     * How long one batch may run before its backend acts on the claim. Zero
     * when there is no queue at all, which means never warn.
     */
    public function getClaimTimeoutSeconds(): int
    {
        return match ($this->_resolveDispatcher()) {
            self::DISPATCHER_MAHO => self::MAHO_CLAIM_TIMEOUT_SECONDS,
            self::DISPATCHER_HIRALE => self::HIRALE_CLAIM_TIMEOUT_SECONDS,
            default => 0,
        };
    }

    /** Null when there is no queue to hold a claim in the first place. */
    public function getBatchWarnSeconds(): ?int
    {
        $timeout = $this->getClaimTimeoutSeconds();

        return $timeout === 0 ? null : max(1, $timeout - self::CLAIM_TIMEOUT_MARGIN_SECONDS);
    }

    /**
     * What the backend actually does past that point. The two differ enough
     * that one sentence would be wrong on one of them: Maho only flags the
     * message, while hirale/queue hands it to another consumer.
     */
    public function getClaimTimeoutNote(): string
    {
        return match ($this->_resolveDispatcher()) {
            self::DISPATCHER_MAHO => 'Maho only reports the message abandoned in the admin and never'
                . ' redelivers it on its own. Installing ext-pcntl lets the worker keep refreshing its claim,'
                . ' which avoids the report entirely — it is missing from the usual php-fpm images.',
            self::DISPATCHER_HIRALE => 'hirale/queue hands the message to another consumer once the'
                . ' transport\'s redeliver_timeout passes, so the batch can be started a second time.',
            default => '',
        };
    }

    /**
     * Announce what an index run touched, so hosts can invalidate on top of it.
     *
     * Callers dispatch after releasing the index lock: an observer that saves a
     * model would otherwise re-enter indexing while the lock is still held.
     *
     * Ids are advisory and deliberately generous — core swallows an indexer's
     * exception and marks the event failed rather than reporting it, so an id
     * here means "this record was handed to its indexer", not "this record is
     * certainly fresh". Invalidating one record too many is cheap; missing one
     * is the bug this exists to fix.
     *
     * @param array<string, list<int>> $entities entity name => touched ids, an
     *                                           empty list meaning every record
     *                                           of that entity
     */
    public function notifyReindexed(
        string $source,
        array $entities = [],
        bool $full = false,
        ?string $indexerCode = null,
    ): void {
        $entities = $this->_normalizeEntityIds($entities);
        if (!$full && $entities === []) {
            return;
        }

        if (!$full && $this->_countEntityIds($entities) > $this->getInt('invalidate_entity_limit', 500)) {
            // Past this many, carrying the list and acting on it costs more than
            // invalidating the entity types wholesale.
            $entities = array_fill_keys(array_keys($entities), []);
        }

        if ($this->getFlag('clean_cache_after_reindex')) {
            $this->_cleanEntityCache($entities, $full);
        }

        try {
            Mage::dispatchEvent(self::EVENT_REINDEX_AFTER, [
                'source' => $source,
                'full' => $full,
                'entities' => $entities,
                'indexer_code' => $indexerCode,
            ]);
        } catch (Throwable $e) {
            // An observer must never fail the run: the message would be retried
            // and the whole batch reindexed again.
            $this->logException($e);
        }
    }

    /**
     * @param array<string, array<int|string>> $entities
     * @return array<string, list<int>>
     */
    private function _normalizeEntityIds(array $entities): array
    {
        $normalized = [];
        foreach ($entities as $entity => $ids) {
            $entity = trim((string) $entity);
            if ($entity === '') {
                continue;
            }

            $ids = array_values(array_unique(array_map('intval', $ids)));

            // A zero id is an event that named no single record — a mass action,
            // a store-scope change — so every record of that entity is touched.
            $normalized[$entity] = in_array(0, $ids, true) ? [] : $ids;
        }

        return $normalized;
    }

    /**
     * @param array<string, list<int>> $entities
     */
    private function _countEntityIds(array $entities): int
    {
        $count = 0;
        foreach ($entities as $ids) {
            $count += count($ids);
        }

        return $count;
    }

    /**
     * @param array<string, list<int>> $entities
     */
    private function _cleanEntityCache(array $entities, bool $full): void
    {
        $tags = [];
        if ($full && $entities === []) {
            // A global full reindex names no entity, so everything this
            // fallback knows about has to go.
            $tags = array_values(self::CACHE_TAG_ENTITIES);
        }

        foreach ($entities as $entity => $ids) {
            $tag = self::CACHE_TAG_ENTITIES[$entity] ?? null;
            if ($tag === null) {
                continue;
            }
            if ($full || $ids === []) {
                $tags[] = $tag;
                continue;
            }
            foreach ($ids as $id) {
                $tags[] = $tag . '_' . $id;
            }
        }

        if ($tags === []) {
            return;
        }

        try {
            Mage::app()->cleanCache(array_values(array_unique($tags)));
        } catch (Throwable $e) {
            $this->logException($e);
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
     * Queue drain messages ride. On Maho they get a name of their own so a host
     * can route them to a resident pool without touching core's shared default
     * queue, which no module may reroute. config.xml leaves it unrouted, so it
     * still falls to the catch-all pool. hirale/queue only knows admin-declared
     * queues, so OpenMage keeps using that module's own routing.
     */
    private function _drainQueue(string $dispatcher): ?string
    {
        return $dispatcher === self::DISPATCHER_MAHO ? self::QUEUE_DRAIN : null;
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

    /**
     * @param bool $force write even where the store has logging switched off.
     *                    Reserved for faults that have no other channel — the
     *                    module writes to its own file, so this never lands in
     *                    system.log.
     */
    public function log(string $message, int $level = self::LOG_LEVEL_INFO, bool $force = false): void
    {
        Mage::log($message, $level, self::LOG_FILE, $force);
    }

    public function logException(Throwable $e): void
    {
        if (class_exists('Mage')) {
            Mage::logException($e);
        }
    }
}
