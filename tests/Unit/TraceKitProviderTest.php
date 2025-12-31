<?php
namespace Gemvc\Core\Apm\Providers\TraceKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Gemvc\Core\Apm\Providers\TraceKit\TraceKitProvider;
use Gemvc\Core\Apm\Providers\TraceKit\Tests\Helpers\MockRequest;

class TraceKitProviderTest extends TestCase
{
    protected function tearDown(): void
    {
        // Clean up environment variables
        unset($_ENV['TRACEKIT_API_KEY'], $_ENV['APM_API_KEY']);
        unset($_ENV['TRACEKIT_SERVICE_NAME'], $_ENV['TRACEKIT_ENDPOINT']);
        unset($_ENV['TRACEKIT_ENABLED'], $_ENV['TRACEKIT_SAMPLE_RATE']);
        unset($_ENV['TRACEKIT_TRACE_RESPONSE'], $_ENV['TRACEKIT_TRACE_DB_QUERY']);
        unset($_ENV['TRACEKIT_TRACE_REQUEST_BODY']);
        unset($_ENV['APM_NAME'], $_ENV['APM_ENABLED'], $_ENV['APM_SAMPLE_RATE']);
        
        // Clear static instance
        TraceKitProvider::clearCurrentInstance();
        
        parent::tearDown();
    }
    
    public function testConstructorLoadsConfiguration(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        $_ENV['TRACEKIT_SERVICE_NAME'] = 'test-service';
        
        $provider = new TraceKitProvider();
        
        $this->assertTrue($provider->isEnabled());
        
        $reflection = new \ReflectionClass($provider);
        $apiKeyProperty = $reflection->getProperty('apiKey');
        $apiKeyProperty->setAccessible(true);
        
        $this->assertEquals('test-api-key', $apiKeyProperty->getValue($provider));
    }
    
    public function testConstructorWithRequest(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $request = new MockRequest('GET', '/test');
        $provider = new TraceKitProvider($request);
        
        $this->assertTrue($provider->isEnabled());
        $this->assertSame($provider, $request->apm);
    }
    
    public function testIsEnabledReturnsFalseWhenNoApiKey(): void
    {
        unset($_ENV['TRACEKIT_API_KEY'], $_ENV['APM_API_KEY']);
        
        $provider = new TraceKitProvider();
        
        $this->assertFalse($provider->isEnabled());
    }
    
    public function testIsEnabledReturnsFalseWhenDisabled(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        $_ENV['TRACEKIT_ENABLED'] = 'false';
        
        $provider = new TraceKitProvider();
        
        $this->assertFalse($provider->isEnabled());
    }
    
    public function testInitMethod(): void
    {
        $provider = new TraceKitProvider(null, ['enabled' => false, 'api_key' => 'test-key']);
        $this->assertFalse($provider->isEnabled());
        
        $result = $provider->init(['enabled' => true, 'api_key' => 'test-key']);
        $this->assertTrue($result);
        $this->assertTrue($provider->isEnabled());
    }
    
    public function testInitMethodWithEnvironmentVariables(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'env-key';
        $_ENV['TRACEKIT_SERVICE_NAME'] = 'env-service';
        
        $provider = new TraceKitProvider();
        
        $reflection = new \ReflectionClass($provider);
        $apiKeyProperty = $reflection->getProperty('apiKey');
        $serviceNameProperty = $reflection->getProperty('serviceName');
        $apiKeyProperty->setAccessible(true);
        $serviceNameProperty->setAccessible(true);
        
        $this->assertEquals('env-key', $apiKeyProperty->getValue($provider));
        $this->assertEquals('env-service', $serviceNameProperty->getValue($provider));
    }
    
    public function testStartSpanReturnsEmptyWhenDisabled(): void
    {
        $provider = new TraceKitProvider(null, ['enabled' => false, 'api_key' => 'key']);
        
        $span = $provider->startSpan('test-operation');
        
        $this->assertEmpty($span);
    }
    
