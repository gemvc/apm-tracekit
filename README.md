# GEMVC APM TraceKit Provider

TraceKit APM provider implementation for GEMVC framework.

## Installation

```bash
composer require gemvc/apm-tracekit
```

## Configuration

Set in your `.env` file:

```env
APM_NAME="TraceKit"
APM_ENABLED="true"
TRACEKIT_API_KEY="your-api-key"
TRACEKIT_SERVICE_NAME="your-service-name"
TRACEKIT_SAMPLE_RATE="1.0"
TRACEKIT_TRACE_RESPONSE="false"
TRACEKIT_TRACE_DB_QUERY="false"
TRACEKIT_TRACE_REQUEST_BODY="false"
```

**Note:** The TraceKit endpoint URL (`https://app.tracekit.dev/v1/traces`) is pre-configured as a library constant and does not need to be set in your `.env` file. If you need to override it (e.g., for custom deployments), you can set `TRACEKIT_ENDPOINT` in your `.env` file.

## License

MIT

