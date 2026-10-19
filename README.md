# OutboundGuardBundle

OutboundGuard helps Symfony developers detect excessive, duplicated and slow outbound HTTP requests during development and testing.

```yaml
outbound_guard:
    max_calls: 5
    max_total_duration_ms: 1000
    slow_call_threshold_ms: 500
```

It transparently observes Symfony HttpClient calls, exposes reports for tests, and adds a small panel to an existing Symfony Web Profiler installation. Collection and detection are implemented through **Phase 2**; final publication work belongs to Phase 3.

## Requirements

- PHP 8.1+ with Symfony 6.4; PHP 8.2+ with Symfony 7.4; PHP 8.4+ with Symfony 8.1.
- Symfony HttpClient configured through FrameworkBundle's service container.

The package name is `hanane/outbound-guard-bundle`, with namespace `Hanane\OutboundGuardBundle`. It has not been published to Packagist.

## Local development

```console
composer install
composer test
composer analyse
composer validate --strict
```

`composer check` runs all three checks. Tests use `MockHttpClient` and `MockResponse`; they make no external HTTP requests. Composer needs network access to download dependencies.

This is a library, so `composer.lock` and `vendor/` are ignored. A local lock file records your installed development dependencies; consuming applications own their lock files.

## Try it in a Symfony application

Until publication, add this repository as a Composer path repository in a separate Symfony application's `composer.json`:

```json
{
    "repositories": [
        {"type": "path", "url": "../OutboundGuard"}
    ]
}
```

Then run in that application:

```console
composer require --dev hanane/outbound-guard-bundle:@dev
```

Register the bundle for development and testing in `config/bundles.php`:

```php
return [
    // Existing bundles...
    Hanane\OutboundGuardBundle\OutboundGuardBundle::class => ['dev' => true, 'test' => true],
];
```

Enable Symfony's HttpClient if it is not already enabled:

```yaml
framework:
    http_client: ~
```

Configuration, in `config/packages/dev/outbound_guard.yaml` (and the corresponding test directory):

```yaml
outbound_guard:
    enabled: true
    capture_paths: false
    max_calls: 5
    max_total_duration_ms: 1000
    slow_call_threshold_ms: 500
```

These are the defaults. Limits must be non-negative integers. Warnings use strict comparisons: equality passes, and zero allows no calls or no measured time. `enabled: false` prevents registration of monitoring services and decorators at container compilation. Registering the bundle only in `dev`/`test` keeps it out of production.

Existing application code is unchanged:

```php
$response = $httpClient->request('GET', 'https://api.example.com/products/42');
$data = $response->toArray();
```

The default client and FrameworkBundle's named/scoped clients are monitored automatically. A client cloned using `withOptions()` shares the same collector.

## Inspect metrics in tests

Inject `Hanane\OutboundGuardBundle\Collector\OutboundCallCollector` into a test helper or application service. For a Symfony `KernelTestCase`, make it accessible through the test container if necessary:

```yaml
# config/services_test.yaml (import this file from your application's test configuration)
services:
    test.outbound_guard.collector:
        alias: Hanane\OutboundGuardBundle\Collector\OutboundCallCollector
        public: true
```

Then, after your application has consumed its outbound responses:

```php
$collector = static::getContainer()->get('test.outbound_guard.collector');
self::assertSame(3, $collector->getCallCount());
self::assertLessThan(800.0, $collector->getTotalDurationMs());

foreach ($collector->getCalls() as $call) {
    $call->method;                  // GET
    $call->target->displayUrl;      // https://api.example.com/[path hidden]
    $call->target->host;            // api.example.com
    $call->target->path;            // null unless capture_paths is enabled
    $call->target->fingerprint;     // opaque identity, valid within one collection
    $call->getStatusCode();         // int|null
    $call->getDurationMs();         // float|null, milliseconds
    $call->getOutcome()->value;     // pending/success/http_error/transport_error/cancelled
    $call->isSuccessful();          // bool|null
}

$slowest = $collector->getSlowestCall(); // OutboundCall|null

$report = $collector->getReport();     // immutable snapshot at this moment
self::assertFalse($report->isBudgetExceeded());
self::assertSame(0, $report->duplicateCallCount);
self::assertSame([], $report->warnings);
```

The report includes call count, known total/slowest duration, pending/unknown-duration counts, repeated-call and slow-call counts, grouped endpoints, configured budgets, and warnings. Warning entries contain a stable `code` and a safe human-readable `message`. Codes are `max_calls`, `max_total_duration`, `duplicate_call`, and `slow_call`.

Reports are calculated on demand from current observations; an already returned snapshot does not change when a response completes or the collector resets. Warnings are advisory: they never block calls, change responses or throw budget exceptions. There is no automatic PHPUnit failure mechanism beyond the assertions you choose to write.

## Example report

With limits of 3 calls, 800ms total, and 500ms for a slow call, three product requests followed by shipping and tax look like:

```text
OutboundGuard
5 HTTP calls | 1240.00ms total | 2 repeated calls | 1 slow call

Outbound call budget exceeded: 5 calls / maximum 3.
Total external HTTP duration exceeded: 1240.00ms / maximum 800ms.
Duplicate outbound endpoint: GET https://api.example.com/products/42 (3 calls; 2 repeats).
Slow outbound call #4: GET https://api.example.com/shipping (600.00ms / threshold 500ms).
```

This example opts into safe path display with `capture_paths: true`. By default, targets display as `https://api.example.com/[path hidden]`; different paths still have different fingerprints and groups.

## Profiler integration

