<?php

declare(strict_types=1);

/**
 * Handler for Hirale_AsyncIndex_Message_DrainEventsMessage.
 * Delegates to the existing Runner::drain logic so domain code stays put.
 */
class Hirale_AsyncIndex_Model_DrainEventsHandler
{
    public function __invoke(Hirale_AsyncIndex_Message_DrainEventsMessage $message): void
    {
        $payload = [
            'reason'   => $message->reason,
            'event_id' => $message->eventId,
            'entity'   => $message->entity,
            'type'     => $message->type,
        ];
        $this->runner()->drain($payload);
    }

    private function runner(): Hirale_AsyncIndex_Model_Runner
    {
        $runner = Mage::getSingleton('hirale_asyncindex/runner');
        if (!$runner instanceof Hirale_AsyncIndex_Model_Runner) {
            throw new RuntimeException('Hirale AsyncIndex runner is unavailable.');
        }
        return $runner;
    }
}