    public function testStartSpanCreatesSpanWhenEnabled(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $provider = new TraceKitProvider();
        
        $span = $provider->startSpan('test-operation', ['test.attr' => 'value']);
        
        $this->assertNotEmpty($span);
        $this->assertArrayHasKey('span_id', $span);
        $this->assertArrayHasKey('trace_id', $span);
        $this->assertArrayHasKey('start_time', $span);
        $this->assertIsString($span['span_id']);
        $this->assertIsString($span['trace_id']);
        $this->assertIsInt($span['start_time']);
    }
    
    public function testStartSpanWithParent(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $request = new MockRequest();
        $provider = new TraceKitProvider($request);
        
        // Start root span first
        $rootSpan = $provider->startSpan('root-operation');
        
        // Start child span
        $childSpan = $provider->startSpan('child-operation');
        
        $this->assertNotEmpty($childSpan);
        $this->assertEquals($rootSpan['trace_id'], $childSpan['trace_id']);
    }
    
    public function testEndSpan(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $provider = new TraceKitProvider();
        
        $span = $provider->startSpan('test-operation');
        $this->assertNotEmpty($span);
        
        // End span should not throw
        $provider->endSpan($span, ['final.attr' => 'value'], TraceKitProvider::STATUS_OK);
        
        // Verify span was ended (check internal state via reflection)
        $reflection = new \ReflectionClass($provider);
        $spansProperty = $reflection->getProperty('spans');
        $spansProperty->setAccessible(true);
        $spans = $spansProperty->getValue($provider);
        
        $this->assertNotEmpty($spans);
        $spanData = $spans[0];
        $this->assertArrayHasKey('end_time', $spanData);
        $this->assertArrayHasKey('duration', $spanData);
    }
    
    public function testEndSpanWithErrorStatus(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $provider = new TraceKitProvider();
        
        $span = $provider->startSpan('test-operation');
        $provider->endSpan($span, [], TraceKitProvider::STATUS_ERROR);
        
        $reflection = new \ReflectionClass($provider);
        $spansProperty = $reflection->getProperty('spans');
        $spansProperty->setAccessible(true);
        $spans = $spansProperty->getValue($provider);
        
        $this->assertEquals(TraceKitProvider::STATUS_ERROR, $spans[0]['status']);
    }
    
    public function testRecordException(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $provider = new TraceKitProvider();
        
        $span = $provider->startSpan('test-operation');
        $exception = new \RuntimeException('Test exception', 500);
        
        $result = $provider->recordException($span, $exception);
        
        $this->assertNotEmpty($result);
        $this->assertEquals($span['span_id'], $result['span_id']);
    }
    
    public function testRecordExceptionWithEmptySpanDataUsesRootSpan(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $request = new MockRequest();
        $provider = new TraceKitProvider($request);
        
        // Root span should be created automatically
        $exception = new \RuntimeException('Test exception', 500);
        
        $result = $provider->recordException([], $exception);
        
        $this->assertNotEmpty($result);
    }
    
    public function testFlush(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $provider = new TraceKitProvider();
        
        $span = $provider->startSpan('test-operation');
        $provider->endSpan($span);
        
        // Flush should not throw
        $provider->flush();
        
        // Verify spans were cleared
        $reflection = new \ReflectionClass($provider);
        $spansProperty = $reflection->getProperty('spans');
        $spansProperty->setAccessible(true);
        $spans = $spansProperty->getValue($provider);
        
        $this->assertEmpty($spans);
    }
    
    public function testFlushWhenDisabled(): void
    {
        $provider = new TraceKitProvider(null, ['enabled' => false, 'api_key' => 'key']);
        
        // Should not throw
        $provider->flush();
    }
    
    public function testGetTraceId(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $provider = new TraceKitProvider();
        
        $span = $provider->startSpan('test-operation');
        
        $traceId = $provider->getTraceId();
        
        $this->assertNotNull($traceId);
        $this->assertEquals($span['trace_id'], $traceId);
    }
    
    public function testGetCurrentInstance(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $provider = new TraceKitProvider();
        
        $this->assertSame($provider, TraceKitProvider::getCurrentInstance());
    }
    
