<?php

declare(strict_types=1);

/** @var Mage_Core_Model_Resource_Setup $this */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();
$runTable = $installer->getTable('hirale_asyncindex/full_run');

if ($connection->isTableExists($runTable) && !$connection->tableColumnExists($runTable, 'cancel_requested')) {
    $connection->addColumn($runTable, 'cancel_requested', [
        'type' => Varien_Db_Ddl_Table::TYPE_SMALLINT,
        'nullable' => false,
        'default' => 0,
        'comment' => 'Cancel Requested',
    ]);
}

$installer->endSetup();
