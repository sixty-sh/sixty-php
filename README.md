# sixty (PHP)

Zero-configuration performance drift detection for PHP — Laravel, Symfony, and
anything on PDO or MongoDB.

```bash
composer require sixty-sh/sixty
```

```bash
SIXTY_API_KEY=sixty_sk_…
SIXTY_SERVICE=checkout-api
SIXTY_RELEASE=$(git rev-parse --short HEAD)
```

That is the install. **Laravel** discovers the service provider; **Symfony**
needs the bundle added to `config/bundles.php`. Both then wire up the request
span, the query instrumentation and the flush — there is no initializer to
write and nothing to call.

Anything else:

```php
\Sixty\Sixty::init();
\Sixty\Instrument\Pdo::instrument($yourPdoConnection);
```

## What it reports

Not latency alone. **Shape** — the numbers that barely move on a warm
development database and take production down a week later:

| signal | the question it answers |
|---|---|
| rows per call | did this query start returning 30,000 rows instead of 30? |
| queries per call | did this method start issuing 20 queries instead of 1? |
| round trips per call | did this read start fetching in three hundred batches? |
| self time | is *my* code slower, or is something I call slower? |
| query plan | did this statement stop using its index? |

Each is recorded per operation, per release. The collector compares one release
against the release before it — which is why `SIXTY_RELEASE` matters more than
any other setting here. Without it there is no "before".

## The PHP problem, and what this does about it

Every other agent in this project keeps a rollup in memory and flushes it from a
background thread. A PHP worker has neither: it cannot run a timer, and
everything it learned dies with the request. Done naively that means one HTTP
POST to the collector per request — at 800 requests a second, 800 POSTs a second
to report data whose whole point is that it was aggregated.

So two things happen instead:

- **The response goes out first.** The flush runs from
  `register_shutdown_function`, and the first thing it does is
  `fastcgi_finish_request()`. Everything after that — the merge, the POST, a
  collector that is down and takes the full timeout — happens after the user
  already has their page.
- **Windows are pooled in shared memory.** With `ext-apcu` installed, each
  request writes its own window under its own key and one request per interval
  merges them all and sends a single payload. The merge is lossless: sketches
  union exactly, so the percentiles are what a single process measuring
  everything would have reported.

Without APCu it still works — each request sends its own window — and the agent
says so once at startup rather than pretending otherwise.

`Sixty::init()` refuses to enable under Swoole coroutines and says why: many
requests share one worker there and switch at every I/O boundary, so the current
span would attribute one request's queries to another request's controller.
Wrong attribution is worse than no data. FPM, CLI, RoadRunner and FrankenPHP are
process-per-request and fully supported.

## Which database you use

| client | how it is picked up | rows means | plans |
|---|---|---|---|
| Laravel (any driver) | automatic, at `ConnectionEstablished` | `rowCount()` | Postgres only |
| Doctrine DBAL | automatic middleware, registered by the bundle | the result's own count | Postgres only |
| plain PDO | `Pdo::instrument($pdo)`, or `new TracedPdo(...)` | `rowCount()` | Postgres only |
| MongoDB (incl. Doctrine ODM) | automatic, via the driver's command monitoring | documents returned or affected | no |

**PDO is instrumented without replacing your connection.** A decorator would
have to be a `PDO` subclass, and a subclass has to open its own connection —
an agent that silently doubles every application's connection count.
`PDO::ATTR_STATEMENT_CLASS` can be set on a connection that already exists, so
your framework keeps its own PDO and every statement it prepares passes through
on the way. If something else already owns the statement class — a profiler, a
debug bar — the agent leaves it alone and measures nothing rather than breaking
it.

That hook covers `prepare()` + `execute()`, which is every query Laravel and
Doctrine issue. `PDO::query()` and `PDO::exec()` never reach a statement class;
use `Sixty\Instrument\TracedPdo` for a connection you construct yourself if you
call those directly.

**MySQL is read by different rules, not the same ones.** `"alice@example.com"`
is a quoted *identifier* in Postgres and a string *literal* in MySQL's default
sql_mode — reading MySQL with the Postgres rules would transmit it. The dialect
comes from the driver, never from the text.

**MongoDB has no statement, so identity is built from keys only**, and a value
has no path into it: `find orders {filter{user_id},limit}`. A cursor is one
operation however many batches it took — the batches are counted as round trips,
because "your method makes three hundred database calls" reads as an N+1 your
code does not contain and sends you looking for a loop when the fix is a batch
size.

