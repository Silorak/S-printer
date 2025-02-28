<?php
declare(strict_types=1);

// Application configuration
class Config {
    // SNMP Settings
    public static string $community;
    public static int $timeout = 500000; // 0.5 seconds
    public static int $retries = 2;
    
    // Cache settings
    public static bool $enableCache = true;
    public static int $cacheTTL = 300; // 5 minutes
    
    // Printer definitions
    public static array $printers = [
        '192.168.0.1',
		'192.168.0.2'
    ];
    
    // Custom descriptions for each printer
    public static array $descriptions = [
        '192.168.0.1' => 'config example',
		'192.168.0.2' => 'config example'
    ];
    
    // Initialize configuration
    public static function init(): void {
        // Set community string from environment or use default
        self::$community = getenv('SNMP_COMMUNITY') ?: 'public';
        
        // Setup error handling based on environment
        $errorReporting = getenv('PHP_ERROR_REPORTING');
        if ($errorReporting) {
            error_reporting(intval($errorReporting));
        }
        
        $displayErrors = getenv('PHP_DISPLAY_ERRORS');
        if ($displayErrors !== false) {
            ini_set('display_errors', $displayErrors);
        }
    }
}

// Initialize configuration
Config::init();
