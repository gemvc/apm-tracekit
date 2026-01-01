# Testing Protocol for TraceKit APM Provider

## Overview

This document outlines the testing protocol for `gemvc/apm-tracekit` package. The test suite ensures that the TraceKit provider correctly implements the GEMVC APM Contracts interface and provides reliable distributed tracing functionality.

**Current Test Coverage:**
- **Total Tests:** See test execution results
- **Test Categories:** Unit, Integration, Protocol
- **Test Helpers:** MockRequest, MockApiCall

## Test Structure

### Test Organization

```
tests/
├── bootstrap.php              # Test bootstrap file
├── Helpers/
│   ├── MockApiCall.php        # Mock AsyncApiCall for testing
│   └── MockRequest.php        # Mock Request class for testing
├── Unit/
│   ├── TraceKitProviderTest.php
│   ├── TraceKitProviderBehaviorTest.php
│   ├── TraceKitModelTest.php
│   └── TraceKitToolkitTest.php
├── Integration/
│   ├── TraceKitProviderIntegrationTest.php
│   └── TraceKitModelIntegrationTest.php
└── Protocol/
    └── TraceKitProtocolTest.php
```

### Test Categories

#### 1. Unit Tests

**Purpose:** Test individual components in isolation

**TraceKitProviderTest**
- Constructor behavior
- Configuration loading (environment variables, config array)
- Enabled/disabled state
- Sample rate handling
- Trace flags (response, DB query, request body)
- Root trace creation (`startTrace()`)
- Child span creation (`startSpan()`)
- Span ending (`endSpan()`)
- Exception recording (`recordException()`)
- Trace ID generation
- Flush behavior (`flush()`)
- Static instance management (`getCurrentInstance()`, `clearCurrentInstance()`)

**TraceKitProviderBehaviorTest**
- Provider behavior with various configurations
- Edge cases and error scenarios
- Sampling logic
- Span context management

**TraceKitModelTest**
- Model-specific functionality (additional methods)
- `addEvent()` method testing
- `getSampleRate()` and `getSampleRatePercent()` methods
- `getActiveSpan()` method
- Backward compatibility

**TraceKitToolkitTest**
- Toolkit initialization
- API key management
- Service name management
- Base URL configuration
- Endpoint methods (all abstract endpoint methods)
- Account management methods (`registerService()`, `verifyCode()`, `getStatus()`)
- Health monitoring methods (`sendHeartbeat()`, `sendHeartbeatAsync()`, `listHealthChecks()`)
- Metrics methods (`getMetrics()`)
- Alerts methods (`getAlertsSummary()`, `getActiveAlerts()`)
- Webhook methods (`createWebhook()`, `listWebhooks()`)
- Billing methods (`getSubscription()`, `listPlans()`, `createCheckoutSession()`)
- Overridden methods (`verifyCode()`, `sendHeartbeatAsync()`, `registerService()`)

#### 2. Integration Tests

**Purpose:** Test components working together

**TraceKitProviderIntegrationTest**
- Full lifecycle with mock request
- Root trace creation
- Child span creation
- Span hierarchy
- Exception handling
- Trace flushing
- Request property assignment

**TraceKitModelIntegrationTest**
- Model integration with framework
- Request integration
- Response integration

#### 3. Protocol Tests

**Purpose:** Test OpenTelemetry OTLP format compliance

**TraceKitProtocolTest**
- OTLP JSON format validation
- Trace ID format (32 hex characters)
- Span ID format (16 hex characters)
- Timestamp format (nanoseconds)
- Service name in resource attributes
- Span hierarchy structure
- Event structure
- Status code format
- Attribute normalization

## Test Requirements

### Dependencies

- **PHPUnit:** ^10.5
- **PHP:** >= 8.2
- **gemvc/apm-contracts:** ^1.0
- **gemvc/library:** ^5.2 (for Request/Response classes)

### Environment Setup

Tests use environment variables for configuration. Each test should:
1. Set required environment variables in `setUp()`
2. Clean up environment variables in `tearDown()`
3. Use isolated configuration to avoid test interference

### Mock Objects

#### MockRequest

The `MockRequest` class provides a mock implementation of `Gemvc\Http\Request`:

```php
use Gemvc\Core\Apm\Providers\TraceKit\Tests\Helpers\MockRequest;

$request = new MockRequest('GET', '/api/test');
$request->setHeader('User-Agent', 'Test Agent');
$request->post = ['name' => 'test'];  // For POST requests
```

**Required Methods:**
- `getMethod(): string`
- `getUri(): string`
- `getHeader(string $name): ?string`
- `getServiceName(): string`
- `getMethodName(): string`

**Properties:**
- `$request->apm` - APM instance assignment
- `$request->post` - POST body data
- `$request->put` - PUT body data
- `$request->patch` - PATCH body data

#### MockApiCall

The `MockApiCall` class provides a mock implementation of `Gemvc\Http\AsyncApiCall`:

```php
use Gemvc\Core\Apm\Providers\TraceKit\Tests\Helpers\MockApiCall;

// Mock is used internally by TraceKitProvider for trace sending
```

## Test Execution

### Running All Tests

```bash
composer test
# or
vendor/bin/phpunit
```

### Running Specific Test Suites

```bash
# Unit tests only
vendor/bin/phpunit tests/Unit

# Integration tests only
vendor/bin/phpunit tests/Integration

# Protocol tests only
vendor/bin/phpunit tests/Protocol
```

### Running Specific Test Classes

```bash
vendor/bin/phpunit tests/Unit/TraceKitProviderTest.php
```

### Running Specific Test Methods

```bash
vendor/bin/phpunit --filter testConstructorLoadsConfiguration
```

