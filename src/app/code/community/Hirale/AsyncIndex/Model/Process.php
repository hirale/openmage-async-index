<?php

declare(strict_types=1);

class Hirale_AsyncIndex_Model_Process extends Mage_Index_Model_Process
{
    public function reindexEverything()
    {
        $helper = $this->_getAsyncIndexHelper();
        if (!$helper->shouldRunAsync() || !$helper->getFlag('auto_reindex_required')) {
            return parent::reindexEverything();
        }

        $fullReindex = Mage::getSingleton('hirale_asyncindex/fullReindex');
        if (!$fullReindex instanceof Hirale_AsyncIndex_Model_FullReindex) {
            return parent::reindexEverything();
        }

        $fullReindex->scheduleProcessAndDependencies($this, 'external_reindex_everything');

        return $this;
    }

    private function _getAsyncIndexHelper(): Hirale_AsyncIndex_Helper_Data
    {
        $helper = Mage::helper('hirale_asyncindex');
        if (!$helper instanceof Hirale_AsyncIndex_Helper_Data) {
            throw new RuntimeException('Hirale AsyncIndex helper is unavailable.');
        }

        return $helper;
    }
}
