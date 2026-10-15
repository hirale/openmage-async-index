<?php

declare(strict_types=1);

namespace HiraleAsyncIndex\Tests\Unit;

use HiraleAsyncIndex\Tests\Support\FakeResource;
use Maho\Queue\QueueManager;
use PHPUnit\Framework\TestCase;

/**
 * A full reindex used to make every drain step aside until it ended, which on
 * a real catalog meant thirteen minutes of no stock, price or category updates
 * at all. Drains now step around the processes being rebuilt and nothing else.
 */
class DrainYieldTest extends TestCase
{
    protected function setUp(): void
    {
        QueueManager::reset();
    }

    protected function tearDown(): void
    {
        \Mage::reset();
        QueueManager::reset();
    }

    private function bootstrap(\Mage_Index_Model_Process ...$processes): FakeResource
    {
        $resource = new FakeResource();
        $resource->connection->updateResult = 1;
        \Mage::$singletons['core/resource'] = $resource;
        \Mage::$singletons['core/lock'] = new \Mage_Core_Model_Lock();
        \Mage::$singletons['index/indexer'] = new \Mage_Index_Model_Indexer($processes);
        \Mage::$singletons['hirale_asyncindex/fullReindex'] = new \Hirale_AsyncIndex_Model_FullReindex();
        \Mage::$singletons['hirale_asyncindex/runner'] = new \Hirale_AsyncIndex_Model_Runner();
        \Mage::$singletons['hirale_asyncindex/modeManager'] = new \Hirale_AsyncIndex_Model_ModeManager();
        \Mage::$enabledModules['Maho_Queue'] = true;
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];
        foreach ($processes as $process) {
            \Mage::$processes[$process->getId()] = $process;
        }