### Code Coverage

```bash
vendor/bin/phpunit --coverage-text
vendor/bin/phpunit --coverage-html coverage/
```

## Test Scenarios

### Configuration Tests

#### Environment Variable Loading
- ✓ Load API key from `TRACEKIT_API_KEY`
- ✓ Load API key from `APM_API_KEY` (unified)
- ✓ Load service name from `TRACEKIT_SERVICE_NAME`
- ✓ Load endpoint from `TRACEKIT_ENDPOINT`
- ✓ Load sample rate from `TRACEKIT_SAMPLE_RATE` or `APM_SAMPLE_RATE`
- ✓ Load trace flags from environment variables
- ✓ Configuration priority: config array > provider env > unified env > defaults

#### Config Array Support
- ✓ Config array takes precedence over environment variables
- ✓ Boolean parsing (`'true'`, `'1'`, `'false'`, `'0'`, boolean)
- ✓ Sample rate clamping (0.0 to 1.0)
- ✓ Empty API key disables provider

### Span Management Tests

#### Root Span Creation
- ✓ Root span created automatically for HTTP requests
- ✓ Root span includes HTTP attributes (method, URL, route, user agent)
- ✓ Request body included if `trace_request_body` is enabled
- ✓ Root span uses `SPAN_KIND_SERVER`
- ✓ Trace ID generated automatically

#### Child Span Creation
- ✓ Child span inherits trace ID from root span
- ✓ Child span has parent span ID
- ✓ Child span uses correct span kind
- ✓ Child span attributes are normalized
- ✓ Span stack maintains context

#### Span Ending
- ✓ Span end time recorded
- ✓ Span duration calculated
- ✓ Final attributes merged
- ✓ Status set correctly (OK/ERROR)
- ✓ Span removed from stack

#### Exception Recording
- ✓ Exception recorded on span
- ✓ Exception event created with stack trace
- ✓ Span status set to ERROR
- ✓ Auto-creates trace if no root span exists
- ✓ Errors are always traced (forced sampling)

### Trace Sending Tests

#### Trace Payload Building
- ✓ OTLP JSON format structure
- ✓ Service name in resource attributes
- ✓ Spans in correct structure
- ✓ Only completed spans included
- ✓ Attributes in OTLP format
- ✓ Events in OTLP format

#### Non-Blocking Sending
- ✓ Uses `AsyncApiCall::fireAndForget()`
- ✓ Traces sent after HTTP response
- ✓ Failures don't affect application
- ✓ Graceful error handling

### Toolkit Tests

#### Account Management
- ✓ Service registration
- ✓ Email verification
- ✓ Status checking

#### Health Monitoring
- ✓ Synchronous heartbeat
- ✓ Asynchronous heartbeat
- ✓ Health checks listing

#### Metrics & Alerts
- ✓ Service metrics retrieval
- ✓ Alerts summary
- ✓ Active alerts

#### Webhooks
- ✓ Webhook creation
- ✓ Webhook listing

#### Billing
- ✓ Subscription information
- ✓ Plans listing
- ✓ Checkout session creation

## Test Data

### Sample Trace Data

```php
$traceId = 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6';  // 32 hex chars
$spanId = 'a1b2c3d4e5f6g7h8';  // 16 hex chars
$startTime = 1704067200000000000;  // Nanoseconds
```

### Sample OTLP Payload

```json
{
  "resourceSpans": [
    {
      "resource": {
        "attributes": [
          {
            "key": "service.name",
            "value": {
              "stringValue": "test-service"
            }
          }
        ]
      },
      "scopeSpans": [
        {
          "spans": [
            {
              "traceId": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6",
              "spanId": "a1b2c3d4e5f6g7h8",
              "name": "http-request",
              "kind": 2,
              "startTimeUnixNano": "1704067200000000000",
              "endTimeUnixNano": "1704067201000000000",
              "attributes": [],
              "status": {
                "code": "STATUS_CODE_OK",
                "message": ""
              },
              "events": []
            }
          ]
        }
      ]
    }
  ]
}
```

## Continuous Integration

### CI Requirements

- All tests must pass
- Code coverage should be maintained
- PHPStan level 9 must pass
- No syntax errors
- No deprecated code usage

### Pre-Commit Checks

Before committing:
1. Run all tests: `composer test`
2. Run PHPStan: `composer phpstan`
3. Check code style (if configured)

## Test Maintenance

### Adding New Tests

When adding new functionality:
1. Add unit tests for the new feature
2. Add integration tests if it involves multiple components
3. Add protocol tests if it affects OTLP format
4. Update this document if test structure changes

### Test Naming Convention

- Test methods: `testFeatureName()` or `testFeatureNameWithCondition()`
- Test classes: `*Test.php`
- Test helpers: `*Helper.php` or `Mock*.php`

### Test Isolation

- Each test should be independent
- Clean up environment variables in `tearDown()`
- Clear static instances if used
- Use unique test data to avoid conflicts

## Troubleshooting

### Common Issues

**Issue:** Tests fail with "Class not found"
- **Solution:** Run `composer install` to ensure dependencies are installed

**Issue:** Environment variable conflicts
- **Solution:** Ensure `tearDown()` cleans up all environment variables

**Issue:** Mock request not working
- **Solution:** Check that `MockRequest` implements all required methods

**Issue:** Trace sending tests fail
- **Solution:** Ensure `MockApiCall` is properly configured

## References

- [GEMVC APM Contracts Testing Protocol](../vendor/gemvc/apm-contracts/TESTING_PROTOCOL.md)
- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [OpenTelemetry OTLP Specification](https://opentelemetry.io/docs/specs/otlp/)

