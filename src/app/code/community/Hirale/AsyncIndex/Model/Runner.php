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

        $lock = Mage_Index_Model_Lock::getInstance();
        if (!$lock->setLock(self::LOCK_NAME)) {
            return ['processed' => 0, 'errors' => 0, 'pending' => $this->hasPendingEvents(), 'locked' => true];
        }

        $helper->clearKickPending();
        try {
            return $helper->withDrainContext(function () use ($helper): array {
                $fullReindex = Mage::getSingleton('hirale_asyncindex/fullReindex');
                if ($fullReindex instanceof Hirale_AsyncIndex_Model_FullReindex && $fullReindex->hasActiveRuns()) {
                    $fullReindex->enqueueNextActiveRun();
                    return ['processed' => 0, 'errors' => 0, 'pending' => true, 'locked' => false];
                }

                $result = $this->_drainPendingEvents(
                    $helper->getInt('batch_size', 200),
                    $helper->getInt('max_runtime_seconds', 45),
                );

                if ($result['pending'] && $result['processed'] > 0) {
                    $helper->enqueueDrain(['reason' => 'continuation'], true);
                }

                return $result + ['locked' => false];
            });
        } finally {
            $lock->releaseLock(self::LOCK_NAME);
        }
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
     * @return array{processed:int, errors:int, pending:bool}
     */
    private function _drainPendingEvents(int $batchSize, int $maxRuntimeSeconds): array
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
