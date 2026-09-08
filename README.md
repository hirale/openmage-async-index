# Hirale Async Index

Asynchronous core index event and full reindex orchestration for OpenMage and
Maho.

The module keeps `index_event` and `index_process_event` as the durable source
of truth, then dispatches a message to wake a background worker. Handlers are
at least once, so the DB-backed pending event state is always used to decide
what work remains.

## Queue backend

There is no shared queue package any more. The module picks a backend at
runtime, in this order:

| Platform | Backend | Package to install |
| --- | --- | --- |
| Maho with the core `Maho_Queue` module | `\Maho\Queue\QueueManager` | none — it ships with the platform |
| OpenMage | `\Hirale\Queue\Bus` | [`hirale/queue`](https://github.com/hirale/queue) `^3.0` |
| Neither | — | async indexing stays off, core indexing is untouched |

`Maho_Queue` wins whenever it is present and enabled, even on a store that also
has `hirale/queue` installed.

> **2.0.0 is a breaking change.** `hirale/queue` moved from `require` to
> `suggest`. OpenMage installs that upgrade from 1.x must require it explicitly,
> or async indexing silently turns itself off.

## Install

**Maho** (with core `Maho_Queue`):

```bash
composer require hirale/openmage-async-index
composer dump-autoload
```

`composer dump-autoload` is required: it compiles the
`#[\Maho\Config\MessageHandler]` attributes into
`vendor/composer/maho_attributes.php`. Without it the messages have no
registered handler, and the queue refuses to decode them.

**OpenMage** (20.17+, PHP 8.3+) — one-time tweaks first; details in the
[hirale/queue README](https://github.com/hirale/queue#openmage-one-time-composer-adjustments):

```bash
composer config platform.php 8.3
composer config allow-plugins.hirale/magento-module-installer true
composer require hirale/magento-module-installer hirale/queue hirale/openmage-async-index
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

## Queues and worker pools

On Maho, drain messages ride the `default` queue and full reindex batches get
their own `full_reindex` queue, routed in `config.xml` so neither lands in the
resident `fast` pool. A custom name in
`Hirale > Async Index > Full reindex queue` is used verbatim; anything the core
routing does not claim falls to the catch-all pool.

On OpenMage the same setting maps onto a `hirale/queue` transport, which has to
exist in that module's own configuration. Leaving it empty uses the routing in
`hirale/queue`'s `config.xml`.

Drain dispatches carry a dedupe key on Maho, so a drain that is already pending
or processing suppresses further ones. A continuation dispatched by the handler
itself keeps the key but skips the check, otherwise its own in-flight row would
swallow the rest of the chain.

## Runtime

Enable `Hirale > Async Index` only after the queue backend is configured and
working. When async index is disabled, core indexing behaves as it did before.

When enabled, the module automatically switches manual index processes to
`real_time` so the platform records index events. The original modes are stored
and are restored when async index is disabled.

The queue job only wakes the runner. The runner scans pending index events and
`require_reindex` processes from the database, processes them in batches, and
publishes continuation jobs while work remains.

Full reindex runs are also queued. Product-backed indexers are split into
product ID batches. Indexers that cannot be safely split by product still run
through the same async full reindex coordinator as a global batch unit, so admin
and CLI calls do not perform the heavy full reindex inside the request process
while async index is enabled.
