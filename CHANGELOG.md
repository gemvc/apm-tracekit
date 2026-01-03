# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

- **1.1.0** (2026-01-03) - CLI Setup Wizard
- **1.0.0** (2026-01-01) - Initial release

---

## Notes

- This package requires `gemvc/apm-contracts` ^1.0
- This package requires `gemvc/library` ^5.2
- PHP 8.2+ is required
- See RELEASE_NOTES.md for detailed release information
- See TESTING_PROTOCOL.md for testing information

