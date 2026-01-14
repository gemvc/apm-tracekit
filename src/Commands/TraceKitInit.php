<?php

namespace Gemvc\Core\Apm\Providers\TraceKit\Commands;

use Gemvc\CLI\Command;
use Gemvc\CLI\Commands\CliBoxShow;
use Gemvc\Core\Apm\Providers\TraceKit\TraceKitToolkit;
use Gemvc\Helper\ProjectHelper;

/**
 * TraceKit Initialization Command
 * 
 * Interactive setup wizard for TraceKit APM integration.
 * Supports both registration flow (async email verification) and manual API key entry.
 * 
 * Usage: tracekit:init
 */
class TraceKitInit extends Command
{
    private CliBoxShow $box;
    private TraceKitToolkit $toolkit;
    private bool $nonInteractive = false;

    public function __construct(array $args = [], array $options = [])
    {
        parent::__construct($args, $options);
        $this->box = new CliBoxShow();
        $this->toolkit = new TraceKitToolkit();
        $this->nonInteractive = in_array('--non-interactive', $args) || in_array('-n', $args);
    }

    public function execute(): bool
    {
        // Step 1: Welcome Banner
        $this->displayWelcomeBanner();
        
        // Step 2: Check existing configuration
        $existingConfig = $this->checkExistingConfiguration();
        
        if ($existingConfig['configured']) {
            if (!$this->handleExistingConfiguration($existingConfig)) {
                return true; // User chose to keep existing or exit
            }
        }
        
        // Step 3: Choose setup method
        $setupMethod = $this->chooseSetupMethod();
        
        $apiKey = '';
        if ($setupMethod === 'register') {
            // Registration flow (async - waits for email verification)
            $apiKey = $this->handleRegistrationFlow();
            if (empty($apiKey)) {
                return false;
            }
        } else {
            // Manual API key entry
            $apiKey = $this->handleManualApiKeyEntry();
            if (empty($apiKey)) {
                return false;
            }
        }
        
        // Step 4: Service name setup (common for both flows)
        $serviceName = $this->handleServiceNameSetup();
        if (empty($serviceName)) {
            return false;
        }
        
        // Step 5: Save configuration
        if (!$this->saveConfiguration($apiKey, $serviceName)) {
            return false;
        }
        
        // Step 6: Test connection
        $this->testConnection();
        
        // Step 7: Final success message
        $this->displaySuccessMessage();
        
        return true;
    }

    /**
     * Step 1: Display welcome banner
     */
    private function displayWelcomeBanner(): void
    {
        $lines = [
            "\033[1;96m╔═══════════════════════════════════════════════════════╗\033[0m",
            "\033[1;96m║\033[0m        \033[1;92mWelcome to TraceKit APM Setup    \033[0m              \033[1;96m║\033[0m",
            "\033[1;96m╚═══════════════════════════════════════════════════════╝\033[0m",
            "",
            "\033[1;94mTraceKit\033[0m provides distributed tracing and performance",
            "monitoring for your GEMVC application.",
            "",
            "\033[1;92m✓ TraceKit is already installed and available!\033[0m",
            "  (Included automatically with gemvc/library)",
            "",
            "\033[1;93m⚡ Features:\033[0m",
            "  • OpenTelemetry OTLP format",
            "  • Batch trace sending (reliable & OpenSwoole compatible)",
            "  • Automatic span management",
            "  • Exception tracking",
            "  • Health monitoring",
            "",
            "\033[1;36mLet's configure TraceKit for your project!\033[0m"
        ];
        
        foreach ($lines as $line) {
            $this->write($line . "\n", 'white');
        }
        
        $this->write("\n");
    }

    /**
     * Check existing configuration
     */
    private function checkExistingConfiguration(): array
    {
        try {
            ProjectHelper::loadEnv();
        } catch (\Exception $e) {
            return ['configured' => false, 'error' => $e->getMessage()];
        }

        $apiKey = $_ENV['TRACEKIT_API_KEY'] ?? $_ENV['APM_API_KEY'] ?? null;
        $serviceName = $_ENV['TRACEKIT_SERVICE_NAME'] ?? $_ENV['APM_SERVICE_NAME'] ?? null;
        $enabled = $_ENV['APM_ENABLED'] ?? $_ENV['TRACEKIT_ENABLED'] ?? 'false';

        return [
            'configured' => !empty($apiKey),
            'apiKey' => $apiKey,
            'serviceName' => $serviceName,
            'enabled' => $enabled === 'true' || $enabled === '1',
        ];
    }

