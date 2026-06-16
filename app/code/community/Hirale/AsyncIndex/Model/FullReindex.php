<?php

declare(strict_types=1);

class Hirale_AsyncIndex_Model_FullReindex
{
    private const LOCK_NAME = 'hirale_asyncindex_drain';
    private const STATUS_QUEUED = 'queued';
    private const STATUS_RUNNING = 'running';
    private const STATUS_SUCCEEDED = 'succeeded';
    private const STATUS_FAILED = 'failed';
    private const STATUS_CANCELED = 'canceled';

    private const MODE_PRODUCT = 'product';
    private const MODE_GLOBAL = 'global';

    private const PRODUCT_BATCH_INDEXERS = [
        'catalog_product_attribute' => true,
        'catalog_product_price' => true,
        'cataloginventory_stock' => true,
        'catalog_product_flat' => true,
        'catalogsearch_fulltext' => true,
    ];

    /**
     * @return list<int>
     */
    public function scheduleRequiredRuns(): array
    {
        $helper = $this->_getHelper();
        if (!$helper->isEnabled() || !$helper->isQueueEnabled() || !$helper->getFlag('auto_reindex_required')) {
            return [];
        }

        $runIds = [];
        foreach ($this->_getOrderedProcesses() as $process) {
            if ($process->getStatus() !== Mage_Index_Model_Process::STATUS_REQUIRE_REINDEX) {
                continue;
            }

            $runId = $this->scheduleProcess($process, 'required_reindex');
            if ($runId > 0) {
                $runIds[] = $runId;
            }
        }

        return $runIds;
    }

    public function scheduleProcessAndDependencies(Mage_Index_Model_Process $process, string $reason): void
    {
        $indexer = Mage::getSingleton('index/indexer');
        if (!$indexer instanceof Mage_Index_Model_Indexer) {
            $this->scheduleProcess($process, $reason);
            return;
        }

        foreach ($process->getDepends() as $dependencyCode) {
            $dependency = $indexer->getProcessByCode($dependencyCode);
            if ($dependency instanceof Mage_Index_Model_Process) {
                $this->scheduleProcessAndDependencies($dependency, $reason . ':dependency');
            }
        }

        $this->scheduleProcess($process, $reason);
    }

