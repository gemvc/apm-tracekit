<?php
namespace Gemvc\Core\Apm\Providers\TraceKit;

use Gemvc\Core\Apm\AbstractApm;
use Gemvc\Helper\ProjectHelper;

/**
 * TraceKit Model - Custom Lightweight APM Implementation
 * 
 * This is a custom lightweight implementation of TraceKit APM using GEMVC's native capabilities.
 * It provides distributed tracing and performance monitoring without heavy dependencies.
 * 
 * Features:
 * - Lightweight (no OpenTelemetry, no 23 packages)
 * - Batch trace sending (uses AbstractApm's batching system with synchronous ApiCall)
 * - Simple span tracking with stack-based context
 * - Custom JSON trace payload
 * - Graceful error handling
 * 
 * @package Gemvc\Core\Apm\Providers\TraceKit
 */
class TraceKitModel extends AbstractApm
{
    /**
     * Static registry to store the current active TraceKitModel instance
     * This allows backward compatibility with getCurrentInstance()
     * 
     * @var TraceKitModel|null
     */
    private static ?TraceKitModel $currentInstance = null;
    
    // TraceKit-specific configuration
    private string $apiKey;
    private string $serviceName;
    private string $endpoint;
    
    // Active span tracking (simple stack for context propagation)
    /** @var array<int, array<string, mixed>> */
    private array $spanStack = [];
    
    // Current trace data
    /** @var array<int, array<string, mixed>> */
    private array $spans = [];
    
    // Constants - Span kinds (OpenTelemetry OTLP uses integers)
    public const SPAN_KIND_UNSPECIFIED = 0;
    public const SPAN_KIND_INTERNAL = 1;
    public const SPAN_KIND_SERVER = 2;
    public const SPAN_KIND_CLIENT = 3;
    public const SPAN_KIND_PRODUCER = 4;
    public const SPAN_KIND_CONSUMER = 5;
    
    // Status codes (OpenTelemetry OTLP uses string codes)
    public const STATUS_OK = 'OK';
    public const STATUS_ERROR = 'ERROR';
    
    /**
     * Valid span kinds for validation (cached for performance)
     * 
     * @var array<int, int>
     */
    private static array $validSpanKinds = [
        self::SPAN_KIND_UNSPECIFIED,
        self::SPAN_KIND_INTERNAL,
        self::SPAN_KIND_SERVER,
        self::SPAN_KIND_CLIENT,
        self::SPAN_KIND_PRODUCER,
        self::SPAN_KIND_CONSUMER,
    ];
    
    /**
     * Default TraceKit endpoint URL (library constant)
     * 
     * This is the default endpoint used by the library. It can be overridden
     * via the 'endpoint' config key or TRACEKIT_ENDPOINT environment variable.
     * 
     * @var string
     */
    private const DEFAULT_ENDPOINT = 'https://app.tracekit.dev/v1/traces';
    
    /**
     * Constructor - Initializes TraceKit provider from environment variables
     * 
     * At runtime, instances are created with configuration loaded from environment variables.
     * The init() method is available for setup/configuration via CLI/GUI tools.
     * 
     * @param \Gemvc\Http\Request|null $request The HTTP request object
     * @param array<string, mixed> $config Optional configuration override
     */
    public function __construct(?\Gemvc\Http\Request $request = null, array $config = [])
    {
        // Register this instance as the current active instance (for backward compatibility)
        self::$currentInstance = $this;
        
        // Call parent constructor which loads configuration from environment variables
        parent::__construct($request, $config);
        
        // Note: Parent constructor already sets $this->request->apm = $this;
        // The deprecated $request->tracekit property is no longer used
    }
    
    /**
     * Initialize TraceKit provider with configuration (for setup/configuration via CLI/GUI)
     * 
     * This method is called during setup/configuration process (via CLI command or GUI)
     * to configure the provider. It loads TraceKit-specific environment variables and
     * merges with defaults. Can be used to set up the .env file or configuration.
     * 
     * Configuration priority: config array > environment variables > defaults
     * 
     * This is NOT called during runtime object creation - the constructor handles that.
     * 
     * @param array<string, mixed> $config Optional configuration override
     * @return bool True if initialization was successful, false otherwise
     */
    public function init(array $config = []): bool
    {
        try {
            // Define default configuration values
            $defaults = [
                'api_key' => '',
                'service_name' => 'gemvc-app',
                'endpoint' => self::DEFAULT_ENDPOINT,
                'enabled' => true,
                'sample_rate' => 1.0,
                'trace_response' => false,
                'trace_db_query' => false,
                'trace_request_body' => false,
            ];
            
            // Load environment variables (with fallback to defaults)
            $envConfig = $this->loadEnvironmentVariables();
            
            // Merge: defaults < env variables < config array (config array has highest priority)
            $finalConfig = array_merge($defaults, $envConfig, $config);
            
            // Call parent init() which will call loadConfiguration() and handle common initialization
            return parent::init($finalConfig);
        } catch (\Throwable $e) {
            // Log error in dev environment
            if (ProjectHelper::isDevEnvironment()) {
                error_log("TraceKit: Initialization failed: " . $e->getMessage());
            }
            return false;
        }
    }
    
