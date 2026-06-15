<?php
declare(strict_types=1);

/**
 * PrinterDiscovery Class
 * 
 * This class is responsible for talking to printers using SNMP (Simple Network Management Protocol).
 * It does NOT scan the network anymore. Instead, it checks specific IPs that you provide.
 * 
 * It can:
 * 1. Check if a printer exists at an IP.
 * 2. Get the printer's name, model, and serial number.
 * 3. Get the current ink/toner levels.
 */
class PrinterDiscovery {
    private string $community; // The SNMP password (usually 'public')
    private int $timeout;      // How long to wait for a response
    private int $retries;      // How many times to try if it fails
    
    /**
     * Constructor
     * 
     * Sets up the SNMP settings.
     */
    public function __construct(string $community = 'public', int $timeout = 200000, int $retries = 1) {
        $this->community = $community;
        $this->timeout = $timeout;
        $this->retries = $retries;
    }
    
    /**
     * Detect Printer
     * 
     * Checks a single IP address to see if a printer is there.
     * Used when you click "Check IP" in the "Add Printer" modal.
     * 
     * @param string $ip The IP address to check
     * @return array|null The printer info if found, or null if not
     */
    public function detectPrinter(string $ip): ?array {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }

        // Try to get the system description via SNMP.
        // If this works, there is a device there!
        $sysDescr = @snmp2_get($ip, $this->community, '.1.3.6.1.2.1.1.1.0', $this->timeout, $this->retries);
        if ($sysDescr === false) {
            return null;
        }

        // If found, get all the details
        $sysInfo = $this->getSysInfo($ip);
        $printerInfo = $this->getPrinterInfo($ip);
        
