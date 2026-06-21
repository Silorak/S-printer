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
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

require_once 'PrinterDiscovery.php';
require_once 'Database.php';
require_once 'CacheManager.php';
require_once 'Formatting.php';
require_once 'Auth.php';

const MAX_JSON_BODY_BYTES = 2097152; // 2 MiB import/API request cap
const MAX_IMPORT_PRINTERS = 1000;
const MAX_IMPORT_FLOORS = 200;
const MAX_TEXT_BYTES = 65535;
const MAX_URL_BYTES = 2048;
const MAX_JSON_COLUMN_BYTES = 65535;
const REFRESH_CACHE_TTL_SECONDS = 15;
const ALLOWED_SETTINGS = ['hide_ip' => true, 'show_serial' => true];

function invalidateRuntimeCache(): void {
    (new CacheManager('cache'))->clear();
}

// Helper function to send a success response
// Uses 'never' return type (PHP 8.1+) to indicate this function always halts execution
function sendResponse(mixed $data = null): never {
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

// Helper function to send an error response
function sendError(string $message): never {
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function requirePost(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        sendError('Method not allowed');
    }
}

function readJsonInput(): array {
    $rawInput = file_get_contents('php://input');
    if ($rawInput === false) {
        http_response_code(400);
        sendError('Unable to read request body');
    }

    if (strlen($rawInput) > MAX_JSON_BODY_BYTES) {
        http_response_code(413);
        sendError('Request body is too large');
    }

    if (trim($rawInput) === '') {
        return [];
    }

    $decoded = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        sendError('Invalid JSON body');
    }

    if (!is_array($decoded)) {
        http_response_code(400);
        sendError('JSON body must be an object or array');
    }

    return $decoded;
}

function inputObjectOrFail(mixed $input): array {
    if (!is_array($input)) {
        sendError('Invalid request data');
    }
    return $input;
}

function sanitizeTextValue(mixed $value, int $maxBytes, bool $nullable = true): ?string {
    if ($value === null) {
        return $nullable ? null : '';
    }

    $text = trim((string)$value);
    if ($text === '') {
        return $nullable ? null : '';
    }

    if (strlen($text) > $maxBytes) {
        $text = substr($text, 0, $maxBytes);
    }

    return $text;
}

function sanitizeRequiredText(mixed $value, string $fieldName, int $maxBytes): string {
    $text = sanitizeTextValue($value, $maxBytes, false) ?? '';
    if ($text === '') {
        sendError("{$fieldName} is required");
    }
    return $text;
}

function validateIpOrFail(mixed $value, string $fieldName = 'IP address'): string {
    $ip = trim((string)$value);
    if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
        sendError("Invalid {$fieldName}");
    }
    return $ip;
}

function validatePositiveInt(mixed $value, string $fieldName): int {
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false) {
        sendError("Invalid {$fieldName}");
    }
    return (int)$id;
}

function sanitizeFloorId(mixed $value): ?int {
    if ($value === null || $value === '' || $value === false) {
        return null;
    }

    $floorId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($floorId === false) {
        sendError('Invalid floor ID');
    }

    return (int)$floorId;
}

function sanitizeSortOrder(mixed $value): int {
    if ($value === null || $value === '') {
        return 0;
    }

    $sortOrder = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100000]]);
    if ($sortOrder === false) {
        sendError('Invalid sort order');
    }

    return (int)$sortOrder;
}

function sanitizePurchaseUrl(mixed $value): ?string {
    $url = sanitizeTextValue($value, MAX_URL_BYTES, true);
    if ($url === null) {
        return null;
    }

    if (filter_var($url, FILTER_VALIDATE_URL) === false) {
        sendError('Invalid purchase URL');
    }

    $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?? ''));
    if (!in_array($scheme, ['http', 'https'], true)) {
        sendError('Purchase URL must use http or https');
    }

    return $url;
}

function sanitizeJsonColumnValue(mixed $value, string $fieldName): mixed {
    if ($value === null || $value === '') {
        return null;
    }

    if (is_array($value)) {
        $encoded = json_encode($value);
        if ($encoded === false || strlen($encoded) > MAX_JSON_COLUMN_BYTES) {
            sendError("Invalid {$fieldName}");
        }
        return $value;
    }

    if (is_string($value)) {
        if (strlen($value) > MAX_JSON_COLUMN_BYTES) {
            sendError("{$fieldName} is too large");
        }
        json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            sendError("Invalid {$fieldName}");
        }
        return $value;
    }

    sendError("Invalid {$fieldName}");
}

