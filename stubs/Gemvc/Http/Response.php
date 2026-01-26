<?php

namespace Gemvc\Http;

class Response
{
    /**
     * @param array<string, mixed>|null $data
     * @param int $code
     * @param string $message
     */
    public static function success(?array $data = null, int $code = 1, string $message = ''): JsonResponse
    {
        return new JsonResponse();
    }
}
