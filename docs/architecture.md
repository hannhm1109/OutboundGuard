# Phase 1 architecture

## Why the idea works

An incoming request can trigger an external HTTP version of N+1: repeated work is easy to miss when individual client calls look harmless. Symfony already exposes the needed extension points. A small observer and an in-memory collector make this visible without changing application call sites or introducing a storage system.

Phase 1 establishes observation and isolation. Phase 2 can analyze the same records without changing the transport integration. A separate analyzer is not necessary until there are actual rules to run.

## Symfony decisions

### Modern bundle configuration

`OutboundGuardBundle` extends `AbstractBundle`. Its `configure()` hook builds the `outbound_guard` configuration tree; `loadExtension()` loads explicit service definitions. This supplies Symfony's DI extension/configuration behavior without redundant legacy `Extension` and `Configuration` classes.

Configuration is processed and services are wired at container compilation, not on each HTTP call. Services are private and constructor-injected. The two initial switches are `enabled` and `capture_paths`; budgets belong to the next phase.

### Decorate every logical client

FrameworkBundle registers the default and scoped clients with `http_client.client`. Scoped clients reference the common transport directly; decorating only `http_client` misses them. The compiler pass registers one decorator per tagged service, just as Symfony's own profiler does.

Decoration priority 0 places OutboundGuard outside Symfony's built-in URI template, retry and tracing decorators. URI expansion and base URI resolution therefore happen before the safe target is captured, and an existing retry mechanism counts as one logical call. Redirects do not mutate the original target identity.

No new application-facing HTTP interface is needed. Existing `HttpClientInterface` autowiring aliases still resolve to the corresponding decorated service.

### Preserve asynchronous semantics

Measuring only the runtime of `request()` would mostly measure request scheduling. Calling `getContent()` from that method would force synchronous consumption and break streaming.

`AsyncDecoratorTrait` supplies Symfony-compatible `stream()` and `withOptions()` behavior. `AsyncResponse` supplies the response wrapper and invokes a generator callback as chunks are processed. Our callback passes the exact chunks through and reads only status, timing and cancellation information. It never examines chunk content.

The call object is initialized just after `AsyncResponse` accepts the request, using its non-blocking `getInfo('url')` to obtain the resolved original URL. The callback holds a reference to that one object. This short deferred initialization avoids reimplementing URI resolution or retaining raw options in our records.

Transport errors and resumable polling timeouts differ: polling timeouts leave the call pending; terminal errors mark it failed. Error chunks still reach Symfony and the caller. A synchronous transport exception is rethrown unchanged and yields a failed record with unknown duration. Cancellation is distinct from success.

### A shared service with request-local contents

Symfony services are generally shared, so shared does not mean request-scoped. `RequestResetSubscriber` clears the collection at the highest request-event priority for main requests, before ordinary routing, security and application listeners. It does not clear on subrequests or response events. Applications should avoid scheduling network work before the request lifecycle begins if they expect it in that request's metrics.

`OutboundCallCollector` also implements `ResetInterface` and is explicitly tagged `kernel.reset`. This participates in the framework's service-reset mechanism without relying on the application's autoconfiguration settings.

Reset replaces the array and its random fingerprint key. Each outstanding response holds its old call record rather than the collector or an array index. Late completion therefore cannot overwrite a new request's metrics or reinsert stale data. This supports sequential requests in a reused kernel; simultaneous incoming requests sharing one container are outside this model.

## Data model

| Object | Contents | Purpose |
| --- | --- | --- |
| `RequestTarget` | Readonly host, optional path, display URL, keyed fingerprint | Retains only the chosen safe identity |
| `OutboundCall` | Readonly method/target; observed nullable status/duration; outcome | Represents one asynchronous logical call |
| `CallOutcome` | Pending, success, HTTP error, transport error, cancelled | Makes incomplete and failed transfers explicit |
| `OutboundCallCollector` | List of calls and a random collection key | Exposes count, known total time and slowest known call |

Metadata fields are narrow typed properties, not a copy of `getInfo()` or the request options. Mutating observation methods are marked internal; consumers should use getters. Completion is idempotent. An unconsumed response is not a successful zero-millisecond request.

## Safe duplicate foundation

The fingerprint uses:

```text
HMAC-SHA256(collection key, UPPERCASE_METHOD + " " + normalized origin + original path)
```

The scheme/host are lowercase, default ports are omitted and an empty path becomes `/`. Path case, encoding and trailing slash are preserved to avoid conflating distinct resources. Credentials, fragments and the whole query string are discarded before hashing. This choice also ignores `query` options without having to inspect them.

A fresh random key prevents stable cross-request identifiers and makes simple dictionary guesses from stored hashes harder. It is not encryption and is not an anonymity guarantee. The collector and key remain in memory; only the safe call records should ever be handed to a profiler or report.

