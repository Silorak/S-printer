<?php
declare(strict_types=1);

class PrinterDiscovery {
    private string $community;
    private int $timeout;
    private int $retries;
    private array $knownPrinters; // A list of known printer IPs for direct checking
    
    /**
     * Constructor
     */
    public function __construct(string $community = 'public', int $timeout = 500000, int $retries = 2) {
        $this->community = $community;
        $this->timeout = $timeout;
        $this->retries = $retries;
        $this->knownPrinters = require_once 'known_printers.php';
    }
    
    /**
     * Discover printers on the network
     *
     * @param array $subnets List of subnets to scan in CIDR notation (e.g. ['192.168.1.0/24', '10.0.0.0/24'])
     * @param int $maxCount Maximum number of IPs to scan per subnet
     * @return array Discovered printers with their data
     */
    public function discoverPrinters(array $subnets = [], int $maxCount = 100): array {
        $discoveredPrinters = [];
        
        // First check known printers (faster)
        foreach ($this->knownPrinters as $ip => $info) {
            $printerData = $this->checkPrinter($ip);
            if ($printerData) {
                // Printer is online - merge with known information
                $printerData['info']['description'] = $info['description'] ?? '';
                $printerData['info']['purchase_url'] = $info['purchase_url'] ?? '';
                $printerData['info']['status'] = 'online';
                $discoveredPrinters[$ip] = $printerData;
            } else {
                // Printer is offline - create a basic offline entry
                $discoveredPrinters[$ip] = [
                    'info' => [
                        'ip' => $ip,
                        'last_updated' => time(),
                        'description' => $info['description'] ?? '',
                        'purchase_url' => $info['purchase_url'] ?? '',
                        'full_name' => $info['name'] ?? 'Offline Printer',
                        'serial_number' => $info['serial'] ?? 'N/A',
                        'status' => 'offline'
                    ],
                    'levels' => []
                ];
            }
        }
        
        // If subnets are provided, scan them
        if (!empty($subnets)) {
            foreach ($subnets as $subnet) {
                $ips = $this->generateIPsFromCIDR($subnet, $maxCount);
                foreach ($ips as $ip) {
                    // Skip if already checked as a known printer
                    if (isset($this->knownPrinters[$ip])) {
                        continue;
                    }
                    
                    $printerData = $this->checkPrinter($ip);
                    if ($printerData) {
                        $printerData['info']['status'] = 'online';
                        $discoveredPrinters[$ip] = $printerData;
                    }
                }
            }
        }
        
        return $discoveredPrinters;
    }
    
    /**
     * Check if an IP is a printer and get its data
     *
     * @param string $ip IP address to check
     * @return array|null Printer data or null if not a printer or offline
     */
    public function checkPrinter(string $ip): ?array {
        // Skip invalid IPs
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return null;
        }
        
        // Try a fast check first - just see if the device responds to SNMP
        $sysDescr = @snmp2_get($ip, $this->community, '.1.3.6.1.2.1.1.1.0', $this->timeout, $this->retries);
        
        if ($sysDescr === false) {
            return null; // Not an SNMP device or doesn't respond
        }
        
        // Now check if it's a printer - try to get printer-specific MIB
        $isPrinter = @snmp2_get($ip, $this->community, '.1.3.6.1.2.1.43.5.1.1.1.1', $this->timeout, $this->retries);
        
        if ($isPrinter === false) {
            return null; // Not a printer
        }
        
