<?php
namespace Gemvc\Core\Apm\Providers\TraceKit;

use Gemvc\Core\Apm\AbstractApm;
use Gemvc\Helper\ProjectHelper;

/**
 * TraceKit Provider - TraceKit APM Implementation
 * 
 * This is the TraceKit-specific implementation of the APM interface.
 * It extends AbstractApm and provides TraceKit-specific functionality:
 * - OpenTelemetry OTLP JSON format
 * - Non-blocking trace sending via AsyncApiCall
 * - Simple span tracking with stack-based context
 * 
 * @package Gemvc\Core\Apm\Providers\TraceKit
 */
class TraceKitProvider extends AbstractApm
{
    /**
     * Static registry to store the current active TraceKitProvider instance
     * This allows backward compatibility with getCurrentInstance()
     * 
     * @var TraceKitProvider|null
     */
    private static ?TraceKitProvider $currentInstance = null;
    
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
     * Uses ProjectHelper for any helper functions if needed.
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
     * Get the current active TraceKitProvider instance (for backward compatibility)
     * 
     * @return TraceKitProvider|null The current active instance or null if not set
     */
    public static function getCurrentInstance(): ?TraceKitProvider
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
            // Log only in dev mode to avoid blocking I/O in production
            if (ProjectHelper::isDevEnvironment()) {
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
            if (ProjectHelper::isDevEnvironment()) {
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
     * Override isEnabled() to also check API key
     * 
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->apiKey);
    }
    
