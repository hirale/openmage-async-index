<?php

declare(strict_types=1);

namespace HiraleAsyncIndex\Tests\Unit;

use Hirale\Queue\Bus;
use Maho\Queue\QueueManager;
use Maho\Queue\Stamp\DedupeKeyStamp;
use PHPUnit\Framework\TestCase;

class HelperDataTest extends TestCase
{
    protected function setUp(): void
    {
        Bus::reset();
        QueueManager::reset();
    }

    protected function tearDown(): void
    {
        \Mage::reset();
        Bus::reset();
        QueueManager::reset();
    }

    public function testConfigPathPrefixIsStable(): void
    {
        self::assertSame('hirale_asyncindex/settings/', \Hirale_AsyncIndex_Helper_Data::XML_PATH_PREFIX);
        self::assertSame('hirale_asyncindex_drain_context', \Hirale_AsyncIndex_Helper_Data::REGISTRY_DRAIN_CONTEXT);
        self::assertSame('hirale_asyncindex_full_reindex_context', \Hirale_AsyncIndex_Helper_Data::REGISTRY_FULL_REINDEX_CONTEXT);
    }

    public function testBridgePrefersMahoCoreQueueWhenTheModuleIsEnabled(): void
    {
        \Mage::$enabledModules['Maho_Queue'] = true;
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];

        $helper = new \Hirale_AsyncIndex_Helper_Data();

