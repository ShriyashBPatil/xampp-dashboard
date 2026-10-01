<?php
require_once __DIR__ . '/core.php';

// Initialize GitHub Settings and Repos Tables in Auth Database
function init_github_system() {
    try {
        $authPdo = get_db_connection(AUTH_DB);

        // GitHub Settings Table
        $authPdo->exec("CREATE TABLE IF NOT EXISTS `github_settings` (
            `id` INT PRIMARY KEY,
            `github_token` TEXT NULL,
            `github_username` VARCHAR(100) NULL,
            `github_avatar` VARCHAR(255) NULL,
            `default_visibility` ENUM('public', 'private') DEFAULT 'private',
            `git_committer_name` VARCHAR(100) DEFAULT 'XAMPP Dashboard',
            `git_committer_email` VARCHAR(150) DEFAULT 'admin@workspace.local',
            `auto_sync_enabled` TINYINT(1) DEFAULT 0,
            `auto_sync_interval` VARCHAR(50) DEFAULT 'hourly',
            `auto_create_missing` TINYINT(1) DEFAULT 0,
            `include_db_backup` TINYINT(1) DEFAULT 1,
            `last_global_sync` DATETIME NULL,
            `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Add columns if older schema
        try {
            $authPdo->exec("ALTER TABLE `github_settings` ADD COLUMN IF NOT EXISTS `auto_sync_interval` VARCHAR(50) DEFAULT 'hourly'");
            $authPdo->exec("ALTER TABLE `github_settings` ADD COLUMN IF NOT EXISTS `auto_create_missing` TINYINT(1) DEFAULT 0");
        } catch (Exception $e) {}

        // GitHub Repos Mapping Table
        $authPdo->exec("CREATE TABLE IF NOT EXISTS `github_repos` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `project_name` VARCHAR(150) NOT NULL UNIQUE,
            `repo_name` VARCHAR(150) NOT NULL,
            `repo_url` VARCHAR(255) NOT NULL,
            `branch` VARCHAR(50) DEFAULT 'main',
            `is_sync_enabled` TINYINT(1) DEFAULT 1,
            `include_db` TINYINT(1) DEFAULT 1,
            `db_name` VARCHAR(100) NULL,
            `last_sync_status` ENUM('idle', 'success', 'error') DEFAULT 'idle',
            `last_sync_message` TEXT NULL,
            `last_sync_at` DATETIME NULL,
            `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (`project_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Ensure default settings row exists
        $stmt = $authPdo->query("SELECT COUNT(*) FROM `github_settings` WHERE `id` = 1");
        if ($stmt->fetchColumn() == 0) {
            $authPdo->exec("INSERT INTO `github_settings` (`id`, `default_visibility`, `git_committer_name`, `git_committer_email`, `include_db_backup`, `auto_sync_interval`, `auto_create_missing`) 
                           VALUES (1, 'private', 'XAMPP Dashboard', 'admin@workspace.local', 1, 'hourly', 0)");
        }
    } catch (Exception $e) {
        error_log("init_github_system error: " . $e->getMessage());
    }
}
init_github_system();

// Retrieve GitHub Global Settings
function get_github_settings() {
    try {
        $authPdo = get_db_connection(AUTH_DB);
        $stmt = $authPdo->prepare("SELECT * FROM `github_settings` WHERE `id` = 1 LIMIT 1");
        $stmt->execute();
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res) {
            if (empty($res['auto_sync_interval'])) {
                $res['auto_sync_interval'] = 'hourly';
            }
            if (!isset($res['auto_create_missing'])) {
                $res['auto_create_missing'] = 0;
            }
            return $res;
        }
    } catch (Exception $e) {}

    return [
        'id' => 1,
        'github_token' => '',
        'github_username' => '',
        'github_avatar' => '',
        'default_visibility' => 'private',
        'git_committer_name' => 'XAMPP Dashboard',
        'git_committer_email' => 'admin@workspace.local',
        'auto_sync_enabled' => 0,
        'auto_sync_interval' => 'hourly',
        'auto_create_missing' => 0,
        'include_db_backup' => 1,
        'last_global_sync' => null
    ];
}

// Save GitHub Global Settings
function save_github_settings($data) {
    try {
        $authPdo = get_db_connection(AUTH_DB);
        $stmt = $authPdo->prepare("UPDATE `github_settings` SET 
            `github_token` = ?, 
            `github_username` = ?, 
            `github_avatar` = ?, 
            `default_visibility` = ?, 
            `git_committer_name` = ?, 
            `git_committer_email` = ?, 
            `auto_sync_enabled` = ?, 
            `auto_sync_interval` = ?, 
            `auto_create_missing` = ?, 
            `include_db_backup` = ?
            WHERE `id` = 1");

        $stmt->execute([
            $data['github_token'] ?? '',
            $data['github_username'] ?? '',
            $data['github_avatar'] ?? '',
            $data['default_visibility'] ?? 'private',
            $data['git_committer_name'] ?? 'XAMPP Dashboard',
            $data['git_committer_email'] ?? 'admin@workspace.local',
            !empty($data['auto_sync_enabled']) ? 1 : 0,
            $data['auto_sync_interval'] ?? 'hourly',
            !empty($data['auto_create_missing']) ? 1 : 0,
            !empty($data['include_db_backup']) ? 1 : 0
        ]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Helper: Call GitHub API via cURL
function github_api_request($endpoint, $token, $method = 'GET', $postData = null) {
    $url = (str_starts_with($endpoint, 'http')) ? $endpoint : 'https://api.github.com/' . ltrim($endpoint, '/');
    $ch = curl_init();
    
    $headers = [
        'User-Agent: XAMPP-Dashboard-Sync/1.0',
        'Accept: application/vnd.github.v3+json',
        'Authorization: Bearer ' . trim($token)
    ];

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($postData) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($postData) ? $postData : json_encode($postData));
        }
    } elseif ($method === 'PATCH') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        if ($postData) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($postData) ? $postData : json_encode($postData));
        }
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        return ['success' => false, 'code' => 0, 'error' => 'cURL Error: ' . $curlErr];
    }

    $json = json_decode($response, true);
    $isSuccess = ($httpCode >= 200 && $httpCode < 300);

    $errorMsg = null;
    if (!$isSuccess) {
        if (!empty($json['errors']) && is_array($json['errors'])) {
            $errList = [];
            foreach ($json['errors'] as $err) {
                if (is_array($err)) {
                    $errList[] = $err['message'] ?? ($err['code'] ?? json_encode($err));
                } else {
                    $errList[] = (string)$err;
                }
            }
            $errorMsg = ($json['message'] ?? 'Error') . ' (' . implode(', ', $errList) . ')';
        } else {
            $errorMsg = $json['message'] ?? ('HTTP Code ' . $httpCode);
        }
    }

    return [
        'success' => $isSuccess,
        'code' => $httpCode,
        'data' => $json,
        'raw' => $response,
        'error' => $errorMsg
    ];
}

// Verify GitHub Personal Access Token
function verify_github_token($token) {
    if (empty(trim($token))) {
        return ['success' => false, 'error' => 'No GitHub token provided.'];
    }
    $res = github_api_request('/user', $token);
    if ($res['success'] && !empty($res['data']['login'])) {
        return [
            'success' => true,
            'user' => [
                'login' => $res['data']['login'],
                'name' => $res['data']['name'] ?? $res['data']['login'],
                'avatar_url' => $res['data']['avatar_url'] ?? '',
                'html_url' => $res['data']['html_url'] ?? '',
                'public_repos' => $res['data']['public_repos'] ?? 0,
                'total_private_repos' => $res['data']['total_private_repos'] ?? 0
            ]
        ];
    }
    return [
        'success' => false,
        'error' => $res['error'] ?? 'Invalid GitHub token or authentication failed.'
    ];
}

// Create Remote Repository on GitHub via API
function create_github_repo($token, $repoName, $description = '', $isPrivate = true) {
    if (empty(trim($token))) {
        return ['success' => false, 'error' => 'GitHub token is missing. Please save your Personal Access Token in GitHub Settings.'];
    }
    
    // Clean repo name: replace spaces and special characters with hyphens
    $cleanRepoName = preg_replace('/[^a-zA-Z0-9_\.-]/', '-', trim($repoName));
    $cleanRepoName = preg_replace('/-+/', '-', $cleanRepoName);
    $cleanRepoName = trim($cleanRepoName, '-');

    $payload = [
        'name' => $cleanRepoName,
        'description' => $description ?: "Automated sync backup for {$cleanRepoName}",
        'private' => (bool)$isPrivate,
        'auto_init' => false
    ];

    $res = github_api_request('/user/repos', $token, 'POST', $payload);
    if ($res['success'] && !empty($res['data']['clone_url'])) {
        return [
            'success' => true,
            'repo' => [
                'name' => $res['data']['name'],
                'full_name' => $res['data']['full_name'],
                'html_url' => $res['data']['html_url'],
                'clone_url' => $res['data']['clone_url'],
                'private' => $res['data']['private']
            ]
        ];
    }

    // If repository already exists on this user's account, automatically fetch existing repo
    if (!$res['success'] && $res['code'] === 422) {
        $userRes = github_api_request('/user', $token);
        if ($userRes['success'] && !empty($userRes['data']['login'])) {
            $owner = $userRes['data']['login'];
            $existingRepo = github_api_request("/repos/{$owner}/{$cleanRepoName}", $token);
            if ($existingRepo['success'] && !empty($existingRepo['data']['clone_url'])) {
                return [
                    'success' => true,
                    'already_existed' => true,
                    'repo' => [
                        'name' => $existingRepo['data']['name'],
                        'full_name' => $existingRepo['data']['full_name'],
                        'html_url' => $existingRepo['data']['html_url'],
                        'clone_url' => $existingRepo['data']['clone_url'],
                        'private' => $existingRepo['data']['private']
                    ]
                ];
            }
        }
    }

    $errorMsg = $res['error'] ?? 'Failed to create GitHub repository.';
    if (stripos($errorMsg, 'secondary rate limit') !== false) {
        $errorMsg = "GitHub API rate limit cooldown: GitHub is temporarily throttling new repository creation. Please wait 1-2 minutes, or create the repo at github.com/new and connect it using 'Link URL'.";
    }

    return [
        'success' => false,
        'error' => $errorMsg
    ];
}

// Get Registered Project Repo Configuration
function get_project_repo_record($projectName) {
    try {
        $authPdo = get_db_connection(AUTH_DB);
        $stmt = $authPdo->prepare("SELECT * FROM `github_repos` WHERE `project_name` = ? LIMIT 1");
        $stmt->execute([$projectName]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}

// Save or Update Project Repo Configuration
function save_project_repo_record($projectName, $repoName, $repoUrl, $branch = 'main', $includeDb = 1, $dbName = null, $isSyncEnabled = 1) {
    try {
        $authPdo = get_db_connection(AUTH_DB);
        $stmt = $authPdo->prepare("INSERT INTO `github_repos` 
            (`project_name`, `repo_name`, `repo_url`, `branch`, `include_db`, `db_name`, `is_sync_enabled`) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            `repo_name` = VALUES(`repo_name`),
            `repo_url` = VALUES(`repo_url`),
            `branch` = VALUES(`branch`),
            `include_db` = VALUES(`include_db`),
            `db_name` = VALUES(`db_name`),
            `is_sync_enabled` = VALUES(`is_sync_enabled`)");
        return $stmt->execute([$projectName, $repoName, $repoUrl, $branch, $includeDb ? 1 : 0, $dbName, $isSyncEnabled ? 1 : 0]);
    } catch (Exception $e) {
        return false;
    }
}

// Update Project Repo Sync Status
function update_project_sync_status($projectName, $status, $message = null) {
    try {
        $authPdo = get_db_connection(AUTH_DB);
        $stmt = $authPdo->prepare("UPDATE `github_repos` SET 
            `last_sync_status` = ?, 
            `last_sync_message` = ?, 
            `last_sync_at` = NOW() 
            WHERE `project_name` = ?");
        $stmt->execute([$status, $message, $projectName]);

        // If row didn't exist, auto-insert from local git config
        if ($stmt->rowCount() === 0) {
            $info = get_project_git_info($projectName);
            if (!empty($info['remote_url']) || !empty($info['display_remote_url'])) {
                $rawUrl = $info['display_remote_url'] ?: $info['remote_url'];
                $cleanName = $projectName;
                if (preg_match('#github\.com[:/]([^/]+)/([^/]+?)(?:\.git)?/?$#i', $rawUrl, $m)) {
                    $cleanName = $m[1] . '/' . $m[2];
                }
                save_project_repo_record($projectName, $cleanName, $rawUrl, $info['branch'] ?: 'main', 1, null, 1);
                $stmt->execute([$status, $message, $projectName]);
            }
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Toggle Project Auto-Sync Enable/Disable
function toggle_project_sync($projectName, $enabled) {
    try {
        $authPdo = get_db_connection(AUTH_DB);
        $record = get_project_repo_record($projectName);
        if (!$record) {
            $info = get_project_git_info($projectName);
            $rawUrl = $info['display_remote_url'] ?: $info['remote_url'];
            $cleanName = $projectName;
            if (preg_match('#github\.com[:/]([^/]+)/([^/]+?)(?:\.git)?/?$#i', $rawUrl, $m)) {
                $cleanName = $m[1] . '/' . $m[2];
            }
            save_project_repo_record($projectName, $cleanName, $rawUrl, $info['branch'] ?: 'main', 1, null, $enabled ? 1 : 0);
        } else {
            $stmt = $authPdo->prepare("UPDATE `github_repos` SET `is_sync_enabled` = ? WHERE `project_name` = ?");
            $stmt->execute([$enabled ? 1 : 0, $projectName]);
        }
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Delete Project Repo Record
function delete_project_repo_record($projectName) {
    try {
        $authPdo = get_db_connection(AUTH_DB);
        $stmt = $authPdo->prepare("DELETE FROM `github_repos` WHERE `project_name` = ?");
        return $stmt->execute([$projectName]);
    } catch (Exception $e) {
        return false;
    }
}

// Run Local Git Command within Project Directory
function run_git_cmd($projectDir, $command) {
    $safeDir = escapeshellarg($projectDir);
    // Execute with discovery across filesystem enabled
    $fullCmd = "export GIT_DISCOVERY_ACROSS_FILESYSTEM=1 && cd {$safeDir} && {$command} 2>&1";
    $output = [];
    $returnCode = 0;
    exec($fullCmd, $output, $returnCode);

    return [
        'code' => $returnCode,
        'output' => implode("\n", $output),
        'output_lines' => $output
    ];
}

// Inspect Git State for a Project Folder
function get_project_git_info($projectName) {
    $fullPath = WWW_ROOT . '/' . $projectName;
    $record = get_project_repo_record($projectName);

    $isDir = is_dir($fullPath);
    $isGit = $isDir && is_dir($fullPath . '/.git');

    $branch = 'main';
    $remoteUrl = '';
    $cleanRemoteUrl = '';
    $lastCommit = null;
    $hasUncommitted = false;
    $uncommittedCount = 0;

    if ($isGit) {
        // Current branch
        $branchRes = run_git_cmd($fullPath, "git branch --show-current");
        if ($branchRes['code'] === 0 && !empty(trim($branchRes['output']))) {
            $branch = trim($branchRes['output']);
        }

        // Remote URL
        $remoteRes = run_git_cmd($fullPath, "git remote get-url origin");
        if ($remoteRes['code'] === 0 && !empty(trim($remoteRes['output']))) {
            $remoteUrl = trim($remoteRes['output']);
            // Redact token from remoteUrl for safe display
            $cleanRemoteUrl = preg_replace('#https://[^@]+@github\.com/#', 'https://github.com/', $remoteUrl);
        }

        // Last Commit Info
        $logRes = run_git_cmd($fullPath, "git log -1 --format=\"%h|%s|%an|%ad\" --date=relative");
        if ($logRes['code'] === 0 && !empty(trim($logRes['output']))) {
            $parts = explode('|', trim($logRes['output']));
            if (count($parts) >= 4) {
                $lastCommit = [
                    'hash' => $parts[0],
                    'message' => $parts[1],
                    'author' => $parts[2],
                    'date' => $parts[3]
                ];
            }
        }

        // Uncommitted changes
        $statusRes = run_git_cmd($fullPath, "git status --porcelain");
        if ($statusRes['code'] === 0) {
            $lines = array_filter(explode("\n", trim($statusRes['output'])));
            $uncommittedCount = count($lines);
            $hasUncommitted = ($uncommittedCount > 0);
        }
    }

    return [
        'project_name' => $projectName,
        'full_path' => $fullPath,
        'is_dir' => $isDir,
        'is_git' => $isGit,
        'branch' => $branch,
        'remote_url' => $remoteUrl,
        'display_remote_url' => $cleanRemoteUrl ?: ($record['repo_url'] ?? ''),
        'last_commit' => $lastCommit,
        'has_uncommitted' => $hasUncommitted,
        'uncommitted_count' => $uncommittedCount,
        'record' => $record,
        'is_configured' => !empty($record['repo_url']) || !empty($remoteUrl)
    ];
}

// Generate Default .gitignore for Project
function ensure_project_gitignore($projectDir) {
    $gitignorePath = $projectDir . '/.gitignore';
    if (!file_exists($gitignorePath)) {
        $defaultContent = <<<EOT
# Standard Project .gitignore (Generated by XAMPP Dashboard)
node_modules/
.DS_Store
Thumbs.db
*.log
*.zip
*.tar.gz
*.rar
*.7z
.env.local
.vscode/
.idea/

# Preserve DB backups
!database_backup.sql
!db_backup.sql
EOT;
        @file_put_contents($gitignorePath, $defaultContent);
    }
}

// Format Remote URL with Token for Headless Git Auth
function build_authenticated_github_url($rawUrl, $token) {
    $clean = trim($rawUrl);
    // Convert SSH or plain https url to token auth url (supporting repos with dots in name)
    if (preg_match('#github\.com[:/]([^/]+)/([^/]+?)(?:\.git)?/?$#i', $clean, $m)) {
        $owner = $m[1];
        $repo = $m[2];
        if (!empty($token)) {
            return "https://oauth2:{$token}@github.com/{$owner}/{$repo}.git";
        } else {
            return "https://github.com/{$owner}/{$repo}.git";
        }
    }
    return $rawUrl;
}

// Initialize Local Repo and Link with GitHub Remote
function initialize_and_link_repo($projectName, $repoUrl, $branch = 'main', $includeDb = 1, $dbName = null) {
    $fullPath = WWW_ROOT . '/' . $projectName;
    if (!is_dir($fullPath)) {
        return ['success' => false, 'error' => "Project folder '{$projectName}' does not exist."];
    }

    $settings = get_github_settings();
    $token = $settings['github_token'] ?? '';
    $committerName = $settings['git_committer_name'] ?: 'XAMPP Dashboard';
    $committerEmail = $settings['git_committer_email'] ?: 'admin@workspace.local';

    // Ensure .gitignore
    ensure_project_gitignore($fullPath);

    // Init git if needed
    if (!is_dir($fullPath . '/.git')) {
        $initRes = run_git_cmd($fullPath, "git init -b " . escapeshellarg($branch));
        if ($initRes['code'] !== 0) {
            // Fallback for older git without -b
            $initRes = run_git_cmd($fullPath, "git init && git checkout -B " . escapeshellarg($branch));
        }
        if (!is_dir($fullPath . '/.git')) {
            return ['success' => false, 'error' => "Failed to initialize git repository in project directory: " . ($initRes['output'] ?: 'Permission denied or directory not writable')];
        }
    }

    // Set author
    run_git_cmd($fullPath, "git config user.name " . escapeshellarg($committerName));
    run_git_cmd($fullPath, "git config user.email " . escapeshellarg($committerEmail));

    // Authenticated remote URL
    $authRemoteUrl = build_authenticated_github_url($repoUrl, $token);
    
    // Set origin remote
    run_git_cmd($fullPath, "git remote remove origin");
    $addRemoteRes = run_git_cmd($fullPath, "git remote add origin " . escapeshellarg($authRemoteUrl));

    if ($addRemoteRes['code'] !== 0) {
        return ['success' => false, 'error' => 'Failed to configure git remote: ' . $addRemoteRes['output']];
    }

    // Determine clean repo name
    $cleanRepoName = $projectName;
    if (preg_match('#github\.com[:/]([^/]+)/([^/]+?)(?:\.git)?/?$#i', $repoUrl, $m)) {
        $cleanRepoName = $m[1] . '/' . $m[2];
    }

    // Save mapping in database
    save_project_repo_record($projectName, $cleanRepoName, $repoUrl, $branch, $includeDb, $dbName, 1);
    update_project_sync_status($projectName, 'idle', 'Repository linked successfully.');

    return ['success' => true, 'message' => "Project '{$projectName}' linked with GitHub repository."];
}

// Dump Database to SQL file inside Project
function backup_project_database($projectName, $dbName, $projectDir) {
    if (empty($dbName)) return ['success' => false, 'error' => 'No database name specified.'];
    
    $dumpFile = $projectDir . '/database_backup.sql';
    // Use mariadb-dump / mysqldump inside container (db host: db, root, no password)
    $cmd = "mysqldump --skip-ssl -h db -u root " . escapeshellarg($dbName) . " > " . escapeshellarg($dumpFile) . " 2>&1";
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);

    if ($code === 0 && file_exists($dumpFile) && filesize($dumpFile) > 0) {
        return ['success' => true, 'file' => 'database_backup.sql', 'size' => filesize($dumpFile)];
    }

    // Fallback: try mariadb-dump
    $cmd2 = "mariadb-dump --skip-ssl -h db -u root " . escapeshellarg($dbName) . " > " . escapeshellarg($dumpFile) . " 2>&1";
    $out2 = [];
    $code2 = 0;
    exec($cmd2, $out2, $code2);

    if ($code2 === 0 && file_exists($dumpFile) && filesize($dumpFile) > 0) {
        return ['success' => true, 'file' => 'database_backup.sql', 'size' => filesize($dumpFile)];
    }

    return ['success' => false, 'error' => implode("\n", array_merge($out, $out2)) ?: 'Dump failed or database empty.'];
}

// Sync (Commit + Push) Single Project to GitHub
function sync_project_to_github($projectName, $customCommitMsg = null) {
    $fullPath = WWW_ROOT . '/' . $projectName;
    if (!is_dir($fullPath)) {
        return ['success' => false, 'error' => "Project folder '{$projectName}' not found."];
    }

    $settings = get_github_settings();
    $token = $settings['github_token'] ?? '';
    $record = get_project_repo_record($projectName);

    $branch = $record['branch'] ?? 'main';
    $includeDb = !empty($record['include_db']);
    $dbName = $record['db_name'] ?? null;

    // Detect DB if not specified
    if (empty($dbName)) {
        // Check for common DB name matching project name or configs
        if (in_array($projectName, $GLOBALS['dbList'] ?? [])) {
            $dbName = $projectName;
        }
    }

    $logs = [];
    $logs[] = "[" . date('Y-m-d H:i:s') . "] Starting sync for '{$projectName}'...";

    // Ensure Git is initialized
    if (!is_dir($fullPath . '/.git')) {
        if (!empty($record['repo_url'])) {
            $initRes = initialize_and_link_repo($projectName, $record['repo_url'], $branch, $includeDb, $dbName);
            if (!$initRes['success']) {
                update_project_sync_status($projectName, 'error', $initRes['error']);
                return ['success' => false, 'error' => $initRes['error'], 'logs' => $logs];
            }
            $logs[] = "Initialized local git repository and configured remote.";
        } else {
            $err = "Project is not initialized with Git or linked to a GitHub repository.";
            update_project_sync_status($projectName, 'error', $err);
            return ['success' => false, 'error' => $err, 'logs' => $logs];
        }
    }

    // Refresh remote url with current token
    if (!empty($token) && !empty($record['repo_url'])) {
        $authUrl = build_authenticated_github_url($record['repo_url'], $token);
        run_git_cmd($fullPath, "git remote set-url origin " . escapeshellarg($authUrl));
    }

    // Database Dump if enabled
    if ($includeDb && !empty($dbName)) {
        $logs[] = "Dumping MySQL database '{$dbName}' to database_backup.sql...";
        $dbRes = backup_project_database($projectName, $dbName, $fullPath);
        if ($dbRes['success']) {
            $logs[] = "Database backup created (" . round($dbRes['size'] / 1024, 1) . " KB).";
        } else {
            $logs[] = "Database dump warning: " . $dbRes['error'];
        }
    }

    // Git Add
    $addRes = run_git_cmd($fullPath, "git add -A");
    if ($addRes['code'] !== 0) {
        $logs[] = "git add error: " . $addRes['output'];
    }

    // Git Commit
    $statusRes = run_git_cmd($fullPath, "git status --porcelain");
    $hasChanges = !empty(trim($statusRes['output']));

    if ($hasChanges) {
        $msg = $customCommitMsg ?: ("Auto-sync backup (" . date('Y-m-d H:i:s') . ")");
        $commitRes = run_git_cmd($fullPath, "git commit -m " . escapeshellarg($msg));
        $logs[] = "Committed changes: " . $msg;
    } else {
        $logs[] = "Working tree clean. No new local changes to commit.";
    }

    // Ensure branch name is set
    run_git_cmd($fullPath, "git branch -M " . escapeshellarg($branch));

    // Git Push
    $logs[] = "Pushing to GitHub remote origin/{$branch}...";
    $pushRes = run_git_cmd($fullPath, "git push -u origin " . escapeshellarg($branch));

    if ($pushRes['code'] === 0) {
        $logs[] = "Push successful: " . $pushRes['output'];
        $successMsg = "Synced successfully to GitHub at " . date('M d, Y H:i:s');
        update_project_sync_status($projectName, 'success', $successMsg);
        return [
            'success' => true,
            'message' => $successMsg,
            'logs' => $logs
        ];
    } else {
        // Redact any tokens from output logs
        $cleanErr = preg_replace('#https://[^@]+@github\.com/#', 'https://github.com/', $pushRes['output']);
        $logs[] = "Push failed: " . $cleanErr;
        update_project_sync_status($projectName, 'error', $cleanErr);
        return [
            'success' => false,
            'error' => "Push failed: " . $cleanErr,
            'logs' => $logs
        ];
    }
}

// Pull (Fetch + Merge) Single Project from GitHub
function pull_project_from_github($projectName) {
    $fullPath = WWW_ROOT . '/' . $projectName;
    if (!is_dir($fullPath) || !is_dir($fullPath . '/.git')) {
        return ['success' => false, 'error' => "Project folder '{$projectName}' is not a valid git repository."];
    }

    $settings = get_github_settings();
    $token = $settings['github_token'] ?? '';
    $record = get_project_repo_record($projectName);
    $branch = $record['branch'] ?? 'main';

    if (!empty($token) && !empty($record['repo_url'])) {
        $authUrl = build_authenticated_github_url($record['repo_url'], $token);
        run_git_cmd($fullPath, "git remote set-url origin " . escapeshellarg($authUrl));
    }

    $pullRes = run_git_cmd($fullPath, "git pull origin " . escapeshellarg($branch));
    $cleanOutput = preg_replace('#https://[^@]+@github\.com/#', 'https://github.com/', $pullRes['output']);

    if ($pullRes['code'] === 0) {
        return ['success' => true, 'output' => $cleanOutput];
    } else {
        return ['success' => false, 'error' => $cleanOutput];
    }
}

// Automatically create GitHub repositories for all unlinked projects
function auto_create_unlinked_projects($initialSync = true) {
    $settings = get_github_settings();
    $token = $settings['github_token'] ?? '';
    $visibility = $settings['default_visibility'] ?? 'private';
    $isPrivate = ($visibility === 'private');

    if (empty($token)) {
        return [
            'total' => 0,
            'created' => 0,
            'errors' => 1,
            'details' => [],
            'error' => 'GitHub Personal Access Token is missing. Please save your token in Settings.'
        ];
    }

    $allProjects = scandir(WWW_ROOT);
    $createdCount = 0;
    $errorCount = 0;
    $results = [];

    foreach ($allProjects as $item) {
        if (in_array($item, ['.', '..', 'dashboard', '.git', '.DS_Store'])) continue;
        $fullPath = WWW_ROOT . '/' . $item;
        if (!is_dir($fullPath)) continue;

        $info = get_project_git_info($item);
        if ($info['is_configured']) {
            continue; // Already linked to a GitHub repo
        }

        $logs = [];
        $logs[] = "[" . date('H:i:s') . "] Auto-creating repository for project '{$item}'...";

        // Sanitize repository name
        $repoName = preg_replace('/[^a-zA-Z0-9_\.-]/', '-', $item);
        $repoName = preg_replace('/-+/', '-', $repoName);
        $repoName = trim($repoName, '-');

        // Check if matching DB exists
        $dbName = null;
        if (in_array($item, $GLOBALS['dbList'] ?? [])) {
            $dbName = $item;
        }

        $createRes = create_github_repo($token, $repoName, "Automated backup for {$item}", $isPrivate);
        if (!$createRes['success']) {
            $errorCount++;
            $logs[] = "[!] GitHub creation failed: " . $createRes['error'];
            $results[$item] = ['success' => false, 'error' => $createRes['error'], 'logs' => $logs];
            continue;
        }

        $repoUrl = $createRes['repo']['clone_url'];
        $logs[] = "[✓] GitHub repository created: " . $createRes['repo']['html_url'];

        $linkRes = initialize_and_link_repo($item, $repoUrl, 'main', 1, $dbName);
        if (!$linkRes['success']) {
            $errorCount++;
            $logs[] = "[!] Link failed: " . $linkRes['error'];
            $results[$item] = ['success' => false, 'error' => $linkRes['error'], 'logs' => $logs];
            continue;
        }
        $logs[] = "[✓] Local Git configured and linked.";

        if ($initialSync) {
            $logs[] = "[" . date('H:i:s') . "] Pushing initial backup to GitHub...";
            $syncRes = sync_project_to_github($item, "Initial automated project backup");
            if (!empty($syncRes['logs'])) {
                foreach ($syncRes['logs'] as $l) {
                    $logs[] = "  " . $l;
                }
            }
            if ($syncRes['success']) {
                $createdCount++;
                $logs[] = "[✓] Initial commit and push completed successfully!";
                $results[$item] = ['success' => true, 'message' => "Created and backed up.", 'logs' => $logs, 'repo_url' => $createRes['repo']['html_url']];
            } else {
                $errorCount++;
                $logs[] = "[!] Push error: " . $syncRes['error'];
                $results[$item] = ['success' => false, 'error' => $syncRes['error'], 'logs' => $logs, 'repo_url' => $createRes['repo']['html_url']];
            }
        } else {
            $createdCount++;
            $logs[] = "[✓] Linked successfully.";
            $results[$item] = ['success' => true, 'message' => "Created and linked.", 'logs' => $logs, 'repo_url' => $createRes['repo']['html_url']];
        }
    }

    return [
        'total' => $createdCount + $errorCount,
        'created' => $createdCount,
        'errors' => $errorCount,
        'details' => $results
    ];
}

// Sync All Projects Configured for Sync
function sync_all_projects_to_github($force = false) {
    $settings = get_github_settings();
    $results = [];

    // If auto_create_missing is enabled, first create any missing repos
    if (!empty($settings['auto_create_missing'])) {
        $autoCreateRes = auto_create_unlinked_projects(true);
        if (!empty($autoCreateRes['details'])) {
            foreach ($autoCreateRes['details'] as $pName => $pRes) {
                $results[$pName] = $pRes;
            }
        }
    }

    $allProjects = scandir(WWW_ROOT);
    $syncedCount = 0;
    $errorCount = 0;
    $skippedCount = 0;

    foreach ($allProjects as $item) {
        if (in_array($item, ['.', '..', 'dashboard', '.git', '.DS_Store'])) continue;
        if (isset($results[$item])) continue; // Already handled by auto-create
        $fullPath = WWW_ROOT . '/' . $item;
        if (!is_dir($fullPath)) continue;

        $record = get_project_repo_record($item);
        
        // If explicitly disabled for this project and not forced, skip
        if ($record && empty($record['is_sync_enabled']) && !$force) {
            $skippedCount++;
            continue;
        }

        // If repo record exists or local .git exists
        if (($record && !empty($record['repo_url'])) || is_dir($fullPath . '/.git')) {
            $syncRes = sync_project_to_github($item, "Bulk auto-sync backup: " . date('Y-m-d H:i:s'));
            $results[$item] = $syncRes;
            if ($syncRes['success']) {
                $syncedCount++;
            } else {
                $errorCount++;
            }
        }
    }

    try {
        $authPdo = get_db_connection(AUTH_DB);
        $authPdo->exec("UPDATE `github_settings` SET `last_global_sync` = NOW() WHERE `id` = 1");
    } catch (Exception $e) {}

    return [
        'total' => count($results),
        'synced' => $syncedCount,
        'errors' => $errorCount,
        'skipped' => $skippedCount,
        'details' => $results
    ];
}