function sanitizePrinterPayload(mixed $payload, bool $requireIp = true, bool $allowStoredFields = false): array {
    if (!is_array($payload)) {
        sendError('Invalid printer data');
    }

    $clean = [];

    if ($requireIp || array_key_exists('ip', $payload)) {
        if (empty($payload['ip'])) {
            sendError('IP address is required');
        }
        $clean['ip'] = validateIpOrFail($payload['ip']);
    }

    $clean['name'] = sanitizeTextValue($payload['name'] ?? null, 255, true);
    $clean['description'] = sanitizeTextValue($payload['description'] ?? null, 100, true);
    $clean['purchase_url'] = sanitizePurchaseUrl($payload['purchase_url'] ?? null);
    $clean['serial'] = sanitizeTextValue($payload['serial'] ?? ($payload['serial_number'] ?? null), 255, true);
    $clean['model'] = sanitizeTextValue($payload['model'] ?? null, 255, true);
    $clean['vendor'] = sanitizeTextValue($payload['vendor'] ?? null, 255, true);
    $clean['floor_id'] = sanitizeFloorId($payload['floor_id'] ?? null);

    if (array_key_exists('custom_oids', $payload)) {
        $clean['custom_oids'] = sanitizeJsonColumnValue($payload['custom_oids'], 'custom_oids');
    }

    if ($allowStoredFields && array_key_exists('last_levels', $payload)) {
        $clean['last_levels'] = sanitizeJsonColumnValue($payload['last_levels'], 'last_levels');
    }

    return $clean;
}

function normalizeSettingValue(mixed $value, string $key): string {
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }

    if ($value === 1 || $value === '1') {
        return '1';
    }

    if ($value === 0 || $value === '0') {
        return '0';
    }

    sendError("Invalid value for setting {$key}");
}

// Strict Content-Type validation for POST requests (OWASP CSRF prevention)
// application/json triggers CORS preflight; rejecting other types prevents
// attackers from bypassing preflight with text/plain form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') === false) {
        http_response_code(415); // Unsupported Media Type (RFC 7231)
        sendError('Content-Type must be application/json');
    }
}

// Get the "action" parameter from the URL (e.g., api.php?action=add_printer)
$action = $_GET['action'] ?? '';

// Get the JSON data sent by the JavaScript
$input = readJsonInput();

