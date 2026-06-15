<?php
declare(strict_types=1);

const SPRINTER_MIN_PASSWORD_BYTES = 15;
const SPRINTER_MAX_PASSWORD_BYTES = 1024;
const SPRINTER_PERMISSIONS = [
    'can_add_printer' => 'Add printers',
    'can_edit_printer' => 'Edit printers',
    'can_delete_printer' => 'Delete printers',
    'can_manage_floors' => 'Manage floors',
    'can_manage_settings' => 'Manage display settings',
    'can_import_export' => 'Import and export backups',
    'can_manage_users' => 'Manage users'
];

function sprinterIsHttps(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function sprinterStartSecureSession(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_name('sprinter_sid');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => sprinterIsHttps(),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();

    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

function sprinterCsrfToken(): string {
    sprinterStartSecureSession();
    return (string)$_SESSION['csrf_token'];
}

function sprinterVerifyCsrfToken(?string $token): bool {
    sprinterStartSecureSession();
    return is_string($token) && hash_equals((string)$_SESSION['csrf_token'], $token);
}

function sprinterRequireCsrfToken(): void {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);
    if (!sprinterVerifyCsrfToken(is_string($token) ? $token : null)) {
        sprinterApiError('Invalid CSRF token', 403);
    }
}

function sprinterPasswordAlgo(): string|int|null {
    if (defined('PASSWORD_ARGON2ID') && in_array(PASSWORD_ARGON2ID, password_algos(), true)) {
        return PASSWORD_ARGON2ID;
    }
    return PASSWORD_DEFAULT;
}

function sprinterValidatePassword(string $password): ?string {
    $length = strlen($password);
    if ($length < SPRINTER_MIN_PASSWORD_BYTES) {
        return 'Password must be at least 15 bytes long.';
    }
    if ($length > SPRINTER_MAX_PASSWORD_BYTES) {
        return 'Password is too long.';
    }
    if (sprinterPasswordAlgo() === PASSWORD_DEFAULT && $length > 72) {
        return 'Password is too long for the available password hashing backend.';
    }
    return null;
}

function sprinterHashPassword(string $password): string {
    $hash = password_hash($password, sprinterPasswordAlgo());
    if (!is_string($hash) || $hash === '') {
        throw new RuntimeException('Unable to hash password');
    }
    return $hash;
}

function sprinterNormalizeUsername(mixed $username): string {
    $normalized = trim((string)$username);
    if (!preg_match('/^[A-Za-z0-9_.-]{3,64}$/', $normalized)) {
        throw new InvalidArgumentException('Username must be 3-64 characters and use letters, numbers, dots, underscores, or hyphens.');
    }
    return $normalized;
}

function sprinterPermissionDefaults(bool $grantAll = false): array {
    $permissions = [];
    foreach (SPRINTER_PERMISSIONS as $key => $_label) {
        if ($key === 'can_manage_users') {
            continue;
        }
        $permissions[$key] = $grantAll;
    }
    return $permissions;
}

function sprinterSanitizePermissions(mixed $input): array {
    $permissions = sprinterPermissionDefaults(false);
    if (!is_array($input)) {
        return $permissions;
    }

    foreach ($permissions as $key => $_value) {
        $permissions[$key] = !empty($input[$key]) && $input[$key] !== '0' && $input[$key] !== 'false';
    }
    return $permissions;
}

function sprinterPermissionsFromUser(?array $user): array {
    $permissions = sprinterPermissionDefaults(false);
    if (!$user) {
        $permissions['can_manage_users'] = false;
        return $permissions;
    }

    $isAdmin = !empty($user['is_admin']);
    foreach ($permissions as $key => $_value) {
        $permissions[$key] = $isAdmin || !empty($user[$key]);
    }
    $permissions['can_manage_users'] = $isAdmin;
    return $permissions;
}

function sprinterCan(?array $user, string $permission): bool {
    $permissions = sprinterPermissionsFromUser($user);
    return !empty($permissions[$permission]);
}

function sprinterRequirePermission(?array $user, string $permission): void {
    if (!sprinterCan($user, $permission)) {
        sprinterApiError('Forbidden', 403);
    }
}

function sprinterPublicUser(array $user): array {
    return [
        'id' => (int)$user['id'],
        'username' => (string)$user['username'],
        'is_admin' => !empty($user['is_admin']),
        'is_active' => !empty($user['is_active']),
        'permissions' => sprinterPermissionsFromUser($user)
    ];
}

function sprinterPublicUsers(array $users): array {
    return array_map(static function (array $user): array {
        return sprinterPublicUser($user) + [
            'created_at' => $user['created_at'] ?? null,
            'last_login_at' => $user['last_login_at'] ?? null
        ];
    }, $users);
}

function sprinterGetCurrentUser(Database $db): ?array {
    sprinterStartSecureSession();
    $userId = $_SESSION['user_id'] ?? null;
    if (!is_int($userId) && !(is_string($userId) && ctype_digit($userId))) {
        unset($_SESSION['user_id']);
        return null;
    }

    $user = $db->getUserById((int)$userId);
    if (!$user || empty($user['is_active'])) {
        unset($_SESSION['user_id']);
        return null;
    }
    return $user;
}

function sprinterLogin(Database $db, string $username, string $password): bool {
    $user = $db->getUserByUsername($username);
    if (!$user || empty($user['is_active']) || !password_verify($password, (string)$user['password_hash'])) {
        return false;
    }

    $algo = sprinterPasswordAlgo();
    if (password_needs_rehash((string)$user['password_hash'], $algo)) {
        $db->updateUserPasswordHash((int)$user['id'], sprinterHashPassword($password));
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $db->recordUserLogin((int)$user['id']);
    return true;
}

function sprinterLogout(): void {
    sprinterStartSecureSession();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
}

function sprinterRegisterFirstAdmin(Database $db, string $username, string $password): bool {
    if ($db->hasUsers()) {
        return false;
    }

    $passwordError = sprinterValidatePassword($password);
    if ($passwordError !== null) {
        throw new InvalidArgumentException($passwordError);
    }

    $created = $db->createUser(
        sprinterNormalizeUsername($username),
        sprinterHashPassword($password),
        true,
        sprinterPermissionDefaults(true),
        true
    );

    if ($created === false) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$created;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $db->recordUserLogin((int)$created);
    return true;
}

function sprinterAuthRedirect(): never {
    $target = strtok($_SERVER['REQUEST_URI'] ?? 'index.php', '?') ?: 'index.php';
    header('Location: ' . $target);
    exit;
}

function sprinterSetAuthError(string $message): void {
    sprinterStartSecureSession();
    $_SESSION['auth_error'] = $message;
}

function sprinterTakeAuthError(): ?string {
    sprinterStartSecureSession();
    $message = $_SESSION['auth_error'] ?? null;
    unset($_SESSION['auth_error']);
    return is_string($message) ? $message : null;
}

function sprinterHandleAuthPost(Database $db): void {
    sprinterStartSecureSession();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['auth_action'])) {
        return;
    }

    $action = (string)$_POST['auth_action'];

    if (!sprinterVerifyCsrfToken(isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : null)) {
        sprinterSetAuthError('Your session expired. Please try again.');
        sprinterAuthRedirect();
    }

    try {
        if ($action === 'logout') {
            sprinterLogout();
            sprinterAuthRedirect();
        }

        if ($action === 'register_admin') {
            if (sprinterRegisterFirstAdmin($db, (string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? ''))) {
                sprinterAuthRedirect();
            }
            sprinterSetAuthError('Unable to create the main admin account.');
            sprinterAuthRedirect();
        }

        if ($action === 'login') {
            $username = sprinterNormalizeUsername($_POST['username'] ?? '');
            if (sprinterLogin($db, $username, (string)($_POST['password'] ?? ''))) {
                sprinterAuthRedirect();
            }
            sprinterSetAuthError('Invalid username or password.');
            sprinterAuthRedirect();
        }
    } catch (Throwable $e) {
        sprinterSetAuthError($e->getMessage());
        sprinterAuthRedirect();
    }
}