    /**
     * Load TraceKit environment variables
     * 
     * Loads all TRACEKIT_* environment variables and converts them to config array format.
     * 
     * @return array<string, mixed> Configuration array from environment variables
     */
    private function loadEnvironmentVariables(): array
    {
        $envConfig = [];
        
        // Load API key (check both TRACEKIT_API_KEY and APM_API_KEY for unified support)
        $apiKey = $_ENV['TRACEKIT_API_KEY'] ?? $_ENV['APM_API_KEY'] ?? null;
        if (is_string($apiKey) && $apiKey !== '') {
            $envConfig['api_key'] = $apiKey;
        }
        
        // Load service name
        $serviceName = $_ENV['TRACEKIT_SERVICE_NAME'] ?? null;
        if (is_string($serviceName) && $serviceName !== '') {
            $envConfig['service_name'] = $serviceName;
        }
        
        // Load endpoint
        $endpoint = $_ENV['TRACEKIT_ENDPOINT'] ?? null;
        if (is_string($endpoint) && $endpoint !== '') {
            $envConfig['endpoint'] = $endpoint;
        }
        
        // Load enabled flag
        $enabled = $_ENV['TRACEKIT_ENABLED'] ?? null;
        if ($enabled !== null) {
            $envConfig['enabled'] = $enabled;
        }
        
        // Load sample rate
        $sampleRate = $_ENV['TRACEKIT_SAMPLE_RATE'] ?? null;
        if ($sampleRate !== null) {
            $envConfig['sample_rate'] = $sampleRate;
        }
        
        // Load trace flags
        $traceResponse = $_ENV['TRACEKIT_TRACE_RESPONSE'] ?? null;
        if ($traceResponse !== null) {
            $envConfig['trace_response'] = $traceResponse;
        }
        
        $traceDbQuery = $_ENV['TRACEKIT_TRACE_DB_QUERY'] ?? null;
        if ($traceDbQuery !== null) {
            $envConfig['trace_db_query'] = $traceDbQuery;
        }
        
        // Load trace request body (check both env vars for backward compatibility)
        $traceRequestBody = $_ENV['TRACEKIT_TRACE_REQUEST_BODY'] ?? $_ENV['TRACEKIT_TRACE_RESPONSE_BODY'] ?? null;
        if ($traceRequestBody !== null) {
            $envConfig['trace_request_body'] = $traceRequestBody;
        }
        
        return $envConfig;
    }
    
    /**
     * Get the current active TraceKitModel instance
     * 
     * This is used by Controller and UniversalQueryExecuter to get the same instance
     * that was created by ApiService, ensuring all spans share the same traceId
     * 
     * @return TraceKitModel|null The current active instance or null if not set
     */
    public static function getCurrentInstance(): ?TraceKitModel
    {
        return self::$currentInstance;
    }
    
    /**
     * Clear the current active instance (called on flush)
     * 
     * @return void
     */
    public static function clearCurrentInstance(): void
    {
        self::$currentInstance = null;
    }
    
    /**
     * Load TraceKit-specific configuration
     * 
     * @param array<string, mixed> $config Optional configuration override
     * @return void
     */
    protected function loadConfiguration(array $config = []): void
    {
        // Load TraceKit-specific configuration
        $this->apiKey = $this->loadApiKey($config);
        $this->serviceName = $this->loadServiceName($config);
        $this->endpoint = $this->loadEndpoint($config);
        
        // Configuration precedence: config array > TRACEKIT_* env vars > APM_* env vars (already set by parent)
        // Override common config with config array first, then TRACEKIT_* env vars for backward compatibility
        
        // Enabled flag: config > TRACEKIT_ENABLED > (already set by parent from APM_ENABLED)
        if (isset($config['enabled'])) {
            $enabledValue = $config['enabled'];
            $this->enabled = is_string($enabledValue) ? ($enabledValue === 'true' || $enabledValue === '1') : (bool)$enabledValue;
        } elseif (isset($_ENV['TRACEKIT_ENABLED'])) {
            $enabledValue = $_ENV['TRACEKIT_ENABLED'];
            $this->enabled = is_string($enabledValue) ? ($enabledValue === 'true' || $enabledValue === '1') : (bool)$enabledValue;
        }
        
        // Sample rate: config > TRACEKIT_SAMPLE_RATE > (already set by parent from APM_SAMPLE_RATE)
        if (isset($config['sample_rate'])) {
            $sampleRateValue = $config['sample_rate'];
            $this->sampleRate = is_numeric($sampleRateValue) ? max(0.0, min(1.0, (float)$sampleRateValue)) : 1.0;
        } elseif (isset($_ENV['TRACEKIT_SAMPLE_RATE'])) {
            $sampleRateValue = $_ENV['TRACEKIT_SAMPLE_RATE'];
            $this->sampleRate = is_numeric($sampleRateValue) ? max(0.0, min(1.0, (float)$sampleRateValue)) : 1.0;
        }
        
        // Trace response: config > TRACEKIT_TRACE_RESPONSE > (already set by parent from APM_TRACE_RESPONSE)
        if (isset($config['trace_response'])) {
            $traceResponseValue = $config['trace_response'];
            $this->traceResponse = is_string($traceResponseValue) ? ($traceResponseValue === 'true' || $traceResponseValue === '1') : (bool)$traceResponseValue;
        } elseif (isset($_ENV['TRACEKIT_TRACE_RESPONSE'])) {
            $traceResponseValue = $_ENV['TRACEKIT_TRACE_RESPONSE'];
            $this->traceResponse = is_string($traceResponseValue) ? ($traceResponseValue === 'true' || $traceResponseValue === '1') : (bool)$traceResponseValue;
        }
        
        // Trace DB query: config > TRACEKIT_TRACE_DB_QUERY > (already set by parent from APM_TRACE_DB_QUERY)
        if (isset($config['trace_db_query'])) {
            $traceDbQueryValue = $config['trace_db_query'];
            $this->traceDbQuery = is_string($traceDbQueryValue) ? ($traceDbQueryValue === 'true' || $traceDbQueryValue === '1') : (bool)$traceDbQueryValue;
        } elseif (isset($_ENV['TRACEKIT_TRACE_DB_QUERY'])) {
            $traceDbQueryValue = $_ENV['TRACEKIT_TRACE_DB_QUERY'];
            $this->traceDbQuery = is_string($traceDbQueryValue) ? ($traceDbQueryValue === 'true' || $traceDbQueryValue === '1') : (bool)$traceDbQueryValue;
        }
        
        // Trace request body: config > TRACEKIT_TRACE_REQUEST_BODY > TRACEKIT_TRACE_RESPONSE_BODY > (already set by parent)
        if (isset($config['trace_request_body'])) {
            $traceRequestBodyValue = $config['trace_request_body'];
            $this->traceRequestBody = is_string($traceRequestBodyValue) ? ($traceRequestBodyValue === 'true' || $traceRequestBodyValue === '1') : (bool)$traceRequestBodyValue;
        } else {
            $traceRequestBodyValue = $_ENV['TRACEKIT_TRACE_REQUEST_BODY'] ?? $_ENV['TRACEKIT_TRACE_RESPONSE_BODY'] ?? null;
            if ($traceRequestBodyValue !== null) {
                $this->traceRequestBody = is_string($traceRequestBodyValue) ? ($traceRequestBodyValue === 'true' || $traceRequestBodyValue === '1') : (bool)$traceRequestBodyValue;
            }
        }
        
        // Disable if no API key
        if (empty($this->apiKey)) {
            $this->enabled = false;
        }
    }
    
