<?php
declare(strict_types=1);

/**
 * PrinterDiscovery Class
 *
 * This class is responsible for talking to printers using SNMP (Simple Network Management Protocol).
 * It does NOT scan the network. It checks specific IPs that you provide.
 *
 * It can:
 * 1. Check if a printer exists at an IP (detectPrinter).
 * 2. Get live status + ink/toner levels for a known printer (getLiveStatus).
 *
 * Performance notes:
 * - All "get many OIDs" operations use one batched SNMP request (SNMP class) when
 *   available, falling back to sequential snmp2_get() calls otherwise.
 * - sysDescr is fetched once per operation and passed down (no duplicate fetches).
 */
class PrinterDiscovery {
    private string $community; // The SNMP password (usually 'public')
    private int $timeout;      // How long to wait for a response (microseconds)
    private int $retries;      // How many times to try if it fails

    /**
     * Per-printer SNMP access, set via configureSnmp():
     * version ('1'|'2c'|'3') + community, or v3 credentials.
     */
    private array $snmp = [
        'version' => '2c',
        'community' => 'public',
        'v3_level' => 'authPriv',
        'v3_user' => '',
        'v3_auth_proto' => 'SHA',
        'v3_auth_pass' => '',
        'v3_priv_proto' => 'AES',
        'v3_priv_pass' => '',
    ];

    /** @var array<string, SNMP> Reused SNMP sessions per IP (one UDP socket per printer) */
    private array $sessions = [];

