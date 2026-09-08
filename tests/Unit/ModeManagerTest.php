<?php

declare(strict_types=1);

namespace HiraleAsyncIndex\Tests\Unit;

use HiraleAsyncIndex\Tests\Support\FakeResource;
use PHPUnit\Framework\TestCase;

class ModeManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        \Mage::reset();
    }

    private function bootstrapProcesses(\Mage_Index_Model_Process ...$processes): FakeResource
    {
        $resource = new FakeResource();
        \Mage::$singletons['core/resource'] = $resource;
        \Mage::$singletons['index/indexer'] = new \Mage_Index_Model_Indexer($processes);

        return $resource;
    }

    public function testTakeoverRecordsTheModeItIsTakingOver(): void
    {
        // Regression guard for the original bug: the state row used to be written
        // the first time a process was merely seen, so an indexer the operator
        // switched to manual later was taken over while its row still claimed the
        // mode it had before — and disabling async index restored that instead.
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];
        $manual = new \Mage_Index_Model_Process(
            id: 4,
            indexerCode: 'catalog_product_price',
            mode: \Mage_Index_Model_Process::MODE_MANUAL,
        );
        $resource = $this->bootstrapProcesses($manual);

        (new \Hirale_AsyncIndex_Model_ModeManager())->normalizeManagedModes();

        self::assertSame([\Mage_Index_Model_Process::MODE_REAL_TIME], $manual->savedModes);
        self::assertCount(1, $resource->connection->upserts);

        $upsert = $resource->connection->upserts[0];
        self::assertSame('hirale_asyncindex_process_state', $upsert['table']);
        self::assertSame(4, $upsert['values']['process_id']);
        self::assertSame(\Mage_Index_Model_Process::MODE_MANUAL, $upsert['values']['original_mode']);
        self::assertSame(\Mage_Index_Model_Process::MODE_REAL_TIME, $upsert['values']['managed_mode']);
        self::assertSame(1, $upsert['values']['is_managed']);

        // original_mode must be in the updated column list, otherwise a row left
        // over from an earlier takeover keeps its stale value.
        self::assertContains('original_mode', $upsert['fields']);
        self::assertNotContains('created_at', $upsert['fields']);
    }

    public function testTakeoverIsLogged(): void
    {
        // The operator's change silently not sticking is otherwise
        // indistinguishable from a bug.
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];
        $this->bootstrapProcesses(new \Mage_Index_Model_Process(
            id: 4,
            indexerCode: 'catalog_product_price',
            mode: \Mage_Index_Model_Process::MODE_MANUAL,
        ));

        (new \Hirale_AsyncIndex_Model_ModeManager())->normalizeManagedModes();

        self::assertCount(1, \Mage::$logs);
        self::assertStringContainsString('catalog_product_price', \Mage::$logs[0]['message']);
        self::assertSame(\Hirale_AsyncIndex_Helper_Data::LOG_FILE, \Mage::$logs[0]['file']);
        self::assertSame(\Hirale_AsyncIndex_Helper_Data::LOG_LEVEL_NOTICE, \Mage::$logs[0]['level']);
        // Not forced: the reverted mode is visible in the admin anyway, so this
        // respects a store that turned logging off.
        self::assertFalse(\Mage::$logs[0]['force']);
    }

    public function testProcessesAlreadyOnRealTimeCostNoQueriesAtAll(): void
    {
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];
        $realTime = new \Mage_Index_Model_Process(id: 5, indexerCode: 'catalog_url');
        $resource = $this->bootstrapProcesses($realTime);

        (new \Hirale_AsyncIndex_Model_ModeManager())->normalizeManagedModes();

        self::assertSame([], $realTime->savedModes);
        self::assertSame([], $resource->connection->upserts);
        self::assertSame([], $resource->connection->inserts);
        self::assertSame([], $resource->connection->updates);
    }

    public function testRestoreReturnsManagedProcessesAndClearsTheTable(): void
    {
        $resource = new FakeResource();
        $resource->connection->fetchAllResponses[] = [
            ['process_id' => 4, 'original_mode' => \Mage_Index_Model_Process::MODE_MANUAL],
        ];
        \Mage::$singletons['core/resource'] = $resource;

        $process = new \Mage_Index_Model_Process(id: 4, indexerCode: 'catalog_product_price');
        \Mage::$processes[4] = $process;

        (new \Hirale_AsyncIndex_Model_ModeManager())->restoreManagedModes();

        self::assertSame([\Mage_Index_Model_Process::MODE_MANUAL], $process->savedModes);
        self::assertCount(1, $resource->connection->deletes);
        self::assertSame('hirale_asyncindex_process_state', $resource->connection->deletes[0]['table']);
    }

    public function testSyncRestoresOnlyWhenTheRestoreFlagIsSet(): void
    {
        $resource = new FakeResource();
        \Mage::$singletons['core/resource'] = $resource;
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '0'];

        (new \Hirale_AsyncIndex_Model_ModeManager())->sync();

        self::assertSame([], $resource->connection->deletes);
    }
}