        return $resource;
    }

    private static function processWithEvent(int $id, string $code, int $eventId): \Mage_Index_Model_Process
    {
        $process = new \Mage_Index_Model_Process(id: $id, indexerCode: $code);
        $process->unprocessedEvents = [new \Mage_Index_Model_Event(id: $eventId, entity: 'catalog_product', entityPk: $eventId)];

        return $process;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function runRow(array $overrides = []): array
    {
        return $overrides + [
            'run_id' => 9,
            'process_id' => 1,
            'indexer_code' => 'catalog_category_product',
            'mode' => 'global',
            'status' => 'running',
            'cursor_value' => 0,
            'total' => 1,
            'processed' => 0,
            'event_waterline' => 0,
            'cancel_requested' => 0,
        ];
    }

    /**
     * @return list<\Hirale_AsyncIndex_Message_DrainEventsMessage>
     */
    private static function drainDispatches(): array
    {
        return array_values(array_filter(
            array_column(QueueManager::$dispatches, 'message'),
            static fn (object $message): bool => $message instanceof \Hirale_AsyncIndex_Message_DrainEventsMessage,
        ));
    }

    public function testOtherIndexersKeepDrainingWhileOneIsRebuilt(): void
    {
        $rebuilt = self::processWithEvent(1, 'catalog_category_product', 11);
        $price = self::processWithEvent(2, 'catalog_product_price', 12);
        $stock = self::processWithEvent(3, 'cataloginventory_stock', 13);
        $resource = $this->bootstrap($rebuilt, $price, $stock);
        $resource->connection->fetchColResult = [1];

        $result = (new \Hirale_AsyncIndex_Model_Runner())->drain();

        self::assertSame(2, $result['processed']);
        self::assertSame([], $rebuilt->processedEvents);
        self::assertCount(1, $price->processedEvents);
        self::assertCount(1, $stock->processedEvents);
        self::assertSame(['catalog_product' => [12, 13]], \Mage::$events[0]['data']['entities']);
    }

    public function testTheDependenciesARebuildLocksAreHeldBackButItsDependentsAreNot(): void
    {
        // reindexEverything() rebuilds a process's dependencies first, and
        // reindexAll() throws on any of them a drain has locked. Processes that
        // depend on the rebuilt one are not touched by it.
        $rebuilt = self::processWithEvent(1, 'catalog_url', 11);
        $rebuilt->depends = ['catalog_category_product'];
        $dependency = self::processWithEvent(2, 'catalog_category_product', 12);
        $dependency->depends = ['catalog_product_attribute'];
        $transitive = self::processWithEvent(3, 'catalog_product_attribute', 13);
        $dependent = self::processWithEvent(4, 'catalogsearch_fulltext', 14);
        $dependent->depends = ['catalog_url'];
        $resource = $this->bootstrap($rebuilt, $dependency, $transitive, $dependent);
        $resource->connection->fetchColResult = [1];

        (new \Hirale_AsyncIndex_Model_Runner())->drain();

        self::assertSame([], $rebuilt->processedEvents);
        self::assertSame([], $dependency->processedEvents);
        self::assertSame([], $transitive->processedEvents);
        self::assertCount(1, $dependent->processedEvents);
    }

    public function testHeldBackEventsDoNotKeepTheDrainContinuing(): void
    {
        // Counted, they would make every drain during a rebuild queue another
        // straight away, for nothing: the run's own end picks them up.
        $resource = $this->bootstrap(self::processWithEvent(1, 'catalog_url', 11));
        $resource->connection->fetchColResult = [1];

        (new \Hirale_AsyncIndex_Model_Runner())->drain();

        self::assertStringContainsString('pe.process_id NOT IN (1)', $resource->connection->lastFetchOneSql);
    }

    public function testWithoutAnActiveRunNothingIsHeldBack(): void
    {
        $process = self::processWithEvent(1, 'catalog_url', 11);
        $resource = $this->bootstrap($process);

        (new \Hirale_AsyncIndex_Model_Runner())->drain();

        self::assertCount(1, $process->processedEvents);
        self::assertStringNotContainsString('NOT IN', $resource->connection->lastFetchOneSql);
    }

    public function testFullBatchesTakeALockOfTheirOwn(): void
    {
        // Sharing the drain's lock is what made a thirteen-minute rebuild hold
        // back every indexer, whatever the drain itself decided to skip.
        $resource = $this->bootstrap(new \Mage_Index_Model_Process(id: 1, indexerCode: 'catalog_category_product'));
        $resource->connection->fetchAllResponses = [[self::runRow()], [['run_id' => 9, 'status' => 'succeeded']]];

        (new \Hirale_AsyncIndex_Model_FullReindex())->runBatch(9);

        $lock = \Mage::$singletons['core/lock'];
        self::assertSame(['hirale_asyncindex_full_reindex'], $lock->acquired);
        self::assertNotContains('hirale_asyncindex_drain', $lock->acquired);
    }

    public function testAFinishedRunQueuesADrainForTheEventsItHeldBack(): void
    {
        $process = new \Mage_Index_Model_Process(id: 1, indexerCode: 'catalog_category_product');
        $resource = $this->bootstrap($process);
        $resource->connection->fetchOneResult = 4;
        $resource->connection->fetchAllResponses = [[self::runRow()], [['run_id' => 9, 'status' => 'succeeded']]];

        (new \Hirale_AsyncIndex_Model_FullReindex())->runBatch(9);

        self::assertSame(1, $process->reindexEverythingCalls);
        $drains = self::drainDispatches();
        self::assertCount(1, $drains);
        self::assertSame('full_reindex_finished', $drains[0]->reason);

        // No key: a drain in flight may already have decided to skip this
        // process, and an enforced key would let it swallow this dispatch.
        $call = QueueManager::$dispatches[array_search($drains[0], array_column(QueueManager::$dispatches, 'message'), true)];
        self::assertNull($call['dedupeKey']);
        self::assertSame([], $call['stamps']);
        self::assertSame('index_drain', $call['queue']);
    }

    public function testARunThatStillHasBatchesLeftQueuesNoDrain(): void
    {
        $process = new \Mage_Index_Model_Process(id: 1, indexerCode: 'catalog_product_price');
        $process->indexer = new \Mage_Index_Model_Indexer_Abstract();
        $process->indexer->resource = new class {
            public function reindexProductIds(): void {}
        };
        $resource = $this->bootstrap($process);
        $resource->connection->fetchOneResult = 4;
        $resource->connection->fetchColResult = [1, 2, 3];
        $resource->connection->fetchAllResponses = [
            [self::runRow(['mode' => 'product', 'indexer_code' => 'catalog_product_price', 'total' => 10])],
            [['run_id' => 9, 'status' => 'running']],
        ];

        (new \Hirale_AsyncIndex_Model_FullReindex())->runBatch(9);

        self::assertSame([], self::drainDispatches());
    }

    public function testAFinishedRunWithNothingWaitingQueuesNoDrain(): void
    {
        $resource = $this->bootstrap(new \Mage_Index_Model_Process(id: 1, indexerCode: 'catalog_category_product'));
        $resource->connection->fetchAllResponses = [[self::runRow()], [['run_id' => 9, 'status' => 'succeeded']]];

        (new \Hirale_AsyncIndex_Model_FullReindex())->runBatch(9);

        self::assertSame([], self::drainDispatches());
    }

    public function testACanceledRunQueuesADrainForTheEventsItHeldBack(): void
    {
        $resource = $this->bootstrap(new \Mage_Index_Model_Process(id: 1, indexerCode: 'catalog_category_product'));
        $resource->connection->fetchOneResult = 2;
        $resource->connection->fetchAllResponses = [[self::runRow(['cancel_requested' => 1])]];

        (new \Hirale_AsyncIndex_Model_FullReindex())->runBatch(9);

        self::assertCount(1, self::drainDispatches());
    }

    public function testABatchDefersWhileADrainHoldsItsProcess(): void
    {
        // A drain that began before the run was queued can still be inside the
        // process; core's reindexAll() would throw and fail the whole run.
        $process = new \Mage_Index_Model_Process(id: 1, indexerCode: 'catalog_category_product');
        $process->locked = true;
        $resource = $this->bootstrap($process);
        $resource->connection->fetchAllResponses = [[self::runRow(['status' => 'queued'])]];

        $result = (new \Hirale_AsyncIndex_Model_FullReindex())->runBatch(9);

        self::assertTrue($result['locked']);
        self::assertSame(0, $process->reindexEverythingCalls);
        self::assertSame([], $process->resource->calls);
        self::assertSame([], $resource->connection->updates);

        self::assertCount(1, QueueManager::$dispatches);
        $retry = QueueManager::$dispatches[0];
        self::assertInstanceOf(\Hirale_AsyncIndex_Message_FullReindexBatchMessage::class, $retry['message']);
        self::assertSame(15, $retry['delaySeconds']);
    }

    public function testABatchDefersWhileADrainHoldsADependencyItWouldRebuild(): void
    {
        $process = new \Mage_Index_Model_Process(id: 1, indexerCode: 'catalog_url');
        $process->depends = ['catalog_category_product'];
        $dependency = new \Mage_Index_Model_Process(id: 2, indexerCode: 'catalog_category_product');
        $dependency->locked = true;
        $resource = $this->bootstrap($process, $dependency);
        $resource->connection->fetchAllResponses = [[self::runRow(['status' => 'queued'])]];

        $result = (new \Hirale_AsyncIndex_Model_FullReindex())->runBatch(9);

        self::assertTrue($result['locked']);
        self::assertSame(0, $process->reindexEverythingCalls);
    }

    public function testTheReconcilerStillDrainsWhileARunIsActive(): void
    {
        // It is the only crash recovery a drain has; skipping it for the length
        // of a rebuild left a killed drain unrecovered for that long.
        $resource = $this->bootstrap();
        $resource->connection->fetchOneResult = 3;
        $resource->connection->fetchAllResponses = [[self::runRow()]];

        (new \Hirale_AsyncIndex_Model_Reconciler())->execute();

        $messages = array_map(get_class(...), array_column(QueueManager::$dispatches, 'message'));
        self::assertSame([
            \Hirale_AsyncIndex_Message_FullReindexBatchMessage::class,
            \Hirale_AsyncIndex_Message_DrainEventsMessage::class,
        ], $messages);
        self::assertSame('reconciler', QueueManager::$dispatches[1]['message']->reason);
    }
}
