<?php
namespace Gemvc\Core\Apm\Providers\TraceKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Gemvc\Core\Apm\Providers\TraceKit\TraceKitProvider;
use Gemvc\Core\Apm\Providers\TraceKit\Tests\Helpers\MockRequest;

/**
 * Behavior-focused tests - These tests verify actual behavior, not just that methods exist
 * 
 * These tests are designed to FIND ERRORS, not just fulfill code coverage.
 */
class TraceKitProviderBehaviorTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['TRACEKIT_API_KEY'], $_ENV['APM_API_KEY']);
        unset($_ENV['TRACEKIT_SAMPLE_RATE'], $_ENV['TRACEKIT_ENABLED']);
        TraceKitProvider::clearCurrentInstance();
        parent::tearDown();
    }
    
    /**
     * BEHAVIOR TEST: Verify span parent-child relationship is correct
     * 
     * This test verifies that child spans actually reference their parent span ID
     */
    public function testSpanParentChildRelationshipIsCorrect(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $provider = new TraceKitProvider();
        
        $parentSpan = $provider->startSpan('parent-operation');
        $parentSpanId = $parentSpan['span_id'];
        
        $childSpan = $provider->startSpan('child-operation');
        $childSpanId = $childSpan['span_id'];
        
        // Verify child span has correct parent reference
        $reflection = new \ReflectionClass($provider);
        $spansProperty = $reflection->getProperty('spans');
        $spansProperty->setAccessible(true);
        $spans = $spansProperty->getValue($provider);
        
        // Find child span in spans array
        $childSpanData = null;
        foreach ($spans as $span) {
            if (($span['span_id'] ?? null) === $childSpanId) {
                $childSpanData = $span;
                break;
            }
        }
        
        $this->assertNotNull($childSpanData, 'Child span should exist in spans array');
        $this->assertEquals($parentSpanId, $childSpanData['parent_span_id'] ?? null, 
            'Child span should have parent_span_id matching parent span_id');
    }
    
    /**
     * BEHAVIOR TEST: Verify trace ID is consistent across all spans in a trace
     */
    public function testTraceIdIsConsistentAcrossAllSpans(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $provider = new TraceKitProvider();
        
        $span1 = $provider->startSpan('operation-1');
        $span2 = $provider->startSpan('operation-2');
        $span3 = $provider->startSpan('operation-3');
        
        $traceId1 = $span1['trace_id'];
        $traceId2 = $span2['trace_id'];
        $traceId3 = $span3['trace_id'];
        
        // All spans should have the same trace ID
        $this->assertEquals($traceId1, $traceId2, 'Span 1 and 2 should have same trace ID');
        $this->assertEquals($traceId2, $traceId3, 'Span 2 and 3 should have same trace ID');
        $this->assertEquals($traceId1, $provider->getTraceId(), 'getTraceId() should return same trace ID');
    }
    
    /**
     * BEHAVIOR TEST: Verify sampling actually works - 0% should sample nothing
     */
    public function testSamplingAtZeroPercentSamplesNothing(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        $_ENV['TRACEKIT_SAMPLE_RATE'] = '0.0';
        
        $provider = new TraceKitProvider();
        
        // Create 10 spans - none should be sampled
        $sampledCount = 0;
        for ($i = 0; $i < 10; $i++) {
            $span = $provider->startSpan('test-' . $i);
            if (!empty($span)) {
                $sampledCount++;
            }
        }
        
        $this->assertEquals(0, $sampledCount, 'With 0% sampling, no spans should be created');
    }
    
    /**
     * BEHAVIOR TEST: Verify sampling at 100% samples everything
     */
    public function testSamplingAtHundredPercentSamplesEverything(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        $_ENV['TRACEKIT_SAMPLE_RATE'] = '1.0';
        
        $provider = new TraceKitProvider();
        
        // Create 10 spans - all should be sampled
        $sampledCount = 0;
        for ($i = 0; $i < 10; $i++) {
            $span = $provider->startSpan('test-' . $i);
            if (!empty($span)) {
                $sampledCount++;
            }
        }
        
        $this->assertEquals(10, $sampledCount, 'With 100% sampling, all spans should be created');
    }
    
    /**
     * BEHAVIOR TEST: Verify force sample bypasses sampling
     */
    public function testForceSampleBypassesSamplingRate(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        $_ENV['TRACEKIT_SAMPLE_RATE'] = '0.0'; // 0% sampling
        
        $provider = new TraceKitProvider();
        
        // Normal span should be empty
        $normalSpan = $provider->startSpan('normal');
        $this->assertEmpty($normalSpan, 'Normal span should be empty with 0% sampling');
        
        // Force sample should work
        $forcedSpan = $provider->startTrace('forced', [], true);
        $this->assertNotEmpty($forcedSpan, 'Force sample should bypass 0% sampling rate');
        $this->assertArrayHasKey('span_id', $forcedSpan);
        $this->assertArrayHasKey('trace_id', $forcedSpan);
    }
    
    /**
     * BEHAVIOR TEST: Verify exception is recorded with correct data
     */
    public function testExceptionIsRecordedWithCorrectData(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $provider = new TraceKitProvider();
        
        $span = $provider->startSpan('test-operation');
        $exception = new \RuntimeException('Test error message', 500);
        
        $result = $provider->recordException($span, $exception);
        
        // Verify exception was recorded
        $reflection = new \ReflectionClass($provider);
        $spansProperty = $reflection->getProperty('spans');
        $spansProperty->setAccessible(true);
        $spans = $spansProperty->getValue($provider);
        
        $spanData = $spans[0];
        
        // Verify events array exists and has exception event
        $this->assertArrayHasKey('events', $spanData, 'Span should have events array');
        $this->assertNotEmpty($spanData['events'], 'Events array should not be empty');
        
        $exceptionEvent = $spanData['events'][0];
        $this->assertEquals('exception', $exceptionEvent['name'] ?? null, 'Event name should be "exception"');
        $this->assertEquals('RuntimeException', $exceptionEvent['attributes']['exception.type'] ?? null, 
            'Exception type should be recorded');
        $this->assertEquals('Test error message', $exceptionEvent['attributes']['exception.message'] ?? null, 
            'Exception message should be recorded');
        $this->assertEquals(500, $exceptionEvent['attributes']['exception.code'] ?? null, 
            'Exception code should be recorded');
        
        // Verify span status is set to ERROR
        $this->assertEquals(TraceKitProvider::STATUS_ERROR, $spanData['status'] ?? null, 
            'Span status should be ERROR after exception');
    }
    
    /**
     * BEHAVIOR TEST: Verify endSpan calculates duration correctly
     */
    public function testEndSpanCalculatesDurationCorrectly(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $provider = new TraceKitProvider();
        
        $span = $provider->startSpan('test-operation');
        $startTime = $span['start_time'];
        
        // Wait a bit (microseconds)
        usleep(1000); // 1ms
        
        $provider->endSpan($span);
        
        $reflection = new \ReflectionClass($provider);
        $spansProperty = $reflection->getProperty('spans');
        $spansProperty->setAccessible(true);
        $spans = $spansProperty->getValue($provider);
        
        $spanData = $spans[0];
        
        // Verify end_time and duration are set
        $this->assertArrayHasKey('end_time', $spanData);
        $this->assertArrayHasKey('duration', $spanData);
        
        // Verify duration is positive
        $this->assertGreaterThan(0, $spanData['duration'], 'Duration should be positive');
        
        // Verify duration = end_time - start_time
        $calculatedDuration = $spanData['end_time'] - $startTime;
        $this->assertEquals($calculatedDuration, $spanData['duration'], 
            'Duration should equal end_time - start_time');
    }
    
    /**
     * BEHAVIOR TEST: Verify flush clears spans and trace ID
     */
    public function testFlushClearsSpansAndTraceId(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $provider = new TraceKitProvider();
        
        $span1 = $provider->startSpan('operation-1');
        $span2 = $provider->startSpan('operation-2');
        
        $traceId = $provider->getTraceId();
        $this->assertNotNull($traceId, 'Trace ID should exist before flush');
        
        $provider->endSpan($span1);
        $provider->endSpan($span2);
        $provider->flush();
        
        // Verify spans are cleared
        $reflection = new \ReflectionClass($provider);
        $spansProperty = $reflection->getProperty('spans');
        $spansProperty->setAccessible(true);
        $spans = $spansProperty->getValue($provider);
        
        $this->assertEmpty($spans, 'Spans array should be empty after flush');
        
        // Verify trace ID is cleared
        $this->assertNull($provider->getTraceId(), 'Trace ID should be null after flush');
    }
    
    /**
     * BEHAVIOR TEST: Verify configuration precedence (config > env > default)
     */
    public function testConfigurationPrecedenceIsCorrect(): void
    {
        // Set environment variables
        $_ENV['TRACEKIT_API_KEY'] = 'env-api-key';
        $_ENV['TRACEKIT_SERVICE_NAME'] = 'env-service';
        $_ENV['TRACEKIT_ENABLED'] = 'false';
        $_ENV['TRACEKIT_SAMPLE_RATE'] = '0.5';
        
        // Config array should override environment
        $provider = new TraceKitProvider(null, [
            'api_key' => 'config-api-key',
            'service_name' => 'config-service',
            'enabled' => true,
            'sample_rate' => 0.8
        ]);
        
        $reflection = new \ReflectionClass($provider);
        $apiKeyProperty = $reflection->getProperty('apiKey');
        $serviceNameProperty = $reflection->getProperty('serviceName');
        $enabledProperty = $reflection->getProperty('enabled');
        $sampleRateProperty = $reflection->getProperty('sampleRate');
        
        $apiKeyProperty->setAccessible(true);
        $serviceNameProperty->setAccessible(true);
        $enabledProperty->setAccessible(true);
        $sampleRateProperty->setAccessible(true);
        
        // Verify config values override env values
        $this->assertEquals('config-api-key', $apiKeyProperty->getValue($provider), 
            'Config api_key should override env TRACEKIT_API_KEY');
        $this->assertEquals('config-service', $serviceNameProperty->getValue($provider), 
            'Config service_name should override env TRACEKIT_SERVICE_NAME');
        $this->assertTrue($enabledProperty->getValue($provider), 
            'Config enabled should override env TRACEKIT_ENABLED');
        $this->assertEquals(0.8, $sampleRateProperty->getValue($provider), 
            'Config sample_rate should override env TRACEKIT_SAMPLE_RATE');
    }
    
    /**
     * BEHAVIOR TEST: Verify endSpan with empty span data doesn't crash
     */
    public function testEndSpanWithEmptyDataDoesNotCrash(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $provider = new TraceKitProvider();
        
        // Should not throw
        $provider->endSpan([], []);
        
        // Verify no spans were created
        $reflection = new \ReflectionClass($provider);
        $spansProperty = $reflection->getProperty('spans');
        $spansProperty->setAccessible(true);
        $spans = $spansProperty->getValue($provider);
        
        $this->assertEmpty($spans, 'No spans should be created when ending empty span');
    }
    
    /**
     * BEHAVIOR TEST: Verify recordException with empty span creates root span
     */
    public function testRecordExceptionWithEmptySpanCreatesRootSpan(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $request = new MockRequest('GET', '/test');
        $provider = new TraceKitProvider($request);
        
        // Record exception with empty span - should create root span
        $exception = new \RuntimeException('Test error', 500);
        $result = $provider->recordException([], $exception);
        
        $this->assertNotEmpty($result, 'recordException should return span data even with empty input');
        $this->assertArrayHasKey('span_id', $result);
        $this->assertArrayHasKey('trace_id', $result);
        
        // Verify root span was set
        $reflection = new \ReflectionClass($provider);
        $rootSpanProperty = $reflection->getProperty('rootSpan');
        $rootSpanProperty->setAccessible(true);
        $rootSpan = $rootSpanProperty->getValue($provider);
        
        $this->assertNotEmpty($rootSpan, 'Root span should be created for exception');
        $this->assertEquals($result['span_id'], $rootSpan['span_id'] ?? null, 
            'Returned span should match root span');
    }
    
    /**
     * BEHAVIOR TEST: Verify span stack is managed correctly (push/pop)
     */
    public function testSpanStackIsManagedCorrectly(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $provider = new TraceKitProvider();
        
        $span1 = $provider->startSpan('operation-1');
        $span2 = $provider->startSpan('operation-2');
        $span3 = $provider->startSpan('operation-3');
        
        // Verify stack has 3 spans
        $reflection = new \ReflectionClass($provider);
        $spanStackProperty = $reflection->getProperty('spanStack');
        $spanStackProperty->setAccessible(true);
        $spanStack = $spanStackProperty->getValue($provider);
        
        $this->assertCount(3, $spanStack, 'Span stack should have 3 spans');
        
        // End spans in reverse order
        $provider->endSpan($span3);
        $spanStack = $spanStackProperty->getValue($provider);
        $this->assertCount(2, $spanStack, 'Span stack should have 2 spans after ending span3');
        
        $provider->endSpan($span2);
        $spanStack = $spanStackProperty->getValue($provider);
        $this->assertCount(1, $spanStack, 'Span stack should have 1 span after ending span2');
        
        $provider->endSpan($span1);
        $spanStack = $spanStackProperty->getValue($provider);
        $this->assertEmpty($spanStack, 'Span stack should be empty after ending all spans');
    }
}

