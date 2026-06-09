<?php

declare(strict_types=1);

class Hirale_AsyncIndex_Model_Indexer extends Mage_Index_Model_Indexer
{
    public function processEntityAction($entity, $entityType, $eventType): Mage_Index_Model_Indexer
    {
        $helper = $this->_getAsyncIndexHelper();
        if (!$helper->shouldRunAsync()) {
            return parent::processEntityAction($entity, $entityType, $eventType);
        }

        $event = $this->logEvent($entity, $entityType, $eventType, false);
        if ($event->getProcessIds()) {
            $event->save();
            $helper->enqueueDrain(
                reason: 'process_entity_action',
                eventId: (int) $event->getId(),
                entity: (string) $entityType,
                type: (string) $eventType,
            );
        }

        return $this;
    }

    public function indexEvents($entity = null, $type = null)
    {
        $helper = $this->_getAsyncIndexHelper();
        if (!$helper->shouldRunAsync()) {
            return parent::indexEvents($entity, $type);
        }

        $helper->enqueueDrain(
            reason: 'index_events',
            entity: $entity !== null ? (string) $entity : null,
            type: $type !== null ? (string) $type : null,
        );

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