    public function scheduleProcess(Mage_Index_Model_Process $process, string $reason): int
    {
        $helper = $this->_getHelper();
        if (!$helper->isEnabled() || !$helper->isQueueEnabled()) {
            return 0;
        }

        $processId = (int) $process->getId();
        if ($processId <= 0) {
            return 0;
        }

        $connection = $this->_connection();
        $connection->beginTransaction();
        try {
            $activeRun = $this->_loadActiveRunForUpdate($processId);
            if ($activeRun !== null) {
                $connection->commit();
                return (int) $activeRun['run_id'];
            }

            $mode = $this->_isProductBatchProcess($process) ? self::MODE_PRODUCT : self::MODE_GLOBAL;
            $now = $this->_now();
            $connection->insert($this->_runTable(), [
                'process_id' => $processId,
                'indexer_code' => (string) $process->getIndexerCode(),
                'mode' => $mode,
                'status' => self::STATUS_QUEUED,
                'cursor_value' => 0,
                'total' => $mode === self::MODE_PRODUCT ? $this->_getProductCount() : 1,
                'processed' => 0,
                'event_waterline' => $this->_getEventWaterline($processId),
                'reason' => $reason,
                'last_error' => null,
                'created_at' => $now,
                'updated_at' => $now,
                'started_at' => null,
                'finished_at' => null,
            ]);
            $runId = (int) $connection->lastInsertId($this->_runTable(), 'run_id');
            $connection->commit();
        } catch (Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        $process->changeStatus(Mage_Index_Model_Process::STATUS_REQUIRE_REINDEX);
        $this->enqueueRun($runId);

        return $runId;
    }

    public function enqueueNextActiveRun(): bool
    {
        $run = $this->_loadNextActiveRun();
        if ($run === null) {
            return false;
        }

        return $this->enqueueRun((int) $run['run_id']);
    }

    public function hasActiveRuns(): bool
    {
        return $this->_loadNextActiveRun() !== null;
    }

    public function enqueueRun(int $runId, int $delay = 0): bool
    {
        return $this->_getHelper()->enqueueFullReindexBatch($runId, $delay);
    }

    /**
     * @return array{processed:int, pending:bool, locked:bool}
     */
    public function runBatch(int $runId): array
    {
        $helper = $this->_getHelper();
        if (!$helper->isEnabled() || !$helper->isQueueEnabled()) {
            return ['processed' => 0, 'pending' => false, 'locked' => false];
        }

        if (!$helper->acquireIndexLock(self::LOCK_NAME)) {
            $this->enqueueRun($runId, 15);
            return ['processed' => 0, 'pending' => true, 'locked' => true];
        }

        try {
            return $helper->withFullReindexContext(function () use ($runId): array {
                return $this->_runBatch($runId);
            });
        } finally {
            $helper->releaseIndexLock(self::LOCK_NAME);
        }
    }

    /**
     * Request cooperative cancellation of a queued/running run. The worker
     * observes the flag at the top of the next batch and transitions the run
     * to canceled; nothing terminates mid-batch.
     */
    public function requestCancel(int $runId): bool
    {
        return $this->_connection()->update($this->_runTable(), [
            'cancel_requested' => 1,
            'updated_at' => $this->_now(),
        ], [
            'run_id = ?' => $runId,
            'status IN (?)' => [self::STATUS_QUEUED, self::STATUS_RUNNING],
        ]) > 0;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listActiveRuns(): array
    {
        return $this->_connection()->fetchAll(sprintf(
            'SELECT run_id, process_id, indexer_code, mode, status, cursor_value, total, processed, cancel_requested, reason, started_at, updated_at'
            . ' FROM %s WHERE status IN (%s, %s) ORDER BY run_id ASC',
            $this->_runTable(),
            $this->_connection()->quote(self::STATUS_QUEUED),
            $this->_connection()->quote(self::STATUS_RUNNING),
        ));
    }

    /**
     * @return array{processed:int, pending:bool, locked:bool}
     */
    private function _runBatch(int $runId): array
    {
        $run = $this->_loadRun($runId);
        if ($run === null || !in_array($run['status'], [self::STATUS_QUEUED, self::STATUS_RUNNING], true)) {
            return ['processed' => 0, 'pending' => $this->hasActiveRuns(), 'locked' => false];
        }

        $process = Mage::getModel('index/process')->load((int) $run['process_id']);
        if (!$process instanceof Mage_Index_Model_Process || !$process->getId()) {
            $this->_failRun($runId, 'Index process is unavailable.');
            return ['processed' => 0, 'pending' => $this->hasActiveRuns(), 'locked' => false];
        }

        if ((int) ($run['cancel_requested'] ?? 0) === 1) {
            $this->_cancelRun($runId, $process);
            $this->enqueueNextActiveRun();
            return ['processed' => 0, 'pending' => $this->hasActiveRuns(), 'locked' => false];
        }

        try {
            if ($run['status'] === self::STATUS_QUEUED) {
                $this->_markRunning($runId, $process);
            }

            if ($run['mode'] === self::MODE_PRODUCT) {
                $processed = $this->_runProductBatch($run, $process);
            } else {
                $processed = $this->_runGlobalBatch($run, $process);
            }

            $updatedRun = $this->_loadRun($runId);
            if ($updatedRun !== null && $updatedRun['status'] === self::STATUS_RUNNING) {
                $this->enqueueRun($runId);
                return ['processed' => $processed, 'pending' => true, 'locked' => false];
            }

            $this->enqueueNextActiveRun();
            return ['processed' => $processed, 'pending' => $this->hasActiveRuns(), 'locked' => false];
        } catch (Throwable $e) {
            $this->_failRun($runId, $e->getMessage());
            $this->_getHelper()->logException($e);
            throw $e;
        }
    }

    private function _runProductBatch(array $run, Mage_Index_Model_Process $process): int
    {
        $ids = $this->_getNextProductIds((int) $run['cursor_value'], $this->_getHelper()->getInt('full_batch_size', 500));
        if ($ids === []) {
            $this->_finishRun((int) $run['run_id'], $process, (int) $run['event_waterline']);
            return 0;
        }

        $indexer = $process->getIndexer();
        if (!is_object($indexer) || !method_exists($indexer, 'reindexEntity')) {
            throw new RuntimeException(sprintf(
                'Indexer %s cannot reindex product entities.',
                (string) $process->getIndexerCode(),
            ));
        }

        $indexer->reindexEntity($ids);
        $processed = count($ids);
        $newProcessed = (int) $run['processed'] + $processed;
        $newCursor = max($ids);

        $this->_connection()->update($this->_runTable(), [
            'cursor_value' => $newCursor,
            'processed' => $newProcessed,
            'updated_at' => $this->_now(),
        ], ['run_id = ?' => (int) $run['run_id']]);

        if ($newProcessed >= (int) $run['total']) {
            $this->_finishRun((int) $run['run_id'], $process, (int) $run['event_waterline']);
        }

        return $processed;
    }

    private function _runGlobalBatch(array $run, Mage_Index_Model_Process $process): int
    {
        $process->reindexEverything();
        $this->_connection()->update($this->_runTable(), [
            'processed' => 1,
            'updated_at' => $this->_now(),
        ], ['run_id = ?' => (int) $run['run_id']]);
        $this->_finishRun((int) $run['run_id'], $process, (int) $run['event_waterline']);

        return 1;
    }

    private function _markRunning(int $runId, Mage_Index_Model_Process $process): void
    {
        $process->getResource()->startProcess($process);
        $this->_connection()->update($this->_runTable(), [
            'status' => self::STATUS_RUNNING,
            'started_at' => $this->_now(),
            'updated_at' => $this->_now(),
        ], ['run_id = ?' => $runId]);
    }

    private function _finishRun(int $runId, Mage_Index_Model_Process $process, int $eventWaterline): void
    {
        $this->_markProcessEventsDone((int) $process->getId(), $eventWaterline);
        $process->getResource()->endProcess($process);
        $this->_connection()->update($this->_runTable(), [
            'status' => self::STATUS_SUCCEEDED,
            'updated_at' => $this->_now(),
            'finished_at' => $this->_now(),
        ], ['run_id = ?' => $runId]);
    }

    private function _cancelRun(int $runId, Mage_Index_Model_Process $process): void
    {
        if ($process->getId()) {
            $process->getResource()->failProcess($process);
        }
        $now = $this->_now();
        $this->_connection()->update($this->_runTable(), [
            'status' => self::STATUS_CANCELED,
            'last_error' => 'Canceled by operator',
            'updated_at' => $now,
            'finished_at' => $now,
        ], ['run_id = ?' => $runId]);
    }

    private function _failRun(int $runId, string $lastError): void
    {
        $run = $this->_loadRun($runId);
        if ($run !== null) {
            $process = Mage::getModel('index/process')->load((int) $run['process_id']);
            if ($process instanceof Mage_Index_Model_Process && $process->getId()) {
                $process->getResource()->failProcess($process);
            }
        }

        $this->_connection()->update($this->_runTable(), [
            'status' => self::STATUS_FAILED,
            'last_error' => $lastError,
            'updated_at' => $this->_now(),
            'finished_at' => $this->_now(),
        ], ['run_id = ?' => $runId]);
    }

    private function _markProcessEventsDone(int $processId, int $eventWaterline): void
    {
        if ($eventWaterline <= 0) {
            return;
        }

        $this->_connection()->update($this->_processEventTable(), [
            'status' => Mage_Index_Model_Process::EVENT_STATUS_DONE,
        ], [
            'process_id = ?' => $processId,
            'event_id <= ?' => $eventWaterline,
            'status <> ?' => Mage_Index_Model_Process::EVENT_STATUS_DONE,
        ]);
    }

    /**
     * @return list<int>
     */
    private function _getNextProductIds(int $cursor, int $limit): array
    {
        $sql = sprintf(
            'SELECT entity_id FROM %s WHERE entity_id > %d ORDER BY entity_id ASC LIMIT %d',
            $this->_productTable(),
            $cursor,
            max(1, $limit),
        );

        return array_map('intval', $this->_connection()->fetchCol($sql));
    }

    private function _getProductCount(): int
    {
        return (int) $this->_connection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $this->_productTable()));
    }

