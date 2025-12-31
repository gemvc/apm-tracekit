<?php
namespace Gemvc\Core\Apm\Providers\TraceKit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Gemvc\Core\Apm\Providers\TraceKit\TraceKitModel;
use Gemvc\Core\Apm\Providers\TraceKit\Tests\Helpers\MockRequest;

class TraceKitModelTest extends TestCase
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
        TraceKitModel::clearCurrentInstance();
        
        parent::tearDown();
    }
    
    public function testConstructorLoadsConfiguration(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        $_ENV['TRACEKIT_SERVICE_NAME'] = 'test-service';
        
        $model = new TraceKitModel();
        
        $this->assertTrue($model->isEnabled());
        
        $reflection = new \ReflectionClass($model);
        $apiKeyProperty = $reflection->getProperty('apiKey');
        $apiKeyProperty->setAccessible(true);
        
        $this->assertEquals('test-api-key', $apiKeyProperty->getValue($model));
    }
    
    public function testConstructorWithRequest(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $request = new MockRequest('GET', '/test');
        $model = new TraceKitModel($request);
        
        $this->assertTrue($model->isEnabled());
        $this->assertSame($model, $request->apm);
    }
    
    public function testIsEnabledReturnsFalseWhenNoApiKey(): void
    {
        unset($_ENV['TRACEKIT_API_KEY'], $_ENV['APM_API_KEY']);
        
        $model = new TraceKitModel();
        
        $this->assertFalse($model->isEnabled());
    }
    
    public function testInitMethod(): void
    {
        $model = new TraceKitModel(null, ['enabled' => false, 'api_key' => 'test-key']);
        $this->assertFalse($model->isEnabled());
        
        $result = $model->init(['enabled' => true, 'api_key' => 'test-key']);
        $this->assertTrue($result);
        $this->assertTrue($model->isEnabled());
    }
    
    public function testStartSpanReturnsEmptyWhenDisabled(): void
    {
        $model = new TraceKitModel(null, ['enabled' => false, 'api_key' => 'key']);
        
        $span = $model->startSpan('test-operation');
        
        $this->assertEmpty($span);
    }
    
    public function testStartSpanCreatesSpanWhenEnabled(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $model = new TraceKitModel();
        
        $span = $model->startSpan('test-operation', ['test.attr' => 'value']);
        
        $this->assertNotEmpty($span);
        $this->assertArrayHasKey('span_id', $span);
        $this->assertArrayHasKey('trace_id', $span);
        $this->assertArrayHasKey('start_time', $span);
    }
    
    public function testStartTrace(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $model = new TraceKitModel();
        
        $trace = $model->startTrace('http-request', ['http.method' => 'GET']);
        
        $this->assertNotEmpty($trace);
        $this->assertArrayHasKey('span_id', $trace);
        $this->assertArrayHasKey('trace_id', $trace);
        $this->assertArrayHasKey('start_time', $trace);
    }
    
    public function testEndSpan(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $model = new TraceKitModel();
        
        $span = $model->startSpan('test-operation');
        $this->assertNotEmpty($span);
        
        $model->endSpan($span, ['final.attr' => 'value'], TraceKitModel::STATUS_OK);
        
        $reflection = new \ReflectionClass($model);
        $spansProperty = $reflection->getProperty('spans');
        $spansProperty->setAccessible(true);
        $spans = $spansProperty->getValue($model);
        
        $this->assertNotEmpty($spans);
        $this->assertArrayHasKey('end_time', $spans[0]);
    }
    
    public function testRecordException(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $model = new TraceKitModel();
        
        $span = $model->startSpan('test-operation');
        $exception = new \RuntimeException('Test exception', 500);
        
        $result = $model->recordException($span, $exception);
        
        $this->assertNotEmpty($result);
    }
    
    public function testFlush(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $model = new TraceKitModel();
        
        $span = $model->startSpan('test-operation');
        $model->endSpan($span);
        
        $model->flush();
        
        $reflection = new \ReflectionClass($model);
        $spansProperty = $reflection->getProperty('spans');
        $spansProperty->setAccessible(true);
        $spans = $spansProperty->getValue($model);
        
        $this->assertEmpty($spans);
    }
    
    public function testGetTraceId(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $model = new TraceKitModel();
        
        $span = $model->startSpan('test-operation');
        
        $traceId = $model->getTraceId();
        
        $this->assertNotNull($traceId);
        $this->assertEquals($span['trace_id'], $traceId);
    }
    
    public function testGetCurrentInstance(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $model = new TraceKitModel();
        
        $this->assertSame($model, TraceKitModel::getCurrentInstance());
    }
    
    public function testClearCurrentInstance(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $model = new TraceKitModel();
        $this->assertSame($model, TraceKitModel::getCurrentInstance());
        
        TraceKitModel::clearCurrentInstance();
        
        $this->assertNull(TraceKitModel::getCurrentInstance());
    }
    
    public function testDetermineStatusFromHttpCode(): void
    {
        $this->assertEquals(TraceKitModel::STATUS_OK, TraceKitModel::determineStatusFromHttpCode(200));
        $this->assertEquals(TraceKitModel::STATUS_ERROR, TraceKitModel::determineStatusFromHttpCode(400));
        $this->assertEquals(TraceKitModel::STATUS_ERROR, TraceKitModel::determineStatusFromHttpCode(500));
    }
    
    public function testLimitStringForTracing(): void
    {
        $longString = str_repeat('a', 3000);
        $limited = TraceKitModel::limitStringForTracing($longString);
        
        $this->assertLessThanOrEqual(2003, strlen($limited));
        $this->assertStringEndsWith('...', $limited);
    }
}

