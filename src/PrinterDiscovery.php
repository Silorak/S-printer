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
    public function __construct(string $community = 'public', int $timeout = 500000, int $retries = 2) {
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
                    $info[$key] = $this->sanitizeSnmpValue($value);
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
                
                if ($max <= 0) continue;
                
                $percentage = round(($current / $max) * 100, 2);
                $color = $this->determineColor($supply['description']);
                
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
        
        // Map of OIDs for different brands
        $oidMappings = [
            'HP' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1', 'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.2', 'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.3', 'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.4', 'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.4'
            ],
            'TOSHIBA' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.2', 'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.3', 'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.6', 'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.6',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.9', 'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.9'
            ],
            'CANON' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1', 'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.2', 'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.3', 'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.4', 'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.4'
            ],
            'EPSON' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1', 'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.2', 'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.3', 'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.4', 'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.4',
                'max_light_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.5', 'light_cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.5',
                'max_light_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.6', 'light_magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.6'
            ],
            // Brother laser printers use standard MIB, inkjets are handled separately below
            'BROTHER' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1', 'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.2', 'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.3', 'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.4', 'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.4'
            ],
            'RICOH' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1', 'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.2', 'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.3', 'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.4', 'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.4'
            ],
            'LEXMARK' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1', 'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.2', 'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.3', 'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.4', 'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.4'
            ],
            'KYOCERA' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1', 'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.2', 'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.3', 'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.4', 'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.4'
            ],
            'KONICA' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1', 'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.2', 'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.3', 'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.4', 'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.4'
            ],
            'XEROX' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1', 'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.2', 'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.3', 'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.4', 'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.4'
            ],
            'SAMSUNG' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1', 'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.2', 'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.3', 'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.4', 'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.4'
            ],
            'PANTUM' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1', 'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1'
            ],
            'GENERIC' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1', 'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.2', 'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.3', 'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.4', 'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.4'
            ]
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
        
        // Special case for Brother inkjet printers (MFC-J series, DCP-J series, etc.)
        // These use a proprietary OID that returns hex-encoded ink levels
        if ($vendorName === 'BROTHER' && isset($sysDescr)) {
            $sysDescrClean = $this->sanitizeSnmpValue($sysDescr);
            // Check if it's an inkjet model (models containing -J typically are inkjets)
            if (preg_match('/MFC-J|DCP-J|MFC-INK|DCP-INK/i', $sysDescrClean)) {
                $brotherLevels = $this->getBrotherInkjetLevels($ip);
                if (!empty($brotherLevels)) {
                    return $brotherLevels;
                }
            }
            // Brother mono laser printers (DCP-L, HL-L, MFC-L series)
            // Skip direct OIDs — the hardcoded index mapping assumes color order,
            // but mono lasers have Drum at index 1 and Black Toner at index 2.
            // Let the standard MIB walk use supply descriptions to identify correctly.
            if (preg_match('/DCP-L|HL-L|MFC-L/i', $sysDescrClean)) {
                return [];
            }
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
                
                // Handle special SNMP return codes:
                // -3 = Supply is not low (no exact level available - skip)
                // -2 = Unknown status (skip)
                // 0 or less = Empty or not genuine cartridge
                if ($current == -3 || $current == -2) {
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
        // Handle Hex-STRING values: convert hex bytes to ASCII
        // e.g., "Hex-STRING: 48 50" → "HP", "Hex-STRING: 00 00 00 00" → "N/A"
        if (preg_match('/^Hex-STRING:\s*/i', $value)) {
            $hexPart = trim(preg_replace('/^Hex-STRING:\s*/i', '', $value));
            $hexBytes = str_replace(' ', '', $hexPart);
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
        
        if (preg_match('/black|noir|negro|k\b|\bk/i', $description)) return 'black';
        if (preg_match('/cyan|blue|c\b|\bc/i', $description)) return 'cyan';
        if (preg_match('/magenta|pink|m\b|\bm/i', $description)) return 'magenta';
        if (preg_match('/yellow|jaune|y\b|\by/i', $description)) return 'yellow';
        
        // Fallback: if it says "toner" but no color, assume black
        if (strpos($description, 'toner') !== false) return 'black';
        
        return 'other';
    }
    
    /**
     * Get Brother Inkjet Levels
     * 
     * Brother inkjet printers (MFC-J, DCP-J series) use a proprietary OID
     * that returns hex-encoded ink level data.
     * OID: .1.3.6.1.4.1.2435.2.3.9.4.2.1.5.5.8.0 (brInfoMaintenance)
     */
    private function getBrotherInkjetLevels(string $ip): array {
        $levels = [];
        
        // Try the Brother proprietary OID for ink levels
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
        
        // The response is a hex string. Try to parse it.
        // Format: The hex data contains 7-byte groups where:
        // Byte 1: Color identifier (0x81=Black, 0x82=Cyan, 0x83=Magenta, 0x84=Yellow)
        // Byte 5 or 6: Percentage value
        
        // Convert response to clean hex
        $cleanValue = $this->sanitizeSnmpValue($response);
        
        // Remove "Hex-STRING: " prefix if present
        $cleanValue = preg_replace('/^Hex-STRING:\s*/i', '', $cleanValue);
        
        // Convert spaces and format to pure hex
        $hexData = str_replace([' ', '\n', '\r'], '', $cleanValue);
        
        // Try to find color markers and their levels
        // Brother format: 81 XX XX XX YY (where YY is the percentage for black)
        $colorMap = [
            '81' => 'black',
            '82' => 'cyan', 
            '83' => 'magenta',
            '84' => 'yellow'
        ];
        
        // Method 1: Look for the pattern where ink levels are encoded
        // Each cartridge info block is typically 7 bytes
        foreach ($colorMap as $marker => $color) {
            $pos = strpos(strtoupper($hexData), strtoupper($marker));
            if ($pos !== false && $pos + 10 <= strlen($hexData)) {
                // Get the byte at offset +8 (4 bytes = 8 hex chars after marker)
                // This is where the percentage typically is
                $percentHex = substr($hexData, $pos + 8, 2);
                $percentage = hexdec($percentHex);
                
                // Validate percentage is reasonable (0-100)
                if ($percentage >= 0 && $percentage <= 100) {
                    $levels[$color] = [
                        'percentage' => $percentage,
                        'current' => $percentage,
                        'max' => 100,
                        'color' => $color,
                        'description' => ucfirst($color) . ' Ink'
                    ];
                    
                    // Add status for empty/not genuine
                    if ($percentage <= 0) {
                        $levels[$color]['status'] = 'empty_or_not_genuine';
                    }
                }
            }
        }
        
        // If method 1 didn't work, try alternative Brother OIDs
        if (empty($levels)) {
            // Try individual ink level OIDs (some Brother models use these)
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