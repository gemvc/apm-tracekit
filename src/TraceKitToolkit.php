<?php
namespace Gemvc\Core\Apm\Providers\TraceKit;

use Gemvc\Core\Apm\AbstractApmToolkit;
use Gemvc\Http\AsyncApiCall;
use Gemvc\Http\JsonResponse;
use Gemvc\Http\Response;

/**
 * TraceKit Toolkit - Client-Side Integration & Management
 * 
 * Provides full control over TraceKit service integration using TraceKit REST API.
 * This class handles account registration, health monitoring, metrics, alerts, and webhooks.
 * 
 * Features:
 * - Account registration and email verification
 * - Health check monitoring (heartbeats)
 * - Service status and metrics
 * - Alert management
 * - Webhook management
 * - Subscription & billing info
 * 
 * API Documentation: https://app.tracekit.dev/docs/integration/api
 * 
 * @package Gemvc\Core\Apm\Providers\TraceKit
 */
class TraceKitToolkit extends AbstractApmToolkit
{
    /**
     * Default TraceKit base URL (library constant)
     * 
     * This is the default base URL used by the library. It can be overridden
     * via TRACEKIT_BASE_URL environment variable.
     * 
     * @var string
     */
    private const DEFAULT_BASE_URL = 'https://app.tracekit.dev';
    
    /**
     * Get provider-specific API key environment variable name
     * 
     * @return string|null Provider-specific env var name, or null to use only APM_API_KEY
     */
    protected function getProviderApiKeyEnvName(): ?string
    {
        return 'TRACEKIT_API_KEY';
    }
    
    /**
     * Get provider-specific base URL environment variable name
     * 
     * @return string|null Provider-specific env var name, or null to use default
     */
    protected function getProviderBaseUrlEnvName(): ?string
    {
        return 'TRACEKIT_BASE_URL';
    }
    
    /**
     * Get provider-specific service name environment variable name
     * 
     * @return string|null Provider-specific env var name, or null to use default
     */
    protected function getProviderServiceNameEnvName(): ?string
    {
        return 'TRACEKIT_SERVICE_NAME';
    }
    
    /**
     * Get default base URL for the provider
     * 
     * @return string Default base URL
     */
    protected function getDefaultBaseUrl(): string
    {
        return self::DEFAULT_BASE_URL;
    }
    
    // ==========================================
    // Abstract Endpoint Methods
    // ==========================================
    
    /**
     * Get registration endpoint
     * 
     * @return string Endpoint path
     */
    protected function getRegisterEndpoint(): string
    {
        return '/v1/integrate/register';
    }
    
    /**
     * Get verification endpoint
     * 
     * @return string Endpoint path
     */
    protected function getVerifyEndpoint(): string
    {
        return '/v1/integrate/verify';
    }
    
    /**
     * Get status endpoint
     * 
     * @return string Endpoint path
     */
    protected function getStatusEndpoint(): string
    {
        return '/v1/integrate/status';
    }
    
    /**
     * Get heartbeat endpoint
     * 
     * @return string Endpoint path
     */
    protected function getHeartbeatEndpoint(): string
    {
        return '/v1/health/heartbeat';
    }
    
    /**
     * Get health checks endpoint
     * 
     * @return string Endpoint path
     */
    protected function getHealthChecksEndpoint(): string
    {
        return '/api/health-checks';
    }
    
    /**
     * Get metrics endpoint
     * 
     * @return string Endpoint path (with {serviceName} placeholder)
     */
    protected function getMetricsEndpoint(): string
    {
        return '/api/metrics/services/{serviceName}';
    }
    
    /**
     * Get alerts summary endpoint
     * 
     * @return string Endpoint path
     */
    protected function getAlertsSummaryEndpoint(): string
    {
        return '/v1/alerts/summary';
    }
    
    /**
     * Get active alerts endpoint
     * 
     * @return string Endpoint path
     */
    protected function getActiveAlertsEndpoint(): string
    {
        return '/v1/alerts/active';
    }
    
    /**
     * Get webhooks endpoint
     * 
     * @return string Endpoint path
     */
    protected function getWebhooksEndpoint(): string
    {
        return '/v1/webhooks';
    }
    
    /**
     * Get subscription endpoint
     * 
     * @return string Endpoint path
     */
    protected function getSubscriptionEndpoint(): string
    {
        return '/v1/billing/subscription';
    }
    
    /**
     * Get plans endpoint
     * 
     * @return string Endpoint path
     */
    protected function getPlansEndpoint(): string
    {
        return '/v1/billing/plans';
    }
    
    /**
     * Get checkout session endpoint
     * 
     * @return string Endpoint path
     */
    protected function getCheckoutSessionEndpoint(): string
    {
        return '/v1/billing/create-checkout-session';
    }
    
    // ==========================================
    // Override Methods for TraceKit-Specific Behavior
    // ==========================================
    
    /**
     * Override verifyCode to update API key from response
     * 
     * @param string $sessionId Session ID from registerService()
     * @param string $code Verification code from email
     * @return JsonResponse
     */
    public function verifyCode(string $sessionId, string $code): JsonResponse
    {
        $response = parent::verifyCode($sessionId, $code);
        
        // Update API key if provided in response
        if ($response->response_code === 200 && is_array($response->data)) {
            if (isset($response->data['api_key']) && is_string($response->data['api_key'])) {
                $this->apiKey = $response->data['api_key'];
            }
        }
        
        return $response;
    }
    
    /**
     * Override sendHeartbeatAsync to use TraceKit-specific endpoint
     * 
     * @param string $status Service status
     * @param array<string, mixed> $metadata Optional metadata
     * @return void
     */
    public function sendHeartbeatAsync(string $status = 'healthy', array $metadata = []): void
    {
        if (empty($this->apiKey)) {
            // Silently fail if no API key - don't log errors for missing config
            return;
        }
        
        try {
            $async = new AsyncApiCall();
            $async->setTimeouts(1, 3); // Short timeouts for heartbeats
            
            $payload = [
                'service_name' => $this->serviceName,
                'status' => $status,
            ];
            
            if (!empty($metadata)) {
                $payload['metadata'] = $metadata;
            }
            
            $headers = [
                'X-API-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ];
            
            $async->addPost('heartbeat', $this->baseUrl . $this->getHeartbeatEndpoint(), $payload, $headers)
                  ->onResponse('heartbeat', function($result, $id) {
                      if (!$result['success']) {
                          error_log("TraceKit: Heartbeat failed: " . ($result['error'] ?? 'Unknown error'));
                      }
                  })
                  ->fireAndForget();
        } catch (\Throwable $e) {
            // Silently fail - don't let heartbeat errors break the application
            error_log("TraceKit: Heartbeat error: " . $e->getMessage());
        }
    }
    /**
     * Override registerService to customize success message
     * 
     * @param string $email Email address for verification
     * @param string|null $organizationName Optional organization name
     * @param string $source Partner/framework code (default: 'gemvc')
     * @param array<string, mixed> $sourceMetadata Optional metadata (version, environment, etc.)
     * @return JsonResponse
     */
    public function registerService(
        string $email,
        ?string $organizationName = null,
        string $source = 'gemvc',
        array $sourceMetadata = []
    ): JsonResponse {
        $response = parent::registerService($email, $organizationName, $source, $sourceMetadata);
        
        // Override success message for registration
        if ($response->response_code === 200) {
            return Response::success($response->data, 1, 'Verification code sent to email');
        }
        
        return $response;
    }
}

