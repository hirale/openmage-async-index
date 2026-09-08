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

        /** @var list<array{message:string,level:int,file:?string,force:bool}> */
        public static array $logs = [];

        /** @var list<array{name:string,data:array<string, mixed>}> */
        public static array $events = [];

        public static ?Mage_Core_Model_App $app = null;

        public static ?Throwable $eventException = null;

        public static ?object $asyncIndexHelper = null;

        // No LOG_* constants on purpose: OpenMage declares none, and Maho's are
        // Monolog enum cases rather than ints. A stub that carried int ones
        // would let module code compile here and fatal on both real platforms.

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
            self::$events = [];
            self::$app = null;
            self::$eventException = null;
            self::$asyncIndexHelper = null;
        }

        public static function helper(string $alias): object
        {
            if ($alias === 'hirale_queue' && self::$helper !== null) {
                return self::$helper;
            }
            if ($alias === 'hirale_asyncindex') {
                return self::$asyncIndexHelper ?? new \Hirale_AsyncIndex_Helper_Data();
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

        public static function log(
            string $message,
            ?int $level = null,
            ?string $file = null,
            bool $forceLog = false,
        ): void {
            self::$logs[] = [
                'message' => $message,
                'level' => (int) $level,
                'file' => $file,
                'force' => $forceLog,
            ];
        }

        /** @param array<string, mixed> $data */
        public static function dispatchEvent(string $name, array $data = []): void
        {
            if (self::$eventException !== null) {
                $e = self::$eventException;
                self::$eventException = null;
                throw $e;
            }
            // Registry snapshot: lets a test prove an event was dispatched
            // outside the drain / full-reindex context rather than inside it.
            self::$events[] = ['name' => $name, 'data' => $data, 'registry' => self::$registry];
        }

        public static function app(): Mage_Core_Model_App
        {
            return self::$app ??= new \Mage_Core_Model_App();
        }
    }
}

if (!class_exists('Mage_Core_Model_App')) {
    class Mage_Core_Model_App
    {
        /** @var list<array<int, string>> */
        public array $cleanedTags = [];

        /** @param array<int, string> $tags */
        public function cleanCache($tags = []): self
        {
            $this->cleanedTags[] = $tags;
            return $this;
        }
    }
}

if (!class_exists('Mage_Index_Model_Indexer_Abstract')) {
    class Mage_Index_Model_Indexer_Abstract
    {
        /** @var list<list<int>> */
        public array $reindexedEntities = [];

        /** @param list<int> $ids */
        public function reindexEntity(array $ids): void
        {
            $this->reindexedEntities[] = $ids;
        }
    }
}

if (!class_exists('Mage_Index_Model_Event')) {
    class Mage_Index_Model_Event
    {
        public int $saves = 0;

        // Null until a process records an outcome, exactly like core: the event
        // resource skips its whole process-row branch while it is not an array.
        /** @var array<int|string, string>|null */
        private ?array $processIds = null;

        public function __construct(
            private int $id = 0,
            private string $entity = '',
            private ?int $entityPk = null,
        ) {}

        public function getId(): int
        {
            return $this->id;
        }

        public function getEntity(): string
        {
            return $this->entity;
        }

        public function getEntityPk(): ?int
        {
            return $this->entityPk;
        }

        public function addProcessId($processId, string $status = 'new'): self
        {
            $this->processIds[$processId] = $status;
            return $this;
        }

        /** @return array<int|string, string>|null */
        public function getProcessIds(): ?array
        {
            return $this->processIds;
        }

        public function save(): self
        {
            $this->saves++;
            return $this;
        }
    }
}

if (!class_exists('Mage_Index_Model_Resource_Event_Collection')) {
    class Mage_Index_Model_Resource_Event_Collection
    {
        private int $cursor = 0;

        /** @param list<Mage_Index_Model_Event> $events */
        public function __construct(private array $events = []) {}

        public function setPageSize(int $size): self
        {
            return $this;
        }

        public function setCurPage(int $page): self
        {
            return $this;
        }

        public function setOrder(string $field, string $direction): self
        {
            return $this;
        }

        public function fetchItem(): Mage_Index_Model_Event|false
        {
            return $this->events[$this->cursor++] ?? false;
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

        /** @var list<Mage_Index_Model_Event> */
        public array $unprocessedEvents = [];
        /** @var list<Mage_Index_Model_Event> */
        public array $processedEvents = [];
        public bool $locked = false;
        public ?Throwable $processEventException = null;
        public bool $markEventsFailed = false;
        public ?Mage_Index_Model_Indexer_Abstract $indexer = null;

        public function getIndexer(): ?Mage_Index_Model_Indexer_Abstract
        {
            return $this->indexer;
        }

        /** @return list<string> */
        public function getDepends(): array
        {
            return [];
        }

        public function isLocked(): bool
        {
            return $this->locked;
        }

        public function lock(): void
        {
            $this->locked = true;
        }

        public function unlock(): void
        {
            $this->locked = false;
        }

        public function getUnprocessedEventsCollection(): Mage_Index_Model_Resource_Event_Collection
        {
            return new Mage_Index_Model_Resource_Event_Collection($this->unprocessedEvents);
        }

        public function processEvent(Mage_Index_Model_Event $event): self
        {
            if ($this->processEventException !== null) {
                throw $this->processEventException;
            }
            $this->processedEvents[] = $event;

            // Core records the outcome on the event and returns normally, even
            // when the indexer threw; it never reports the failure to the caller.
            $event->addProcessId(
                $this->id,
                $this->markEventsFailed ? self::EVENT_STATUS_ERROR : self::EVENT_STATUS_DONE,
            );

            return $this;
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
        /** @var list<array{name:string,events_dispatched:int}> */
        public array $releaseLog = [];
        public bool $acquireResult = true;

        public function acquire(string $name, bool $blocking = false): bool
        {
            $this->acquired[] = $name;
            return $this->acquireResult;
        }

        public function release(string $name): bool
        {
            $this->released[] = $name;
            // How much had been announced by the time the lock went back: lets a
            // test prove a dispatch happened after the release, not merely that
            // both happened.
            $this->releaseLog[] = ['name' => $name, 'events_dispatched' => count(Mage::$events)];
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
