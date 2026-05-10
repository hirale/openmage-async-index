<?php

declare(strict_types=1);

if (!class_exists('Mage_Core_Helper_Abstract')) {
    class Mage_Core_Helper_Abstract {}
}

if (!class_exists('Mage')) {
    class Mage
    {
        public static ?object $helper = null;
        public static ?object $model = null;

        /** @var array<string, mixed> */
        public static array $config = [];

        public static function reset(): void
        {
            self::$helper = null;
            self::$model = null;
            self::$config = [];
        }

        public static function helper(string $alias): object
        {
            if ($alias === 'hirale_queue' && self::$helper !== null) {
                return self::$helper;
            }

            throw new RuntimeException(sprintf('Helper %s is unavailable.', $alias));
        }

        public static function getModel(string $alias): object
        {
            if ($alias === 'hirale_queue/task' && self::$model !== null) {
                return self::$model;
            }

            throw new RuntimeException(sprintf('Model %s is unavailable.', $alias));
        }

        public static function getStoreConfigFlag(string $path): bool
        {
            return !empty(self::$config[$path]);
        }

        public static function getStoreConfig(string $path): mixed
        {
            return self::$config[$path] ?? null;
        }

        public static function logException(Throwable $e): void
        {
        }
    }
}

if (!interface_exists('Hirale_Queue_Model_TaskHandlerInterface')) {
    interface Hirale_Queue_Model_TaskHandlerInterface
    {
        public function handle($data);
    }
}

require_once __DIR__ . '/../src/app/code/community/Hirale/AsyncIndex/Helper/Data.php';
require_once __DIR__ . '/../src/app/code/community/Hirale/AsyncIndex/Model/QueueHandler.php';
