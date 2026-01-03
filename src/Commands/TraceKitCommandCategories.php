<?php

namespace Gemvc\Core\Apm\Providers\TraceKit\Commands;

/**
 * TraceKit Command Categories
 * 
 * Defines command mappings for TraceKit CLI commands.
 * This should be integrated into GEMVC's CommandCategories class.
 */
class TraceKitCommandCategories
{
    public const CATEGORIES = [
        'APM' => [
            'tracekit:init' => 'Interactive setup wizard for TraceKit APM (registration or manual API key entry)',
            'tracekit:register' => 'Register service with TraceKit APM and receive verification code',
            'tracekit:verify' => 'Verify email code and configure TraceKit (updates .env automatically)',
            'tracekit:status' => 'Check TraceKit configuration and connection status',
            'tracekit:config' => 'Show or update TraceKit configuration in .env file',
            'tracekit:test' => 'Test TraceKit connection and send a test trace',
        ],
    ];

    public static function getCommandClass(string $command): string
    {
        $mappings = [
            'tracekit:init' => 'TraceKitInit',
            'tracekit:register' => 'TraceKitRegister',
            'tracekit:verify' => 'TraceKitVerify',
            'tracekit:status' => 'TraceKitStatus',
            'tracekit:config' => 'TraceKitConfig',
            'tracekit:config:set' => 'TraceKitConfig',
            'tracekit:test' => 'TraceKitTest',
        ];

        return $mappings[$command] ?? '';
    }

    public static function getCategory(string $command): string
    {
        foreach (self::CATEGORIES as $category => $commands) {
            if (isset($commands[$command])) {
                return $category;
            }
        }
        return 'Other';
    }

    public static function getDescription(string $command): string
    {
        foreach (self::CATEGORIES as $commands) {
            if (isset($commands[$command])) {
                return $commands[$command];
            }
        }
        return '';
    }

    /**
     * @return array<string, string|array<string>>
     */
    public static function getExamples(): array
    {
        return [
            'tracekit:init' => 'php vendor/bin/tracekit init',
            'tracekit:register' => [
                'php vendor/bin/tracekit register',
                'php vendor/bin/tracekit register user@example.com',
                'php vendor/bin/tracekit register user@example.com --org="My Company"',
            ],
            'tracekit:verify' => [
                'php vendor/bin/tracekit verify <session-id> <code>',
                'php vendor/bin/tracekit verify abc123 456789',
            ],
            'tracekit:status' => 'php vendor/bin/tracekit status',
            'tracekit:config' => [
                'php vendor/bin/tracekit config',
                'php vendor/bin/tracekit config:set TRACEKIT_SERVICE_NAME my-service',
            ],
            'tracekit:test' => 'php vendor/bin/tracekit test',
        ];
    }
}

