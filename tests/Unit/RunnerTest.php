<?php

declare(strict_types=1);

namespace HiraleAsyncIndex\Tests\Unit;

use Hirale\Queue\Bus;
use HiraleAsyncIndex\Tests\Support\FakeResource;
use PHPUnit\Framework\TestCase;

class RunnerTest extends TestCase
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

    private function bootstrap(\Mage_Index_Model_Process ...$processes): FakeResource
    {
        $resource = new FakeResource();
        \Mage::$singletons['core/resource'] = $resource;
        \Mage::$singletons['core/lock'] = new \Mage_Core_Model_Lock();
        \Mage::$singletons['index/indexer'] = new \Mage_Index_Model_Indexer($processes);
        \Mage::$singletons['hirale_asyncindex/fullReindex'] = new \Hirale_AsyncIndex_Model_FullReindex();
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];

        return $resource;
    }

    public function testDrainAnnouncesTheRecordsItHandedToIndexers(): void
    {
        $process = new \Mage_Index_Model_Process(id: 1, indexerCode: 'catalog_product_price');
        $process->unprocessedEvents = [
            new \Mage_Index_Model_Event(id: 1, entity: 'catalog_product', entityPk: 7),
            new \Mage_Index_Model_Event(id: 2, entity: 'catalog_product', entityPk: 9),
        ];
        $this->bootstrap($process);

        $result = (new \Hirale_AsyncIndex_Model_Runner())->drain();

        self::assertSame(2, $result['processed']);
        self::assertCount(2, $process->processedEvents);

        self::assertCount(1, \Mage::$events);
        self::assertSame(
            \Hirale_AsyncIndex_Helper_Data::EVENT_REINDEX_AFTER,
            \Mage::$events[0]['name'],
        );
        self::assertSame(
            ['catalog_product' => [7, 9]],
            \Mage::$events[0]['data']['entities'],
        );
        self::assertSame('drain', \Mage::$events[0]['data']['source']);
    }

    public function testTheAnnouncementLeavesTheDrainContextBehind(): void
    {
        // An observer that saves a model must not re-enter indexing from inside
        // the drain, so the event is dispatched after the context is dropped
        // and the index lock released.
        $process = new \Mage_Index_Model_Process(id: 1, indexerCode: 'catalog_product_price');
        $process->unprocessedEvents = [
            new \Mage_Index_Model_Event(id: 1, entity: 'catalog_product', entityPk: 7),
        ];
        $this->bootstrap($process);

        (new \Hirale_AsyncIndex_Model_Runner())->drain();

        self::assertSame([], \Mage::$events[0]['registry']);

        $lock = \Mage::$singletons['core/lock'];
        self::assertSame(['hirale_asyncindex_drain'], $lock->released);

        // Nothing had been announced yet when the lock went back, and something
        // was announced by the end: the dispatch is after the release.
        self::assertSame(0, $lock->releaseLog[0]['events_dispatched']);
        self::assertCount(1, \Mage::$events);
    }

    public function testEventsCoreMarkedFailedAreCountedAndWarnedAbout(): void
    {
        // Core catches the indexer's exception and records the failure on the
        // event, returning normally — so the only way to notice is the status it
        // left behind. Nothing ever retries these.
        $process = new \Mage_Index_Model_Process(id: 1, indexerCode: 'catalog_product_price');
        $process->markEventsFailed = true;
        $process->unprocessedEvents = [
            new \Mage_Index_Model_Event(id: 1, entity: 'catalog_product', entityPk: 7),
        ];
        $this->bootstrap($process);

        $result = (new \Hirale_AsyncIndex_Model_Runner())->drain();

        self::assertSame(1, $result['errors']);

        self::assertCount(1, \Mage::$logs);
        self::assertStringContainsString('not retried', \Mage::$logs[0]['message']);
        self::assertStringContainsString('hirale:asyncindex:events', \Mage::$logs[0]['message']);
        self::assertSame(
            \Hirale_AsyncIndex_Helper_Data::LOG_LEVEL_WARNING,
            \Mage::$logs[0]['level'],
        );
        // Forced: a store with dev/log/active off would otherwise drop the only
        // signal that the index is drifting.
        self::assertTrue(\Mage::$logs[0]['force']);

        // Still announced: the indexer may have written part of the record, and
        // invalidating one record too many is cheaper than missing one.
        self::assertSame(['catalog_product' => [7]], \Mage::$events[0]['data']['entities']);
    }

    public function testASuccessfulDrainWarnsAboutNothing(): void
    {
        $process = new \Mage_Index_Model_Process(id: 1, indexerCode: 'catalog_product_price');
        $process->unprocessedEvents = [
            new \Mage_Index_Model_Event(id: 1, entity: 'catalog_product', entityPk: 7),
        ];
        $this->bootstrap($process);

        $result = (new \Hirale_AsyncIndex_Model_Runner())->drain();

        self::assertSame(0, $result['errors']);
        self::assertSame([], \Mage::$logs);
    }

    public function testFailedEventsCanBeListedAndCounted(): void
    {
        $resource = $this->bootstrap();
        $resource->connection->fetchOneResult = 4;
        $resource->connection->fetchAllResponses[] = [
            [
                'event_id' => 12,
                'process_id' => 1,
                'indexer_code' => 'catalog_product_price',
                'entity' => 'catalog_product',
                'entity_pk' => 7,
                'type' => 'save',
                'created_at' => '2026-09-08 09:00:00',
            ],
        ];

        $runner = new \Hirale_AsyncIndex_Model_Runner();

        self::assertSame(4, $runner->countFailedEvents());

        $rows = $runner->listFailedEvents(10);
        self::assertCount(1, $rows);
        self::assertSame(12, $rows[0]['event_id']);
        self::assertStringContainsString("status = 'error'", $resource->connection->lastFetchAllSql);
        self::assertStringContainsString('index_event', $resource->connection->lastFetchAllSql);
    }

    public function testCompletedEventRowsCanBePruned(): void
    {
        $resource = $this->bootstrap();

        (new \Hirale_AsyncIndex_Model_Runner())->pruneCompletedEvents();

        self::assertCount(1, $resource->connection->deletes);
        self::assertSame('index_process_event', $resource->connection->deletes[0]['table']);
        self::assertSame(
            \Mage_Index_Model_Process::EVENT_STATUS_DONE,
            $resource->connection->deletes[0]['where']['status = ?'],
        );
    }

    public function testAnEventWhoseIndexerThrewPastCoreIsNotAnnouncedAsTouched(): void
    {
        $process = new \Mage_Index_Model_Process(id: 1, indexerCode: 'catalog_product_price');
        $process->processEventException = new \RuntimeException('indexer blew up');
        $process->unprocessedEvents = [
            new \Mage_Index_Model_Event(id: 1, entity: 'catalog_product', entityPk: 7),
        ];
        $this->bootstrap($process);

        $result = (new \Hirale_AsyncIndex_Model_Runner())->drain();

        self::assertSame(1, $result['errors']);
        self::assertSame([], \Mage::$events);
    }

    public function testNothingIsAnnouncedWhenThereWasNothingToDrain(): void
    {
        $this->bootstrap(new \Mage_Index_Model_Process(id: 1, indexerCode: 'catalog_product_price'));

        (new \Hirale_AsyncIndex_Model_Runner())->drain();

        self::assertSame([], \Mage::$events);
    }

    public function testManualProcessesAreSkipped(): void
    {
        $process = new \Mage_Index_Model_Process(
            id: 1,
            indexerCode: 'catalog_product_price',
            mode: \Mage_Index_Model_Process::MODE_MANUAL,
        );
        $process->unprocessedEvents = [
            new \Mage_Index_Model_Event(id: 1, entity: 'catalog_product', entityPk: 7),
        ];
        $this->bootstrap($process);

        (new \Hirale_AsyncIndex_Model_Runner())->drain();

        self::assertSame([], $process->processedEvents);
        self::assertSame([], \Mage::$events);
    }

    public function testALockedIndexIsLeftForTheHolder(): void
    {
        $this->bootstrap(new \Mage_Index_Model_Process(id: 1, indexerCode: 'catalog_product_price'));
        \Mage::$singletons['core/lock']->acquireResult = false;

        $result = (new \Hirale_AsyncIndex_Model_Runner())->drain();

        self::assertTrue($result['locked']);
        self::assertSame([], \Mage::$events);
    }
}
