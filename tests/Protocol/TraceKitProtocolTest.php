<?php
namespace Gemvc\Core\Apm\Providers\TraceKit\Tests\Protocol;

use PHPUnit\Framework\TestCase;
use Gemvc\Core\Apm\ApmFactory;
use Gemvc\Core\Apm\Providers\TraceKit\TraceKitProvider;
use Gemvc\Core\Apm\Providers\TraceKit\Tests\Helpers\MockRequest;

/**
 * Protocol Tests - Verify TraceKitProvider works with ApmFactory
 * 
 * These tests verify that TraceKitProvider correctly implements
 * the APM contracts and works with the factory pattern.
 */
class TraceKitProtocolTest extends TestCase
{
    protected function tearDown(): void
    {
        // Clean up environment variables
        unset($_ENV['APM_NAME'], $_ENV['APM_ENABLED'], $_ENV['APM_API_KEY']);
        unset($_ENV['TRACEKIT_API_KEY'], $_ENV['TRACEKIT_SERVICE_NAME']);
        unset($_ENV['TRACEKIT_ENABLED'], $_ENV['TRACEKIT_SAMPLE_RATE']);
        
        TraceKitProvider::clearCurrentInstance();
        
        parent::tearDown();
    }
    
    public function testApmFactoryCreatesTraceKitProvider(): void
    {
        $_ENV['APM_NAME'] = 'TraceKit';
        $_ENV['APM_ENABLED'] = 'true';
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $request = new MockRequest();
        $apm = ApmFactory::create($request);
        
        $this->assertInstanceOf(TraceKitProvider::class, $apm);
        $this->assertTrue($apm->isEnabled());
    }
    
    public function testApmFactoryReturnsNullWhenDisabled(): void
    {
        $_ENV['APM_NAME'] = 'TraceKit';
        $_ENV['APM_ENABLED'] = 'false';
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $request = new MockRequest();
        $apm = ApmFactory::create($request);
        
        // Factory may return null or instance that's disabled
        if ($apm === null) {
            $this->assertNull($apm);
        } else {
            $this->assertFalse($apm->isEnabled());
        }
    }
    
    public function testApmFactoryReturnsNullWhenNoApiKey(): void
    {
        $_ENV['APM_NAME'] = 'TraceKit';
        $_ENV['APM_ENABLED'] = 'true';
        unset($_ENV['TRACEKIT_API_KEY'], $_ENV['APM_API_KEY']);
        
        $request = new MockRequest();
        $apm = ApmFactory::create($request);
        
        if ($apm !== null) {
            $this->assertFalse($apm->isEnabled());
        }
    }
    
    public function testApmFactoryIsEnabledCheck(): void
    {
        $_ENV['APM_NAME'] = 'TraceKit';
        $_ENV['APM_ENABLED'] = 'true';
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $enabled = ApmFactory::isEnabled();
        
        $this->assertEquals('TraceKit', $enabled);
    }
    
    public function testApmFactoryIsEnabledReturnsNullWhenDisabled(): void
    {
        $_ENV['APM_NAME'] = 'TraceKit';
        $_ENV['APM_ENABLED'] = 'false';
        
        $enabled = ApmFactory::isEnabled();
        
        $this->assertNull($enabled);
    }
    
    public function testProviderImplementsApmInterface(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $provider = new TraceKitProvider();
        
        // Verify all interface methods exist
        $this->assertTrue(method_exists($provider, 'init'));
        $this->assertTrue(method_exists($provider, 'isEnabled'));
        $this->assertTrue(method_exists($provider, 'startSpan'));
        $this->assertTrue(method_exists($provider, 'endSpan'));
        $this->assertTrue(method_exists($provider, 'recordException'));
        $this->assertTrue(method_exists($provider, 'shouldTraceResponse'));
        $this->assertTrue(method_exists($provider, 'shouldTraceDbQuery'));
        $this->assertTrue(method_exists($provider, 'shouldTraceRequestBody'));
        $this->assertTrue(method_exists($provider, 'getTraceId'));
        $this->assertTrue(method_exists($provider, 'flush'));
    }
    
    public function testProviderExtendsAbstractApm(): void
    {
        $provider = new TraceKitProvider();
        
        $this->assertInstanceOf(\Gemvc\Core\Apm\AbstractApm::class, $provider);
    }
    
    public function testRequestObjectIntegration(): void
    {
        $_ENV['TRACEKIT_API_KEY'] = 'test-api-key';
        
        $request = new MockRequest('GET', '/test');
        $provider = new TraceKitProvider($request);
        
        // Verify provider is stored in request
        $this->assertSame($provider, $request->apm);
        
        // Verify provider can access request
        $this->assertSame($request, $provider->getRequest());
    }
}

