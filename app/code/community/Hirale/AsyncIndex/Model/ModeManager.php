<?php

declare(strict_types=1);

class Hirale_AsyncIndex_Model_ModeManager
{
    /**
     * Managed modes follow the main switch: while async index is on, manual
     * indexers are taken over so events keep being recorded; when it is turned
     * off, the modes are handed back — unless the operator asked to keep them,
     * in which case the indexers stay on real_time and the state table is left
     * as it is, so a later re-enable still knows what to restore.
     */
    public function sync(): void
    {
        $helper = $this->_getHelper();
        if ($helper->isEnabled()) {
            if ($helper->getFlag('auto_manage_modes')) {
                $this->normalizeManagedModes();
            }
            return;
        }

        if ($helper->getFlag('restore_modes_on_disable')) {
            $this->restoreManagedModes();
        }
    }

    /**
     * Takes over every manual indexer, recording the mode being taken over at
     * that moment. Recording it the first time a process was merely *seen*
     * stored the wrong mode: an indexer the operator switched to manual later
     * was taken over anyway, but the state row still claimed its original mode
     * was whatever it had been before, so disabling async index restored that
     * instead of the operator's choice.
     *
     * A process the operator switches back to manual while async index runs is
     * taken over again — real_time is what makes events land in the first place
     * — and the log line says so, since the change silently not sticking is
     * otherwise indistinguishable from a bug.
     */
    public function normalizeManagedModes(): void
    {
        foreach ($this->_getProcesses() as $process) {
            $processId = (int) $process->getId();
            if ($processId <= 0 || $process->getMode() !== Mage_Index_Model_Process::MODE_MANUAL) {
                continue;
            }

            $this->_recordTakeover($process);
            $process->setMode(Mage_Index_Model_Process::MODE_REAL_TIME)->save();

            $this->_getHelper()->log(sprintf(
                'Took indexer "%s" from manual to real_time so index events are recorded;'
                . ' the manual mode is restored when async index is disabled.',
                (string) $process->getIndexerCode(),
            ), Mage::LOG_NOTICE);
        }
    }

    /**
     * Hands every taken-over indexer back to the mode it had at takeover, then
     * clears the table — including rows written by older versions, which
     * recorded every process rather than only the ones actually taken over.
     */
    public function restoreManagedModes(): void
    {
        foreach ($this->_loadManagedStates() as $state) {
            $process = Mage::getModel('index/process')->load((int) $state['process_id']);
            if ($process instanceof Mage_Index_Model_Process && $process->getId()) {
                $originalMode = (string) $state['original_mode'];
                if ($originalMode !== '' && $process->getMode() !== $originalMode) {
                    $process->setMode($originalMode)->save();
                }
            }
        }

        $this->_connection()->delete($this->_stateTable());
    }

    /**
     * @return list<Mage_Index_Model_Process>
     */
    private function _getProcesses(): array
    {
        $indexer = Mage::getSingleton('index/indexer');
        if (!$indexer instanceof Mage_Index_Model_Indexer) {
            return [];
        }

        $processes = [];
        foreach ($indexer->getProcessesCollection() as $process) {
            if ($process instanceof Mage_Index_Model_Process) {
                $processes[] = $process;
            }
        }

        return $processes;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function _loadManagedStates(): array
    {
        return $this->_connection()->fetchAll(sprintf(
            'SELECT * FROM %s WHERE is_managed = 1 ORDER BY process_id ASC',
            $this->_stateTable(),
        ));
    }

    /**
     * Upsert on the unique process_id index: one write instead of a select and
     * a branch, and it re-takes a process whose row survived an earlier run.
     */
    private function _recordTakeover(Mage_Index_Model_Process $process): void
    {
        $now = $this->_now();
        $this->_connection()->insertOnDuplicate($this->_stateTable(), [
            'process_id' => (int) $process->getId(),
            'indexer_code' => (string) $process->getIndexerCode(),
            'original_mode' => (string) $process->getMode(),
            'managed_mode' => Mage_Index_Model_Process::MODE_REAL_TIME,
            'is_managed' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['indexer_code', 'original_mode', 'managed_mode', 'is_managed', 'updated_at']);
    }

    private function _stateTable(): string
    {
        return Mage::getSingleton('core/resource')->getTableName('hirale_asyncindex/process_state');
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
