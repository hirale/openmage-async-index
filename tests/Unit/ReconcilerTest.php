<?php

declare(strict_types=1);

namespace HiraleAsyncIndex\Tests\Unit;

use HiraleAsyncIndex\Tests\Support\FakeResource;
use Maho\Queue\QueueManager;
use PHPUnit\Framework\TestCase;

class ReconcilerTest extends TestCase
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

    private function bootstrap(int $pendingEvents): FakeResource
    {
        $resource = new FakeResource();
        $resource->connection->fetchOneResult = $pendingEvents;
        \Mage::$singletons['core/resource'] = $resource;
        \Mage::$singletons['hirale_asyncindex/modeManager'] = new \Hirale_AsyncIndex_Model_ModeManager();
        \Mage::$singletons['hirale_asyncindex/runner'] = new \Hirale_AsyncIndex_Model_Runner();
        \Mage::$singletons['hirale_asyncindex/fullReindex'] = new \Hirale_AsyncIndex_Model_FullReindex();
        \Mage::$enabledModules['Maho_Queue'] = true;
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];

        return $resource;
    }

    public function testPendingEventsDispatchADrainWithoutADedupeKey(): void
    {
        // This is the recovery path for a worker killed mid-drain: its row stays
        // processing for the five minutes the queue takes to call the claim
        // abandoned, and an enforced key would suppress exactly the dispatch
        // meant to get indexing moving again. Once a minute is the rate limit.
        $this->bootstrap(pendingEvents: 3);

        (new \Hirale_AsyncIndex_Model_Reconciler())->execute();

        self::assertCount(1, QueueManager::$dispatches);

        $call = QueueManager::$dispatches[0];
        self::assertInstanceOf(\Hirale_AsyncIndex_Message_DrainEventsMessage::class, $call['message']);
        self::assertSame('reconciler', $call['message']->reason);
        self::assertNull($call['dedupeKey']);
        self::assertSame([], $call['stamps']);
    }

    public function testNothingIsDispatchedWithoutPendingWork(): void
    {
        $this->bootstrap(pendingEvents: 0);

        (new \Hirale_AsyncIndex_Model_Reconciler())->execute();

        self::assertSame([], QueueManager::$dispatches);
    }

    public function testDisabledModuleOnlySyncsModes(): void
    {
        $this->bootstrap(pendingEvents: 3);
        \Mage::$config['hirale_asyncindex/settings/enabled'] = '0';

        (new \Hirale_AsyncIndex_Model_Reconciler())->execute();

        self::assertSame([], QueueManager::$dispatches);
    }
}
