<?php

declare(strict_types=1);

class Hirale_AsyncIndex_Model_Runner
{
    private const LOCK_NAME = 'hirale_asyncindex_drain';

    /**
     * @param array<string, mixed> $payload
     * @return array{processed:int, errors:int, pending:bool, locked:bool}
     */
    public function drain(array $payload = []): array
    {
        $helper = $this->_getHelper();
        if (!$helper->isEnabled() || !$helper->isQueueEnabled()) {
            return ['processed' => 0, 'errors' => 0, 'pending' => false, 'locked' => false];
        }

        if (!$helper->acquireIndexLock(self::LOCK_NAME)) {
            return ['processed' => 0, 'errors' => 0, 'pending' => $this->hasPendingEvents(), 'locked' => true];
        }

        $touched = [];
        try {
            $result = $helper->withDrainContext(function () use ($helper, &$touched): array {
                $fullReindex = Mage::getSingleton('hirale_asyncindex/fullReindex');
                if ($fullReindex instanceof Hirale_AsyncIndex_Model_FullReindex && $fullReindex->hasActiveRuns()) {
                    $fullReindex->enqueueNextActiveRun();
                    return ['processed' => 0, 'errors' => 0, 'pending' => true, 'locked' => false];
                }

                $result = $this->_drainPendingEvents(
                    $helper->getInt('batch_size', 200),
                    $helper->getInt('max_runtime_seconds', 45),
                    $touched,
                );

                if ($result['pending'] && $result['processed'] > 0) {
                    $helper->enqueueDrain(reason: 'continuation');
                }

                return $result + ['locked' => false];
            });
        } finally {
            $helper->releaseIndexLock(self::LOCK_NAME);
        }

        // Announced outside the lock and outside the drain context: an observer
        // that saves a model would otherwise re-enter indexing from inside.
        $helper->notifyReindexed('drain', $touched);

        if ($result['errors'] > 0) {
            // Nothing retries a failed event: core marks it and
            // getUnprocessedEventsCollection() only ever selects new ones. This
            // line is the only signal that the index is drifting.
            $helper->log(sprintf(
                'Drain left %d index event(s) failed; they are not retried.'
                . ' Run "hirale:asyncindex:events" to list them.',
                $result['errors'],
            ), Hirale_AsyncIndex_Helper_Data::LOG_LEVEL_WARNING);
        }

        return $result;
    }

    public function hasPendingEvents(): bool
    {
        $helper = $this->_getHelper();
        if (!$helper->isEnabled() || !$helper->isQueueEnabled()) {
            return false;
        }

        return $this->_getPendingEventCount() > 0;
    }

    /**
     * @param array<string, list<int>> $touched entity name => ids handed to an
     *                                          indexer, collected for the
     *                                          post-drain invalidation event
     * @return array{processed:int, errors:int, pending:bool}
     */
    private function _drainPendingEvents(int $batchSize, int $maxRuntimeSeconds, array &$touched): array
    {
        $processed = 0;
        $errors = 0;
        $startedAt = microtime(true);
        $deadline = $startedAt + max(1, $maxRuntimeSeconds);

        foreach ($this->getOrderedProcesses() as $process) {
            if ($processed >= $batchSize || microtime(true) >= $deadline) {
                break;
            }

            if ($process->getMode() === Mage_Index_Model_Process::MODE_MANUAL || $process->isLocked()) {
                continue;
            }

            $remaining = $batchSize - $processed;
            $process->lock();
            try {
                $events = $process->getUnprocessedEventsCollection();
                $events->setPageSize($remaining);
                $events->setCurPage(1);
                $events->setOrder('event_id', 'ASC');

                while ($processed < $batchSize && microtime(true) < $deadline && ($event = $events->fetchItem())) {
                    try {
                        $process->processEvent($event);
                        $event->save();
                        $this->_recordTouched($touched, $event);
                        if ($this->_eventFailed($process, $event)) {
                            $errors++;
                        }
                    } catch (Throwable $e) {
                        $errors++;
                        $this->_markEventError($process, $event, $e);
                    }
                    $processed++;
                }
            } finally {
                $process->unlock();
            }
        }

        return [
            'processed' => $processed,
            'errors' => $errors,
            'pending' => $this->_getPendingEventCount() > 0,
        ];
    }

    /**
     * @return list<Mage_Index_Model_Process>
     */
    public function getOrderedProcesses(): array
    {
        $indexer = Mage::getSingleton('index/indexer');
        if (!$indexer instanceof Mage_Index_Model_Indexer) {
            throw new RuntimeException('Mage indexer singleton is unavailable.');
        }

        $byCode = [];
        foreach ($indexer->getProcessesCollection() as $process) {
            if ($process instanceof Mage_Index_Model_Process) {
                $byCode[$process->getIndexerCode()] = $process;
            }
        }

        $ordered = [];
        $visited = [];
        $visiting = [];
        foreach ($byCode as $code => $process) {
            $this->_visitProcess($code, $process, $byCode, $ordered, $visited, $visiting);
        }

        return $ordered;
    }

