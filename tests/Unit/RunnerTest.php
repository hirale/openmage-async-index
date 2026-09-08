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
    }

    public function testAFailedEventIsNotAnnouncedAsTouched(): void
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