    /**
     * Handle existing configuration
     */
    private function handleExistingConfiguration(array $config): bool
    {
        $this->box->displayInfoBox('Existing Configuration Found', [
            "Service Name: " . ($config['serviceName'] ?? 'Not set'),
            "API Key: " . (substr($config['apiKey'] ?? '', 0, 8) . '...'),
            "Status: " . ($config['enabled'] ? 'Enabled' : 'Disabled'),
        ]);

        if ($this->nonInteractive) {
            $this->info("Non-interactive mode: keeping existing configuration");
            return false;
        }

        $this->write("\033[1;36mTraceKit is already configured. What would you like to do?\033[0m\n", 'blue');
        $this->write("  [\033[32m1\033[0m] Keep existing configuration\n");
        $this->write("  [\033[32m2\033[0m] Re-configure (will overwrite existing)\n");
        $this->write("  [\033[32m3\033[0m] Test connection\n");
        $this->write("  [\033[32m4\033[0m] Exit\n");
        $this->write("\033[1;36mEnter your choice (1-4) [1]:\033[0m ", 'blue');

        $handle = fopen("php://stdin", "r");
        if ($handle === false) {
            return false;
        }
        $line = fgets($handle);
        fclose($handle);
        $choice = $line !== false ? trim($line) : '1';

        switch ($choice) {
            case '2':
                $this->info("Proceeding with re-configuration...");
                return true; // Continue with setup flow
            case '3':
                $this->testConnection();
                return false; // Exit after test
            case '4':
                $this->info("Exiting. Your configuration remains unchanged.");
                return false; // Exit
            default:
                $this->info("Keeping existing configuration.");
                return false; // Exit
        }
    }

    /**
     * Step 2: Choose setup method
     */
    private function chooseSetupMethod(): string
    {
        $this->write("\n\033[1;94m┌──────────────────────────────────────────────────────┐\033[0m\n", 'white');
        $this->write("\033[1;94m│\033[0m             \033[1;96mChoose Setup Method\033[0m                      \033[1;94m│\033[0m\n", 'white');
        $this->write("\033[1;94m└──────────────────────────────────────────────────────┘\033[0m\n\n", 'white');
        
        $this->write("  \033[1;36m[1]\033[0m \033[1;97mEasy Register\033[0m - I don't have an API key\n", 'white');
        $this->write("      \033[90mTraceKit will send an API key to your email\033[0m\n\n", 'white');
        
        $this->write("  \033[1;36m[2]\033[0m \033[1;97mI have API key\033[0m - Enter your existing API key\n", 'white');
        $this->write("      \033[90mUse this if you already have a TraceKit API key\033[0m\n\n", 'white');
        
        // Get user input
        while (true) {
            $this->write("\033[1;36mEnter your choice (1-2) [1]:\033[0m ", 'white');
            
            $handle = fopen("php://stdin", "r");
            if ($handle === false) {
                $this->error("Failed to open stdin");
                return 'register';
            }
            
            $line = fgets($handle);
            fclose($handle);
            
            $choice = $line !== false ? trim($line) : '';
            
            // Default to register if empty
            if ($choice === '') {
                $choice = '1';
            }
            
            if ($choice === '1') {
                $this->info("Selected: Easy Register");
                return 'register';
            } elseif ($choice === '2') {
                $this->info("Selected: I have API key");
                return 'manual';
            }
            
            $this->warning("Invalid choice. Please enter 1 or 2.");
        }
    }

    /**
     * Step 3a: Handle registration flow (async - waits for email)
     */
    private function handleRegistrationFlow(): string
    {
        $this->box->displayInfoBox('Easy Register', [
            'TraceKit will send a verification code to your email',
            'You will need to enter the code to complete registration',
            ''
        ]);

        // Get email
        $email = $this->args[0] ?? null;
        if (empty($email)) {
            $this->write("\033[1;36mEnter your email address:\033[0m ", 'blue');
            $handle = fopen("php://stdin", "r");
            if ($handle === false) {
                $this->error("Failed to read input");
                return '';
            }
            $line = fgets($handle);
            fclose($handle);
            $email = $line !== false ? trim($line) : '';
        }

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email address");
            return '';
        }

        // Get organization name (optional)
        $orgName = null;
        if (!$this->nonInteractive) {
            $this->write("\033[1;36mOrganization name (optional, press Enter to skip):\033[0m ", 'blue');
            $handle = fopen("php://stdin", "r");
            if ($handle !== false) {
                $line = fgets($handle);
                fclose($handle);
                $orgName = $line !== false ? trim($line) : null;
                if ($orgName === '') {
                    $orgName = null;
                }
            }
        }

        $this->info("Registering service with TraceKit...");
        $this->write("  Sending registration request... ", 'blue');

