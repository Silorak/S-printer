<?php
declare(strict_types=1);

/**
 * Database Class
 * 
 * This class handles all interactions with the MariaDB database.
 * It opens the connection, creates the table if it doesn't exist,
 * and provides methods to Add, Get, Update, and Delete printers.
 */
class Database {
    private ?PDO $pdo = null; // The connection object
    private ?string $lastError = null; // Stores the last error message

    /**
     * Constructor
     * 
     * Automatically called when you do `new Database()`.
     * It connects to the database using settings from environment variables.
     */
    public function __construct() {
        // Get settings from Docker environment (or use defaults)
        $host = getenv('DB_HOST') ?: 'db';
        $db   = getenv('DB_NAME') ?: 'sprinter';
        $user = getenv('DB_USER') ?: 'sprinter';
        $pass = getenv('DB_PASS') ?: 'sprinter_password';
        $charset = 'utf8mb4'; // Support all characters (emojis, etc.)

        // Connection string (DSN)
        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw errors if something goes wrong
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,     // Return data as arrays
            PDO::ATTR_EMULATE_PREPARES   => false,                // Use real prepared statements (Security)
        ];

        try {
            // Try to connect
            $this->pdo = new PDO($dsn, $user, $pass, $options);
            // Make sure the schema exists - but only actually run the
            // (idempotent) CREATE statements when the printers table is
            // missing. Probing is 1 tiny query; initSchema() is ~7 queries
            // and used to run on EVERY request.
            if (!$this->tablesReady()) {
                $this->initSchema();
            }
        } catch (\PDOException $e) {
            // If connection fails, save the error
            $this->lastError = "Database connection failed: " . $e->getMessage();
            error_log($this->lastError);
        }
    }

    // Get the last error message (FULL detail - for server logs only)
    public function getLastError(): ?string {
        return $this->lastError;
    }

    /**
     * Client-safe error: maps known SQL problems to friendly text and never
     * exposes SQLSTATE/vendor messages to the browser (schema disclosure).
     * The full detail stays in the server error log.
     */
    public function getClientError(string $fallback = 'Database error - check server logs'): string {
        $err = $this->lastError ?? '';
        if ($err !== '') error_log('DB error: ' . $err);
        if (strpos($err, '1062') !== false) return 'An entry with this value already exists';
        if (strpos($err, '22001') !== false || strpos($err, '1406') !== false) return 'A value is too long';
        if (strpos($err, '1452') !== false) return 'Referenced entry does not exist';
        return $fallback;
    }

    /**
     * Tables Ready Check
     *
     * One cheap query to see if the printers table exists.
     * @return bool True when the schema looks present
     */
    private function tablesReady(): bool {
        if (!$this->pdo) return false;
        try {
            // Probe every table the schema defines (one tiny query each).
            // Tables/columns added later (alert_state, snmp_auth) must still
            // trigger initSchema on existing installs.
            foreach (['printers', 'alert_state'] as $table) {
                $this->pdo->query("SELECT 1 FROM {$table} LIMIT 1");
            }
            $col = $this->pdo->query(
                "SHOW COLUMNS FROM printers LIKE 'snmp_auth'"
            );
            if ($col->rowCount() === 0) return false;
            return true;
        } catch (\PDOException $e) {
            if ($e->getCode() === '42S02') {
                return false; // a table does not exist yet -> run schema init
            }
            // Any other error (permissions, ...) won't be fixed by DDL.
            // Log it once and treat as ready to keep requests cheap.
            error_log("Schema probe failed: " . $e->getMessage());
            return true;
        }
    }

    /**
     * Validate + normalize the per-printer SNMP access JSON.
     *
     * Shape: {"version":"1|2c|3","community":"..","v3_level":"authPriv|authNoPriv|noAuthNoPriv",
     *         "v3_user":"..","v3_auth_proto":"SHA|MD5","v3_auth_pass":"..",
     *         "v3_priv_proto":"AES|DES","v3_priv_pass":".."}
     *
     * Control chars are stripped everywhere (command/header injection),
     * lengths capped, enums enforced. v3 rules: username required; auth
     * passphrase required unless level is noAuthNoPriv; privacy passphrase
     * required for authPriv.
     *
     * @return string|null Normalized JSON, or NULL = invalid/rejected.
     */
    public static function sanitizeSnmpAuth($json): ?string {
        if (!is_string($json) || trim($json) === '') return null;
        $c = json_decode($json, true);
        if (!is_array($c)) return null;

        $get = static function (array $a, string $k, string $def = ''): string {
            $v = $a[$k] ?? $def;
            if (!is_scalar($v)) return $def;
            $v = trim((string)$v);
            return preg_replace('/[[:cntrl:]]/', '', $v) ?? '';
        };

        $version = SnmpVersion::tryFrom($get($c, 'version', '2c')) ?? SnmpVersion::V2C;
        $level   = SnmpSecLevel::tryFrom($get($c, 'v3_level', 'authPriv')) ?? SnmpSecLevel::AUTH_PRIV;
        $authP   = SnmpAuthProto::tryFrom($get($c, 'v3_auth_proto', 'SHA')) ?? SnmpAuthProto::SHA;
        $privP   = SnmpPrivProto::tryFrom($get($c, 'v3_priv_proto', 'AES')) ?? SnmpPrivProto::AES;

        $out = [
            'version' => $version->value,
            'community' => $get($c, 'community', 'public'),
            'v3_level' => $level->value,
            'v3_user' => mb_substr($get($c, 'v3_user'), 0, 64),
            'v3_auth_proto' => $authP->value,
            'v3_auth_pass' => mb_substr($get($c, 'v3_auth_pass'), 0, 128),
            'v3_priv_proto' => $privP->value,
            'v3_priv_pass' => mb_substr($get($c, 'v3_priv_pass'), 0, 128),
        ];

        if ($out['community'] === '') $out['community'] = 'public';
        if (mb_strlen($out['community']) > 191) return null;

        if ($out['version'] === SnmpVersion::V3->value) {
            if ($out['v3_user'] === '') return null;
            if ($out['v3_level'] !== SnmpSecLevel::NO_AUTH_NO_PRIV->value && $out['v3_auth_pass'] === '') return null;
            if ($out['v3_level'] === SnmpSecLevel::AUTH_PRIV->value && $out['v3_priv_pass'] === '') return null;
        }

        $json = json_encode($out);
        return $json === false ? null : $json;
    }

    /**
     * Sanitized copy for edit forms: no passphrases, but flags saying
     * whether they are stored (so the UI can show "(saved)").
     */
    public static function snmpAuthView(?string $json): ?array {
        if (!is_string($json) || $json === '') return null;
        $c = json_decode($json, true);
        if (!is_array($c)) return null;
        return [
            'version' => (string)($c['version'] ?? '2c'),
            'community' => (string)($c['community'] ?? 'public'),
            'v3_level' => (string)($c['v3_level'] ?? 'authPriv'),
            'v3_user' => (string)($c['v3_user'] ?? ''),
            'v3_auth_proto' => (string)($c['v3_auth_proto'] ?? 'SHA'),
            'v3_priv_proto' => (string)($c['v3_priv_proto'] ?? 'AES'),
            'has_auth_pass' => (bool)($c['v3_auth_pass'] ?? false),
            'has_priv_pass' => (bool)($c['v3_priv_pass'] ?? false),
        ];
    }

    // ==================== INPUT VALIDATION ====================

    /**
     * Settings keys that hold credentials/tokens. They are stored normally
     * but NEVER exported or returned to the browser.
     */
    public const SECRET_SETTINGS = [
        'smtp_pass', 'oauth_refresh_token', 'oauth_access_token',
        'oauth_token_expires', 'oauth_device_code'
    ];

    /**
     * Printer row keys that hold SNMP credentials. Stripped from exports and
     * API views; a sanitized copy (no passphrases) is served for edit forms.
     */
    public const SECRET_PRINTER_KEYS = ['snmp_auth'];

    /**
     * Sanitize an incoming setting value.
     *
     * Security properties:
     * - strips ALL control chars (CR/LF) from every value -> kills SMTP
     *   command injection ("MAIL FROM:<..\r\nBcc:..>") and email header
     *   injection from user-controlled settings;
     * - restricts values that end up in URLs or network calls
     *   (smtp_host, oauth tenant/client_id) to safe charsets;
     * - validates numeric fields;
     * - non-scalars (arrays/objects from crafted JSON) are dropped.
     *
     * @return string|null The sanitized value, or NULL = reject/drop.
     */
    public static function sanitizeSetting(string $key, $value): ?string {
        if (!is_scalar($value)) return null;
        $v = trim((string)$value);

        switch ($key) {
            case 'smtp_port':
                return preg_match('/^\d{1,5}$/', $v) && (int)$v >= 1 && (int)$v <= 65535
                    ? (string)(int)$v : null;
            case 'alert_threshold':
                return preg_match('/^\d{1,3}$/', $v) ? (string)min(100, max(1, (int)$v)) : null;
            case 'smtp_host':
                return preg_match('/^[A-Za-z0-9.\-]{1,253}$/', $v) ? $v : null;
            case 'oauth_tenant':
            case 'oauth_client_id':
                // these are interpolated into token URLs - strict charset
                return preg_match('/^[A-Za-z0-9._\-]{1,128}$/', $v) ? $v : null;
            case 'smtp_user':
                $v = preg_replace('/[[:cntrl:]]/', '', $v);
                return ($v !== null && $v !== '' && strlen($v) <= 191) ? $v : null;
            case 'smtp_from_name':
                $v = preg_replace('/[[:cntrl:]]/', '', $v);
                return ($v !== null && $v !== '' && strlen($v) <= 100) ? $v : null;
            case 'alert_recipients':
                $v = preg_replace('/[[:cntrl:]]/', '', $v);
                return ($v !== null && strlen($v) <= 1000) ? $v : null;
            default:
                $v = preg_replace('/[[:cntrl:]]/', '', $v);
                return ($v !== null && strlen($v) <= 1000) ? $v : null;
        }
    }

    // ==================== INPUT VALIDATION ====================

    /**
     * Validate an IP address (IPv4 or IPv6).
     * Everything we store as "ip" passes through here, so a value like
     * '"><script>...' can never enter the database.
     */
    public static function isValidIp($ip): bool {
        return is_string($ip) && $ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * Accept only http(s) URLs. Prevents javascript:/data: URLs from being
     * stored in purchase_url and later rendered as link targets.
     */
    public static function sanitizeUrl($url): ?string {
        if (!is_string($url)) return null;
        $url = trim($url);
        if ($url === '') return null;
        if (!preg_match('#^https?://#i', $url)) return null;
        if (filter_var($url, FILTER_VALIDATE_URL) === false) return null;
        return $url;
    }

    /**
     * Normalize a levels array for storage/comparison.
     */
    public static function normalizeLevelsJson($levels): ?string {
        if (!is_array($levels)) return null;
        $json = json_encode($levels);
        return $json === false ? null : $json;
    }

    // ==================== TRANSACTION SUPPORT ====================

    public function beginTransaction(): bool {
        if (!$this->pdo) return false;
        return $this->pdo->beginTransaction();
    }

    public function commit(): bool {
        if (!$this->pdo) return false;
        return $this->pdo->commit();
    }

    public function rollBack(): bool {
        if (!$this->pdo) return false;
        if (!$this->pdo->inTransaction()) return true;
        return $this->pdo->rollBack();
    }

    /**
     * Initialize Schema
     * 
     * Creates all required tables if they don't exist yet.
     * This runs every time the app starts to ensure the DB is ready.
     */
    private function initSchema(): void {
        if (!$this->pdo) return;

        try {
            // Create floors table
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS floors (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");

            // Create settings table
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )");

            // Create printers table
            $sql = "CREATE TABLE IF NOT EXISTS printers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip VARCHAR(45) NOT NULL UNIQUE,
                name VARCHAR(255),
                description TEXT,
                purchase_url TEXT,
                serial VARCHAR(255),
                model VARCHAR(255),
                vendor VARCHAR(255),
                floor_id INT NULL,
                custom_oids JSON,
                last_levels JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (floor_id) REFERENCES floors(id) ON DELETE SET NULL
            )";

            $this->pdo->exec($sql);

            // Alert state: one row per printer per alert kind. Holds the last
            // observed state so alerts fire on TRANSITIONS only (no spam),
            // with a silent baseline on the first run.
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS alert_state (
                printer_id INT NOT NULL,
                kind VARCHAR(20) NOT NULL,
                state VARCHAR(255) NOT NULL,
                notified_at TIMESTAMP NULL DEFAULT NULL,
                PRIMARY KEY (printer_id, kind),
                FOREIGN KEY (printer_id) REFERENCES printers(id) ON DELETE CASCADE
            )");
            
            // SNMP access config per printer (JSON: version, community or v3
            // credentials). Secrets live here - masked in API views, stripped
            // from exports.
            $check = $this->pdo->query("SHOW COLUMNS FROM printers LIKE 'snmp_auth'");
            if ($check->rowCount() === 0) {
                $this->pdo->exec("ALTER TABLE printers ADD COLUMN snmp_auth TEXT NULL AFTER custom_oids");
            }

            // Migration: Check if last_levels exists, if not add it (for existing installs)
            $check = $this->pdo->query("SHOW COLUMNS FROM printers LIKE 'last_levels'");
            if ($check->rowCount() === 0) {
                $this->pdo->exec("ALTER TABLE printers ADD COLUMN last_levels JSON AFTER custom_oids");
            }

            // Migration: Check if floor_id exists, if not add it
            $check = $this->pdo->query("SHOW COLUMNS FROM printers LIKE 'floor_id'");
            if ($check->rowCount() === 0) {
                $this->pdo->exec("ALTER TABLE printers ADD COLUMN floor_id INT NULL AFTER vendor");
            }

            // Insert default settings if not exist
            $this->pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES 
                ('hide_ip', '0'),
                ('show_serial', '1')
            ");

        } catch (\PDOException $e) {
            $this->lastError = "Schema initialization failed: " . $e->getMessage();
            error_log($this->lastError);
        }
    }

    /**
     * Get All Printers
     * 
     * Fetches a list of all printers saved in the database.
     * @return array List of printers
     */
    public function getPrinters(): array {
        if (!$this->pdo) return [];
        try {
            // SELECT * means "get everything"
            $stmt = $this->pdo->query("SELECT * FROM printers ORDER BY name ASC, ip ASC");
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            $this->lastError = "Fetch failed: " . $e->getMessage();
            return [];
        }
    }

    /**
     * Add a New Printer
     * 
     * Saves a new printer to the database.
     * Uses "Prepared Statements" (:ip, :name) to prevent SQL Injection attacks.
     * 
     * @param array $data The printer data (ip, name, etc.)
     * @return bool True if successful, False if failed
     */
    public function addPrinter(array $data): bool {
        if (!$this->pdo) {
            if (!$this->lastError) $this->lastError = "Database not connected";
            return false;
        }
        
        // Reject non-IP values early (stored XSS / data hygiene)
        if (!self::isValidIp($data['ip'] ?? null)) {
            $this->lastError = "Invalid IP address";
            return false;
        }
        // Per-printer SNMP access config (v1/v2c community or v3 credentials)
        $snmpAuth = self::sanitizeSnmpAuth($data['snmp_auth'] ?? null);

        try {
            $sql = "INSERT INTO printers (ip, name, description, purchase_url, serial, model, vendor, floor_id, custom_oids, snmp_auth) 
                    VALUES (:ip, :name, :description, :purchase_url, :serial, :model, :vendor, :floor_id, :custom_oids, :snmp_auth)";
            
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':ip' => $data['ip'],
                ':name' => isset($data['name']) ? (string)$data['name'] : null,
                ':description' => isset($data['description']) ? (string)$data['description'] : null,
                ':purchase_url' => self::sanitizeUrl($data['purchase_url'] ?? null),
                ':serial' => isset($data['serial']) ? (string)$data['serial'] : null,
                ':model' => isset($data['model']) ? (string)$data['model'] : null,
                ':vendor' => isset($data['vendor']) ? (string)$data['vendor'] : null,
                ':floor_id' => !empty($data['floor_id']) ? (int)$data['floor_id'] : null,
                ':custom_oids' => isset($data['custom_oids']) && is_array($data['custom_oids']) ? json_encode($data['custom_oids']) : null,
                ':snmp_auth' => $snmpAuth
            ]);
        } catch (\PDOException $e) {
            $this->lastError = "Insert failed: " . $e->getMessage();
            return false;
        }
    }

    /**
     * Update a Printer
     * 
     * Updates details of an existing printer.
     * 
     * @param int $id The ID of the printer to update
     * @param array $data The new data
     * @return bool True if successful
     */
    public function updatePrinter(int $id, array $data): bool {
        if (!$this->pdo) {
            if (!$this->lastError) $this->lastError = "Database not connected";
            return false;
        }

        // Whitelist + dynamic SET clause: only fields present in $data are
        // updated, so a partial payload can no longer wipe other fields to NULL.
        $editable = [
            'name' => static function ($v) { return (string)$v; },
            'description' => static function ($v) { return (string)$v; },
            'purchase_url' => static function ($v) { return self::sanitizeUrl($v); },
            'serial' => static function ($v) { return (string)$v; },
            'model' => static function ($v) { return (string)$v; },
            'vendor' => static function ($v) { return (string)$v; },
            'snmp_auth' => static function ($v) { return self::sanitizeSnmpAuth($v); }
        ];

        $sets = [];
        $params = [':id' => $id];

        foreach ($editable as $field => $cast) {
            if (!array_key_exists($field, $data)) continue;
            $val = $cast($data[$field]);
            if ($val === null && $field === 'snmp_auth') continue; // never wipe stored SNMP config
            $sets[] = "{$field} = :{$field}";
            $params[":{$field}"] = $val;
        }

        if (array_key_exists('floor_id', $data)) {
            $sets[] = "floor_id = :floor_id";
            $params[':floor_id'] = !empty($data['floor_id']) ? (int)$data['floor_id'] : null;
        }

        if (empty($sets)) {
            $this->lastError = "No editable fields provided";
            return false;
        }

        try {
            $sql = "UPDATE printers SET " . implode(', ', $sets) . " WHERE id = :id";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute($params);
        } catch (\PDOException $e) {
            $this->lastError = "Update failed: " . $e->getMessage();
            return false;
        }
    }

    /**
     * Update Printer Levels
     * 
     * Saves the latest ink/toner levels to the database.
     * This allows us to show "Last Known Levels" if the printer goes offline.
     * 
     * @param int $id The printer ID
     * @param array $levels The levels array
     * @return bool True if successful
     */
    public function updatePrinterLevels(int $id, array $levels): bool {
        if (!$this->pdo) return false;
        try {
            $sql = "UPDATE printers SET last_levels = :levels WHERE id = :id";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':id' => $id,
                ':levels' => json_encode($levels)
            ]);
        } catch (\PDOException $e) {
            // Silently fail for levels update is okay, we don't want to break the page
            return false;
        }
    }

    /**
     * Delete a Printer
     * 
     * Removes a printer from the database permanently.
     * 
     * @param int $id The ID of the printer to delete
     * @return bool True if successful
     */
    public function deletePrinter(int $id): bool {
        if (!$this->pdo) {
            if (!$this->lastError) $this->lastError = "Database not connected";
            return false;
        }
        try {
            $stmt = $this->pdo->prepare("DELETE FROM printers WHERE id = :id");
            return $stmt->execute([':id' => $id]);
        } catch (\PDOException $e) {
            $this->lastError = "Delete failed: " . $e->getMessage();
            return false;
        }
    }
    
    // ==================== FLOOR METHODS ====================

    /**
     * Get All Floors
     * 
     * @return array List of floors ordered by sort_order
     */
    public function getFloors(): array {
        if (!$this->pdo) return [];
        try {
            $stmt = $this->pdo->query("SELECT * FROM floors ORDER BY sort_order ASC, id ASC");
            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            $this->lastError = "Fetch floors failed: " . $e->getMessage();
            return [];
        }
    }

    /**
     * Add a New Floor
     * 
     * @param string $name The floor name
     * @param int $sortOrder The display order
     * @return int|false The new floor ID or false on failure
     */
    public function addFloor(string $name, int $sortOrder = 0): int|false {
        if (!$this->pdo) return false;
        $name = trim($name);
        if ($name === '') { $this->lastError = 'Floor name is required'; return false; }
        if (mb_strlen($name) > 100) { $this->lastError = 'Floor name is too long (max 100 characters)'; return false; }
        try {
            $stmt = $this->pdo->prepare("INSERT INTO floors (name, sort_order) VALUES (:name, :sort_order)");
            $stmt->execute([':name' => $name, ':sort_order' => $sortOrder]);
            return (int) $this->pdo->lastInsertId();
        } catch (\PDOException $e) {
            $this->lastError = "Add floor failed: " . $e->getMessage();
            return false;
        }
    }

    /**
     * Update a Floor
     * 
     * @param int $id The floor ID
     * @param string $name The new name
     * @param int|null $sortOrder The new sort order (optional)
     * @return bool True if successful
     */
    public function updateFloor(int $id, string $name, ?int $sortOrder = null): bool {
        if (!$this->pdo) return false;
        try {
            if ($sortOrder !== null) {
                $stmt = $this->pdo->prepare("UPDATE floors SET name = :name, sort_order = :sort_order WHERE id = :id");
                return $stmt->execute([':id' => $id, ':name' => $name, ':sort_order' => $sortOrder]);
            } else {
                $stmt = $this->pdo->prepare("UPDATE floors SET name = :name WHERE id = :id");
                return $stmt->execute([':id' => $id, ':name' => $name]);
            }
        } catch (\PDOException $e) {
            $this->lastError = "Update floor failed: " . $e->getMessage();
            return false;
        }
    }

    /**
     * Delete a Floor
     * 
     * Printers on this floor will have floor_id set to NULL.
     * 
     * @param int $id The floor ID to delete
     * @return bool True if successful
     */
    public function deleteFloor(int $id): bool {
        if (!$this->pdo) return false;
        try {
            $stmt = $this->pdo->prepare("DELETE FROM floors WHERE id = :id");
            return $stmt->execute([':id' => $id]);
        } catch (\PDOException $e) {
            $this->lastError = "Delete floor failed: " . $e->getMessage();
            return false;
        }
    }

    // ==================== ALERT STATE METHODS ====================

    /**
     * All alert states keyed by [printer_id][kind].
     */
    public function getAlertStates(): array {
        if (!$this->pdo) return [];
        try {
            $rows = $this->pdo->query(
                "SELECT printer_id, kind, state FROM alert_state"
            )->fetchAll();
            $out = [];
            foreach ($rows as $r) {
                $out[(int)$r['printer_id']][$r['kind']] = $r['state'];
            }
            return $out;
        } catch (\PDOException $e) {
            $this->lastError = "Alert states fetch failed: " . $e->getMessage();
            return [];
        }
    }

    /**
     * Upsert an alert state (preserves notified_at on update).
     */
    public function setAlertState(int $printerId, string $kind, string $state): bool {
        if (!$this->pdo) return false;
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO alert_state (printer_id, kind, state, notified_at)
                 VALUES (:pid, :kind, :state, NULL)
                 ON DUPLICATE KEY UPDATE state = VALUES(state)"
            );
            return $stmt->execute([':pid' => $printerId, ':kind' => $kind, ':state' => $state]);
        } catch (\PDOException $e) {
            $this->lastError = "Alert state write failed: " . $e->getMessage();
            return false;
        }
    }

    /**
     * Mark the moment an alert was actually emailed.
     */
    public function markAlertNotified(int $printerId, string $kind): bool {
        if (!$this->pdo) return false;
        try {
            $stmt = $this->pdo->prepare(
                "UPDATE alert_state SET notified_at = NOW() WHERE printer_id = :pid AND kind = :kind"
            );
            return $stmt->execute([':pid' => $printerId, ':kind' => $kind]);
        } catch (\PDOException $e) {
            $this->lastError = "Alert notified_at update failed: " . $e->getMessage();
            return false;
        }
    }

    // ==================== SETTINGS METHODS ====================

    /**
     * Get All Settings
     * 
     * @return array Associative array of key => value
     */
    public function getSettings(): array {
        if (!$this->pdo) return [];
        try {
            $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM settings");
            $rows = $stmt->fetchAll();
            $settings = [];
            foreach ($rows as $row) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            return $settings;
        } catch (\PDOException $e) {
            $this->lastError = "Fetch settings failed: " . $e->getMessage();
            return [];
        }
    }

    /**
     * Get a single setting (used by the OAuth device-code flow).
     */
    public function getSingleSetting(string $key, string $default = ''): string {
        if (!$this->pdo) return $default;
        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = :key");
            $stmt->execute([':key' => $key]);
            $row = $stmt->fetch();
            return $row ? (string)$row['setting_value'] : $default;
        } catch (\PDOException $e) {
            return $default;
        }
    }

    /**
     * Set a Setting Value
     * 
     * @param string $key The setting key
     * @param string $value The value to set
     * @return bool True if successful
     */
    public function setSetting(string $key, string $value): bool {
        if (!$this->pdo) return false;
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value) 
                 ON DUPLICATE KEY UPDATE setting_value = :value2"
            );
            return $stmt->execute([':key' => $key, ':value' => $value, ':value2' => $value]);
        } catch (\PDOException $e) {
            $this->lastError = "Set setting failed: " . $e->getMessage();
            return false;
        }
    }
}
