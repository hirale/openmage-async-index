<?php

declare(strict_types=1);

class Hirale_AsyncIndex_Model_Reconciler
{
    public function execute(): void
    {
        $helper = $this->_getHelper();
        if (!$helper->isEnabled()) {
            $this->_getModeManager()->sync();
            return;
        }

        $this->_getModeManager()->sync();
        if ($helper->getFlag('auto_reindex_required')) {
            $this->_getFullReindex()->scheduleRequiredRuns();
        }

        $runner = $this->_getRunner();
        if (!$runner->hasPendingEvents() && !$this->_getFullReindex()->hasActiveRuns()) {
            return;
        }

        if ($this->_getFullReindex()->hasActiveRuns()) {
            $this->_getFullReindex()->enqueueNextActiveRun();
            return;
        }

        $helper->enqueueDrain(['reason' => 'reconciler'], true);
    }

    private function _getRunner(): Hirale_AsyncIndex_Model_Runner
    {
        $runner = Mage::getSingleton('hirale_asyncindex/runner');
        if (!$runner instanceof Hirale_AsyncIndex_Model_Runner) {
            throw new RuntimeException('Hirale AsyncIndex runner is unavailable.');
        }

        return $runner;
    }

    private function _getFullReindex(): Hirale_AsyncIndex_Model_FullReindex
    {
        $fullReindex = Mage::getSingleton('hirale_asyncindex/fullReindex');
        if (!$fullReindex instanceof Hirale_AsyncIndex_Model_FullReindex) {
            throw new RuntimeException('Hirale AsyncIndex full reindex runner is unavailable.');
        }

        return $fullReindex;
    }

    private function _getModeManager(): Hirale_AsyncIndex_Model_ModeManager
    {
        $manager = Mage::getSingleton('hirale_asyncindex/modeManager');
        if (!$manager instanceof Hirale_AsyncIndex_Model_ModeManager) {
            throw new RuntimeException('Hirale AsyncIndex mode manager is unavailable.');
        }

        return $manager;
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
