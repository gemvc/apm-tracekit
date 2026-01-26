# Release Notes

## Version 2.1.1 - PHPStan & PHPUnit Bug Fixes

**Release Date:** 2026-01-26

### Overview

This patch release fixes PHPStan static analysis errors and PHPUnit test runtime errors by adding GEMVC framework stub files. These stubs allow the package to be analyzed and tested independently without requiring the full GEMVC framework to be installed.

### What's Fixed

#### PHPStan Static Analysis

- **GEMVC Framework Stub Files** - Created comprehensive stub files for all GEMVC framework classes used by TraceKit
  - `Gemvc\Http\Request` - HTTP request class with required methods and properties
  - `Gemvc\Helper\ProjectHelper` - Project helper with static utility methods
  - `Gemvc\CLI\Command` - Base command class for CLI infrastructure
  - `Gemvc\CLI\Commands\CliBoxShow` - CLI display box class
  - `Gemvc\Http\JsonResponse` - JSON response class
  - `Gemvc\Http\AsyncApiCall` - Async API call class
  - `Gemvc\Http\Response` - HTTP response class
- **PHPStan Configuration** - Updated `phpstan.neon` to load stub files via `bootstrapFiles`
- **Result** - PHPStan now passes with no errors at level 9

#### PHPUnit Test Runtime

- **Test Bootstrap Enhancement** - Updated `tests/bootstrap.php` to load stub files before test execution
- **Runtime Errors Fixed** - All 18 previous test runtime errors resolved
- **Result** - All 87 tests now pass successfully (200 assertions)

#### Type Safety Improvements

- **TraceKitProvider Type Narrowing** - Fixed type narrowing issues in `flush()` method
  - Improved array access type safety for OTLP payload structure
  - Added proper type assertions for nested array access
  - Fixed string casting issues in debug logging
- **TraceKitInit Type Hints** - Enhanced constructor parameter type hints

### Technical Details

#### Stub Files Location

All stub files are located in the `stubs/` directory:
- `stubs/Gemvc/Http/Request.php`
- `stubs/Gemvc/Helper/ProjectHelper.php`
- `stubs/Gemvc/CLI/Command.php`
- `stubs/Gemvc/CLI/Commands/CliBoxShow.php`
- `stubs/Gemvc/Http/JsonResponse.php`
- `stubs/Gemvc/Http/AsyncApiCall.php`
- `stubs/Gemvc/Http/Response.php`

#### Stub Files Purpose

- **PHPStan Analysis** - Stub files are loaded via `phpstan.neon` bootstrap configuration for static analysis
- **Test Execution** - Stub files are loaded via `tests/bootstrap.php` for runtime test execution
- **Minimal Implementation** - Stubs provide minimal implementations with correct method signatures and type hints

### Migration Guide

**Upgrading from 2.0.0 or 2.1.0** - No migration required. This is a bug fix release with no breaking changes.

### Breaking Changes

None - This is a patch release with only bug fixes.

### Testing

- **PHPStan** - Passes with no errors (level 9)
- **PHPUnit** - All 87 tests passing (200 assertions)
- **Type Safety** - Improved type narrowing and assertions

### Changelog

See CHANGELOG.md for detailed changelog.

---

## Version 2.0.0 - Batch Trace Sending & OpenSwoole Compatibility

**Release Date:** 2026-01-14

### Overview

This release migrates TraceKit APM from `AsyncApiCall` to `AbstractApm`'s batching system, providing improved reliability and OpenSwoole production compatibility. The new batching system uses synchronous `ApiCall` to send traces in time-based batches, eliminating async operation issues that caused errors in OpenSwoole environments.

**Important:** TraceKit is now automatically included when you install `gemvc/library` - no separate installation required! If you're using GEMVC framework, TraceKit APM is already available and ready to configure.

### What's New

#### Included in gemvc/library

- **Auto-Inclusion** - TraceKit is now automatically installed as a dependency of `gemvc/library`
- **No Separate Installation** - No need to run `composer require gemvc/apm-tracekit` when using GEMVC framework
- **Always Available** - TraceKit is included by default in all GEMVC installations

