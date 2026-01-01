![gemvc_header_for_github](https://github.com/user-attachments/assets/69dcc3f3-b422-47b6-a67d-a9df94628158)
# GEMVC APM TraceKit Provider

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.2-blue.svg)](https://www.php.net/)

TraceKit APM provider implementation for GEMVC framework. This package implements the [GEMVC APM Contracts](https://github.com/gemvc/apm-contracts) interface, providing distributed tracing and performance monitoring for GEMVC applications.

## ⬇️ Installation

```bash
composer require gemvc/apm-tracekit
```

This package automatically installs `gemvc/apm-contracts` as a dependency, which provides the base interfaces and abstract classes.

## 🎯 Architecture

This package is built on top of the [GEMVC APM Contracts](https://github.com/gemvc/apm-contracts) package:

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
│  │ TraceKitProvider │  │ TraceKitToolkit   │            │
│  │  (extends        │  │  (extends         │            │
│  │   AbstractApm)   │  │   AbstractApmToolkit)          │
│  │  [Used by Factory]│  └──────────────────┘            │
│  └──────────────────┘                                   │
│  ┌──────────────────┐                                   │
│  │ TraceKitModel    │                                   │
│  │  (extends        │                                   │
│  │   AbstractApm)   │                                   │
│  │  [Alternative]   │                                   │
│  └──────────────────┘                                   │
└─────────────────────────────────────────────────────────┘
```

**Key Components:**
- **`TraceKitProvider`** - Main APM provider class extending `AbstractApm` (used by `ApmFactory`)
- **`TraceKitModel`** - Alternative APM provider implementation extending `AbstractApm` (with additional methods like `addEvent()`)
- **`TraceKitToolkit`** - Client-side integration and management class extending `AbstractApmToolkit`
- **OpenTelemetry OTLP JSON Format** - Sends traces in standard OpenTelemetry format
- **Non-blocking Trace Sending** - Uses GEMVC's `AsyncApiCall` for fire-and-forget trace delivery

## 🔐 Configuration

### Environment Variables

Set in your `.env` file:

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
TRACEKIT_SAMPLE_RATE="1.0"
TRACEKIT_TRACE_RESPONSE="false"
TRACEKIT_TRACE_DB_QUERY="false"
TRACEKIT_TRACE_REQUEST_BODY="false"
```

**Note:** The TraceKit endpoint URL (`https://app.tracekit.dev/v1/traces`) is pre-configured as a library constant and does not need to be set in your `.env` file. If you need to override it (e.g., for custom deployments), you can set `TRACEKIT_ENDPOINT` in your `.env` file.

### Configuration Priority

Configuration values are loaded in the following priority order:
1. **Config array** (passed to constructor/init) - Highest priority
2. **Provider-specific env vars** (`TRACEKIT_*`) - Medium priority
3. **Unified APM env vars** (`APM_*`) - Lower priority
4. **Default values** - Lowest priority

### Unified API Key Support

You can use either `TRACEKIT_API_KEY` or the unified `APM_API_KEY` environment variable:

```env
# Both work the same way
TRACEKIT_API_KEY="your-api-key"
# or
APM_API_KEY="your-api-key"
```

## 💡 Usage

### Automatic Integration

Once installed and configured, TraceKit automatically integrates with GEMVC:

1. **Framework Initialization** - The framework creates a `TraceKitProvider` instance via `ApmFactory::create()`
2. **Root Trace Creation** - A root span is automatically created for each HTTP request
3. **Span Management** - Child spans are created for database queries, controller operations, etc.
4. **Trace Flushing** - Traces are automatically sent after the HTTP response (non-blocking)

### Manual Span Creation

You can create custom spans in your code:

```php
// Get APM instance from Request
$apm = $request->apm;

if ($apm !== null && $apm->isEnabled()) {
    // Start a custom span
    $span = $apm->startSpan('custom-operation', [
        'custom.attribute' => 'value'
    ]);
    
    try {
        // Your code here
        $result = doSomething();
        
        // End span with success
        $apm->endSpan($span, ['result' => 'success'], \Gemvc\Core\Apm\ApmInterface::STATUS_OK);
    } catch (\Throwable $e) {
        // Record exception
        $apm->recordException($span, $e);
        $apm->endSpan($span, ['result' => 'error'], \Gemvc\Core\Apm\ApmInterface::STATUS_ERROR);
        throw $e;
    }
}
```

### Using TraceKit Toolkit

The `TraceKitToolkit` class provides client-side integration features:

```php
use Gemvc\Core\Apm\Providers\TraceKit\TraceKitToolkit;

// Initialize toolkit
$toolkit = new TraceKitToolkit();

// Register new service (first-time setup)
$response = $toolkit->registerService('admin@example.com', 'My Organization');
if ($response->response_code === 200) {
    $sessionId = $response->data['session_id'];
    // User receives verification code via email
}

// Verify code and activate service
$response = $toolkit->verifyCode($sessionId, '123456');
if ($response->response_code === 200) {
    $apiKey = $response->data['api_key'];
    // Save to .env: TRACEKIT_API_KEY=$apiKey
}

// Send periodic health heartbeat (non-blocking)
$toolkit->sendHeartbeatAsync('healthy', [
    'memory_usage_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
    'cpu_load' => sys_getloadavg()[0] ?? 0,
]);

// Get service metrics
$metrics = $toolkit->getMetrics('1h');

// Create webhook for alerts
$toolkit->createWebhook(
    'production-alerts',
    'https://example.com/webhooks/alerts',
    ['alert.created', 'alert.resolved'],
    true
);
```

## ✨ Features

### OpenTelemetry OTLP JSON Format

- **Standard Format** - Uses OpenTelemetry OTLP JSON format for compatibility
- **Service Discovery** - Automatically includes service name in resource attributes
- **Span Hierarchy** - Supports parent-child span relationships
- **Event Recording** - Can record exception events and custom events on spans

### Non-Blocking Trace Sending

- **Fire-and-Forget** - Uses `AsyncApiCall::fireAndForget()` for non-blocking delivery
- **Response First** - Ensures HTTP response is sent before trace delivery
- **Background Processing** - Traces are sent in the background after response completion
- **Graceful Degradation** - Failures in trace sending don't affect application performance

### Span Management

- **Stack-Based Context** - Simple stack-based span context propagation
- **Automatic Sampling** - Respects sample rate configuration
- **Error Handling** - Errors are always traced (forced sampling)
- **Span Kinds** - Supports OpenTelemetry span kinds (SERVER, CLIENT, INTERNAL, etc.)

## 📖 API Reference

### TraceKitProvider

Main provider class implementing `ApmInterface` (used by `ApmFactory`):

**Instance Methods:**
- `init(array $config = []): bool` - Initialize provider with configuration (for setup/configuration via CLI/GUI)
- `isEnabled(): bool` - Check if tracing is enabled
- `startTrace(string $operationName, array $attributes = [], bool $forceSample = false): array` - Start a root trace (span). `$forceSample = true` forces tracing regardless of sample rate (used for errors)
- `startSpan(string $operationName, array $attributes = [], int $kind = self::SPAN_KIND_INTERNAL): array` - Start a child span. `$kind` can be `SPAN_KIND_SERVER`, `SPAN_KIND_CLIENT`, `SPAN_KIND_INTERNAL`, etc.
- `endSpan(array $spanData, array $finalAttributes = [], ?string $status = self::STATUS_OK): void` - End a span. `$status` can be `STATUS_OK` or `STATUS_ERROR`
- `recordException(array $spanData, \Throwable $exception): array` - Record an exception on a span. Auto-creates trace if no root span exists
- `flush(): void` - Send traces to TraceKit service (non-blocking)
- `getTraceId(): ?string` - Get current trace ID (inherited from `AbstractApm`)

**Static Methods:**
- `getCurrentInstance(): ?TraceKitProvider` - Get the current active instance
- `clearCurrentInstance(): void` - Clear the current active instance

**Note:** `TraceKitModel` is an alternative implementation with additional methods like `addEvent()`, `getSampleRate()`, `getSampleRatePercent()`, and `getActiveSpan()`.

### TraceKitToolkit

Client-side integration class implementing `ApmToolkitInterface`:

- `registerService(string $email, ?string $organizationName, string $source, array $sourceMetadata): JsonResponse` - Register new service
- `verifyCode(string $sessionId, string $code): JsonResponse` - Verify email and get API key
- `getStatus(): JsonResponse` - Check integration status
- `sendHeartbeatAsync(string $status, array $metadata): void` - Send asynchronous heartbeat
- `getMetrics(string $window): JsonResponse` - Get service metrics
- `getAlertsSummary(): JsonResponse` - Get alerts overview
- `createWebhook(string $name, string $url, array $events, bool $enabled): JsonResponse` - Create webhook

For complete API documentation, see the [GEMVC APM Contracts README](vendor/gemvc/apm-contracts/README.md).

## 🔄 Related Packages

- [gemvc/apm-contracts](https://github.com/gemvc/apm-contracts) - Base APM contracts and interfaces
- [gemvc/library](https://github.com/gemvc/library) - GEMVC core framework

## 🌐 Environment Variables Reference

### Core APM Variables

- `APM_NAME` - APM provider name (must be "TraceKit" for this provider)
- `APM_ENABLED` - Enable/disable APM (`"true"`, `"1"`, `"false"`, `"0"`, or boolean)
- `APM_SAMPLE_RATE` - Sample rate for traces (0.0 to 1.0, where 1.0 = 100%)
- `APM_TRACE_RESPONSE` - Enable/disable response tracing
- `APM_TRACE_DB_QUERY` - Enable/disable database query tracing
- `APM_TRACE_REQUEST_BODY` - Enable/disable request body tracing
- `APM_API_KEY` - Unified API key (works for all providers)

### TraceKit-Specific Variables

- `TRACEKIT_API_KEY` - TraceKit API key (or use `APM_API_KEY`)
- `TRACEKIT_SERVICE_NAME` - Service name for traces
- `TRACEKIT_ENDPOINT` - Override default endpoint URL (optional)
- `TRACEKIT_SAMPLE_RATE` - Override sample rate (optional)
- `TRACEKIT_TRACE_RESPONSE` - Override response tracing flag (optional)
- `TRACEKIT_TRACE_DB_QUERY` - Override DB query tracing flag (optional)
- `TRACEKIT_TRACE_REQUEST_BODY` - Override request body tracing flag (optional)

## 🧪 Development

### Running Tests

```bash
composer test
```

### Code Quality

```bash
composer phpstan
```

## ⚖️ License

MIT License - see [LICENSE](LICENSE) file for details.

## 👥 Contributing

Contributions are welcome! Please see the [GEMVC APM Contracts README](vendor/gemvc/apm-contracts/README.md) for information about the APM provider architecture.

## Credits

Part of the [GEMVC PHP Framework built for Microservices](https://gemvc.de) ecosystem.

