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
        '192.168.40.118',
        '172.17.163.235',
        '10.82.165.14',
        '10.82.165.15',
        '10.82.165.16'
    ];
    
    // Custom descriptions for each printer
    public static array $descriptions = [
        '192.168.40.118' => '1 aukštas | Mokytojų kambario spausdintuvas',
        '172.17.163.235' => '1 aukštas | Raštinės spausdintuvas',
        '10.82.165.14' => '1 aukštas | Darželio raštinės spausdintuvas',
        '10.82.165.15' => '1 aukštas | Rašinės HP spausdintuvas',
        '10.82.165.16' => '1 aukštas | Būdinčio spausdintuvas'
    ];
    
    // URLs for purchasing supplies for each printer
    public static array $purchaseUrls = [
        '192.168.40.118' => 'https://www.ekoprint.lt/paieska?q=CF259',
        '172.17.163.235' => 'https://example.com/toner-canon-1',
        '10.82.165.14' => 'https://example.com/toner-hp-2',
        '10.82.165.15' => 'https://www.ekoprint.lt/paieska?q=CF226',
        '10.82.165.16' => 'https://www.ekoprint.lt/paieska?q=No.415x'
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