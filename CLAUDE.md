<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>

## Decisions the code states but cannot explain

### A review is about an order, and order names everyone in it

`ProductReviewService` and `DriverRatingService` take the buyer, the merchant, the courier and the products
from order's `GetOrderDetails`, never from the request. Until 2026-10-13 the request named the merchant and
the courier: one delivered order let a buyer rate every courier in the fleet and file a review under any
merchant. Order sends `merchant_principal_id` and `driver_principal_id` from kinetix-contracts v1.1.0. The
courier is the one order recorded as having delivered, so a rating for an order with no courier on record
is refused.

### Nobody reviews themselves

A buyer whose principal is the order's `merchant_principal_id` cannot review the product, and one whose
principal is the order's `driver_principal_id` cannot rate the courier: both are 422 (user ruling O-12,
2026-10-21). Order is to refuse such a cart line too; review checks on its own, because orders placed before
that exist. An order with no merchant on record cannot be told apart, and its review is filed with none.

### Delivered is read from the enum value, not its name

`GrpcOrderClient` compares the status with `OrderStatus::ORDER_STATUS_DELIVERED` and `ORDER_STATUS_COMPLETED`.
It used to compare `OrderStatus::name()` with `"DELIVERED"`, but the name is `"ORDER_STATUS_DELIVERED"`, so
every review and rating in production was refused with 422 while the fake order client, which returned
`"DELIVERED"`, kept the suite green. `tests/Unit/GrpcOrderClientTest.php` maps real protobuf messages for
that reason; a fake that skips the mapping cannot catch it.

### Who wrote a review is not public

`customer_principal_id` and `order_id` are `$hidden` on both models, because the listing routes take no
token. A second review of the same product from the same order, or a second rating of the same order's
courier, is a 409 from the unique index, not a 500.

### Every error on `api/*` is rendered in `bootstrap/app.php`

