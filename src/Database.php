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
            // Make sure the 'printers' table exists
            $this->initSchema();
        } catch (\PDOException $e) {
            // If connection fails, save the error
            $this->lastError = "Database connection failed: " . $e->getMessage();
            error_log($this->lastError);
        }
    }

    // Get the raw connection object if needed
    public function getConnection(): ?PDO {
        return $this->pdo;
    }

    // Get the last error message
    public function getLastError(): ?string {
        return $this->lastError;
    }

    /**
     * Begin Database Transaction
     */
    public function beginTransaction(): bool {
        if (!$this->pdo) return false;
        return $this->pdo->beginTransaction();
    }

    /**
     * Commit Database Transaction
     */
    public function commit(): bool {
        if (!$this->pdo) return false;
        return $this->pdo->commit();
    }

    /**
     * Rollback Database Transaction
     */
    public function rollBack(): bool {
        if (!$this->pdo) return false;
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
        
        try {
            $sql = "INSERT INTO printers (ip, name, description, purchase_url, serial, model, vendor, floor_id, custom_oids) 
                    VALUES (:ip, :name, :description, :purchase_url, :serial, :model, :vendor, :floor_id, :custom_oids)";
            
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':ip' => $data['ip'],
                ':name' => $data['name'] ?? null,
                ':description' => $data['description'] ?? null,
                ':purchase_url' => $data['purchase_url'] ?? null,
                ':serial' => $data['serial'] ?? null,
                ':model' => $data['model'] ?? null,
                ':vendor' => $data['vendor'] ?? null,
                ':floor_id' => !empty($data['floor_id']) ? (int)$data['floor_id'] : null,
                ':custom_oids' => isset($data['custom_oids']) ? json_encode($data['custom_oids']) : null
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

        try {
            $sql = "UPDATE printers SET 
                    name = :name, 
                    description = :description, 
                    purchase_url = :purchase_url,
                    floor_id = :floor_id 
                    WHERE id = :id";
            
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute([
                ':id' => $id,
                ':name' => $data['name'] ?? null,
                ':description' => $data['description'] ?? null,
                ':purchase_url' => $data['purchase_url'] ?? null,
                ':floor_id' => !empty($data['floor_id']) ? (int)$data['floor_id'] : null
            ]);
        } catch (\PDOException $e) {
            $this->lastError = "Update failed: " . $e->getMessage();
            return false;
        }
    }

    /**
     * Update a Printer Fully
     * 
     * Updates all details of an existing printer including model, serial, vendor, custom_oids, last_levels.
     * Useful for import/restore operations.
     * 
     * @param int $id The ID of the printer to update
     * @param array $data The new data
     * @return bool True if successful
     */
    public function updatePrinterFully(int $id, array $data): bool {
        if (!$this->pdo) {
            if (!$this->lastError) $this->lastError = "Database not connected";
            return false;
        }

        try {
            $sql = "UPDATE printers SET 
                    name = :name, 
                    description = :description, 
                    purchase_url = :purchase_url,
                    floor_id = :floor_id,
                    model = :model,
                    serial = :serial,
                    vendor = :vendor,
                    custom_oids = :custom_oids,
                    last_levels = :last_levels
                    WHERE id = :id";
            
            $stmt = $this->pdo->prepare($sql);
            
            $customOids = null;
            if (isset($data['custom_oids'])) {
                $customOids = is_array($data['custom_oids']) ? json_encode($data['custom_oids']) : $data['custom_oids'];
            }
            
            $lastLevels = null;
            if (isset($data['last_levels'])) {
                $lastLevels = is_array($data['last_levels']) ? json_encode($data['last_levels']) : $data['last_levels'];
            }

            return $stmt->execute([
                ':id' => $id,
                ':name' => $data['name'] ?? null,
                ':description' => $data['description'] ?? null,
                ':purchase_url' => $data['purchase_url'] ?? null,
                ':floor_id' => !empty($data['floor_id']) ? (int)$data['floor_id'] : null,
                ':model' => $data['model'] ?? null,
                ':serial' => $data['serial'] ?? null,
                ':vendor' => $data['vendor'] ?? null,
                ':custom_oids' => $customOids,
                ':last_levels' => $lastLevels
            ]);
        } catch (\PDOException $e) {
            $this->lastError = "Full update failed: " . $e->getMessage();
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
    
    /**
     * Get Printer by IP
     * 
     * Checks if a printer with this IP already exists.
     * Useful for preventing duplicates.
     * 
     * @param string $ip The IP address to look up
     * @return array|null The printer record or null if not found
     */
    public function getPrinterByIp(string $ip): ?array {
        if (!$this->pdo) return null;
        try {
            $stmt = $this->pdo->prepare("SELECT * FROM printers WHERE ip = :ip");
            $stmt->execute([':ip' => $ip]);
            $result = $stmt->fetch();
            return $result ?: null;
        } catch (\PDOException $e) {
            $this->lastError = "Fetch by IP failed: " . $e->getMessage();
            return null;
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
     * Get a Single Setting
     * 
     * @param string $key The setting key
     * @param mixed $default Default value if not found
     * @return mixed The setting value or default
     */
    public function getSetting(string $key, mixed $default = null): mixed {
        if (!$this->pdo) return $default;
        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = :key");
            $stmt->execute([':key' => $key]);
            $result = $stmt->fetch();
            return $result ? $result['setting_value'] : $default;
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
