# OutboundGuardBundle

OutboundGuard helps Symfony developers inspect outbound HTTP calls during development and testing.

This repository currently contains **Phase 1: the collection core**. It collects safe metadata and exposes metrics for assertions. Budgets, duplicate/slow-call warnings, and a profiler panel are planned for Phase 2; publication polish belongs to Phase 3.

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

Optional Phase 1 configuration, in `config/packages/dev/outbound_guard.yaml` (and the corresponding test directory):

```yaml
outbound_guard:
    enabled: true
    capture_paths: false
```

These are the only options currently implemented. `enabled: false` prevents registration of monitoring services and decorators at container compilation. Registering the bundle only in `dev`/`test` keeps it out of production.

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
```

Metrics are in memory. There is no database, log output, or automatic test failure mechanism.

## Privacy and identity

The collector stores no request/response headers, cookies, bodies, raw options, exception messages, debug information, credentials, query strings, fragments, or environment values. The HTTP client still handles these normally; OutboundGuard's records contain only the selected metadata.

Paths are hidden by default because reset tokens and API keys can appear in paths. Set `capture_paths: true` only when paths in your application are safe to retain and display. This opt-in preserves the literal path; it cannot recognize arbitrary secrets embedded in it. Hostnames are visible and may themselves identify tenants or internal services.

Fingerprint input is the uppercased method plus normalized scheme, lowercase host, significant port, and path. The implementation uses HMAC-SHA256 with a random key renewed for every collection; it stores only the resulting fingerprint. Queries, user info and fragments are excluded completely. Default ports and empty root paths are normalized; path case, encoded separators and trailing slashes remain significant.

Consequently, `/items?page=1` and `/items?page=2` deliberately share an endpoint identity. Later duplicate warnings will identify repeated endpoints, not prove that request payloads or responses were identical. Fingerprints cannot be compared across incoming requests. Phase 1 provides the identity foundation; it does not yet group duplicates or generate warnings.

## Timing and lifecycle

The client uses Symfony's asynchronous response decorator. It never forces headers or body reads from `request()`, changes response content, or adds retries.

- A call is counted when the underlying client accepts it, even before its response is consumed. A synchronous transport failure is counted too; invalid request arguments remain the client's own error.
- Status is captured when observed without blocking. Transfer duration comes from the client's `total_time` in seconds and is converted to milliseconds on completion, failure or cancellation when available.
- Unconsumed responses remain pending with unknown duration. HTTP failures are recognizable from a known 4xx/5xx status even before the transfer finishes. Normal HttpClient exceptions are preserved.
- Totals sum known terminal durations. Unknown durations contribute no time, rather than an invented estimate. A total is therefore partial while responses remain pending or lack timing information.
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
```

The bundle uses `AbstractBundle` for configuration and service loading. A compiler pass decorates services tagged `http_client.client`, following Symfony's own HttpClient profiler integration. The transport is not separately decorated, so a scoped request is counted once.

Manually constructed clients (`HttpClient::create()`) bypass the container and are outside automatic monitoring. Custom client services can opt in with Symfony's `http_client.client` tag; avoid tagging both nested wrappers and their transport. Counts represent logical calls, not individual redirect hops or attempts inside an existing retry decorator. Timing follows information reported by the wrapped client; no separate network instrumentation is installed.

See [the Phase 1 architecture notes](docs/architecture.md) for design decisions, the file map, and interview talking points.

## Remaining phases

2. Deterministic budgets, duplicate/slow-call detection, warnings, and a proportional profiler panel.
3. Cleanup, final security/configuration review, documentation and publication readiness, license file and release checklist.

Persistence, dashboards, caching, retries, tracing, external integrations, controller attributes, and a custom PHPUnit framework are outside this implementation.
