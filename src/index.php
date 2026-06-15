<?php
declare(strict_types=1);

// Settings
$config = [
    'snmp_community' => getenv('SNMP_COMMUNITY') ?: 'public',
    'snmp_timeout' => 500000, // 0.5 seconds
    'snmp_retries' => 2,
    'cache_enabled' => true,
    'cache_ttl' => 300, // 5 minutes
];

// Include dependencies
require_once 'PrinterDiscovery.php';
require_once 'CacheManager.php';
require_once 'Database.php';

// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:;");
header("Referrer-Policy: same-origin");

// Initialize components
$db = new Database();
$cacheManager = new CacheManager('cache', $config['cache_ttl']);
$discovery = new PrinterDiscovery(
    $config['snmp_community'],
    $config['snmp_timeout'],
    $config['snmp_retries']
);

// Check if we should clear cache (from request parameter)
if (isset($_GET['refresh']) && $_GET['refresh'] === '1') {
    $cacheManager->clear();
}

// Get printers from DB
$dbPrinters = $db->getPrinters();
$dbError = $db->getLastError();

// Get floors and settings
$floors = $db->getFloors();
$settings = $db->getSettings();

$printers = [];

// Process printers (with caching)
$cacheKey = 'printers_data';
if ($config['cache_enabled']) {
    $cachedData = $cacheManager->get($cacheKey);
    if (!empty($cachedData)) {
        // If cached data exists, use it
        // We need to merge cached data with DB data to ensure we have the latest DB changes
        // But we want to keep the cached SNMP levels/status
        $printers = $cachedData;
        
        // Check if DB has changed (simple check: count)
        // In a real app, we'd use timestamps or more complex logic
        if (count($dbPrinters) !== count($printers)) {
            $printers = []; // Force refresh if counts differ
        }
    }
}