    /**
     * @param array<string, Mage_Index_Model_Process> $byCode
     * @param list<Mage_Index_Model_Process> $ordered
     * @param array<string, bool> $visited
     * @param array<string, bool> $visiting
     */
    private function _visitProcess(
        string $code,
        Mage_Index_Model_Process $process,
        array $byCode,
        array &$ordered,
        array &$visited,
        array &$visiting,
    ): void {
        if (isset($visited[$code]) || isset($visiting[$code])) {
            return;
        }

        $visiting[$code] = true;
        foreach ($process->getDepends() as $dependencyCode) {
            if (isset($byCode[$dependencyCode])) {
                $this->_visitProcess($dependencyCode, $byCode[$dependencyCode], $byCode, $ordered, $visited, $visiting);
            }
        }

        unset($visiting[$code]);
        $visited[$code] = true;
        $ordered[] = $process;
    }

    /**
     * Core catches an indexer's exception and records the failure on the event
     * instead of reporting it, so this is the only way to notice — and it needs
     * no query, the status is already on the object that was just saved.
     */
    private function _eventFailed(Mage_Index_Model_Process $process, Mage_Index_Model_Event $event): bool
    {
        $statuses = $event->getProcessIds();
        if (!is_array($statuses)) {
            return false;
        }

        return ($statuses[$process->getId()] ?? null) === Mage_Index_Model_Process::EVENT_STATUS_ERROR;
    }

    /**
     * An event with no entity pk named no single record — a mass action, a
     * store-scope change — and is recorded as id 0, which the helper reads as
     * "every record of this entity".
     *
     * @param array<string, list<int>> $touched
     */
    private function _recordTouched(array &$touched, Mage_Index_Model_Event $event): void
    {
        $entity = trim((string) $event->getEntity());
        if ($entity === '') {
            return;
        }

        $touched[$entity][] = (int) $event->getEntityPk();
    }

    private function _markEventError(Mage_Index_Model_Process $process, Mage_Index_Model_Event $event, Throwable $e): void
    {
        try {
            $event->addProcessId($process->getId(), Mage_Index_Model_Process::EVENT_STATUS_ERROR);
            $event->save();
        } catch (Throwable $saveError) {
            $this->_getHelper()->logException($saveError);
        }

        $this->_getHelper()->logException($e);
    }

    /**
     * Events an indexer failed on. Nothing ever picks them up again — core marks
     * the row and getUnprocessedEventsCollection() only selects new ones — so
     * they are invisible without this.
     *
     * @return list<array<string, mixed>>
     */
    public function listFailedEvents(int $limit = 50): array
    {
        $resource = Mage::getSingleton('core/resource');
        $connection = $resource->getConnection('core_read');

        return $connection->fetchAll(sprintf(
            'SELECT pe.event_id, pe.process_id, p.indexer_code, e.entity, e.entity_pk, e.type, e.created_at'
            . ' FROM %s pe'
            . ' INNER JOIN %s p ON p.process_id = pe.process_id'
            . ' LEFT JOIN %s e ON e.event_id = pe.event_id'
            . ' WHERE pe.status = %s'
            . ' ORDER BY pe.event_id DESC LIMIT %d',
            $resource->getTableName('index/process_event'),
            $resource->getTableName('index/process'),
            $resource->getTableName('index/event'),
            $connection->quote(Mage_Index_Model_Process::EVENT_STATUS_ERROR),
            max(1, $limit),
        ));
    }

    public function countFailedEvents(): int
    {
        $resource = Mage::getSingleton('core/resource');
        $connection = $resource->getConnection('core_read');

        return (int) $connection->fetchOne(sprintf(
            'SELECT COUNT(*) FROM %s WHERE status = %s',
            $resource->getTableName('index/process_event'),
            $connection->quote(Mage_Index_Model_Process::EVENT_STATUS_ERROR),
        ));
    }

    /**
     * Drops rows a finished full reindex marked done before this module learned
     * to delete them the way core does. Safe at any time: a done row means the
     * process already handled that event.
     */
    public function pruneCompletedEvents(): int
    {
        $resource = Mage::getSingleton('core/resource');

        return $resource->getConnection('core_write')->delete(
            $resource->getTableName('index/process_event'),
            ['status = ?' => Mage_Index_Model_Process::EVENT_STATUS_DONE],
        );
    }

    private function _getPendingEventCount(): int
    {
        $resource = Mage::getSingleton('core/resource');
        $connection = $resource->getConnection('core_read');
        $processEventTable = $resource->getTableName('index/process_event');
        $processTable = $resource->getTableName('index/process');

        $sql = sprintf(
            'SELECT COUNT(*) FROM %s pe INNER JOIN %s p ON p.process_id = pe.process_id WHERE pe.status = %s AND p.mode <> %s',
            $processEventTable,
            $processTable,
            $connection->quote(Mage_Index_Model_Process::EVENT_STATUS_NEW),
            $connection->quote(Mage_Index_Model_Process::MODE_MANUAL),
        );

        return (int) $connection->fetchOne($sql);
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
