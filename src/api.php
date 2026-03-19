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

require_once 'PrinterDiscovery.php';
require_once 'Database.php';
require_once 'CacheManager.php';

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
$input = json_decode(file_get_contents('php://input'), true);

try {
    $db = new Database();
    $discovery = new PrinterDiscovery();

    // Decide what to do based on the "action"
    switch ($action) {
        case 'check_ip':
            // Action: Check if a printer exists at a specific IP
            if (empty($input['ip'])) {
                sendError('IP address is required');
            }
            
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
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                sendError('Method not allowed');
            }
            if (empty($input['ip'])) {
                sendError('IP address is required');
            }
            
            // Try to add it
            if ($db->addPrinter($input)) {
                // Invalidate cache so dashboard shows the new printer immediately
                (new CacheManager('cache'))->delete('printers_data');
                sendResponse();
            } else {
                // Log detailed error server-side, return generic message (OWASP)
                error_log('Add printer failed: ' . $db->getLastError());
                sendError('Failed to add printer. The IP may already exist.');
            }
            break;

        case 'edit_printer':
            // Action: Update an existing printer
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                sendError('Method not allowed');
            }
            if (empty($input['id'])) {
                sendError('Printer ID is required');
            }
            
            // Try to update it
            if ($db->updatePrinter((int)$input['id'], $input)) {
                // Invalidate cache so dashboard reflects the edit immediately
                (new CacheManager('cache'))->delete('printers_data');
                sendResponse();
            } else {
                error_log('Edit printer failed: ' . $db->getLastError());
                sendError('Failed to update printer');
            }
            break;

        case 'delete_printer':
            // Action: Remove a printer
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                sendError('Method not allowed');
            }
            if (empty($input['id'])) {
                sendError('Printer ID is required');
            }
            
            // Try to delete it
            if ($db->deletePrinter((int)$input['id'])) {
                // Invalidate cache so dashboard removes the printer immediately
                (new CacheManager('cache'))->delete('printers_data');
                sendResponse();
            } else {
                error_log('Delete printer failed: ' . $db->getLastError());
                sendError('Failed to delete printer');
            }
            break;

        case 'export_printers':
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
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                sendError('Method not allowed');
            }
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
            
            // First, import floors and build ID mapping
            foreach ($floors as $floor) {
                if (empty($floor['name'])) continue;
                
                $oldId = $floor['id'] ?? null;
                
                // Try to add the floor
                $newId = $db->addFloor($floor['name'], (int)($floor['sort_order'] ?? 0));
                
                if ($newId !== false && $oldId !== null) {
                    $floorIdMap[$oldId] = $newId;
                    $floorCount++;
                }
            }
            
            // Then, import printers with updated floor IDs
            foreach ($printers as $printer) {
                if (empty($printer['ip'])) continue;
                
                // Map old floor_id to new floor_id if applicable
                if (!empty($printer['floor_id']) && isset($floorIdMap[$printer['floor_id']])) {
                    $printer['floor_id'] = $floorIdMap[$printer['floor_id']];
                } elseif (!empty($printer['floor_id'])) {
                    // Floor doesn't exist in import, set to null
                    $printer['floor_id'] = null;
                }

                // Check if exists
                $existing = $db->getPrinterByIp($printer['ip']);
                
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
                if (in_array($key, ['hide_ip', 'show_serial'])) {
                    $db->setSetting($key, (string)$value);
                }
            }

            // Invalidate cache after import
            (new CacheManager('cache'))->delete('printers_data');

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
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                sendError('Method not allowed');
            }
            if (empty($input['name'])) {
                sendError('Floor name is required');
            }
            
            $sortOrder = isset($input['sort_order']) ? (int)$input['sort_order'] : 0;
            $floorId = $db->addFloor($input['name'], $sortOrder);
            
            if ($floorId !== false) {
                sendResponse(['id' => $floorId]);
            } else {
                error_log('Add floor failed: ' . $db->getLastError());
                sendError('Failed to add floor');
            }
            break;

        case 'edit_floor':
            // Action: Update a floor
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                sendError('Method not allowed');
            }
            if (empty($input['id']) || empty($input['name'])) {
                sendError('Floor ID and name are required');
            }
            
            $sortOrder = isset($input['sort_order']) ? (int)$input['sort_order'] : null;
            
            if ($db->updateFloor((int)$input['id'], $input['name'], $sortOrder)) {
                sendResponse();
            } else {
                error_log('Edit floor failed: ' . $db->getLastError());
                sendError('Failed to update floor');
            }
            break;

        case 'delete_floor':
            // Action: Delete a floor
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                sendError('Method not allowed');
            }
            if (empty($input['id'])) {
                sendError('Floor ID is required');
            }
            
            if ($db->deleteFloor((int)$input['id'])) {
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
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                sendError('Method not allowed');
            }
            // Action: Save multiple settings
            if (!is_array($input)) {
                sendError('Invalid settings data');
            }
            
            $success = true;
            foreach ($input as $key => $value) {
                // Only allow approved setting keys
                if (in_array($key, ['hide_ip', 'show_serial'])) {
                    if (!$db->setSetting($key, (string)$value)) {
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

        default:
            sendError('Invalid action');
    }
} catch (Exception $e) {
    // Log detailed error server-side, return generic message (OWASP)
    error_log('API error: ' . $e->getMessage());
    sendError('An unexpected error occurred');
}
