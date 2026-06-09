<?php

declare(strict_types=1);

/**
 * Handler for Hirale_AsyncIndex_Message_FullReindexBatchMessage. Delegates
 * to the existing FullReindex::runBatch logic; the runner is responsible
 * for enqueueing follow-up batches if more work remains.
 */
class Hirale_AsyncIndex_Model_FullReindexBatchHandler
{
    public function __invoke(Hirale_AsyncIndex_Message_FullReindexBatchMessage $message): void
    {
        $this->fullReindex()->runBatch($message->runId);
    }

    private function fullReindex(): Hirale_AsyncIndex_Model_FullReindex
    {
        $fullReindex = Mage::getSingleton('hirale_asyncindex/fullReindex');
        if (!$fullReindex instanceof Hirale_AsyncIndex_Model_FullReindex) {
            throw new RuntimeException('Hirale AsyncIndex full reindex runner is unavailable.');
        }
        return $fullReindex;
    }
}