**Why MySQL and MongoDB get no query plans.** Postgres has
`EXPLAIN (GENERIC_PLAN)`, which plans a statement without binding a parameter —
there is no step at which a value could enter it. MySQL and MongoDB can only
explain a query that still has its values in it, so capturing a plan there would
mean retaining somebody's data to compose a command out of it. The same refusal
in all four agents.

## Your own code

PHP has no build step to rewrite functions and no hook that fires when a method
is defined, so measuring your own layer is a line where it is worth having:

```php
public function forUser(int $userId): array
{
    return \Sixty\Sixty::trace('OrdersQuery#forUser', fn () => Order::where('user_id', $userId)->get()->all());
}
```

The name is an identity compared across releases, so it must not contain
anything that varies per call — an id in a name mints an operation per id.
`Sixty::annotate('rows', count($result))` records a value on the current
operation.

## What it costs

Measured as CPU time — `getrusage`, not a stopwatch, because the number that
matters is what the agent takes from the application rather than what the
machine happened to be doing:

| | cost |
|---|---|
| a traced method | **2.7µs** |
| a query, recorded and rolled up | **2.9µs** |
| **measuring a 4-span request** | **13µs** — the only part a user waits for |
| draining that window to a payload | 22µs — after `fastcgi_finish_request()` |
| its share of the interval merge | ~35µs — also after the response |

So a request with a route and three queries pays about **13µs of user-visible
time** and another ~57µs of worker CPU once the response is already gone. On a
Laravel request that costs 5–50ms, that is a fraction of a percent.

PHP pays more per request than the threaded agents do, and it is structural
rather than sloppy: a share-nothing worker has to serialize and hand over its
window every single request, where a threaded agent keeps accumulating in
memory and serializes once a minute.

### What the benchmark found

Three things, none of which a unit test would have shown:

- **`Buffer::add` was quadratic.** It scanned every pending APCu key on each
  request to enforce the cap, so the cost grew with how many requests arrived
  between flushes — 168µs per request at two thousand pending. It is an atomic
  `apcu_inc` now, which is ~12µs and cannot lose a concurrent increment.
- **`Buffer::merge` was superlinear**, decoding and re-encoding the accumulated
  sketch once per window: 112µs per window at two hundred. Decoding once,
  accumulating into sketch objects and encoding at the end made it linear, and
  keeping the first window's blob as a *string* until a second one arrives skips
  the round trip entirely for operations that appear in only one window.
- **The sketch codec was the hottest code in the agent**, at 2.6µs to encode.
  A function call per varint and six `pack()` calls per header, ten times a
  request. Inlined and packed in one go it is 1.2µs, and `tests/WireTest.php`
  proves the bytes did not change — it compares them to fixtures written by the
  JavaScript implementation the collector decodes with.

Together those took a request from ~155µs of agent CPU to ~70µs, and removed a
cost that grew with traffic.

## What happens when the collector is down

Nothing, to your application:

- the request path never opens a socket to the collector
- the flush runs after `fastcgi_finish_request()`, so a slow or dead collector
  costs the worker time and the user none
- failures are swallowed and counted; undeliverable windows are dropped, so the
  agent's memory is bounded by your application's shape rather than by somebody
  else's uptime
- an exception anywhere inside the agent is caught before it reaches your code —
  the middleware is written so that the only unguarded line in it is
  `$next($request)`

## Compatibility

PHP 8.1+. Laravel 10/11/12, Symfony 6.4/7, Doctrine DBAL 3 and 4. No runtime
dependencies beyond `ext-json` and `ext-curl`: this package is loaded into other
people's production processes, and every dependency it took would be a version
conflict it could cause in an application that has nothing to do with
observability.

## Tests

```bash
composer install
vendor/bin/phpunit
```

The sketch and SQL suites assert against fixtures generated by the JavaScript
agent (`node tests/fixtures/generate.mjs`) rather than against themselves — a
codec bug that is consistent between a PHP writer and a PHP reader passes every
roundtrip test there is, and the collector decodes these bytes with the
JavaScript implementation.

Integration tests skip when a database is not running. `docker compose` in the
repository root starts Postgres; MySQL and MongoDB need one container each:

```bash
docker run -d -p 3308:3306 -e MYSQL_ROOT_PASSWORD=drift -e MYSQL_DATABASE=demo_rails \
  -e MYSQL_USER=drift -e MYSQL_PASSWORD=drift mysql:8
docker run -d -p 27018:27017 mongo:7
```

A running example lives in [`apps/demo-laravel`](../../apps/demo-laravel).
