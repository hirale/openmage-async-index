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

> **2.0.0 is a breaking change.**
>
> - `hirale/queue` moved from `require` to `suggest`. OpenMage installs that
>   upgrade from 1.x must require it explicitly, or async indexing silently
>   turns itself off.
> - Four settings were removed because nothing read them: *Full Reindex Max
>   Runtime Seconds*, *Max Attempts*, *Retry Delay Seconds* and *Coalesce TTL
>   Seconds*. Retry behaviour has always come from the queue backend
>   (`system/queue/*` on Maho, the Hirale Queue section on OpenMage). Values
>   already saved under those paths are ignored; nothing needs to be migrated.

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

On Maho, drain messages ride an `index_drain` queue and full reindex batches an
`indexer`-style `full_reindex` queue. Neither uses core's shared `default`
queue: no module may reroute that one, so a host could never move drain onto a
pool of its own. `config.xml` leaves `index_drain` unrouted, which means the
catch-all (`slow`) pool consumes it — the same place it went before.

The catch-all pool is on-demand: it exits after 60 seconds idle and the
watchdog cron restarts it once a minute, so a drain can wait up to about a
minute on a quiet store. If that matters, route the queue from your own
`config.xml` or `app/etc/local.xml`:

```xml
<global>
    <queue>
        <routing>
            <index_drain>fast</index_drain>
        </routing>
    </queue>
</global>
```

`fast` is resident, but it is core's tier for work a human is waiting on, and a
drain can run for up to `max_runtime_seconds`. Declaring a resident pool of your
own and routing `index_drain` to it keeps checkout mail out of the way:

```xml
<pools>
    <index><sort_order>15</sort_order></index>
</pools>
```

A custom name in `Hirale > Async Index > Full reindex queue` is used verbatim on
both platforms. On OpenMage it maps onto a `hirale/queue` transport, which has
to exist in that module's own configuration; leaving it empty uses the routing
in `hirale/queue`'s `config.xml`.

### Deduplication (Maho only)

`hirale/queue` v3 has no dispatch-time dedup, so on OpenMage every dispatch
inserts a job.

- Drain messages carry a shared key, so a drain that is already pending or
  processing suppresses further ones.
- Full reindex batches carry a key per run, so the once-a-minute reconciler and
  the batch chain cannot pile up dozens of messages for the same run — while
  runs still never suppress each other.
- A message a handler dispatches to continue its own chain keeps the key but
  skips the check, otherwise its own in-flight row would swallow the rest.
- The reconciler's drain carries **no** key at all. It is the recovery path for
  a worker killed mid-drain, whose row stays `processing` for the five minutes
  the queue takes to call the claim abandoned; an enforced key would suppress
  the very dispatch meant to get indexing moving again. Its schedule is the
  rate limit. This makes the reconciler cron the only crash-recovery mechanism
  there is — disabling it disables recovery.

## Runtime

Enable `Hirale > Async Index` only after the queue backend is configured and
working. When async index is disabled, core indexing behaves as it did before.

When enabled, the module switches manual index processes to `real_time` so the
platform records index events. The mode each process had at the moment it was
taken over is stored, and handed back when async index is disabled.

An indexer you switch back to manual while async index is running is taken over
again on the next reconciler tick — `real_time` is what makes events land in the
first place. The takeover is logged to `var/log/asyncindex.log`, so a change
that does not stick is distinguishable from a bug. Turn async index off first if
you want an indexer to stay manual.

Turning *Restore Modes on Disable* off leaves the indexers on `real_time` and
keeps the stored modes, so a later re-enable still knows what to hand back.

The queue job only wakes the runner. The runner scans pending index events and
`require_reindex` processes from the database, processes them in batches, and
publishes continuation jobs while work remains.

Full reindex runs are also queued. Product-backed indexers are split into
product ID batches. Indexers that cannot be safely split by product still run
through the same async full reindex coordinator as a global batch unit, so admin
and CLI calls do not perform the heavy full reindex inside the request process
while async index is enabled.
