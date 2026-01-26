# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.1.1] - 2026-01-26

### Fixed

#### PHPStan Static Analysis
- **GEMVC Framework Stub Files** - Added stub files for GEMVC framework classes to resolve PHPStan errors
  - Created `stubs/Gemvc/Http/Request.php` - Request class stub with required methods and properties
  - Created `stubs/Gemvc/Helper/ProjectHelper.php` - ProjectHelper class stub with static methods
  - Created `stubs/Gemvc/CLI/Command.php` - Command class stub for CLI infrastructure
  - Created `stubs/Gemvc/CLI/Commands/CliBoxShow.php` - CliBoxShow class stub for CLI display
  - Created `stubs/Gemvc/Http/JsonResponse.php` - JsonResponse class stub
  - Created `stubs/Gemvc/Http/AsyncApiCall.php` - AsyncApiCall class stub
  - Created `stubs/Gemvc/Http/Response.php` - Response class stub
  - Updated `phpstan.neon` to include stub files in bootstrap configuration
  - PHPStan now passes with no errors (level 9)

#### PHPUnit Test Runtime
- **Test Bootstrap Enhancement** - Fixed PHPUnit test failures by loading stub files at runtime
  - Updated `tests/bootstrap.php` to require all GEMVC framework stub files
  - Tests now pass successfully (87 tests, 200 assertions)
  - All 18 previous runtime errors resolved

#### Type Safety Improvements
- **TraceKitProvider Type Narrowing** - Fixed type narrowing issues in `flush()` method
  - Improved array access type safety for OTLP payload structure
  - Added proper type assertions for nested array access
  - Fixed string casting issues in debug logging
  - Enhanced type hints in `TraceKitInit` constructor parameters

### Changed

- **PHPStan Configuration** - Added `bootstrapFiles` configuration to `phpstan.neon` for stub file loading
- **Test Bootstrap** - Enhanced `tests/bootstrap.php` to load framework stubs before test execution

---

## [2.0.0] - 2026-01-14

### Added

#### Batching System Integration
- **AbstractApm Batching** - Full integration with `AbstractApm`'s batching system from `apm-contracts` 1.4.0
- **Batch Payload Building** - Implemented `buildBatchPayload()` to combine multiple traces into single batch requests
- **Batch Endpoint Configuration** - Implemented `getBatchEndpoint()` for batch API endpoint
- **Batch Headers Configuration** - Implemented `getBatchHeaders()` for batch request headers
- **Time-Based Batching** - Traces are automatically batched and sent every 5 seconds (configurable via `APM_SEND_INTERVAL`)
- **Shutdown Safety** - `forceSendBatch()` ensures all pending traces are sent immediately on shutdown

#### OpenSwoole Compatibility
- **Synchronous ApiCall** - Replaced `AsyncApiCall` with synchronous `ApiCall` for OpenSwoole production compatibility
- **Reliable Delivery** - Synchronous calls ensure traces are sent reliably without async operation issues

### Changed

#### Breaking Changes
- **Trace Sending Method** - Migrated from `AsyncApiCall::fireAndForget()` to `AbstractApm`'s batching system
  - Traces are now batched and sent every 5 seconds instead of immediately
  - Uses synchronous `ApiCall` instead of async operations
  - Behavior change: Traces may appear in dashboards with up to 5 seconds delay (configurable)
- **Dependency Update** - Updated `gemvc/apm-contracts` requirement from `^1.0` to `^1.4.0`
  - Requires implementation of abstract batching methods
  - Benefits from improved batch send interval (default: 5 seconds, was 10 seconds in older versions)

#### Implementation Changes
- **flush() Method** - Now adds traces to batch queue instead of sending immediately
  - Calls `addTraceToBatch()` to queue traces
  - Calls `sendBatchIfNeeded()` to check if batch should be sent (time-based)
- **flushOnShutdown() Method** - Now calls `forceSendBatch()` after flush to ensure all traces are sent
- **Batching Methods** - Implemented required abstract methods from `AbstractApm`:
  - `buildBatchPayload()` - Combines multiple trace payloads into single OTLP batch
  - `getBatchEndpoint()` - Returns TraceKit endpoint URL
  - `getBatchHeaders()` - Returns HTTP headers with API key

