<?php

/**
 * Drain pending Mage_Index events. Multiple drain dispatches are
 * idempotent — the handler iterates events until the table is empty.
 *
 * v3 does NOT coalesce drain dispatches (v2's time-bucketed job_id
 * dedup is dropped); duplicate drains over an empty event table are
 * effectively no-ops. v3.1 may reintroduce dispatch-time dedup via
 * a JobIdHintStamp if drain dispatch volume becomes a problem.
 */
final readonly class Hirale_AsyncIndex_Message_DrainEventsMessage
{
    public function __construct(
        public string $reason,
        public ?int $eventId = null,
        public ?string $entity = null,
        public ?string $type = null,
    ) {
    }
}
