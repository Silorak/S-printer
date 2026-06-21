<?php
declare(strict_types=1);

/**
 * Database Class
 *
 * Handles all MariaDB interactions: connection, schema initialization,
 * and CRUD for printers, floors, settings, and users.
 */
class Database {
    private ?PDO $pdo = null;
    private ?string $lastError = null;

    public function __construct() {
        $host = getenv('DB_HOST') ?: 'db';
        $db   = getenv('DB_NAME') ?: 'sprinter';
        $user = getenv('DB_USER') ?: 'sprinter';
        $pass = getenv('DB_PASS') ?: 'sprinter_password';
        $charset = 'utf8mb4';

        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->pdo = new PDO($dsn, $user, $pass, $options);
            $this->initSchema();
        } catch (\PDOException $e) {
            $this->lastError = "Database connection failed: " . $e->getMessage();
            error_log($this->lastError);
        }
    }

    public function getConnection(): ?PDO {
        return $this->pdo;
    }

    public function getLastError(): ?string {
        return $this->lastError;
    }

    public function beginTransaction(): bool {
        return $this->pdo ? $this->pdo->beginTransaction() : false;
    }

    public function commit(): bool {
        return $this->pdo ? $this->pdo->commit() : false;
    }

    public function rollBack(): bool {
        return $this->pdo ? $this->pdo->rollBack() : false;
    }

    // ===================================================================
    // Internal query helpers — eliminate try/prepare/execute/catch boilerplate
    // ===================================================================

    /** Execute a prepared statement with params. Returns PDOStatement or null on failure. */
    private function run(string $sql, array $params = []): ?PDOStatement {
        if (!$this->pdo) return null;
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            return null;
        }
    }

    /** Run a SELECT and return all rows as array. Returns [] on failure. */
    private function fetchAll(string $sql, array $params = []): array {
        $stmt = $this->run($sql, $params);
        return $stmt ? $stmt->fetchAll() : [];
    }

    /** Run a SELECT and return one row or null. */
    private function fetchOne(string $sql, array $params = []): ?array {
        $stmt = $this->run($sql, $params);
        if (!$stmt) return null;
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ===================================================================
    // Schema
    // ===================================================================

    private function initSchema(): void {
        if (!$this->pdo) return;

        try {
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS floors (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )");

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(64) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                is_admin TINYINT(1) DEFAULT 0,
                can_add_printer TINYINT(1) DEFAULT 0,
                can_edit_printer TINYINT(1) DEFAULT 0,
                can_delete_printer TINYINT(1) DEFAULT 0,
                can_manage_floors TINYINT(1) DEFAULT 0,
                can_manage_settings TINYINT(1) DEFAULT 0,
                can_import_export TINYINT(1) DEFAULT 0,
                is_active TINYINT(1) DEFAULT 1,
                failed_attempts INT DEFAULT 0,
                locked_until DATETIME NULL,
                last_login_at DATETIME NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )");

            $this->pdo->exec("CREATE TABLE IF NOT EXISTS printers (
                id INT AUTO_INCREMENT PRIMARY KEY,
                ip VARCHAR(45) NOT NULL UNIQUE,
                name VARCHAR(255),
                description TEXT,
                purchase_url TEXT,
                serial VARCHAR(255),
                model VARCHAR(255),
                vendor VARCHAR(100),
                floor_id INT,
                custom_oids JSON,
                last_levels JSON,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (floor_id) REFERENCES floors(id) ON DELETE SET NULL
            )");

            // Idempotent migrations for columns added after initial release
            $columns = $this->pdo->query("SHOW COLUMNS FROM users LIKE 'failed_attempts'");
            if ($columns && $columns->fetch() === false) {
                $this->pdo->exec("ALTER TABLE users ADD COLUMN failed_attempts INT DEFAULT 0 AFTER is_active");
            }
            $columns = $this->pdo->query("SHOW COLUMNS FROM users LIKE 'locked_until'");
            if ($columns && $columns->fetch() === false) {
                $this->pdo->exec("ALTER TABLE users ADD COLUMN locked_until DATETIME NULL AFTER failed_attempts");
            }
        } catch (\PDOException $e) {
            $this->lastError = "Schema init failed: " . $e->getMessage();
            error_log($this->lastError);
        }
    }

    // ===================================================================
    // Printer CRUD
    // ===================================================================

    public function getPrinters(): array {
        return $this->fetchAll("SELECT * FROM printers ORDER BY name ASC, ip ASC");
    }

    public function addPrinter(array $data): bool {
        return (bool)$this->run(
            "INSERT INTO printers (ip, name, description, purchase_url, serial, model, vendor, floor_id, custom_oids)
             VALUES (:ip, :name, :description, :purchase_url, :serial, :model, :vendor, :floor_id, :custom_oids)",
            [
                ':ip' => $data['ip'],
                ':name' => $data['name'] ?? null,
                ':description' => $data['description'] ?? null,
                ':purchase_url' => $data['purchase_url'] ?? null,
                ':serial' => $data['serial'] ?? null,
                ':model' => $data['model'] ?? null,
                ':vendor' => $data['vendor'] ?? null,
                ':floor_id' => !empty($data['floor_id']) ? (int)$data['floor_id'] : null,
                ':custom_oids' => isset($data['custom_oids']) ? json_encode($data['custom_oids']) : null,
            ]
        );
    }

    public function updatePrinter(int $id, array $data): bool {
        return (bool)$this->run(
            "UPDATE printers SET name = :name, description = :description, purchase_url = :purchase_url, floor_id = :floor_id WHERE id = :id",
            [
                ':id' => $id,
                ':name' => $data['name'] ?? null,
                ':description' => $data['description'] ?? null,
                ':purchase_url' => $data['purchase_url'] ?? null,
                ':floor_id' => !empty($data['floor_id']) ? (int)$data['floor_id'] : null,
            ]
        );
    }

    public function updatePrinterFully(int $id, array $data): bool {
        $customOids = isset($data['custom_oids'])
            ? (is_array($data['custom_oids']) ? json_encode($data['custom_oids']) : $data['custom_oids'])
            : null;
        $lastLevels = isset($data['last_levels'])
            ? (is_array($data['last_levels']) ? json_encode($data['last_levels']) : $data['last_levels'])
            : null;

        return (bool)$this->run(
            "UPDATE printers SET
                name = :name, description = :description, purchase_url = :purchase_url,
                floor_id = :floor_id, model = :model, serial = :serial, vendor = :vendor,
                custom_oids = :custom_oids, last_levels = :last_levels
             WHERE id = :id",
            [
                ':id' => $id,
                ':name' => $data['name'] ?? null,
                ':description' => $data['description'] ?? null,
                ':purchase_url' => $data['purchase_url'] ?? null,
                ':floor_id' => !empty($data['floor_id']) ? (int)$data['floor_id'] : null,
                ':model' => $data['model'] ?? null,
                ':serial' => $data['serial'] ?? null,
                ':vendor' => $data['vendor'] ?? null,
                ':custom_oids' => $customOids,
                ':last_levels' => $lastLevels,
            ]
        );
    }

    public function hasLevelsChanged(?string $storedLevelsJson, array $levels): bool {
        if ($storedLevelsJson === null || trim($storedLevelsJson) === '') return true;
        $storedLevels = json_decode($storedLevelsJson, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($storedLevels)) return true;
        $stored = json_encode($this->normalizeForJsonComparison($storedLevels), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $fresh = json_encode($this->normalizeForJsonComparison($levels), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $stored !== $fresh;
    }

    public function updatePrinterLevelsIfChanged(int $id, array $levels, ?string $storedLevelsJson): bool {
        if (!$this->hasLevelsChanged($storedLevelsJson, $levels)) return true;
        return $this->updatePrinterLevels($id, $levels);
    }

    public function updatePrinterLevels(int $id, array $levels): bool {
        return (bool)$this->run(
            "UPDATE printers SET last_levels = :levels WHERE id = :id",
            [':id' => $id, ':levels' => json_encode($levels)]
        );
    }

    public function deletePrinter(int $id): bool {
        return (bool)$this->run("DELETE FROM printers WHERE id = :id", [':id' => $id]);
    }

    public function getPrinterByIp(string $ip): ?array {
        return $this->fetchOne("SELECT * FROM printers WHERE ip = :ip", [':ip' => $ip]);
    }

    // ===================================================================
    // Floor CRUD
    // ===================================================================

    public function getFloors(): array {
        return $this->fetchAll("SELECT * FROM floors ORDER BY sort_order ASC, id ASC");
    }

    public function addFloor(string $name, int $sortOrder = 0): int|false {
        if (!$this->pdo) return false;
        $stmt = $this->run(
            "INSERT INTO floors (name, sort_order) VALUES (:name, :sort_order)",
            [':name' => $name, ':sort_order' => $sortOrder]
        );
        return $stmt ? (int)$this->pdo->lastInsertId() : false;
    }

    public function updateFloor(int $id, string $name, ?int $sortOrder = null): bool {
        if ($sortOrder !== null) {
            return (bool)$this->run(
                "UPDATE floors SET name = :name, sort_order = :sort_order WHERE id = :id",
                [':id' => $id, ':name' => $name, ':sort_order' => $sortOrder]
            );
        }
        return (bool)$this->run(
            "UPDATE floors SET name = :name WHERE id = :id",
            [':id' => $id, ':name' => $name]
        );
    }

    public function deleteFloor(int $id): bool {
        return (bool)$this->run("DELETE FROM floors WHERE id = :id", [':id' => $id]);
    }

    // ===================================================================
    // Settings CRUD
    // ===================================================================

    public function getSettings(): array {
        $rows = $this->fetchAll("SELECT setting_key, setting_value FROM settings");
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        return $settings;
    }

    public function getSetting(string $key, mixed $default = null): mixed {
        $row = $this->fetchOne("SELECT setting_value FROM settings WHERE setting_key = :key", [':key' => $key]);
        return $row ? $row['setting_value'] : $default;
    }

    public function setSetting(string $key, string $value): bool {
        return (bool)$this->run(
            "INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value)
             ON DUPLICATE KEY UPDATE setting_value = :value2",
            [':key' => $key, ':value' => $value, ':value2' => $value]
        );
    }

    // ===================================================================
    // User CRUD + Auth helpers
    // ===================================================================

    public function hasUsers(): bool {
        if (!$this->pdo) return false;
        $row = $this->fetchOne("SELECT COUNT(*) AS user_count FROM users");
        return (int)($row['user_count'] ?? 0) > 0;
    }

    public function getUserByUsername(string $username): ?array {
        return $this->fetchOne("SELECT * FROM users WHERE username = :username LIMIT 1", [':username' => $username]);
    }

    public function getUserById(int $id): ?array {
        return $this->fetchOne("SELECT * FROM users WHERE id = :id LIMIT 1", [':id' => $id]);
    }

    public function getUsers(): array {
        return $this->fetchAll(
            "SELECT id, username, is_admin, can_add_printer, can_edit_printer, can_delete_printer,
                    can_manage_floors, can_manage_settings, can_import_export, is_active,
                    last_login_at, created_at, updated_at
             FROM users ORDER BY is_admin DESC, username ASC"
        );
    }

    public function createFirstAdminAtomic(string $username, string $passwordHash, array $permissions, bool $isActive = true): int|false {
        if (!$this->pdo) return false;
        try {
            $this->pdo->beginTransaction();
            $check = $this->pdo->query('SELECT COUNT(*) FROM users FOR UPDATE');
            if ((int)$check->fetchColumn() > 0) {
                $this->pdo->rollBack();
                return false;
            }
            $this->run($this->userInsertSql(), $this->userInsertParams($username, $passwordHash, true, $permissions, $isActive));
            $id = (int)$this->pdo->lastInsertId();
            $this->pdo->commit();
            return $id;
        } catch (\PDOException $e) {
            $this->lastError = "Create first admin failed: " . $e->getMessage();
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return false;
        }
    }

    public function createUser(string $username, string $passwordHash, bool $isAdmin = false, array $permissions = [], bool $isActive = true): int|false {
        if (!$this->pdo) return false;
        $stmt = $this->run($this->userInsertSql(), $this->userInsertParams($username, $passwordHash, $isAdmin, $permissions, $isActive));
        return $stmt ? (int)$this->pdo->lastInsertId() : false;
    }

    public function recordFailedLogin(int $userId, int $maxAttempts = 5, int $baseLockoutSeconds = 15): bool {
        if (!$this->pdo) return false;
        try {
            $stmt = $this->pdo->prepare("UPDATE users
                SET failed_attempts = failed_attempts + 1,
                    locked_until = CASE
                        WHEN failed_attempts + 1 >= :max THEN
                            DATE_ADD(NOW(), INTERVAL LEAST(:base * POWER(2, GREATEST(failed_attempts + 1 - :max2, 0)), 3600) SECOND)
                        ELSE locked_until
                    END
                WHERE id = :id");
            $stmt->bindValue(':max', $maxAttempts, \PDO::PARAM_INT);
            $stmt->bindValue(':base', $baseLockoutSeconds, \PDO::PARAM_INT);
            $stmt->bindValue(':max2', $maxAttempts, \PDO::PARAM_INT);
            $stmt->bindValue(':id', $userId, \PDO::PARAM_INT);
            return $stmt->execute();
        } catch (\PDOException $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    public function resetFailedLogin(int $userId): bool {
        return (bool)$this->run(
            "UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = :id",
            [':id' => $userId]
        );
    }

    public function isUserLocked(int $userId): ?string {
        $stmt = $this->run(
            "SELECT locked_until FROM users WHERE id = :id AND locked_until IS NOT NULL AND locked_until > NOW() LIMIT 1",
            [':id' => $userId]
        );
        if (!$stmt) return null;
        $result = $stmt->fetchColumn();
        return $result !== false ? (string)$result : null;
    }

    public function updateUserPermissions(int $id, array $permissions, bool $isActive = true): bool {
        return (bool)$this->run(
            "UPDATE users SET
                can_add_printer = :can_add_printer, can_edit_printer = :can_edit_printer,
                can_delete_printer = :can_delete_printer, can_manage_floors = :can_manage_floors,
                can_manage_settings = :can_manage_settings, can_import_export = :can_import_export,
                is_active = :is_active
             WHERE id = :id AND is_admin = 0",
            $this->userPermissionParams($id, $permissions, $isActive)
        );
    }

    public function updateUserPasswordHash(int $id, string $passwordHash): bool {
        return (bool)$this->run(
            "UPDATE users SET password_hash = :password_hash WHERE id = :id",
            [':id' => $id, ':password_hash' => $passwordHash]
        );
    }

    public function recordUserLogin(int $id): bool {
        return (bool)$this->run(
            "UPDATE users SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id",
            [':id' => $id]
        );
    }

    // ===================================================================
    // Private helpers
    // ===================================================================

    private function userInsertSql(): string {
        return "INSERT INTO users (
            username, password_hash, is_admin,
            can_add_printer, can_edit_printer, can_delete_printer,
            can_manage_floors, can_manage_settings, can_import_export, is_active
        ) VALUES (
            :username, :password_hash, :is_admin,
            :can_add_printer, :can_edit_printer, :can_delete_printer,
            :can_manage_floors, :can_manage_settings, :can_import_export, :is_active
        )";
    }

    private function userInsertParams(string $username, string $passwordHash, bool $isAdmin, array $permissions, bool $isActive): array {
        return array_merge(
            [':username' => $username, ':password_hash' => $passwordHash, ':is_admin' => $isAdmin ? 1 : 0],
            $this->userPermissionParams(0, $permissions, $isActive)
        );
    }

    private function userPermissionParams(int $id, array $permissions, bool $isActive): array {
        $params = [
            ':can_add_printer' => !empty($permissions['can_add_printer']) ? 1 : 0,
            ':can_edit_printer' => !empty($permissions['can_edit_printer']) ? 1 : 0,
            ':can_delete_printer' => !empty($permissions['can_delete_printer']) ? 1 : 0,
            ':can_manage_floors' => !empty($permissions['can_manage_floors']) ? 1 : 0,
            ':can_manage_settings' => !empty($permissions['can_manage_settings']) ? 1 : 0,
            ':can_import_export' => !empty($permissions['can_import_export']) ? 1 : 0,
            ':is_active' => $isActive ? 1 : 0,
        ];
        if ($id > 0) $params[':id'] = $id;
        return $params;
    }

    private function normalizeForJsonComparison(mixed $value): mixed {
        if (!is_array($value)) return $value;
        if (array_is_list($value)) {
            return array_map(fn($item) => $this->normalizeForJsonComparison($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeForJsonComparison($item);
        }
        return $value;
    }
}
