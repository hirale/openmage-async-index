<?php

declare(strict_types=1);

namespace HiraleAsyncIndex\Tests\Unit;

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

    public function testEnqueueTaskUsesQueueTaskModelContract(): void
    {
        $task = new QueueTaskStub();
        \Mage::$helper = new QueueHelperStub();
        \Mage::$model = $task;
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
                'data' => ['action' => 'drain_events'],
                'retryCount' => 5,
                'retryDelay' => 12,
                'timeout' => 99,
            ],
        ], $task->calls);
    }

    public function testQueueHandlerSignatureMatchesQueueInterface(): void
    {
        $method = new \ReflectionMethod(\Hirale_AsyncIndex_Model_QueueHandler::class, 'handle');
        $parameter = $method->getParameters()[0];

        self::assertFalse($parameter->hasType());
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

class QueueHelperStub
{
    public function getRedis(): void
    {
    }
}

class QueueTaskStub
{
    /** @var list<array{handler:string,data:array<string, mixed>,retryCount:int,retryDelay:int,timeout:int}> */
    public array $calls = [];

    /**
     * @param array<string, mixed> $data
     */
    public function addTask(string $handler, array $data, int $retryCount = 3, int $retryDelay = 60, int $timeout = 60): void
    {
        $this->calls[] = [
            'handler' => $handler,
            'data' => $data,
            'retryCount' => $retryCount,
            'retryDelay' => $retryDelay,
            'timeout' => $timeout,
        ];
    }
}
