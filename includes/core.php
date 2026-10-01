<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('WWW_ROOT', realpath(__DIR__ . '/../..'));
define('AUTH_DB', 'xampp_dashboard');

// Flash Messaging
function set_flash($type, $msg) {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}
function setFlash($type, $msg) {
    set_flash($type, $msg);
}

function get_flash() {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
function getFlash() {
    return get_flash();
}

// Database Connection Helper (Connects with empty password)
function get_db_connection($dbname = null) {
    $dsn = "mysql:host=db;charset=utf8mb4" . ($dbname ? ";dbname=" . $dbname : "");
    return new PDO($dsn, "root", "", [
        PDO::ATTR_TIMEOUT => 3,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
}
function getDbConnection($dbname = null) {
    return get_db_connection($dbname);
}

// Ensure Auth Database and Users Table exist
function init_auth_system() {
    try {
        $rootPdo = get_db_connection();
        $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `" . AUTH_DB . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        $authPdo = get_db_connection(AUTH_DB);
        $authPdo->exec("CREATE TABLE IF NOT EXISTS `users` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `username` VARCHAR(50) NOT NULL UNIQUE,
            `email` VARCHAR(100) NOT NULL UNIQUE,
            `password` VARCHAR(255) NOT NULL,
            `role` ENUM('admin', 'user') DEFAULT 'user',
            `status` ENUM('active', 'suspended') DEFAULT 'active',
            `permissions` TEXT NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Migrate existing table if columns missing
        try {
            $authPdo->exec("ALTER TABLE `users` ADD COLUMN `status` ENUM('active', 'suspended') DEFAULT 'active' AFTER `role`");
        } catch (Exception $e) {}
        try {
            $authPdo->exec("ALTER TABLE `users` ADD COLUMN `permissions` TEXT NULL AFTER `status`");
        } catch (Exception $e) {}

        // Check if any admin exists. If not, auto-create default admin (admin / admin123)
        $stmt = $authPdo->query("SELECT COUNT(*) FROM `users` WHERE `role` = 'admin'");
        if ($stmt->fetchColumn() == 0) {
            $passHash = password_hash('admin123', PASSWORD_DEFAULT);
            $allPerms = json_encode(get_all_privilege_keys());
            $insert = $authPdo->prepare("INSERT INTO `users` (username, email, password, role, status, permissions) VALUES (?, ?, ?, 'admin', 'active', ?)");
            $insert->execute(['admin', 'admin@example.com', $passHash, $allPerms]);
        }
    } catch (Exception $e) {
        // Fallback
    }
}
init_auth_system();

// System Settings Helper & Persistent Storage
function init_system_settings() {
    try {
        $authPdo = get_db_connection(AUTH_DB);
        $authPdo->exec("CREATE TABLE IF NOT EXISTS `system_settings` (
            `setting_key` VARCHAR(100) PRIMARY KEY,
            `setting_value` TEXT NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $defaults = [
            'workspace_title' => 'Shriyash Patil',
            'workspace_subtitle' => 'Workspace Suite • Apache • MariaDB',
            'server_domain' => 'server.shriyashpatil.in:8181',
            'default_remote_path' => '/',
            'allow_guest_mode' => '1',
            'debug_mode' => '0',
            'auto_patch_db' => '1',
            'exclude_git' => '1',
            'exclude_node_modules' => '1',
            'exclude_vendor' => '0',
            'session_lifetime' => '86400',
            'theme_accent' => 'indigo'
        ];

        foreach ($defaults as $k => $v) {
            $stmt = $authPdo->prepare("INSERT IGNORE INTO `system_settings` (`setting_key`, `setting_value`) VALUES (?, ?)");
            $stmt->execute([$k, $v]);
        }
    } catch (Exception $e) {}
}
init_system_settings();

function get_system_settings() {
    try {
        $authPdo = get_db_connection(AUTH_DB);
        $stmt = $authPdo->query("SELECT `setting_key`, `setting_value` FROM `system_settings`");
        $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        return $rows ?: [];
    } catch (Exception $e) {
        return [];
    }
}

function get_system_setting($key, $default = null) {
    $settings = get_system_settings();
    return $settings[$key] ?? $default;
}

function save_system_settings($data) {
    try {
        $authPdo = get_db_connection(AUTH_DB);
        $stmt = $authPdo->prepare("INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`)");
        foreach ($data as $k => $v) {
            $stmt->execute([$k, is_bool($v) ? ($v ? '1' : '0') : (string)$v]);
        }
        return ['success' => true, 'message' => 'Settings saved successfully.'];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Auth Helpers
function current_user() {
    if (empty($_SESSION['user'])) return null;
    
    // For authenticated registered users, sync permissions & status live from DB
    if (empty($_SESSION['user']['is_guest']) && !empty($_SESSION['user']['id'])) {
        static $synced = false;
        if (!$synced) {
            try {
                $authPdo = get_db_connection(AUTH_DB);
                $stmt = $authPdo->prepare("SELECT id, username, email, role, status, permissions FROM `users` WHERE id = ?");
                $stmt->execute([$_SESSION['user']['id']]);
                $fresh = $stmt->fetch();
                if ($fresh) {
                    if (($fresh['status'] ?? 'active') === 'suspended') {
                        unset($_SESSION['user']);
                        set_flash('error', 'Your account has been locked or suspended by an administrator.');
                        header('Location: /dashboard/login.php');
                        exit;
                    }
                    $_SESSION['user']['role'] = $fresh['role'];
                    $_SESSION['user']['status'] = $fresh['status'];
                    $_SESSION['user']['permissions'] = $fresh['permissions'];
                    $_SESSION['user']['username'] = $fresh['username'];
                    $_SESSION['user']['email'] = $fresh['email'];
                } else {
                    // User was deleted
                    unset($_SESSION['user']);
                    set_flash('error', 'Your account no longer exists.');
                    header('Location: /dashboard/login.php');
                    exit;
                }
            } catch (Exception $e) {}
            $synced = true;
        }
    }
    return $_SESSION['user'];
}

function is_logged_in() {
    $u = current_user();
    return !empty($u);
}

function is_guest() {
    $u = current_user();
    return !empty($u) && (!empty($u['is_guest']) || ($u['role'] ?? '') === 'guest');
}

function is_admin() {
    $u = current_user();
    return !empty($u) && !is_guest() && ($u['role'] ?? '') === 'admin';
}

// All Available Granular Feature Privileges (Organized by Functional Category)
function get_all_feature_privileges() {
    return [
        'Deploy & Sync' => [
            'can_ftp_deploy'           => ['name' => 'FTP Project Deploy', 'desc' => 'Upload local folders to remote shared hosting via FTP'],
            'can_ftp_explorer'         => ['name' => 'FTP Remote Filesystem', 'desc' => 'Browse, read, and create remote directories on FTP servers'],
            'can_github_sync'          => ['name' => 'GitHub Repository Sync', 'desc' => 'Trigger git repository sync and remote backups']
        ],
        'Files & Editor' => [
            'can_files_browse'         => ['name' => 'Browse Workspace Files', 'desc' => 'Inspect directories and view source code files'],
            'can_files_edit'           => ['name' => 'Edit & Save Files', 'desc' => 'Modify existing code and save changes to disk'],
            'can_files_create'         => ['name' => 'Create Files & Folders', 'desc' => 'Create new scripts and directories in workspace'],
            'can_files_upload'         => ['name' => 'Upload Files to Server', 'desc' => 'Directly upload files into workspace folders'],
            'can_files_download'       => ['name' => 'Download Files & ZIPs', 'desc' => 'Download files and compress folders into ZIP archives'],
            'can_files_delete'         => ['name' => 'Delete Files & Folders', 'desc' => 'Remove files and subdirectories from workspace']
        ],
        'Database (MariaDB)' => [
            'can_db_view'              => ['name' => 'Browse Tables & Schema', 'desc' => 'View database lists, table structures, and browse rows'],
            'can_db_query'             => ['name' => 'Execute SQL Console', 'desc' => 'Run custom SQL queries in database manager'],
            'can_db_create'            => ['name' => 'Create & Drop Databases', 'desc' => 'Create new databases or delete existing tables/databases'],
            'can_db_export'            => ['name' => 'Export SQL Dumps', 'desc' => 'Download complete database SQL backup dumps']
        ],
        'Servers & CLI' => [
            'can_terminal_exec'        => ['name' => 'Web Terminal & CLI', 'desc' => 'Run bash commands and interactive AGY CLI shell'],
            'can_ssh_connect'          => ['name' => 'SSH Remote Host Sessions', 'desc' => 'Open and manage remote SSH host connections']
        ],
        'User & Access Control' => [
            'can_users_create'         => ['name' => 'Add / Create New Users', 'desc' => 'Register and onboard new workspace collaborator accounts'],
            'can_users_edit_privileges'=> ['name' => 'Set User Roles & Privileges', 'desc' => 'Assign roles and modify granular feature privileges for accounts'],
            'can_users_suspend'        => ['name' => 'Lock & Suspend Accounts', 'desc' => 'Temporarily lock or activate user accounts'],
            'can_users_reset_pwd'      => ['name' => 'Reset User Passwords', 'desc' => 'Directly override and assign new passwords to accounts'],
            'can_users_delete'         => ['name' => 'Remove / Delete Users', 'desc' => 'Delete user accounts and revoke workspace access']
        ],
        'System & Provisioning' => [
            'can_autosetup'            => ['name' => '1-Click Auto Setup', 'desc' => 'Deploy ZIP/folder archives with database auto-creation'],
            'can_system_settings'      => ['name' => 'Universal Settings', 'desc' => 'Configure environment parameters, domains, and exclusions']
        ]
    ];
}

function get_all_privilege_keys() {
    $keys = [];
    foreach (get_all_feature_privileges() as $category => $items) {
        foreach ($items as $k => $v) {
            $keys[] = $k;
        }
    }
    return $keys;
}

function get_user_permissions($userId = null) {
    if ($userId === null) {
        $user = current_user();
        if (!$user) return [];
        if (($user['role'] ?? '') === 'admin') {
            return get_all_privilege_keys();
        }
        if (isset($user['permissions'])) {
            $perms = is_array($user['permissions']) ? $user['permissions'] : (json_decode($user['permissions'], true) ?: []);
            return is_array($perms) ? $perms : [];
        }
        $userId = $user['id'] ?? 0;
    }
    if (!$userId) return [];
    try {
        $authPdo = get_db_connection(AUTH_DB);
        $stmt = $authPdo->prepare("SELECT role, permissions FROM `users` WHERE id = ?");
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row) return [];
        if ($row['role'] === 'admin') {
            return get_all_privilege_keys();
        }
        return json_decode($row['permissions'] ?? '[]', true) ?: [];
    } catch (Exception $e) {
        return [];
    }
}

function has_permission($permission) {
    if (!is_logged_in()) return false;
    if (is_guest()) {
        return in_array($permission, ['can_files_browse', 'can_db_view']);
    }
    if (is_admin()) return true;
    $perms = get_user_permissions();
    return in_array($permission, $perms);
}

function require_permission($permission, $customMsg = 'Access denied. You do not have permission to access this module or perform this action.') {
    require_login();
    if (!has_permission($permission)) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' || (!empty($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)) {
            header('Content-Type: application/json', true, 403);
            echo json_encode(['success' => false, 'error' => $customMsg]);
            exit;
        }
        set_flash('error', $customMsg);
        $redirect = $_SERVER['HTTP_REFERER'] ?? '/dashboard/';
        // Avoid self redirection loop
        if (strpos($redirect, $_SERVER['PHP_SELF']) !== false) {
            $redirect = '/dashboard/';
        }
        header('Location: ' . $redirect);
        exit;
    }
}

function login_as_guest() {
    $_SESSION['user'] = [
        'id' => 0,
        'username' => 'Guest',
        'email' => 'guest@workspace.local',
        'role' => 'guest',
        'is_guest' => true,
        'created_at' => date('Y-m-d H:i:s')
    ];
}

function require_login() {
    if (!is_logged_in()) {
        set_flash('error', 'Please log in to access the dashboard.');
        header('Location: /dashboard/login.php');
        exit;
    }
}

function require_not_guest($customMsg = 'This action is disabled in Guest Mode (Read-Only). Please sign in with an administrator account.') {
    if (is_guest()) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json', true, 403);
            echo json_encode(['success' => false, 'error' => $customMsg]);
            exit;
        }
        set_flash('error', $customMsg);
        $redirect = $_SERVER['HTTP_REFERER'] ?? '/dashboard/';
        header('Location: ' . $redirect);
        exit;
    }
}