#### Batch Trace Sending System

- **Time-Based Batching** - Traces are automatically collected and sent in batches every 5 seconds (configurable via `APM_SEND_INTERVAL`)
- **Synchronous ApiCall** - Replaced `AsyncApiCall::fireAndForget()` with synchronous `ApiCall` for reliable delivery
- **Batch Payload Building** - Multiple traces are efficiently combined into single batch requests, reducing HTTP overhead
- **Shutdown Safety** - `forceSendBatch()` ensures all pending traces are sent immediately on application shutdown
- **OpenSwoole Compatible** - No async operations that cause production issues in OpenSwoole environments

#### AbstractApm Integration

- **Full Batching Support** - Complete integration with `AbstractApm`'s batching system from `apm-contracts` 1.4.0
- **Required Methods Implemented**:
  - `buildBatchPayload()` - Combines multiple trace payloads into single OTLP batch format
  - `getBatchEndpoint()` - Returns TraceKit API endpoint for batch requests
  - `getBatchHeaders()` - Returns HTTP headers with API key authentication

### Breaking Changes

#### Trace Sending Behavior

- **Batching Delay** - Traces are now sent in batches every 5 seconds instead of immediately
  - Traces may appear in dashboards with up to 5 seconds delay (configurable)
  - Set `APM_SEND_INTERVAL=1` for near-immediate sending (minimum: 1 second)
- **Synchronous Sending** - Uses synchronous `ApiCall` instead of async `AsyncApiCall`
  - No more fire-and-forget behavior
  - Traces are sent as part of the batching cycle

#### Dependency Requirements

- **apm-contracts Update** - Requires `gemvc/apm-contracts` ^1.4.0
  - Previous requirement: `^1.0`
  - New version includes improved batch send interval (default: 5 seconds)

### Migration Guide

**For Users:**

1. **No Installation Required** - TraceKit is automatically included in `gemvc/library`
   - If you're using GEMVC framework, TraceKit is already available
   - Simply run the setup wizard: `php vendor/bin/tracekit init`
   - No need to run `composer require gemvc/apm-tracekit`

2. **No Code Changes Required** - The public API remains the same
   - All existing code using `$apm->startSpan()`, `$apm->endSpan()`, etc. continues to work
   - No breaking changes to method signatures

3. **Trace Visibility:**
   - Traces may appear with up to 5 seconds delay (configurable)
   - All traces are guaranteed to be sent on shutdown (no data loss)
   - For faster visibility, set `APM_SEND_INTERVAL=1` in your `.env` file

4. **Configuration:**
   ```env
   # Optional: Adjust batch send interval (default: 5 seconds)
   APM_SEND_INTERVAL=5
   
   # Existing configuration remains the same
   APM_NAME="TraceKit"
   APM_ENABLED="true"
   TRACEKIT_API_KEY="your-api-key"
   TRACEKIT_SERVICE_NAME="your-service-name"
   ```

**For Developers:**

- Update `composer.json` to require `gemvc/apm-contracts` ^1.4.0 (if installing standalone)
- Run `composer update` to get the latest dependencies (if installing standalone)
- No breaking API changes - all public methods remain the same
- Internal implementation changed from `AsyncApiCall` to batching system

### Fixed Issues

- **OpenSwoole Production Errors** - Fixed errors and problems caused by `AsyncApiCall` in OpenSwoole production environments
- **Trace Delivery Reliability** - Improved trace delivery with synchronous `ApiCall` and batching system
- **Shutdown Data Loss** - Ensured all traces are sent on shutdown using `forceSendBatch()`

### Performance Improvements

- **Batch Efficiency** - Multiple traces combined into single requests, reducing HTTP overhead
- **Configurable Frequency** - Adjust `APM_SEND_INTERVAL` to balance trace visibility vs. batch efficiency
- **OpenSwoole Compatible** - No async operations that cause production issues

### Technical Details

#### Batching System

