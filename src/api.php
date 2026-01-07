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
            if (empty($input['ip'])) {
                sendError('IP address is required');
            }
            
            // Try to add it
            if ($db->addPrinter($input)) {
                sendResponse();
            } else {
                // If it fails, send back the specific database error
                sendError('Failed to add printer: ' . $db->getLastError());
            }
            break;

        case 'edit_printer':
            // Action: Update an existing printer
            if (empty($input['id'])) {
                sendError('Printer ID is required');
            }
            
            // Try to update it
            if ($db->updatePrinter((int)$input['id'], $input)) {
                sendResponse();
            } else {
                sendError('Failed to update printer: ' . $db->getLastError());
            }
            break;

        case 'delete_printer':
            // Action: Remove a printer
            if (empty($input['id'])) {
                sendError('Printer ID is required');
            }
            
            // Try to delete it
            if ($db->deletePrinter((int)$input['id'])) {
                sendResponse();
            } else {
                sendError('Failed to delete printer: ' . $db->getLastError());
            }
            break;

        case 'export_printers':
            // Action: Get all printers for export
            $printers = $db->getPrinters();
            sendResponse($printers);
            break;

        case 'import_printers':
            // Action: Import printers from JSON
            if (empty($input['printers']) || !is_array($input['printers'])) {
                sendError('Invalid data format');
            }

            $count = 0;
            $errors = [];

            foreach ($input['printers'] as $printer) {
                if (empty($printer['ip'])) continue;

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

            sendResponse(['count' => $count, 'errors' => $errors]);
            break;

        // ==================== FLOOR ENDPOINTS ====================

        case 'get_floors':
            // Action: Get all floors
            $floors = $db->getFloors();
            sendResponse($floors);
            break;

        case 'add_floor':
            // Action: Create a new floor
            if (empty($input['name'])) {
                sendError('Floor name is required');
            }
            
            $sortOrder = isset($input['sort_order']) ? (int)$input['sort_order'] : 0;
            $floorId = $db->addFloor($input['name'], $sortOrder);
            
            if ($floorId !== false) {
                sendResponse(['id' => $floorId]);
            } else {
                sendError('Failed to add floor: ' . $db->getLastError());
            }
            break;

        case 'edit_floor':
            // Action: Update a floor
            if (empty($input['id']) || empty($input['name'])) {
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
            if (empty($input['id'])) {
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
            // Action: Get all settings
            $settings = $db->getSettings();
            sendResponse($settings);
            break;

        case 'save_settings':
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
    sendError('Server error: ' . $e->getMessage());
}
