<?php

namespace Gemvc\Http;

/**
 * @property-read \Gemvc\Core\Apm\ApmInterface|null $apm
 */
class Request
{
    /**
     * @var array<string, string>|null
     */
    public $headers = null;
    
    /**
     * @var array<string, mixed>
     */
    public $post = [];
    
    /**
     * @var array<string, mixed>
     */
    public $put = [];
    
    /**
     * @var array<string, mixed>
     */
    public $patch = [];

    public function getServiceName(): string
    {
        return '';
    }

    public function getMethodName(): string
    {
        return '';
    }

    public function getMethod(): string
    {
        return '';
    }

    public function getUri(): string
    {
        return '';
    }

    public function getHeader(string $name): ?string
    {
        return null;
    }
}
