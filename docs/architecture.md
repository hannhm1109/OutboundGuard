# Architecture

## Why the idea works

An incoming request can trigger an external HTTP version of N+1: repeated work is easy to miss when individual client calls look harmless. Symfony already exposes the needed extension points. A small observer and an in-memory collector make this visible without changing application call sites or introducing a storage system.

Collection establishes observation and isolation. A pure analyzer applies four simple rules to the same records, while the profiler reads their safe report.

## Symfony decisions

### Modern bundle configuration

`OutboundGuardBundle` extends `AbstractBundle`. Its `configure()` hook builds the `outbound_guard` configuration tree; `loadExtension()` loads explicit service definitions. This supplies Symfony's DI extension/configuration behavior without redundant legacy `Extension` and `Configuration` classes.

Configuration is processed and services are wired at container compilation, not on each HTTP call. Services are private and constructor-injected. `enabled` and `capture_paths` control collection. `max_calls`, `max_total_duration_ms`, and `slow_call_threshold_ms` are non-negative integer limits with defaults of 5, 1000, and 500. Symfony merges configuration resources and rejects invalid values before the container boots.

### Decorate every logical client

FrameworkBundle registers the default and scoped clients with `http_client.client`. Scoped clients reference the common transport directly; decorating only `http_client` misses them. The compiler pass registers one decorator per tagged service, just as Symfony's own profiler does.

Decoration priority 0 places OutboundGuard outside Symfony's built-in URI template, retry and tracing decorators. URI expansion and base URI resolution therefore happen before the safe target is captured, and an existing retry mechanism counts as one logical call. Redirects do not mutate the original target identity.

No new application-facing HTTP interface is needed. Existing `HttpClientInterface` autowiring aliases still resolve to the corresponding decorated service.

### Preserve asynchronous semantics

Measuring only the runtime of `request()` would mostly measure request scheduling. Calling `getContent()` from that method would force synchronous consumption and break streaming.

`AsyncDecoratorTrait` supplies Symfony-compatible `stream()` and `withOptions()` behavior. `AsyncResponse` supplies the response wrapper and invokes a generator callback as chunks are processed. Our callback passes the exact chunks through and reads only status, timing and cancellation information. It never examines chunk content.

The monitor overrides `withOptions()` only to carry buffering defaults into `AsyncResponse`, which owns the buffer. Forwarding `buffer: false` solely to the inner client would otherwise silently re-enable buffering at the wrapper. Clone semantics and per-request precedence are regression-tested. Buffer defaults set only on an already wrapped inner client are not introspectable through the contract and must be repeated at the monitored call site.

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
| `Budget` | Readonly non-negative global limits | Shares validated limits between analyzer, reports and UI |
| `OutboundAnalyzer` | Stateless rule evaluation | Groups fingerprints and calculates warnings without I/O |
| `OutboundReport` | Readonly metrics, safe group/warning arrays and budget | Immutable snapshot for assertions and profiler serialization |

Metadata fields are narrow typed properties, not a copy of `getInfo()` or the request options. Mutating observation methods are marked internal; consumers should use getters. Completion is idempotent. An unconsumed response is not a successful zero-millisecond request.

## Safe duplicate foundation

The fingerprint uses:

```text
HMAC-SHA256(collection key, UPPERCASE_METHOD + " " + normalized origin + original path)
```

The scheme/host are lowercase, default ports are omitted and an empty path becomes `/`. Path case, encoding and trailing slash are preserved to avoid conflating distinct resources. Credentials, fragments and the whole query string are discarded before hashing. This choice also ignores `query` options without having to inspect them.

A fresh random key prevents stable cross-request identifiers and makes simple dictionary guesses from stored hashes harder. It is not encryption and is not an anonymity guarantee. The collector and key remain in memory; only the safe call records should ever be handed to a profiler or report.

The explicit trade-off is endpoint-level identity: pagination/filter/token differences in a query do not create distinct endpoints. Method differences do. Bodies are neither stored nor fingerprinted, so a repeated-POST warning cannot prove duplicate business operations.

Even path-only display can expose secrets. The secure default therefore retains no path text in the target object, while still allowing comparisons using the keyed fingerprint. Opting into path display transfers that decision to the application owner. No heuristic redaction claims to recognize every possible secret.

## Detection and reports

