<?php

declare(strict_types=1);

namespace Maho\Queue\Stamp {
    if (!class_exists(DedupeKeyStamp::class)) {
        class DedupeKeyStamp
        {
            public function __construct(public readonly string $key, public readonly bool $enforce = true)
            {
            }
        }
    }
}

namespace Maho\Queue {
    if (!class_exists(QueueManager::class)) {
        /**
         * Recording stub for Maho's core queue entry point. The real class ships
         * with the platform, which the unit suite does not boot; tests assert
         * against the recorded dispatches.
         */
        class QueueManager
        {
            /** @var list<array{message:object,delaySeconds:?int,queue:string,dedupeKey:?string,stamps:list<object>}> */
            public static array $dispatches = [];

            public static ?\Throwable $nextException = null;

            public static function reset(): void
            {
                self::$dispatches = [];
                self::$nextException = null;
            }

            /** @param list<object> $stamps */
            public static function dispatch(
                object $message,
                ?int $delaySeconds = null,
                string $queue = 'default',
                ?string $dedupeKey = null,
                array $stamps = [],
            ): object {
                if (self::$nextException !== null) {
                    $e = self::$nextException;
                    self::$nextException = null;
                    throw $e;
                }

                self::$dispatches[] = [
                    'message' => $message,
                    'delaySeconds' => $delaySeconds,
                    'queue' => $queue,
                    'dedupeKey' => $dedupeKey,
                    'stamps' => $stamps,
                ];

                return $message;
            }
        }
    }
}
