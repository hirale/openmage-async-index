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

        /** @var array<string, object> */
        public static array $singletons = [];

        /** @var array<string, mixed> */
        public static array $registry = [];

        /** @var array<string, mixed> */
        public static array $config = [];

        public static function reset(): void
        {
            self::$helper = null;
            self::$model = null;
            self::$singletons = [];
            self::$registry = [];
            self::$config = [];
        }

        public static function helper(string $alias): object
        {
            if ($alias === 'hirale_queue' && self::$helper !== null) {
                return self::$helper;
            }
            if ($alias === 'hirale_asyncindex') {
                return new \Hirale_AsyncIndex_Helper_Data();
            }

            throw new RuntimeException(sprintf('Helper %s is unavailable.', $alias));
        }

        public static function getModel(string $alias): object
        {
            if (str_starts_with($alias, 'hirale_queue/') && self::$model !== null) {
                return self::$model;
            }

            throw new RuntimeException(sprintf('Model %s is unavailable.', $alias));
        }

        public static function getSingleton(string $alias): object
        {
            if (isset(self::$singletons[$alias])) {
                return self::$singletons[$alias];
            }

            throw new RuntimeException(sprintf('Singleton %s is unavailable.', $alias));
        }

        public static function getStoreConfigFlag(string $path): bool
        {
            return !empty(self::$config[$path]);
        }

        public static function getStoreConfig(string $path): mixed
        {
            return self::$config[$path] ?? null;
        }

        public static function register(string $key, mixed $value, bool $graceful = false): void
        {
            self::$registry[$key] = $value;
        }

        public static function unregister(string $key): void
        {
            unset(self::$registry[$key]);
        }

        public static function registry(string $key): mixed
        {
            return self::$registry[$key] ?? null;
        }

        public static function logException(Throwable $e): void
        {
        }
    }
}

require_once __DIR__ . '/Support/QueueBusStub.php';
require_once __DIR__ . '/Support/Stubs.php';
require_once __DIR__ . '/../app/code/community/Hirale/AsyncIndex/Helper/Data.php';
require_once __DIR__ . '/../app/code/community/Hirale/AsyncIndex/Message/DrainEventsMessage.php';
require_once __DIR__ . '/../app/code/community/Hirale/AsyncIndex/Message/FullReindexBatchMessage.php';
require_once __DIR__ . '/../app/code/community/Hirale/AsyncIndex/Model/DrainEventsHandler.php';
require_once __DIR__ . '/../app/code/community/Hirale/AsyncIndex/Model/FullReindexBatchHandler.php';
require_once __DIR__ . '/../app/code/community/Hirale/AsyncIndex/Model/FullReindex.php';