- Traces are queued using `addTraceToBatch()` when `flush()` is called
- `sendBatchIfNeeded()` checks if batch interval has elapsed (default: 5 seconds)
- Batch payload combines all queued traces into single OTLP JSON format
- Synchronous `ApiCall` sends batch to TraceKit endpoint
- On shutdown, `forceSendBatch()` ensures immediate sending of all pending traces

#### Removed Components

- `AsyncApiCall::fireAndForget()` pattern (completely removed)
- `sendTraces()` private method (replaced by batching system)
- `extractServiceNameFromPayload()` helper method (no longer needed)
- `validatePayloadStructure()` helper method (no longer needed)

### Dependencies

- **PHP:** >= 8.2 (unchanged)
- **gemvc/apm-contracts:** ^1.4.0 (updated from ^1.0)
- **gemvc/library:** ^5.2 (unchanged)

### Testing

- All 87 tests passing (200 assertions)
- PHPStan passes with no errors
- Integration tests verify batching behavior
- OpenSwoole compatibility verified

### Changelog

See CHANGELOG.md for detailed changelog.

---

## Version 1.1.0 - CLI Setup Wizard

**Release Date:** 2026-01-03

### Overview

This release adds an interactive CLI setup wizard (`tracekit init`) that simplifies TraceKit APM configuration. The wizard guides users through registration, email verification, and automatic `.env` file configuration.

### What's New

#### CLI Command Features

- **Interactive Setup Wizard** - `tracekit init` command for easy configuration
  - Welcome banner with TraceKit features overview
  - Existing configuration detection and management options
  - Two setup methods:
    - **Easy Register** - Automated registration with email verification
      - Prompts for email and organization name
      - Sends registration request to TraceKit
      - Waits for user to enter verification code (CLI does not exit)
      - Retry logic for verification (max 3 attempts)
      - Automatically receives and saves API key
    - **I have API key** - Manual API key entry
      - Prompts for existing API key
      - Validates API key format
  - Service name setup with uniqueness validation
  - Automatic `.env` file configuration using `ProjectHelper`
  - Connection testing to verify setup
  - Success message with next steps

### Usage

#### CLI Setup Wizard

The easiest way to set up TraceKit is using the interactive CLI command:

```bash
php vendor/bin/tracekit init
```

**Setup Flow:**

1. **Welcome Banner** - Displays TraceKit features and welcome message
2. **Configuration Check** - Detects existing configuration and offers options:
   - Reconfigure (start fresh)
   - Test existing connection
   - Exit
3. **Setup Method Selection** - Choose between:
   - **Option 1: Easy Register** (Recommended)
     - Enter your email address
     - Enter organization name (optional)
     - Registration request sent to TraceKit
     - Verification code sent to your email
     - **CLI waits for you to enter the verification code** (does not exit)
     - Enter verification code when prompted
     - Automatic API key retrieval and saving
   - **Option 2: I have API key**
     - Enter your existing TraceKit API key
     - API key validation
4. **Service Name Setup** - Enter unique service name for your application
5. **Configuration Saving** - Automatically updates `.env` file with:
   - `TRACEKIT_API_KEY`
   - `TRACEKIT_SERVICE_NAME`
   - `APM_NAME="TraceKit"`
   - `APM_ENABLED="true"`
6. **Connection Test** - Verifies API key and connection to TraceKit
7. **Success Message** - Displays confirmation and next steps

**Note:** The CLI command uses GEMVC's standard CLI infrastructure and integrates seamlessly with the framework.

### Migration Guide

**Upgrading from 1.0.0** - No migration required. This is a feature addition.

To use the new CLI wizard:

1. Update to version 1.1.0: `composer update gemvc/apm-tracekit`
2. Run the setup wizard: `php vendor/bin/tracekit init`
3. Follow the interactive prompts

Existing installations continue to work without changes.

### Breaking Changes

None - This is a backwards-compatible feature addition.

### Changelog

See CHANGELOG.md for detailed changelog.

---

## Version 1.0.0 - Initial Release

