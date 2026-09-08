<?php

declare(strict_types=1);

namespace HiraleAsyncIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;

class ReindexNotificationTest extends TestCase
{
    protected function tearDown(): void
    {
        \Mage::reset();
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchedPayload(): array
    {
        self::assertCount(1, \Mage::$events);
        self::assertSame(
            \Hirale_AsyncIndex_Helper_Data::EVENT_REINDEX_AFTER,
            \Mage::$events[0]['name'],
        );

        return \Mage::$events[0]['data'];
    }

    public function testTouchedIdsAreDedupedPerEntity(): void
    {
        // One product save registers the same event against several processes,
        // so the drain reports the id once per process it was handed to.
        (new \Hirale_AsyncIndex_Helper_Data())->notifyReindexed('drain', [
            'catalog_product' => [7, 7, 9, 7],
            'catalog_category' => [3],
        ]);

        $payload = $this->dispatchedPayload();
        self::assertSame('drain', $payload['source']);
        self::assertFalse($payload['full']);
        self::assertSame([7, 9], $payload['entities']['catalog_product']);
        self::assertSame([3], $payload['entities']['catalog_category']);
        self::assertNull($payload['indexer_code']);
    }

    public function testAnEventWithoutAnEntityPkInvalidatesThatEntityWholesale(): void
    {
        // Mass actions and store-scope changes name no single record; core logs
        // them with a null entity_pk, which the runner records as id 0.
        (new \Hirale_AsyncIndex_Helper_Data())->notifyReindexed('drain', [
            'catalog_product' => [7, 0, 9],
        ]);

        // An empty list means "every record of this entity".
        self::assertSame([], $this->dispatchedPayload()['entities']['catalog_product']);
    }

    public function testIdListsBeyondTheLimitDegradeToWholesaleEntities(): void
    {
        \Mage::$config = ['hirale_asyncindex/settings/invalidate_entity_limit' => '3'];

        (new \Hirale_AsyncIndex_Helper_Data())->notifyReindexed('drain', [
            'catalog_product' => [1, 2, 3, 4],
        ]);

        $payload = $this->dispatchedPayload();
        self::assertSame(['catalog_product' => []], $payload['entities']);
        // Still not a global invalidation: only these entity types are affected.
        self::assertFalse($payload['full']);
    }

    public function testIdListsAtTheLimitAreStillListed(): void
    {
        \Mage::$config = ['hirale_asyncindex/settings/invalidate_entity_limit' => '3'];

        (new \Hirale_AsyncIndex_Helper_Data())->notifyReindexed('drain', [
            'catalog_product' => [1, 2, 3],
        ]);

        self::assertSame([1, 2, 3], $this->dispatchedPayload()['entities']['catalog_product']);
    }

    public function testNothingIsAnnouncedWhenNothingWasTouched(): void
    {
        (new \Hirale_AsyncIndex_Helper_Data())->notifyReindexed('drain', []);

        self::assertSame([], \Mage::$events);
    }

    public function testAGlobalBatchAnnouncesItselfWithoutEntities(): void
    {
        (new \Hirale_AsyncIndex_Helper_Data())->notifyReindexed(
            'full_reindex',
            [],
            full: true,
            indexerCode: 'catalog_url',
        );

        $payload = $this->dispatchedPayload();
        self::assertTrue($payload['full']);
        self::assertSame([], $payload['entities']);
        self::assertSame('catalog_url', $payload['indexer_code']);
    }

    public function testCacheIsLeftAloneUnlessTheFallbackIsTurnedOn(): void
    {
        (new \Hirale_AsyncIndex_Helper_Data())->notifyReindexed('drain', ['catalog_product' => [7]]);

        self::assertSame([], \Mage::app()->cleanedTags);
    }

    public function testTheFallbackCleansPerRecordTags(): void
    {
        \Mage::$config = ['hirale_asyncindex/settings/clean_cache_after_reindex' => '1'];

        (new \Hirale_AsyncIndex_Helper_Data())->notifyReindexed('drain', [
            'catalog_product' => [7, 9],
            'catalog_category' => [3],
            // Not a tag this module knows; it still reaches observers.
            'cataloginventory_stock_item' => [11],
        ]);

        self::assertSame(
            [['catalog_product_7', 'catalog_product_9', 'catalog_category_3']],
            \Mage::app()->cleanedTags,
        );
    }

    public function testTheFallbackCleansEntityTagsWhenEverythingIsTouched(): void
    {
        \Mage::$config = ['hirale_asyncindex/settings/clean_cache_after_reindex' => '1'];

        (new \Hirale_AsyncIndex_Helper_Data())->notifyReindexed('full_reindex', [], full: true);

        self::assertSame([['catalog_product', 'catalog_category']], \Mage::app()->cleanedTags);
    }

    public function testAThrowingObserverNeverFailsTheRun(): void
    {
        // The message would be retried and the whole batch reindexed again.
        \Mage::$eventException = new \RuntimeException('observer exploded');

        (new \Hirale_AsyncIndex_Helper_Data())->notifyReindexed('drain', ['catalog_product' => [7]]);

        self::assertSame([], \Mage::$events);
    }
}
