<?php

declare(strict_types=1);

class Hirale_AsyncIndex_Model_QueueHandler implements Hirale_Queue_Model_TaskHandlerInterface
{
    /**
     * @param mixed $data
     */
    public function handle($data): void
    {
        if (!is_array($data)) {
            $data = [];
        }

        $payload = isset($data['data']) && is_array($data['data']) ? $data['data'] : $data;
        $action = (string) ($payload['action'] ?? 'drain_events');

        if ($action === 'schedule_full') {
            $this->_getFullReindex()->scheduleRequiredRuns();
            $this->_getFullReindex()->enqueueNextActiveRun();
            return;
        }

        if ($action === 'run_full_batch') {
            $this->_getFullReindex()->runBatch((int) ($payload['run_id'] ?? 0));
            return;
        }

        $this->_getRunner()->drain($payload);
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
}
