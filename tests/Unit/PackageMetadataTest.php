<?php

declare(strict_types=1);

namespace HiraleAsyncIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;

class PackageMetadataTest extends TestCase
{
    public function testComposerMetadataMapsMagentoModuleFiles(): void
    {
        $composer = json_decode(
            (string) file_get_contents(__DIR__ . '/../../composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame('hirale/openmage-async-index', $composer['name']);
        self::assertSame('magento-module', $composer['type']);
        self::assertSame('dev-master', $composer['require']['hirale/openmage-redis-queue']);
        self::assertContains(
            ['src/app/etc/modules/Hirale_AsyncIndex.xml', 'app/etc/modules/Hirale_AsyncIndex.xml'],
            $composer['extra']['map'],
        );
        self::assertContains(
            ['src/app/code/community/Hirale/AsyncIndex', 'app/code/community/Hirale/AsyncIndex'],
            $composer['extra']['map'],
        );
    }

    public function testModuleDeclarationDependsOnIndexAndQueue(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../src/app/etc/modules/Hirale_AsyncIndex.xml');

        self::assertNotFalse($xml);
        self::assertSame('true', (string) $xml->modules->Hirale_AsyncIndex->active);
        self::assertSame('community', (string) $xml->modules->Hirale_AsyncIndex->codePool);
        self::assertTrue(isset($xml->modules->Hirale_AsyncIndex->depends->Mage_Index));
        self::assertTrue(isset($xml->modules->Hirale_AsyncIndex->depends->Hirale_Queue));
    }

    public function testConfigRewritesIndexerAndProcessWithoutAdminRouterOverride(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../src/app/code/community/Hirale/AsyncIndex/etc/config.xml');

        self::assertNotFalse($xml);
        self::assertSame('Hirale_AsyncIndex_Model_Indexer', (string) $xml->global->models->index->rewrite->indexer);
        self::assertSame('Hirale_AsyncIndex_Model_Process', (string) $xml->global->models->index->rewrite->process);
        self::assertFalse(isset($xml->admin->routers));
    }

    public function testAsyncAutomationDefaultsAreEnabledExceptMainSwitch(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../src/app/code/community/Hirale/AsyncIndex/etc/config.xml');
        $settings = $xml->default->hirale_asyncindex->settings;

        self::assertSame('0', (string) $settings->enabled);
        self::assertSame('1', (string) $settings->auto_manage_modes);
        self::assertSame('1', (string) $settings->restore_modes_on_disable);
        self::assertSame('1', (string) $settings->auto_reindex_required);
        self::assertSame('500', (string) $settings->full_batch_size);
    }

    public function testEnabledBackendWarnsWhenQueueIsDisabled(): void
    {
        $backend = file_get_contents(
            __DIR__ . '/../../src/app/code/community/Hirale/AsyncIndex/Model/System/Config/Backend/Enabled.php',
        );

        self::assertIsString($backend);
        self::assertStringContainsString('isQueueEnabled()', $backend);
        self::assertStringContainsString('addWarning', $backend);
        self::assertStringContainsString('Hirale Queue is unavailable', $backend);
    }
}