function require_admin() {
    require_login();
    if (!is_admin()) {
        set_flash('error', 'Access denied. Administrator privileges required.');
        header('Location: /dashboard/');
        exit;
    }
}

// Recursive directory delete helper
function deleteDirectoryRecursive($dir) {
    if (!file_exists($dir)) return true;
    if (!is_dir($dir)) return unlink($dir);
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') continue;
        if (!deleteDirectoryRecursive($dir . DIRECTORY_SEPARATOR . $item)) return false;
    }
    return rmdir($dir);
}

// Run SQL file helper
function run_sql_file($targetDb, $sqlFilePath) {
    $pdo = get_db_connection($targetDb);
    $queriesCount = 0;
    
    // Fast import via mysql CLI (empty password)
    $cmd = "mysql --skip-ssl -h db -u root " . escapeshellarg($targetDb) . " < " . escapeshellarg($sqlFilePath) . " 2>&1";
    $output = [];
    $ret = 0;
    @exec($cmd, $output, $ret);
    if ($ret === 0) {
        return 1;
    }

    // Fallback: chunked execution
    $handle = fopen($sqlFilePath, 'r');
    if (!$handle) return 0;

    $query = '';
    $inString = false;
    $delimiter = ';';

    while (!feof($handle)) {
        $line = fgets($handle);
        $trimmed = trim($line);

        if (preg_match('/^DELIMITER\s+(.+)$/i', $trimmed, $matches)) {
            $delimiter = trim($matches[1]);
            continue;
        }

        if (!$inString && (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#') || str_starts_with($trimmed, '/*'))) {
            continue;
        }

        $query .= $line;
        if (str_ends_with(rtrim($query), $delimiter)) {
            $stmt = trim(substr(rtrim($query), 0, -strlen($delimiter)));
            if (!empty($stmt)) {
                try {
                    $pdo->exec($stmt);
                    $queriesCount++;
                } catch (Exception $e) {
                    // ignore non-fatal
                }
            }
            $query = '';
        }
    }
    fclose($handle);

    if (!empty(trim($query))) {
        try {
            $pdo->exec(trim($query));
            $queriesCount++;
        } catch (Exception $e) {}
    }
    return $queriesCount;
}

// Helper to recursively set permissions
function chmod_r($path, $dirPerm = 0777, $filePerm = 0666) {
    if (!file_exists($path)) return;
    if (is_dir($path)) {
        @chmod($path, $dirPerm);
        $handle = opendir($path);
        if ($handle) {
            while (($entry = readdir($handle)) !== false) {
                if ($entry === '.' || $entry === '..') continue;
                chmod_r($path . '/' . $entry, $dirPerm, $filePerm);
            }
            closedir($handle);
        }
    } else {
        @chmod($path, $filePerm);
    }
}

// Clean up junk files and flatten single nested root directory if zip/folder was extracted as folder-in-folder
function normalize_directory_structure($dir) {
    if (!is_dir($dir)) return;

    // Remove junk files/folders from macOS / Windows / archive generators
    $junk = ['__MACOSX', '.DS_Store', 'Thumbs.db', 'desktop.ini'];
    foreach ($junk as $j) {
        $junkPath = $dir . '/' . $j;
        if (file_exists($junkPath)) {
            if (is_dir($junkPath)) {
                deleteDirectoryRecursive($junkPath);
            } else {
                @unlink($junkPath);
            }
        }
    }

    // Check if everything was extracted into a single nested subfolder
    $items = array_values(array_filter(scandir($dir), function($item) {
        return !in_array($item, ['.', '..', '__MACOSX', '.DS_Store', 'Thumbs.db', 'desktop.ini']);
    }));

    // If there is only 1 entry in the project folder and that entry is a directory,
    // move all files from the sub-directory into the main project folder.
    while (count($items) === 1 && is_dir($dir . '/' . $items[0])) {
        $nestedSubdir = $dir . '/' . $items[0];
        $tempStaging = $dir . '_staging_' . uniqid();
        
        if (rename($nestedSubdir, $tempStaging)) {
            $subItems = scandir($tempStaging);
            foreach ($subItems as $subItem) {
                if ($subItem === '.' || $subItem === '..') continue;
                rename($tempStaging . '/' . $subItem, $dir . '/' . $subItem);
            }
            @rmdir($tempStaging);
        }

        $items = array_values(array_filter(scandir($dir), function($item) {
            return !in_array($item, ['.', '..', '__MACOSX', '.DS_Store', 'Thumbs.db', 'desktop.ini']);
        }));
    }

    chmod_r($dir, 0777, 0666);
}

// Auto config patcher (patches to empty password)
function patch_project_configs($projectDir, $dbName) {
    $configCandidates = [
        'config.php', 'db.php', 'database.php', 'conn.php', 'connect.php', 'connection.php',
        'config/database.php', 'config/db.php', 'app/config.php', 'includes/db.php', 'includes/config.php',
        'db_connect.php', 'dbconn.php', '.env'
    ];
    
    $patchedFiles = [];
    foreach ($configCandidates as $rel) {
        $filePath = $projectDir . '/' . $rel;
        if (file_exists($filePath) && is_file($filePath)) {
            $content = file_get_contents($filePath);
            $orig = $content;

            if ($rel === '.env') {
                $content = preg_replace('/^DB_HOST=.*$/m', 'DB_HOST=db', $content);
                $content = preg_replace('/^DB_DATABASE=.*$/m', 'DB_DATABASE=' . $dbName, $content);
                $content = preg_replace('/^DB_USERNAME=.*$/m', 'DB_USERNAME=root', $content);
                $content = preg_replace('/^DB_PASSWORD=.*$/m', 'DB_PASSWORD=', $content);
            } else {
                $content = preg_replace('/define\s*\(\s*[\'\"](DB_HOST|DBHOST|MYSQL_HOST|DATABASE_HOST)[\'\"]\s*,\s*[\'\"].*?[\'\"]\s*\)/i', "define('$1', 'db')", $content);
                $content = preg_replace('/define\s*\(\s*[\'\"](DB_NAME|DBNAME|MYSQL_DB|DATABASE_NAME|DB_DATABASE)[\'\"]\s*,\s*[\'\"].*?[\'\"]\s*\)/i', "define('$1', '{$dbName}')", $content);
                $content = preg_replace('/define\s*\(\s*[\'\"](DB_USER|DBUSER|MYSQL_USER|DATABASE_USER|DB_USERNAME)[\'\"]\s*,\s*[\'\"].*?[\'\"]\s*\)/i', "define('$1', 'root')", $content);
                $content = preg_replace('/define\s*\(\s*[\'\"](DB_PASS|DBPASSWORD|DBPASS|MYSQL_PASS|DATABASE_PASS|DB_PASSWORD)[\'\"]\s*,\s*[\'\"].*?[\'\"]\s*\)/i', "define('$1', '')", $content);

                $content = preg_replace('/\\$(host|db_host|servername|dbHost|dbserver)\s*=\s*[\'\"].*?[\'\"]\s*;/i', "\$$1 = 'db';", $content);
                $content = preg_replace('/\\$(dbname|db_name|database|dbName|dbDatabase)\s*=\s*[\'\"].*?[\'\"]\s*;/i', "\$$1 = '{$dbName}';", $content);
                $content = preg_replace('/\\$(user|db_user|username|dbUser|dbUsername)\s*=\s*[\'\"].*?[\'\"]\s*;/i', "\$$1 = 'root';", $content);
                $content = preg_replace('/\\$(pass|password|db_pass|dbPassword|dbpass)\s*=\s*[\'\"].*?[\'\"]\s*;/i', "\$$1 = '';", $content);
            }

            if ($content !== $orig) {
                file_put_contents($filePath, $content);
                $patchedFiles[] = $rel;
            }
        }
    }
    return $patchedFiles;
}

// Environment & Database Scan
$phpVersion = phpversion();
$serverSoftware = $_SERVER['SERVER_SOFTWARE'] ?? 'Apache 2.4';
$dbList = [];
$dbStatus = false;
$dbError = null;

try {
    $rootPdo = get_db_connection();
    $dbStatus = true;
    $databases = $rootPdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
    $systemDbs = ['information_schema', 'mysql', 'performance_schema', 'sys', AUTH_DB];
    foreach ($databases as $d) {
        if (!in_array($d, $systemDbs)) {
            $dbList[] = $d;
        }
    }
} catch (Exception $e) {
    $dbError = $e->getMessage();
}

// Scan projects & scripts in WWW_ROOT
$allProjects = [];
$standaloneScripts = [];
$scanItems = scandir(WWW_ROOT);

foreach ($scanItems as $item) {
    if (in_array($item, ['.', '..', 'index.php', 'info.php', 'dashboard', '.DS_Store', '.git'])) continue;
    $fullPath = WWW_ROOT . '/' . $item;
    
    if (is_dir($fullPath)) {
        $entryPoint = 'index.php';
        if (file_exists($fullPath . '/index.html')) $entryPoint = 'index.html';
        
        $sqlFiles = glob($fullPath . '/*.sql');
        $hasDb = file_exists($fullPath . '/db.php') || file_exists($fullPath . '/config.php') || !empty($sqlFiles);

        // Detect project favicon
        $projectFavicon = null;
        $faviconCandidates = [
            'favicon.ico', 'favicon.png', 'favicon.svg', 'favicon.webp', 'icon.png', 'icon.svg',
            'apple-touch-icon.png', 'apple-touch-icon-precomposed.png',
            'assets/favicon.ico', 'assets/favicon.png', 'assets/favicon.svg', 'assets/icon.png',
            'assets/images/favicon.ico', 'assets/images/favicon.png', 'assets/images/favicon.svg',
            'assets/img/favicon.ico', 'assets/img/favicon.png', 'assets/img/favicon.svg',
            'assets/icons/favicon.ico', 'assets/icons/favicon.png', 'assets/icons/favicon.svg',
            'public/favicon.ico', 'public/favicon.png', 'public/favicon.svg',
            'static/favicon.ico', 'static/favicon.png', 'static/favicon.svg'
        ];

        foreach ($faviconCandidates as $favCandidate) {
            if (file_exists($fullPath . '/' . $favCandidate)) {
                $projectFavicon = '/' . rawurlencode($item) . '/' . $favCandidate;
                break;
            }
        }

        // Check HTML head of entry point if not found in root/common assets
        if (!$projectFavicon && file_exists($fullPath . '/' . $entryPoint)) {
            $headerSnippet = @file_get_contents($fullPath . '/' . $entryPoint, false, null, 0, 8192);
            if ($headerSnippet) {
                if (preg_match('/<link[^>]+rel=["\'](?:shortcut )?icon["\'][^>]+href=["\']([^"\'>]+)["\']/i', $headerSnippet, $m) ||
                    preg_match('/<link[^>]+href=["\']([^"\'>]+)["\'][^>]+rel=["\'](?:shortcut )?icon["\']/i', $headerSnippet, $m)) {
                    $favHref = trim($m[1]);
                    if (preg_match('#^https?://#i', $favHref)) {
                        $projectFavicon = $favHref;
                    } elseif (str_starts_with($favHref, '/')) {
                        $projectFavicon = $favHref;
                    } else {
                        $projectFavicon = '/' . rawurlencode($item) . '/' . ltrim($favHref, './');
                    }
                }
            }
        }

        $allProjects[] = [
            'name' => $item,
            'entry' => $entryPoint,
            'url' => '/' . rawurlencode($item) . '/',
            'display_url' => 'server.shriyashpatil.in:8181/' . $item,
            'has_db' => $hasDb,
            'favicon' => $projectFavicon,
            'updated' => date('M d, Y H:i', filemtime($fullPath))
        ];
    } elseif (pathinfo($item, PATHINFO_EXTENSION) === 'php') {
        $standaloneScripts[] = [
            'name' => $item,
            'url' => '/' . rawurlencode($item),
            'display_url' => 'server.shriyashpatil.in:8181/' . $item,
            'size' => round(filesize($fullPath) / 1024, 1) . ' KB',
            'updated' => date('M d, Y H:i', filemtime($fullPath))
        ];
    }
}
