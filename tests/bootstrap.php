<?php

declare(strict_types=1);

if (!class_exists('Mage_Core_Helper_Abstract')) {
    class Mage_Core_Helper_Abstract
    {
        public function isModuleEnabled(string $moduleName): bool
        {
            return !empty(Mage::$enabledModules[$moduleName]);
        }
    }
}

if (!class_exists('Mage_Core_Helper_Data')) {
    class Mage_Core_Helper_Data extends Mage_Core_Helper_Abstract {}
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

        /** @var array<string, bool> */
        public static array $enabledModules = [];

        /** @var array<int, Mage_Index_Model_Process> */
        public static array $processes = [];

        /** @var list<array{message:string,level:int,file:?string}> */
        public static array $logs = [];

        public const LOG_INFO = 6;
        public const LOG_NOTICE = 5;
        public const LOG_WARNING = 4;
        public const LOG_ERR = 3;

        public static function reset(): void
        {
            self::$helper = null;
            self::$model = null;
            self::$singletons = [];
            self::$registry = [];
            self::$config = [];
            self::$enabledModules = [];
            self::$processes = [];
            self::$logs = [];
        }

        public static function helper(string $alias): object
        {
            if ($alias === 'hirale_queue' && self::$helper !== null) {
                return self::$helper;
            }
            if ($alias === 'hirale_asyncindex') {
                return new \Hirale_AsyncIndex_Helper_Data();
            }
            if ($alias === 'core') {
                return new \Mage_Core_Helper_Data();
            }

            throw new RuntimeException(sprintf('Helper %s is unavailable.', $alias));
        }

        public static function getModel(string $alias): object
        {
            if (str_starts_with($alias, 'hirale_queue/') && self::$model !== null) {
                return self::$model;
            }
            if ($alias === 'index/process') {
                return new \Mage_Index_Model_Process();
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

        public static function log(string $message, ?int $level = null, ?string $file = null): void
        {
            self::$logs[] = ['message' => $message, 'level' => (int) $level, 'file' => $file];
        }
    }
}

if (!class_exists('Mage_Index_Model_Process')) {
    // Stub of the core process model, enough for the mode manager and the
    // full-reindex batch machinery. load() resolves against Mage::$processes so
    // Mage::getModel('index/process')->load($id) behaves like the real lookup.
    class Mage_Index_Model_Process
    {
        public const MODE_REAL_TIME = 'real_time';
        public const MODE_MANUAL = 'manual';
        public const EVENT_STATUS_NEW = 'new';
        public const EVENT_STATUS_DONE = 'done';
        public const EVENT_STATUS_ERROR = 'error';
        public const STATUS_PENDING = 'pending';
        public const STATUS_REQUIRE_REINDEX = 'require_reindex';

        /** @var list<string> */
        public array $savedModes = [];
        /** @var list<string> */
        public array $statusChanges = [];
        public int $reindexEverythingCalls = 0;
        public Mage_Index_Model_Resource_Process $resource;

        public function __construct(
            private int $id = 0,
            private string $indexerCode = '',
            private string $mode = self::MODE_REAL_TIME,
            private string $status = self::STATUS_PENDING,
        ) {
            $this->resource = new Mage_Index_Model_Resource_Process();
        }

        public function load(int $id): self
        {
            return Mage::$processes[$id] ?? new self();
        }

        public function getId(): int
        {
            return $this->id;
        }

        public function getIndexerCode(): string
        {
            return $this->indexerCode;
        }

        public function getMode(): string
        {
            return $this->mode;
        }

        public function setMode(string $mode): self
        {
            $this->mode = $mode;
            return $this;
        }

        public function save(): self
        {
            $this->savedModes[] = $this->mode;
            return $this;
        }

        public function getStatus(): string
        {
            return $this->status;
        }

        public function changeStatus(string $status): self
        {
            $this->statusChanges[] = $status;
            $this->status = $status;
            return $this;
        }

        public function reindexEverything(): self
        {
            $this->reindexEverythingCalls++;
            return $this;
        }

        public function getResource(): Mage_Index_Model_Resource_Process
        {
            return $this->resource;
        }
    }
}

if (!class_exists('Mage_Index_Model_Resource_Process')) {
    class Mage_Index_Model_Resource_Process
    {
        /** @var list<string> */
        public array $calls = [];

        public function startProcess(Mage_Index_Model_Process $process): void
        {
            $this->calls[] = 'start';
        }

        public function endProcess(Mage_Index_Model_Process $process): void
        {
            $this->calls[] = 'end';
        }

        public function failProcess(Mage_Index_Model_Process $process): void
        {
            $this->calls[] = 'fail';
        }
    }
}

if (!class_exists('Mage_Index_Model_Indexer')) {
    class Mage_Index_Model_Indexer
    {
        /** @param list<Mage_Index_Model_Process> $processes */
        public function __construct(private array $processes = []) {}

        /** @return list<Mage_Index_Model_Process> */
        public function getProcessesCollection(): array
        {
            return $this->processes;
        }
    }
}

if (!class_exists('Mage_Core_Model_Lock')) {
    // Spy stub for Maho's core/lock model (acquire/release/isHeld). OpenMage's
    // Mage_Index_Model_Lock is intentionally NOT defined here, so the helper's
    // class_exists() guard falls through to this Maho path under test.
    class Mage_Core_Model_Lock
    {
        /** @var list<string> */
        public array $acquired = [];
        /** @var list<string> */
        public array $released = [];
        public bool $acquireResult = true;

        public function acquire(string $name, bool $blocking = false): bool
        {
            $this->acquired[] = $name;
            return $this->acquireResult;
        }

        public function release(string $name): bool
        {
            $this->released[] = $name;
            return true;
        }

        public function isHeld(string $name): bool
        {
            return in_array($name, $this->acquired, true) && !in_array($name, $this->released, true);
        }
    }
}

require_once __DIR__ . '/Support/QueueBusStub.php';
require_once __DIR__ . '/Support/MahoQueueStub.php';
require_once __DIR__ . '/Support/Stubs.php';
require_once __DIR__ . '/../app/code/community/Hirale/AsyncIndex/Helper/Data.php';
require_once __DIR__ . '/../app/code/community/Hirale/AsyncIndex/Message/DrainEventsMessage.php';
require_once __DIR__ . '/../app/code/community/Hirale/AsyncIndex/Message/FullReindexBatchMessage.php';
require_once __DIR__ . '/../app/code/community/Hirale/AsyncIndex/Model/DrainEventsHandler.php';
require_once __DIR__ . '/../app/code/community/Hirale/AsyncIndex/Model/FullReindexBatchHandler.php';
require_once __DIR__ . '/../app/code/community/Hirale/AsyncIndex/Model/FullReindex.php';
require_once __DIR__ . '/../app/code/community/Hirale/AsyncIndex/Model/ModeManager.php';
require_once __DIR__ . '/../app/code/community/Hirale/AsyncIndex/Model/Runner.php';
require_once __DIR__ . '/../app/code/community/Hirale/AsyncIndex/Model/Reconciler.php';