        // Combine them into one array
        return array_merge($sysInfo, $printerInfo);
    }

    /**
     * Get Printer Data (Live)
     * 
     * Fetches the latest status and ink levels for a printer that is already in our database.
     * 
     * @param array $dbPrinter The printer record from our database
     * @return array The live data (status, levels, etc.)
     */
    public function getPrinterData(array $dbPrinter): array {
        $ip = $dbPrinter['ip'];
        
        // Start with the data we have in the database
        $printerData = [
            'info' => [
                'ip' => $ip,
                'last_updated' => time(),
                'full_name' => $dbPrinter['name'] ?: ($dbPrinter['model'] ?: 'Unknown Printer'),
                'description' => $dbPrinter['description'],
                'purchase_url' => $dbPrinter['purchase_url'],
                'serial_number' => $dbPrinter['serial'],
                'model' => $dbPrinter['model'],
                'last_levels' => $dbPrinter['last_levels'] ?? null, // Include saved levels for offline display
                'status' => 'offline' // Assume offline until proven otherwise
            ],
            'levels' => []
        ];
        
        try {
            // Try to contact the printer
            $sysDescr = @snmp2_get($ip, $this->community, '.1.3.6.1.2.1.1.1.0', $this->timeout, $this->retries);
            
            if ($sysDescr !== false) {
                // It answered! It's online.
                $printerData['info']['status'] = 'online';
                
                // Get the current ink/toner levels
                $printerData['levels'] = $this->getSupplyLevels($ip);
            }
        } catch (Throwable $e) {
            // If anything goes wrong, it stays "offline"
        }
        
        return $printerData;
    }
    
    /**
     * Get System Info
     * 
     * Gets basic device info like Name, Location, and Uptime.
     */
    private function getSysInfo(string $ip): array {
        $sysInfo = [];
        
        // List of "OIDs" (Object Identifiers) to ask for
        $sysOids = [
            'sys_name' => '.1.3.6.1.2.1.1.5.0',
            'sys_description' => '.1.3.6.1.2.1.1.1.0',
            'sys_location' => '.1.3.6.1.2.1.1.6.0',
            'sys_contact' => '.1.3.6.1.2.1.1.4.0',
            'sys_uptime' => '.1.3.6.1.2.1.1.3.0'
        ];
        
        foreach ($sysOids as $key => $oid) {
            $value = @snmp2_get($ip, $this->community, $oid, $this->timeout, $this->retries);
            if ($value !== false) {
                $sysInfo[$key] = $this->sanitizeSnmpValue($value);
            }
        }
        
        // Try to guess the vendor (HP, Canon, etc.) from the description
        if (isset($sysInfo['sys_description'])) {
            $sysInfo['vendor'] = $this->detectVendor($sysInfo['sys_description']);
        }
        
        return $sysInfo;
    }
    
    /**
     * Get Printer Info
     * 
     * Gets printer-specific details like Model and Serial Number.
     * Different brands use different OIDs, so we try a few.
     */
    private function getPrinterInfo(string $ip): array {
        $info = [];
        
        $printerOids = [
            'full_name' => [
                '.1.3.6.1.2.1.25.3.2.1.3.1',
                '.1.3.6.1.2.1.43.5.1.1.16.1',
                '.1.3.6.1.4.1.11.2.3.9.4.2.1.1.3.2.0' // HP-specific
            ],
            'serial_number' => [
                '.1.3.6.1.2.1.43.5.1.1.17.1',
                '.1.3.6.1.2.1.43.5.1.1.17.1.1',
                '.1.3.6.1.4.1.11.2.3.9.4.2.1.1.3.3.0' // HP-specific
            ],
            'model' => [
                '.1.3.6.1.2.1.25.3.2.1.3.1',
                '.1.3.6.1.2.1.43.5.1.1.16.1'
            ]
        ];
        
        foreach ($printerOids as $key => $oids) {
            foreach ($oids as $oid) {
                $value = @snmp2_get($ip, $this->community, $oid, $this->timeout, $this->retries);
                if ($value !== false) {
                    $cleanedVal = $this->sanitizeSnmpValue($value);
                    if ($key === 'full_name' || $key === 'model') {
                        // Strip out firmware info e.g. " /5.114..." or " /CXIGY..."
                        $cleanedVal = preg_replace('/\s*\/[0-9a-zA-Z\.\-_]{2,}.*$/i', '', $cleanedVal);
                        $cleanedVal = trim($cleanedVal);
                    }
                    $info[$key] = $cleanedVal;
                    break; // Found it! Stop looking for this item.
                }
            }
        }
        
        return $info;
    }
    
    /**
     * Get Supply Levels
     * 
     * The magic part: asks the printer how much ink/toner is left.
     */
    private function getSupplyLevels(string $ip): array {
        $levels = [];
        
        try {
            // 1. Try vendor-specific shortcuts first (faster/more reliable for some)
            $directLevels = $this->getDirectSupplyLevels($ip);
            if (!empty($directLevels)) {
                return $directLevels;
            }
            
            // 2. If that fails, do a full "Walk" of the standard printer MIB
            // This asks for EVERYTHING in the supply table
            $supplyTable = @snmp2_real_walk($ip, $this->community, '.1.3.6.1.2.1.43.11.1.1', $this->timeout, $this->retries);
            
            if ($supplyTable === false) {
                return $levels;
            }
            
            $supplies = [];
            
            // Parse the raw SNMP results
            foreach ($supplyTable as $oid => $value) {
                // Get Description
                if (preg_match('/\.1\.3\.6\.1\.2\.1\.43\.11\.1\.1\.6\.1\.(\d+)$/', $oid, $matches)) {
                    $supplies[$matches[1]]['description'] = $this->sanitizeSnmpValue($value);
                }
                // Get Max Capacity
                if (preg_match('/\.1\.3\.6\.1\.2\.1\.43\.11\.1\.1\.8\.1\.(\d+)$/', $oid, $matches)) {
                    $supplies[$matches[1]]['max'] = $this->sanitizeSnmpValue($value);
                }
                // Get Current Level
                if (preg_match('/\.1\.3\.6\.1\.2\.1\.43\.11\.1\.1\.9\.1\.(\d+)$/', $oid, $matches)) {
                    $supplies[$matches[1]]['current'] = $this->sanitizeSnmpValue($value);
                }
            }
            
            // Calculate percentages
            foreach ($supplies as $index => $supply) {
                if (!isset($supply['description'], $supply['max'], $supply['current'])) continue;
                
                $max = (float)$supply['max'];
                $current = (float)$supply['current'];
                $color = $this->determineColor($supply['description']);
                if ($color === 'other') continue; // Hide other levels like drum units
                
                // Handle state-based toner reporting (RFC 3805 / Brother)
                if ($current == -3) {
                    // Level is OK / not low, map to 100% with status 'ok'
                    $levels[$color] = [
                        'percentage' => 100,
                        'current' => 100,
                        'max' => 100,
                        'color' => $color,
                        'description' => $supply['description'],
                        'status' => 'ok'
                    ];
                    continue;
                }
                
                if ($current == -2) {
                    continue; // Skip if status is completely unknown
                }
                
                if ($max <= 0) continue;
                
                $percentage = round(($current / $max) * 100, 2);
                
                // Check for 0% or negative values (empty or not genuine)
                if ($percentage <= 0) {
                    $levels[$color] = [
                        'percentage' => 0,
                        'current' => $current,
                        'max' => $max,
                        'color' => $color,
                        'description' => $supply['description'],
                        'status' => 'empty_or_not_genuine'
                    ];
                    continue;
                }
                
                $levels[$color] = [
                    'percentage' => $percentage,
                    'current' => $current,
                    'max' => $max,
                    'color' => $color,
                    'description' => $supply['description']
                ];
            }
        } catch (Throwable $e) {
            error_log("Error getting supply levels for {$ip}: " . $e->getMessage());
        }
        
        return $levels;
    }
    
    /**
     * Get Direct Supply Levels
     * 
     * Uses specific OIDs for known brands (HP, Brother, etc.) if the standard way fails.
     */
    private function getDirectSupplyLevels(string $ip): array {
        $levels = [];
        
        // First, figure out who made this printer
        $vendorName = '';
        $sysDescr = @snmp2_get($ip, $this->community, '.1.3.6.1.2.1.1.1.0', $this->timeout, $this->retries);
        if ($sysDescr !== false) {
            $vendorName = $this->detectVendor($this->sanitizeSnmpValue($sysDescr));
        }
        
        // Standard 4-color OID mapping (used by most vendors)
        $standardMap = [
            'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1', 'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1',
            'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.2', 'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
            'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.3', 'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
            'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.4', 'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.4'
        ];
 
        // Map of OIDs for different brands
        $oidMappings = [
            'HP' => $standardMap,
            'TOSHIBA' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.2', 'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.3', 'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.6', 'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.6',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.9', 'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.9'
            ],
            'CANON' => $standardMap,
            'EPSON' => array_merge($standardMap, [
                'max_light_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.5', 'light_cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.5',
                'max_light_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.6', 'light_magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.6'
            ]),
            // Brother laser printers use standard MIB, inkjets are handled separately below
            'BROTHER' => $standardMap,
            'RICOH' => $standardMap,
            'LEXMARK' => $standardMap,
            'KYOCERA' => $standardMap,
            'KONICA' => $standardMap,
            'XEROX' => $standardMap,
            'SAMSUNG' => $standardMap,
            'PANTUM' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1', 'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1'
            ],
            'GENERIC' => $standardMap
        ];
        
        // Use Generic if unknown
        $oids = $oidMappings[$vendorName] ?? $oidMappings['GENERIC'];
 
        // Special case for TOSHIBA e-STUDIO409S (Monochrome)
        // The generic Toshiba map includes color OIDs which map to Imaging Units on this model.
        if ($vendorName === 'TOSHIBA' && isset($sysDescr) && stripos($sysDescr, 'e-STUDIO409S') !== false) {
             $oids = [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.2', 'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.2'
            ];
        }
        
        // Special case for Brother printers (inkjet and laser models alike)
        // These use a proprietary OID (brInfoMaintenance) that returns hex-encoded consumable data
        if ($vendorName === 'BROTHER' && isset($sysDescr)) {
            $brotherLevels = $this->getBrotherConsumableLevels($ip);
            if (!empty($brotherLevels)) {
                return $brotherLevels;
            }
            // If the proprietary check failed/returned nothing, fall back to standard MIB walk
            return [];
        }
        $data = [];
        
        foreach ($oids as $key => $oid) {
            $value = @snmp2_get($ip, $this->community, $oid, $this->timeout, $this->retries);
            if ($value !== false) {
                $data[$key] = (float)$this->sanitizeSnmpValue($value);
            }
        }
        
        // Calculate percentages from the data we found
        $colors = ['black', 'cyan', 'magenta', 'yellow'];
        foreach ($colors as $color) {
            if (isset($data["max_{$color}"], $data[$color])) {
                $max = $data["max_{$color}"];
                $current = $data[$color];
                
                // Handle state-based toner reporting (RFC 3805 / Brother)
                if ($current == -3) {
                    $levels[$color] = [
                        'percentage' => 100,
                        'current' => 100,
                        'max' => 100,
                        'color' => $color,
                        'description' => ucfirst($color) . ' supply',
                        'status' => 'ok'
                    ];
                    continue;
                }
                
                // Handle special SNMP return codes:
                // -3 = Supply is not low (handled above)
                // -2 = Unknown status (skip)
                // 0 or less = Empty or not genuine cartridge
                if ($current == -2) {
                    // Printer doesn't report exact levels for this cartridge - skip it
                    continue;
                }
                
                if ($max <= 0) continue;
                
                $percentage = round(($current / $max) * 100, 2);
                
                // Check for 0% or negative values (empty or not genuine)
                if ($percentage <= 0) {
                    $levels[$color] = [
                        'percentage' => 0,
                        'current' => $current,
                        'max' => $max,
                        'color' => $color,
                        'description' => ucfirst($color) . ' supply',
                        'status' => 'empty_or_not_genuine'
                    ];
                    continue;
                }
                
                $levels[$color] = [
                    'percentage' => $percentage,
                    'current' => $current,
                    'max' => $max,
                    'color' => $color,
                    'description' => ucfirst($color) . ' supply'
                ];
            }
        }
        
        return $levels;
    }
    
    /**
     * Sanitize SNMP Value
     * 
     * Cleans up the raw text returned by the printer (removes "STRING:", quotes, etc.)
     */
    private function sanitizeSnmpValue(string $value): string {
        // Handle Hex-STRING values: convert hex bytes to ASCII or numeric integer
        // e.g., "Hex-STRING: 48 50" → "HP", "Hex-STRING: 00 00 00 64" → "100", "Hex-STRING: 00 00 00 00" → "0"
        if (preg_match('/^Hex-STRING:\s*/i', $value)) {
            $hexPart = trim(preg_replace('/^Hex-STRING:\s*/i', '', $value));
            $hexBytes = str_replace(' ', '', $hexPart);
            
            // First check if it represents a clean printable ASCII string
            $ascii = '';
            $isPrintable = true;
            for ($i = 0; $i < strlen($hexBytes); $i += 2) {
                $byte = hexdec(substr($hexBytes, $i, 2));
                if ($byte < 32 || $byte > 126) {
                    $isPrintable = false;
                }
                $ascii .= chr($byte);
            }
            
            if ($isPrintable && strlen($hexBytes) > 0) {
                return trim($ascii);
            }
            
            // If not printable ASCII, it might be an encoded integer (e.g. "00 00 00 64" = 100)
            if (strlen($hexBytes) <= 8) {
                return (string)hexdec($hexBytes);
            }
            
            // Otherwise fallback to filtering printable characters
            $ascii = '';
            for ($i = 0; $i < strlen($hexBytes); $i += 2) {
                $byte = hexdec(substr($hexBytes, $i, 2));
                if ($byte >= 32 && $byte <= 126) {
                    $ascii .= chr($byte);
                }
            }
            $ascii = trim($ascii);
            return $ascii !== '' ? $ascii : 'N/A';
        }

        $clean = trim(preg_replace([
            '/^STRING:\s*"/', 
            '/"$/', 
            '/^(INTEGER|Counter32|Gauge32|Timeticks):\s*/i'
        ], '', $value));

        // Decode hex numbers prefixed with 0x (common for Pantum printers)
        if (preg_match('/^0x([0-9a-fA-F]+)$/i', $clean, $matches)) {
            return (string)hexdec($matches[1]);
        }

        return (string)$clean;
    }
    
    /**
     * Detect Vendor
     * 
     * Guesses the brand based on the description text.
     */
    private function detectVendor(string $description): string {
        $description = strtolower($description);
        $vendors = [
            'HP' => ['hp', 'hewlett', 'laserjet', 'officejet'],
            'CANON' => ['canon', 'pixma'],
            'EPSON' => ['epson', 'workforce'],
            'BROTHER' => ['brother', 'mfc'],
            'LEXMARK' => ['lexmark'],
            'XEROX' => ['xerox', 'phaser'],
            'KYOCERA' => ['kyocera'],
            'RICOH' => ['ricoh'],
            'KONICA' => ['konica', 'minolta'],
            'SAMSUNG' => ['samsung'],
            'TOSHIBA' => ['toshiba'],
            'PANTUM' => ['pantum']
        ];
        
        foreach ($vendors as $vendor => $keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($description, $keyword) !== false) return $vendor;
            }
        }
        return 'GENERIC';
    }
    
    /**
     * Determine Color
     * 
     * Guesses the ink color based on the cartridge name (e.g., "Black Cartridge" -> "black").
     */
    private function determineColor(string $description): string {
        $description = strtolower($description);
        
        if (preg_match('/\b(black|noir|negro|k)\b/i', $description)) return 'black';
        if (preg_match('/\b(cyan|blue|c)\b/i', $description)) return 'cyan';
        if (preg_match('/\b(magenta|pink|m)\b/i', $description)) return 'magenta';
        if (preg_match('/\b(yellow|jaune|y)\b/i', $description)) return 'yellow';
        
        // Fallback: if it says "toner" but no color, assume black
        if (strpos($description, 'toner') !== false) return 'black';
        
        return 'other';
    }
    
    /**
     * Get Brother Consumable Levels
     * 
     * Brother printers (both inkjets like MFC-J and modern lasers like DCP-L/MFC-L)
     * use a proprietary OID (brInfoMaintenance) that returns hex-encoded consumable data.
     * OID: .1.3.6.1.4.1.2435.2.3.9.4.2.1.5.5.8.0 (or .1.3.6.1.4.1.2435.2.3.9.4.2.1.5.5.8)
     */
    private function getBrotherConsumableLevels(string $ip): array {
        $levels = [];
        
        // Try the Brother proprietary OID for maintenance details
        $brotherOid = '.1.3.6.1.4.1.2435.2.3.9.4.2.1.5.5.8.0';
        $response = @snmp2_get($ip, $this->community, $brotherOid, $this->timeout, $this->retries);
        
        if ($response === false) {
            // Try without the .0 suffix
            $brotherOid = '.1.3.6.1.4.1.2435.2.3.9.4.2.1.5.5.8';
            $response = @snmp2_get($ip, $this->community, $brotherOid, $this->timeout, $this->retries);
        }
        
        if ($response === false) {
            return $levels;
        }
        
        // Clean up the hex representation
        if (preg_match('/^Hex-STRING:\s*/i', $response)) {
            $cleanValue = preg_replace('/^Hex-STRING:\s*/i', '', $response);
            $hexData = str_replace([' ', "\n", "\r"], '', $cleanValue);
        } else {
            $hexData = bin2hex($response);
        }
        
        // Try to parse using our robust regex segment pattern: [ID] 01 04 [4-byte value]
        if (preg_match_all('/([0-9a-fA-F]{2})0104([0-9a-fA-F]{8})/i', $hexData, $matches, PREG_SET_ORDER)) {
            $colorMap = [
                '81' => 'black',
                '82' => 'cyan', 
                '83' => 'magenta',
                '84' => 'yellow',
                '41' => 'other', // Drum Unit
                '51' => 'other', // Drum Unit alt 1
                '52' => 'other'  // Drum Unit alt 2
            ];
            
            foreach ($matches as $match) {
                $marker = strtoupper($match[1]);
                $valHex = $match[2];
                
                if (isset($colorMap[$marker])) {
                    $color = $colorMap[$marker];
                    if ($color === 'other') continue; // Hide other levels like drum units
                    
                    $valDec = hexdec($valHex);
                    
                    // Decode percentage or status
                    // Brother represents remaining life as either:
                    // 1) 0-100 directly (e.g. 00000046 = 70%)
                    // 2) 0-10000 representing 0.01% units (e.g. 0000238c = 9100 / 100 = 91%)
                    $percentage = $valDec;
                    if ($valDec > 100) {
                        $percentage = $valDec / 100.0;
                    }
                    $percentage = min(100.0, max(0.0, $percentage));
                    
                    $description = ucfirst($color) . ' Toner';
                    if ($marker === '41' || $marker === '51' || $marker === '52') {
                        $description = 'Drum Unit';
                    }
                    
                    $levels[$color] = [
                        'percentage' => $percentage,
                        'current' => $percentage,
                        'max' => 100,
                        'color' => $color,
                        'description' => $description
                    ];
                    
                    if ($percentage <= 0) {
                        $levels[$color]['status'] = 'empty_or_not_genuine';
                    }
                }
            }
        }
        
        // If brInfoMaintenance returned nothing, try individual ink level OIDs as fallback
        if (empty($levels)) {
            $inkOids = [
                'black' => '.1.3.6.1.4.1.2435.2.3.9.4.2.1.5.5.10.0',
                'cyan' => '.1.3.6.1.4.1.2435.2.3.9.4.2.1.5.5.11.0',
                'magenta' => '.1.3.6.1.4.1.2435.2.3.9.4.2.1.5.5.12.0',
                'yellow' => '.1.3.6.1.4.1.2435.2.3.9.4.2.1.5.5.13.0'
            ];
            
            foreach ($inkOids as $color => $oid) {
                $value = @snmp2_get($ip, $this->community, $oid, $this->timeout, $this->retries);
                if ($value !== false) {
                    $percentage = (int)$this->sanitizeSnmpValue($value);
                    if ($percentage >= 0 && $percentage <= 100) {
                        $levels[$color] = [
                            'percentage' => $percentage,
                            'current' => $percentage,
                            'max' => 100,
                            'color' => $color,
                            'description' => ucfirst($color) . ' Ink'
                        ];
                        
                        if ($percentage <= 0) {
                            $levels[$color]['status'] = 'empty_or_not_genuine';
                        }
                    }
                }
            }
        }
        
        return $levels;
    }
}