    private const OID_SYS_DESCR = '.1.3.6.1.2.1.1.1.0';

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
     * Configure per-printer SNMP access (from the printer's snmp_auth JSON).
     * Accepts an already-decoded array or a JSON string.
     */
    public function configureSnmp($json): void {
        $cfg = is_string($json) ? json_decode($json, true) : $json;
        if (!is_array($cfg)) return; // keep defaults (v2c / community)
        foreach (['version', 'community', 'v3_level', 'v3_user', 'v3_auth_proto', 'v3_auth_pass', 'v3_priv_proto', 'v3_priv_pass'] as $k) {
            if (isset($cfg[$k]) && is_scalar($cfg[$k])) {
                $this->snmp[$k] = (string)$cfg[$k];
            }
        }
    }

    private function isV3(): bool {
        return $this->snmp['version'] === '3';
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
        $raw = $this->getOids($ip, [self::OID_SYS_DESCR]);
        if (!isset($raw[self::OID_SYS_DESCR])) {
            $this->closeSession($ip);
            return null;
        }
        $sysDescr = $this->sanitizeSnmpValue($raw[self::OID_SYS_DESCR]);

        // If found, get all the details.
        // sysDescr is reused - getSysInfo() must not fetch it a second time.
        $sysInfo = $this->getSysInfo($ip, $sysDescr);
        $printerInfo = $this->getPrinterInfo($ip);

        // Combine them into one array
        return array_merge($sysInfo, $printerInfo);
    }

    /**
     * Get Live Status
     *
     * Probes a printer and returns ONLY live (network) data:
     * online/offline status and current supply levels.
     *
     * Static data (name, serial, ...) always comes from the database,
     * so this result is safe to cache per-printer.
     *
     * @param string $ip The printer IP
     * @return array ['status' => 'online'|'offline', 'levels' => array]
     */
    public function getLiveStatus(string $ip): array {
        $result = ['status' => 'offline', 'levels' => []];

        try {
            // One probe to decide if the device answers at all
            $raw = $this->getOids($ip, [self::OID_SYS_DESCR]);
            if (!isset($raw[self::OID_SYS_DESCR])) {
                return $result;
            }

            $sysDescr = $this->sanitizeSnmpValue($raw[self::OID_SYS_DESCR]);
            $result['status'] = 'online';

            // Vendor detected once here and passed down:
            // getSupplyLevels() must NOT re-fetch sysDescr.
            $vendor = $this->detectVendor($sysDescr);
            $result['levels'] = $this->getSupplyLevels($ip, $vendor, $sysDescr);
        } catch (Throwable $e) {
            // Any SNMP failure means "no fresh data" - stays offline
        }

        return $result;
    }

    /**
     * Get System Info
     *
     * Gets basic device info like Name, Location, and Uptime.
     * Uses ONE batched SNMP request for all standard OIDs.
     */
    private function getSysInfo(string $ip, ?string $knownSysDescr = null): array {
        $sysInfo = [];

        // List of "OIDs" (Object Identifiers) to ask for.
        // If the caller already knows sysDescr, it is excluded from the batch.
        $sysOids = [
            'sys_name' => '.1.3.6.1.2.1.1.5.0',
            'sys_location' => '.1.3.6.1.2.1.1.6.0',
            'sys_contact' => '.1.3.6.1.2.1.1.4.0',
            'sys_uptime' => '.1.3.6.1.2.1.1.3.0'
        ];

        // Single round trip for all standard OIDs (instead of 5 sequential calls)
        $raw = $this->getOids($ip, array_values($sysOids));

        foreach ($sysOids as $key => $oid) {
            if (isset($raw[$oid])) {
                $sysInfo[$key] = $this->sanitizeSnmpValue($raw[$oid]);
            }
        }

        if ($knownSysDescr !== null) {
            $sysInfo['sys_description'] = $knownSysDescr;
        } elseif (isset($raw[self::OID_SYS_DESCR])) {
            $sysInfo['sys_description'] = $this->sanitizeSnmpValue($raw[self::OID_SYS_DESCR]);
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
     * Different brands use different OIDs, so we try a few candidates per key.
     * Candidates are resolved in "rounds": round 1 tries every key's first
     * candidate in ONE batched request, round 2 the second candidates, etc.
     * Worst case is 3 batched requests instead of 8 sequential ones.
     */
    private function getPrinterInfo(string $ip): array {
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

        $info = [];
        $maxCandidates = 0;
        foreach ($printerOids as $oids) {
            $maxCandidates = max($maxCandidates, count($oids));
        }

        // Resolve keys round by round; a key is done as soon as one OID answers
        for ($round = 0; $round < $maxCandidates; $round++) {
            $batch = [];
            foreach ($printerOids as $key => $oids) {
                if (isset($info[$key])) {
                    continue; // already resolved
                }
                if ($round < count($oids)) {
                    $batch[$key] = $oids[$round];
                }
            }
            if (empty($batch)) {
                break; // everything resolved
            }

            $raw = $this->getOids($ip, array_values($batch));
            foreach ($batch as $key => $oid) {
                if (isset($raw[$oid])) {
                    $info[$key] = $this->sanitizeSnmpValue($raw[$oid]);
                }
            }
        }

        return $info;
    }

    /**
     * Get Supply Levels
     *
     * The magic part: asks the printer how much ink/toner is left.
     *
     * @param string $ip The printer IP
     * @param string $vendor Vendor detected from sysDescr (avoids a duplicate fetch)
     * @param string $sysDescr Raw sysDescr string (for model-specific quirks)
     */
    private function getSupplyLevels(string $ip, string $vendor, string $sysDescr): array {
        $levels = [];

        try {
            // 1. Try vendor-specific shortcuts first (faster/more reliable for some)
            $directLevels = $this->getDirectSupplyLevels($ip, $vendor, $sysDescr);
            if (!empty($directLevels)) {
                return $directLevels;
            }

            // 2. If that fails, do a full "Walk" of the standard printer MIB
            // This asks for EVERYTHING in the supply table
            $supplyTable = $this->snmpWalk($ip, '.1.3.6.1.2.1.43.11.1.1');

            if ($supplyTable === false) {
                return $levels;
            }

            $supplies = [];

            // Parse the raw SNMP results.
            // Row cap: a broken/hostile device returning an endless table must
            // not balloon memory (PHP would eventually hit memory_limit and
            // kill the request) - 256 rows is far beyond any real printer.
            $parsedRows = 0;
            foreach ($supplyTable as $oid => $value) {
                if ($parsedRows > 256 * 3) {
                    break;
                }
                $parsedRows++;
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

                // Handle special SNMP return codes (same as direct path):
                // -1 = other/unknown, -2 = unknown status, -3 = supply not low.
                // These are NOT real levels - skip them instead of showing
                // a negative percentage bar.
                if ($current < 0) continue;

                $percentage = max(0.0, min(100.0, round(($current / $max) * 100, 2)));
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
     * All OIDs for the detected vendor are fetched in ONE batched request.
     *
     * @param string $ip The printer IP
     * @param string $vendor Vendor detected from sysDescr
     * @param string $sysDescr Raw sysDescr string
     */
    private function getDirectSupplyLevels(string $ip, string $vendor, string $sysDescr): array {
        $levels = [];

        // Map of OIDs for different brands
        $standardQuads = static function (int $start = 1): array {
            // Most vendors map the 4 standard supplies to table rows 1..4
            $rows = ['black' => $start, 'cyan' => $start + 1, 'magenta' => $start + 2, 'yellow' => $start + 3];
            $map = [];
            foreach ($rows as $color => $row) {
                $map["max_{$color}"] = ".1.3.6.1.2.1.43.11.1.1.8.1.{$row}";
                $map[$color] = ".1.3.6.1.2.1.43.11.1.1.9.1.{$row}";
            }
            return $map;
        };

        $oidMappings = [
            'HP' => $standardQuads(),
            'CANON' => $standardQuads(),
            'BROTHER' => $standardQuads(), // laser printers; inkjets handled separately below
            'RICOH' => $standardQuads(),
            'LEXMARK' => $standardQuads(),
            'KYOCERA' => $standardQuads(),
            'KONICA' => $standardQuads(),
            'XEROX' => $standardQuads(),
            'SAMSUNG' => $standardQuads(),
            'GENERIC' => $standardQuads(),
            'TOSHIBA' => [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.2', 'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.2',
                'max_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.3', 'cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.3',
                'max_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.6', 'magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.6',
                'max_yellow' => '.1.3.6.1.2.1.43.11.1.1.8.1.9', 'yellow' => '.1.3.6.1.2.1.43.11.1.1.9.1.9'
            ],
            'EPSON' => array_merge($standardQuads(), [
                'max_light_cyan' => '.1.3.6.1.2.1.43.11.1.1.8.1.5', 'light_cyan' => '.1.3.6.1.2.1.43.11.1.1.9.1.5',
                'max_light_magenta' => '.1.3.6.1.2.1.43.11.1.1.8.1.6', 'light_magenta' => '.1.3.6.1.2.1.43.11.1.1.9.1.6'
            ])
        ];

        // Use Generic if unknown
        $oids = $oidMappings[$vendor] ?? $oidMappings['GENERIC'];

        // Special case for TOSHIBA e-STUDIO409S (Monochrome)
        // The generic Toshiba map includes color OIDs which map to Imaging Units on this model.
        if ($vendor === 'TOSHIBA' && stripos($sysDescr, 'e-STUDIO409S') !== false) {
             $oids = [
                'max_black' => '.1.3.6.1.2.1.43.11.1.1.8.1.2', 'black' => '.1.3.6.1.2.1.43.11.1.1.9.1.2'
            ];
        }

        // Special case for Brother inkjet printers (MFC-J series, DCP-J series, etc.)
        // These use a proprietary OID that returns hex-encoded ink levels
        if ($vendor === 'BROTHER' && preg_match('/MFC-J|DCP-J|MFC-INK|DCP-INK/i', $sysDescr)) {
            $brotherLevels = $this->getBrotherInkjetLevels($ip);
            if (!empty($brotherLevels)) {
                return $brotherLevels;
            }
        }

        // ONE batched request for every OID in the map
        // (instead of 8-10 sequential snmp2_get calls)
        $raw = $this->getOids($ip, array_values($oids));

        $data = [];
        foreach ($oids as $key => $oid) {
            if (isset($raw[$oid])) {
                $data[$key] = (float)$this->sanitizeSnmpValue($raw[$oid]);
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
                // -1 = Other/unknown (skip)
                // 0 or less = Empty or not genuine cartridge
                if ($current < 0) {
                    // Printer doesn't report exact levels for this cartridge - skip it
                    continue;
                }

                if ($max <= 0) continue;

                $percentage = max(0.0, min(100.0, round(($current / $max) * 100, 2)));

                // Check for 0% (empty or not genuine)
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
        $clean = trim(preg_replace([
            '/^STRING:\s*"/',
            '/"$/',
            '/^(INTEGER|Counter32|Gauge32|Timeticks|Hex-STRING|Opaque|IpAddress):\s*/i'
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
            'TOSHIBA' => ['toshiba']
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
     *
     * Careful: single-letter codes (K/C/M/Y) are matched with STRICT word
     * boundaries and checked LAST. Otherwise the "k" in "Ink" would match
     * black and every "Cyan Ink Cartridge" would be classified as black.
     */
    private function determineColor(string $description): string {
        $d = strtolower($description);

        // Full color words first (a few common languages)
        if (preg_match('/cyan|cian/', $d)) return 'cyan';
        if (preg_match('/magenta/', $d)) return 'magenta';
        if (preg_match('/yellow|jaune|amarillo|amarelo|gelb/', $d)) return 'yellow';
        if (preg_match('/black|noir|negro|schwarz|preto/', $d)) return 'black';

        // Single-letter codes with real word boundaries:
        // \bk\b does NOT match the k in "Ink" (no boundary between n and k)
        if (preg_match('/\bc\b/', $d)) return 'cyan';
        if (preg_match('/\bm\b/', $d)) return 'magenta';
        if (preg_match('/\by\b/', $d)) return 'yellow';
        if (preg_match('/\bk\b/', $d)) return 'black';

        // Fallback: if it says "toner" but no color, assume black
        if (strpos($d, 'toner') !== false) return 'black';

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
        // Format: The hex data contains blocks where:
        // Byte 1: Color identifier (0x81=Black, 0x82=Cyan, 0x83=Magenta, 0x84=Yellow)
        // A later byte in the block: Percentage value

        // Convert response to clean hex
        $cleanValue = $this->sanitizeSnmpValue($response);

        // Remove "Hex-STRING: " prefix if present
        $cleanValue = preg_replace('/^Hex-STRING:\s*/i', '', $cleanValue);

        // Keep hex digits only, then split into BYTES (aligned scanning).
        // The old code used strpos() on the hex string, which could match at an
        // odd nibble offset and read garbage.
        $hexOnly = strtoupper(preg_replace('/[^0-9A-Fa-f]/', '', $cleanValue) ?? '');
        if (strlen($hexOnly) % 2 !== 0) {
            $hexOnly = substr($hexOnly, 0, -1); // keep byte alignment
        }
        if ($hexOnly === '') {
            return $levels;
        }
        $bytes = str_split($hexOnly, 2);
        $byteCount = count($bytes);

        $colorMap = [
            '81' => 'black',
            '82' => 'cyan',
            '83' => 'magenta',
            '84' => 'yellow'
        ];

        foreach ($colorMap as $marker => $color) {
            $found = false;
            for ($i = 0; $i + 1 < $byteCount && !$found; $i++) {
                if ($bytes[$i] !== $marker) continue;

                // Percentage candidate: try a few offsets after the marker byte
                foreach ([$i + 5, $i + 4, $i + 6] as $offset) {
                    if ($offset >= $byteCount) continue;
                    $percentage = hexdec($bytes[$offset]);

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
                        $found = true;
                        break;
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

            // One batched request for all four OIDs
            $raw = $this->getOids($ip, array_values($inkOids));
            foreach ($inkOids as $color => $oid) {
                if (isset($raw[$oid])) {
                    $percentage = (int)$this->sanitizeSnmpValue($raw[$oid]);
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

    // ==================== SNMP TRANSPORT ====================

    /**
     * Get (reused) SNMP session for an IP, if the SNMP class is available.
     */
    private function session(string $ip) {
        if (!class_exists('SNMP')) {
            return null;
        }
        if (!isset($this->sessions[$ip])) {
            try {
                $versionConst = $this->isV3() ? SNMP::VERSION_3
                    : ($this->snmp['version'] === '1' ? SNMP::VERSION_1 : SNMP::VERSION_2C);
                $s = new SNMP($versionConst, $ip, $this->community, $this->timeout, $this->retries);
                // Same text format as snmp2_get() so sanitizeSnmpValue() works
                if (defined('SNMP::VALUE_LIBRARY') && property_exists($s, 'valueretrieval')) {
                    $s->valueretrieval = SNMP_VALUE_LIBRARY;
                }
                if ($this->isV3()) {
                    $s->setSecurity(
                        $this->snmp['v3_level'],
                        $this->snmp['v3_auth_proto'],
                        $this->snmp['v3_auth_pass'],
                        $this->snmp['v3_priv_proto'],
                        $this->snmp['v3_priv_pass']
                    );
                }
                $this->sessions[$ip] = $s;
            } catch (Throwable $e) {
                return null;
            }
        }
        return $this->sessions[$ip];
    }

    /**
     * Close and forget a session (after a fatal transport error).
     */
    private function closeSession(string $ip): void {
        if (isset($this->sessions[$ip])) {
            @$this->sessions[$ip]->close();
            unset($this->sessions[$ip]);
        }
    }

    /**
     * Get many OIDs with as few SNMP round trips as possible.
     *
     * Tries one batched SNMP::get() first. If that fails or the SNMP class
     * is unavailable, falls back to sequential snmp2_get() per OID.
     *
     * @param string $ip Printer IP
     * @param array $oids List of OIDs
     * @return array Map of requested OID => raw value (only successful ones)
     */
    private function getOids(string $ip, array $oids): array {
        $oids = array_values(array_unique($oids));
        $results = [];

        // Batched path: 1 round trip for all OIDs.
        // Attempt 2 disables exceptions, because a single missing OID makes
        // the whole batch throw - missing OIDs are then filtered per value.
        $s = $this->session($ip);
        if ($s !== null) {
            for ($attempt = 0; $attempt < 2; $attempt++) {
                try {
                    if ($attempt === 1 && !$this->disableSnmpExceptions($s)) {
                        break;
                    }
                    $this->collectSnmpResults(@$s->get($oids), $results);
                    break;
                } catch (Throwable $e) {
                    if ($attempt === 1) {
                        $this->closeSession($ip);
                    }
                }
            }
        }

        // Sequential fallback for whatever the batched call did not resolve
        foreach ($oids as $oid) {
            if (isset($results[$oid])) {
                continue;
            }
            $v = $this->snmpSingleGet($ip, $oid);
            if ($v !== null) {
                $this->collectSnmpValue($oid, $v, $results);
            }
        }

        return $results;
    }

    /**
     * Version-aware single-OID get (v1 / v2c / v3).
     */
    private function snmpSingleGet(string $ip, string $oid): ?string {
        if ($this->isV3()) {
            $v = @snmp3_get(
                $ip, $this->snmp['v3_user'], $this->snmp['v3_level'],
                $this->snmp['v3_auth_proto'], $this->snmp['v3_auth_pass'],
                $this->snmp['v3_priv_proto'], $this->snmp['v3_priv_pass'],
                $oid, $this->timeout, $this->retries
            );
        } elseif ($this->snmp['version'] === '1') {
            $v = @snmpget($ip, $this->community, $oid, $this->timeout, $this->retries);
        } else {
            $v = @snmp2_get($ip, $this->community, $oid, $this->timeout, $this->retries);
        }
        return $v === false ? null : (string)$v;
    }

    /**
     * Version-aware real walk (supply table fallback).
     */
    private function snmpWalk(string $ip, string $oid): array|false {
        if ($this->isV3()) {
            return @snmp3_real_walk(
                $ip, $this->snmp['v3_user'], $this->snmp['v3_level'],
                $this->snmp['v3_auth_proto'], $this->snmp['v3_auth_pass'],
                $this->snmp['v3_priv_proto'], $this->snmp['v3_priv_pass'],
                $oid, $this->timeout, $this->retries
            );
        }
        if ($this->snmp['version'] === '1') {
            return @snmp_real_walk($ip, $this->community, $oid, $this->timeout, $this->retries);
        }
        return @snmp2_real_walk($ip, $this->community, $oid, $this->timeout, $this->retries);
    }

    /**
     * Turn exception-throwing SNMP errors into per-OID "No Such ..." values.
     */
    private function disableSnmpExceptions(SNMP $s): bool {
        if (!property_exists($s, 'exceptions_enabled') || !defined('SNMP::ERRNO_NONE')) {
            return false;
        }
        $s->exceptions_enabled = SNMP::ERRNO_NONE;
        return true;
    }

    /**
     * Merge a batched SNMP::get() response into the results map.
     */
    private function collectSnmpResults($res, array &$results): void {
        if (!is_array($res)) {
            return;
        }
        foreach ($res as $oid => $val) {
            $this->collectSnmpValue((string)$oid, $val, $results);
        }
    }

    /**
     * Store one raw SNMP value if it is a real answer (not an error placeholder).
     */
    private function collectSnmpValue(string $oid, $val, array &$results): void {
        if (!is_string($val) || $val === '') {
            return;
        }
        // Skip per-OID error placeholders
        if (stripos($val, 'No Such') !== false || stripos($val, 'End of MIB') !== false) {
            return;
        }
        $results[strtolower($oid)] = $val;
    }

    public function __destruct() {
        foreach (array_keys($this->sessions) as $ip) {
            $this->closeSession($ip);
        }
    }
}
