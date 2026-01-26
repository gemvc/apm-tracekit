<?php

namespace Gemvc\Http;

class AsyncApiCall
{
    /**
     * @param int $connectTimeout
     * @param int $readTimeout
     */
    public function setTimeouts(int $connectTimeout, int $readTimeout): self
    {
        return $this;
    }

    /**
     * @param string $id
     * @param string $url
     * @param array<string, mixed> $data
     * @param array<string, string> $headers
     */
    public function addPost(string $id, string $url, array $data, array $headers): self
    {
        return $this;
    }

    /**
     * @param string $id
     * @param callable $callback
     */
    public function onResponse(string $id, callable $callback): self
    {
        return $this;
    }

    public function fireAndForget(): void
    {
    }
}