**Release Date:** 2026-01-01

### Overview

This is the initial release of the TraceKit APM provider for GEMVC framework. This package implements the [GEMVC APM Contracts](https://github.com/gemvc/apm-contracts) interface, providing a complete TraceKit integration for distributed tracing and performance monitoring in GEMVC applications.

### What's New

#### Core Features

- **TraceKitProvider** - Full implementation of `ApmInterface` extending `AbstractApm` (used by `ApmFactory`)
- **TraceKitModel** - Alternative APM provider implementation with additional methods (`addEvent()`, `getSampleRate()`, etc.)
- **TraceKitToolkit** - Client-side integration and management class extending `AbstractApmToolkit`
- **OpenTelemetry OTLP JSON Format** - Standard OpenTelemetry format for trace data
- **Non-Blocking Trace Sending** - Fire-and-forget trace delivery using GEMVC's `AsyncApiCall`
- **Automatic Integration** - Seamless integration with GEMVC framework via `ApmFactory`

#### TraceKit Provider Features

- **Root Trace Creation** - Automatically creates root spans for HTTP requests via `startTrace()`
- **Child Span Support** - Supports nested spans for database queries, controller operations, etc. via `startSpan()`
- **Exception Recording** - Automatic exception tracking with stack traces via `recordException()`
- **Span Context Management** - Stack-based context propagation for span hierarchy
- **Sampling Support** - Configurable sample rate (0.0 to 1.0) with force sampling for errors
- **Trace Flags** - Configurable flags for response, DB query, and request body tracing
- **Static Instance Management** - `getCurrentInstance()` and `clearCurrentInstance()` for backward compatibility

#### TraceKit Toolkit Features

- **Account Management** - Service registration and email verification
- **Health Monitoring** - Synchronous and asynchronous heartbeat support
- **Service Metrics** - Retrieve service performance metrics
- **Alerts Management** - Get alerts summary and active alerts
- **Webhook Management** - Create and manage webhooks for events
- **Billing Integration** - Subscription and billing information access

### Architecture

This package follows the GEMVC APM Contracts architecture:

```
┌─────────────────────────────────────────────────────────┐
│              gemvc/apm-contracts                        │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐   │
│  │ ApmInterface │  │ AbstractApm  │  │ ApmFactory   │   │
│  │  (Contract)  │  │  (Base)      │  │ (Universal)  │   │
│  └──────────────┘  └──────────────┘  └──────────────┘   │
│  ┌──────────────────┐  ┌──────────────────┐             │
│  │ApmToolkitInterface│ │AbstractApmToolkit│             │
│  │   (Contract)     │  │    (Base)        │             │
│  └──────────────────┘  └──────────────────┘             │
└────────────────────┬────────────────────────────────────┘
                     │ implements/extends
                     ▼
┌─────────────────────────────────────────────────────────┐
│            gemvc/apm-tracekit                           │
│  ┌──────────────────┐  ┌──────────────────┐             │
│  │ TraceKitProvider │  │ TraceKitToolkit  │             │
│  │  (extends        │  │  (extends         │            │
│  │   AbstractApm)   │  │   AbstractApmToolkit)          │
│  └──────────────────┘  └──────────────────┘             │
└─────────────────────────────────────────────────────────┘
```

### Configuration

#### Environment Variables

```env
# Core APM Configuration
APM_NAME="TraceKit"
APM_ENABLED="true"
APM_SAMPLE_RATE="1.0"
APM_TRACE_RESPONSE="false"
APM_TRACE_DB_QUERY="false"
APM_TRACE_REQUEST_BODY="false"

# TraceKit-Specific Configuration
TRACEKIT_API_KEY="your-api-key"
TRACEKIT_SERVICE_NAME="your-service-name"
TRACEKIT_ENDPOINT="https://app.tracekit.dev/v1/traces"  # Optional
```

#### Configuration Priority

1. Config array (passed to constructor) - Highest priority
2. Provider-specific env vars (`TRACEKIT_*`) - Medium priority
3. Unified APM env vars (`APM_*`) - Lower priority
4. Default values - Lowest priority

### Usage

#### Automatic Integration

Once installed and configured, TraceKit automatically integrates with GEMVC:

1. Framework creates `TraceKitProvider` via `ApmFactory::create()`
2. Root span is automatically created for each HTTP request
3. Child spans are created for database queries, controller operations, etc.
4. Traces are automatically sent after HTTP response (non-blocking)

#### Manual Span Creation

```php
$apm = $request->apm;

if ($apm !== null && $apm->isEnabled()) {
    $span = $apm->startSpan('custom-operation', [
        'custom.attribute' => 'value'
    ]);
    
    try {
        $result = doSomething();
        $apm->endSpan($span, ['result' => 'success'], ApmInterface::STATUS_OK);
    } catch (\Throwable $e) {
        $apm->recordException($span, $e);
        $apm->endSpan($span, ['result' => 'error'], ApmInterface::STATUS_ERROR);
        throw $e;
    }
}
```

#### Toolkit Usage

```php
use Gemvc\Core\Apm\Providers\TraceKit\TraceKitToolkit;

$toolkit = new TraceKitToolkit();

// Register service
$response = $toolkit->registerService('admin@example.com', 'My Organization');
if ($response->response_code === 200) {
    $sessionId = $response->data['session_id'];
}

// Verify code
$response = $toolkit->verifyCode($sessionId, '123456');
if ($response->response_code === 200) {
    $apiKey = $response->data['api_key'];
    // Save to .env: TRACEKIT_API_KEY=$apiKey
}

// Send heartbeat
$toolkit->sendHeartbeatAsync('healthy', [
    'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
]);
```

### Technical Details

#### OpenTelemetry OTLP JSON Format

- Uses standard OpenTelemetry OTLP JSON format
- Trace IDs: 32-character hex strings (128 bits)
- Span IDs: 16-character hex strings (64 bits)
- Timestamps: Nanoseconds since Unix epoch
- Service name included in resource attributes

#### Non-Blocking Trace Sending

- Uses `AsyncApiCall::fireAndForget()` for non-blocking delivery
- Ensures HTTP response is sent before trace delivery
- Traces are sent in background after response completion
- Failures in trace sending don't affect application performance

#### Span Management

- Stack-based span context propagation
- Automatic sampling based on configured sample rate
- Errors are always traced (forced sampling)
- Supports OpenTelemetry span kinds (SERVER, CLIENT, INTERNAL, etc.)

### Dependencies

- **PHP:** >= 8.2
- **gemvc/apm-contracts:** ^1.0
- **gemvc/library:** ^5.2

### Testing

- **Unit Tests:** Comprehensive unit test coverage
- **Integration Tests:** Integration tests with mock requests
- **Protocol Tests:** Protocol compliance tests
- **Test Coverage:** See TESTING_PROTOCOL.md for details

### Migration Guide

**First-time installation** - No migration required. This is the initial release.

**Note:** TraceKit is now automatically included when you install `gemvc/library` - no separate installation required!

To get started:

1. **TraceKit is already available** - If you're using GEMVC framework, TraceKit is automatically included
2. Run the setup wizard: `php vendor/bin/tracekit init`
   - Or manually configure environment variables in `.env`
3. Set `APM_NAME="TraceKit"` in your `.env` file (done automatically by CLI)
4. The framework will automatically use TraceKit for APM

**Standalone Installation:** If you need to install this package outside of GEMVC framework, you can still use:
```bash
composer require gemvc/apm-tracekit
```

### Known Issues

None at this time.

### Breaking Changes

None - This is the initial release.

### Changelog

See CHANGELOG.md for detailed changelog.

### Credits

Part of the [GEMVC PHP Framework built for Microservices](https://gemvc.de) ecosystem.

---

## Support

- **Issues:** https://github.com/gemvc/apm-tracekit/issues
- **Source:** https://github.com/gemvc/apm-tracekit
- **Documentation:** See README.md and vendor/gemvc/apm-contracts/README.md