        self::assertTrue($helper->isQueueEnabled());
        self::assertTrue($helper->enqueueDrain('index_events'));
        self::assertCount(1, QueueManager::$dispatches);
        self::assertSame([], Bus::$dispatches);
    }

    public function testBridgeFallsBackToHiraleQueueWhenMahoQueueIsDisabled(): void
    {
        // The stubbed QueueManager class exists either way, so the module flag
        // is what actually decides the branch.
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];

        $helper = new \Hirale_AsyncIndex_Helper_Data();

        self::assertTrue($helper->isQueueEnabled());
        self::assertTrue($helper->enqueueDrain('index_events'));
        self::assertCount(1, Bus::$dispatches);
        self::assertSame([], QueueManager::$dispatches);
    }

    public function testBridgeReportsQueueUnavailableWithoutAnyBackend(): void
    {
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];

        $helper = new class extends \Hirale_AsyncIndex_Helper_Data {
            #[\Override]
            protected function _isHiraleQueueAvailable(): bool
            {
                return false;
            }
        };

        self::assertFalse($helper->isQueueEnabled());
        self::assertFalse($helper->enqueueDrain('index_events'));
        self::assertFalse($helper->enqueueFullReindexBatch(7));
        self::assertSame([], Bus::$dispatches);
        self::assertSame([], QueueManager::$dispatches);
    }

    public function testMahoDrainDispatchCarriesTheDedupeKeyOnTheDefaultQueue(): void
    {
        \Mage::$enabledModules['Maho_Queue'] = true;
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];

        self::assertTrue((new \Hirale_AsyncIndex_Helper_Data())->enqueueDrain(
            'index_events',
            42,
            'catalog_product',
            'save',
        ));

        $call = QueueManager::$dispatches[0];
        self::assertSame('default', $call['queue']);
        self::assertSame(\Hirale_AsyncIndex_Helper_Data::DRAIN_DEDUPE_KEY, $call['dedupeKey']);
        self::assertNull($call['delaySeconds']);
        self::assertSame([], $call['stamps']);

        $message = $call['message'];
        self::assertInstanceOf(\Hirale_AsyncIndex_Message_DrainEventsMessage::class, $message);
        self::assertSame('index_events', $message->reason);
        self::assertSame(42, $message->eventId);
        self::assertSame('catalog_product', $message->entity);
        self::assertSame('save', $message->type);
    }

    public function testMahoContinuationDrainKeepsTheKeyWithoutEnforcingIt(): void
    {
        // The handler's own row is still processing under this key; an enforced
        // dedupe would swallow the continuation and stall the drain chain.
        \Mage::$enabledModules['Maho_Queue'] = true;
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];

        $helper = new \Hirale_AsyncIndex_Helper_Data();
        $helper->withDrainContext(static fn(): bool => $helper->enqueueDrain('continuation'));

        $call = QueueManager::$dispatches[0];
        self::assertNull($call['dedupeKey']);
        self::assertCount(1, $call['stamps']);

        $stamp = $call['stamps'][0];
        self::assertInstanceOf(DedupeKeyStamp::class, $stamp);
        self::assertSame(\Hirale_AsyncIndex_Helper_Data::DRAIN_DEDUPE_KEY, $stamp->key);
        self::assertFalse($stamp->enforce);
    }

    public function testMahoFullReindexBatchUsesItsOwnQueueAndCarriesNoDedupeKey(): void
    {
        \Mage::$enabledModules['Maho_Queue'] = true;
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];

        self::assertTrue((new \Hirale_AsyncIndex_Helper_Data())->enqueueFullReindexBatch(7, 30));

        $call = QueueManager::$dispatches[0];
        self::assertSame(\Hirale_AsyncIndex_Helper_Data::QUEUE_FULL_REINDEX, $call['queue']);
        self::assertSame(30, $call['delaySeconds']);
        self::assertNull($call['dedupeKey']);
        self::assertSame(7, $call['message']->runId);
    }

    public function testMahoFullReindexBatchHonoursTheConfiguredQueueOverride(): void
    {
        \Mage::$enabledModules['Maho_Queue'] = true;
        \Mage::$config = [
            'hirale_asyncindex/settings/enabled' => '1',
            'hirale_asyncindex/settings/full_reindex_queue' => ' indexer ',
        ];

        self::assertTrue((new \Hirale_AsyncIndex_Helper_Data())->enqueueFullReindexBatch(7));

        self::assertSame('indexer', QueueManager::$dispatches[0]['queue']);
        self::assertNull(QueueManager::$dispatches[0]['delaySeconds']);
    }

    public function testMahoDispatchFailureIsSwallowedAndLogged(): void
    {
        \Mage::$enabledModules['Maho_Queue'] = true;
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];
        QueueManager::$nextException = new \RuntimeException('queue table missing');

        self::assertFalse((new \Hirale_AsyncIndex_Helper_Data())->enqueueDrain('index_events'));
    }

    public function testEnqueueDrainDispatchesDrainEventsMessage(): void
    {
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];

        $result = (new \Hirale_AsyncIndex_Helper_Data())->enqueueDrain(
            'index_events',
            42,
            'catalog_product',
            'save',
        );

        self::assertTrue($result);
        self::assertCount(1, Bus::$dispatches);
        self::assertSame('dispatch', Bus::$dispatches[0]['method']);

        $message = Bus::$dispatches[0]['message'];
        self::assertInstanceOf(\Hirale_AsyncIndex_Message_DrainEventsMessage::class, $message);
        self::assertSame('index_events', $message->reason);
        self::assertSame(42, $message->eventId);
        self::assertSame('catalog_product', $message->entity);
        self::assertSame('save', $message->type);
    }

    public function testEnqueueDrainReturnsFalseWhenModuleIsDisabled(): void
    {
        self::assertFalse((new \Hirale_AsyncIndex_Helper_Data())->enqueueDrain('index_events'));
        self::assertSame([], Bus::$dispatches);
    }

    public function testEnqueueDrainSwallowsDispatchFailureAndLogs(): void
    {
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];
        Bus::$nextException = new \RuntimeException('transport down');

        self::assertFalse((new \Hirale_AsyncIndex_Helper_Data())->enqueueDrain('index_events'));
    }

    public function testEnqueueFullReindexBatchRoutesToConfiguredQueue(): void
    {
        \Mage::$config = [
            'hirale_asyncindex/settings/enabled' => '1',
            'hirale_asyncindex/settings/full_reindex_queue' => ' indexer ',
        ];

        self::assertTrue((new \Hirale_AsyncIndex_Helper_Data())->enqueueFullReindexBatch(7));
        self::assertCount(1, Bus::$dispatches);
        self::assertSame('dispatchOnQueue', Bus::$dispatches[0]['method']);
        self::assertSame('indexer', Bus::$dispatches[0]['queue']);

        $message = Bus::$dispatches[0]['message'];
        self::assertInstanceOf(\Hirale_AsyncIndex_Message_FullReindexBatchMessage::class, $message);
        self::assertSame(7, $message->runId);
    }

    public function testEnqueueFullReindexBatchUsesDelayedDispatchWithoutQueueOverride(): void
    {
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];

        self::assertTrue((new \Hirale_AsyncIndex_Helper_Data())->enqueueFullReindexBatch(7, 30));
        self::assertCount(1, Bus::$dispatches);
        self::assertSame('dispatchDelayed', Bus::$dispatches[0]['method']);
        self::assertSame(30, Bus::$dispatches[0]['delaySeconds']);
    }

    public function testGetFullReindexQueueNameReturnsEmptyByDefault(): void
    {
        self::assertSame('', (new \Hirale_AsyncIndex_Helper_Data())->getFullReindexQueueName());
    }

    public function testGetFullReindexQueueNameTrimsConfiguredValue(): void
    {
        \Mage::$config = ['hirale_asyncindex/settings/full_reindex_queue' => '  indexer  '];

        self::assertSame('indexer', (new \Hirale_AsyncIndex_Helper_Data())->getFullReindexQueueName());
    }

    public function testHandlersExposeInvokableTypedSignatures(): void
    {
        foreach ([
            \Hirale_AsyncIndex_Model_DrainEventsHandler::class => \Hirale_AsyncIndex_Message_DrainEventsMessage::class,
            \Hirale_AsyncIndex_Model_FullReindexBatchHandler::class => \Hirale_AsyncIndex_Message_FullReindexBatchMessage::class,
        ] as $handler => $messageClass) {
            $method = new \ReflectionMethod($handler, '__invoke');
            $parameter = $method->getParameters()[0];

            self::assertSame($messageClass, (string) $parameter->getType());
            self::assertSame('void', (string) $method->getReturnType());
        }
    }

    public function testHandlersCarryTheMahoMessageHandlerAttribute(): void
    {
        // Maho compiles this attribute into vendor/composer/maho_attributes.php;
        // the message class is inferred from the first parameter, asserted above.
        foreach ([
            \Hirale_AsyncIndex_Model_DrainEventsHandler::class,
            \Hirale_AsyncIndex_Model_FullReindexBatchHandler::class,
        ] as $handler) {
            $method = new \ReflectionMethod($handler, '__invoke');

            self::assertCount(1, $method->getAttributes('Maho\\Config\\MessageHandler'));
        }
    }

    public function testAcquireIndexLockDelegatesToMahoCoreLockWhenIndexLockAbsent(): void
    {
        // Regression guard: OpenMage's Mage_Index_Model_Lock does not exist on
        // Maho. The old code called Mage_Index_Model_Lock::getInstance()
        // unconditionally and fatally errored on Maho, stalling every drain /
        // full-reindex run. The helper must fall back to Maho's core/lock.
        self::assertFalse(class_exists('Mage_Index_Model_Lock'));

        $lock = new \Mage_Core_Model_Lock();
        \Mage::$singletons['core/lock'] = $lock;

        $helper = new \Hirale_AsyncIndex_Helper_Data();

        self::assertTrue($helper->acquireIndexLock('hirale_asyncindex_drain'));
        self::assertSame(['hirale_asyncindex_drain'], $lock->acquired);

        $helper->releaseIndexLock('hirale_asyncindex_drain');
        self::assertSame(['hirale_asyncindex_drain'], $lock->released);
    }

    public function testAcquireIndexLockReturnsFalseWhenCoreLockHeld(): void
    {
        $lock = new \Mage_Core_Model_Lock();
        $lock->acquireResult = false;
        \Mage::$singletons['core/lock'] = $lock;

        self::assertFalse((new \Hirale_AsyncIndex_Helper_Data())->acquireIndexLock('hirale_asyncindex_drain'));
    }

    public function testFullReindexCallsEntityReindexOnIndexer(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../app/code/community/Hirale/AsyncIndex/Model/FullReindex.php',
        );

        self::assertIsString($source);
        self::assertStringContainsString('$indexer = $process->getIndexer();', $source);
        self::assertStringContainsString('$indexer->reindexEntity($ids);', $source);
        self::assertStringNotContainsString('$process->reindexEntity($ids);', $source);
    }
}
