<?php
// This file acts as the "API" (Application Programming Interface).
// It receives requests from the JavaScript (script.js) and sends back data (JSON).
// It doesn't show any HTML, just raw data.

declare(strict_types=1);

// Turn on error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '0'); // Don't show errors to the user, just log them

// Set the content type to JSON so the browser knows we are sending data
header('Content-Type: application/json');

require_once 'SnmpEnums.php';
require_once 'PrinterDiscovery.php';
require_once 'CacheManager.php';
require_once 'SmtpClient.php';
require_once 'AlertService.php';
require_once 'Database.php';

// Helper function to send a success response
function sendResponse($data = null) {
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

// Helper function to send an error response
function sendError($message) {
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

// Get the "action" parameter from the URL (e.g., api.php?action=add_printer)
$action = $_GET['action'] ?? '';

// Actions that consume the JSON request body.
// They must be sent as application/json: this blocks cross-site form posts
// with enctype=text/plain smuggling raw JSON (a simple request = no CORS
// preflight = would otherwise execute state changes from any website).
$bodyActions = ['check_ip', 'add_printer', 'edit_printer', 'delete_printer',
                'import_printers', 'add_floor', 'edit_floor', 'delete_floor', 'save_settings',
                'send_test_email', 'refresh_cache'];

// State-changing actions additionally require a same-origin request when the
// browser provides an Origin/Referer header. Server-side tools (curl etc.)
// send neither and stay allowed - this app is a local-network tool, not a
// public service.
$mutatingActions = ['add_printer', 'edit_printer', 'delete_printer', 'import_printers',
                    'add_floor', 'edit_floor', 'delete_floor', 'save_settings', 'send_test_email',
                    'refresh_cache'];

if (in_array($action, $bodyActions, true)) {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') === false) {
        sendError('Content-Type must be application/json');
    }
}

if (in_array($action, $mutatingActions, true)) {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $originHost = '';
    if ($origin !== '') {
        $originHost = parse_url($origin, PHP_URL_HOST) ?: '';
    } elseif ($referer !== '') {
        $originHost = parse_url($referer, PHP_URL_HOST) ?: '';
    }
    if ($originHost !== '' && $host !== '' && strcasecmp($originHost, $host) !== 0) {
        sendError('Cross-origin requests are not allowed');
    }
}

// Get the JSON data sent by the JavaScript.
// json_decode can return scalars/null - only arrays may be accessed with [].
$rawBody = file_get_contents('php://input');
$input = $rawBody === '' || $rawBody === false ? [] : json_decode($rawBody, true);
if (!is_array($input)) {
    $input = []; // malformed or non-object body: treat as empty, actions validate their fields
}

try {
    $db = new Database();
    $discovery = new PrinterDiscovery();
    $cacheManager = new CacheManager('cache', 300);

    // Decide what to do based on the "action"
    switch ($action) {
        case 'check_ip':
            // Action: Check if a printer exists at a specific IP
            if (!isset($input['ip']) || !is_string($input['ip']) || $input['ip'] === '') {
                sendError('IP address is required');
            }
            
            // Per-printer SNMP access from the dialog (v1/v2c community or v3)
            $discovery->configureSnmp($input['snmp_auth'] ?? null);

            // Ask PrinterDiscovery to check the IP
            $data = $discovery->detectPrinter($input['ip']);
            
            if ($data) {
                sendResponse($data);
            } else {
                sendError('No printer found at this IP');
            }
            break;

        case 'add_printer':
            // Action: Save a new printer to the database
            if (!Database::isValidIp($input['ip'] ?? null)) {
                sendError('A valid IP address is required');
            }
            
            if (!empty($input['snmp_auth'])) {
                $input['snmp_auth'] = Database::sanitizeSnmpAuth($input['snmp_auth'])
                    ?? sendError('Invalid SNMP settings');
            }
            // Try to add it
            if ($db->addPrinter($input)) {
                sendResponse();
            } else {
                sendError($db->getClientError('Could not add printer'));
            }
            break;

        case 'edit_printer':
            // Action: Update an existing printer
            if (!isset($input['id']) || !is_numeric($input['id'])) {
                sendError('Printer ID is required');
            }
            
            if (isset($input['snmp_auth']) && $input['snmp_auth'] !== '') {
                $clean = Database::sanitizeSnmpAuth($input['snmp_auth'])
                    ?? sendError('Invalid SNMP settings');
                $input['snmp_auth'] = $clean;
            }
            // Try to update it
            if ($db->updatePrinter((int)$input['id'], $input)) {
                sendResponse();
            } else {
                sendError($db->getClientError('Could not update printer'));
            }
            break;

        case 'delete_printer':
            // Action: Remove a printer
            if (!isset($input['id']) || !is_numeric($input['id'])) {
                sendError('Printer ID is required');
            }
            
            // Drop the printer's cached live-data file so no orphan
            // cache entries accumulate for deleted IPs.
            if (isset($input['ip']) && is_string($input['ip'])) {
                $cacheManager->delete('live_' . $input['ip']);
            }

            // Try to delete it
            if ($db->deletePrinter((int)$input['id'])) {
                sendResponse();
            } else {
                sendError('Failed to delete printer: ' . $db->getLastError());
            }
            break;

        case 'export_printers':
            // Action: Export all printers AND floors for complete backup.
            // SECURITY: credential settings (SMTP password, OAuth tokens) are
            // stripped - backup files get shared and must not carry secrets.
            $printers = $db->getPrinters();
            $floors = $db->getFloors();
            $settings = $db->getSettings();
            foreach (Database::SECRET_SETTINGS as $secretKey) {
                unset($settings[$secretKey]);
            }

            // per-printer SNMP credentials are secrets too
            foreach ($printers as &$p) {
                unset($p['snmp_auth']);
            }
            unset($p);

            sendResponse([
                'version' => 2,  // v2: secrets are never included
                'printers' => $printers,
                'floors' => $floors,
                'settings' => $settings,
                'exported_at' => date('Y-m-d H:i:s')
            ]);
            break;

        case 'import_printers':
            // Action: Import printers and floors from JSON
            // Support both new format (with floors) and old format (printers only)
            
            // Handle old format (array of printers) or new format (object with printers key)
            $printers = [];
            $floors = [];
            $settings = [];
            
            if (isset($input['printers']) && is_array($input['printers'])) {
                // New format with structure
                $printers = $input['printers'];
                $floors = $input['floors'] ?? [];
                $settings = $input['settings'] ?? [];
            } elseif (is_array($input) && !empty($input) && isset($input[0]['ip'])) {
                // Old format: direct array of printers
                $printers = $input;
            } else {
                sendError('Invalid data format');
            }
            
            $count = 0;
            $floorCount = 0;
            $errors = [];
            $floorIdMap = []; // Maps old floor IDs to new floor IDs

            // Existing printers by IP: one SELECT instead of one per entry
            $existingByIp = [];
            foreach ($db->getPrinters() as $existingPrinter) {
                $existingByIp[$existingPrinter['ip']] = $existingPrinter;
            }

            // Existing floors by (lowercased) name: re-importing the same
            // backup used to duplicate every floor - now we reuse by name.
            $existingFloors = [];
            foreach ($db->getFloors() as $existingFloor) {
                $existingFloors[mb_strtolower((string)$existingFloor['name'])] = (int)$existingFloor['id'];
            }
            
            // Caps: a hostile or accidental 10k-printer backup must not
            // bloat the database and freeze the dashboard.
            if (count($printers) > 1000 || count($floors) > 100) {
                sendError('Backup too large: max 1000 printers and 100 floors');
            }

            $db->beginTransaction();
            try {
                // First, import floors and build ID mapping
                foreach ($floors as $floor) {
                    if (!is_array($floor) || empty($floor['name']) || !is_string($floor['name'])) continue;

                    $oldId = isset($floor['id']) ? (int)$floor['id'] : null;
                    $nameKey = mb_strtolower($floor['name']);

                    if (isset($existingFloors[$nameKey])) {
                        // Floor already exists - map to it instead of duplicating
                        if ($oldId !== null) {
                            $floorIdMap[$oldId] = $existingFloors[$nameKey];
                        }
                        continue;
                    }

                    // Try to add the floor
                    $newId = $db->addFloor($floor['name'], (int)($floor['sort_order'] ?? 0));

                    if ($newId !== false) {
                        $existingFloors[$nameKey] = $newId;
                        if ($oldId !== null) {
                            $floorIdMap[$oldId] = $newId;
                        }
                        $floorCount++;
                    }
                }

                // Then, import printers with updated floor IDs
                foreach ($printers as $printer) {
                    if (!is_array($printer) || empty($printer['ip']) || !is_string($printer['ip'])) continue;

                    // Invalid IP entries are skipped, not imported
                    if (!Database::isValidIp($printer['ip'])) {
                        $errors[] = "Skipped invalid IP: " . substr($printer['ip'], 0, 64);
                        continue;
                    }

                    // Map old floor_id to new floor_id if applicable
                    if (!empty($printer['floor_id']) && isset($floorIdMap[(int)$printer['floor_id']])) {
                        $printer['floor_id'] = $floorIdMap[(int)$printer['floor_id']];
                    } elseif (!empty($printer['floor_id'])) {
                        // Floor doesn't exist in import, set to null
                        $printer['floor_id'] = null;
                    }

                    // Check if exists (from the preloaded map)
                    $existing = $existingByIp[$printer['ip']] ?? null;

                    if ($existing) {
                        // Update existing
                        if ($db->updatePrinter((int)$existing['id'], $printer)) {
                            $count++;
                        } else {
                            $errors[] = "Failed to update {$printer['ip']}";
                        }
                    } else {
                        // Add new
                        if ($db->addPrinter($printer)) {
                            $count++;
                        } else {
                            $errors[] = "Failed to add {$printer['ip']}";
                        }
                    }
                }

                // Import settings if provided
                foreach ($settings as $key => $value) {
                    if (is_string($key) && in_array($key, ['hide_ip', 'show_serial'], true)) {
                        $db->setSetting($key, (string)$value);
                    }
                }

                $db->commit();
            } catch (Throwable $e) {
                // All-or-nothing: a broken import must not leave half the
                // printers/floors in the database.
                $db->rollBack();
                sendError('Import failed: ' . $e->getMessage());
            }

            sendResponse([
                'printerCount' => $count, 
                'floorCount' => $floorCount,
                'errors' => $errors
            ]);
            break;

        // ==================== FLOOR ENDPOINTS ====================

        case 'get_floors':
            // Action: Get all floors
            $floors = $db->getFloors();
            sendResponse($floors);
            break;

        case 'add_floor':
            // Action: Create a new floor
            if (!isset($input['name']) || !is_string($input['name']) || trim($input['name']) === '') {
                sendError('Floor name is required');
            }
            
            $sortOrder = isset($input['sort_order']) ? (int)$input['sort_order'] : 0;
            $floorId = $db->addFloor($input['name'], $sortOrder);
            
            if ($floorId !== false) {
                sendResponse(['id' => $floorId]);
            } else {
                sendError($db->getClientError('Could not add floor'));
            }
            break;

        case 'edit_floor':
            // Action: Update a floor
            if (!isset($input['id']) || !is_numeric($input['id'])
                || !isset($input['name']) || !is_string($input['name']) || trim($input['name']) === '') {
                sendError('Floor ID and name are required');
            }
            
            $sortOrder = isset($input['sort_order']) ? (int)$input['sort_order'] : null;
            
            if ($db->updateFloor((int)$input['id'], $input['name'], $sortOrder)) {
                sendResponse();
            } else {
                sendError('Failed to update floor: ' . $db->getLastError());
            }
            break;

        case 'delete_floor':
            // Action: Delete a floor
            if (!isset($input['id']) || !is_numeric($input['id'])) {
                sendError('Floor ID is required');
            }
            
            if ($db->deleteFloor((int)$input['id'])) {
                sendResponse();
            } else {
                sendError('Failed to delete floor: ' . $db->getLastError());
            }
            break;

        // ==================== SETTINGS ENDPOINTS ====================

        case 'get_settings':
            // Action: Get all settings.
            // The SMTP password NEVER leaves the server: the browser only
            // learns whether one is stored.
            $settings = $db->getSettings();
            $smtpPassSet = isset($settings['smtp_pass']) && $settings['smtp_pass'] !== '';
            unset($settings['smtp_pass']);
            $settings['smtp_pass_set'] = $smtpPassSet ? '1' : '0';
            // OAuth secrets stay server-side; the browser only learns connection state.
            $connected = !empty($settings['oauth_refresh_token']);
            foreach (['oauth_refresh_token', 'oauth_access_token', 'oauth_token_expires', 'oauth_device_code', 'alert_test_last'] as $secretKey) {
                unset($settings[$secretKey]);
            }
            $settings['oauth_connected'] = $connected ? '1' : '0';
            sendResponse($settings);
            break;

        case 'save_settings':
            // Action: Save multiple settings
            if (!is_array($input)) {
                sendError('Invalid settings data');
            }
            
            $allowed = [
                'hide_ip', 'show_serial',
                'alerts_enabled', 'alert_threshold', 'alert_offline', 'alert_recover',
                'alert_recipients', 'smtp_host', 'smtp_port', 'smtp_secure',
                'smtp_user', 'smtp_pass', 'smtp_from_name',
                'mail_auth_mode', 'oauth_tenant', 'oauth_client_id', 'oauth_client_secret'
            ];
            // Server-only secrets: stored but never accepted from / sent to
            // the browser (set exclusively by the device-code flow).
            $serverOnly = ['oauth_refresh_token', 'oauth_access_token', 'oauth_token_expires'];
            $success = true;
            foreach ($input as $key => $value) {
                // Only allow approved setting keys
                if (!in_array($key, $allowed, true)) continue;
                if (in_array($key, $serverOnly, true)) continue;

                // Central sanitizer: strips control chars (kills CRLF SMTP /
                // header injection), validates charsets and numeric ranges.
                $clean = Database::sanitizeSetting((string)$key, $value);

                // An empty password means "keep the stored one": the browser
                // never receives the stored password, so it cannot send it back.
                if ($key === 'smtp_pass' && ($clean === null || $clean === '')) continue;

                if ($clean === null) continue; // rejected value
                if (!$db->setSetting((string)$key, $clean)) {
                    $success = false;
                }
            }
            
            if ($success) {
                sendResponse();
            } else {
                sendError('Failed to save some settings');
            }
            break;

        case 'refresh_cache':
            // Drop all cached live data; the next page load re-polls.
            sendResponse(['cleared' => $cacheManager->clear()]);
            break;

        case 'send_test_email':
            // Action: send a sample alert email using stored last-known levels
            // (no SNMP in the request path - the email builds instantly).
            $settings = $db->getSettings();

            // Rate limit: one test email per 30s (shared across the LAN -
            // prevents using the victim's 365 mailbox as a spam relay).
            $lastTest = (int)($settings['alert_test_last'] ?? 0);
            if (time() - $lastTest < 30) {
                sendError('Please wait ' . (30 - (time() - $lastTest)) . 's between test emails');
            }
            $db->setSetting('alert_test_last', (string)time());
            $cfg = [
                'alert_recipients' => (string)($input['alert_recipients'] ?? $settings['alert_recipients'] ?? ''),
                'smtp_host' => (string)($input['smtp_host'] ?? $settings['smtp_host'] ?? ''),
                'smtp_port' => (string)($input['smtp_port'] ?? $settings['smtp_port'] ?? ''),
                'smtp_secure' => (string)($input['smtp_secure'] ?? $settings['smtp_secure'] ?? 'auto'),
                'smtp_user' => (string)($input['smtp_user'] ?? $settings['smtp_user'] ?? ''),
                // posted password wins, otherwise fall back to the stored one
                'smtp_pass' => (string)($input['smtp_pass'] ?? '') !== '' ? (string)$input['smtp_pass'] : (string)($settings['smtp_pass'] ?? ''),
                'smtp_from_name' => (string)($input['smtp_from_name'] ?? $settings['smtp_from_name'] ?? 'S~printer'),
                'mail_auth_mode' => (string)($input['mail_auth_mode'] ?? $settings['mail_auth_mode'] ?? 'password'),
                'oauth_tenant' => (string)($settings['oauth_tenant'] ?? ''),
                'oauth_client_id' => (string)($input['oauth_client_id'] ?? $settings['oauth_client_id'] ?? ''),
                'oauth_refresh_token' => (string)($settings['oauth_refresh_token'] ?? ''),
                'oauth_access_token' => (string)($settings['oauth_access_token'] ?? ''),
                'oauth_token_expires' => (int)($settings['oauth_token_expires'] ?? 0),
            ];

            $dbPrinters = $db->getPrinters();
            $view = [];
            foreach ($dbPrinters as $row) {
                $levels = [];
                if (is_string($row['last_levels'] ?? null) && $row['last_levels'] !== '') {
                    $dec = json_decode($row['last_levels'], true);
                    if (is_array($dec)) $levels = $dec;
                }
                $view[$row['ip']] = [
                    'info' => ['full_name' => $row['name'] ?: 'Unknown Printer'],
                    'ip' => $row['ip'],
                    'status' => $levels ? 'online' : 'offline',
                    'levels' => $levels
                ];
            }

            $emailPrinters = AlertService::buildEmailPrinters($view, $dbPrinters);
            $html = AlertService::buildEmailHtml($emailPrinters, time());
            $text = AlertService::buildEmailText($emailPrinters, time());
            $subject = 'S~printer test email - ' . count($emailPrinters) . ' printer(s)';

            $send = AlertService::sendEmail($db, $cfg, $subject, $html, $text);
            if ($send['ok']) {
                $db->setSetting('alerts_last_sent', date('Y-m-d H:i:s'));
                $db->setSetting('alerts_last_error', '');
                sendResponse(['sent' => true]);
            } else {
                $db->setSetting('alerts_last_error', date('Y-m-d H:i:s') . ' ' . $send['error']);
                sendError('Test email failed: ' . $send['error']);
            }
            break;

        case 'oauth_start':
            // Device-code flow start (Microsoft 365 public client)
            $clientId = trim((string)($input['oauth_client_id'] ?? ''));
            $tenant = trim((string)($input['oauth_tenant'] ?? ''));
            // tenant/client_id are interpolated into token URLs: strict charset
            if ($clientId !== '' && !preg_match('/^[A-Za-z0-9._\-]{1,128}$/', $clientId)) sendError('Invalid Client ID');
            if ($tenant !== '' && !preg_match('/^[A-Za-z0-9._\-]{1,128}$/', $tenant)) sendError('Invalid tenant');
            if ($clientId === '') sendError('Client ID is required');
            $start = AlertService::oauthStart($clientId, $tenant);
            if (isset($start['error'])) sendError($start['error']);
            $db->setSetting('oauth_device_code', (string)$start['device_code']);
            sendResponse([
                'verification_uri' => $start['verification_uri'],
                'user_code' => $start['user_code'],
                'message' => $start['message'],
                'expires_in' => $start['expires_in'],
                'interval' => $start['interval']
            ]);
            break;

        case 'oauth_poll':
            $clientId = trim((string)($input['oauth_client_id'] ?? ''));
            $tenant = trim((string)($input['oauth_tenant'] ?? ''));
            if ($clientId !== '' && !preg_match('/^[A-Za-z0-9._\-]{1,128}$/', $clientId)) sendError('Invalid Client ID');
            if ($tenant !== '' && !preg_match('/^[A-Za-z0-9._\-]{1,128}$/', $tenant)) sendError('Invalid tenant');
            $deviceCode = (string)$db->getSingleSetting('oauth_device_code');
            if ($clientId === '' || $deviceCode === '') sendError('Flow not started');
            $poll = AlertService::oauthPoll($db, $clientId, $tenant, $deviceCode);
            if (($poll['status'] ?? '') === 'ok') {
                $db->setSetting('oauth_device_code', '');
                if ($db->getSingleSetting('mail_auth_mode') === '') {
                    $db->setSetting('mail_auth_mode', 'oauth2');
                }
            }
            sendResponse(['status' => $poll['status'], 'message' => $poll['message'] ?? '']);
            break;

        default:
            sendError('Invalid action');
    }
} catch (Throwable $e) {
    // Throwable, not Exception: TypeError/Error also become clean JSON 200
    // responses instead of an HTML fatal error page.
    sendError('Server error: ' . $e->getMessage());
}
