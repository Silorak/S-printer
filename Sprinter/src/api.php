<?php
declare(strict_types=1);

// Settings
$config = [
    'snmp_community' => getenv('SNMP_COMMUNITY') ?: 'public',
    'snmp_timeout' => 500000, // 0.5 seconds
    'snmp_retries' => 2,
    'cache_enabled' => true,
    'cache_ttl' => 300, // 5 minutes
    'scan_network' => false, // Set to true to enable auto-discovery on network
    'scan_subnets' => [
        '192.168.40.0/24',
        '172.17.163.0/24',
        '10.82.165.0/24'
    ],
    'scan_max_ips' => 50 // Max IPs to scan per subnet
];

// Include dependencies
require_once 'PrinterDiscovery.php';
require_once 'CacheManager.php';

// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
// Adjust CSP for an API endpoint if needed, for now this is fine.
header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none';");
header("Referrer-Policy: same-origin");
header('Content-Type: application/json; charset=utf-8');


// Initialize cache manager
$cacheManager = new CacheManager('cache', $config['cache_ttl']);

// Initialize printer discovery
$discovery = new PrinterDiscovery(
    $config['snmp_community'],
    $config['snmp_timeout'],
    $config['snmp_retries']
);

// Check if we should clear cache (from request parameter)
if (isset($_GET['refresh']) && $_GET['refresh'] === '1') {
    $cacheManager->clear();
}

// Get printer data (from cache or fresh)
$printers = [];
if ($config['cache_enabled']) {
    $printers = $cacheManager->get('printers');
}

// If not in cache, discover printers
if (empty($printers)) {
    $subnets = $config['scan_network'] ? $config['scan_subnets'] : [];
    $printers = $discovery->discoverPrinters($subnets, $config['scan_max_ips']);

    // Cache the results
    if ($config['cache_enabled'] && !empty($printers)) {
        $cacheManager->set('printers', $printers);
    }
}

// Output the data as JSON
echo json_encode($printers, JSON_PRETTY_PRINT);