// If no printers are loaded (either no cache or cache invalidated) and no DB error
if (empty($printers) && empty($dbError)) {
    // Loop through each saved printer to get its live status
    foreach ($dbPrinters as $dbPrinter) {
        // Get live data (ink levels, online/offline) using SNMP
        // This combines the database info (name, IP) with live info
        $data = $discovery->getPrinterData($dbPrinter);
        $printers[$dbPrinter['ip']] = $data; // Store printer data indexed by IP
    }
    
    // Cache the results if caching is enabled and we have data
    if ($config['cache_enabled'] && !empty($printers)) {
        $cacheManager->set($cacheKey, $printers);
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
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/layout.css">
    <link rel="stylesheet" href="css/components.css">
    <link rel="stylesheet" href="css/modals.css">
    <link rel="stylesheet" href="css/responsive.css">
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
        <div class="header-controls">
            <button id="settingsBtn" class="settings-btn" title="Settings" aria-label="Open settings">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/>
                    <circle cx="12" cy="12" r="3"/>
                </svg>
            </button>
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
        </div>
    </header>
    
    <?php if ($dbError): ?>
        <?php
            $isConnectionRefused = strpos($dbError, 'Connection refused') !== false || strpos($dbError, 'Can\'t connect') !== false;
            
            if ($isConnectionRefused):
                header("Refresh: 3"); // Auto refresh every 3 seconds
        ?>
            <div class="loading-overlay">
                <div class="loading-content">
                    <div class="spinner-large"></div>
                    <h2>Database is loading</h2>
                    <p>Please stand by...</p>
                </div>
            </div>
            <script>
                setTimeout(function() {
                    window.location.reload();
                }, 3000);
            </script>
            <style>body { overflow: hidden; }</style>
            <?php exit; // Stop rendering the rest of the page ?>
        <?php else: ?>
            <div class="error-banner">
                <strong>⚠️</strong>
                <?= htmlspecialchars($dbError) ?>
            </div>
            <style>body { margin-top: 60px; }</style>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Main Layout with Sidebar -->
    <div class="main-layout">
        <h1>🖨️ Printer Status Monitor</h1>
        
        <?php if (empty($printers)): ?>
            <div class="container">
                <div class="empty-state-box static-empty-state">
                    <strong>No printers found</strong>
                    <p>No printers configured. Click the + button to add one.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="refresh-panel">
                <a href="?refresh=1" class="refresh-button">Refresh Printer Data</a>
                <div class="last-refresh">Last updated: <?= date('Y-m-d H:i:s') ?></div>
            </div>
            
            <div class="content-with-sidebar">
                <!-- Floor Sidebar -->
                <aside class="floor-sidebar" id="floorSidebar">
                    <nav class="floor-nav">
                        <button class="floor-item active" data-floor-id="all">
                            <span class="floor-icon">📋</span>
                            <span class="floor-name">All Floors</span>
                        </button>
                        <?php foreach ($floors as $floor): ?>
                        <button class="floor-item" data-floor-id="<?= $floor['id'] ?>">
                            <span class="floor-icon">🏢</span>
                            <span class="floor-name"><?= htmlspecialchars($floor['name']) ?></span>
                        </button>
                        <?php endforeach; ?>
                    </nav>
                </aside>
                
                <!-- Printer Cards Container -->
                <div class="container">
            
            <?php foreach ($printers as $ip => $data): ?>
                <?php 
                // Find DB ID and floor_id for this printer
                $dbId = 0;
                $floorId = null;
                foreach ($dbPrinters as $p) {
                    if ($p['ip'] === $ip) {
                        $dbId = $p['id'];
                        $floorId = $p['floor_id'] ?? null;
                        break;
                    }
                }
                
                // Check printer status - will be 'online' or 'offline'
                $printerStatus = $data['info']['status'] ?? 'online';
                
                // Ensure ID and floor_id are in the info array for the edit modal
                $data['info']['id'] = $dbId;
                $data['info']['floor_id'] = $floorId;
                ?>
                <div class="cube <?= $printerStatus === 'offline' ? 'offline-cube' : '' ?>" data-id="<?= $dbId ?>" data-ip="<?= $ip ?>" data-floor-id="<?= $floorId ?? '' ?>">
                    <div class="status-indicator <?= $printerStatus ?>"></div>
                    

                    <div class="info-item">
                        <strong>Full Name:</strong> <span class="printer-name"><?= htmlspecialchars($data['info']['full_name'] ?? $data['info']['model'] ?? $data['info']['sys_name'] ?? 'N/A') ?></span>
                    </div>
                    
                    <div class="info-item">
                        <strong>Serial:</strong> <?= htmlspecialchars($data['info']['serial_number'] ?? 'N/A') ?>
                    </div>

                    <?php if ($printerStatus === 'offline'): ?>
                        <div class="offline-message">
                            Printer is currently offline
                        </div>
                        <?php 
                        // Check if we have last known levels in the database
                        $lastLevels = !empty($data['info']['last_levels']) ? json_decode($data['info']['last_levels'], true) : null;
                        
                        if ($lastLevels && is_array($lastLevels)): 
                        ?>
                            <div class="last-seen-levels">
                                <small>Last known levels:</small>
                                <?php foreach ($lastLevels as $color => $level): ?>
                                    <div class="level-bar faded">
                                        <div class="level-label">
                                            <span><?= ucfirst($color) ?></span>
                                            <span><?= $level['percentage'] ?>%</span>
                                        </div>
                                        <div class="level-container">
                                            <div class="level-fill <?= $color ?>-fill" 
                                                 style="width: <?= $level['percentage'] ?>%"
                                                 data-level="<?= $level['percentage'] ?>">
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php 
                        // Check if levels exist AND have actual data
                        // "No ink/toner level data available" = OIDs missing/unsupported model
                        // 0% levels = Printer IS reporting, show "Not Genuine / Empty Cartridge"
                        $hasLevels = isset($data['levels']) && is_array($data['levels']) && count($data['levels']) > 0;
                        if ($hasLevels): 
                            // Save these levels to the database for future offline use
                            if ($dbId > 0) {
                                $db->updatePrinterLevels($dbId, $data['levels']);
                            }
                        ?>
                            <?php foreach ($data['levels'] as $color => $level): ?>
                                <?php 
                                // Determine display text based on status
                                $displayPercentage = $level['percentage'] . '%';
                                $barWidth = max(0, $level['percentage']);
                                $specialClass = '';
                                
                                // Handle special statuses
                                if (isset($level['status']) && $level['status'] === 'empty_or_not_genuine') {
                                    $displayPercentage = 'Not Genuine / Empty Cartridge';
                                    $barWidth = 0;
                                    $specialClass = 'warning-level';
                                } elseif ($level['percentage'] <= 0) {
                                    // No status set but percentage is 0 or less
                                    $displayPercentage = 'Not Genuine / Empty Cartridge';
                                    $barWidth = 0;
                                    $specialClass = 'warning-level';
                                }
                                ?>
                                <div class="level-bar <?= $specialClass ?>">
                                    <div class="level-label">
                                        <span><?= ucfirst($color) ?></span>
                                        <span><?= $displayPercentage ?></span>
                                    </div>
                                    <div class="level-container">
                                        <div class="level-fill <?= $color ?>-fill" 
                                             style="width: <?= $barWidth ?>%"
                                             data-level="<?= $barWidth ?>"
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

                    <div class="cube-bottom">
                        <?php if (!empty($data['info']['description'])): ?>
                            <div class="description-embed printer-desc">
                                <?= htmlspecialchars($data['info']['description'], ENT_QUOTES) ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="cube-footer">
                            <div class="actions-menu">
                                <button class="action-btn delete-btn" onclick="deletePrinter(<?= $dbId ?>)" title="Remove Printer">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="3 6 5 6 21 6"></polyline>
                                        <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                                    </svg>
                                </button>
                                <button class="action-btn edit-btn" onclick="openEditModal(<?= htmlspecialchars(json_encode($data['info'])) ?>)" title="Edit Printer">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                    </svg>
                                </button>
                            </div>

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
                </div>
            <?php endforeach; ?>
                </div><!-- /.container -->
            </div><!-- /.content-with-sidebar -->
        <?php endif; ?>
    </div><!-- /.main-layout -->

    <!-- Floating Action Button -->
    <button id="addPrinterBtn" class="fab" title="Add Printer">+</button>

    <!-- Modal -->
    <div id="printerModal" class="modal">
        <div class="modal-content">
            <span class="close-modal">&times;</span>
            <h2 id="modalTitle">Add Printer</h2>
            <form id="printerForm">
                <input type="hidden" id="printerId" name="id">
                
                <div class="form-group">
                    <label for="printerIp">IP Address</label>
                    <div class="input-group">
                        <input type="text" id="printerIp" name="ip" placeholder="192.168.1.100" required pattern="^([0-9]{1,3}\.){3}[0-9]{1,3}$">
                        <button type="button" id="checkIpBtn" class="secondary-btn">Check IP</button>
                    </div>
                    <small id="ipStatus" class="status-text"></small>
                </div>

                <div class="form-group">
                    <label for="printerName">Name</label>
                    <input type="text" id="printerName" name="name" placeholder="Office Printer">
                </div>

                <div class="form-group">
                    <label for="printerDesc">Description</label>
                    <input type="text" id="printerDesc" name="description" placeholder="Location, notes..." maxlength="100">
                </div>

                <div class="form-group">
                    <label for="printerFloor">Floor</label>
                    <select id="printerFloor" name="floor_id" class="form-select">
                        <option value="">-- No Floor --</option>
                        <?php foreach ($floors as $floor): ?>
                        <option value="<?= $floor['id'] ?>"><?= htmlspecialchars($floor['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="printerUrl">Purchase URL</label>
                    <input type="url" id="printerUrl" name="purchase_url" placeholder="https://example.com/supplies">
                </div>

                <!-- Hidden fields populated by Check IP -->
                <input type="hidden" id="printerModel" name="model">
                <input type="hidden" id="printerSerial" name="serial">
                <input type="hidden" id="printerVendor" name="vendor">

                <div class="form-actions">
                    <div class="left-actions">
                        <button type="button" id="exportBtn" class="secondary-btn" title="Export to JSON">Export</button>
                        <button type="button" id="importBtn" class="secondary-btn" title="Import from JSON">Import</button>
                        <input type="file" id="importFile" accept=".json" style="display: none;">
                    </div>
                    <button type="submit" class="primary-btn">Save Printer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Settings Modal -->
    <div id="settingsModal" class="modal">
        <div class="modal-content settings-modal-content">
            <span class="close-modal" id="closeSettingsModal">&times;</span>
            <h2>⚙️ Settings</h2>
            
            <div class="settings-layout">
                <!-- Sidebar navigation -->
                <nav class="settings-nav">
                    <button class="settings-nav-item active" data-tab="floors">
                        🏢 Manage Floors
                    </button>
                    <button class="settings-nav-item" data-tab="display">
                        🖥️ Display Settings
                    </button>
                </nav>

                <!-- Tab content -->
                <div class="settings-content">
                    <!-- Manage Floors Tab -->
                    <div class="settings-tab active" id="floorsTab">
                        <h3>Manage Floors</h3>
                        <p class="settings-hint">Add floors to organize your printers by location.</p>
                        
                        <div class="floor-manager">
                            <div class="add-floor-form">
                                <input type="text" id="newFloorName" placeholder="e.g., 1st Floor" maxlength="50">
                                <button type="button" id="addFloorBtn" class="primary-btn">Add Floor</button>
                            </div>
                            
                            <ul id="floorList" class="floor-list">
                                <?php foreach ($floors as $floor): ?>
                                <li class="floor-list-item" data-floor-id="<?= $floor['id'] ?>">
                                    <input type="text" class="floor-name-input" value="<?= htmlspecialchars($floor['name']) ?>">
                                    <button type="button" class="save-floor-btn secondary-btn" title="Save">✓</button>
                                    <button type="button" class="delete-floor-btn danger-btn" title="Delete">×</button>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            
                            <?php if (empty($floors)): ?>
                            <p class="no-floors-msg">No floors configured yet.</p>
                            <?php endif; ?>
                            
                            <div class="settings-actions">
                                <button type="button" id="saveFloorsBtn" class="primary-btn">Save & Close</button>
                            </div>
                        </div>
                    </div>

                    <!-- Display Settings Tab -->
                    <div class="settings-tab" id="displayTab">
                        <h3>Display Settings</h3>
                        <p class="settings-hint">Customize what information is shown on printer cards.</p>
                        
                        <div class="settings-options">
                            <label class="setting-toggle">
                                <span class="setting-label">Hide IP Address</span>
                                <input type="checkbox" id="settingHideIp" <?= ($settings['hide_ip'] ?? '0') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                            
                            <label class="setting-toggle">
                                <span class="setting-label">Show Serial Number</span>
                                <input type="checkbox" id="settingShowSerial" <?= ($settings['show_serial'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        
                        <div class="settings-actions">
                            <button type="button" id="saveSettingsBtn" class="primary-btn">Save Settings</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
</body>
</html>