<?php

declare(strict_types=1);

namespace HiraleAsyncIndex\Tests\Unit;

use Hirale\Queue\Bus;
use HiraleAsyncIndex\Tests\Support\FakeResource;
use PHPUnit\Framework\TestCase;

class FullReindexTest extends TestCase
{
    protected function setUp(): void
    {
        Bus::reset();
    }

    protected function tearDown(): void
    {
        \Mage::reset();
        Bus::reset();
    }

    public function testRequestCancelUpdatesQueuedRow(): void
    {
        $resource = new FakeResource();
        $resource->connection->updateResult = 1;
        \Mage::$singletons['core/resource'] = $resource;

        $fullReindex = new \Hirale_AsyncIndex_Model_FullReindex();

        self::assertTrue($fullReindex->requestCancel(42));
        self::assertCount(1, $resource->connection->updates);

        $call = $resource->connection->updates[0];
        self::assertSame('hirale_asyncindex_full_run', $call['table']);
        self::assertSame(1, $call['values']['cancel_requested']);
        self::assertArrayHasKey('updated_at', $call['values']);

        $where = $call['where'];
        self::assertIsArray($where);
        self::assertSame(42, $where['run_id = ?']);
        self::assertSame(['queued', 'running'], $where['status IN (?)']);
    }

    public function testRequestCancelReturnsFalseWhenNoMatchingRow(): void
    {
        $resource = new FakeResource();
        $resource->connection->updateResult = 0;
        \Mage::$singletons['core/resource'] = $resource;

        $fullReindex = new \Hirale_AsyncIndex_Model_FullReindex();

        self::assertFalse($fullReindex->requestCancel(99));
    }

    public function testListActiveRunsFiltersByActiveStatuses(): void
    {
        $resource = new FakeResource();
        $resource->connection->fetchAllResponses[] = [
            ['run_id' => 1, 'process_id' => 5, 'indexer_code' => 'catalog_product_price', 'status' => 'queued'],
            ['run_id' => 2, 'process_id' => 6, 'indexer_code' => 'catalog_product_flat', 'status' => 'running'],
        ];
        \Mage::$singletons['core/resource'] = $resource;

        $fullReindex = new \Hirale_AsyncIndex_Model_FullReindex();
        $rows = $fullReindex->listActiveRuns();

        self::assertCount(2, $rows);
        self::assertSame(1, $rows[0]['run_id']);
        self::assertStringContainsString("status IN ('queued', 'running')", $resource->connection->lastFetchAllSql);
    }

    public function testEnqueueRunRoutesBatchMessageToConfiguredQueue(): void
    {
        \Mage::$config = [
            'hirale_asyncindex/settings/enabled' => '1',
            'hirale_asyncindex/settings/full_reindex_queue' => 'indexer',
        ];

        $fullReindex = new \Hirale_AsyncIndex_Model_FullReindex();
        $result = $fullReindex->enqueueRun(123);

        self::assertTrue($result);
        self::assertCount(1, Bus::$dispatches);
        self::assertSame('dispatchOnQueue', Bus::$dispatches[0]['method']);
        self::assertSame('indexer', Bus::$dispatches[0]['queue']);

        $message = Bus::$dispatches[0]['message'];
        self::assertInstanceOf(\Hirale_AsyncIndex_Message_FullReindexBatchMessage::class, $message);
        self::assertSame(123, $message->runId);
    }

    public function testEnqueueRunUsesDefaultRoutingWhenQueueUnconfigured(): void
    {
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];

        $fullReindex = new \Hirale_AsyncIndex_Model_FullReindex();
        $result = $fullReindex->enqueueRun(123);