        // We have a printer, gather its data
        return $this->getPrinterData($ip);
    }
    
    /**
     * Get comprehensive printer data via SNMP
     *
     * @param string $ip Printer IP address
     * @return array|null Printer data
     */
    private function getPrinterData(string $ip): ?array {
        $printerData = [
            'info' => [
                'ip' => $ip,
                'last_updated' => time()
            ],
            'levels' => []
        ];
        
        try {
            // Get basic system info
            $sysData = $this->getSysInfo($ip);
            $printerData['info'] = array_merge($printerData['info'], $sysData);
            
            // Get printer name, location, serial number
            $printerData['info'] = array_merge($printerData['info'], $this->getPrinterInfo($ip));
            
            // Get supply levels
            $printerData['levels'] = $this->getSupplyLevels($ip);
            
            return $printerData;
        } catch (Throwable $e) {
            error_log("Error getting printer data for {$ip}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Get system information from SNMP
     *
     * @param string $ip Device IP
     * @return array System information
     */
    private function getSysInfo(string $ip): array {
        $sysInfo = [];
        
        // Common system OIDs
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
        
        // Detect vendor from system description
        if (isset($sysInfo['sys_description'])) {
            $sysInfo['detected_vendor'] = $this->detectVendor($sysInfo['sys_description']);
        }
        
        return $sysInfo;
    }
    
    /**
     * Get printer-specific information
     *
     * @param string $ip Printer IP
     * @return array Printer information
     */
    private function getPrinterInfo(string $ip): array {
        $info = [];
        
        // Try multiple OIDs for the same info to handle different vendor implementations
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
        
        // Try each alternative OID
        foreach ($printerOids as $key => $oids) {
            foreach ($oids as $oid) {
                $value = @snmp2_get($ip, $this->community, $oid, $this->timeout, $this->retries);
                if ($value !== false) {
                    $info[$key] = $this->sanitizeSnmpValue($value);
                    break; // Found a value, no need to try other OIDs
                }
            }
        }
        
        return $info;
    }
    
    /**
     * Get supply levels (toner, ink, etc)
     *
     * @param string $ip Printer IP
     * @return array Supply levels
     */
    private function getSupplyLevels(string $ip): array {
        $levels = [];
        
        try {
            // Try vendor-specific direct OID approach first (more reliable for some printers)
            $directLevels = $this->getDirectSupplyLevels($ip);
            if (!empty($directLevels)) {
                return $directLevels;
            }
            
            // Fallback to generic SNMP walk approach
            // Walk the supply table to get all supplies
            $supplyTable = @snmp2_real_walk($ip, $this->community, '.1.3.6.1.2.1.43.11.1.1', $this->timeout, $this->retries);
            
            if ($supplyTable === false) {
                return $levels;
            }
            
            // Collect supply information from SNMP walk
            $supplies = [];
            
            // First pass: extract basic info about each supply
            foreach ($supplyTable as $oid => $value) {
                // Extract index from OID
                if (preg_match('/\.1\.3\.6\.1\.2\.1\.43\.11\.1\.1\.6\.1\.(\d+)$/', $oid, $matches)) {
                    $index = $matches[1];
                    $supplies[$index]['description'] = $this->sanitizeSnmpValue($value);
                }
                
                // Look for max value
                if (preg_match('/\.1\.3\.6\.1\.2\.1\.43\.11\.1\.1\.8\.1\.(\d+)$/', $oid, $matches)) {
                    $index = $matches[1];
                    // Store as a string but it will be converted to float for calculations
                    $supplies[$index]['max'] = $this->sanitizeSnmpValue($value);
                }
                
                // Look for current value
                if (preg_match('/\.1\.3\.6\.1\.2\.1\.43\.11\.1\.1\.9\.1\.(\d+)$/', $oid, $matches)) {
                    $index = $matches[1];
                    // Store as a string but it will be converted to float for calculations
                    $supplies[$index]['current'] = $this->sanitizeSnmpValue($value);
                }
            }
            
            // Second pass: identify colors and format levels
            foreach ($supplies as $index => $supply) {
                if (!isset($supply['description'], $supply['max'], $supply['current'])) {
                    continue; // Skip incomplete supplies
                }
                
                // Convert to float for calculations
                $max = (float)$supply['max'];
                $current = (float)$supply['current'];
                
                // Skip if max is 0
                if ($max <= 0) {
                    continue;
                }
                
                // Calculate percentage
                $percentage = round(($current / $max) * 100, 2);
                
                // Determine color
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
     * Get supply levels using direct OID access for specific vendors
     * 
     * @param string $ip Printer IP
     * @return array Supply levels or empty array if not available
     */
    private function getDirectSupplyLevels(string $ip): array {
        $levels = [];
        
        // First, try to determine the vendor
        $vendorName = '';
        $sysDescr = @snmp2_get($ip, $this->community, '.1.3.6.1.2.1.1.1.0', $this->timeout, $this->retries);
        if ($sysDescr !== false) {
            $sysDescr = $this->sanitizeSnmpValue($sysDescr);
            $vendorName = $this->detectVendor($sysDescr);
        }
        
        // Vendor-specific OID mappings
        $oidMappings = [
            'HP' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1',
                'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.2',
                'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.3',
                'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.4',
                'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.4'
            ],
            'TOSHIBA' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.2',
                'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.3',
                'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.6',
                'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.6',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.9',
                'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.9'
            ],
            'CANON' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1',
                'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.2',
                'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.3',
                'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.4',
                'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.4'
            ],
            'EPSON' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1',
                'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.2',
                'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.3',
                'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.4',
                'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.4',
                'max_light_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.5',
                'light_cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.5',
                'max_light_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.6',
                'light_magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.6'
            ],
            'BROTHER' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1',
                'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.2',
                'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.3',
                'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.4',
                'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.4'
            ],
            'RICOH' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1',
                'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.2',
                'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.3',
                'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.4',
                'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.4'
            ],
            'LEXMARK' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1',
                'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.2',
                'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.3',
                'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.4',
                'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.4'
            ],
            'KYOCERA' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1',
                'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.2',
                'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.3',
                'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.4',
                'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.4'
            ],
            'KONICA' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1',
                'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.2',
                'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.3',
                'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.4',
                'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.4'
            ],
            'XEROX' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1',
                'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.2',
                'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.3',
                'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.4',
                'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.4'
            ],
            'SAMSUNG' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1',
                'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.2',
                'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.3',
                'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.4',
                'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.4'
            ],
            'GENERIC' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1',
                'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.2',
                'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.3',
                'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.4',
                'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.4'
            ]
        ];
        
        // If we don't have specific mappings for this vendor, use GENERIC
        if (!isset($oidMappings[$vendorName])) {
            $vendorName = 'GENERIC';
        }
        
        // Try to get direct values using OIDs
        $oids = $oidMappings[$vendorName];
        $data = [];
        
        foreach ($oids as $key => $oid) {
            $value = @snmp2_get($ip, $this->community, $oid, $this->timeout, $this->retries);
            if ($value !== false) {
                $data[$key] = (float)$this->sanitizeSnmpValue($value);
            }
        }
        
        // Process the raw data into structured levels
        $colors = ['black', 'cyan', 'magenta', 'yellow', 'light_cyan', 'light_magenta'];
        foreach ($colors as $color) {
            if (isset($data["max_{$color}"], $data[$color])) {
                $max = $data["max_{$color}"];
                $current = $data[$color];
                
                // Skip if max is 0
                if ($max <= 0) {
                    continue;
                }
                
                // Calculate percentage
                $percentage = round(($current / $max) * 100, 2);
                
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
     * Generate IPs from CIDR notation
     *
     * @param string $cidr CIDR notation (e.g. 192.168.1.0/24)
     * @param int $maxCount Maximum number of IPs to return
     * @return array List of IP addresses
     */
    private function generateIPsFromCIDR(string $cidr, int $maxCount = 100): array {
        [$net, $mask] = explode('/', $cidr);
        
        // Get network address
        $netLong = ip2long($net);
        $maskLong = ~((1 << (32 - $mask)) - 1);
        $netLong &= $maskLong;
        
        // Calculate total number of IPs
        $totalIps = 1 << (32 - $mask);
        $count = min($totalIps - 2, $maxCount); // Exclude network and broadcast addresses
        
        $ips = [];
        for ($i = 1; $i <= $count; $i++) {
            $ip = long2ip($netLong + $i);
            $ips[] = $ip;
        }
        
        return $ips;
    }
    
    /**
     * Sanitize SNMP response value
     *
     * @param string $value SNMP response
     * @return string Always returns a string, even for numeric values
     */
    private function sanitizeSnmpValue(string $value): string {
        $clean = trim(preg_replace([
            '/^STRING:\s*"/', 
            '/"$/', 
            '/^(INTEGER|Counter32|Gauge32|Timeticks):\s*/i'
        ], '', $value));
        
        // Always return a string, even for numeric values
        return (string)$clean;
    }
    
    /**
     * Detect vendor from device description
     *
     * @param string $description Device description
     * @return string Vendor name
     */
    private function detectVendor(string $description): string {
        $description = strtolower($description);
        
        $vendors = [
            'HP' => ['hp', 'hewlett-packard', 'hewlett packard', 'laserjet', 'officejet', 'deskjet'],
            'CANON' => ['canon', 'imagerunner', 'pixma'],
            'EPSON' => ['epson', 'workforce', 'stylus'],
            'BROTHER' => ['brother', 'mfc', 'dcp'],
            'LEXMARK' => ['lexmark'],
            'XEROX' => ['xerox', 'phaser', 'workcentre'],
            'KYOCERA' => ['kyocera'],
            'RICOH' => ['ricoh'],
            'KONICA' => ['konica', 'minolta', 'bizhub'],
            'SAMSUNG' => ['samsung'],
            'TOSHIBA' => ['toshiba', 'e-studio', 'estudio']
        ];
        
        foreach ($vendors as $vendor => $keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($description, $keyword) !== false) {
                    return $vendor;
                }
            }
        }
        
        return 'GENERIC';
    }
    
    /**
     * Determine color from supply description
     *
     * @param string $description Supply description
     * @return string Color name
     */
    private function determineColor(string $description): string {
        $description = strtolower($description);
        
        $colors = [
            'black' => ['black', 'noir', 'negro', 'schwarz', 'k ', ' k', 'blk'],
            'cyan' => ['cyan', 'azul', 'blau', 'c ', ' c'],
            'magenta' => ['magenta', 'rosa', 'rot', 'm ', ' m'],
            'yellow' => ['yellow', 'jaune', 'amarillo', 'gelb', 'y ', ' y'],
            'light_cyan' => ['light cyan', 'light blue', 'photo cyan', 'lc ', ' lc'],
            'light_magenta' => ['light magenta', 'light pink', 'photo magenta', 'lm ', ' lm']
        ];
        
        foreach ($colors as $color => $keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($description, $keyword) !== false) {
                    return $color;
                }
            }
        }
        
        // If it's toner but no specific color is found, assume black
        if (stripos($description, 'toner') !== false || 
            stripos($description, 'cartridge') !== false) {
            return 'black';
        }
        
	return 'other';
    }
}