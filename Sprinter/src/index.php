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
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:;");
header("Referrer-Policy: same-origin");

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

// Set content type
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="S~printer - Printer Status Monitor">
    <title>S~printer - Printer Status Monitor</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="icon" href="favicon.ico" type="image/x-icon">
</head>
<body>
    <header class="header">
        <div class="logo-wrapper">
            <!-- Ink Fluid Logo replaces the static image -->
            <div class="ink-logo">
                <div class="ink-bubble"></div>
                <div class="ink-bubble"></div>
                <div class="ink-bubble"></div>
                <div class="ink-bubble"></div>
                <div class="ink-wave"></div>
            </div>
            <div class="brand-text">
                <div class="site-name">S~printer</div>
                <div class="site-subtitle">Printer Status Monitor</div>
            </div>
        </div>
        <div class="search-container">
            <input type="text" id="printerSearch" placeholder="⌛ Write here to search...">
        </div>
        <div class="theme-toggle-container">
            <button id="themeToggle" class="theme-toggle" aria-label="Toggle dark/light theme">
                <div class="theme-toggle-knob"></div>
                <svg class="theme-toggle-sun" viewBox="0 0 24 24" width="18" height="18">
                    <circle cx="12" cy="12" r="4" fill="currentColor"/>
                    <path d="M12 2v2M12 20v2M4 12H2M22 12h-2M6 6l-1.4-1.4M19.4 19.4l-1.4-1.4M6 18l-1.4 1.4M19.4 4.6l-1.4 1.4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <svg class="theme-toggle-moon" viewBox="0 0 24 24" width="18" height="18">
                     <path d="M12 3a9 9 0 1 0 9 9c0-.46-.04-.92-.1-1.36a5.5 5.5 0 0 1-4.96-8.65C14.99 2.36 13.52 2 12 2v1z" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                </svg>
            </button>
        </div>
    </header>

    <h1>🖨️ Printer Status Monitor</h1>
    
    <div class="container">
        <?php if (empty($printers)): ?>
            <div class="cube">
                <div class="info-item">
                    <strong>No printers found</strong>
                    <p>Unable to connect to any printers. Please check your network connection and SNMP settings.</p>
                    <p><a href="?refresh=1" class="refresh-button">Refresh Now</a></p>
                </div>
            </div>
        <?php else: ?>
            <div class="refresh-panel">
                <a href="?refresh=1" class="refresh-button">Refresh Printer Data</a>
                <div class="last-refresh">Last updated: <?= date('Y-m-d H:i:s') ?></div>
            </div>
            
            <?php foreach ($printers as $ip => $data): ?>
                <?php 
                // Check printer status - will be 'online' or 'offline'
                $printerStatus = $data['info']['status'] ?? 'online';
                ?>
                <div class="cube <?= $printerStatus === 'offline' ? 'offline-cube' : '' ?>">
                    <div class="status-indicator <?= $printerStatus ?>"></div>
                    
                    <div class="info-item">
                        <strong>Full Name:</strong> <?= htmlspecialchars($data['info']['full_name'] ?? $data['info']['model'] ?? $data['info']['sys_name'] ?? 'N/A') ?>
                    </div>
                    
                    <div class="info-item">
                        <strong>Serial:</strong> <?= htmlspecialchars($data['info']['serial_number'] ?? 'N/A') ?>
                    </div>

                    <?php if ($printerStatus === 'offline'): ?>
                        <div class="offline-message">
                            Printer is currently offline
                        </div>
                    <?php else: ?>
                        <?php 
                        // Check if levels exist AND have actual data
                        $hasLevels = isset($data['levels']) && is_array($data['levels']) && count($data['levels']) > 0;
                        if ($hasLevels): 
                        ?>
                            <?php foreach ($data['levels'] as $color => $level): ?>
                                <div class="level-bar">
                                    <div class="level-label">
                                        <span><?= ucfirst($color) ?></span>
                                        <span><?= $level['percentage'] ?>%</span>
                                    </div>
                                    <div class="level-container">
                                        <div class="level-fill <?= $color ?>-fill" 
                                             style="width: <?= $level['percentage'] ?>%"
                                             data-level="<?= $level['percentage'] ?>"
                                             aria-label="<?= $level['current'] ?>/<?= $level['max'] ?>">
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="no-data-message">
                                No ink/toner level data available
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!empty($data['info']['description'])): ?>
                        <div class="description-embed">
                            <?= htmlspecialchars($data['info']['description'], ENT_QUOTES) ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="cube-footer">
                        <?php if (!empty($data['info']['purchase_url'])): ?>
                            <a href="<?= htmlspecialchars($data['info']['purchase_url'], ENT_QUOTES, 'UTF-8') ?>" class="buy-button" target="_blank" rel="noopener noreferrer">
                                Order Supplies
                            </a>
                        <?php else: ?>
                            <span class="placeholder-button"></span>
                        <?php endif; ?>
                        <div class="last-updated">
                            IP: <?= htmlspecialchars($ip) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <script src="script.js"></script>
</body>
</html>