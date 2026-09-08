<?php

declare(strict_types=1);

namespace HiraleAsyncIndex\Tests\Unit;

use HiraleAsyncIndex\Tests\Support\FakeResource;
use PHPUnit\Framework\TestCase;

class BatchDurationTest extends TestCase
{
    protected function tearDown(): void
    {
        \Mage::reset();
    }

    public function testClaimTimeoutFollowsTheBackendInUse(): void
    {
        $helper = new \Hirale_AsyncIndex_Helper_Data();

        // hirale/queue: the Symfony Redis transport's redeliver_timeout default.
        self::assertSame(3600, $helper->getClaimTimeoutSeconds());
        self::assertSame(3540, $helper->getBatchWarnSeconds());
        self::assertStringContainsString('another consumer', $helper->getClaimTimeoutNote());

        // Maho: DbTransport::ABANDONED_AFTER_SECONDS.
        \Mage::$enabledModules['Maho_Queue'] = true;
        self::assertSame(300, $helper->getClaimTimeoutSeconds());
        self::assertSame(240, $helper->getBatchWarnSeconds());
        self::assertStringContainsString('never redelivers', $helper->getClaimTimeoutNote());
    }

    public function testNoQueueMeansNoThresholdToWarnAbout(): void
    {
        $helper = new class extends \Hirale_AsyncIndex_Helper_Data {
            #[\Override]
            protected function _isHiraleQueueAvailable(): bool
            {
                return false;
            }
        };

        self::assertSame(0, $helper->getClaimTimeoutSeconds());
        self::assertNull($helper->getBatchWarnSeconds());
        self::assertSame('', $helper->getClaimTimeoutNote());
    }

    /**
     * @param array<int, array<int, array<string, mixed>>> $extraRows
     */
    private function runGlobalBatch(array $extraRows = []): FakeResource
    {
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];
        \Mage::$singletons['core/lock'] = new \Mage_Core_Model_Lock();

        $resource = new FakeResource();
        $resource->connection->updateResult = 1;
        $resource->connection->fetchAllResponses = array_merge([
            [[
                'run_id' => 9,
                'process_id' => 3,
                'indexer_code' => 'catalog_url',
                'mode' => 'global',
                'status' => 'queued',
                'cursor_value' => 0,
                'total' => 1,
                'processed' => 0,
                'event_waterline' => 0,
                'cancel_requested' => 0,
            ]],
        ], $extraRows, [
            [['run_id' => 9, 'status' => 'succeeded']],
        ]);
        \Mage::$singletons['core/resource'] = $resource;
        \Mage::$processes[3] = new \Mage_Index_Model_Process(id: 3, indexerCode: 'catalog_url');

        (new \Hirale_AsyncIndex_Model_FullReindex())->runBatch(9);

        return $resource;
    }

    public function testEveryBatchRecordsHowLongItTook(): void
    {
        $this->runGlobalBatch();

        self::assertCount(1, \Mage::$logs);
        self::assertStringContainsString('global batch for "catalog_url" took', \Mage::$logs[0]['message']);
        // Informational, so a store with logging off pays nothing for it.
        self::assertFalse(\Mage::$logs[0]['force']);
    }

    public function testABatchPastTheThresholdWarnsWithTheCatalogScale(): void
    {
        // Any duration counts as long here; a real one cannot be simulated in a
        // unit test, and the threshold itself is covered above.
        \Mage::$asyncIndexHelper = new class extends \Hirale_AsyncIndex_Helper_Data {
            #[\Override]
            public function getBatchWarnSeconds(): ?int
            {
                return 0;
            }
        };

        $this->runGlobalBatch([[['products' => 3220, 'categories' => 55]]]);

        self::assertCount(2, \Mage::$logs);
        $warning = \Mage::$logs[1];

        self::assertSame(\Hirale_AsyncIndex_Helper_Data::LOG_LEVEL_WARNING, $warning['level']);
        // Forced: the store this bites has no reason to have logging on.
        self::assertTrue($warning['force']);
        self::assertStringContainsString('catalog_url', $warning['message']);
        self::assertStringContainsString('over 3220 products and 55 categories', $warning['message']);
        // Says what the backend will do and that re-queueing is harmless.
        self::assertStringContainsString('no-op', $warning['message']);
        self::assertStringContainsString('cannot split it further', $warning['message']);
    }

    public function testAProductBatchIsToldToLowerTheBatchSizeInstead(): void
    {
        \Mage::$asyncIndexHelper = new class extends \Hirale_AsyncIndex_Helper_Data {
            #[\Override]
            public function getBatchWarnSeconds(): ?int
            {
                return 0;
            }
        };
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
            [['products' => 3220, 'categories' => 55]],
            [['run_id' => 4, 'status' => 'running']],
        ];
        \Mage::$singletons['core/resource'] = $resource;

        $process = new \Mage_Index_Model_Process(id: 3, indexerCode: 'catalog_product_price');
        $process->indexer = new \Mage_Index_Model_Indexer_Abstract();
        \Mage::$processes[3] = $process;

        (new \Hirale_AsyncIndex_Model_FullReindex())->runBatch(4);

        $warning = \Mage::$logs[1];
        self::assertStringContainsString('Lower Full Reindex Batch Size', $warning['message']);
        self::assertStringNotContainsString('cannot split it further', $warning['message']);
    }

    public function testAReDeliveredBatchForAFinishedRunDoesNothing(): void
    {
        // The queue reports a long-running claim abandoned; an operator may
        // re-queue it. By the time it lands the run has finished, and repeating
        // a full rebuild would be the real damage.
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];
        \Mage::$singletons['core/lock'] = new \Mage_Core_Model_Lock();

        $resource = new FakeResource();
        $resource->connection->fetchAllResponses = [
            [['run_id' => 9, 'process_id' => 3, 'indexer_code' => 'catalog_url', 'status' => 'succeeded']],
        ];
        \Mage::$singletons['core/resource'] = $resource;

        $process = new \Mage_Index_Model_Process(id: 3, indexerCode: 'catalog_url');
        \Mage::$processes[3] = $process;

        $result = (new \Hirale_AsyncIndex_Model_FullReindex())->runBatch(9);

        self::assertSame(0, $result['processed']);
        self::assertSame(0, $process->reindexEverythingCalls);
        self::assertSame([], $process->resource->calls);
        self::assertSame([], $resource->connection->updates);
        self::assertSame([], \Mage::$events);
    }

    public function testABatchThatCannotTakeTheIndexLockDefersInsteadOfRunning(): void
    {
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];
        $lock = new \Mage_Core_Model_Lock();
        $lock->acquireResult = false;
        \Mage::$singletons['core/lock'] = $lock;
        \Mage::$singletons['core/resource'] = new FakeResource();

        $process = new \Mage_Index_Model_Process(id: 3, indexerCode: 'catalog_url');
        \Mage::$processes[3] = $process;

        $result = (new \Hirale_AsyncIndex_Model_FullReindex())->runBatch(9);

        self::assertTrue($result['locked']);
        self::assertSame(0, $process->reindexEverythingCalls);
    }
}