The collector injects a `Budget` and an `OutboundAnalyzer`; `getReport()` evaluates the current list on demand. There is no cached result to become stale while asynchronous responses complete, and no rule evaluation on individual response chunks.

All comparisons are strict `>`: equality passes, and zero is a usable limit. Known durations from successful, failed and cancelled transfers contribute to duration rules. Pending calls contribute to call count and duplicates while their timing stays unknown. Incomplete totals are labeled, not treated as a complete measurement.

Grouping uses the fingerprint, never the displayed URL. With paths hidden, two different endpoints can display the same origin while remaining separate groups. Repeated-call count is the sum of `count - 1` across groups: counts 3 and 2 produce 3 repeats across 2 groups. Each group retains only method, safe display target, occurrence count, summed known duration, missing-duration count and distinct known statuses. It contains no raw call, response, query, fingerprint or collection key.

Warnings are safe `code`/`message` pairs in deterministic order: call budget, duration budget, duplicate groups in first-seen order, and slow calls in request order. The four stable codes are `max_calls`, `max_total_duration`, `duplicate_call`, and `slow_call`. Reports copy scalar values and readonly budget data, so later completion or reset cannot mutate an existing report.

## Optional Symfony profiler

The profiler integration is one data collector, one Twig template and conditional service wiring. `ProfilerPass` runs before Symfony's own profiler pass and registers the tagged collector only when the core collector, framework profiler, WebProfiler controller and Twig definitions exist. No application needs Twig or WebProfilerBundle to use the core.

`OutboundGuardDataCollector` extends HttpKernel's `DataCollector` and implements `LateDataCollectorInterface`. It picks up a snapshot on response and refreshes it just before the profile is saved at termination. This can observe work consumed during a streamed response or a termination listener without forcing network reads. Normal request/terminate ordering is assumed; simultaneous incoming requests sharing a container remain outside the collection model.

Only the safe report in the inherited `$data` property is serialized. Symfony's normal profiler storage persists that report when enabled; the source service, raw calls and fingerprint key are excluded. Other panels, including Symfony's existing HttpClient panel, have independent privacy behavior. OutboundGuard is not a global profiler scrubber.

The Twig template extends the native profiler layout and provides toolbar, menu and panel blocks. It relies on Symfony's existing styles, keeps target text escaped, and displays unknown/partial timing explicitly. It adds no JavaScript, custom routes, database or separate dashboard.

## Files

| File | Responsibility |
| --- | --- |
| `composer.json` | Package metadata, dependencies, PSR-4 mappings and check commands |
| `src/OutboundGuardBundle.php` | Configuration, services import and compiler-pass registration |
| `config/services.php` | Explicit collector/subscriber wiring and reset/event tags |
| `src/DependencyInjection/Compiler/HttpClientPass.php` | Container decoration of default and scoped clients |
| `src/HttpClient/MonitoringHttpClient.php` | Lazy response observation, transport failure handling |
| `src/Collector/OutboundCallCollector.php` | In-memory metrics and reset boundary |
| `src/Analysis/{Budget,OutboundAnalyzer,OutboundReport}.php` | Global limits, deterministic rules and immutable safe snapshots |
| `src/DataCollector/OutboundGuardDataCollector.php` | Early/late profiler snapshots and safe serialization |
| `src/DependencyInjection/Compiler/ProfilerPass.php` | Conditional profiler registration before Symfony processes collectors |
| `templates/Collector/outbound_guard.html.twig` | Native toolbar, menu, grouped call table and warnings |
| `src/Metadata/{RequestTarget,OutboundCall,CallOutcome}.php` | Privacy boundary and typed call state |
| `src/EventSubscriber/RequestResetSubscriber.php` | Incoming main-request lifecycle |
| `tests/Unit/` | Deterministic client, metadata, metrics and subscriber behavior |
| `tests/Fixtures/` | Minimal real kernel, mock response factory, profiler controller/routes and autowired consumer |
| `tests/Integration/BundleIntegrationTest.php` | Actual container compilation, client injection, requests and service resets |
| `tests/Integration/ProfilerIntegrationTest.php` | Real profile storage, panel/toolbar routes, privacy, escaping and reset isolation |
| `phpunit.xml.dist`, `phpstan.neon.dist` | Test and static-analysis configuration |
| `.github/workflows/ci.yml` | Checks for Symfony 6.4/PHP 8.1, Symfony 7.4/PHP 8.2, and Symfony 8.1/PHP 8.4 |
| `README.md`, `docs/architecture.md` | Usage, scope, design rationale and limitations |
| `.gitignore`, `.gitattributes`, `.editorconfig` | Dependency/cache exclusions and text conventions |
| `LICENSE`, `CHANGELOG.md`, `CONTRIBUTING.md`, `SECURITY.md` | License, initial release notes, verification and privacy/reporting policy |
| `docs/index.md`, `docs/releasing.md` | Documentation entry point, publication metadata and release checklist |