    /**
     * Initialize root trace from Request object
     * 
     * @return void
     */
    protected function initializeRootTrace(): void
    {
        if ($this->request === null) {
            return;
        }
        
        try {
            // Log only in dev mode to avoid blocking I/O in production (suppress during tests)
            if (ProjectHelper::isDevEnvironment() && !defined('PHPUNIT_TEST')) {
                error_log("TraceKit: Initialized and enabled for service: " . $this->request->getServiceName() . '/' . $this->request->getMethodName());
            }
            
            // Build root span attributes from Request
            $rootAttributes = [
                'http.method' => $this->request->getMethod(),
                'http.url' => $this->request->getUri(),
                'http.user_agent' => $this->request->getHeader('User-Agent') ?? 'unknown',
                'http.route' => $this->request->getServiceName() . '/' . $this->request->getMethodName(),
            ];
            
            // Optionally include request body if enabled
            if ($this->shouldTraceRequestBody()) {
                $requestBody = $this->getRequestBodyForTracing();
                if ($requestBody !== null) {
                    $rootAttributes['http.request.body'] = self::limitStringForTracing($requestBody);
                }
            }
            
            // Start root trace for this request
            $this->rootSpan = $this->startTrace('http-request', $rootAttributes);
            
            if (empty($this->rootSpan)) {
                if (ProjectHelper::isDevEnvironment()) {
                    error_log("TraceKit: Failed to start trace (sampling or error)");
                }
                return;
            }
            
            $traceId = is_string($this->rootSpan['trace_id'] ?? null) ? $this->rootSpan['trace_id'] : 'N/A';
            if (ProjectHelper::isDevEnvironment() && !defined('PHPUNIT_TEST')) {
                error_log("TraceKit: Root span started - Trace ID: " . substr($traceId, 0, 16) . "...");
            }
            
            // Register shutdown function to flush traces after response is sent
            register_shutdown_function(function() {
                $this->flushOnShutdown();
            });
        } catch (\Throwable $e) {
            // Silently fail - don't let TraceKit break the application
            if (ProjectHelper::isDevEnvironment()) {
                error_log("TraceKit: Failed to initialize root trace: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            }
        }
    }
    
    /**
     * Flush traces on shutdown (called by register_shutdown_function)
     * 
     * Ensures all batched traces are sent immediately on shutdown using
     * AbstractApm's forceSendBatch() method.
     * 
     * @return void
     */
    private function flushOnShutdown(): void
    {
        if (empty($this->rootSpan)) {
            if (ProjectHelper::isDevEnvironment() && !defined('PHPUNIT_TEST')) {
                error_log("TraceKit: Flush skipped - rootSpan is empty");
            }
            return;
        }
        
        try {
            // Ensure HTTP response is sent first (for Apache/Nginx with PHP-FPM)
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            
            // Get response status code if available
            $statusCodeRaw = http_response_code();
            $statusCode = is_int($statusCodeRaw) ? $statusCodeRaw : 200;
            
            if (ProjectHelper::isDevEnvironment() && !defined('PHPUNIT_TEST')) {
                error_log("TraceKit: Flushing trace on shutdown - Status: " . $statusCode);
            }
            
            // End root span with final status
            $this->endSpan($this->rootSpan, [
                'http.status_code' => $statusCode,
            ], self::determineStatusFromHttpCode($statusCode));
            
            // Flush (adds trace to batch queue)
            $this->flush();
            
            // Force send all batched traces immediately on shutdown
            $this->forceSendBatch();
        } catch (\Throwable $e) {
            // Silently fail - don't let TraceKit break the application
            if (ProjectHelper::isDevEnvironment()) {
                error_log("TraceKit: Failed to flush traces on shutdown: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
            }
        }
    }
    
    // ==========================================
    // Configuration Loading Methods (Private)
    // ==========================================
    
    /**
     * Load API key from config array or environment variable
     * 
     * @param array<string, mixed> $config Configuration array
     * @return string API key or empty string if not found
     */
    private function loadApiKey(array $config): string
    {
        if (isset($config['api_key']) && is_string($config['api_key'])) {
            return $config['api_key'];
        }
        
        $envKey = $_ENV['TRACEKIT_API_KEY'] ?? null;
        return is_string($envKey) ? $envKey : '';
    }
    
    /**
     * Load service name from config array or environment variable
     * 
     * @param array<string, mixed> $config Configuration array
     * @return string Service name or default 'gemvc-app'
     */
    private function loadServiceName(array $config): string
    {
        if (isset($config['service_name']) && is_string($config['service_name'])) {
            return $config['service_name'];
        }
        
        $envName = $_ENV['TRACEKIT_SERVICE_NAME'] ?? null;
        return is_string($envName) ? $envName : 'gemvc-app';
    }
    
    /**
     * Load endpoint URL from config array or environment variable
     * 
     * Uses library constant DEFAULT_ENDPOINT as the default value.
     * Can be overridden via config array or TRACEKIT_ENDPOINT environment variable.
     * 
     * @param array<string, mixed> $config Configuration array
     * @return string Endpoint URL or default TraceKit endpoint
     */
    private function loadEndpoint(array $config): string
    {
        if (isset($config['endpoint']) && is_string($config['endpoint'])) {
            return $config['endpoint'];
        }
        $envEndpoint = $_ENV['TRACEKIT_ENDPOINT'] ?? null;
        return is_string($envEndpoint) ? $envEndpoint : self::DEFAULT_ENDPOINT;
    }
    
    /**
     * Check if tracing is enabled
     * 
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->apiKey);
    }
    
    /**
     * Get current sample rate (0.0 to 1.0, where 1.0 = 100%)
     * 
     * @return float Sample rate as decimal (0.0 = 0%, 1.0 = 100%)
     */
    public function getSampleRate(): float
    {
        return $this->sampleRate;
    }
    
    /**
     * Get current sample rate as percentage (0 to 100)
     * 
     * @return float Sample rate as percentage (0.0 = 0%, 100.0 = 100%)
     */
    public function getSampleRatePercent(): float
    {
        return $this->sampleRate * 100.0;
    }
    
    /**
     * Start a new trace (root span) for a server request
     * 
     * This automatically generates a trace ID and creates the root span.
     * The span is automatically activated in the context (added to stack).
     * 
     * @param string $operationName Operation name (e.g., 'http-request')
     * @param array<string, mixed> $attributes Optional attributes (e.g., ['http.method' => 'POST', 'http.url' => '/api/users'])
     * @param bool $forceSample Force sampling (e.g., for errors) - always traces regardless of sample rate
     * @return array<string, mixed> Span data: ['span_id' => string, 'trace_id' => string, 'start_time' => int]
     */
    public function startTrace(string $operationName, array $attributes = [], bool $forceSample = false): array
    {
        if (!$this->shouldSample($forceSample)) {
            return [];
        }
        
        try {
            // Get or generate trace ID
            $traceId = $this->getTraceIdOrGenerate();
            
            // Generate span ID
            $spanId = $this->generateSpanId();
            
            // Get current time in microseconds
            $startTime = $this->getMicrotime();
            
            // Create span data
            $spanData = $this->createSpanData($traceId, $spanId, null, $operationName, self::SPAN_KIND_SERVER, $startTime, $attributes);
            
            // Add to spans array
            $this->spans[] = $spanData;
            
            // Push to stack (activate in context)
            $this->pushSpan($spanData);
            
            // Return span reference
            return $this->createSpanDataReturn($spanId, $traceId, $startTime);
        } catch (\Throwable $e) {
            // Graceful degradation - log error but don't break application
            error_log("TraceKit: Failed to start trace: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Start a child span
     * 
     * Automatically inherits from the currently active span in context (stack).
     * If no active span exists, this creates a root span instead.
     * 
     * @param string $operationName Operation name (e.g., 'database-query', 'http-client-call')
     * @param array<string, mixed> $attributes Optional attributes
     * @param int $kind Span kind: SPAN_KIND_SERVER (2), SPAN_KIND_CLIENT (3), or SPAN_KIND_INTERNAL (1) (default: SPAN_KIND_INTERNAL)
     * @return array<string, mixed> Span data: ['span_id' => string, 'trace_id' => string, 'start_time' => int]
     */
    public function startSpan(string $operationName, array $attributes = [], int $kind = self::SPAN_KIND_INTERNAL): array
    {
        if (!$this->isEnabled()) {
            return [];
        }
        
        // Check sampling (unless this is a forced sample, which startSpan doesn't support)
        // Child spans inherit the sampling decision from the root trace
        // If no root trace exists, we need to check sampling
        // However, if we have a request, the root span should have been created already
        // So we only check sampling if there's no root span AND no request (standalone span)
        if (empty($this->rootSpan) && $this->request === null && !$this->shouldSample(false)) {
            return [];
        }
        
        try {
            // Get or generate trace ID
            $traceId = $this->getTraceIdOrGenerate();
            
            // Get active span (parent)
            $activeSpan = $this->getActiveSpan();
            $parentSpanIdRaw = $activeSpan['span_id'] ?? null;
            $parentSpanId = is_string($parentSpanIdRaw) ? $parentSpanIdRaw : null;
            
            // Generate span ID
            $spanId = $this->generateSpanId();
            
            // Get current time in microseconds
            $startTime = $this->getMicrotime();
            
            // Validate kind (must be valid OpenTelemetry span kind integer)
            if (!in_array($kind, self::$validSpanKinds, true)) {
                $kind = self::SPAN_KIND_INTERNAL;
            }
            
            // Create span data
            $spanData = $this->createSpanData($traceId, $spanId, $parentSpanId, $operationName, $kind, $startTime, $attributes);
            
            // Add to spans array
            $this->spans[] = $spanData;
            
            // Push to stack (activate in context)
            $this->pushSpan($spanData);
            
            // Return span reference
            return $this->createSpanDataReturn($spanId, $traceId, $startTime);
        } catch (\Throwable $e) {
            // Graceful degradation
            error_log("TraceKit: Failed to start span: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * End a span and detach it from context
     * 
     * @param array<string, mixed> $spanData Span data returned from startTrace() or startSpan()
     * @param array<string, mixed> $finalAttributes Optional attributes to add before ending
     * @param string|null $status Span status: 'OK' or 'ERROR' (default: 'OK')
     * @return void
     */
    public function endSpan(array $spanData, array $finalAttributes = [], ?string $status = self::STATUS_OK): void
    {
        if (empty($spanData) || !$this->isEnabled()) {
            return;
        }
        
        try {
            $spanId = $this->getSpanIdFromSpanData($spanData);
            if (!$spanId) {
                return;
            }
            
            // Find span in spans array
            $spanIndex = $this->findSpanIndexById($spanId);
            if ($spanIndex === null) {
                return;
            }
            
            // Get end time
            $endTime = $this->getMicrotime();
            /** @var array<string, mixed> $span */
            $span = $this->spans[$spanIndex];
            $startTime = is_int($span['start_time'] ?? null) ? $span['start_time'] : $endTime;
            $duration = $endTime - $startTime;
            
            // Update span
            $this->spans[$spanIndex]['end_time'] = $endTime;
            $this->spans[$spanIndex]['duration'] = $duration;
            
            // Add final attributes (optimized: skip array_merge if empty)
            if (!empty($finalAttributes)) {
                /** @var array<string, mixed> $span */
                $span = $this->spans[$spanIndex];
                $existingAttributes = is_array($span['attributes'] ?? null) ? $span['attributes'] : [];
                $normalizedAttributes = $this->normalizeAttributes($finalAttributes);
                // Use array union operator for better performance when keys don't conflict
                $this->spans[$spanIndex]['attributes'] = array_merge($existingAttributes, $normalizedAttributes);
            }
            
            // Set status
            if ($status === self::STATUS_ERROR) {
                $this->spans[$spanIndex]['status'] = self::STATUS_ERROR;
            } else {
                $this->spans[$spanIndex]['status'] = self::STATUS_OK;
            }
            
            // Pop from stack (detach from context)
            $this->popSpan();
        } catch (\Throwable $e) {
            // Graceful degradation
            error_log("TraceKit: Failed to end span: " . $e->getMessage());
        }
    }
    
    /**
     * Record an exception on a span
     * 
     * IMPORTANT: If no trace exists (spanData is empty), this will automatically
     * use the root span if available, or create a trace to ensure errors are ALWAYS logged.
     * 
     * @param array<string, mixed> $spanData Span data returned from startTrace() or startSpan() (can be empty to use root span)
     * @param \Throwable $exception Exception to record
     * @return array<string, mixed> Updated span data (useful if trace was auto-created)
     */
    public function recordException(array $spanData, \Throwable $exception): array
    {
        if (!$this->isEnabled()) {
            return [];
        }
        
        try {
            // If no span data provided, try to use root span
            if (empty($spanData) || empty($spanData['span_id'])) {
                if (!empty($this->rootSpan)) {
                    $spanData = $this->rootSpan;
                } else {
                    // If no root span exists, create one automatically (errors are always logged)
                    // Add error context to attributes
                    $errorAttributes = [
                        'error.type' => get_class($exception),
                        'error.message' => $exception->getMessage(),
                        'error.code' => $exception->getCode(),
                    ];
                    
                    // Add HTTP metadata if Request is available
                    if ($this->request !== null) {
                        $errorAttributes['http.method'] = $this->request->getMethod();
                        $errorAttributes['http.url'] = $this->request->getUri();
                    }
                    
                    // Force sample = true to ensure error is always traced
                    $spanData = $this->startTrace('error-handler', $errorAttributes, true);
                    
                    if (empty($spanData)) {
                        // Failed to create trace, log and return
                        error_log("TraceKit: Failed to create trace for exception: " . $exception->getMessage());
                        return [];
                    }
                    
                    // Store as root span if it wasn't set
                    $this->rootSpan = $spanData;
                }
            }
            
            $spanId = $this->getSpanIdFromSpanData($spanData);
            if (!$spanId) {
                return $spanData;
            }
            
            // Find span in spans array
            $spanIndex = $this->findSpanIndexById($spanId);
            if ($spanIndex === null) {
                return $spanData;
            }
            
            // Format exception event
            $event = $this->createEvent('exception', [
                'exception.type' => get_class($exception),
                'exception.message' => $exception->getMessage(),
                'exception.code' => $exception->getCode(),
                'exception.stacktrace' => $this->formatStackTrace($exception),
            ]);
            
            // Add event to span
            $this->addEventToSpan($spanIndex, $event);
            
            // Set span status to ERROR
            $this->spans[$spanIndex]['status'] = self::STATUS_ERROR;
            
            return $spanData;
        } catch (\Throwable $e) {
            // Graceful degradation
            error_log("TraceKit: Failed to record exception: " . $e->getMessage());
            return empty($spanData) ? [] : $spanData;
        }
    }
    
    /**
     * Add an event to a span
     * 
     * @param array<string, mixed> $spanData Span data
     * @param string $eventName Event name
     * @param array<string, mixed> $attributes Event attributes
     * @return void
     */
    public function addEvent(array $spanData, string $eventName, array $attributes = []): void
    {
        if (empty($spanData) || !$this->isEnabled()) {
            return;
        }
        
        try {
            $spanId = $this->getSpanIdFromSpanData($spanData);
            if (!$spanId) {
                return;
            }
            
            // Find span in spans array
            $spanIndex = $this->findSpanIndexById($spanId);
            if ($spanIndex === null) {
                return;
            }
            
            // Create event
            $event = $this->createEvent($eventName, $attributes);
            
            // Add event to span
            $this->addEventToSpan($spanIndex, $event);
        } catch (\Throwable $e) {
            // Graceful degradation
            error_log("TraceKit: Failed to add event: " . $e->getMessage());
        }
    }
    
    /**
     * Flush traces (add to batch queue for sending)
     * 
     * Uses AbstractApm's batching system which sends traces in batches
     * every APM_SEND_INTERVAL seconds (default: 5 seconds) using synchronous ApiCall.
     * This is compatible with OpenSwoole production environments.
     * 
     * @return void
     */
    public function flush(): void
    {
        if (!$this->isEnabled() || empty($this->spans) || $this->traceId === null) {
            if (!defined('PHPUNIT_TEST')) {
                error_log("TraceKit: Flush skipped - enabled: " . ($this->isEnabled() ? 'yes' : 'no') . ", spans: " . count($this->spans) . ", traceId: " . ($this->traceId ?? 'null'));
            }
            return;
        }
        
        try {
            // Build trace payload
            $payload = $this->buildTracePayload();
            
            if (empty($payload)) {
                // Clear spans even if payload is empty
                $this->spans = [];
                $this->traceId = null;
                return;
            }
            
            // Add trace to batch queue (uses AbstractApm's batching system)
            $this->addTraceToBatch($payload);
            
            // Check if batch should be sent (time-based, uses APM_SEND_INTERVAL from base class)
            $this->sendBatchIfNeeded();
            
            // Clear spans for next trace
            $this->spans = [];
            $this->traceId = null;
            
            // Clear current instance after flush (new request will create new instance)
            self::clearCurrentInstance();
        } catch (\Throwable $e) {
            // Graceful degradation - log error but don't break application
            error_log("TraceKit: Failed to flush traces: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        }
    }
    
    /**
     * Get current trace ID
     * 
     * @return string|null
     */
    public function getTraceId(): ?string
    {
        return $this->traceId;
    }
    
    /**
     * Get active span (for context propagation)
     * 
     * @return array<string, mixed>|null
     */
    public function getActiveSpan(): ?array
    {
        return end($this->spanStack) ?: null;
    }
    
    // ==========================================
    // Private Helper Methods
    // ==========================================
    
    /**
     * Push span to stack (activate in context)
     * 
     * @param array<string, mixed> $spanData
     * @return void
     */
    private function pushSpan(array $spanData): void
    {
        $this->spanStack[] = $spanData;
    }
    
    /**
     * Pop span from stack (detach from context)
     * 
     * @return array<string, mixed>|null
     */
    private function popSpan(): ?array
    {
        return array_pop($this->spanStack);
    }
    
    /**
     * Create an event data structure
     * 
     * @param string $name Event name
     * @param array<string, mixed> $attributes Event attributes (will be normalized)
     * @return array{name: string, time: int, attributes: array<string, mixed>}
     */
    private function createEvent(string $name, array $attributes = []): array
    {
        return [
            'name' => $name,
            'time' => $this->getMicrotime(),
            'attributes' => $this->normalizeAttributes($attributes),
        ];
    }
    
    /**
     * Get trace ID, generating it if it doesn't exist
     * 
     * @return string Guaranteed non-null trace ID
     */
    private function getTraceIdOrGenerate(): string
    {
        if ($this->traceId === null) {
            $this->traceId = $this->generateTraceId();
        }
        /** @var string $traceId */
        $traceId = $this->traceId;
        return $traceId;
    }
    
    /**
     * Extract span ID from span data
     * 
     * @param array<string, mixed> $spanData
     * @return string|null
     */
    private function getSpanIdFromSpanData(array $spanData): ?string
    {
        $spanId = $spanData['span_id'] ?? null;
        return is_string($spanId) ? $spanId : null;
    }
    
    /**
     * Find span index in spans array by span ID
     * 
     * @param string $spanId
     * @return int|null Index of span or null if not found
     */
    private function findSpanIndexById(string $spanId): ?int
    {
        foreach ($this->spans as $index => $span) {
            if (($span['span_id'] ?? null) === $spanId) {
                return $index;
            }
        }
        return null;
    }
    
    /**
     * Add an event to a span at the specified index
     * 
     * Optimized to avoid unnecessary array copy - directly appends to span events array
     * 
     * @param int $spanIndex Index of the span in $this->spans array
     * @param array<string, mixed> $event Event data structure
     * @return void
     */
    private function addEventToSpan(int $spanIndex, array $event): void
    {
        if (!isset($this->spans[$spanIndex]['events']) || !is_array($this->spans[$spanIndex]['events'])) {
            $this->spans[$spanIndex]['events'] = [];
        }
        // Direct append - no array copy needed
        $this->spans[$spanIndex]['events'][] = $event;
    }
    
    /**
     * Build a single OTLP attribute entry
     * 
     * Converts a key-value pair to OpenTelemetry OTLP attribute format.
     * 
     * @param string $key Attribute key
     * @param mixed $value Attribute value (will be converted to string)
     * @return array{key: string, value: array{stringValue: string}}
     */
    private function buildOtlpAttribute(string $key, mixed $value): array
    {
        return [
            'key' => $key,
            'value' => [
                'stringValue' => is_string($value) || is_numeric($value) ? (string)$value : ''
            ]
        ];
    }
    
    /**
     * Build OTLP format event from internal event format
     * 
     * Converts internal event data structure to OpenTelemetry OTLP JSON format.
     * 
     * @param array<string, mixed> $event Internal event data
     * @return array{name: string, timeUnixNano: string, attributes: array<int, array<string, mixed>>}
     */
    private function buildOtlpEvent(array $event): array
    {
        $eventAttributes = [];
        $eventAttrs = is_array($event['attributes'] ?? null) ? $event['attributes'] : [];
        foreach ($eventAttrs as $key => $value) {
            $eventAttributes[] = $this->buildOtlpAttribute((string)$key, $value);
        }
        
        $eventName = is_string($event['name'] ?? null) ? $event['name'] : 'event';
        $eventTime = is_int($event['time'] ?? null) ? $event['time'] : 0;
        
        return [
            'name' => $eventName,
            'timeUnixNano' => (string)$eventTime,
            'attributes' => $eventAttributes,
        ];
    }
    
    /**
     * Build OTLP format span data from internal span format
     * 
     * Converts internal span data structure to OpenTelemetry OTLP JSON format.
     * 
     * @param array<string, mixed> $span Internal span data
     * @param array<int, array<string, mixed>> $otlpAttributes OTLP formatted attributes
     * @param array<int, array<string, mixed>> $otlpEvents OTLP formatted events
     * @return array<string, mixed> OTLP format span data
     */
    private function buildOtlpSpan(array $span, array $otlpAttributes, array $otlpEvents): array
    {
        $traceId = is_string($span['trace_id'] ?? null) ? $span['trace_id'] : '';
        $spanId = is_string($span['span_id'] ?? null) ? $span['span_id'] : '';
        $name = is_string($span['name'] ?? null) ? $span['name'] : '';
        $kind = is_int($span['kind'] ?? null) ? $span['kind'] : self::SPAN_KIND_INTERNAL;
        $startTime = is_int($span['start_time'] ?? null) ? $span['start_time'] : 0;
        $endTime = is_int($span['end_time'] ?? null) ? $span['end_time'] : 0;
        $status = is_string($span['status'] ?? null) ? $span['status'] : self::STATUS_OK;
        $parentSpanId = $span['parent_span_id'] ?? null;
        
        // Extract error message if status is ERROR
        $errorMessage = '';
        if ($status === self::STATUS_ERROR) {
            $spanAttributes = is_array($span['attributes'] ?? null) ? $span['attributes'] : [];
            $errorMessage = is_string($spanAttributes['error.message'] ?? null) ? $spanAttributes['error.message'] : 'Error';
        }
        
        $spanData = [
            'traceId' => $traceId,
            'spanId' => $spanId,
            'name' => $name,
            'kind' => $kind,
            'startTimeUnixNano' => (string)$startTime,
            'endTimeUnixNano' => (string)$endTime,
            'attributes' => $otlpAttributes,
            'status' => [
                'code' => $status === self::STATUS_ERROR ? 'STATUS_CODE_ERROR' : 'STATUS_CODE_OK',
                'message' => $errorMessage,
            ],
            'events' => $otlpEvents,
        ];
        
        // Only include parentSpanId if it exists (root spans don't have parent)
        if ($parentSpanId !== null && is_string($parentSpanId)) {
            $spanData['parentSpanId'] = $parentSpanId;
        }
        
        return $spanData;
    }
    
    /**
     * Create span data return value
     * 
     * @param string $spanId
     * @param string $traceId
     * @param int $startTime
     * @return array{span_id: string, trace_id: string, start_time: int}
     */
    private function createSpanDataReturn(string $spanId, string $traceId, int $startTime): array
    {
        return [
            'span_id' => $spanId,
            'trace_id' => $traceId,
            'start_time' => $startTime,
        ];
    }
    
    /**
     * Create span data structure
     * 
     * @param string $traceId
     * @param string $spanId
     * @param string|null $parentSpanId Parent span ID (null for root spans)
     * @param string $name Operation name
     * @param int $kind Span kind (SPAN_KIND_SERVER, SPAN_KIND_CLIENT, etc.)
     * @param int $startTime Start time in nanoseconds
     * @param array<string, mixed> $attributes Span attributes
     * @return array<string, mixed>
     */
    private function createSpanData(string $traceId, string $spanId, ?string $parentSpanId, string $name, int $kind, int $startTime, array $attributes = []): array
    {
        return [
            'trace_id' => $traceId,
            'span_id' => $spanId,
            'parent_span_id' => $parentSpanId,
            'name' => $name,
            'kind' => $kind,
            'start_time' => $startTime,
            'end_time' => null,
            'duration' => null,
            'attributes' => $this->normalizeAttributes($attributes),
            'status' => self::STATUS_OK,
            'events' => [],
        ];
    }
    
    /**
     * Generate trace ID (32 hex characters for OTLP JSON)
     * 
     * OpenTelemetry OTLP JSON uses hex strings for trace_id (not base64)
     * 
     * @return string 32-character hex string
     */
    private function generateTraceId(): string
    {
        // Generate 16 random bytes (128 bits) and convert to hex (32 characters)
        return bin2hex(random_bytes(16));
    }
    
    /**
     * Generate span ID (16 hex characters for OTLP JSON)
     * 
     * OpenTelemetry OTLP JSON uses hex strings for span_id (not base64)
     * 
     * @return string 16-character hex string
     */
    private function generateSpanId(): string
    {
        // Generate 8 random bytes (64 bits) and convert to hex (16 characters)
        return bin2hex(random_bytes(8));
    }
    
    /**
     * Get current time in nanoseconds (Unix timestamp * 1,000,000,000)
     * 
     * OpenTelemetry OTLP requires timestamps in nanoseconds
     * 
     * @return int Nanoseconds since Unix epoch
     */
    private function getMicrotime(): int
    {
        return (int)(microtime(true) * 1000000000);
    }
    
    /**
     * Normalize attributes (convert to string/int/float/bool)
     * 
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    private function normalizeAttributes(array $attributes): array
    {
        /** @var array<string, mixed> $normalized */
        $normalized = [];
        
        foreach ($attributes as $key => $value) {
            if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
                $normalized[$key] = $value;
            } elseif (is_array($value)) {
                /** @var array<int|string, mixed> $value */
                $normalized[$key] = array_map(function(mixed $v): string {
                    return is_string($v) || is_numeric($v) ? (string) $v : '';
                }, $value);
            } else {
                // Value is not string, int, float, bool, or array - convert to string safely
                // Since we've already checked it's not scalar types, it must be object/resource/null
                if ($value === null) {
                    $normalized[$key] = '';
                } else {
                    // For objects/resources, convert to string
                    // PHP's string casting for objects calls __toString() if available
                    // For resources, it converts to "Resource id #X"
                    if (is_object($value) && method_exists($value, '__toString')) {
                        $normalized[$key] = (string) $value;
                    } elseif (is_resource($value)) {
                        $normalized[$key] = (string) $value;
                    } else {
                        // Fallback for objects without __toString
                        $normalized[$key] = '';
                    }
                }
            }
        }
        
        return $normalized;
    }
    
    /**
     * Format exception stack trace
     * 
     * @param \Throwable $exception
     * @return string
     */
    private function formatStackTrace(\Throwable $exception): string
    {
        $frames = [];
        
        // First line: where the exception was thrown
        $frames[] = $exception->getFile() . ':' . $exception->getLine();
        
        foreach ($exception->getTrace() as $frame) {
            /** @var array{file?: string, line?: int, function?: string, class?: string} $frame */
            $file = $frame['file'] ?? '';
            $line = $frame['line'] ?? 0;
            $function = $frame['function'] ?? '';
            $class = $frame['class'] ?? '';
            
            if ($class && $function) {
                $function = $class . '::' . $function;
            }
            
            // Only include frames that have file information
            if ($file && $function) {
                $frames[] = sprintf('%s at %s:%d', $function, $file, $line);
            } elseif ($file) {
                $frames[] = sprintf('%s:%d', $file, $line);
            }
        }
        
        return implode("\n", $frames);
    }
    
    /**
     * Build trace payload for sending to TraceKit
     * 
     * Format: OpenTelemetry OTLP JSON format for TraceKit service discovery
     * 
     * @return array<string, mixed>
     */
    private function buildTracePayload(): array
    {
        // Filter out incomplete spans (no end_time)
        /** @var array<int, array<string, mixed>> $completedSpans */
        $completedSpans = array_filter($this->spans, function($span): bool {
            /** @var array<string, mixed> $span */
            return isset($span['end_time']);
        });
        
        if (empty($completedSpans)) {
            return [];
        }
        
        // Convert spans to OpenTelemetry OTLP format
        /** @var array<int, array<string, mixed>> $spans */
        $spans = [];
        foreach ($completedSpans as $span) {
            /** @var array<string, mixed> $span */
            // Build attributes array in OTLP format
            $attributes = [];
            $spanAttributes = is_array($span['attributes'] ?? null) ? $span['attributes'] : [];
            foreach ($spanAttributes as $key => $value) {
                $attributes[] = $this->buildOtlpAttribute((string)$key, $value);
            }
            
            // Build events array in OTLP format
            $events = [];
            $spanEvents = is_array($span['events'] ?? null) ? $span['events'] : [];
            foreach ($spanEvents as $event) {
                /** @var array<string, mixed> $event */
                $events[] = $this->buildOtlpEvent($event);
            }
            
            // Build OTLP format span data
            $spanData = $this->buildOtlpSpan($span, $attributes, $events);
            $spans[] = $spanData;
        }
        
        // OpenTelemetry OTLP JSON format for TraceKit
        return [
            'resourceSpans' => [
                [
                    'resource' => [
                        'attributes' => [
                            [
                                'key' => 'service.name',
                                'value' => [
                                    'stringValue' => $this->serviceName
                                ]
                            ]
                        ]
                    ],
                    'scopeSpans' => [
                        [
                            'spans' => $spans,
                        ]
                    ]
                ]
            ]
        ];
    }
    
    // ==========================================
    // Batching Methods (Required by AbstractApm)
    // ==========================================
    
    /**
     * Build batch payload from multiple traces
     * 
     * Combines multiple trace payloads into a single OTLP batch payload.
     * Note: TraceKit currently sends traces immediately via fire-and-forget,
     * but this method is required by AbstractApm for potential future batching support.
     * 
     * @param array<int, array<string, mixed>> $traces Array of trace payloads
     * @return array<string, mixed> Combined batch payload in OTLP format
     */
    protected function buildBatchPayload(array $traces): array
    {
        if (empty($traces)) {
            return [];
        }
        
        // Combine all spans from all traces into a single resource span
        /** @var array<int, array<string, mixed>> $allSpans */
        $allSpans = [];
        
        foreach ($traces as $trace) {
            /** @var array<string, mixed> $trace */
            if (!isset($trace['resourceSpans']) || !is_array($trace['resourceSpans'])) {
                continue;
            }
            
            $resourceSpans = $trace['resourceSpans'];
            if (empty($resourceSpans) || !is_array($resourceSpans[0] ?? null)) {
                continue;
            }
            
            /** @var array<string, mixed> $firstResourceSpan */
            $firstResourceSpan = $resourceSpans[0];
            $scopeSpans = is_array($firstResourceSpan['scopeSpans'] ?? null) ? $firstResourceSpan['scopeSpans'] : [];
            if (empty($scopeSpans) || !is_array($scopeSpans[0] ?? null)) {
                continue;
            }
            
            /** @var array<string, mixed> $firstScopeSpan */
            $firstScopeSpan = $scopeSpans[0];
            $spansRaw = $firstScopeSpan['spans'] ?? null;
            
            if (is_array($spansRaw)) {
                /** @var array<int, array<string, mixed>> $spansRaw */
                $allSpans = array_merge($allSpans, $spansRaw);
            }
        }
        
        if (empty($allSpans)) {
            return [];
        }
        
        // Build combined OTLP payload with all spans
        return [
            'resourceSpans' => [
                [
                    'resource' => [
                        'attributes' => [
                            [
                                'key' => 'service.name',
                                'value' => [
                                    'stringValue' => $this->serviceName
                                ]
                            ]
                        ]
                    ],
                    'scopeSpans' => [
                        [
                            'spans' => $allSpans,
                        ]
                    ]
                ]
            ]
        ];
    }
    
    /**
     * Get endpoint URL for batch sending
     * 
     * @return string API endpoint URL
     */
    protected function getBatchEndpoint(): string
    {
        return $this->endpoint;
    }
    
    /**
     * Get HTTP headers for batch sending
     * 
     * @return array<string, string> HTTP headers as key-value pairs
     */
    protected function getBatchHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'X-API-Key' => $this->apiKey
        ];
    }
}