    /**
     * Flush traces on shutdown (called by register_shutdown_function)
     * 
     * @return void
     */
    private function flushOnShutdown(): void
    {
        if (empty($this->rootSpan)) {
            if (ProjectHelper::isDevEnvironment()) {
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
            
            if (ProjectHelper::isDevEnvironment()) {
                error_log("TraceKit: Flushing trace - Status: " . $statusCode);
            }
            
            // End root span with final status
            $this->endSpan($this->rootSpan, [
                'http.status_code' => $statusCode,
            ], self::determineStatusFromHttpCode($statusCode));
            
            // Flush traces (non-blocking - AsyncApiCall will use fireAndForget)
            $this->flush();
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
    
    // ==========================================
    // Span Management Methods (Public - ApmInterface)
    // ==========================================
    
    /**
     * Start a new trace (root span) for a server request
     * 
     * @param string $operationName Operation name (e.g., 'http-request')
     * @param array<string, mixed> $attributes Optional attributes
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
            // Graceful degradation
            error_log("TraceKit: Failed to start trace: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Start a child span
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
     * @param array<string, mixed> $spanData Span data (can be empty to use root span)
     * @param \Throwable $exception Exception to record
     * @return array<string, mixed> Updated span data
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
     * Flush traces (send to TraceKit service)
     * 
     * @return void
     */
    public function flush(): void
    {
        if (!$this->isEnabled() || empty($this->spans) || $this->traceId === null) {
            return;
        }
        
        try {
            // Build trace payload
            $payload = $this->buildTracePayload();
            
            // Validate payload structure
            $validatedData = $this->validatePayloadStructure($payload);
            if ($validatedData === null) {
                return;
            }
            
            // Send traces using fire-and-forget (non-blocking)
            $this->sendTraces($payload);
            
            // Clear spans for next trace
            $this->spans = [];
            $this->traceId = null;
            
            // Clear current instance after flush
            self::clearCurrentInstance();
        } catch (\Throwable $e) {
            // Graceful degradation
            error_log("TraceKit: Failed to flush traces: " . $e->getMessage());
        }
    }
    
    // ==========================================
    // Private Helper Methods
    // ==========================================
    
    /**
     * Get active span (for context propagation)
     * 
     * @return array<string, mixed>|null
     */
    private function getActiveSpan(): ?array
    {
        return end($this->spanStack) ?: null;
    }
    
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
     * Get trace ID, generating it if it doesn't exist
     * 
     * @return string Guaranteed non-null trace ID
     */
    private function getTraceIdOrGenerate(): string
    {
        if ($this->traceId === null) {
            $this->traceId = $this->generateTraceId();
        }
        return $this->traceId;
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
     * Create an event data structure
     * 
     * @param string $name Event name
     * @param array<string, mixed> $attributes Event attributes
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
     * Add an event to a span at the specified index
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
        $this->spans[$spanIndex]['events'][] = $event;
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
     * @param int $kind Span kind
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
     * @return string 32-character hex string
     */
    private function generateTraceId(): string
    {
        return bin2hex(random_bytes(16));
    }
    
    /**
     * Generate span ID (16 hex characters for OTLP JSON)
     * 
     * @return string 16-character hex string
     */
    private function generateSpanId(): string
    {
        return bin2hex(random_bytes(8));
    }
    
    /**
     * Get current time in nanoseconds
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
                if ($value === null) {
                    $normalized[$key] = '';
                } else {
                    if (is_object($value) && method_exists($value, '__toString')) {
                        $normalized[$key] = (string) $value;
                    } elseif (is_resource($value)) {
                        $normalized[$key] = (string) $value;
                    } else {
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
    
    /**
     * Build a single OTLP attribute entry
     * 
     * @param string $key Attribute key
     * @param mixed $value Attribute value
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
     * Extract service name from resource span payload
     * 
     * @param array<string, mixed> $firstResourceSpan First resource span from payload
     * @return string Service name or 'unknown' if not found
     */
    private function extractServiceNameFromPayload(array $firstResourceSpan): string
    {
        $resource = is_array($firstResourceSpan['resource'] ?? null) ? $firstResourceSpan['resource'] : [];
        $resourceAttrs = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];
        $firstAttr = is_array($resourceAttrs[0] ?? null) ? $resourceAttrs[0] : [];
        $attrValue = is_array($firstAttr['value'] ?? null) ? $firstAttr['value'] : [];
        return is_string($attrValue['stringValue'] ?? null) ? $attrValue['stringValue'] : 'unknown';
    }
    
    /**
     * Validate payload structure and extract spans data
     * 
     * @param array<string, mixed> $payload The trace payload to validate
     * @return array{spans: array<int, array<string, mixed>>, spanCount: int, firstResourceSpan: array<string, mixed>}|null
     */
    private function validatePayloadStructure(array $payload): ?array
    {
        if (empty($payload) || !isset($payload['resourceSpans'])) {
            return null;
        }
        
        $resourceSpans = is_array($payload['resourceSpans']) ? $payload['resourceSpans'] : [];
        if (empty($resourceSpans) || !is_array($resourceSpans[0] ?? null)) {
            return null;
        }
        
        /** @var array<string, mixed> $firstResourceSpan */
        $firstResourceSpan = $resourceSpans[0];
        $scopeSpans = is_array($firstResourceSpan['scopeSpans'] ?? null) ? $firstResourceSpan['scopeSpans'] : [];
        if (empty($scopeSpans) || !is_array($scopeSpans[0] ?? null)) {
            return null;
        }
        
        /** @var array<string, mixed> $firstScopeSpan */
        $firstScopeSpan = $scopeSpans[0];
        $spansRaw = $firstScopeSpan['spans'] ?? null;
        if (!is_array($spansRaw)) {
            return null;
        }
        
        /** @var array<int, array<string, mixed>> $spans */
        $spans = $spansRaw;
        $spanCount = count($spans);
        
        if ($spanCount === 0) {
            return null;
        }
        
        return [
            'spans' => $spans,
            'spanCount' => $spanCount,
            'firstResourceSpan' => $firstResourceSpan,
        ];
    }
    
    /**
     * Send traces to TraceKit using fire-and-forget (non-blocking)
     * 
     * @param array<string, mixed> $payload The trace payload to send
     * @return void
     */
    private function sendTraces(array $payload): void
    {
        try {
            // Validate payload structure
            $validatedData = $this->validatePayloadStructure($payload);
            if ($validatedData === null) {
                error_log("TraceKit: Empty or invalid payload structure, skipping send");
                return;
            }
            
            $spans = $validatedData['spans'];
            $spanCount = $validatedData['spanCount'];
            $firstResourceSpan = $validatedData['firstResourceSpan'];
            
            // Extract service name
            $serviceName = $this->extractServiceNameFromPayload($firstResourceSpan);
            
            // Extract trace ID
            $firstSpan = is_array($spans[0] ?? null) ? $spans[0] : [];
            $traceIdRaw = is_string($firstSpan['traceId'] ?? null) ? $firstSpan['traceId'] : 'N/A';
            $traceId = substr($traceIdRaw, 0, 16);
            
            error_log("TraceKit: Queueing trace for fire-and-forget send - Service: {$serviceName}, Spans: {$spanCount}, Trace ID: {$traceId}...");
            
            // Use AsyncApiCall with fireAndForget() for truly non-blocking sending
            $asyncCall = new \Gemvc\Http\AsyncApiCall();
            $asyncCall->setTimeouts(1, 3);
            
            // Add POST request with trace payload and required headers
            $asyncCall->addPost('tracekit', $this->endpoint, $payload, [
                'Content-Type' => 'application/json',
                'X-API-Key' => $this->apiKey
            ])
                ->onResponse('tracekit', function($result, $requestId) use ($serviceName, $spanCount) {
                    /** @var array<string, mixed> $result */
                    if (!($result['success'] ?? false)) {
                        $error = is_string($result['error'] ?? null) ? $result['error'] : 'Unknown error';
                        error_log("TraceKit: Failed to send traces: " . $error);
                    } else {
                        $responseCode = is_int($result['http_code'] ?? null) ? $result['http_code'] : 0;
                        $body = $result['body'] ?? null;
                        $responseBody = is_string($body) ? substr($body, 0, 200) : json_encode($body);
                        error_log("TraceKit: ✅ Traces sent successfully (fire-and-forget) - Service: {$serviceName}, Spans: {$spanCount}, HTTP: {$responseCode}");
                        
                        if ($responseCode >= 400) {
                            error_log("TraceKit: Warning - HTTP {$responseCode} response from TraceKit. Response: {$responseBody}");
                        }
                    }
                });
            
            // Fire and forget - this sends HTTP response first, then executes in background
            $asyncCall->fireAndForget();
            
        } catch (\Throwable $e) {
            // Silently fail - don't let TraceKit break your app
            error_log("TraceKit: Error sending traces: " . $e->getMessage());
        }
    }
}

