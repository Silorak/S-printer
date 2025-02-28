<?php
declare(strict_types=1);

/**
 * Class to handle vendor-specific OID mappings and detection
 */
class PrinterOIDs {
    // Common OIDs across all vendors
    private const COMMON_OIDS = [
        'name' => '.1.3.6.1.2.1.43.8.2.1.14.1.1',
    ];
    
    // Vendor-specific OID mappings
    private const VENDOR_OIDS = [
        'HP' => [
            'full_name' => '.1.3.6.1.2.1.25.3.2.1.3.1',
            'serial_number' => '.1.3.6.1.2.1.43.5.1.1.17.1',
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
            'full_name' => '.1.3.6.1.2.1.25.3.2.1.3.1',
            'serial_number' => '.1.3.6.1.2.1.43.5.1.1.17.1',
            'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.2',
            'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
            'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.3',
            'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
            'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.6',
            'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.6',
            'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.9',
            'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.9'
        ],
        'GENERIC' => [
            'full_name' => '.1.3.6.1.2.1.25.3.2.1.3.1',
            'serial_number' => '.1.3.6.1.2.1.43.5.1.1.17.1',
            'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.1',
            'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.1',
        ]
    ];
    
    // Mapping of vendor identification keywords
    private const VENDOR_KEYWORDS = [
        'HP' => ['hp', 'hewlett', 'laserjet', 'officejet', 'deskjet'],
        'TOSHIBA' => ['toshiba', 'e-studio'],
        'CANON' => ['canon', 'imagerunner', 'pixma'],
        'EPSON' => ['epson', 'workforce', 'stylus'],
        'BROTHER' => ['brother', 'mfc', 'dcp'],
        'LEXMARK' => ['lexmark']
    ];
    
    /**
     * Get OID mappings for a specific vendor
     * 
     * @param string $vendor The vendor name
     * @return array The OID mappings
     */
    public static function getVendorOids(string $vendor): array {
        $vendor = strtoupper($vendor);
        $vendorOids = self::VENDOR_OIDS[$vendor] ?? self::VENDOR_OIDS['GENERIC'];
        
        // Merge with common OIDs
        return array_merge(self::COMMON_OIDS, $vendorOids);
    }
    
    /**
     * Detect printer vendor from name
     * 
     * @param string $name The printer name
     * @return string The detected vendor
     */
    public static function detectPrinterVendor(string $name): string {
        $name = strtolower($name);
        
        foreach (self::VENDOR_KEYWORDS as $vendor => $keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($name, $keyword) !== false) {
                    return $vendor;
                }
            }
        }
        
        return 'GENERIC';
    }
}
