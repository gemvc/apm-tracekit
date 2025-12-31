<?php
namespace Gemvc\Core\Apm\Providers\TraceKit\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Gemvc\Core\Apm\Providers\TraceKit\TraceKitModel;
use Gemvc\Core\Apm\Providers\TraceKit\Tests\Helpers\MockRequest;

class TraceKitModelIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        // Clean up environment variables
        unset($_ENV['TRACEKIT_API_KEY'], $_ENV['APM_API_KEY']);
        unset($_ENV['TRACEKIT_SERVICE_NAME'], $_ENV['TRACEKIT_ENDPOINT']);
        unset($_ENV['TRACEKIT_ENABLED'], $_ENV['TRACEKIT_SAMPLE_RATE']);
        unset($_ENV['APM_NAME'], $_ENV['APM_ENABLED']);
        
        TraceKitModel::clearCurrentInstance();
        
        parent::tearDown();
    }
    
    public function testFullRequestLifecycle(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        $_ENV['TRACEKIT_SERVICE_NAME'] = 'test-service';
        
        $request = new MockRequest('POST', '/api/users', ['User-Agent' => 'Test/1.0'], ['name' => 'John']);
        $model = new TraceKitModel($request);
        
        // Verify root trace was initialized
        $reflection = new \ReflectionClass($model);
        $rootSpanProperty = $reflection->getProperty('rootSpan');
        $rootSpanProperty->setAccessible(true);
        $rootSpan = $rootSpanProperty->getValue($model);
        
        $this->assertNotEmpty($rootSpan);
        
        // Start child span
        $childSpan = $model->startSpan('database-query', [
            'db.query' => 'SELECT * FROM users',
            'db.table' => 'users'
        ]);
        
        $this->assertNotEmpty($childSpan);
        $this->assertEquals($rootSpan['trace_id'], $childSpan['trace_id']);
        
        // End child span
        $model->endSpan($childSpan, ['db.rows' => 10], TraceKitModel::STATUS_OK);
        
        // End root span
        $model->endSpan($rootSpan, ['http.status_code' => 200], TraceKitModel::STATUS_OK);
        
        // Flush
        $model->flush();
        
        // Verify spans were cleared
        $spansProperty = $reflection->getProperty('spans');
        $spansProperty->setAccessible(true);
        $spans = $spansProperty->getValue($model);
        
        $this->assertEmpty($spans);
    }
    
    public function testExceptionHandling(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $request = new MockRequest('GET', '/api/test');
        $model = new TraceKitModel($request);
        
        // Start span
        $span = $model->startSpan('test-operation');
        
        // Record exception
        $exception = new \RuntimeException('Test error', 500);
        $result = $model->recordException($span, $exception);
        
        $this->assertNotEmpty($result);
        
        // Verify exception was recorded
        $reflection = new \ReflectionClass($model);
        $spansProperty = $reflection->getProperty('spans');
        $spansProperty->setAccessible(true);
        $spans = $spansProperty->getValue($model);
        
        // Find the span we created (not the root span)
        // If we have a request, spans[0] is the root span, spans[1] is our child span
        $spanData = null;
        if (!empty($spans)) {
            // Use the last span (child span) if we have a request, otherwise first span
            $spanData = count($spans) > 1 ? end($spans) : $spans[0];
        }
        
        $this->assertNotNull($spanData, 'Span should exist');
        // Events may be added directly to the span or in a separate events array
        // Check if events exist or if exception info is in attributes
        $hasEvents = isset($spanData['events']) && !empty($spanData['events']);
        $hasExceptionAttributes = isset($spanData['attributes']['error.type']) || 
                                   isset($spanData['attributes']['exception.type']);
        
        $this->assertTrue($hasEvents || $hasExceptionAttributes, 'Exception should be recorded in events or attributes');
    }
    
    public function testNestedSpans(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $model = new TraceKitModel();
        
        // Root span
        $rootSpan = $model->startSpan('root-operation');
        
        // Child span
        $childSpan = $model->startSpan('child-operation');
        
        // Grandchild span
        $grandchildSpan = $model->startSpan('grandchild-operation');
        
        $this->assertEquals($rootSpan['trace_id'], $childSpan['trace_id']);
        $this->assertEquals($rootSpan['trace_id'], $grandchildSpan['trace_id']);
        
        // End in reverse order
        $model->endSpan($grandchildSpan);
        $model->endSpan($childSpan);
        $model->endSpan($rootSpan);
        
        // Verify all spans were created
        $reflection = new \ReflectionClass($model);
        $spansProperty = $reflection->getProperty('spans');
        $spansProperty->setAccessible(true);
        $spans = $spansProperty->getValue($model);
        
        $this->assertCount(3, $spans);
    }
}

