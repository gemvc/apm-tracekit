<?php

namespace Gemvc\Helper;

class ProjectHelper
{
    public static function isDevEnvironment(): bool
    {
        return false;
    }

    public static function loadEnv(): void
    {
    }

    public static function getVersion(): string
    {
        return '';
    }

    public static function updateEnvVariables(array $vars): bool
    {
        return false;
    }
}
