<?php

/**
 * Run one batch of a full-reindex job, identified by the hirale_asyncindex_full_run
 * row id. The handler enqueues a follow-up batch for the same run if more work
 * remains, or transitions the run to its terminal state.
 */
final readonly class Hirale_AsyncIndex_Message_FullReindexBatchMessage
{
    public function __construct(public int $runId)
    {
    }
}