function sprinterApiError(string $message, int $status = 401): never {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

function sprinterRequireApiUser(Database $db): array {
    sprinterStartSecureSession();
    if (!$db->hasUsers()) {
        sprinterApiError('Setup required', 428);
    }

    $user = sprinterGetCurrentUser($db);
    if (!$user) {
        sprinterApiError('Authentication required', 401);
    }
    return $user;
}

function sprinterRenderAuthPage(string $mode, ?string $error = null): never {
    sprinterStartSecureSession();
    $isRegister = $mode === 'register';
    $title = $isRegister ? 'Create main admin account' : 'Login';
    $subtitle = $isRegister
        ? 'No admin account exists yet. Create the persistent main admin account to unlock S~printer.'
        : 'Sign in to monitor local printers.';
    $action = $isRegister ? 'register_admin' : 'login';
    $button = $isRegister ? 'Create Admin Account' : 'Login';
    $passwordAutocomplete = $action === 'login' ? 'current-password' : 'new-password';
    $token = htmlspecialchars(sprinterCsrfToken(), ENT_QUOTES, 'UTF-8');
    $safeError = $error ? htmlspecialchars($error, ENT_QUOTES, 'UTF-8') : '';

    header('Content-Type: text/html; charset=utf-8');
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:;");
    header("Referrer-Policy: same-origin");

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="S~printer authentication">
    <title>S~printer - {$title}</title>
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/layout.css">
    <link rel="stylesheet" href="css/components.css">
    <link rel="stylesheet" href="css/modals.css">
    <link rel="stylesheet" href="css/responsive.css">
</head>
<body class="auth-body">
    <main class="auth-shell">
        <section class="auth-card">
            <div class="ink-logo auth-logo">
                <div class="ink-bubble"></div>
                <div class="ink-bubble"></div>
                <div class="ink-bubble"></div>
                <div class="ink-bubble"></div>
                <div class="ink-wave"></div>
            </div>
            <h1>{$title}</h1>
            <p class="auth-subtitle">{$subtitle}</p>
HTML;
    if ($safeError !== '') {
        echo "\n            <div class=\"auth-error\">{$safeError}</div>";
    }
    echo <<<HTML
            <form method="post" class="auth-form" autocomplete="on">
                <input type="hidden" name="auth_action" value="{$action}">
                <input type="hidden" name="csrf_token" value="{$token}">
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" required minlength="3" maxlength="64" pattern="[A-Za-z0-9_.-]{3,64}" autocomplete="username">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" required minlength="15" maxlength="1024" autocomplete="{$passwordAutocomplete}">
                    <small class="auth-hint">Use at least 15 characters. Passphrases are recommended.</small>
                </div>
                <button type="submit" class="primary-btn auth-submit">{$button}</button>
            </form>
        </section>
    </main>
</body>
</html>
HTML;
    exit;
}
