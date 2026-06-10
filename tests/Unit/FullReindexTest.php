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
}
