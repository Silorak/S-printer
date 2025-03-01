<?php
declare(strict_types=1);

/**
 * Class to handle printer data retrieval and processing
 */
class PrinterService {
    private string $cacheDir;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->cacheDir = __DIR__ . '/cache';
        
        // Create cache directory if it doesn't exist
        if (!file_exists($this->cacheDir) && Config::$enableCache) {
            mkdir($this->cacheDir, 0755, true);
        }
    }
    
    /**
     * Get data for all printers
     * 
     * @return array The printer data
     */
    public function getAllPrinters(): array {
        $results = [];
        
        foreach (Config::$printers as $ip) {
            $printerData = $this->getPrinterData($ip);
            if (!empty($printerData)) {
                $results[$ip] = $printerData;
            }
        }
        
        return $results;
    }
    
    /**
     * Get data for a specific printer
     * 
     * @param string $ip The printer IP address
     * @return array|null The printer data
     */
    public function getPrinterData(string $ip): ?array {
        // Check cache first if enabled
        if (Config::$enableCache) {
            $cachedData = $this->getCachedData($ip);
            if ($cachedData !== null) {
                return $cachedData;
            }
        }
        
        // Get fresh data if not in cache
        $printerData = $this->fetchPrinterData($ip);
        
        // Cache the data if enabled and data is available
        if (Config::$enableCache && $printerData !== null) {
            $this->cacheData($ip, $printerData);
        }
        
        return $printerData;
    }
    
    /**
     * Fetch printer data via SNMP
     * 
     * @param string $ip The printer IP address
     * @return array|null The printer data
     */
    private function fetchPrinterData(string $ip): ?array {
        $printerData = [];
        
        try {
            // Try to get the printer name first to detect vendor
            $nameOid = '.1.3.6.1.2.1.43.8.2.1.14.1.1';
            $nameResponse = @snmp2_get($ip, Config::$community, $nameOid, Config::$timeout, Config::$retries);
            
            if ($nameResponse === false) {
                // Try alternative name OID
                $nameOid = '.1.3.6.1.2.1.25.3.2.1.3.1';
                $nameResponse = @snmp2_get($ip, Config::$community, $nameOid, Config::$timeout, Config::$retries);
            }
            
            $vendorName = $nameResponse !== false 
                ? $this->sanitizeSnmpValue($nameResponse)
                : 'Unknown';
            
            $vendor = PrinterOIDs::detectPrinterVendor($vendorName);
            $oidMap = PrinterOIDs::getVendorOids($vendor);
            
            // Query all OIDs for the detected vendor
            foreach ($oidMap as $key => $oid) {
                if (!empty($oid)) {
                    $value = @snmp2_get($ip, Config::$community, $oid, Config::$timeout, Config::$retries);
                    if ($value !== false) {
                        $printerData[$key] = $this->sanitizeSnmpValue($value);
                    }
                }
            }
            
            // Add metadata
            $printerData += [
                'vendor' => $vendor,
                'detected_name' => $vendorName,
                'description' => Config::$descriptions[$ip] ?? '',
                'purchase_url' => Config::$purchaseUrls[$ip] ?? '',
                'last_updated' => time()
            ];
            
            // Process the data into a structured format
            return $this->processPrinterData($printerData);
        } catch (Throwable $e) {
            // Log the error
            error_log("Error fetching printer data for {$ip}: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Sanitize SNMP response value
     * 
     * @param string $value The SNMP response
     * @return string|float The sanitized value
     */
    private function sanitizeSnmpValue(string $value): string|float {
        $clean = trim(preg_replace([
            '/^STRING:\s*"/', 
            '/"$/', 
            '/^(INTEGER|Counter32|Gauge32|Timeticks):\s*/i'
        ], '', $value));
        
        // Handle potentially unsafe values
        return is_numeric($clean) ? (float)$clean : htmlspecialchars($clean, ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Process printer data into a structured format
     * 
     * @param array $data The raw printer data
     * @return array The processed data
     */
    private function processPrinterData(array $data): array {
        $levels = [];
        
        // Process color levels
        foreach (['black', 'cyan', 'magenta', 'yellow'] as $color) {
            if (isset($data["max_$color"], $data[$color])) {
                $max = (float)$data["max_$color"];
                $current = (float)$data[$color];
                
                if ($max > 0) {
                    $levels[$color] = [
                        'percentage' => round(($current / $max) * 100, 2),
                        'current' => $current,
                        'max' => $max,
                        'color' => $color
                    ];
                }
            }
        }
        
        return [
            'info' => $data,
            'levels' => array_filter($levels)
        ];
    }
    
    /**
     * Get cached printer data
     * 
     * @param string $ip The printer IP address
     * @return array|null The cached data or null if not found/expired
     */
    private function getCachedData(string $ip): ?array {
        $cacheFile = $this->getCacheFilePath($ip);
        
        if (file_exists($cacheFile)) {
            $fileTime = filemtime($cacheFile);
            
            // Check if cache is still valid
            if (time() - $fileTime < Config::$cacheTTL) {
                $data = file_get_contents($cacheFile);
                if ($data !== false) {
                    $decoded = json_decode($data, true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        return $decoded;
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Cache printer data
     * 
     * @param string $ip The printer IP address
     * @param array $data The data to cache
     * @return bool Success or failure
     */
    private function cacheData(string $ip, array $data): bool {
        $cacheFile = $this->getCacheFilePath($ip);
        $jsonData = json_encode($data);
        
        if ($jsonData === false) {
            return false;
        }
        
        return file_put_contents($cacheFile, $jsonData) !== false;
    }
    
    /**
     * Get cache file path for a printer
     * 
     * @param string $ip The printer IP address
     * @return string The cache file path
     */
    private function getCacheFilePath(string $ip): string {
        return $this->cacheDir . '/' . md5($ip) . '.json';
    }
}