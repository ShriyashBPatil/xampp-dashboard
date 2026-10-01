<?php
require_once __DIR__ . '/core.php';

/**
 * Initialize FTP Profiles and Logs Tables in Auth Database
 */
function init_ftp_system() {
    try {
        $authPdo = get_db_connection(AUTH_DB);

        // FTP Profiles Table
        $authPdo->exec("CREATE TABLE IF NOT EXISTS `ftp_profiles` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `profile_name` VARCHAR(100) NOT NULL,
            `provider` VARCHAR(50) DEFAULT 'cpanel',
            `protocol` ENUM('ftp', 'ftps', 'sftp') DEFAULT 'ftp',
            `host` VARCHAR(255) NOT NULL,
            `port` INT DEFAULT 21,
            `username` VARCHAR(150) NOT NULL,
            `password` TEXT NULL,
            `remote_path` VARCHAR(255) DEFAULT '/public_html/',
            `passive_mode` TINYINT(1) DEFAULT 1,
            `ssl_verify` TINYINT(1) DEFAULT 0,
            `is_default` TINYINT(1) DEFAULT 0,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // FTP Transfer Logs Table
        $authPdo->exec("CREATE TABLE IF NOT EXISTS `ftp_transfer_logs` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `profile_id` INT NULL,
            `project_name` VARCHAR(150) NOT NULL,
            `remote_path` VARCHAR(255) NOT NULL,
            `files_count` INT DEFAULT 0,
            `total_bytes` BIGINT DEFAULT 0,
            `status` ENUM('success', 'partial', 'failed') DEFAULT 'success',
            `message` TEXT NULL,
            `transferred_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    } catch (Exception $e) {
        error_log("init_ftp_system error: " . $e->getMessage());
    }
}
init_ftp_system();

/**
 * Retrieve all saved FTP profiles
 */
function get_ftp_profiles() {
    try {
        $authPdo = get_db_connection(AUTH_DB);
        $stmt = $authPdo->query("SELECT * FROM `ftp_profiles` ORDER BY `is_default` DESC, `profile_name` ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Retrieve single FTP profile by ID
 */
function get_ftp_profile($id) {
    try {
        $authPdo = get_db_connection(AUTH_DB);
        $stmt = $authPdo->prepare("SELECT * FROM `ftp_profiles` WHERE `id` = ? LIMIT 1");
        $stmt->execute([(int)$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Save or update FTP profile
 */
function save_ftp_profile($data) {
    try {
        $authPdo = get_db_connection(AUTH_DB);

        $id = !empty($data['id']) ? (int)$data['id'] : 0;
        $name = trim($data['profile_name'] ?? 'Shared Hosting Server');
        $provider = trim($data['provider'] ?? 'cpanel');
        $protocol = in_array($data['protocol'] ?? '', ['ftp', 'ftps', 'sftp']) ? $data['protocol'] : 'ftp';
        $host = trim($data['host'] ?? '');
        $port = !empty($data['port']) ? (int)$data['port'] : ($protocol === 'sftp' ? 22 : 21);
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';
        $remotePath = trim($data['remote_path'] ?? '/public_html/');
        $passiveMode = isset($data['passive_mode']) && $data['passive_mode'] ? 1 : 0;
        $sslVerify = isset($data['ssl_verify']) && $data['ssl_verify'] ? 1 : 0;
        $isDefault = isset($data['is_default']) && $data['is_default'] ? 1 : 0;

        if (empty($host) || empty($username)) {
            return ['success' => false, 'error' => 'Host and Username are required.'];
        }

        if ($isDefault) {
            $authPdo->exec("UPDATE `ftp_profiles` SET `is_default` = 0");
        }

        if ($id > 0) {
            // Update
            if ($password === '') {
                // Keep existing password
                $stmt = $authPdo->prepare("UPDATE `ftp_profiles` SET 
                    `profile_name` = ?, `provider` = ?, `protocol` = ?, `host` = ?, `port` = ?,
                    `username` = ?, `remote_path` = ?, `passive_mode` = ?, `ssl_verify` = ?, `is_default` = ?
                    WHERE `id` = ?");
                $stmt->execute([$name, $provider, $protocol, $host, $port, $username, $remotePath, $passiveMode, $sslVerify, $isDefault, $id]);
            } else {
                $stmt = $authPdo->prepare("UPDATE `ftp_profiles` SET 
                    `profile_name` = ?, `provider` = ?, `protocol` = ?, `host` = ?, `port` = ?,
                    `username` = ?, `password` = ?, `remote_path` = ?, `passive_mode` = ?, `ssl_verify` = ?, `is_default` = ?
                    WHERE `id` = ?");
                $stmt->execute([$name, $provider, $protocol, $host, $port, $username, $password, $remotePath, $passiveMode, $sslVerify, $isDefault, $id]);
            }
            return ['success' => true, 'id' => $id, 'message' => "Profile '{$name}' updated successfully."];
        } else {
            // Insert
            $stmt = $authPdo->prepare("INSERT INTO `ftp_profiles` 
                (`profile_name`, `provider`, `protocol`, `host`, `port`, `username`, `password`, `remote_path`, `passive_mode`, `ssl_verify`, `is_default`)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $provider, $protocol, $host, $port, $username, $password, $remotePath, $passiveMode, $sslVerify, $isDefault]);
            $newId = (int)$authPdo->lastInsertId();
            return ['success' => true, 'id' => $newId, 'message' => "Hosting profile '{$name}' created successfully."];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Delete FTP profile
 */
function delete_ftp_profile($id) {
    try {
        $authPdo = get_db_connection(AUTH_DB);
        $stmt = $authPdo->prepare("DELETE FROM `ftp_profiles` WHERE `id` = ?");
        $stmt->execute([(int)$id]);
        return ['success' => true, 'message' => 'Profile deleted.'];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Log transfer history
 */
function log_ftp_transfer($profileId, $projectName, $remotePath, $filesCount, $totalBytes, $status, $message) {
    try {
        $authPdo = get_db_connection(AUTH_DB);
        $stmt = $authPdo->prepare("INSERT INTO `ftp_transfer_logs` 
            (`profile_id`, `project_name`, `remote_path`, `files_count`, `total_bytes`, `status`, `message`)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$profileId, $projectName, $remotePath, $filesCount, $totalBytes, $status, $message]);
    } catch (Exception $e) {}
}

/**
 * Get recent transfer logs
 */
function get_ftp_transfer_logs($limit = 15) {
    try {
        $authPdo = get_db_connection(AUTH_DB);
        $stmt = $authPdo->prepare("SELECT l.*, p.profile_name, p.provider, p.host 
            FROM `ftp_transfer_logs` l 
            LEFT JOIN `ftp_profiles` p ON l.profile_id = p.id 
            ORDER BY l.transferred_at DESC LIMIT ?");
        $stmt->bindValue(1, (int)$limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Build Base cURL URL for FTP/FTPS/SFTP
 */
function build_ftp_url($config, $remoteFilePath = '') {
    $protocol = $config['protocol'] ?? 'ftp';
    $host = trim($config['host'] ?? '');
    $port = !empty($config['port']) ? (int)$config['port'] : ($protocol === 'sftp' ? 22 : 21);

    // Normalize protocol scheme
    $scheme = 'ftp://';
    if ($protocol === 'ftps') {
        $scheme = 'ftps://';
    } elseif ($protocol === 'sftp') {
        $scheme = 'sftp://';
    }

    $cleanPath = ltrim($remoteFilePath, '/');
    return $scheme . $host . ':' . $port . '/' . $cleanPath;
}

/**
 * Configure common cURL FTP options
 */
function setup_ftp_curl_handle($ch, $config) {
    $username = $config['username'] ?? '';
    $password = $config['password'] ?? '';
    $protocol = $config['protocol'] ?? 'ftp';
    $passive = !empty($config['passive_mode']);
    $sslVerify = !empty($config['ssl_verify']);

    curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);

    // Passive mode vs Active mode
    if ($protocol !== 'sftp') {
        curl_setopt($ch, CURLOPT_FTP_USE_EPSV, $passive ? false : false);
        curl_setopt($ch, CURLOPT_FTP_USE_PRET, false);
    }

    // SSL / TLS settings
    if ($protocol === 'ftps') {
        curl_setopt($ch, CURLOPT_USE_SSL, CURLUSESSL_ALL);
    } elseif ($protocol === 'ftp') {
        curl_setopt($ch, CURLOPT_USE_SSL, CURLUSESSL_TRY);
    }

    if (!$sslVerify) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }
}

/**
 * Test FTP Connection & verify access
 */
function test_ftp_connection($config) {
    $startTime = microtime(true);
    $remotePath = trim($config['remote_path'] ?? '/public_html/');
    $testUrl = build_ftp_url($config, $remotePath);
    
    // Ensure trailing slash for directory listing
    if (!str_ends_with($testUrl, '/')) {
        $testUrl .= '/';
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $testUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_DIRLISTONLY, true);
    setup_ftp_curl_handle($ch, $config);

    $result = curl_exec($ch);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    $latencyMs = round((microtime(true) - $startTime) * 1000);

    if ($errno === 0) {
        $lines = array_filter(array_map('trim', explode("\n", (string)$result)));
        return [
            'success' => true,
            'message' => "Connected successfully to {$config['host']} ({$latencyMs} ms). Remote directory is reachable.",
            'latency_ms' => $latencyMs,
            'remote_files' => array_values($lines),
            'files_count' => count($lines)
        ];
    } else {
        return [
            'success' => false,
            'error' => "FTP Connection failed: {$error} (Error #{$errno})",
            'latency_ms' => $latencyMs
        ];
    }
}

/**
 * Scan Project Directory for FTP Deployment
 */
function scan_project_files_for_ftp($projectOrPath, $options = []) {
    // If it's a project name relative to WWW_ROOT
    if (strpos($projectOrPath, '/') === false && strpos($projectOrPath, '\\') === false) {
        $dir = WWW_ROOT . '/' . $projectOrPath;
    } else {
        $dir = $projectOrPath;
    }

    $excludeGit = $options['exclude_git'] ?? true;
    $excludeNodeModules = $options['exclude_node_modules'] ?? true;
    $excludeVendor = $options['exclude_vendor'] ?? false;
    $excludeIdea = $options['exclude_ide'] ?? true;

    $files = [];
    $totalBytes = 0;

    if (is_file($dir)) {
        // Single file standalone script
        $rel = basename($dir);
        $size = filesize($dir);
        return [
            'success' => true,
            'project' => basename($dir),
            'is_single_file' => true,
            'total_files' => 1,
            'total_bytes' => $size,
            'total_size_formatted' => format_bytes($size),
            'files' => [
                [
                    'relative_path' => $rel,
                    'full_path' => $dir,
                    'size' => $size,
                    'formatted_size' => format_bytes($size)
                ]
            ]
        ];
    }

    if (!is_dir($dir)) {
        return ['success' => false, 'error' => "Project folder not found: {$projectOrPath}"];
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $subPathName = $iterator->getSubPathname();
        $normalizedSubPath = str_replace('\\', '/', $subPathName);

        // Exclusions
        if ($excludeGit && (str_starts_with($normalizedSubPath, '.git') || strpos($normalizedSubPath, '/.git') !== false)) {
            continue;
        }
        if ($excludeNodeModules && (str_starts_with($normalizedSubPath, 'node_modules') || strpos($normalizedSubPath, '/node_modules') !== false)) {
            continue;
        }
        if ($excludeVendor && (str_starts_with($normalizedSubPath, 'vendor') || strpos($normalizedSubPath, '/vendor') !== false)) {
            continue;
        }
        if ($excludeIdea && (
            str_starts_with($normalizedSubPath, '.idea') || strpos($normalizedSubPath, '/.idea') !== false ||
            str_starts_with($normalizedSubPath, '.vscode') || strpos($normalizedSubPath, '/.vscode') !== false ||
            basename($normalizedSubPath) === '.DS_Store' || basename($normalizedSubPath) === 'Thumbs.db'
        )) {
            continue;
        }

        if ($item->isFile()) {
            $fSize = $item->getSize();
            $files[] = [
                'relative_path' => $normalizedSubPath,
                'full_path' => $item->getPathname(),
                'size' => $fSize,
                'formatted_size' => format_bytes($fSize)
            ];
            $totalBytes += $fSize;
        }
    }

    return [
        'success' => true,
        'project' => basename($dir),
        'is_single_file' => false,
        'total_files' => count($files),
        'total_bytes' => $totalBytes,
        'total_size_formatted' => format_bytes($totalBytes),
        'files' => $files
    ];
}

/**
 * Upload single file to FTP / FTPS / SFTP using cURL with auto-directory creation
 */
function upload_single_file_ftp($localFilePath, $remoteRelativePath, $config) {
    if (!file_exists($localFilePath) || !is_file($localFilePath)) {
        return ['success' => false, 'error' => "Local file does not exist: {$localFilePath}"];
    }

    $baseRemotePath = rtrim($config['remote_path'] ?? '/public_html/', '/');
    $fullRemotePath = $baseRemotePath . '/' . ltrim($remoteRelativePath, '/');
    $uploadUrl = build_ftp_url($config, $fullRemotePath);

    $fp = fopen($localFilePath, 'r');
    if (!$fp) {
        return ['success' => false, 'error' => "Unable to read local file: {$localFilePath}"];
    }

    $fileSize = filesize($localFilePath);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $uploadUrl);
    curl_setopt($ch, CURLOPT_UPLOAD, 1);
    curl_setopt($ch, CURLOPT_INFILE, $fp);
    curl_setopt($ch, CURLOPT_INFILESIZE, $fileSize);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // Automatically create remote directory tree
    curl_setopt($ch, CURLOPT_FTP_CREATE_MISSING_DIRS, CURLFTP_CREATE_DIR_RETRY);

    setup_ftp_curl_handle($ch, $config);

    $result = curl_exec($ch);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    fclose($fp);

    if ($errno === 0) {
        return [
            'success' => true,
            'remote_path' => $fullRemotePath,
            'bytes' => $fileSize
        ];
    } else {
        return [
            'success' => false,
            'error' => "Upload failed ({$error}, #{$errno})",
            'remote_path' => $fullRemotePath
        ];
    }
}

/**
 * Format bytes into human readable format
 */
function format_bytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

/**
 * Get detailed remote directory listing via FTP
 */
function get_ftp_directory_listing($config, $remotePath = '/') {
    $cleanPath = '/' . trim($remotePath, '/');
    if ($cleanPath !== '/' && !str_ends_with($cleanPath, '/')) {
        $cleanPath .= '/';
    }

    $url = build_ftp_url($config, $cleanPath);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_DIRLISTONLY, false);
    setup_ftp_curl_handle($ch, $config);

    $rawListing = curl_exec($ch);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno !== 0) {
        return [
            'success' => false,
            'error' => "Failed to list remote directory: {$error} (#{$errno})",
            'current_path' => $cleanPath,
            'items' => []
        ];
    }

    $lines = array_filter(array_map('trim', explode("\n", (string)$rawListing)));
    $directories = [];
    $files = [];

    foreach ($lines as $line) {
        if (empty($line)) continue;

        // Unix style: drwxr-xr-x 4 user group 4096 Sep 30 06:43 foldername
        if (preg_match('/^([d\-lbcps][rwx\-tTsS]{9})\s+(\d+)\s+(\S+)\s+(\S+)\s+(\d+)\s+(\w{3}\s+\d+\s+[\d:]+)\s+(.+)$/', $line, $m)) {
            $perms = $m[1];
            $isDir = str_starts_with($perms, 'd');
            $owner = $m[3];
            $group = $m[4];
            $size = (int)$m[5];
            $date = $m[6];
            $name = trim($m[7]);

            if ($name === '.' || $name === '..') continue;

            $item = [
                'name' => $name,
                'is_dir' => $isDir,
                'type' => $isDir ? 'dir' : 'file',
                'size' => $size,
                'formatted_size' => $isDir ? '-' : format_bytes($size),
                'permissions' => $perms,
                'owner' => $owner,
                'group' => $group,
                'date' => $date,
                'extension' => $isDir ? '' : strtolower(pathinfo($name, PATHINFO_EXTENSION)),
                'path' => rtrim($cleanPath, '/') . '/' . $name
            ];

            if ($isDir) {
                $directories[] = $item;
            } else {
                $files[] = $item;
            }
        }
        // Windows DOS style: 09-30-26 06:43PM <DIR> foldername or 09-30-26 06:43PM 1234 filename.ext
        elseif (preg_match('/^(\d{2}-\d{2}-\d{2,4}\s+\d{2}:\d{2}[AP]M)\s+(<DIR>|\d+)\s+(.+)$/i', $line, $m)) {
            $date = $m[1];
            $isDir = strtoupper($m[2]) === '<DIR>';
            $size = $isDir ? 0 : (int)$m[2];
            $name = trim($m[3]);

            if ($name === '.' || $name === '..') continue;

            $item = [
                'name' => $name,
                'is_dir' => $isDir,
                'type' => $isDir ? 'dir' : 'file',
                'size' => $size,
                'formatted_size' => $isDir ? '-' : format_bytes($size),
                'permissions' => $isDir ? 'drwxr-xr-x' : '-rw-r--r--',
                'owner' => 'ftp',
                'group' => 'ftp',
                'date' => $date,
                'extension' => $isDir ? '' : strtolower(pathinfo($name, PATHINFO_EXTENSION)),
                'path' => rtrim($cleanPath, '/') . '/' . $name
            ];

            if ($isDir) {
                $directories[] = $item;
            } else {
                $files[] = $item;
            }
        }
        // Fallback simple filename
        else {
            $name = $line;
            if ($name === '.' || $name === '..') continue;
            $files[] = [
                'name' => $name,
                'is_dir' => false,
                'type' => 'file',
                'size' => 0,
                'formatted_size' => '-',
                'permissions' => '-rw-r--r--',
                'owner' => '',
                'group' => '',
                'date' => '',
                'extension' => strtolower(pathinfo($name, PATHINFO_EXTENSION)),
                'path' => rtrim($cleanPath, '/') . '/' . $name
            ];
        }
    }

    // Sort directories and files alphabetically
    usort($directories, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    usort($files, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    $items = array_merge($directories, $files);

    return [
        'success' => true,
        'current_path' => $cleanPath,
        'items' => $items,
        'total_items' => count($items),
        'directories_count' => count($directories),
        'files_count' => count($files)
    ];
}

/**
 * Read / Preview Remote File Content via FTP
 */
function read_ftp_file_content($config, $remoteFilePath, $maxBytes = 2097152) {
    $cleanPath = ltrim($remoteFilePath, '/');
    $url = build_ftp_url($config, $cleanPath);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    setup_ftp_curl_handle($ch, $config);

    $content = curl_exec($ch);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno !== 0) {
        return [
            'success' => false,
            'error' => "Failed to read remote file: {$error} (#{$errno})"
        ];
    }

    $isTruncated = false;
    if (strlen($content) > $maxBytes) {
        $content = substr($content, 0, $maxBytes);
        $isTruncated = true;
    }

    $ext = strtolower(pathinfo($cleanPath, PATHINFO_EXTENSION));
    $isBinary = !in_array($ext, ['php', 'html', 'htm', 'js', 'css', 'json', 'txt', 'sql', 'md', 'xml', 'env', 'htaccess', 'ini', 'log', 'sh', 'py', 'svg']);

    return [
        'success' => true,
        'file_name' => basename($cleanPath),
        'remote_path' => '/' . $cleanPath,
        'size' => strlen($content),
        'formatted_size' => format_bytes(strlen($content)),
        'content' => $isBinary ? base64_encode($content) : $content,
        'is_binary' => $isBinary,
        'is_truncated' => $isTruncated,
        'extension' => $ext
    ];
}

/**
 * Create Remote Folder via FTP
 */
function create_ftp_remote_folder($config, $parentPath, $folderName) {
    $parent = '/' . trim($parentPath, '/');
    $target = rtrim($parent, '/') . '/' . trim($folderName, '/');
    $quoteTarget = ltrim($target, '/');

    $url = build_ftp_url($config, $parent === '/' ? '' : ltrim($parent, '/'));

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_QUOTE, ["MKD {$quoteTarget}"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    setup_ftp_curl_handle($ch, $config);

    $res = curl_exec($ch);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno === 0) {
        return ['success' => true, 'message' => "Remote folder '{$folderName}' created.", 'path' => $target];
    } else {
        return ['success' => false, 'error' => "Failed to create folder: {$error} (#{$errno})"];
    }
}

/**
 * Delete Remote File or Folder via FTP
 */
function delete_ftp_remote_item($config, $remotePath, $isDir = false) {
    $cleanPath = ltrim($remotePath, '/');
    $command = $isDir ? "RMD {$cleanPath}" : "DELE {$cleanPath}";

    $url = build_ftp_url($config, '');

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_QUOTE, [$command]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    setup_ftp_curl_handle($ch, $config);

    $res = curl_exec($ch);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    curl_close($ch);

    if ($errno === 0) {
        return ['success' => true, 'message' => "Item '{$cleanPath}' deleted successfully."];
    } else {
        return ['success' => false, 'error' => "Failed to delete: {$error} (#{$errno})"];
    }
}

