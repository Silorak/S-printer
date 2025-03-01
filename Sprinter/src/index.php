<?php
declare(strict_types=1);

// Include dependencies
require_once 'config.php';
require_once 'oid_mapping.php';
require_once 'printer_service.php';

// Security headers
header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: DENY");
header("X-XSS-Protection: 1; mode=block");
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:;");
header("Referrer-Policy: same-origin");

// Initialize printer service
$printerService = new PrinterService();

// Get printer data
$results = $printerService->getAllPrinters();

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
            <img src="logo.png" class="logo-img" alt="S~printer Logo">
            <div class="brand-text">
                <div class="site-name">S~printer</div>
                <div class="site-subtitle">Printer Status Monitor</div>
            </div>
        </div>
        <div class="search-container">
            <input type="text" id="printerSearch" placeholder="⌛ Write here to search...">
        </div>
        <div class="theme-switch-wrapper">
            <button id="themeToggle" class="theme-toggle" aria-label="Toggle dark/light theme">
                <div class="theme-toggle-track">
                    <div class="theme-toggle-thumb"></div>
                    <svg class="theme-toggle-icon sun" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16">
                        <circle cx="12" cy="12" r="5" fill="currentColor"/>
                        <path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                    <svg class="theme-toggle-icon moon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16">
                        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" fill="currentColor" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
            </button>
        </div>
    </header>

    <h1>🖨️ Printer Status Monitor</h1>
    
    <div class="container">
        <?php if (empty($results)): ?>
            <div class="cube">
                <div class="info-item">
                    <strong>No printers found</strong>
                    <p>Unable to connect to any printers. Please check your network connection and SNMP settings.</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($results as $ip => $data): ?>
                <div class="cube">
                    <div class="status-indicator <?= !empty($data) ? 'online' : 'offline' ?>"></div>
                    
                    <div class="info-item">
                        <strong>Full Name:</strong> <?= htmlspecialchars($data['info']['full_name'] ?? 'N/A') ?>
                    </div>
                    
                    <div class="info-item">
                        <strong>Serial:</strong> <?= htmlspecialchars($data['info']['serial_number'] ?? 'N/A') ?>
                    </div>

                    <?php if (!empty($data['levels'])): ?>
                        <?php foreach ($data['levels'] as $level): ?>
                            <div class="level-bar">
                                <div class="level-label">
                                    <span><?= ucfirst($level['color']) ?></span>
                                    <span><?= $level['percentage'] ?>%</span>
                                </div>
                                <div class="level-container">
                                    <div class="level-fill <?= $level['color'] ?>-fill" 
                                         style="width: <?= $level['percentage'] ?>%"
                                         data-level="<?= $level['percentage'] ?>"
                                         aria-label="<?= $level['current'] ?>/<?= $level['max'] ?>">
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="info-item">
                            <em>No ink/toner level data available</em>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($data['info']['description'])): ?>
                        <div class="description-embed">
                            <?= htmlspecialchars($data['info']['description'], ENT_QUOTES) ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (isset($data['info']['last_updated'])): ?>
                        <div class="cube-footer">
                            <?php if (!empty($data['info']['purchase_url'])): ?>
                                <a href="<?= htmlspecialchars($data['info']['purchase_url'], ENT_QUOTES, 'UTF-8') ?>" class="buy-button" target="_blank" rel="noopener noreferrer">
                                    Order Supplies
                                </a>
                            <?php else: ?>
                                <span class="placeholder-button"></span>
                            <?php endif; ?>
                            <div class="last-updated">
                                Updated: <?= date('Y-m-d H:i:s', $data['info']['last_updated']) ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script src="script.js"></script>
</body>
</html>