The explicit trade-off is endpoint-level identity: pagination/filter/token differences in a query do not create distinct endpoints. Method differences do. Bodies are neither stored nor fingerprinted, so a later repeated-POST warning cannot prove duplicate business operations.

Even path-only display can expose secrets. The secure default therefore retains no path text in the target object, while still allowing comparisons using the keyed fingerprint. Opting into path display transfers that decision to the application owner. No heuristic redaction claims to recognize every possible secret.

## Files

| File | Responsibility |
| --- | --- |
| `composer.json` | Package metadata, dependencies, PSR-4 mappings and check commands |
| `src/OutboundGuardBundle.php` | Configuration, services import and compiler-pass registration |
| `config/services.php` | Explicit collector/subscriber wiring and reset/event tags |
| `src/DependencyInjection/Compiler/HttpClientPass.php` | Container decoration of default and scoped clients |
| `src/HttpClient/MonitoringHttpClient.php` | Lazy response observation, transport failure handling |
| `src/Collector/OutboundCallCollector.php` | In-memory metrics and reset boundary |
| `src/Metadata/{RequestTarget,OutboundCall,CallOutcome}.php` | Privacy boundary and typed call state |
| `src/EventSubscriber/RequestResetSubscriber.php` | Incoming main-request lifecycle |
| `tests/Unit/` | Deterministic client, metadata, metrics and subscriber behavior |
| `tests/Fixtures/` | Minimal real kernel, mock response factory and autowired consumer |
| `tests/Integration/BundleIntegrationTest.php` | Actual container compilation, client injection, requests and service resets |
| `phpunit.xml.dist`, `phpstan.neon.dist` | Test and static-analysis configuration |
| `.github/workflows/ci.yml` | Checks for Symfony 6.4/PHP 8.1, Symfony 7.4/PHP 8.2, and Symfony 8.1/PHP 8.4 |
| `README.md`, `docs/architecture.md` | Usage, scope, design rationale and limitations |
| `.gitignore`, `.gitattributes`, `.editorconfig` | Dependency/cache exclusions and text conventions |

## Tests and useful experiments

Run `composer check`. Tests use no real APIs, sleep calls or timing tolerances; mock response `total_time` supplies deterministic durations. Integration tests boot FrameworkBundle and this bundle together, so service decoration and reset tags are actually exercised.

Read the tests in this order:

1. `MonitoringHttpClientTest::testOneRequestRemainsLazyAndRecordsMetadataAfterConsumption`: request scheduling versus transfer completion.
2. `BundleIntegrationTest::testExistingDefaultAndScopedClientInjectionIsTransparentlyMonitored`: transparent autowiring and scoped clients counted once.
3. `MonitoringHttpClientTest::testLateResponsesCannotContaminateTheNextCollection`: isolation beyond simply emptying an array.
4. `RequestTargetTest` and the client's secrets test: the privacy boundary, including query strings and path secrets.
5. The polling-timeout test: an idle stream event does not necessarily mean permanent failure.

## Interview talking points

- A bundle packages reusable integration; an application owns its business logic and deployment.
- Service decoration preserves existing call sites and contracts. A compiler pass handles a tagged family of clients at compile time.
- Shared DI services need explicit lifecycle management when holding mutable per-request state.
- Async clients require observing response progress/completion rather than just timing `request()`.
- Safe data modeling is stronger than sanitizing a large dump after collection. Nullable values distinguish unknown from zero.
- Summed external duration measures accumulated transfer work, with a different meaning from wall-clock latency under concurrency.
- Endpoint repetition is a useful signal, not proof that repeated requests are bugs. Query omission is a documented privacy/precision trade-off.
- Unit tests prove local behavior; real-kernel tests prove the Symfony integration actually connects those behaviors.

## Scope boundary

Phase 1 deliberately stops before budgets, duplicate grouping, warnings and profiler templates. Controller attributes remain optional and are not part of this implementation. No persistence, custom event bus, service locator, HTTP replacement framework or external observability system is introduced.

For future evolution, detection can consume these records and a Symfony data collector can copy their safe fields for the profiler. Neither needs transport changes or a database.

## References checked for this implementation

Symfony's maintained branches were checked on 2026-10-19. The local baseline is PHP 8.1 with Symfony 6.4; newer-branch checks use an isolated portable PHP runtime rather than replacing XAMPP.

- [Symfony releases](https://symfony.com/releases)
- [HttpClient extensibility](https://symfony.com/doc/7.4/http_client.html#extensibility)
- [Bundle service loading with AbstractBundle](https://symfony.com/doc/7.4/bundles/extension.html)
- [Reusable bundle best practices](https://symfony.com/doc/7.4/bundles/best_practices.html)
- [Service decoration](https://symfony.com/doc/7.4/service_container/decoration.html)
- Symfony's installed `HttpClient/DependencyInjection/HttpClientPass.php`, `AsyncDecoratorTrait`, `AsyncResponse`, and FrameworkBundle HttpClient service definitions.
