<?php

namespace Gemvc\Http;

class JsonResponse
{
    public int $response_code = 200;
    
    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = null;
    
    public string $message = '';
}
