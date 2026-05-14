<?php

declare(strict_types=1);

namespace HiraleAsyncIndex\Tests\Unit;

use HiraleAsyncIndex\Tests\Support\FakeResource;
use HiraleAsyncIndex\Tests\Support\QueueHelperStub;
use HiraleAsyncIndex\Tests\Support\QueueStub;
use PHPUnit\Framework\TestCase;

class FullReindexTest extends TestCase
{
    protected function tearDown(): void
    {
        \Mage::reset();
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

    public function testEnqueueRunPropagatesConfiguredQueueOption(): void
    {
        $queue = new QueueStub();
        \Mage::$helper = new QueueHelperStub();
        \Mage::$model = $queue;
        \Mage::$config = [
            'hirale_asyncindex/settings/enabled' => '1',
            'hirale_asyncindex/settings/full_reindex_queue' => 'indexer',
            'hirale_asyncindex/settings/full_max_runtime_seconds' => '30',
        ];

        $fullReindex = new \Hirale_AsyncIndex_Model_FullReindex();
        $result = $fullReindex->enqueueRun(123);

        self::assertTrue($result);
        self::assertCount(1, $queue->calls);
        self::assertSame('indexer', $queue->calls[0]['options']['queue'] ?? null);
        self::assertSame(['action' => 'run_full_batch', 'run_id' => 123], $queue->calls[0]['payload']);
    }

    public function testEnqueueRunOmitsQueueOptionWhenUnconfigured(): void
    {
        $queue = new QueueStub();
        \Mage::$helper = new QueueHelperStub();
        \Mage::$model = $queue;
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];

        $fullReindex = new \Hirale_AsyncIndex_Model_FullReindex();
        $result = $fullReindex->enqueueRun(123);

        self::assertTrue($result);
        self::assertArrayNotHasKey('queue', $queue->calls[0]['options']);
    }

    public function testEnqueueRunPropagatesDelay(): void
    {
        $queue = new QueueStub();
        \Mage::$helper = new QueueHelperStub();
        \Mage::$model = $queue;
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];

        $fullReindex = new \Hirale_AsyncIndex_Model_FullReindex();
        $fullReindex->enqueueRun(7, 15);

        self::assertSame(15, $queue->calls[0]['options']['delay']);
    }
}
