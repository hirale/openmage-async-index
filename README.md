# Hirale Async Index

Asynchronous core index event and full reindex orchestration for OpenMage and
Maho.

The module keeps `index_event` and `index_process_event` as the durable source
of truth, then uses `hirale/openmage-redis-queue` to wake a background worker.
Handlers are at least once, so the DB-backed pending event state is always used
to decide what work remains.

## Install

```bash
composer require hirale/openmage-async-index
```

For local development, add a path repository next to the application:

```json
{
    "repositories": [
        {
            "name": "openmage-async-index",
            "type": "path",
            "url": "../openmage-async-index",
            "options": {
                "symlink": true
            }
        }
    ]
}
```

## Runtime

Enable `Hirale > Async Index` only after `Hirale > Queue` is configured and
working. When async index is disabled, core indexing behaves as it did before.

When enabled, the module automatically switches manual index processes to
`real_time` so Maho records index events. The original modes are stored and are
restored when async index is disabled.

The queue job only wakes the runner. The runner scans pending index events and
`require_reindex` processes from the database, processes them in batches, and
publishes continuation jobs while work remains.

Full reindex runs are also queued. Product-backed indexers are split into
product ID batches. Indexers that cannot be safely split by product still run
through the same async full reindex coordinator as a global batch unit, so admin
and CLI calls do not perform the heavy full reindex inside the request process
while async index is enabled.
