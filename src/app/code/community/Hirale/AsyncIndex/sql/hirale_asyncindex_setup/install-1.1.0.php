<?php

declare(strict_types=1);

/** @var Mage_Core_Model_Resource_Setup $this */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();

$stateTableName = $installer->getTable('hirale_asyncindex/process_state');
if (!$connection->isTableExists($stateTableName)) {
    $stateTable = $connection
        ->newTable($stateTableName)
        ->addColumn('state_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'identity' => true,
            'unsigned' => true,
            'nullable' => false,
            'primary' => true,
        ], 'State ID')
        ->addColumn('process_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'nullable' => false,
        ], 'Index Process ID')
        ->addColumn('indexer_code', Varien_Db_Ddl_Table::TYPE_TEXT, 128, ['nullable' => false], 'Indexer Code')
        ->addColumn('original_mode', Varien_Db_Ddl_Table::TYPE_TEXT, 32, ['nullable' => false], 'Original Mode')
        ->addColumn('managed_mode', Varien_Db_Ddl_Table::TYPE_TEXT, 32, ['nullable' => true], 'Managed Mode')
        ->addColumn('is_managed', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, [
            'nullable' => false,
            'default' => 0,
        ], 'Is Managed')
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, ['nullable' => false], 'Created At')
        ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, ['nullable' => false], 'Updated At')
        ->addIndex(
            $installer->getIdxName('hirale_asyncindex/process_state', ['process_id'], Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE),
            ['process_id'],
            ['type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE],
        )
        ->setComment('Hirale Async Index Managed Process Modes');

    $connection->createTable($stateTable);
}

$runTableName = $installer->getTable('hirale_asyncindex/full_run');
if (!$connection->isTableExists($runTableName)) {
    $runTable = $connection
        ->newTable($runTableName)
        ->addColumn('run_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'identity' => true,
            'unsigned' => true,
            'nullable' => false,
            'primary' => true,
        ], 'Run ID')
        ->addColumn('process_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'nullable' => false,
        ], 'Index Process ID')
        ->addColumn('indexer_code', Varien_Db_Ddl_Table::TYPE_TEXT, 128, ['nullable' => false], 'Indexer Code')
        ->addColumn('mode', Varien_Db_Ddl_Table::TYPE_TEXT, 32, ['nullable' => false], 'Run Mode')
        ->addColumn('status', Varien_Db_Ddl_Table::TYPE_TEXT, 32, ['nullable' => false], 'Run Status')
        ->addColumn('cursor_value', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'nullable' => false,
            'default' => 0,
        ], 'Cursor Value')
        ->addColumn('total', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'nullable' => false,
            'default' => 0,
        ], 'Total Units')
        ->addColumn('processed', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'nullable' => false,
            'default' => 0,
        ], 'Processed Units')
        ->addColumn('event_waterline', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'nullable' => false,
            'default' => 0,
        ], 'Event Waterline')
        ->addColumn('reason', Varien_Db_Ddl_Table::TYPE_TEXT, 255, ['nullable' => false], 'Run Reason')
        ->addColumn('last_error', Varien_Db_Ddl_Table::TYPE_TEXT, '64k', ['nullable' => true], 'Last Error')
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, ['nullable' => false], 'Created At')
        ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, ['nullable' => false], 'Updated At')
        ->addColumn('started_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, ['nullable' => true], 'Started At')
        ->addColumn('finished_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, ['nullable' => true], 'Finished At')
        ->addIndex($installer->getIdxName('hirale_asyncindex/full_run', ['status', 'run_id']), ['status', 'run_id'])
        ->addIndex($installer->getIdxName('hirale_asyncindex/full_run', ['process_id', 'status']), ['process_id', 'status'])
        ->setComment('Hirale Async Index Full Reindex Runs');

    $connection->createTable($runTable);
}

$installer->endSetup();
