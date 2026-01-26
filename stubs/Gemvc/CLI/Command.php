<?php

namespace Gemvc\CLI;

class Command
{
    /**
     * @param array<int, string> $args
     * @param array<string, mixed> $options
     */
    public function __construct(array $args = [], array $options = [])
    {
    }

    public function execute(): bool
    {
        return true;
    }

    /**
     * @param string $message
     * @param string $color
     */
    protected function write(string $message, string $color = 'white'): void
    {
    }

    /**
     * @param string $message
     */
    protected function info(string $message): void
    {
    }

    /**
     * @param string $message
     */
    protected function error(string $message): void
    {
    }

    /**
     * @param string $message
     */
    protected function warning(string $message): void
    {
    }
}