    public function testClearCurrentInstance(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $provider = new TraceKitProvider();
        $this->assertSame($provider, TraceKitProvider::getCurrentInstance());
        
        TraceKitProvider::clearCurrentInstance();
        
        $this->assertNull(TraceKitProvider::getCurrentInstance());
    }
    
    public function testStartTrace(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $provider = new TraceKitProvider();
        
        $trace = $provider->startTrace('http-request', ['http.method' => 'GET']);
        
        $this->assertNotEmpty($trace);
        $this->assertArrayHasKey('span_id', $trace);
        $this->assertArrayHasKey('trace_id', $trace);
        $this->assertArrayHasKey('start_time', $trace);
    }
    
    public function testStartTraceWithForceSample(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        $_ENV['TRACEKIT_SAMPLE_RATE'] = '0.0'; // 0% sampling
        
        $provider = new TraceKitProvider();
        
        // Normal trace should be empty due to sampling
        $trace1 = $provider->startTrace('test', []);
        $this->assertEmpty($trace1);
        
        // Force sample should work
        $trace2 = $provider->startTrace('test', [], true);
        $this->assertNotEmpty($trace2);
    }
    
    public function testSpanKinds(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $provider = new TraceKitProvider();
        
        $span1 = $provider->startSpan('test', [], TraceKitProvider::SPAN_KIND_SERVER);
        $span2 = $provider->startSpan('test', [], TraceKitProvider::SPAN_KIND_CLIENT);
        $span3 = $provider->startSpan('test', [], TraceKitProvider::SPAN_KIND_INTERNAL);
        
        $this->assertNotEmpty($span1);
        $this->assertNotEmpty($span2);
        $this->assertNotEmpty($span3);
    }
    
    public function testInvalidSpanKindDefaultsToInternal(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $provider = new TraceKitProvider();
        
        // Invalid kind should default to INTERNAL
        $span = $provider->startSpan('test', [], 999);
        
        $this->assertNotEmpty($span);
        
        $reflection = new \ReflectionClass($provider);
        $spansProperty = $reflection->getProperty('spans');
        $spansProperty->setAccessible(true);
        $spans = $spansProperty->getValue($provider);
        
        $this->assertEquals(TraceKitProvider::SPAN_KIND_INTERNAL, $spans[0]['kind']);
    }
    
    public function testDetermineStatusFromHttpCode(): void
    {
        $this->assertEquals(TraceKitProvider::STATUS_OK, TraceKitProvider::determineStatusFromHttpCode(200));
        $this->assertEquals(TraceKitProvider::STATUS_OK, TraceKitProvider::determineStatusFromHttpCode(301));
        $this->assertEquals(TraceKitProvider::STATUS_ERROR, TraceKitProvider::determineStatusFromHttpCode(400));
        $this->assertEquals(TraceKitProvider::STATUS_ERROR, TraceKitProvider::determineStatusFromHttpCode(500));
    }
    
    public function testLimitStringForTracing(): void
    {
        $longString = str_repeat('a', 3000);
        $limited = TraceKitProvider::limitStringForTracing($longString);
        
        $this->assertLessThanOrEqual(2003, strlen($limited)); // 2000 + '...'
        $this->assertStringEndsWith('...', $limited);
    }
    
    public function testLimitStringForTracingWithShortString(): void
    {
        $shortString = 'short';
        $limited = TraceKitProvider::limitStringForTracing($shortString);
        
        $this->assertEquals($shortString, $limited);
    }
    
    public function testShouldTraceResponse(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        $_ENV['TRACEKIT_TRACE_RESPONSE'] = 'true';
        
        $provider = new TraceKitProvider();
        
        $this->assertTrue($provider->shouldTraceResponse());
    }
    
    public function testShouldTraceDbQuery(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        $_ENV['TRACEKIT_TRACE_DB_QUERY'] = 'true';
        
        $provider = new TraceKitProvider();
        
        $this->assertTrue($provider->shouldTraceDbQuery());
    }
    
    public function testShouldTraceRequestBody(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        $_ENV['TRACEKIT_TRACE_REQUEST_BODY'] = 'true';
        
        $provider = new TraceKitProvider();
        
        $this->assertTrue($provider->shouldTraceRequestBody());
    }
}

