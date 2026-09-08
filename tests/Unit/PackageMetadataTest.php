<?php

declare(strict_types=1);

namespace HiraleAsyncIndex\Tests\Unit;

use PHPUnit\Framework\TestCase;

class PackageMetadataTest extends TestCase
{
    public function testComposerMetadataIsDualPlatform(): void
    {
        $composer = json_decode(
            (string) file_get_contents(__DIR__ . '/../../composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame('hirale/openmage-async-index', $composer['name']);
        self::assertSame('magento-module', $composer['type']);
        self::assertArrayNotHasKey('hirale/queue', $composer['require']);
        self::assertArrayNotHasKey('mahocommerce/maho', $composer['require']);
        // OpenMage users pull the queue backend in themselves; Maho dispatches
        // through core Maho_Queue and must not drag hirale/queue along.
        self::assertArrayHasKey('hirale/queue', $composer['suggest']);
        self::assertSame('<26.5', $composer['conflict']['mahocommerce/maho']);
        self::assertSame('<20.17', $composer['conflict']['openmage/magento-lts']);
        self::assertContains(
            ['app/etc/modules/Hirale_AsyncIndex.xml', 'app/etc/modules/Hirale_AsyncIndex.xml'],
            $composer['extra']['map'],
        );
        self::assertContains(
            ['app/code/community/Hirale/AsyncIndex', 'app/code/community/Hirale/AsyncIndex'],
            $composer['extra']['map'],
        );
        self::assertSame(
            'lib/MahoCLI/Commands/',
            $composer['autoload']['psr-4']['MahoCLI\\Commands\\'],
        );
    }

    public function testModuleDeclarationDependsOnIndexOnly(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../app/etc/modules/Hirale_AsyncIndex.xml');

        self::assertNotFalse($xml);
        self::assertSame('true', (string) $xml->modules->Hirale_AsyncIndex->active);
        self::assertSame('community', (string) $xml->modules->Hirale_AsyncIndex->codePool);
        self::assertTrue(isset($xml->modules->Hirale_AsyncIndex->depends->Mage_Index));
        // A hard Hirale_Queue dependency aborts config loading on a Maho store,
        // where the module dispatches through core Maho_Queue instead.
        self::assertFalse(isset($xml->modules->Hirale_AsyncIndex->depends->Hirale_Queue));
    }

    public function testConfigRegistersBothQueueBackends(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../app/code/community/Hirale/AsyncIndex/etc/config.xml');

        self::assertNotFalse($xml);
        self::assertSame('slow', (string) $xml->global->queue->routing->full_reindex);
        self::assertSame(
            'hirale_asyncindex/drainEventsHandler',
            (string) $xml->global->hirale_queue->handlers->Hirale_AsyncIndex_Message_DrainEventsMessage,
        );
        self::assertSame(
            'hirale_asyncindex/fullReindexBatchHandler',
            (string) $xml->global->hirale_queue->handlers->Hirale_AsyncIndex_Message_FullReindexBatchMessage,
        );
    }

    public function testConfigRewritesIndexerAndProcessWithoutAdminRouterOverride(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../app/code/community/Hirale/AsyncIndex/etc/config.xml');

        self::assertNotFalse($xml);
        self::assertSame('Hirale_AsyncIndex_Model_Indexer', (string) $xml->global->models->index->rewrite->indexer);
        self::assertSame('Hirale_AsyncIndex_Model_Process', (string) $xml->global->models->index->rewrite->process);
        self::assertFalse(isset($xml->admin->routers));
    }

    public function testAsyncAutomationDefaultsAreEnabledExceptMainSwitch(): void
    {
        $xml = simplexml_load_file(__DIR__ . '/../../app/code/community/Hirale/AsyncIndex/etc/config.xml');
        $settings = $xml->default->hirale_asyncindex->settings;

        self::assertSame('0', (string) $settings->enabled);
        self::assertSame('1', (string) $settings->auto_manage_modes);
        self::assertSame('1', (string) $settings->restore_modes_on_disable);
        self::assertSame('1', (string) $settings->auto_reindex_required);
        self::assertSame('500', (string) $settings->full_batch_size);
    }

    public function testEverySystemXmlSettingIsActuallyReadByTheModule(): void
    {
        // A field an operator can set but no code reads is worse than no field:
        // Max Attempts and Retry Delay used to look like they governed queue
        // retries, which have always come from the queue backend instead.
        $xml = simplexml_load_file(__DIR__ . '/../../app/code/community/Hirale/AsyncIndex/etc/system.xml');
        self::assertNotFalse($xml);

        $sources = '';
        $base = __DIR__ . '/../../app/code/community/Hirale/AsyncIndex';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $sources .= (string) file_get_contents($file->getPathname());
            }
        }

        foreach ($xml->sections->hirale_asyncindex->groups->settings->fields->children() as $field => $_) {
            self::assertStringContainsString(
                "'" . $field . "'",
                $sources,
                sprintf('Setting "%s" is configurable but nothing reads it.', $field),
            );
        }
    }

    public function testEnabledBackendWarnsWhenQueueIsDisabled(): void
    {
        $backend = file_get_contents(
            __DIR__ . '/../../app/code/community/Hirale/AsyncIndex/Model/System/Config/Backend/Enabled.php',
        );

        self::assertIsString($backend);
        self::assertStringContainsString('isQueueEnabled()', $backend);
        self::assertStringContainsString('addWarning', $backend);
        self::assertStringContainsString('no message queue backend is available', $backend);
    }
}