        self::assertTrue($result);
        self::assertSame('dispatch', Bus::$dispatches[0]['method']);
        self::assertNull(Bus::$dispatches[0]['queue']);
    }

    public function testEnqueueRunPropagatesDelay(): void
    {
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];

        $fullReindex = new \Hirale_AsyncIndex_Model_FullReindex();
        $fullReindex->enqueueRun(7, 15);

        self::assertSame('dispatchDelayed', Bus::$dispatches[0]['method']);
        self::assertSame(15, Bus::$dispatches[0]['delaySeconds']);
    }

    public function testFinishedRunDeletesItsProcessEventsInsteadOfMarkingThemDone(): void
    {
        // Core signals "handled" by deleting the index_process_event row, so a
        // row marked done was never removed by anything: every finished run used
        // to leave a fresh batch of them behind for good.
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];
        \Mage::$singletons['core/lock'] = new \Mage_Core_Model_Lock();

        $resource = new FakeResource();
        $resource->connection->updateResult = 1;
        $resource->connection->fetchAllResponses = [
            [[
                'run_id' => 9,
                'process_id' => 3,
                'indexer_code' => 'catalog_url',
                'mode' => 'global',
                'status' => 'queued',
                'cursor_value' => 0,
                'total' => 1,
                'processed' => 0,
                'event_waterline' => 120,
                'cancel_requested' => 0,
            ]],
            [['run_id' => 9, 'status' => 'succeeded']],
        ];
        \Mage::$singletons['core/resource'] = $resource;

        $process = new \Mage_Index_Model_Process(id: 3, indexerCode: 'catalog_url');
        \Mage::$processes[3] = $process;

        $result = (new \Hirale_AsyncIndex_Model_FullReindex())->runBatch(9);

        self::assertSame(1, $result['processed']);
        self::assertSame(1, $process->reindexEverythingCalls);
        self::assertSame(['start', 'end'], $process->resource->calls);

        $deletes = $resource->connection->deletes;
        self::assertCount(2, $deletes);
        self::assertSame('index_process_event', $deletes[0]['table']);
        self::assertSame(3, $deletes[0]['where']['process_id = ?']);
        self::assertSame(120, $deletes[0]['where']['event_id <= ?']);

        // Second sweep clears rows left behind by earlier versions, whatever
        // their event id.
        self::assertSame('index_process_event', $deletes[1]['table']);
        self::assertSame(3, $deletes[1]['where']['process_id = ?']);
        self::assertSame(
            \Mage_Index_Model_Process::EVENT_STATUS_DONE,
            $deletes[1]['where']['status = ?'],
        );

        $runUpdates = array_column($resource->connection->updates, 'values');
        self::assertSame('succeeded', end($runUpdates)['status']);

        // A global batch reindexes everything of its indexer and can name no
        // ids, so it announces itself as a wholesale invalidation.
        self::assertCount(1, \Mage::$events);
        self::assertTrue(\Mage::$events[0]['data']['full']);
        self::assertSame([], \Mage::$events[0]['data']['entities']);
        self::assertSame('catalog_url', \Mage::$events[0]['data']['indexer_code']);
    }

    public function testAProductBatchAnnouncesTheIdsItReindexed(): void
    {
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];
        \Mage::$singletons['core/lock'] = new \Mage_Core_Model_Lock();

        $resource = new FakeResource();
        $resource->connection->updateResult = 1;
        $resource->connection->fetchColResult = [1, 2, 3];
        $resource->connection->fetchAllResponses = [
            [[
                'run_id' => 4,
                'process_id' => 3,
                'indexer_code' => 'catalog_product_price',
                'mode' => 'product',
                'status' => 'queued',
                'cursor_value' => 0,
                'total' => 10,
                'processed' => 0,
                'event_waterline' => 0,
                'cancel_requested' => 0,
            ]],
            [['run_id' => 4, 'status' => 'running']],
        ];
        \Mage::$singletons['core/resource'] = $resource;

        $process = new \Mage_Index_Model_Process(id: 3, indexerCode: 'catalog_product_price');
        $process->indexer = new \Mage_Index_Model_Indexer_Abstract();
        \Mage::$processes[3] = $process;

        $result = (new \Hirale_AsyncIndex_Model_FullReindex())->runBatch(4);

        self::assertSame(3, $result['processed']);
        self::assertTrue($result['pending']);
        self::assertSame([[1, 2, 3]], $process->indexer->reindexedEntities);

        self::assertCount(1, \Mage::$events);
        $payload = \Mage::$events[0]['data'];
        self::assertSame('full_reindex', $payload['source']);
        self::assertFalse($payload['full']);
        self::assertSame(['catalog_product' => [1, 2, 3]], $payload['entities']);
        self::assertSame('catalog_product_price', $payload['indexer_code']);

        // Dispatched after the full-reindex context was dropped, so an observer
        // cannot re-enter indexing from inside the batch.
        self::assertSame([], \Mage::$events[0]['registry']);
    }
}
