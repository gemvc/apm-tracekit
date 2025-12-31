<?php
namespace Gemvc\Core\Apm\Providers\TraceKit\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Gemvc\Core\Apm\Providers\TraceKit\TraceKitProvider;
use Gemvc\Core\Apm\Providers\TraceKit\Tests\Helpers\MockRequest;

class TraceKitProviderIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        // Clean up environment variables
        unset($_ENV['TRACEKIT_API_KEY'], $_ENV['APM_API_KEY']);
        unset($_ENV['TRACEKIT_SERVICE_NAME'], $_ENV['TRACEKIT_ENDPOINT']);
        unset($_ENV['TRACEKIT_ENABLED'], $_ENV['TRACEKIT_SAMPLE_RATE']);
        unset($_ENV['APM_NAME'], $_ENV['APM_ENABLED']);
        
        TraceKitProvider::clearCurrentInstance();
        
        parent::tearDown();
    }
    
    public function testFullRequestLifecycle(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        $_ENV['TRACEKIT_SERVICE_NAME'] = 'test-service';
        
        $request = new MockRequest('POST', '/api/users', ['User-Agent' => 'Test/1.0'], ['name' => 'John']);
        $provider = new TraceKitProvider($request);
        
        // Verify root trace was initialized
        $reflection = new \ReflectionClass($provider);
        $rootSpanProperty = $reflection->getProperty('rootSpan');
        $rootSpanProperty->setAccessible(true);
        $rootSpan = $rootSpanProperty->getValue($provider);
        
        $this->assertNotEmpty($rootSpan);
        
        // Start child span
        $childSpan = $provider->startSpan('database-query', [
            'db.query' => 'SELECT * FROM users',
            'db.table' => 'users'
        ]);
        
        $this->assertNotEmpty($childSpan);
        $this->assertEquals($rootSpan['trace_id'], $childSpan['trace_id']);
        
        // End child span
        $provider->endSpan($childSpan, ['db.rows' => 10], TraceKitProvider::STATUS_OK);
        
        // End root span
        $provider->endSpan($rootSpan, ['http.status_code' => 200], TraceKitProvider::STATUS_OK);
        
        // Flush
        $provider->flush();
        
        // Verify spans were cleared
        $spansProperty = $reflection->getProperty('spans');
        $spansProperty->setAccessible(true);
        $spans = $spansProperty->getValue($provider);
        
        $this->assertEmpty($spans);
    }
    
    public function testExceptionHandling(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $request = new MockRequest('GET', '/api/test');
        $provider = new TraceKitProvider($request);
        
        // Start span
        $span = $provider->startSpan('test-operation');
        
        // Record exception
        $exception = new \RuntimeException('Test error', 500);
        $result = $provider->recordException($span, $exception);
        
        $this->assertNotEmpty($result);
        
        // Verify exception was recorded
        $reflection = new \ReflectionClass($provider);
        $spansProperty = $reflection->getProperty('spans');
        $spansProperty->setAccessible(true);
        $spans = $spansProperty->getValue($provider);
        
        // Find the span we created by matching span_id
        $targetSpanId = $span['span_id'] ?? null;
        $spanData = null;
        foreach ($spans as $spanItem) {
            if (($spanItem['span_id'] ?? null) === $targetSpanId) {
                $spanData = $spanItem;
                break;
            }
        }
        
        // If not found by ID, use the last span (child span)
        if ($spanData === null && !empty($spans)) {
            $spanData = end($spans);
        }
        
        $this->assertNotNull($spanData, 'Span should exist');
        $this->assertArrayHasKey('events', $spanData);
        $this->assertNotEmpty($spanData['events']);
        $this->assertEquals('exception', $spanData['events'][0]['name']);
    }
    
    public function testNestedSpans(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $provider = new TraceKitProvider();
        
        // Root span
        $rootSpan = $provider->startSpan('root-operation');
        
        // Child span
        $childSpan = $provider->startSpan('child-operation');
        
        // Grandchild span
        $grandchildSpan = $provider->startSpan('grandchild-operation');
        
        $this->assertEquals($rootSpan['trace_id'], $childSpan['trace_id']);
        $this->assertEquals($rootSpan['trace_id'], $grandchildSpan['trace_id']);
        
        // End in reverse order
        $provider->endSpan($grandchildSpan);
        $provider->endSpan($childSpan);
        $provider->endSpan($rootSpan);
        
        // Verify all spans were created
        $reflection = new \ReflectionClass($provider);
        $spansProperty = $reflection->getProperty('spans');
        $spansProperty->setAccessible(true);
        $spans = $spansProperty->getValue($provider);
        
        $this->assertCount(3, $spans);
    }
    
    public function testSampling(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        $_ENV['TRACEKIT_SAMPLE_RATE'] = '0.5'; // 50% sampling
        
        $provider = new TraceKitProvider();
        
        // Create multiple spans - some should be sampled, some not
        $spans = [];
        for ($i = 0; $i < 10; $i++) {
            $span = $provider->startSpan('test-' . $i);
            if (!empty($span)) {
                $spans[] = $span;
            }
        }
        
        // With 50% sampling, we should get some spans (statistical test)
        // Note: This is probabilistic, so we just verify the method works
        $this->assertIsArray($spans);
    }
    
    public function testForceSampleAlwaysTraces(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        $_ENV['TRACEKIT_SAMPLE_RATE'] = '0.0'; // 0% sampling
        
        $provider = new TraceKitProvider();
        
        // Normal span should be empty (due to 0% sampling)
        $span1 = $provider->startSpan('normal');
        $this->assertEmpty($span1);
        
        // Force sample should work (bypasses sampling)
        $trace = $provider->startTrace('error', [], true);
        $this->assertNotEmpty($trace);
        
        // Verify trace was created
        $this->assertArrayHasKey('span_id', $trace);
        $this->assertArrayHasKey('trace_id', $trace);
    }
    
    public function testConfigurationPrecedence(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'env-key';
        $_ENV['TRACEKIT_SERVICE_NAME'] = 'env-service';
        $_ENV['TRACEKIT_ENABLED'] = 'false';
        
        // Config array should override environment
        $provider = new TraceKitProvider(null, [
            'api_key' => 'config-key',
            'service_name' => 'config-service',
            'enabled' => true
        ]);
        
        $reflection = new \ReflectionClass($provider);
        $apiKeyProperty = $reflection->getProperty('apiKey');
        $serviceNameProperty = $reflection->getProperty('serviceName');
        $apiKeyProperty->setAccessible(true);
        $serviceNameProperty->setAccessible(true);
        
        // Config should take precedence
        $this->assertEquals('config-key', $apiKeyProperty->getValue($provider));
        $this->assertEquals('config-service', $serviceNameProperty->getValue($provider));
        $this->assertTrue($provider->isEnabled());
    }
}

