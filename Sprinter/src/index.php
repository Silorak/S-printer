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
                <span class="theme-icon">🌙</span>
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
                        <div class="last-updated">
                            Updated: <?= date('Y-m-d H:i:s', $data['info']['last_updated']) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script src="script.js"></script>
</body>
</html>