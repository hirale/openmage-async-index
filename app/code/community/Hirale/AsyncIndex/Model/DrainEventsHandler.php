<?php

declare(strict_types=1);

/**
 * Handler for Hirale_AsyncIndex_Message_DrainEventsMessage.
 * Delegates to the existing Runner::drain logic so domain code stays put.
 *
 * Registered twice on purpose: the #[\Maho\Config\MessageHandler] attribute
 * for Maho's core queue, and <hirale_queue><handlers> in config.xml for
 * hirale/queue on OpenMage. Each backend ignores the other's registration.
 */
class Hirale_AsyncIndex_Model_DrainEventsHandler
{
    #[\Maho\Config\MessageHandler]
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
