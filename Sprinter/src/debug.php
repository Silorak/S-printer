<?php
declare(strict_types=1);

// Include dependencies
require_once 'PrinterDiscovery.php';
require_once 'CacheManager.php';

// Initialize cache manager
$cacheManager = new CacheManager('cache', 300);

// Get printer data from cache
$printers = $cacheManager->get('printers');

// If no cached data, get it fresh
if (empty($printers)) {
    // Initialize printer discovery
    $discovery = new PrinterDiscovery('public', 500000, 2);
    
    // Discover printers
    $printers = $discovery->discoverPrinters();
    
    // Cache the results
    if (!empty($printers)) {
        $cacheManager->set('printers', $printers);
    }
}

// Debug output
echo '<!DOCTYPE html>
<html>
<head>
    <title>Printer Data Debug</title>
    <style>
        body { font-family: monospace; margin: 20px; }
        .printer { margin-bottom: 20px; border: 1px solid #ccc; padding: 10px; }
        .levels { margin-left: 20px; }
        pre { background: #f5f5f5; padding: 10px; overflow: auto; }
    </style>
</head>
<body>';

echo '<h1>Printer Data Structure Debug</h1>';

if (empty($printers)) {
    echo '<p>No printers found.</p>';
} else {
    foreach ($printers as $ip => $data) {
        echo '<div class="printer">';
        echo '<h2>Printer IP: ' . htmlspecialchars($ip) . '</h2>';
        
        echo '<h3>Info:</h3>';
        echo '<pre>' . htmlspecialchars(print_r($data['info'], true)) . '</pre>';
        
        echo '<h3>Levels:</h3>';
        echo '<pre>' . htmlspecialchars(print_r($data['levels'], true)) . '</pre>';
        
        // Test conditions that should trigger the "No ink/toner" message
        echo '<h3>Tests:</h3>';
        echo 'isset($data[\'levels\']): ' . (isset($data['levels']) ? 'true' : 'false') . '<br>';
        echo 'empty($data[\'levels\']): ' . (empty($data['levels']) ? 'true' : 'false') . '<br>';
        echo '!empty($data[\'levels\']): ' . (!empty($data['levels']) ? 'true' : 'false') . '<br>';
        echo 'count($data[\'levels\']): ' . (isset($data['levels']) ? count($data['levels']) : 'N/A') . '<br>';
        echo 'is_array($data[\'levels\']): ' . (isset($data['levels']) && is_array($data['levels']) ? 'true' : 'false') . '<br>';
        
        // Would this condition show the "No ink/toner" message?
        $showNoInkMessage = isset($data['levels']) && empty($data['levels']);
        echo 'Would show "No ink/toner" message: ' . ($showNoInkMessage ? 'YES' : 'NO') . '<br>';
        
        echo '</div>';
    }
}

echo '</body></html>';