try {
    $db = new Database();
    $currentUser = sprinterRequireApiUser($db);
    sprinterRequireCsrfToken();
    $snmpCommunity = getenv('SNMP_COMMUNITY') ?: 'public';
    $discovery = new PrinterDiscovery($snmpCommunity);

    // Decide what to do based on the "action"
    switch ($action) {
        case 'check_ip':
            requirePost();
            sprinterRequirePermission($currentUser, 'can_add_printer');
            $input = inputObjectOrFail($input);
            $ip = validateIpOrFail($input['ip'] ?? '');

            // Ask PrinterDiscovery to check the IP
            $data = $discovery->detectPrinter($ip);

            if ($data) {
                sendResponse($data);
            } else {
                sendError('No printer found at this IP');
            }
            break;

        case 'add_printer':
            requirePost();
            sprinterRequirePermission($currentUser, 'can_add_printer');
            $printer = sanitizePrinterPayload($input, true);

            // Try to add it
            if ($db->addPrinter($printer)) {
                // Invalidate cache so dashboard shows the new printer immediately
                invalidateRuntimeCache();
                sendResponse();
            } else {
                // Log detailed error server-side, return generic message (OWASP)
                error_log('Add printer failed: ' . $db->getLastError());
                sendError('Failed to add printer. The IP may already exist.');
            }
            break;

        case 'edit_printer':
            requirePost();
            sprinterRequirePermission($currentUser, 'can_edit_printer');
            $input = inputObjectOrFail($input);
            $id = validatePositiveInt($input['id'] ?? null, 'printer ID');
            $printer = sanitizePrinterPayload($input, false);

            // Try to update it
            if ($db->updatePrinter($id, $printer)) {
                // Invalidate cache so dashboard reflects the edit immediately
                invalidateRuntimeCache();
                sendResponse();
            } else {
                error_log('Edit printer failed: ' . $db->getLastError());
                sendError('Failed to update printer');
            }
            break;

        case 'delete_printer':
            requirePost();
            sprinterRequirePermission($currentUser, 'can_delete_printer');
            $input = inputObjectOrFail($input);
            $id = validatePositiveInt($input['id'] ?? null, 'printer ID');

            // Try to delete it
            if ($db->deletePrinter($id)) {
                // Invalidate cache so dashboard removes the printer immediately
                invalidateRuntimeCache();
                sendResponse();
            } else {
                error_log('Delete printer failed: ' . $db->getLastError());
                sendError('Failed to delete printer');
            }
            break;

        case 'export_printers':
            sprinterRequirePermission($currentUser, 'can_import_export');
            // Action: Export all printers AND floors for complete backup
            $printers = $db->getPrinters();
            $floors = $db->getFloors();
            $settings = $db->getSettings();

            sendResponse([
                'version' => 1,  // Export format version for future compatibility
                'printers' => $printers,
                'floors' => $floors,
                'settings' => $settings,
                'exported_at' => date('Y-m-d H:i:s')
            ]);
            break;

        case 'import_printers':
            requirePost();
            sprinterRequirePermission($currentUser, 'can_import_export');
            // Action: Import printers and floors from JSON
            // Support both new format (with floors) and old format (printers only)

            // Handle old format (array of printers) or new format (object with printers key)
            $printers = [];
            $floors = [];
            $settings = [];

            if (isset($input['printers']) && is_array($input['printers'])) {
                // New format with structure
                $printers = $input['printers'];
                $floors = isset($input['floors']) && is_array($input['floors']) ? $input['floors'] : [];
                $settings = isset($input['settings']) && is_array($input['settings']) ? $input['settings'] : [];
            } elseif (is_array($input) && !empty($input) && isset($input[0]['ip'])) {
                // Old format: direct array of printers
                $printers = $input;
            } else {
                sendError('Invalid data format');
            }

            if (count($printers) > MAX_IMPORT_PRINTERS) {
                sendError('Import contains too many printers');
            }
            if (count($floors) > MAX_IMPORT_FLOORS) {
                sendError('Import contains too many floors');
            }

            $count = 0;
            $floorCount = 0;
            $errors = [];
            $floorIdMap = []; // Maps old floor IDs to new floor IDs

            // Use chunked transactions to avoid MariaDB lock/heap pressure on large imports
            $db->beginTransaction();
            try {
                // First, import floors and build ID mapping
                foreach ($floors as $floor) {
                    if (!is_array($floor) || empty($floor['name'])) continue;

                    $oldId = $floor['id'] ?? null;
                    $floorName = sanitizeRequiredText($floor['name'], 'Floor name', 50);
                    $sortOrder = sanitizeSortOrder($floor['sort_order'] ?? 0);

                    // Try to add the floor
                    $newId = $db->addFloor($floorName, $sortOrder);

                    if ($newId !== false && $oldId !== null && (is_int($oldId) || is_string($oldId))) {
                        $floorIdMap[$oldId] = $newId;
                        $floorCount++;
                    }
                }

                $batchSize = 50;
                $batchCounter = 0;

                // Then, import printers with updated floor IDs
                foreach ($printers as $printer) {
                    if (!is_array($printer) || empty($printer['ip'])) continue;

                    // Map old floor_id to new floor_id if applicable
                    $importFloorId = $printer['floor_id'] ?? null;
                    if ((is_int($importFloorId) || is_string($importFloorId)) && $importFloorId !== '' && isset($floorIdMap[$importFloorId])) {
                        $printer['floor_id'] = $floorIdMap[$importFloorId];
                    } elseif (!empty($importFloorId)) {
                        // Floor doesn't exist in import, set to null
                        $printer['floor_id'] = null;
                    }

                    $printer = sanitizePrinterPayload($printer, true, true);

                    // Check if exists
                    $existing = $db->getPrinterByIp($printer['ip']);

                    if ($existing) {
                        // Update existing (restore all properties fully during import)
                        if ($db->updatePrinterFully((int)$existing['id'], $printer)) {
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

                    // Prevent MariaDB lock/heap table exhaustion by chunking transaction commits
                    $batchCounter++;
                    if ($batchCounter >= $batchSize) {
                        $db->commit();
                        $db->beginTransaction();
                        $batchCounter = 0;
                    }
                }

                // Import settings if provided
                foreach ($settings as $key => $value) {
                    if (isset(ALLOWED_SETTINGS[$key])) {
                        $db->setSetting($key, normalizeSettingValue($value, $key));
                    }
                }

                // Invalidate cache after import
                invalidateRuntimeCache();

                // Commit the changes to disk
                $db->commit();
            } catch (Exception $e) {
                // Roll back any changes if something goes wrong
                $db->rollBack();
                error_log('Import failed: ' . $e->getMessage());
                sendError('Import failed. Check server logs for details.');
            }

            sendResponse([
                'printerCount' => $count,
                'floorCount' => $floorCount,
                'errors' => $errors
            ]);
            break;

        case 'refresh_printer':
            // Action: Query a single printer's live status and levels via SNMP in the background
            $ip = validateIpOrFail($_GET['ip'] ?? $input['ip'] ?? '');

            $refreshCache = new CacheManager('cache', REFRESH_CACHE_TTL_SECONDS);
            $cacheKey = 'refresh_printer_' . $ip;
            $cachedRefresh = $refreshCache->get($cacheKey);
            if (is_array($cachedRefresh)) {
                sendResponse($cachedRefresh);
            }

            $dbPrinter = $db->getPrinterByIp($ip);
            if (!$dbPrinter) {
                sendError('Printer not found in database');
            }

            // Query live SNMP data
            $data = $discovery->getPrinterData($dbPrinter);
            $data['info']['serial_number_display'] = cleanSerialNumberForDisplay($data['info']['serial_number'] ?? null);

            // If online, save these levels to the database for future offline use
            if (isset($data['info']['status']) && $data['info']['status'] === 'online') {
                if (!empty($data['levels'])) {
                    $db->updatePrinterLevelsIfChanged((int)$dbPrinter['id'], $data['levels'], $dbPrinter['last_levels'] ?? null);
                }
            }

            $refreshCache->set($cacheKey, $data);

            sendResponse($data);
            break;

        // ==================== FLOOR ENDPOINTS ====================

        case 'get_floors':
            // Action: Get all floors
            $floors = $db->getFloors();
            sendResponse($floors);
            break;

        case 'add_floor':
            requirePost();
            sprinterRequirePermission($currentUser, 'can_manage_floors');
            $input = inputObjectOrFail($input);
            $name = sanitizeRequiredText($input['name'] ?? null, 'Floor name', 50);
            $sortOrder = sanitizeSortOrder($input['sort_order'] ?? 0);

            $floorId = $db->addFloor($name, $sortOrder);

            if ($floorId !== false) {
                sendResponse(['id' => $floorId]);
            } else {
                error_log('Add floor failed: ' . $db->getLastError());
                sendError('Failed to add floor');
            }
            break;

        case 'edit_floor':
            requirePost();
            sprinterRequirePermission($currentUser, 'can_manage_floors');
            $input = inputObjectOrFail($input);
            $id = validatePositiveInt($input['id'] ?? null, 'floor ID');
            $name = sanitizeRequiredText($input['name'] ?? null, 'Floor name', 50);
            $sortOrder = array_key_exists('sort_order', $input) ? sanitizeSortOrder($input['sort_order']) : null;

            if ($db->updateFloor($id, $name, $sortOrder)) {
                sendResponse();
            } else {
                error_log('Edit floor failed: ' . $db->getLastError());
                sendError('Failed to update floor');
            }
            break;

        case 'delete_floor':
            requirePost();
            sprinterRequirePermission($currentUser, 'can_manage_floors');
            $input = inputObjectOrFail($input);
            $id = validatePositiveInt($input['id'] ?? null, 'floor ID');

            if ($db->deleteFloor($id)) {
                sendResponse();
            } else {
                error_log('Delete floor failed: ' . $db->getLastError());
                sendError('Failed to delete floor');
            }
            break;

        // ==================== SETTINGS ENDPOINTS ====================

        case 'get_settings':
            // Action: Get all settings
            $settings = $db->getSettings();
            sendResponse($settings);
            break;

        case 'save_settings':
            requirePost();
            sprinterRequirePermission($currentUser, 'can_manage_settings');
            // Action: Save multiple settings
            $input = inputObjectOrFail($input);

            $success = true;
            foreach ($input as $key => $value) {
                // Only allow approved setting keys
                if (isset(ALLOWED_SETTINGS[$key])) {
                    if (!$db->setSetting($key, normalizeSettingValue($value, $key))) {
                        $success = false;
                    }
                }
            }

            if ($success) {
                sendResponse();
            } else {
                sendError('Failed to save some settings');
            }
            break;

        // ==================== USER MANAGEMENT ENDPOINTS ====================

        case 'get_users':
            sprinterRequirePermission($currentUser, 'can_manage_users');
            sendResponse(sprinterPublicUsers($db->getUsers()));
            break;

        case 'create_user':
            requirePost();
            sprinterRequirePermission($currentUser, 'can_manage_users');
            $input = inputObjectOrFail($input);
            try {
                $username = sprinterNormalizeUsername($input['username'] ?? '');
                $password = (string)($input['password'] ?? '');
                $passwordError = sprinterValidatePassword($password);
                if ($passwordError !== null) {
                    sendError($passwordError);
                }

                $userId = $db->createUser(
                    $username,
                    sprinterHashPassword($password),
                    false,
                    sprinterSanitizePermissions($input['permissions'] ?? []),
                    !array_key_exists('is_active', $input) || !empty($input['is_active'])
                );

                if ($userId === false) {
                    error_log('Create user failed: ' . $db->getLastError());
                    sendError('Failed to create user. The username may already exist.');
                }

                sendResponse(['id' => $userId, 'users' => sprinterPublicUsers($db->getUsers())]);
            } catch (Throwable $e) {
                sendError($e->getMessage());
            }
            break;

        case 'update_user_permissions':
            requirePost();
            sprinterRequirePermission($currentUser, 'can_manage_users');
            $input = inputObjectOrFail($input);
            $id = validatePositiveInt($input['id'] ?? null, 'user ID');
            $targetUser = $db->getUserById($id);
            if (!$targetUser) {
                sendError('User not found');
            }
            if (!empty($targetUser['is_admin'])) {
                sendError('Main admin permissions cannot be changed');
            }

            $permissions = sprinterSanitizePermissions($input['permissions'] ?? []);
            $isActive = !array_key_exists('is_active', $input) || !empty($input['is_active']);

            if (!$db->updateUserPermissions($id, $permissions, $isActive)) {
                error_log('Update user permissions failed: ' . $db->getLastError());
                sendError('Failed to update user');
            }

            if (!empty($input['password'])) {
                $password = (string)$input['password'];
                $passwordError = sprinterValidatePassword($password);
                if ($passwordError !== null) {
                    sendError($passwordError);
                }
                if (!$db->updateUserPasswordHash($id, sprinterHashPassword($password))) {
                    error_log('Update user password failed: ' . $db->getLastError());
                    sendError('Failed to update user password');
                }
            }

            sendResponse(['users' => sprinterPublicUsers($db->getUsers())]);
            break;

        case 'change_own_password':
            requirePost();
            $input = inputObjectOrFail($input);
            $currentPassword = (string)($input['current_password'] ?? '');
            $newPassword = (string)($input['new_password'] ?? '');

            if (!password_verify($currentPassword, (string)$currentUser['password_hash'])) {
                sendError('Current password is incorrect', 403);
            }

            $passwordError = sprinterValidatePassword($newPassword);
            if ($passwordError !== null) {
                sendError($passwordError);
            }

            if (!$db->updateUserPasswordHash((int)$currentUser['id'], sprinterHashPassword($newPassword))) {
                error_log('Change own password failed: ' . $db->getLastError());
                sendError('Failed to change password');
            }

            session_regenerate_id(true);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            sendResponse(['csrfToken' => sprinterCsrfToken()]);
            break;

        default:
            sendError('Invalid action');
    }
} catch (Throwable $e) {
    // Log detailed error server-side, return generic message (OWASP)
    error_log('API error: ' . $e->getMessage());
    sendError('An unexpected error occurred');
}