### Removed

- **AsyncApiCall Usage** - Completely removed `AsyncApiCall::fireAndForget()` pattern
- **sendTraces() Method** - Removed private method that used `AsyncApiCall` (no longer needed)
- **Unused Helper Methods** - Removed `extractServiceNameFromPayload()` and `validatePayloadStructure()` methods that were only used by the old `sendTraces()` method

### Fixed

- **OpenSwoole Production Issues** - Fixed errors and problems caused by `AsyncApiCall` in OpenSwoole production environments
- **Trace Reliability** - Improved trace delivery reliability with synchronous `ApiCall` and batching system
- **Shutdown Data Loss** - Ensured all traces are sent on shutdown using `forceSendBatch()`

### Migration Guide

**For Users:**
- No code changes required - the API remains the same
- Traces may appear in dashboards with up to 5 seconds delay (configurable via `APM_SEND_INTERVAL`)
- If you need immediate trace visibility, set `APM_SEND_INTERVAL=1` (minimum: 1 second)
- All traces are guaranteed to be sent on shutdown, so no data loss occurs

**For Developers:**
- Update `composer.json` to require `gemvc/apm-contracts` ^1.4.0
- Run `composer update` to get the latest dependencies
- No breaking API changes - all public methods remain the same

### Performance

- **Batch Efficiency** - Multiple traces are combined into single requests, reducing HTTP overhead
- **Configurable Frequency** - Adjust `APM_SEND_INTERVAL` to balance trace visibility vs. batch efficiency
- **OpenSwoole Compatible** - No async operations that cause production issues

---

## [1.1.0] - 2026-01-03

### Added

#### CLI Commands
- **Standalone Binary** - `bin/tracekit` executable for TraceKit commands
  - Reuses GEMVC CLI patterns for autoloader discovery and command routing
  - Uses `ProjectHelper` for environment setup and project root detection
  - Available as `php vendor/bin/tracekit <command>` after package installation
  - Windows support via `bin/tracekit.bat` wrapper
- **TraceKitInit Command** - Interactive setup wizard for TraceKit APM
  - Welcome banner with TraceKit features overview
  - Existing configuration detection and management
  - Two setup methods: Easy Register (email verification) and Manual API key entry
  - Registration flow with async email verification (CLI waits for user input, does not exit)
  - Verification code retry logic (max 3 attempts)
  - Service name setup with validation
  - Automatic `.env` file configuration using `ProjectHelper::updateEnvVariables()`
  - Connection testing after setup to verify API key
  - Success message with next steps
  - Uses GEMVC CLI infrastructure (`Command`, `CliBoxShow`, `ProjectHelper`)
- **TraceKitCommandCategories** - Command registration and discovery support
  - Command category definitions for GEMVC CLI system
  - Command class mappings
  - Example usage documentation (updated for standalone binary syntax)

### Changed

- None

### Deprecated

- None

### Removed

- None

### Fixed

- None

---

## [1.0.0] - 2026-01-01

### Added

#### Core Implementation
- **TraceKitProvider** - Main APM provider class implementing `ApmInterface`
  - Extends `AbstractApm` from `gemvc/apm-contracts`
  - Used by `ApmFactory` when `APM_NAME="TraceKit"`
  - Implements all required interface methods
  - Supports OpenTelemetry OTLP JSON format
  - Non-blocking trace sending via `AsyncApiCall`
  - Stack-based span context management
  - Automatic root trace creation for HTTP requests via `startTrace()`
  - Child span support with parent-child relationships via `startSpan()`
  - Exception recording with stack traces via `recordException()`
  - Configurable sampling support with force sampling for errors
  - Trace flags for response, DB query, and request body tracing
  - Static instance management (`getCurrentInstance()`, `clearCurrentInstance()`)

- **TraceKitModel** - Alternative APM provider implementation
  - Extends `AbstractApm` from `gemvc/apm-contracts`
  - Additional public methods: `addEvent()`, `getSampleRate()`, `getSampleRatePercent()`, `getActiveSpan()`, `getTraceId()`
  - Similar functionality to `TraceKitProvider` with extended API
  - Not used by `ApmFactory` (factory uses `TraceKitProvider`)