If the application already has WebProfilerBundle, TwigBundle, and the framework profiler enabled, the **OutboundGuard** toolbar item and panel register automatically. Otherwise, follow [Symfony's profiler installation instructions](https://symfony.com/doc/7.4/profiler.html#installation). These are optional dependencies for applications; the core metrics and reports work without them.

The toolbar shows call count, known duration, repeats, slow calls and pending calls. It turns red for exceeded budgets and yellow for other warnings. The panel shows configured limits, warnings and a compact table:

```text
Method | Target                                  | Calls | Known duration | Status
GET    | https://api.example.com/products/42      | 3     | 600.00 ms      | 200
GET    | https://api.example.com/shipping         | 1     | 600.00 ms      | 200
GET    | https://api.example.com/taxes            | 1     |  40.00 ms      | 200
```

Pending and missing timings are labeled explicitly. Grouped rows aggregate known durations and list distinct observed status codes; a row's duration can exceed the slow-call threshold without any individual call being slow. The warning list evaluates each individual transfer.

The profiler collector snapshots on `kernel.response` and refreshes just before Symfony stores the profile, so responses consumed during streaming or termination can be included. It never forces an outbound response to complete.

Core metrics live in memory. When profiling is enabled, Symfony persists the safe report with its normal profiles and retention policy; OutboundGuard adds no database or separate persistence. Its collector serializes only the safe snapshot, excluding the live collector and fingerprint key. Other Symfony or third-party profiler panels have their own data collection policies; OutboundGuard does not sanitize those panels.

## Privacy and identity

The collector stores no request/response headers, cookies, bodies, raw options, exception messages, debug information, credentials, query strings, fragments, or environment values. The HTTP client still handles these normally; OutboundGuard's records contain only the selected metadata.

Paths are hidden by default because reset tokens and API keys can appear in paths. Set `capture_paths: true` only when paths in your application are safe to retain and display. This opt-in preserves the literal path; it cannot recognize arbitrary secrets embedded in it. Hostnames are visible and may themselves identify tenants or internal services.

Fingerprint input is the uppercased method plus normalized scheme, lowercase host, significant port, and path. The implementation uses HMAC-SHA256 with a random key renewed for every collection; it stores only the resulting fingerprint. Queries, user info and fragments are excluded completely. Default ports and empty root paths are normalized; path case, encoded separators and trailing slashes remain significant.

Consequently, `/items?page=1` and `/items?page=2` deliberately share an endpoint identity. Duplicate warnings identify repeated endpoints, not prove that request payloads or responses were identical. Fingerprints cannot be compared across incoming requests. Three calls to one endpoint mean **two repeated calls in one duplicate group**. Every accepted attempt counts, including pending, failed and cancelled calls.

## Timing and lifecycle

The client uses Symfony's asynchronous response decorator. It never forces headers or body reads from `request()`, changes response content, or adds retries.

- A call is counted when the underlying client accepts it, even before its response is consumed. A synchronous transport failure is counted too; invalid request arguments remain the client's own error.
- Status is captured when observed without blocking. Transfer duration comes from the client's `total_time` in seconds and is converted to milliseconds on completion, failure or cancellation when available.
- Unconsumed responses remain pending with unknown duration. HTTP failures are recognizable from a known 4xx/5xx status even before the transfer finishes. Normal HttpClient exceptions are preserved.
- Totals sum known terminal durations. Unknown durations contribute no time, rather than an invented estimate. A total is therefore partial while responses remain pending or lack timing information.
- Slow-call detection uses each known duration; failures and cancellations can still consume external time. Pending calls are counted for the call budget and duplicates but do not receive invented durations or slow-call warnings.
- Concurrent calls overlap: summed durations are **not** incoming-request wall-clock time.
- A main `kernel.request` starts a fresh collection. Subrequests contribute to the main collection. Metrics remain available after the response until the next main request or service reset.
- `kernel.reset` also clears the collector for long-running applications using Symfony service resets. Code using the collector outside a request lifecycle must call `reset()` between units of work.
- Old response callbacks retain their own call record, so finishing a response after a reset cannot contaminate the new collection.

## Architecture and limits

```text
Application's injected HttpClientInterface
  -> MonitoringHttpClient (container decoration)
     -> existing client / scoped client / existing decorators
     <- AsyncResponse, passing chunks through unchanged
  -> OutboundCallCollector
     -> safe OutboundCall records and aggregate metrics
     -> pure OutboundAnalyzer + configured Budget
        -> immutable OutboundReport
           -> application test assertions / optional profiler collector
```

The bundle uses `AbstractBundle` for configuration and service loading. A compiler pass decorates services tagged `http_client.client`, following Symfony's own HttpClient profiler integration. The transport is not separately decorated, so a scoped request is counted once.

Manually constructed clients (`HttpClient::create()`) bypass the container and are outside automatic monitoring. Custom client services can opt in with Symfony's `http_client.client` tag; avoid tagging both nested wrappers and their transport. Counts represent logical calls, not individual redirect hops or attempts inside an existing retry decorator. Timing follows information reported by the wrapped client; no separate network instrumentation is installed.

The analyzer performs no I/O and runs only when a report is requested. Warnings have stable order: call budget, duration budget, duplicate groups in first-seen order, then slow calls in request order.

See [the architecture notes](docs/architecture.md) for design decisions, the file map, and interview talking points.

## Remaining phases

Phases 1 and 2 are implemented. Phase 3 remains: cleanup, final security/configuration review, documentation and publication readiness, license file and release checklist.

Persistence, dashboards, caching, retries, tracing, external integrations, controller attributes, and a custom PHPUnit framework are outside this implementation.
