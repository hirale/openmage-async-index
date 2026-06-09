<?php

declare(strict_types=1);

class Hirale_AsyncIndex_Model_System_Config_Backend_Enabled extends Mage_Core_Model_Config_Data
{
    protected function _afterSave()
    {
        $result = parent::_afterSave();

        try {
            $this->_warnWhenQueueIsDisabled();

            $manager = Mage::getSingleton('hirale_asyncindex/modeManager');
            if ($manager instanceof Hirale_AsyncIndex_Model_ModeManager) {
                $manager->sync();
            }
        } catch (Throwable $e) {
            Mage::logException($e);
        }

        return $result;
    }

    private function _warnWhenQueueIsDisabled(): void
    {
        if ((int) $this->getValue() !== 1) {
            return;
        }

        $helper = Mage::helper('hirale_asyncindex');
        if (!$helper instanceof Hirale_AsyncIndex_Helper_Data || $helper->isQueueEnabled()) {
            return;
        }

        $session = Mage::getSingleton('adminhtml/session');
        if (!is_object($session) || !method_exists($session, 'addWarning')) {
            return;
        }

        $session->addWarning($helper->__(
            'Async Index is enabled, but Hirale Queue is unavailable. Configure Hirale Queue before expecting async indexing or queued full reindex jobs to run.',
        ));
    }
}
