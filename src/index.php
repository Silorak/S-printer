<?php
declare(strict_types=1);

// Settings
$config = [
    'snmp_community' => getenv('SNMP_COMMUNITY') ?: 'public',
    'snmp_timeout' => 400000, // 0.4s - LAN printers answer in milliseconds.
                              // Lower worst case per offline printer: ~0.8s instead of 1.5s.
    'snmp_retries' => 1,
    'cache_enabled' => true,
    'cache_ttl' => 300,   // 5 minutes, PER PRINTER (was: one global cache for all)
    'snmp_budget' => 20,  // soft cap: max seconds of SNMP polling per page render
];

// Include dependencies
require_once 'SnmpEnums.php';
require_once 'PrinterDiscovery.php';
require_once 'PollingService.php';
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

// Get printers from DB
$dbPrinters = $db->getPrinters();
$dbError = $db->getLastError();

// Cache TTL must outlast one full background sweep, otherwise entries expire
// before the daemon comes back around (matters at large fleet sizes).
$cacheManager->setTtl(max(300, count($dbPrinters) * 2 + 60));

// Get floors and settings
$floors = $db->getFloors();
$settings = $db->getSettings();

$printers = [];
$lastUpdated = null;

// Gather live data (SNMP) for every printer.
// The heavy lifting lives in PollingService so the background poller
// (cron.php) and this page share one identical data pipeline:
// per-printer cache -> SNMP probe -> persist last-known levels.
// The cache stores ONLY volatile network data; names/descriptions/URLs
// always come straight from the database, so edits show up instantly.
$printers = [];
$lastUpdated = null;