    private function _getEventWaterline(int $processId): int
    {
        return (int) $this->_connection()->fetchOne(sprintf(
            'SELECT COALESCE(MAX(event_id), 0) FROM %s WHERE process_id = %d',
            $this->_processEventTable(),
            $processId,
        ));
    }

    /**
     * @return list<Mage_Index_Model_Process>
     */
    private function _getOrderedProcesses(): array
    {
        $runner = Mage::getSingleton('hirale_asyncindex/runner');
        if ($runner instanceof Hirale_AsyncIndex_Model_Runner) {
            return $runner->getOrderedProcesses();
        }

        return [];
    }

    private function _isProductBatchProcess(Mage_Index_Model_Process $process): bool
    {
        return isset(self::PRODUCT_BATCH_INDEXERS[(string) $process->getIndexerCode()]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function _loadActiveRun(int $processId): ?array
    {
        $row = $this->_connection()->fetchRow(sprintf(
            'SELECT * FROM %s WHERE process_id = %d AND status IN (%s, %s) ORDER BY run_id ASC LIMIT 1',
            $this->_runTable(),
            $processId,
            $this->_connection()->quote(self::STATUS_QUEUED),
            $this->_connection()->quote(self::STATUS_RUNNING),
        ));

        return is_array($row) ? $row : null;
    }

    /**
     * Must be called inside a transaction; takes a gap lock on the
     * (process_id, status) range so concurrent schedulers serialize.
     *
     * @return array<string, mixed>|null
     */
    private function _loadActiveRunForUpdate(int $processId): ?array
    {
        $row = $this->_connection()->fetchRow(sprintf(
            'SELECT * FROM %s WHERE process_id = %d AND status IN (%s, %s) ORDER BY run_id ASC LIMIT 1 FOR UPDATE',
            $this->_runTable(),
            $processId,
            $this->_connection()->quote(self::STATUS_QUEUED),
            $this->_connection()->quote(self::STATUS_RUNNING),
        ));

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function _loadNextActiveRun(): ?array
    {
        $row = $this->_connection()->fetchRow(sprintf(
            'SELECT * FROM %s WHERE status IN (%s, %s) ORDER BY run_id ASC LIMIT 1',
            $this->_runTable(),
            $this->_connection()->quote(self::STATUS_QUEUED),
            $this->_connection()->quote(self::STATUS_RUNNING),
        ));

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function _loadRun(int $runId): ?array
    {
        $row = $this->_connection()->fetchRow(sprintf(
            'SELECT * FROM %s WHERE run_id = %d',
            $this->_runTable(),
            $runId,
        ));

        return is_array($row) ? $row : null;
    }

    private function _runTable(): string
    {
        return Mage::getSingleton('core/resource')->getTableName('hirale_asyncindex/full_run');
    }

    private function _processEventTable(): string
    {
        return Mage::getSingleton('core/resource')->getTableName('index/process_event');
    }

    private function _productTable(): string
    {
        return Mage::getSingleton('core/resource')->getTableName('catalog/product');
    }

    private function _connection()
    {
        return Mage::getSingleton('core/resource')->getConnection('core_write');
    }

    private function _now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private function _getHelper(): Hirale_AsyncIndex_Helper_Data
    {
        $helper = Mage::helper('hirale_asyncindex');
        if (!$helper instanceof Hirale_AsyncIndex_Helper_Data) {
            throw new RuntimeException('Hirale AsyncIndex helper is unavailable.');
        }

        return $helper;
    }
}
