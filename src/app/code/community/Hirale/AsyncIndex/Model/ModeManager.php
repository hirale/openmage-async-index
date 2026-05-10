<?php

declare(strict_types=1);

class Hirale_AsyncIndex_Model_ModeManager
{
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

    public function normalizeManagedModes(): void
    {
        foreach ($this->_getProcesses() as $process) {
            $processId = (int) $process->getId();
            if ($processId <= 0) {
                continue;
            }

            $state = $this->_loadState($processId);
            if ($state === null) {
                $this->_insertState($process);
            }

            if ($process->getMode() === Mage_Index_Model_Process::MODE_MANUAL) {
                $process->setMode(Mage_Index_Model_Process::MODE_REAL_TIME)->save();
                $this->_markManaged($processId, Mage_Index_Model_Process::MODE_REAL_TIME);
            }
        }
    }

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
     * @return array<string, mixed>|null
     */
    private function _loadState(int $processId): ?array
    {
        $row = $this->_connection()->fetchRow(sprintf(
            'SELECT * FROM %s WHERE process_id = %d',
            $this->_stateTable(),
            $processId,
        ));

        return is_array($row) ? $row : null;
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

    private function _insertState(Mage_Index_Model_Process $process): void
    {
        $now = $this->_now();
        $this->_connection()->insert($this->_stateTable(), [
            'process_id' => (int) $process->getId(),
            'indexer_code' => (string) $process->getIndexerCode(),
            'original_mode' => (string) $process->getMode(),
            'managed_mode' => null,
            'is_managed' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function _markManaged(int $processId, string $managedMode): void
    {
        $this->_connection()->update($this->_stateTable(), [
            'managed_mode' => $managedMode,
            'is_managed' => 1,
            'updated_at' => $this->_now(),
        ], ['process_id = ?' => $processId]);
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