- **TraceKitToolkit** - Client-side integration and management class
  - Extends `AbstractApmToolkit` from `gemvc/apm-contracts`
  - Implements all toolkit interface methods
  - Account management (registration, verification, status)
  - Health monitoring (heartbeat, health checks)
  - Service metrics retrieval
  - Alerts management (summary, active alerts)
  - Webhook management (create, list)
  - Billing integration (subscription, plans, checkout)

#### Configuration Support
- Environment variable configuration
  - `TRACEKIT_API_KEY` - API key for TraceKit service
  - `TRACEKIT_SERVICE_NAME` - Service name for traces
  - `TRACEKIT_ENDPOINT` - Optional endpoint override
  - `TRACEKIT_SAMPLE_RATE` - Sample rate override
  - `TRACEKIT_TRACE_RESPONSE` - Response tracing flag
  - `TRACEKIT_TRACE_DB_QUERY` - DB query tracing flag
  - `TRACEKIT_TRACE_REQUEST_BODY` - Request body tracing flag
- Unified APM configuration support
  - `APM_API_KEY` - Unified API key (works for all providers)
  - `APM_NAME` - Provider name (must be "TraceKit")
  - `APM_ENABLED` - Enable/disable APM
  - `APM_SAMPLE_RATE` - Unified sample rate
  - `APM_TRACE_RESPONSE` - Unified response tracing flag
  - `APM_TRACE_DB_QUERY` - Unified DB query tracing flag
  - `APM_TRACE_REQUEST_BODY` - Unified request body tracing flag
- Configuration priority: config array > provider env vars > unified env vars > defaults
- Config array support in constructor for runtime configuration

#### OpenTelemetry OTLP Format
- Standard OpenTelemetry OTLP JSON format implementation
- Trace ID generation (32-character hex strings, 128 bits)
- Span ID generation (16-character hex strings, 64 bits)
- Nanosecond timestamp support
- Service name in resource attributes
- Span hierarchy with parent-child relationships
- Event recording (exceptions, custom events)
- Status codes (OK, ERROR)
- Span kinds (SERVER, CLIENT, INTERNAL, PRODUCER, CONSUMER)

#### Non-Blocking Trace Sending
- Fire-and-forget trace delivery using `AsyncApiCall::fireAndForget()`
- HTTP response sent before trace delivery
- Background trace sending after response completion
- Graceful error handling (failures don't affect application)
- Automatic trace flushing on shutdown

#### Span Management
- Stack-based span context propagation
- Automatic root span creation for HTTP requests
- Child span creation with automatic parent detection
- Span lifecycle management (start, end, exception recording)
- Sampling support with configurable sample rate
- Force sampling for errors (always trace errors)
- Span attributes normalization
- Event attachment to spans

#### Testing
- Unit tests for `TraceKitProvider`
- Unit tests for `TraceKitToolkit`
- Integration tests with mock requests
- Protocol tests for OTLP format compliance
- Test helpers (`MockRequest`, `MockApiCall`)
- Comprehensive test coverage

#### Documentation
- README.md with comprehensive documentation
- Architecture diagrams
- Usage examples
- Configuration reference
- API reference
- Environment variables documentation

### Changed

- None (initial release)

### Deprecated

- None (initial release)

### Removed

- None (initial release)

### Fixed

- None (initial release)

### Security

- API key stored securely in environment variables
- No sensitive data in trace payloads (configurable)
- Request body tracing is opt-in (disabled by default)
- Graceful error handling prevents information leakage

---

## Version History

- **2.1.1** (2026-01-26) - PHPStan & PHPUnit Bug Fixes
- **2.0.0** (2026-01-14) - ApiCall Batching & OpenSwoole Compatibility
- **1.1.0** (2026-01-03) - CLI Setup Wizard
- **1.0.0** (2026-01-01) - Initial release

---

## Notes

- This package requires `gemvc/apm-contracts` ^1.4.0 (since version 2.0.0)
- This package requires `gemvc/library` ^5.2
- PHP 8.2+ is required
- See RELEASE_NOTES.md for detailed release information
- See TESTING_PROTOCOL.md for testing information

