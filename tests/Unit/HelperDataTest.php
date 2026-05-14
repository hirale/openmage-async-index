<?php

declare(strict_types=1);

namespace HiraleAsyncIndex\Tests\Unit;

use HiraleAsyncIndex\Tests\Support\QueueHelperStub;
use HiraleAsyncIndex\Tests\Support\QueueStub;
use PHPUnit\Framework\TestCase;

class HelperDataTest extends TestCase
{
    protected function tearDown(): void
    {
        \Mage::reset();
    }

    public function testConfigPathPrefixIsStable(): void
    {
        self::assertSame('hirale_asyncindex/settings/', \Hirale_AsyncIndex_Helper_Data::XML_PATH_PREFIX);
        self::assertSame('hirale_asyncindex_drain_context', \Hirale_AsyncIndex_Helper_Data::REGISTRY_DRAIN_CONTEXT);
        self::assertSame('hirale_asyncindex_full_reindex_context', \Hirale_AsyncIndex_Helper_Data::REGISTRY_FULL_REINDEX_CONTEXT);
    }

    public function testQueueEnabledUsesInstalledQueueHelperCapability(): void
    {
        \Mage::$helper = new QueueHelperStub();

        self::assertTrue((new \Hirale_AsyncIndex_Helper_Data())->isQueueEnabled());
    }

    public function testQueueDisabledWhenQueueHelperIsUnavailable(): void
    {
        self::assertFalse((new \Hirale_AsyncIndex_Helper_Data())->isQueueEnabled());
    }

    public function testEnqueueTaskCallsQueueEnqueueWithMergedOptions(): void
    {
        $queue = new QueueStub();
        \Mage::$helper = new QueueHelperStub();
        \Mage::$model = $queue;
        \Mage::$config = [
            'hirale_asyncindex/settings/enabled' => '1',
            'hirale_asyncindex/settings/max_attempts' => '5',
            'hirale_asyncindex/settings/retry_delay' => '12',
            'hirale_asyncindex/settings/full_max_runtime_seconds' => '80',
        ];

        $result = (new \Hirale_AsyncIndex_Helper_Data())->enqueueTask(
            ['action' => 'drain_events'],
            ['timeout' => 99],
        );

        self::assertTrue($result);
        self::assertSame([
            [
                'handler' => \Hirale_AsyncIndex_Model_QueueHandler::class,
                'payload' => ['action' => 'drain_events'],
                'options' => [
                    'max_attempts' => 5,
                    'retry_delay' => 12,
                    'timeout' => 99,
                ],
            ],
        ], $queue->calls);
    }

    public function testEnqueueDrainAttachesCoalesceJobId(): void
    {
        $queue = new QueueStub();
        \Mage::$helper = new QueueHelperStub();
        \Mage::$model = $queue;
        \Mage::$config = [
            'hirale_asyncindex/settings/enabled' => '1',
            'hirale_asyncindex/settings/coalesce_ttl_seconds' => '10',
        ];

        self::assertTrue((new \Hirale_AsyncIndex_Helper_Data())->enqueueDrain());
        self::assertCount(1, $queue->calls);
        self::assertArrayHasKey('job_id', $queue->calls[0]['options']);
        self::assertStringStartsWith('hirale_asyncindex_drain_', $queue->calls[0]['options']['job_id']);
    }

    public function testEnqueueDrainForceSkipsJobIdSoContinuationsAlwaysFire(): void
    {
        $queue = new QueueStub();
        \Mage::$helper = new QueueHelperStub();
        \Mage::$model = $queue;
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];

        self::assertTrue((new \Hirale_AsyncIndex_Helper_Data())->enqueueDrain([], true));
        self::assertCount(1, $queue->calls);
        self::assertArrayNotHasKey('job_id', $queue->calls[0]['options']);
    }

    public function testEnqueueTaskSwallowsDuplicateJobIdAsCoalesced(): void
    {
        $queue = new QueueStub();
        $queue->nextException = new \RuntimeException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
        \Mage::$helper = new QueueHelperStub();
        \Mage::$model = $queue;
        \Mage::$config = ['hirale_asyncindex/settings/enabled' => '1'];

        $result = (new \Hirale_AsyncIndex_Helper_Data())->enqueueTask(
            ['action' => 'drain_events'],
            ['job_id' => 'hirale_asyncindex_drain_42'],
        );

        self::assertFalse($result);
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

    public function testQueueHandlerSatisfiesQueueInterfaceSignature(): void
    {
        $method = new \ReflectionMethod(\Hirale_AsyncIndex_Model_QueueHandler::class, 'handle');
        $parameter = $method->getParameters()[0];
        $returnType = $method->getReturnType();

        self::assertTrue($parameter->hasType());
        self::assertSame('array', (string) $parameter->getType());
        self::assertNotNull($returnType);
        self::assertSame('void', (string) $returnType);
    }

    public function testFullReindexCallsEntityReindexOnIndexer(): void
    {
        $source = file_get_contents(
            __DIR__ . '/../../src/app/code/community/Hirale/AsyncIndex/Model/FullReindex.php',
        );

        self::assertIsString($source);
        self::assertStringContainsString('$indexer = $process->getIndexer();', $source);
        self::assertStringContainsString('$indexer->reindexEntity($ids);', $source);
        self::assertStringNotContainsString('$process->reindexEntity($ids);', $source);
    }
}