The render callback takes every `Throwable`, and Laravel runs render callbacks before its own branches for
`HttpResponseException`, `AuthenticationException` and `ValidationException` (`Handler::render`), so the
callback handles all three: a thrown response is sent as it is (the callback returns null and Laravel sends it;
a route's own is caught earlier, in `Route::run`, but a middleware's, such as a throttle's, is not), an
`AuthenticationException` is 401 `UNAUTHORIZED`, and validation is 422 `VALIDATION_FAILED` with `errors` per
field. Without those branches each was a 500 logged as unhandled. The framework exceptions Laravel maps first
(`ModelNotFoundException`, `AuthorizationException`, `TokenMismatchException`, a Symfony request exception)
arrive as `HttpExceptionInterface` and keep their status; anything else is a 500 and is logged.

### A JWKS outage is 503, never 401

`TokenVerifier` hands `CachedKeySet` an `IdentityKeysClient`, which throws `IdentityKeysUnavailableException`
(503) when identity cannot be reached, answers other than 200, or answers a document with no keys. Past the
library a non-200 is an `UnexpectedValueException`, the type it also throws for a malformed token, so the
verifier cannot tell them apart after the fact. Its rate limit (ten fetches a minute) then answers "Key ID not
found" without asking anyone, so a failed fetch is remembered in the cache until one succeeds, and "Key ID not
found" is 503 while it is. Keys already cached keep verifying through an outage. The cache goes through
Symfony's PSR-6 adapter, which swallows store errors: a database outage must not turn into a 401 either.

### ORDER_SERVICE_GRPC_URL has no default

`config/services.php` reads it with no fallback, and the binding of `OrderClientInterface` refuses to build
without it. Octane warms that binding, so the HTTP process fails as its workers boot rather than on the first
review. review-grpc and the migrator never build the order client and do not read it.

### generated/ follows the pinned contract

`generated/` is protoc's PHP output for the contract `bin/sync-contracts` pins, committed so the suite runs
without protoc; the image regenerates it from the same pin. The build installs `protobuf-dev` as well as
`protobuf`: order.proto imports `google/protobuf/timestamp.proto`, which the Alpine `protobuf` package does
not carry. Regenerate with the same Alpine protoc whenever the pin moves.

### Every review is in the evidence log

`review_evidence` holds one row for every product review and driver rating — the review id, what it rates,
the order, the author, the merchant, the rating, the comment's SHA-256 (of its UTF-8 bytes, or none) and
`created_at` — and Postgres writes it: a trigger on each review table inserts the row in the statement that
inserts the review, so no code path, raw query included, can write a review the log does not hold, and the row
says exactly what the review row says. The other way round holds too: an `INSERT` into the log that does not come
from one of those triggers (`pg_trigger_depth()` below 2) is refused, so no code can write evidence of a review that
does not exist. Triggers on the log refuse `UPDATE`, `DELETE` and `TRUNCATE`. These guard every code path, not the
database owner, who can drop a trigger; what guards against the owner is the anchor. The feed is read through
`ReviewEvidenceRepositoryInterface`, like every other table here. The
migration that created it wrote every review that already existed, in the order they were written: their
content is still there, and an anchor proves only what it proves anyway, that a review has not changed since its
batch was anchored. Review only ever creates reviews; the log is what shows one was not changed or removed in the
database afterwards (notary PLAN decision 39).

The notary reads it through `review.v1.ReviewEvidenceService/ReviewsSince`, on a cursor of `(transaction_id
xid8, sequence)` that serves only rows below `pg_snapshot_xmin(pg_current_snapshot())`: a review whose
transaction commits after a later one's is served late, never skipped. A transaction left open anywhere in the
cluster holds the feed back; it never makes it skip.

Each repository writes a review inside its own transaction, a savepoint under the suite's: on Postgres a
refused insert aborts the transaction around it, and the duplicate review answered 409 left every later query
in the test failing.

### review-grpc is our own RoadRunner

The feed is served by a second process from the same image, `rr -c /app/.rr.yaml`, on `GRPC_PORT` (50060).
Go in this repository is an exception to the estate's one service, one language rule, granted by the user on
2026-10-17 (root `AGENTS.md` §1.4a): it is RoadRunner's build and its two plugins, and review's own logic stays PHP.
PHP cannot serve gRPC, and RoadRunner's gRPC plugin hands its PHP workers no client certificate, so the mesh
check cannot live in PHP. `rr/` builds RoadRunner v2025.1.15 from its plugins (server, gRPC, logger,
status) with two of ours, compiled in: `kinetix_metrics` serves `/metrics` on `METRICS_PORT` with the estate's
families — every call in `kinetix_grpc_server_calls_total`, each contract method published at zero, each
scrape it answers in `kinetix_http_requests_total`, and `kinetix_build_info` under review's own service name,
as warehouse's gRPC process does; RoadRunner's own metrics plugin is not built in, because its endpoint could
not count its own requests. `kinetix_peers` reads the SPIFFE id from the verified client chain and admits only what
`KINETIX_GRPC_ALLOWED_PEERS` grants (`peer`, `peer@package.Service`, `peer@package.Service/Method`; a listed peer
may also ask `grpc.health.v1.Health`). It refuses to start without a list, and refuses every call whose chain
was not verified — so a TLS block that stopped requiring client certificates fails closed, which was run on
2026-10-16: the granted notary itself was answered `Unauthenticated`. The plugin serves no reflection; a probe
needs the contract's proto. The status plugin answers `127.0.0.1:2114/health?plugin=grpc` for the
container's healthcheck. The worker is `grpc-worker.php`, which boots Laravel's console kernel and serves
`App\Grpc\ReviewEvidenceService`.

`spiral/roadrunner-grpc` is held at 3.6: 3.8.0 requires `spiral/roadrunner ^3.0`, whose only release carries
CVE-2025-22871, and 3.7.0 was six days old. `spiral/roadrunner` is pinned to 2025.1.15, the binary's version.
`spiral/goridge` declares `ext-sockets` for its socket relay; the worker talks to RoadRunner over pipes, which
never touches it, and the runtime image does not carry it, so `composer install` ignores that platform
requirement everywhere — keep `relay: pipes`, or add the extension to `docker/php-runtime.Dockerfile` first.

### ext-grpc is built once per process, under a lock

FrankenPHP runs PHP thread-safe (ZTS) in one process, a thread per worker — thirteen on production's six CPUs —
and every worker warms `GrpcOrderClient` as it boots. ext-grpc 1.84 keeps process-wide state that nothing
guards: `ChannelCredentials::setDefaultRootsPem`, which `BaseStub` calls whenever no default roots are set,
`gpr_realloc`s one global buffer, and the persistent-channel tables are looked up without their mutex. Workers
building their first client together corrupted the heap: review v1.1.0's deploy on 2026-10-17 segfaulted (exit
139, dockerd's log; nothing in the container's) in two of its three boots and was rolled back. v1.0.0 has the
same client, so the race was latent; v1.1.0 only moved the timing.

`GrpcOrderClient` therefore builds its stub inside `ProcessWideLock` on `storage/framework/grpc-setup.lock`.
`flock` serialises threads as well as processes, because each `fopen` is an open file description of its own.
It also asks for `force_new`, so its channel never enters the shared tables: each worker owns one channel, which
ends with the worker, every 500 requests. In the lab at 48 workers the old image segfaulted twice in 20 boots
and this one never in 40. Build any other ext-grpc client in the HTTP process the same way. review-grpc is not
affected: RoadRunner's workers are processes, not threads.

### The suite runs on Postgres

The log is a Postgres schema, so the whole suite runs on Postgres in both CIs; the migration refuses any other
engine. `tests/Evidence` commits for real — a sandboxed transaction never commits, so it cannot show what one
transaction sees of another — and runs `migrate:fresh` before and after each test, because the log can never be
emptied.