if (empty($dbError)) {
    $result = PollingService::gather(
        $dbPrinters,
        $discovery,
        $db,
        $cacheManager,
        $config['cache_enabled'],
        (float)$config['snmp_budget']
    );
    $printers = $result['printers'];
    $lastUpdated = $result['lastUpdated'];
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="S~printer - Printer Status Monitor">
    <title>S~printer - Printer Status Monitor</title>
    <script>
        // Apply theme before first paint to avoid a light/dark flash.
        // Keep in sync with setupThemeToggle() in script.js.
        (function () {
            try {
                // ?theme=dark|light overrides for tests/links, then saved
                // preference, then OS preference.
                var p = new URLSearchParams(location.search).get('theme');
                var t = p || localStorage.getItem('theme');
                var osLight = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches;
                if (t === 'light' || (!t && osLight)) {
                    document.documentElement.classList.add('light-theme');
                }
            } catch (e) { /* storage unavailable: keep default */ }
        })();
    </script>
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/layout.css">
    <link rel="stylesheet" href="css/components.css">
    <link rel="stylesheet" href="css/modals.css">
    <link rel="stylesheet" href="css/responsive.css">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🖨️</text></svg>">
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
            <input type="text" id="printerSearch" name="search" aria-label="Search printers" placeholder="Search printers...">
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
                    <div class="theme-toggle-knob">
                        <svg class="theme-toggle-icon-moon" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">
                            <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" fill="currentColor"/>
                        </svg>
                        <svg class="theme-toggle-icon-sun" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true">
                            <circle cx="12" cy="12" r="4" fill="currentColor"/>
                            <path d="M12 2v2M12 20v2M4 12H2M22 12h-2M6 6l-1.4-1.4M19.4 19.4l-1.4-1.4M6 18l-1.4 1.4M19.4 4.6l-1.4 1.4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                    </div>
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
                <button type="button" id="refreshBtn" class="refresh-button">Refresh Printer Data</button>
                <div class="last-refresh">Last updated: <?= date('Y-m-d H:i:s', $lastUpdated ?? time()) ?></div>
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
                // ID and floor_id are already part of the info array
                // (looked up from the $dbByIp map while gathering data)
                $dbId = $data['info']['id'];
                $floorId = $data['info']['floor_id'];
                
                // Check printer status - will be 'online' or 'offline'
                $printerStatus = $data['status'];
                ?>
                <div class="cube <?= $printerStatus === 'offline' ? 'offline-cube' : '' ?>" data-id="<?= $dbId ?>" data-ip="<?= htmlspecialchars($ip, ENT_QUOTES, 'UTF-8') ?>" data-floor-id="<?= $floorId ?? '' ?>">
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
                        <?php if (empty($lastLevels)): ?>
                        <div class="no-data-message">
                            No supply data collected yet
                        </div>
                        <?php endif; ?>
                        <?php 
                        // Check if we have last known levels in the database
                        $lastLevels = !empty($data['info']['last_levels']) ? json_decode($data['info']['last_levels'], true) : null;
                        
                        if ($lastLevels && is_array($lastLevels)): 
                        ?>
                            <div class="last-seen-levels">
                                <small>Last known levels:</small>
                                <?php foreach ($lastLevels as $color => $level): ?>
                                    <?php
                                    // Same wording as the online view for
                                    // empty / non-genuine cartridges.
                                    $isSpecial = ($level['status'] ?? '') === 'empty_or_not_genuine'
                                        || (float)$level['percentage'] <= 0;
                                    $displayPercentage = $isSpecial
                                        ? 'Not Genuine / Empty'
                                        : $level['percentage'] . '%';
                                    ?>
                                    <div class="level-bar faded<?= $isSpecial ? ' warning-level' : '' ?>">
                                        <div class="level-label">
                                            <span><?= ucfirst($color) ?></span>
                                            <span><?= $displayPercentage ?></span>
                                        </div>
                                        <?php if (!$isSpecial): ?>
                                        <div class="level-container">
                                            <div class="level-fill <?= $color ?>-fill" 
                                                 data-level="<?= $level['percentage'] ?>">
                                            </div>
                                        </div>
                                        <?php endif; ?>
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
                                <button class="action-btn edit-btn" onclick="openEditModal(<?= htmlspecialchars(json_encode($data['info'], JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}') ?>)" title="Edit Printer">
                                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                                    </svg>
                                </button>
                            </div>

                            <?php
                            $buyUrl = $data['info']['purchase_url'] ?? null;
                            // Only http(s) can become a link target - never javascript:/data:
                            $buyUrl = is_string($buyUrl) && preg_match('#^https?://#i', $buyUrl) ? $buyUrl : null;
                            ?>
                            <?php if ($buyUrl !== null): ?>
                                <a href="<?= htmlspecialchars($buyUrl, ENT_QUOTES, 'UTF-8') ?>" class="buy-button" target="_blank" rel="noopener noreferrer">
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
            <div class="modal-tabs" role="tablist">
                <button type="button" class="modal-tab active" data-ptab="main">Add Printer</button>
                <button type="button" class="modal-tab" data-ptab="snmp">SNMP Configuration</button>
            </div>
            <form id="printerForm" novalidate>
                <input type="hidden" id="printerId" name="id">

                <div class="modal-tab-pane active" data-pane="main">
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
                </div>

                <div class="modal-tab-pane" data-pane="snmp">
                <p class="settings-hint">How this printer is queried for status and supplies. Most printers work with SNMP v2c and the default community.</p>
<div class="form-group">
                    <label>SNMP access</label>
                    <div class="segmented" id="snmpVersionSeg">
                        <button type="button" class="seg-btn active" data-mode="2c">SNMP v1/v2c</button>
                        <button type="button" class="seg-btn" data-mode="3">SNMP v3</button>
                    </div>
                    <input type="hidden" id="snmpVersion" value="2c">
                </div>

                <div class="form-group" id="snmpV12Section">
                    <label for="snmpCommunity">Community string</label>
                    <div class="input-group">
                        <input type="text" id="snmpCommunity" value="public" placeholder="public">
                        <select id="snmpVersionLegacy" class="form-select">
                            <option value="2c" selected>v2c (default)</option>
                            <option value="1">v1 (older devices)</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" id="snmpV3Section" style="display:none;">
                    <label for="snmpV3User">Security username</label>
                    <input type="text" id="snmpV3User" placeholder="printer-monitor">

                    <div class="v3-select-row">
                        <div class="v3-field">
                            <label for="snmpV3Level">Security level</label>
                            <select id="snmpV3Level" class="form-select">
                                <option value="authPriv" selected>Auth + privacy</option>
                                <option value="authNoPriv">Auth, no privacy</option>
                                <option value="noAuthNoPriv">None</option>
                            </select>
                        </div>
                        <div class="v3-field">
                            <label for="snmpV3AuthProto">Auth protocol</label>
                            <select id="snmpV3AuthProto" class="form-select">
                                <option value="SHA" selected>SHA</option>
                                <option value="MD5">MD5</option>
                            </select>
                        </div>
                        <div class="v3-field">
                            <label for="snmpV3PrivProto">Privacy protocol</label>
                            <select id="snmpV3PrivProto" class="form-select">
                                <option value="AES" selected>AES</option>
                                <option value="DES">DES</option>
                            </select>
                        </div>
                    </div>

                    <label for="snmpV3AuthPass">Auth passphrase</label>
                    <input type="password" id="snmpV3AuthPass" name="snmpV3AuthPass" autocomplete="new-password" placeholder="">
                    <label for="snmpV3PrivPass">Privacy passphrase</label>
                    <input type="password" id="snmpV3PrivPass" name="snmpV3PrivPass" autocomplete="new-password" placeholder="">
                    <small class="settings-hint">Leave passphrases blank to keep the saved ones. They match the SNMPv3 user configured on the printer itself.</small>
                </div>
                </div>

                <input type="hidden" id="printerModel" name="model">
                <input type="hidden" id="printerSerial" name="serial">
                <input type="hidden" id="printerVendor" name="vendor">

                <div class="form-actions">
                    <div class="left-actions">
                        <button type="button" id="exportBtn" class="secondary-btn" title="Export to JSON">Export</button>
                        <button type="button" id="importBtn" class="secondary-btn" title="Import from JSON">Import</button>
                        <input type="file" id="importFile" name="importFile" aria-label="Import backup file" accept=".json" style="display: none;">
                    </div>
                    <button type="submit" class="primary-btn">Save Printer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Settings Modal -->
    <div id="settingsModal" class="modal">
        <div class="modal-content settings-modal-content">
            <span class="close-modal" id="closeSettingsModal" title="Close settings" aria-label="Close settings">&#8250;</span>
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
                    <button class="settings-nav-item" data-tab="alerts">
                        🔔 Alerts
                    </button>
                    <button class="settings-nav-item" data-tab="mailserver">
                        📧 Mail Server
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
                                <input type="text" id="newFloorName" name="floorName" aria-label="New floor name" placeholder="e.g., 1st Floor" maxlength="50">
                                <button type="button" id="addFloorBtn" class="primary-btn">Add Floor</button>
                            </div>
                            
                            <ul id="floorList" class="floor-list">
                                <?php foreach ($floors as $floor): ?>
                                <li class="floor-list-item" data-floor-id="<?= $floor['id'] ?>">
                                    <input type="text" class="floor-name-input" name="floorName" value="<?= htmlspecialchars($floor['name']) ?>" aria-label="Floor name">
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
                                <input type="checkbox" id="settingHideIp" name="hideIp" <?= ($settings['hide_ip'] ?? '0') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                            
                            <label class="setting-toggle">
                                <span class="setting-label">Show Serial Number</span>
                                <input type="checkbox" id="settingShowSerial" name="showSerial" <?= ($settings['show_serial'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        
                        <div class="settings-actions">
                            <button type="button" id="saveSettingsBtn" class="primary-btn">Save Settings</button>
                        </div>
                    </div>

                    <!-- Alerts Tab -->
                    <div class="settings-tab" id="alertsTab">
                        <h3>🔔 Alerts</h3>
                        <p class="settings-hint">
                            The background poller checks printers every few minutes and emails you when a
                            cartridge crosses the low-ink threshold or a printer goes offline / comes back.
                            Alerts fire on <em>state changes</em> only - no spam.
                            Mail delivery is configured in the <strong>Mail Server</strong> tab.
                        </p>

                        <div class="settings-options">
                            <label class="setting-toggle">
                                <span class="setting-label">Enable email alerts</span>
                                <input type="checkbox" id="alertEnabled" name="alertEnabled">
                                <span class="toggle-slider"></span>
                            </label>
                            <label class="setting-toggle">
                                <span class="setting-label">Alert when a printer goes offline</span>
                                <input type="checkbox" id="alertOffline" name="alertOffline">
                                <span class="toggle-slider"></span>
                            </label>
                            <label class="setting-toggle">
                                <span class="setting-label">Notify when a printer is back online</span>
                                <input type="checkbox" id="alertRecover" name="alertRecover">
                                <span class="toggle-slider"></span>
                            </label>
                        </div>

                        <div class="settings-field">
                            <label for="alertThreshold">Low ink threshold (%)</label>
                            <input type="number" id="alertThreshold" name="alertThreshold" class="settings-input" min="1" max="100" value="15">
                        </div>

                        <div class="settings-field">
                            <label for="alertRecipients">Recipients (comma-separated email addresses)</label>
                            <input type="text" id="alertRecipients" name="alertRecipients" class="settings-input" placeholder="ops@ort.lt, john@ort.lt">
                        </div>

                        <div class="settings-actions">
                            <button type="button" id="alertSaveBtn" class="primary-btn">Save Alerts</button>
                        </div>
                    </div>

                    <!-- Mail Server Tab -->
                    <div class="settings-tab" id="mailserverTab">
                        <h3>📧 Mail Server</h3>
                        <p class="settings-hint">
                            How alert emails are delivered. Alerts must be enabled in the
                            <strong>Alerts</strong> tab.
                        </p>

                        <div class="settings-field">
                            <label>Authentication</label>
                            <div class="segmented" id="mailAuthSeg">
                                <button type="button" class="seg-btn active" data-mode="oauth2">Microsoft 365 (OAuth2)</button>
                                <button type="button" class="seg-btn" data-mode="password">Username &amp; password</button>
                            </div>
                            <input type="hidden" id="mailAuthMode" name="mailAuthMode" value="oauth2">
                        </div>

                        <div class="settings-row">
                            <div class="settings-field">
                                <label for="smtpHost">SMTP server</label>
                                <input type="text" id="smtpHost" name="smtpHost" class="settings-input" placeholder="smtp.office365.com">
                            </div>
                            <div class="settings-field">
                                <label for="smtpPort">Port</label>
                                <input type="number" id="smtpPort" name="smtpPort" class="settings-input" placeholder="587">
                            </div>
                            <div class="settings-field">
                                <label for="smtpSecure">Security</label>
                                <select id="smtpSecure" name="smtpSecure" class="settings-select">
                                    <option value="auto">Auto</option>
                                    <option value="tls">STARTTLS</option>
                                    <option value="ssl">SSL/TLS</option>
                                    <option value="none">None (LAN relay)</option>
                                </select>
                            </div>
                        </div>

                        <div class="settings-field">
                            <label for="smtpUser">From mailbox (full address)</label>
                            <input type="text" id="smtpUser" name="smtpUser" class="settings-input" placeholder="no-reply@ort.lt">
                        </div>

                        <!-- Basic auth section -->
                        <div id="mailBasicSection">
                            <div class="settings-field">
                                <label for="smtpPass">Password</label>
                                <input type="password" id="smtpPass" name="smtpPass" autocomplete="new-password" class="settings-input" placeholder="">
                                <small class="settings-hint">Requires "Authenticated SMTP" enabled on the mailbox.</small>
                            </div>
                        </div>

                        <!-- OAuth2 section -->
                        <div id="mailOauthSection">
                            <div class="settings-row">
                                <div class="settings-field">
                                    <label for="oauthTenant">Tenant ID (blank = any tenant)</label>
                                    <input type="text" id="oauthTenant" name="oauthTenant" class="settings-input" placeholder="organizations">
                                </div>
                                <div class="settings-field">
                                    <label for="oauthClientId">App Client ID</label>
                                    <input type="text" id="oauthClientId" name="oauthClientId" class="settings-input" placeholder="00000000-...">
                                </div>
                            </div>

                            <div class="oauth-connect-box" id="oauthConnectBox">
                                <div id="oauthIdle">
                                    <button type="button" id="oauthConnectBtn" class="primary-btn">Connect Microsoft 365</button>
                                    <small class="settings-hint" style="display:block;margin-top:var(--space-2);">
                                        One-time consent with the no-reply mailbox. Needs an Azure app registration
                                        with "Allow public client flows" enabled.
                                    </small>
                                </div>
                                <div id="oauthPending" style="display:none;">
                                    <div style="font:600 var(--text-sm) system-ui,sans-serif;margin-bottom:var(--space-2);">
                                        Go to <a id="oauthUri" href="#" target="_blank" rel="noopener">microsoft.com/devicelogin</a>
                                        and enter code:
                                    </div>
                                    <div id="oauthCode" style="font:bold var(--text-xl) monospace;letter-spacing:4px;color:var(--primary-text);"></div>
                                    <div id="oauthStatus" class="settings-hint">Waiting for consent...</div>
                                </div>
                                <div id="oauthDone" style="display:none;">
                                    <span style="color:var(--ok-text);font:600 var(--text-sm) system-ui,sans-serif;">✓ Connected - token renews automatically.</span>
                                </div>
                            </div>
                        </div>

                        <div class="settings-field">
                            <label for="smtpFromName">From name</label>
                            <input type="text" id="smtpFromName" name="smtpFromName" class="settings-input" placeholder="S~printer">
                        </div>

                        <div class="settings-actions">
                            <button type="button" id="alertTestBtn" class="secondary-btn">Send test email</button>
                            <button type="button" id="mailSaveBtn" class="primary-btn">Save Mail Server</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="script.js"></script>
</body>
</html>