        try {
            ProjectHelper::loadEnv();
            $serviceName = $_ENV['TRACEKIT_SERVICE_NAME'] ?? $_ENV['APM_SERVICE_NAME'] ?? 'gemvc-app';
            
            $response = $this->toolkit->registerService(
                $email,
                $orgName,
                'gemvc',
                [
                    'version' => ProjectHelper::getVersion(),
                    'environment' => $_ENV['APP_ENV'] ?? 'production'
                ]
            );

            if ($response->response_code === 200) {
                $sessionId = $response->data['session_id'] ?? null;
                $this->write("✓ Done\n", 'green');
                
                if ($sessionId) {
                    $this->box->displaySuccessBox('Registration Request Sent', [
                        "✓ Verification code sent to: {$email}",
                        "",
                        "Please check your email for the verification code."
                    ]);
                    
                    // Wait for user to enter verification code
                    $code = null;
                    $attempts = 0;
                    $maxAttempts = 3;
                    
                    while ($attempts < $maxAttempts) {
                        $this->write("\n\033[1;36mEnter verification code from email:\033[0m ", 'blue');
                        $handle = fopen("php://stdin", "r");
                        if ($handle === false) {
                            $this->error("Failed to read input");
                            return '';
                        }
                        $line = fgets($handle);
                        fclose($handle);
                        $code = $line !== false ? trim($line) : '';
                        
                        if (empty($code)) {
                            $this->warning("Verification code cannot be empty");
                            $attempts++;
                            continue;
                        }
                        
                        $this->info("Verifying code...");
                        $this->write("  Verifying... ", 'blue');
                        
                        try {
                            $verifyResponse = $this->toolkit->verifyCode($sessionId, $code);
                            
                            if ($verifyResponse->response_code === 200) {
                                $apiKey = $verifyResponse->data['api_key'] ?? null;
                                $this->write("✓ Verified\n", 'green');
                                
                                if ($apiKey) {
                                    $this->box->displaySuccessBox('Verification Successful', [
                                        "✓ API Key received",
                                        "✓ Service registered successfully"
                                    ]);
                                    return $apiKey;
                                } else {
                                    $this->error("Verification succeeded but no API key received");
                                    return '';
                                }
                            } else {
                                $this->write("✗ Failed\n", 'red');
                                $errorMsg = $verifyResponse->message ?? 'Invalid verification code';
                                $attempts++;
                                
                                if ($attempts < $maxAttempts) {
                                    $this->warning("Verification failed: {$errorMsg}");
                                    $this->info("Attempt {$attempts} of {$maxAttempts}. Please try again.");
                                } else {
                                    $this->error("Verification failed after {$maxAttempts} attempts: {$errorMsg}");
                                    return '';
                                }
                            }
                        } catch (\Throwable $e) {
                            $this->write("✗ Error\n", 'red');
                            $this->error("Verification error: " . $e->getMessage());
                            return '';
                        }
                    }
                    
                    return '';
                } else {
                    $this->error("Registration succeeded but no session ID received");
                    return '';
                }
            } else {
                $errorMsg = $response->message ?? 'Unknown error';
                $this->write("✗ Failed\n", 'red');
                $this->error("Registration failed: {$errorMsg}");
                return '';
            }
        } catch (\Throwable $e) {
            $this->write("✗ Error\n", 'red');
            $this->error("Registration error: " . $e->getMessage());
            return '';
        }
    }

    /**
     * Step 3b: Handle manual API key entry
     */
    private function handleManualApiKeyEntry(): string
    {
        $this->box->displayInfoBox('Manual API Key Entry', [
            'Enter your existing TraceKit API key',
            ''
        ]);

        $apiKey = null;
        while (true) {
            $this->write("\033[1;36mEnter your TraceKit API key:\033[0m ", 'blue');
            $handle = fopen("php://stdin", "r");
            if ($handle === false) {
                $this->error("Failed to read input");
                return '';
            }
            $line = fgets($handle);
            fclose($handle);
            $apiKey = $line !== false ? trim($line) : '';
            
            if (empty($apiKey)) {
                $this->warning("API key cannot be empty. Please try again.");
                continue;
            }
            
            // Basic validation - should be non-empty and reasonable length
            if (strlen($apiKey) < 10) {
                $this->warning("API key seems too short. Please verify and try again.");
                continue;
            }
            
            $this->info("API key entered: " . substr($apiKey, 0, 8) . "...");
            return $apiKey;
        }
    }

    /**
     * Step 4: Handle service name setup
     */
    private function handleServiceNameSetup(): string
    {
        $this->box->displayInfoBox('Service Name Setup', [
            'Enter a unique service name for your application',
            'This will be used to identify your service in TraceKit',
            ''
        ]);

        $serviceName = null;
        while (true) {
            $this->write("\033[1;36mEnter service name [gemvc-app]:\033[0m ", 'blue');
            $handle = fopen("php://stdin", "r");
            if ($handle === false) {
                $this->error("Failed to read input");
                return 'gemvc-app';
            }
            $line = fgets($handle);
            fclose($handle);
            $serviceName = $line !== false ? trim($line) : '';
            
            // Default to gemvc-app if empty
            if ($serviceName === '') {
                $serviceName = 'gemvc-app';
            }
            
            // Validate format: alphanumeric, hyphens, underscores
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $serviceName)) {
                $this->warning("Service name can only contain letters, numbers, hyphens, and underscores. Please try again.");
                continue;
            }
            
            $this->info("Service name: {$serviceName}");
            return $serviceName;
        }
    }

    /**
     * Step 5: Save configuration
     */
    private function saveConfiguration(string $apiKey, string $serviceName): bool
    {
        $this->box->displayInfoBox('Saving Configuration', [
            'Saving configuration to .env file',
            ''
        ]);

        $this->info("Updating .env file...");
        $this->write("  Writing configuration... ", 'blue');

        try {
            ProjectHelper::loadEnv();
            
            $envVars = [
                'TRACEKIT_API_KEY' => $apiKey,
                'TRACEKIT_SERVICE_NAME' => $serviceName,
            ];

            if (empty($_ENV['APM_NAME'])) {
                $envVars['APM_NAME'] = 'TraceKit';
            }

            if (empty($_ENV['APM_ENABLED'])) {
                $envVars['APM_ENABLED'] = 'true';
            }

            $updated = ProjectHelper::updateEnvVariables($envVars);

            if ($updated) {
                $this->write("✓ Saved\n", 'green');
                
                $this->box->displaySuccessBox('Configuration Saved', [
                    "✓ TRACEKIT_API_KEY",
                    "✓ TRACEKIT_SERVICE_NAME: {$serviceName}",
                    "✓ APM_NAME: TraceKit",
                    "✓ APM_ENABLED: true",
                ]);
                
                return true;
            } else {
                $this->write("✗ Failed\n", 'red');
                $this->warning("Failed to update .env file. Please add manually:");
                $this->write("  TRACEKIT_API_KEY={$apiKey}\n", 'yellow');
                $this->write("  TRACEKIT_SERVICE_NAME={$serviceName}\n", 'yellow');
                $this->write("  APM_NAME=TraceKit\n", 'yellow');
                $this->write("  APM_ENABLED=true\n", 'yellow');
                return false;
            }
        } catch (\Exception $e) {
            $this->write("✗ Error\n", 'red');
            $this->error("Configuration error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Step 6: Test connection
     */
    private function testConnection(): bool
    {
        $this->box->displayInfoBox('Testing Connection', [
            'Verifying TraceKit connection and configuration',
            ''
        ]);

        try {
            ProjectHelper::loadEnv();
            $apiKey = $_ENV['TRACEKIT_API_KEY'] ?? $_ENV['APM_API_KEY'] ?? null;

            if (empty($apiKey)) {
                $this->warning("API key not found. Skipping connection test.");
                return false;
            }

            $this->info("Testing connection...");
            $this->write("  Connecting to TraceKit API... ", 'blue');

            $this->toolkit->setApiKey($apiKey);
            $response = $this->toolkit->getStatus();

            if ($response->response_code === 200) {
                $this->write("✓ Connected\n", 'green');
                
                $this->box->displaySuccessBox('Connection Test Passed', [
                    "✓ API connection: OK",
                    "✓ Service: " . ($response->data['service_name'] ?? 'Unknown'),
                    "✓ Status: Active",
                ]);
                
                return true;
            } else {
                $this->write("✗ Failed\n", 'red');
                $errorMsg = $response->message ?? 'Unknown error';
                $this->box->displayErrorBox('Connection Test Failed', [
                    "Error: {$errorMsg}",
                    "Response Code: {$response->response_code}",
                    "",
                    "Please check your API key and try again."
                ]);
                return false;
            }
        } catch (\Throwable $e) {
            $this->write("✗ Error\n", 'red');
            $this->warning("Connection test error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Step 7: Display success message
     */
    private function displaySuccessMessage(): void
    {
        $this->write("\n");
        
        $lines = [
            "\033[1;92m╔═══════════════════════════════════════════════════════╗\033[0m",
            "\033[1;92m║\033[0m            \033[1;96mTraceKit Setup Complete!\033[0m                \033[1;92m║\033[0m",
            "\033[1;92m╚═══════════════════════════════════════════════════════╝\033[0m",
            "",
            "\033[1;92m✓\033[0m TraceKit is now configured and ready to use!",
            "",
            "\033[1;94mNext Steps:\033[0m",
            "  • TraceKit will automatically start tracing your application",
            "  • View traces in your TraceKit dashboard",
            "  • Run '\033[1;33mphp gemvc tracekit:status\033[0m' to check status",
            "  • Run '\033[1;33mphp gemvc tracekit:test\033[0m' to send a test trace",
            "",
            "\033[1;36mHappy tracing! 🚀\033[0m"
        ];

        foreach ($lines as $line) {
            $this->write($line . "\n", 'white');
        }
    }
}
