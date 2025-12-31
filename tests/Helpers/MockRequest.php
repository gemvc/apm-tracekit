<?php
namespace Gemvc\Core\Apm\Providers\TraceKit\Tests\Helpers;

use Gemvc\Http\Request;

/**
 * Mock Request class for testing
 * 
 * This mock implements the methods needed by AbstractApm and TraceKit providers
 */
class MockRequest extends Request
{
    private string $method = 'GET';
    private string $uri = '/';
    private string $serviceName = 'test';
    private string $methodName = 'index';
    
    public function __construct(
        string $method = 'GET',
        string $uri = '/',
        array $headers = [],
        array $body = []
    ) {
        $this->method = $method;
        $this->uri = $uri;
        
        // Set headers (parent class expects ?array)
        if (!empty($headers)) {
            $this->headers = $headers;
        }
        
        // Set body data based on method
        if ($method === 'POST') {
            $this->post = $body;
        } elseif ($method === 'PUT') {
            $this->put = $body;
        } elseif ($method === 'PATCH') {
            $this->patch = $body;
        }
    }
    
    public function getMethod(): string
    {
        return $this->method;
    }
    
    public function getUri(): string
    {
        return $this->uri;
    }
    
    public function getHeader(string $name): ?string
    {
        if ($this->headers === null) {
            return null;
        }
        
        $name = strtolower($name);
        foreach ($this->headers as $key => $value) {
            if (strtolower($key) === $name) {
                return $value;
            }
        }
        return null;
    }
    
    public function getServiceName(): string
    {
        return $this->serviceName;
    }
    
    public function getMethodName(): string
    {
        return $this->methodName;
    }
    
    public function setServiceName(string $name): void
    {
        $this->serviceName = $name;
    }
    
    public function setMethodName(string $name): void
    {
        $this->methodName = $name;
    }
}