## Tests and useful experiments

Run `composer check`. Tests use no real APIs, sleep calls or timing tolerances; mock response `total_time` supplies deterministic durations. Integration tests boot FrameworkBundle and this bundle together, so service decoration and reset tags are actually exercised. Profiler tests also register TwigBundle and WebProfilerBundle, save/reload real profiles, and request the native panel and toolbar through KernelBrowser.

Read the tests in this order:

1. `MonitoringHttpClientTest::testOneRequestRemainsLazyAndRecordsMetadataAfterConsumption`: request scheduling versus transfer completion.
2. `BundleIntegrationTest::testExistingDefaultAndScopedClientInjectionIsTransparentlyMonitored`: transparent autowiring and scoped clients counted once.
3. `MonitoringHttpClientTest::testLateResponsesCannotContaminateTheNextCollection`: isolation beyond simply emptying an array.
4. `RequestTargetTest` and the client's secrets test: the privacy boundary, including query strings and path secrets.
5. The polling-timeout test: an idle stream event does not necessarily mean permanent failure.
6. `OutboundAnalyzerTest::testCheckoutExampleReportsAllFourRulesWithStableOrdering` and the boundary provider: the full example and strict comparison semantics.
7. `ProfilerIntegrationTest`: the feature connects to Symfony's real profiler rather than only rendering a standalone template.

## Interview talking points

- A bundle packages reusable integration; an application owns its business logic and deployment.
- Service decoration preserves existing call sites and contracts. A compiler pass handles a tagged family of clients at compile time.
- Shared DI services need explicit lifecycle management when holding mutable per-request state.
- Async clients require observing response progress/completion rather than just timing `request()`.
- Safe data modeling is stronger than sanitizing a large dump after collection. Nullable values distinguish unknown from zero.
- Summed external duration measures accumulated transfer work, with a different meaning from wall-clock latency under concurrency.
- Endpoint repetition is a useful signal, not proof that repeated requests are bugs. Query omission is a documented privacy/precision trade-off.
- Unit tests prove local behavior; real-kernel tests prove the Symfony integration actually connects those behaviors.
- Pure rule evaluation is easier to test than warnings emitted from transport callbacks. On-demand immutable reports avoid stale cached metrics and mutable profiler data.
- A profiler collector picks up observations; it does not perform the monitored work. Late collection and safe serialization are separate lifecycle concerns.

## Scope boundary

The MVP includes global budgets, duplicate grouping, slow-call detection, warnings and the native profiler panel. Controller attributes remain optional and are not part of this implementation. No custom persistence, event bus, service locator, HTTP replacement framework or external observability system is introduced.

Future controller overrides could supply a different budget to this same analyzer; they do not need a rule engine or transport changes. Publication preparation adds packaging metadata, a license, documentation and verification without expanding runtime features.

## References checked for this implementation

Symfony's maintained branches were checked on 2026-10-19. The local baseline is PHP 8.1 with Symfony 6.4; newer-branch checks use an isolated portable PHP runtime rather than replacing XAMPP.

- [Symfony releases](https://symfony.com/releases)
- [HttpClient extensibility](https://symfony.com/doc/7.4/http_client.html#extensibility)
- [Bundle service loading with AbstractBundle](https://symfony.com/doc/7.4/bundles/extension.html)
- [Reusable bundle best practices](https://symfony.com/doc/7.4/bundles/best_practices.html)
- [Service decoration](https://symfony.com/doc/7.4/service_container/decoration.html)
- [Symfony profiler and custom data collectors](https://symfony.com/doc/7.4/profiler.html)
- Symfony's installed `HttpClient/DependencyInjection/HttpClientPass.php`, `AsyncDecoratorTrait`, `AsyncResponse`, and FrameworkBundle HttpClient service definitions.
