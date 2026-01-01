# Release Notes

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

To get started:

1. Install the package: `composer require gemvc/apm-tracekit`
2. Configure environment variables in `.env`
3. Set `APM_NAME="TraceKit"` in your `.env` file
4. The framework will automatically use TraceKit for APM

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

