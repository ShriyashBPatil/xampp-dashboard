<?php
require_once __DIR__ . '/includes/core.php';
require_once __DIR__ . '/includes/ftp.php';
require_login();

if (!has_permission('can_ftp_explorer') && !has_permission('can_ftp_deploy')) {
    require_permission('can_ftp_explorer', 'Access denied. You do not have permission to access FTP Deploy or Remote Explorer.');
}

$pageTitle = 'Shared Hosting FTP Transfer & Remote File Explorer';
$activeNav = 'ftp';
$isGuest = is_guest();

// Handle GET Download of Remote File
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'download_remote_file') {
    $profileId = (int)($_GET['profile_id'] ?? 0);
    $filePath = trim($_GET['file_path'] ?? '');
    
    if ($profileId > 0 && !empty($filePath)) {
        $profile = get_ftp_profile($profileId);
        if ($profile) {
            $cleanPath = ltrim($filePath, '/');
            $url = build_ftp_url($profile, $cleanPath);

            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($cleanPath) . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            setup_ftp_curl_handle($ch, $profile);
            curl_exec($ch);
            curl_close($ch);
            exit;
        }
    }
    header('Location: /dashboard/ftp.php');
    exit;
}

// Handle AJAX Endpoints
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    // Helper to resolve profile config
    $resolveConfig = function() {
        $profileId = !empty($_POST['profile_id']) ? (int)$_POST['profile_id'] : 0;
        if ($profileId > 0) {
            $prof = get_ftp_profile($profileId);
            if ($prof) return $prof;
        }
        return [
            'protocol' => $_POST['protocol'] ?? 'ftp',
            'host' => trim($_POST['host'] ?? ''),
            'port' => !empty($_POST['port']) ? (int)$_POST['port'] : 21,
            'username' => trim($_POST['username'] ?? ''),
            'password' => $_POST['password'] ?? '',
            'remote_path' => trim($_POST['remote_path'] ?? '/'),
            'passive_mode' => !empty($_POST['passive_mode']) ? 1 : 0,
            'ssl_verify' => !empty($_POST['ssl_verify']) ? 1 : 0,
        ];
    };

    // AJAX: Test Connection
    if ($action === 'ajax_test_connection') {
        $config = $resolveConfig();
        $res = test_ftp_connection($config);
        echo json_encode($res);
        exit;
    }

    // AJAX: Remote Directory Listing
    if ($action === 'ajax_browse_remote') {
        $config = $resolveConfig();
        $path = trim($_POST['path'] ?? '/');
        $res = get_ftp_directory_listing($config, $path);
        echo json_encode($res);
        exit;
    }

    // AJAX: Preview Remote File
    if ($action === 'ajax_preview_remote_file') {
        $config = $resolveConfig();
        $filePath = trim($_POST['file_path'] ?? '');
        if (empty($filePath)) {
            echo json_encode(['success' => false, 'error' => 'File path is required.']);
            exit;
        }
        $res = read_ftp_file_content($config, $filePath);
        echo json_encode($res);
        exit;
    }

    // AJAX: Create Remote Folder
    if ($action === 'ajax_create_remote_folder') {
        if ($isGuest) {
            echo json_encode(['success' => false, 'error' => 'Creating remote folders is disabled in Guest Mode.']);
            exit;
        }
        $config = $resolveConfig();
        $parentPath = trim($_POST['parent_path'] ?? '/');
        $folderName = trim($_POST['folder_name'] ?? '');
        if (empty($folderName) || !preg_match('/^[a-zA-Z0-9_\-\.\s]+$/', $folderName)) {
            echo json_encode(['success' => false, 'error' => 'Invalid folder name.']);
            exit;
        }
        $res = create_ftp_remote_folder($config, $parentPath, $folderName);
        echo json_encode($res);
        exit;
    }

    // AJAX: Delete Remote Item
    if ($action === 'ajax_delete_remote_item') {
        if ($isGuest) {
            echo json_encode(['success' => false, 'error' => 'Deleting remote files is disabled in Guest Mode.']);
            exit;
        }
        $config = $resolveConfig();
        $targetPath = trim($_POST['target_path'] ?? '');
        $isDir = !empty($_POST['is_dir']);
        if (empty($targetPath) || $targetPath === '/' || $targetPath === '/public_html') {
            echo json_encode(['success' => false, 'error' => 'Cannot delete root directory.']);
            exit;
        }
        $res = delete_ftp_remote_item($config, $targetPath, $isDir);
        echo json_encode($res);
        exit;
    }

    // AJAX: Upload Direct File to Remote Folder
    if ($action === 'ajax_upload_direct_file') {
        if ($isGuest) {
            echo json_encode(['success' => false, 'error' => 'Uploads are disabled in Guest Mode.']);
            exit;
        }
        $config = $resolveConfig();
        $remoteDir = trim($_POST['remote_dir'] ?? '/');

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => 'Please select a file to upload.']);
            exit;
        }

        $tmpFile = $_FILES['file']['tmp_name'];
        $origName = $_FILES['file']['name'];
        $targetRemotePath = rtrim($remoteDir, '/') . '/' . ltrim($origName, '/');

        $config['remote_path'] = rtrim($remoteDir, '/');
        $res = upload_single_file_ftp($tmpFile, $origName, $config);
        echo json_encode($res);
        exit;
    }

    // AJAX: Save Profile
    if ($action === 'ajax_save_profile') {
        if ($isGuest) {
            echo json_encode(['success' => false, 'error' => 'Editing profiles is disabled in Guest Mode.']);
            exit;
        }
        $res = save_ftp_profile($_POST);
        echo json_encode($res);
        exit;
    }

    // AJAX: Delete Profile
    if ($action === 'ajax_delete_profile') {
        if ($isGuest) {
            echo json_encode(['success' => false, 'error' => 'Deleting profiles is disabled in Guest Mode.']);
            exit;
        }
        $id = (int)($_POST['profile_id'] ?? 0);
        $res = delete_ftp_profile($id);
        echo json_encode($res);
        exit;
    }

    // AJAX: Get Profile details
    if ($action === 'ajax_get_profile') {
        $id = (int)($_POST['profile_id'] ?? 0);
        $profile = get_ftp_profile($id);
        if ($profile) {
            $profile['has_password'] = !empty($profile['password']);
            unset($profile['password']);
            echo json_encode(['success' => true, 'profile' => $profile]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Profile not found']);
        }
        exit;
    }

    // AJAX: Scan Project for Transfer
    if ($action === 'ajax_scan_project') {
        $project = trim($_POST['project'] ?? '');
        if (empty($project)) {
            echo json_encode(['success' => false, 'error' => 'Project name is required.']);
            exit;
        }

        $options = [
            'exclude_git' => !empty($_POST['exclude_git']),
            'exclude_node_modules' => !empty($_POST['exclude_node_modules']),
            'exclude_vendor' => !empty($_POST['exclude_vendor']),
            'exclude_ide' => !empty($_POST['exclude_ide']),
        ];

        $scan = scan_project_files_for_ftp($project, $options);
        echo json_encode($scan);
        exit;
    }

    // AJAX: Export Associated Database Dump
    if ($action === 'ajax_export_db_for_transfer') {
        if ($isGuest) {
            echo json_encode(['success' => false, 'error' => 'Action disabled in Guest Mode.']);
            exit;
        }
        $project = trim($_POST['project'] ?? '');
        $dbName = trim($_POST['db_name'] ?? '');

        if (empty($dbName)) {
            echo json_encode(['success' => false, 'error' => 'Database name is required.']);
            exit;
        }

        $tmpDir = sys_get_temp_dir() . '/ftp_db_exports';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0777, true);

        $dumpFile = $tmpDir . '/' . $project . '_db_backup_' . date('Ymd_His') . '.sql';
        $cmd = "mysqldump --skip-ssl -h db -u root " . escapeshellarg($dbName) . " > " . escapeshellarg($dumpFile) . " 2>&1";
        $out = [];
        $ret = 0;
        @exec($cmd, $out, $ret);

        if ($ret === 0 && file_exists($dumpFile) && filesize($dumpFile) > 0) {
            echo json_encode([
                'success' => true,
                'file_path' => $dumpFile,
                'file_name' => basename($dumpFile),
                'size' => filesize($dumpFile),
                'formatted_size' => format_bytes(filesize($dumpFile))
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to export database: ' . implode(' ', $out)]);
        }
        exit;
    }

    // AJAX: Transfer Single File
    if ($action === 'ajax_upload_single_file') {
        if ($isGuest) {
            echo json_encode(['success' => false, 'error' => 'Deployments are disabled in Guest Mode.']);
            exit;
        }

        $config = $resolveConfig();
        $localPath = trim($_POST['local_path'] ?? '');
        $remoteRelPath = trim($_POST['remote_rel_path'] ?? '');
        $customRemoteBase = trim($_POST['custom_remote_base'] ?? '');

        if (!empty($customRemoteBase)) {
            $config['remote_path'] = $customRemoteBase;
        }

        if (empty($localPath) || empty($remoteRelPath)) {
            echo json_encode(['success' => false, 'error' => 'Missing local or remote path.']);
            exit;
        }

        $realLocal = realpath($localPath);
        $tmpDir = realpath(sys_get_temp_dir());
        $wwwRoot = realpath(WWW_ROOT);

        if (!$realLocal || (strpos($realLocal, $wwwRoot) !== 0 && strpos($realLocal, $tmpDir) !== 0)) {
            echo json_encode(['success' => false, 'error' => 'Access denied to local path.']);
            exit;
        }

        $res = upload_single_file_ftp($realLocal, $remoteRelPath, $config);
        echo json_encode($res);
        exit;
    }

    // AJAX: Log Finished Transfer
    if ($action === 'ajax_log_transfer') {
        $profileId = !empty($_POST['profile_id']) ? (int)$_POST['profile_id'] : null;
        $projectName = trim($_POST['project'] ?? '');
        $remotePath = trim($_POST['remote_path'] ?? '');
        $filesCount = (int)($_POST['files_count'] ?? 0);
        $totalBytes = (int)($_POST['total_bytes'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['success', 'partial', 'failed']) ? $_POST['status'] : 'success';
        $message = trim($_POST['message'] ?? '');

        log_ftp_transfer($profileId, $projectName, $remotePath, $filesCount, $totalBytes, $status, $message);
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    exit;
}

$profiles = get_ftp_profiles();
$transferLogs = get_ftp_transfer_logs(15);
$selectedProjectParam = trim($_GET['project'] ?? '');
$activeTab = trim($_GET['tab'] ?? 'transfer');
if (!in_array($activeTab, ['transfer', 'explorer'])) {
    $activeTab = 'transfer';
}

include __DIR__ . '/includes/header.php';
?>

<!-- Top Header with Actions -->
<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <div class="flex items-center gap-2">
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Shared Hosting FTP Manager</h1>
            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200">
                FTP &bull; FTPS &bull; SFTP
            </span>
        </div>
        <p class="text-sm text-gray-500 mt-1">
            Deploy projects to shared hosting and live-browse the remote server filesystem in real time.
        </p>
    </div>
    <div class="flex items-center gap-2 flex-wrap">
        <button onclick="openNewProfileModal()" class="inline-flex items-center gap-2 px-3.5 py-2 bg-white border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 hover:border-gray-400 transition-colors shadow-sm">
            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
            <span>Add Hosting Profile</span>
        </button>
        <a href="/dashboard/projects.php" class="inline-flex items-center gap-2 px-3.5 py-2 bg-white border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 transition shadow-sm">
            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
            <span>View Projects</span>
        </a>
    </div>
</div>

<!-- Primary Tab Navigation (Deploy Projects vs Remote File Explorer) -->
<div class="flex items-center gap-2 mb-6 border-b border-gray-200">
    <button type="button" onclick="switchMainTab('transfer')" id="tabBtnTransfer" class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-bold border-b-2 <?= $activeTab === 'transfer' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?> transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
        <span>Deploy & Transfer Projects</span>
    </button>
    <button type="button" onclick="switchMainTab('explorer')" id="tabBtnExplorer" class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-bold border-b-2 <?= $activeTab === 'explorer' ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700' ?> transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/></svg>
        <span>Remote Server File Explorer</span>
        <span class="text-[10px] px-1.5 py-0.2 bg-indigo-100 text-indigo-700 rounded-full font-mono font-semibold">Live</span>
    </button>
</div>

<!-- ================= TAB 1: TRANSFER & DEPLOY ================= -->
<div id="tabContentTransfer" class="<?= $activeTab === 'transfer' ? '' : 'hidden' ?>">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <!-- Left 2 Cols: Transfer Launchpad -->
        <div class="lg:col-span-2 bg-white border border-gray-200 rounded-xl p-6 shadow-sm flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between pb-4 mb-5 border-b border-gray-100">
                    <div class="flex items-center gap-2.5">
                        <div class="w-9 h-9 rounded-lg bg-indigo-50 border border-indigo-100 flex items-center justify-center text-indigo-600">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                        </div>
                        <div>
                            <h2 class="text-base font-bold text-gray-900">Transfer Project to Server</h2>
                            <p class="text-xs text-gray-500">Select local project folder and destination shared hosting server</p>
                        </div>
                    </div>
                    <span class="text-xs font-mono bg-gray-100 text-gray-600 px-2 py-1 rounded">cURL Engine</span>
                </div>

                <form id="transferLauncherForm" onsubmit="handleStartTransfer(event)">
                    <div class="space-y-4">
                        <!-- 1. Local Project Selection -->
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-1.5">
                                1. Select Local Project or Script
                            </label>
                            <select id="transferProjectSelect" class="w-full text-sm bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500" onchange="onProjectSelectChanged()">
                                <option value="">-- Choose a Project Directory or Standalone Script --</option>
                                <optgroup label="Project Folders">
                                    <?php foreach ($allProjects as $p): ?>
                                        <option value="<?= htmlspecialchars($p['name']) ?>" <?= $selectedProjectParam === $p['name'] ? 'selected' : '' ?>>
                                            📁 <?= htmlspecialchars($p['name']) ?> (<?= $p['entry'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php if (!empty($standaloneScripts)): ?>
                                    <optgroup label="Standalone Single Scripts">
                                        <?php foreach ($standaloneScripts as $s): ?>
                                            <option value="<?= htmlspecialchars($s['name']) ?>" <?= $selectedProjectParam === $s['name'] ? 'selected' : '' ?>>
                                                📄 <?= htmlspecialchars($s['name']) ?> (<?= $s['size'] ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                            </select>
                            <div id="projectScanPreview" class="hidden mt-2 p-2.5 bg-gray-50 border border-gray-100 rounded-lg text-xs text-gray-600 flex items-center justify-between">
                                <span id="scanPreviewFiles">0 files detected</span>
                                <span id="scanPreviewSize" class="font-mono font-bold text-gray-800">0 KB</span>
                            </div>
                        </div>

                        <!-- 2. Target Hosting Server Profile -->
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <label class="block text-xs font-bold uppercase tracking-wider text-gray-700">
                                    2. Destination Hosting Server
                                </label>
                                <button type="button" onclick="toggleCustomCredentials()" class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">
                                    <span id="toggleCredsText">Enter Custom / Temporary FTP Details &rarr;</span>
                                </button>
                            </div>

                            <div id="profileSelectWrapper">
                                <?php if (empty($profiles)): ?>
                                    <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-800 flex items-center justify-between">
                                        <span>No saved hosting profiles found. Add a profile for fast 1-click deployments.</span>
                                        <button type="button" onclick="openNewProfileModal()" class="px-2.5 py-1 bg-amber-600 text-white rounded text-xs font-medium hover:bg-amber-700">Add Profile</button>
                                    </div>
                                <?php else: ?>
                                    <select id="transferProfileSelect" class="w-full text-sm bg-gray-50 border border-gray-200 rounded-lg px-3 py-2.5 focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500" onchange="onProfileSelectChanged()">
                                        <?php foreach ($profiles as $prof): ?>
                                            <option value="<?= $prof['id'] ?>" data-host="<?= htmlspecialchars($prof['host']) ?>" data-remote="<?= htmlspecialchars($prof['remote_path']) ?>" data-provider="<?= htmlspecialchars($prof['provider']) ?>" <?= !empty($prof['is_default']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($prof['profile_name']) ?> &bull; <?= strtoupper($prof['protocol']) ?>://<?= htmlspecialchars($prof['host']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php endif; ?>
                            </div>

                            <!-- Manual FTP Credentials Inputs (Collapsible) -->
                            <div id="customCredentialsSection" class="<?= empty($profiles) ? '' : 'hidden' ?> mt-3 p-4 bg-gray-50 border border-gray-200 rounded-xl space-y-3">
                                <div class="text-xs font-bold text-gray-800 border-b border-gray-200 pb-1.5 flex items-center justify-between">
                                    <span>Custom FTP / FTPS Credentials</span>
                                    <span class="text-[10px] text-gray-500 font-normal">Not saved permanently</span>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                                    <div>
                                        <label class="block text-[11px] font-semibold text-gray-600 mb-1">Protocol</label>
                                        <select id="customProtocol" class="w-full text-xs bg-white border border-gray-200 rounded-lg p-2">
                                            <option value="ftp">FTP (Port 21)</option>
                                            <option value="ftps">FTPS - SSL/TLS (Port 21/990)</option>
                                            <option value="sftp">SFTP - SSH (Port 22)</option>
                                        </select>
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="block text-[11px] font-semibold text-gray-600 mb-1">FTP Host / Server</label>
                                        <input type="text" id="customHost" placeholder="e.g. shriyashpatil.in or IP" class="w-full text-xs bg-white border border-gray-200 rounded-lg p-2 font-mono">
                                    </div>
                                </div>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                                    <div>
                                        <label class="block text-[11px] font-semibold text-gray-600 mb-1">Port</label>
                                        <input type="number" id="customPort" value="21" class="w-full text-xs bg-white border border-gray-200 rounded-lg p-2 font-mono">
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-semibold text-gray-600 mb-1">FTP Username</label>
                                        <input type="text" id="customUsername" placeholder="dashboard_shriyashpatil.in" class="w-full text-xs bg-white border border-gray-200 rounded-lg p-2 font-mono">
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-semibold text-gray-600 mb-1">FTP Password</label>
                                        <input type="password" id="customPassword" placeholder="••••••••" class="w-full text-xs bg-white border border-gray-200 rounded-lg p-2">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 3. Remote Path Configuration & Presets -->
                        <div>
                            <label class="block text-xs font-bold uppercase tracking-wider text-gray-700 mb-1.5">
                                3. Destination Folder on Remote Server
                            </label>
                            <div class="flex items-center gap-2">
                                <input type="text" id="targetRemotePathInput" value="/" class="flex-1 text-sm bg-gray-50 border border-gray-200 rounded-lg px-3 py-2 font-mono focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
                            </div>
                            <div class="flex items-center gap-1.5 mt-2 flex-wrap">
                                <span class="text-[11px] text-gray-400 font-medium mr-1">Quick Path Presets:</span>
                                <button type="button" onclick="setRemotePath('/' + getSelectedProjectName())" class="text-[11px] px-2 py-0.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-semibold rounded transition font-mono">
                                    /[project-name]
                                </button>
                                <button type="button" onclick="setRemotePath('/')" class="text-[11px] px-2 py-0.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 font-semibold rounded transition font-mono">
                                    / (Root)
                                </button>
                                <button type="button" onclick="setRemotePath('/public_html/' + getSelectedProjectName())" class="text-[11px] px-2 py-0.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded transition font-mono">
                                    /public_html/[project-name]
                                </button>
                                <button type="button" onclick="setRemotePath('/public_html/')" class="text-[11px] px-2 py-0.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded transition font-mono">
                                    /public_html/
                                </button>
                            </div>
                        </div>

                        <!-- 4. Transfer Options Checklist -->
                        <div class="bg-gray-50 border border-gray-200 rounded-xl p-3.5 space-y-2">
                            <div class="text-xs font-bold text-gray-800 mb-1">Transfer & Optimization Options</div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs text-gray-700">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" id="optExcludeGit" checked class="rounded text-indigo-600 focus:ring-indigo-500">
                                    <span>Exclude <code class="text-gray-600 bg-gray-200 px-1 rounded text-[10px]">.git</code> / IDE files</span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" id="optExcludeNodeModules" checked class="rounded text-indigo-600 focus:ring-indigo-500">
                                    <span>Exclude <code class="text-gray-600 bg-gray-200 px-1 rounded text-[10px]">node_modules</code></span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" id="optIncludeDb" class="rounded text-indigo-600 focus:ring-indigo-500" onchange="toggleDbExportOption(this.checked)">
                                    <span>Export & Upload Database <code class="text-gray-600 bg-gray-200 px-1 rounded text-[10px]">.sql</code></span>
                                </label>
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" id="optPassiveMode" checked class="rounded text-indigo-600 focus:ring-indigo-500">
                                    <span>Use Passive Mode (PASV)</span>
                                </label>
                            </div>
                            <div id="dbSelectRow" class="hidden pt-2 border-t border-gray-200 flex items-center gap-2 text-xs">
                                <span class="text-gray-600">Database to export:</span>
                                <select id="exportDbSelect" class="text-xs bg-white border border-gray-200 rounded p-1">
                                    <?php foreach ($dbList as $db): ?>
                                        <option value="<?= htmlspecialchars($db) ?>"><?= htmlspecialchars($db) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Action Footer -->
                    <div class="mt-6 pt-4 border-t border-gray-100 flex items-center justify-between gap-3">
                        <button type="button" onclick="handleTestConnection()" class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-xs font-semibold transition" id="btnTestConn">
                            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            <span>Test Connection</span>
                        </button>
                        
                        <button type="submit" class="inline-flex items-center gap-2 px-5 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-sm font-semibold transition shadow-sm" id="btnStartTransfer">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                            <span>Start FTP Transfer</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Right Col: Saved Profiles & Quick Info -->
        <div class="space-y-6">
            <!-- Saved Profiles Card -->
            <div class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-sm font-bold text-gray-900 flex items-center gap-2">
                        <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/></svg>
                        Saved Hosting Profiles (<?= count($profiles) ?>)
                    </h3>
                    <button onclick="openNewProfileModal()" class="text-xs text-indigo-600 hover:text-indigo-800 font-semibold">+ Add</button>
                </div>

                <?php if (empty($profiles)): ?>
                    <div class="text-center py-6 text-xs text-gray-400">
                        No hosting profiles saved yet. Click "+ Add" to save your FTP details.
                    </div>
                <?php else: ?>
                    <div class="space-y-2.5 max-h-72 overflow-y-auto pr-1" style="scrollbar-width: thin;">
                        <?php foreach ($profiles as $prof): ?>
                            <div class="p-3 bg-gray-50 hover:bg-gray-100/80 border border-gray-200 rounded-lg transition flex items-center justify-between gap-2">
                                <div class="min-w-0">
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-xs font-bold text-gray-900 truncate"><?= htmlspecialchars($prof['profile_name']) ?></span>
                                        <?php if (!empty($prof['is_default'])): ?>
                                            <span class="text-[9px] uppercase font-bold bg-indigo-100 text-indigo-700 px-1.5 py-0.2 rounded font-mono">Default</span>
                                        <?php endif; ?>
                                    </div>
                                    <span class="text-[11px] text-gray-500 font-mono block truncate"><?= htmlspecialchars($prof['host']) ?></span>
                                </div>
                                <div class="flex items-center gap-1 flex-shrink-0">
                                    <button type="button" onclick="browseRemoteProfile(<?= $prof['id'] ?>)" class="p-1 text-gray-500 hover:text-indigo-600 rounded transition" title="Browse Remote Filesystem">
                                        <svg class="w-3.5 h-3.5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/></svg>
                                    </button>
                                    <button type="button" onclick="testSavedProfile(<?= $prof['id'] ?>)" class="p-1 text-gray-400 hover:text-indigo-600 rounded transition" title="Test Connection">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                    </button>
                                    <button type="button" onclick="editProfile(<?= $prof['id'] ?>)" class="p-1 text-gray-400 hover:text-gray-700 rounded transition" title="Edit Profile">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    </button>
                                    <button type="button" onclick="deleteProfile(<?= $prof['id'] ?>, '<?= htmlspecialchars(addslashes($prof['profile_name'])) ?>')" class="p-1 text-gray-400 hover:text-red-600 rounded transition" title="Delete Profile">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Shared Hosting Setup Tips -->
            <div class="bg-gradient-to-br from-indigo-50/70 to-blue-50/50 border border-indigo-100 rounded-xl p-5">
                <h4 class="text-xs font-bold text-indigo-950 uppercase tracking-wider mb-2 flex items-center gap-1.5">
                    <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Remote File Access
                </h4>
                <p class="text-xs text-indigo-900/80 leading-relaxed mb-3">
                    Switch to the <strong>Remote Server File Explorer</strong> tab above to view, preview code, download files, and manage folders on your live shared hosting server without needing external FTP software.
                </p>
                <button type="button" onclick="switchMainTab('explorer')" class="inline-flex items-center gap-1.5 text-xs font-bold text-indigo-700 hover:text-indigo-900">
                    <span>Open Remote File Explorer &rarr;</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Transfer History & Logs Table -->
    <div class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm mb-8">
        <h3 class="text-base font-bold text-gray-900 mb-4 flex items-center gap-2">
            <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            Recent Transfer History & Logs
        </h3>

        <?php if (empty($transferLogs)): ?>
            <div class="text-center py-8 text-xs text-gray-400">
                No FTP transfer records yet. Deploy a project to view execution history.
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs text-gray-600">
                    <thead class="bg-gray-50 text-gray-500 font-semibold border-b border-gray-200">
                        <tr>
                            <th class="p-3">Project</th>
                            <th class="p-3">Target Host / Server</th>
                            <th class="p-3">Remote Path</th>
                            <th class="p-3">Files / Size</th>
                            <th class="p-3">Status</th>
                            <th class="p-3">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($transferLogs as $log): ?>
                            <tr class="hover:bg-gray-50/80 transition">
                                <td class="p-3 font-semibold text-gray-900 font-mono">
                                    📁 <?= htmlspecialchars($log['project_name']) ?>
                                </td>
                                <td class="p-3 font-mono text-gray-700">
                                    <?= htmlspecialchars($log['host'] ?? ($log['profile_name'] ?? 'Custom Host')) ?>
                                </td>
                                <td class="p-3 font-mono text-gray-500 truncate max-w-xs">
                                    <?= htmlspecialchars($log['remote_path']) ?>
                                </td>
                                <td class="p-3 font-mono">
                                    <?= $log['files_count'] ?> files &bull; <?= format_bytes($log['total_bytes']) ?>
                                </td>
                                <td class="p-3">
                                    <?php if ($log['status'] === 'success'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200">Success</span>
                                    <?php elseif ($log['status'] === 'partial'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-amber-50 text-amber-700 border border-amber-200">Partial</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold bg-red-50 text-red-700 border border-red-200">Failed</span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3 text-gray-400 whitespace-nowrap">
                                    <?= date('M d, H:i', strtotime($log['transferred_at'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- ================= TAB 2: REMOTE SERVER FILE EXPLORER ================= -->
<div id="tabContentExplorer" class="<?= $activeTab === 'explorer' ? '' : 'hidden' ?>">
    <!-- Remote Server Selector & Actions Header -->
    <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm mb-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div class="flex items-center gap-3 flex-1 min-w-0">
            <div class="w-9 h-9 rounded-lg bg-indigo-50 border border-indigo-100 flex items-center justify-center text-indigo-600 flex-shrink-0">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/></svg>
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2">
                    <span class="text-xs font-bold uppercase tracking-wider text-gray-500">Connected Server:</span>
                    <select id="explorerProfileSelect" class="text-xs font-semibold bg-gray-50 border border-gray-200 rounded-lg px-2.5 py-1 text-gray-900 focus:bg-white focus:ring-2 focus:ring-indigo-500" onchange="loadRemoteDirectory('/')">
                        <?php foreach ($profiles as $prof): ?>
                            <option value="<?= $prof['id'] ?>" <?= !empty($prof['is_default']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($prof['profile_name']) ?> (<?= htmlspecialchars($prof['host']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2 flex-wrap">
            <button type="button" onclick="openNewRemoteFolderModal()" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-gray-300 rounded-lg text-xs font-medium text-gray-700 hover:bg-gray-50 transition shadow-sm">
                <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                <span>New Folder</span>
            </button>
            <button type="button" onclick="openUploadDirectModal()" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-600 rounded-lg text-xs font-medium text-white hover:bg-indigo-700 transition shadow-sm">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                <span>Upload File</span>
            </button>
            <button type="button" onclick="refreshRemoteDirectory()" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-xs font-medium transition" title="Refresh Directory">
                <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                <span>Refresh</span>
            </button>
        </div>
    </div>

    <!-- Breadcrumbs Navigation -->
    <div class="bg-white border border-gray-200 rounded-xl p-3 shadow-sm mb-4 flex items-center justify-between gap-3">
        <div id="remoteBreadcrumbBar" class="flex items-center gap-1.5 text-xs font-mono text-gray-600 overflow-x-auto">
            <span class="text-indigo-600 font-bold cursor-pointer hover:underline" onclick="loadRemoteDirectory('/')">/ (root)</span>
        </div>
        <div id="remoteExplorerStats" class="text-xs text-gray-400 font-mono flex-shrink-0">
            0 items
        </div>
    </div>

    <!-- Remote File Table Card -->
    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm mb-8">
        <!-- Loading State -->
        <div id="remoteExplorerLoading" class="p-12 text-center text-gray-500 text-sm hidden">
            <svg class="w-6 h-6 animate-spin mx-auto text-indigo-600 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
            <span>Connecting and fetching remote directory listing...</span>
        </div>

        <!-- Error State -->
        <div id="remoteExplorerError" class="p-8 text-center text-rose-600 text-xs hidden">
            <svg class="w-8 h-8 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            <p id="remoteExplorerErrorMsg">Failed to load remote directory.</p>
        </div>

        <!-- File Table -->
        <div id="remoteExplorerTableWrapper" class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-gray-50 text-gray-500 font-semibold border-b border-gray-200">
                    <tr>
                        <th class="p-3">Name</th>
                        <th class="p-3 w-28">Size</th>
                        <th class="p-3 w-28">Permissions</th>
                        <th class="p-3 w-36">Last Modified</th>
                        <th class="p-3 text-right w-36">Actions</th>
                    </tr>
                </thead>
                <tbody id="remoteExplorerTableBody" class="divide-y divide-gray-100">
                    <!-- Dynamic Items populated via JS -->
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ================= MODALS ================= -->

<!-- 1. LIVE FTP TRANSFER PROGRESS MODAL -->
<div id="ftpTransferProgressModal" class="modal-backdrop">
    <div class="modal-dialog" style="max-width: 650px;">
        <div class="modal-header">
            <div class="modal-title flex items-center gap-2">
                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                <span>Deploying to Shared Hosting</span>
            </div>
            <button type="button" onclick="closeTransferModal()" class="modal-close" id="btnCloseTransferModal">&times;</button>
        </div>

        <div class="modal-body space-y-4">
            <div class="p-3.5 bg-gray-50 border border-gray-200 rounded-xl space-y-2">
                <div class="flex items-center justify-between text-xs font-semibold text-gray-700">
                    <span id="transferStatusText" class="flex items-center gap-1.5 text-indigo-600">
                        <svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                        Initializing transfer queue...
                    </span>
                    <div class="flex items-center gap-2 font-mono">
                        <span id="transferSpeedText" class="text-xs text-gray-500 font-normal">0 KB/s</span>
                        <span id="transferPercentText" class="font-bold text-gray-900 text-sm">0%</span>
                    </div>
                </div>

                <div class="w-full bg-gray-200 rounded-full h-2.5 overflow-hidden">
                    <div id="transferProgressBar" class="bg-indigo-600 h-2.5 rounded-full transition-all duration-150" style="width: 0%;"></div>
                </div>

                <div class="flex items-center justify-between text-[11px] font-mono text-gray-500">
                    <span id="transferFilesCountText">0 / 0 files</span>
                    <span id="transferBytesCountText">0 B / 0 B</span>
                </div>
            </div>

            <div class="p-2.5 bg-indigo-50/60 border border-indigo-100 rounded-lg text-xs flex items-center justify-between">
                <span class="text-indigo-900 truncate max-w-sm" id="transferCurrentFileText">Preparing upload...</span>
                <span class="text-[10px] font-mono text-indigo-600 font-semibold" id="transferEtaText">Calculating ETA...</span>
            </div>

            <div class="border border-gray-200 rounded-lg bg-gray-900 text-gray-100 p-3 font-mono text-[11px] overflow-hidden">
                <div class="flex items-center justify-between text-gray-400 border-b border-gray-800 pb-1.5 mb-2">
                    <span class="flex items-center gap-1.5 font-semibold text-gray-300">
                        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse inline-block"></span>
                        Live Transfer Console
                    </span>
                    <span class="text-[10px] text-gray-500">Timestamped Logs</span>
                </div>
                <div id="transferLiveLogs" class="space-y-1 max-h-48 overflow-y-auto pr-1" style="scrollbar-width: thin;">
                    <div class="text-gray-400"><span class="text-gray-500">--:--:--</span> Ready to initiate connection...</div>
                </div>
            </div>
        </div>

        <div class="modal-footer flex items-center justify-between">
            <span id="transferCompleteSummary" class="text-xs text-gray-500 font-medium"></span>
            <div class="flex items-center gap-2">
                <button type="button" onclick="cancelActiveTransfer()" class="btn btn-outline" id="btnCancelTransfer">Abort</button>
                <button type="button" onclick="closeTransferModal()" class="btn btn-primary hidden" id="btnFinishTransfer">Done</button>
            </div>
        </div>
    </div>
</div>

<!-- 2. ADD / EDIT FTP PROFILE MODAL -->
<div id="profileModal" class="modal-backdrop">
    <div class="modal-dialog" style="max-width: 520px;">
        <div class="modal-header">
            <div class="modal-title">
                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/></svg>
                <span id="profileModalTitle">Add Shared Hosting Profile</span>
            </div>
            <button type="button" onclick="closeModal('profileModal')" class="modal-close">&times;</button>
        </div>

        <form id="saveProfileForm" onsubmit="handleSaveProfile(event)">
            <input type="hidden" name="id" id="editProfileId" value="">
            <div class="modal-body space-y-3">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Profile Name</label>
                        <input type="text" name="profile_name" id="modalProfileName" placeholder="e.g. My Hostinger Server" class="w-full text-xs bg-gray-50 border border-gray-200 rounded-lg p-2 focus:bg-white" required>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Hosting Provider Preset</label>
                        <select name="provider" id="modalProvider" class="w-full text-xs bg-gray-50 border border-gray-200 rounded-lg p-2" onchange="onModalProviderChanged(this.value)">
                            <option value="cpanel">cPanel Hosting</option>
                            <option value="hostinger">Hostinger</option>
                            <option value="godaddy">GoDaddy</option>
                            <option value="namecheap">Namecheap</option>
                            <option value="bluehost">Bluehost</option>
                            <option value="plesk">Plesk</option>
                            <option value="custom">Custom Server</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Protocol</label>
                        <select name="protocol" id="modalProtocol" class="w-full text-xs bg-gray-50 border border-gray-200 rounded-lg p-2">
                            <option value="ftp">FTP (Port 21)</option>
                            <option value="ftps">FTPS (SSL/TLS)</option>
                            <option value="sftp">SFTP (SSH)</option>
                        </select>
                    </div>
                    <div class="col-span-2">
                        <label class="block text-xs font-bold text-gray-700 mb-1">FTP Host / Server</label>
                        <input type="text" name="host" id="modalHost" placeholder="shriyashpatil.in or IP" class="w-full text-xs bg-gray-50 border border-gray-200 rounded-lg p-2 font-mono focus:bg-white" required>
                    </div>
                </div>

                <div class="grid grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Port</label>
                        <input type="number" name="port" id="modalPort" value="21" class="w-full text-xs bg-gray-50 border border-gray-200 rounded-lg p-2 font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Username</label>
                        <input type="text" name="username" id="modalUsername" placeholder="username" class="w-full text-xs bg-gray-50 border border-gray-200 rounded-lg p-2 font-mono focus:bg-white" required>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Password</label>
                        <input type="password" name="password" id="modalPassword" placeholder="••••••••" class="w-full text-xs bg-gray-50 border border-gray-200 rounded-lg p-2 focus:bg-white">
                        <span id="modalPasswordHint" class="hidden text-[10px] text-gray-400 mt-0.5 block">Leave empty to keep existing password</span>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Default Remote Root Path</label>
                    <input type="text" name="remote_path" id="modalRemotePath" value="/" class="w-full text-xs bg-gray-50 border border-gray-200 rounded-lg p-2 font-mono focus:bg-white">
                </div>

                <div class="pt-2 border-t border-gray-100 flex items-center justify-between text-xs">
                    <label class="flex items-center gap-2 cursor-pointer text-gray-700">
                        <input type="checkbox" name="passive_mode" id="modalPassiveMode" value="1" checked class="rounded text-indigo-600">
                        <span>Passive Mode (PASV)</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer text-gray-700">
                        <input type="checkbox" name="is_default" id="modalIsDefault" value="1" class="rounded text-indigo-600">
                        <span>Set as Default Profile</span>
                    </label>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" onclick="closeModal('profileModal')" class="btn btn-outline">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSaveProfile">Save Hosting Profile</button>
            </div>
        </form>
    </div>
</div>

<!-- 3. REMOTE FILE PREVIEW MODAL -->
<div id="remotePreviewModal" class="modal-backdrop">
    <div class="modal-dialog" style="max-width: 800px; height: 85vh; display: flex; flex-direction: column;">
        <div class="modal-header">
            <div class="modal-title flex items-center gap-2">
                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>
                <span id="previewModalFileName">Remote File Viewer</span>
                <span id="previewModalFileSize" class="text-xs font-mono font-normal text-gray-400 bg-gray-100 px-2 py-0.5 rounded">0 B</span>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" onclick="copyPreviewContent()" class="p-1.5 text-gray-500 hover:text-gray-900 rounded hover:bg-gray-100 transition" title="Copy Content">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg>
                </button>
                <a id="previewDownloadLink" href="#" target="_blank" class="p-1.5 text-gray-500 hover:text-indigo-600 rounded hover:bg-gray-100 transition" title="Download File">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                </a>
                <button type="button" onclick="closeModal('remotePreviewModal')" class="modal-close">&times;</button>
            </div>
        </div>

        <div class="modal-body p-0 flex-1 overflow-hidden flex flex-col bg-gray-900">
            <textarea id="previewTextContent" class="w-full flex-1 p-4 bg-gray-950 text-gray-200 font-mono text-xs border-0 focus:outline-none resize-none leading-relaxed" readonly></textarea>
        </div>

        <div class="modal-footer flex items-center justify-between">
            <span id="previewPathDisplay" class="text-xs font-mono text-gray-500"></span>
            <button type="button" onclick="closeModal('remotePreviewModal')" class="btn btn-outline">Close</button>
        </div>
    </div>
</div>

<!-- 4. NEW REMOTE FOLDER MODAL -->
<div id="newRemoteFolderModal" class="modal-backdrop">
    <div class="modal-dialog" style="max-width: 440px;">
        <div class="modal-header">
            <div class="modal-title">
                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                <span>Create Remote Folder</span>
            </div>
            <button type="button" onclick="closeModal('newRemoteFolderModal')" class="modal-close">&times;</button>
        </div>
        <form onsubmit="handleCreateRemoteFolder(event)">
            <div class="modal-body">
                <div class="text-xs text-gray-500 mb-3">
                    Creating folder inside: <code class="font-mono text-gray-800" id="newFolderCurrentParentPath">/</code>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Folder Name</label>
                    <input type="text" id="newRemoteFolderNameInput" placeholder="e.g. assets, api, v2" class="w-full text-xs bg-gray-50 border border-gray-200 rounded-lg p-2.5 font-mono focus:bg-white" required autofocus>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('newRemoteFolderModal')" class="btn btn-outline">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitNewFolder">Create Folder</button>
            </div>
        </form>
    </div>
</div>

<!-- 5. UPLOAD DIRECT FILE MODAL -->
<div id="uploadDirectModal" class="modal-backdrop">
    <div class="modal-dialog" style="max-width: 480px;">
        <div class="modal-header">
            <div class="modal-title">
                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                <span>Upload to Remote Folder</span>
            </div>
            <button type="button" onclick="closeModal('uploadDirectModal')" class="modal-close">&times;</button>
        </div>
        <form id="uploadDirectForm" onsubmit="handleUploadDirectFile(event)">
            <div class="modal-body space-y-3">
                <div class="text-xs text-gray-500">
                    Destination directory: <code class="font-mono text-gray-800" id="uploadDirectDestPath">/</code>
                </div>

                <label class="dropzone block p-6 text-center border-2 border-dashed border-gray-200 rounded-xl cursor-pointer hover:border-indigo-400 bg-gray-50/60 transition">
                    <svg class="w-8 h-8 text-indigo-500 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                    <div class="text-xs font-bold text-gray-800" id="uploadDirectFileNameDisplay">Select or drop file to upload</div>
                    <span class="text-[11px] text-gray-400 block mt-1">Direct upload via FTP</span>
                    <input type="file" id="uploadDirectFileInput" class="hidden" onchange="if(this.files[0]) document.getElementById('uploadDirectFileNameDisplay').textContent = this.files[0].name;" required>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" onclick="closeModal('uploadDirectModal')" class="btn btn-outline">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnSubmitUploadDirect">Upload File</button>
            </div>
        </form>
    </div>
</div>

<script>
let currentRemotePath = '/';
let activeTransferAborted = false;
const availableProfiles = <?= json_encode($profiles) ?>;

function switchMainTab(tab) {
    const btnT = document.getElementById('tabBtnTransfer');
    const btnE = document.getElementById('tabBtnExplorer');
    const contentT = document.getElementById('tabContentTransfer');
    const contentE = document.getElementById('tabContentExplorer');

    if (tab === 'explorer') {
        btnE.className = 'inline-flex items-center gap-2 px-4 py-2.5 text-sm font-bold border-b-2 border-indigo-600 text-indigo-600 transition';
        btnT.className = 'inline-flex items-center gap-2 px-4 py-2.5 text-sm font-bold border-b-2 border-transparent text-gray-500 hover:text-gray-700 transition';
        contentE.classList.remove('hidden');
        contentT.classList.add('hidden');
        loadRemoteDirectory(currentRemotePath);
    } else {
        btnT.className = 'inline-flex items-center gap-2 px-4 py-2.5 text-sm font-bold border-b-2 border-indigo-600 text-indigo-600 transition';
        btnE.className = 'inline-flex items-center gap-2 px-4 py-2.5 text-sm font-bold border-b-2 border-transparent text-gray-500 hover:text-gray-700 transition';
        contentT.classList.remove('hidden');
        contentE.classList.add('hidden');
    }
}

function browseRemoteProfile(profileId) {
    const sel = document.getElementById('explorerProfileSelect');
    if (sel) {
        sel.value = profileId;
    }
    switchMainTab('explorer');
}

// ================= REMOTE EXPLORER ENGINE =================
function getSelectedExplorerProfileId() {
    const sel = document.getElementById('explorerProfileSelect');
    return sel ? sel.value : (availableProfiles[0] ? availableProfiles[0].id : null);
}

function loadRemoteDirectory(path = '/') {
    currentRemotePath = path;
    const profileId = getSelectedExplorerProfileId();
    if (!profileId) {
        document.getElementById('remoteExplorerError').classList.remove('hidden');
        document.getElementById('remoteExplorerErrorMsg').textContent = 'No hosting profile available. Please add a profile first.';
        return;
    }

    const loading = document.getElementById('remoteExplorerLoading');
    const errorBox = document.getElementById('remoteExplorerError');
    const tableBody = document.getElementById('remoteExplorerTableBody');
    const stats = document.getElementById('remoteExplorerStats');

    loading.classList.remove('hidden');
    errorBox.classList.add('hidden');
    tableBody.innerHTML = '';

    updateBreadcrumbs(path);

    const fd = new FormData();
    fd.append('action', 'ajax_browse_remote');
    fd.append('profile_id', profileId);
    fd.append('path', path);

    fetch('/dashboard/ftp.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            loading.classList.add('hidden');
            if (data.success) {
                renderRemoteItems(data.items, path);
                stats.textContent = `${data.total_items} items (${data.directories_count} folders, ${data.files_count} files)`;
            } else {
                errorBox.classList.remove('hidden');
                document.getElementById('remoteExplorerErrorMsg').textContent = data.error || 'Failed to list remote directory.';
            }
        }).catch(err => {
            loading.classList.add('hidden');
            errorBox.classList.remove('hidden');
            document.getElementById('remoteExplorerErrorMsg').textContent = 'Network error: ' + err.message;
        });
}

function refreshRemoteDirectory() {
    loadRemoteDirectory(currentRemotePath);
}

function updateBreadcrumbs(path) {
    const bar = document.getElementById('remoteBreadcrumbBar');
    bar.innerHTML = '';

    const rootSpan = document.createElement('span');
    rootSpan.className = 'text-indigo-600 font-bold cursor-pointer hover:underline flex items-center gap-1';
    rootSpan.innerHTML = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg> / (root)';
    rootSpan.onclick = () => loadRemoteDirectory('/');
    bar.appendChild(rootSpan);

    const parts = path.split('/').filter(p => p.length > 0);
    let accum = '';

    parts.forEach((p, idx) => {
        accum += '/' + p;
        const currentAccum = accum;
        
        const sep = document.createElement('span');
        sep.className = 'text-gray-300';
        sep.textContent = '/';
        bar.appendChild(sep);

        const span = document.createElement('span');
        span.className = idx === parts.length - 1 ? 'font-bold text-gray-900' : 'text-gray-600 hover:text-indigo-600 cursor-pointer hover:underline';
        span.textContent = p;
        span.onclick = () => loadRemoteDirectory(currentAccum);
        bar.appendChild(span);
    });
}

function getFileIconSvg(ext, isDir) {
    if (isDir) {
        return `<svg class="w-4 h-4 text-amber-500" fill="currentColor" viewBox="0 0 20 20"><path d="M2 6a2 2 0 012-2h5l2 2h5a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2V6z"/></svg>`;
    }
    if (['php', 'phtml'].includes(ext)) {
        return `<svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4"/></svg>`;
    }
    if (['html', 'htm'].includes(ext)) {
        return `<svg class="w-4 h-4 text-orange-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>`;
    }
    if (['js', 'json', 'ts'].includes(ext)) {
        return `<svg class="w-4 h-4 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>`;
    }
    if (['zip', 'tar', 'gz', 'rar'].includes(ext)) {
        return `<svg class="w-4 h-4 text-rose-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>`;
    }
    if (['sql', 'db'].includes(ext)) {
        return `<svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>`;
    }
    return `<svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>`;
}

function renderRemoteItems(items, path) {
    const tableBody = document.getElementById('remoteExplorerTableBody');
    tableBody.innerHTML = '';

    // Up one directory if not at root
    if (path !== '/' && path !== '') {
        const parentParts = path.split('/').filter(p => p.length > 0);
        parentParts.pop();
        const parentPath = '/' + parentParts.join('/');

        const trUp = document.createElement('tr');
        trUp.className = 'hover:bg-gray-50/80 cursor-pointer transition';
        trUp.onclick = () => loadRemoteDirectory(parentPath);
        trUp.innerHTML = `
            <td class="p-3 font-semibold text-gray-600 flex items-center gap-2">
                <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 15l-3-3m0 0l3-3m-3 3h8M3 12a9 9 0 1118 0 9 9 0 01-18 0z"/></svg>
                <span>.. (Parent Directory)</span>
            </td>
            <td class="p-3 text-gray-400 font-mono">-</td>
            <td class="p-3 text-gray-400 font-mono">-</td>
            <td class="p-3 text-gray-400 font-mono">-</td>
            <td class="p-3 text-right"></td>
        `;
        tableBody.appendChild(trUp);
    }

    if (items.length === 0) {
        const trEmpty = document.createElement('tr');
        trEmpty.innerHTML = `<td colspan="5" class="p-8 text-center text-xs text-gray-400 italic">This remote directory is empty.</td>`;
        tableBody.appendChild(trEmpty);
        return;
    }

    items.forEach(item => {
        const tr = document.createElement('tr');
        tr.className = 'hover:bg-gray-50/80 transition';

        const iconSvg = getFileIconSvg(item.extension, item.is_dir);
        const escapedPath = item.path.replace(/"/g, '&quot;');
        const escapedName = item.name.replace(/"/g, '&quot;');

        let nameHtml = '';
        if (item.is_dir) {
            nameHtml = `
                <div class="flex items-center gap-2 cursor-pointer text-gray-900 font-semibold hover:text-indigo-600" onclick="loadRemoteDirectory('${escapedPath}')">
                    ${iconSvg}
                    <span>${escapedName}</span>
                </div>
            `;
        } else {
            nameHtml = `
                <div class="flex items-center gap-2 cursor-pointer text-gray-700 hover:text-indigo-600" onclick="previewRemoteFile('${escapedPath}', '${escapedName}')">
                    ${iconSvg}
                    <span class="font-mono">${escapedName}</span>
                </div>
            `;
        }

        const profileId = getSelectedExplorerProfileId();
        const downloadUrl = `/dashboard/ftp.php?action=download_remote_file&profile_id=${profileId}&file_path=${encodeURIComponent(item.path)}`;

        tr.innerHTML = `
            <td class="p-3">${nameHtml}</td>
            <td class="p-3 font-mono text-gray-500">${item.formatted_size}</td>
            <td class="p-3 font-mono text-gray-400">${item.permissions}</td>
            <td class="p-3 text-gray-500 font-mono whitespace-nowrap">${item.date}</td>
            <td class="p-3 text-right">
                <div class="flex items-center justify-end gap-1">
                    ${!item.is_dir ? `
                        <button type="button" onclick="previewRemoteFile('${escapedPath}', '${escapedName}')" class="p-1 text-gray-400 hover:text-indigo-600 rounded transition" title="Preview / View Code">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                        </button>
                        <a href="${downloadUrl}" target="_blank" class="p-1 text-gray-400 hover:text-gray-700 rounded transition" title="Download">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        </a>
                    ` : `
                        <button type="button" onclick="loadRemoteDirectory('${escapedPath}')" class="p-1 text-gray-400 hover:text-indigo-600 rounded transition" title="Open Folder">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    `}
                    <button type="button" onclick="deleteRemoteItem('${escapedPath}', ${item.is_dir ? 1 : 0}, '${escapedName}')" class="p-1 text-gray-400 hover:text-rose-600 rounded transition" title="Delete">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                    </button>
                </div>
            </td>
        `;
        tableBody.appendChild(tr);
    });
}

function previewRemoteFile(filePath, fileName) {
    const profileId = getSelectedExplorerProfileId();
    document.getElementById('previewModalFileName').textContent = fileName;
    document.getElementById('previewModalFileSize').textContent = 'Loading...';
    document.getElementById('previewPathDisplay').textContent = filePath;
    document.getElementById('previewTextContent').value = 'Fetching remote file contents...';
    
    const dlLink = document.getElementById('previewDownloadLink');
    dlLink.href = `/dashboard/ftp.php?action=download_remote_file&profile_id=${profileId}&file_path=${encodeURIComponent(filePath)}`;

    openModal('remotePreviewModal');

    const fd = new FormData();
    fd.append('action', 'ajax_preview_remote_file');
    fd.append('profile_id', profileId);
    fd.append('file_path', filePath);

    fetch('/dashboard/ftp.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('previewModalFileSize').textContent = data.formatted_size;
                if (data.is_binary) {
                    document.getElementById('previewTextContent').value = `[Binary file content - ${data.formatted_size}]\nDownload file to view.`;
                } else {
                    document.getElementById('previewTextContent').value = data.content;
                }
            } else {
                document.getElementById('previewTextContent').value = 'Error: ' + (data.error || 'Failed to read file');
            }
        }).catch(err => {
            document.getElementById('previewTextContent').value = 'Network error: ' + err.message;
        });
}

function copyPreviewContent() {
    const text = document.getElementById('previewTextContent').value;
    navigator.clipboard.writeText(text).then(() => {
        alert('Copied file contents to clipboard!');
    });
}

function openNewRemoteFolderModal() {
    document.getElementById('newFolderCurrentParentPath').textContent = currentRemotePath;
    document.getElementById('newRemoteFolderNameInput').value = '';
    openModal('newRemoteFolderModal');
}

function handleCreateRemoteFolder(e) {
    e.preventDefault();
    const folderName = document.getElementById('newRemoteFolderNameInput').value.trim();
    if (!folderName) return;

    const profileId = getSelectedExplorerProfileId();
    const btn = document.getElementById('btnSubmitNewFolder');
    btn.disabled = true;
    btn.textContent = 'Creating...';

    const fd = new FormData();
    fd.append('action', 'ajax_create_remote_folder');
    fd.append('profile_id', profileId);
    fd.append('parent_path', currentRemotePath);
    fd.append('folder_name', folderName);

    fetch('/dashboard/ftp.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.textContent = 'Create Folder';
            if (data.success) {
                closeModal('newRemoteFolderModal');
                loadRemoteDirectory(currentRemotePath);
            } else {
                alert(data.error || 'Failed to create remote folder');
            }
        }).catch(err => {
            btn.disabled = false;
            btn.textContent = 'Create Folder';
            alert('Error: ' + err.message);
        });
}

function deleteRemoteItem(targetPath, isDir, name) {
    if (!confirm(`Are you sure you want to delete ${isDir ? 'folder' : 'file'} "${name}" from the remote server?`)) return;

    const profileId = getSelectedExplorerProfileId();
    const fd = new FormData();
    fd.append('action', 'ajax_delete_remote_item');
    fd.append('profile_id', profileId);
    fd.append('target_path', targetPath);
    fd.append('is_dir', isDir ? '1' : '0');

    fetch('/dashboard/ftp.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                loadRemoteDirectory(currentRemotePath);
            } else {
                alert(data.error || 'Failed to delete remote item');
            }
        });
}

function openUploadDirectModal() {
    document.getElementById('uploadDirectDestPath').textContent = currentRemotePath;
    document.getElementById('uploadDirectFileNameDisplay').textContent = 'Select or drop file to upload';
    document.getElementById('uploadDirectFileInput').value = '';
    openModal('uploadDirectModal');
}

function handleUploadDirectFile(e) {
    e.preventDefault();
    const fileInput = document.getElementById('uploadDirectFileInput');
    if (!fileInput.files || !fileInput.files[0]) {
        alert('Please choose a file to upload.');
        return;
    }

    const profileId = getSelectedExplorerProfileId();
    const btn = document.getElementById('btnSubmitUploadDirect');
    btn.disabled = true;
    btn.textContent = 'Uploading...';

    const fd = new FormData();
    fd.append('action', 'ajax_upload_direct_file');
    fd.append('profile_id', profileId);
    fd.append('remote_dir', currentRemotePath);
    fd.append('file', fileInput.files[0]);

    fetch('/dashboard/ftp.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.textContent = 'Upload File';
            if (data.success) {
                closeModal('uploadDirectModal');
                loadRemoteDirectory(currentRemotePath);
            } else {
                alert(data.error || 'Failed to upload file');
            }
        }).catch(err => {
            btn.disabled = false;
            btn.textContent = 'Upload File';
            alert('Upload error: ' + err.message);
        });
}

// ================= TRANSFER LAUNCHPAD LOGIC =================
function getSelectedProjectName() {
    const sel = document.getElementById('transferProjectSelect');
    return sel.value ? sel.value.trim() : '';
}

function setRemotePath(path) {
    document.getElementById('targetRemotePathInput').value = path;
}

function onProjectSelectChanged() {
    const proj = getSelectedProjectName();
    if (!proj) {
        document.getElementById('projectScanPreview').classList.add('hidden');
        return;
    }

    const curRemote = document.getElementById('targetRemotePathInput').value;
    if (curRemote.startsWith('/public_html') || curRemote.startsWith('public_html') || curRemote === '/') {
        document.getElementById('targetRemotePathInput').value = '/' + proj;
    }

    const fd = new FormData();
    fd.append('action', 'ajax_scan_project');
    fd.append('project', proj);
    fd.append('exclude_git', '1');
    fd.append('exclude_node_modules', '1');
    fd.append('exclude_ide', '1');

    fetch('/dashboard/ftp.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('scanPreviewFiles').textContent = data.total_files + ' files detected';
                document.getElementById('scanPreviewSize').textContent = data.total_size_formatted;
                document.getElementById('projectScanPreview').classList.remove('hidden');
            }
        }).catch(() => {});
}

function onProfileSelectChanged() {
    const sel = document.getElementById('transferProfileSelect');
    if (!sel || !sel.value) return;
    const opt = sel.options[sel.selectedIndex];
    if (opt) {
        const remote = opt.getAttribute('data-remote') || '/';
        const proj = getSelectedProjectName();
        if (proj) {
            document.getElementById('targetRemotePathInput').value = rtrim(remote, '/') + '/' + proj;
        } else {
            document.getElementById('targetRemotePathInput').value = remote;
        }
    }
}

function rtrim(str, chr) {
    return str.replace(new RegExp(chr + '+$'), '');
}

function toggleCustomCredentials() {
    const section = document.getElementById('customCredentialsSection');
    const text = document.getElementById('toggleCredsText');

    if (section.classList.contains('hidden')) {
        section.classList.remove('hidden');
        text.innerHTML = '&larr; Use Saved Profile Instead';
    } else {
        section.classList.add('hidden');
        text.innerHTML = 'Enter Custom / Temporary FTP Details &rarr;';
    }
}

function toggleDbExportOption(checked) {
    const row = document.getElementById('dbSelectRow');
    if (checked) row.classList.remove('hidden');
    else row.classList.add('hidden');
}

function onModalProviderChanged(val) {
    const pathInput = document.getElementById('modalRemotePath');
    if (val === 'cpanel' || val === 'godaddy' || val === 'namecheap' || val === 'bluehost') {
        pathInput.value = '/public_html/';
    } else if (val === 'hostinger') {
        pathInput.value = 'public_html/';
    } else if (val === 'plesk') {
        pathInput.value = '/httpdocs/';
    } else {
        pathInput.value = '/';
    }
}

function openNewProfileModal() {
    document.getElementById('profileModalTitle').textContent = 'Add Shared Hosting Profile';
    document.getElementById('editProfileId').value = '';
    document.getElementById('modalProfileName').value = '';
    document.getElementById('modalHost').value = '';
    document.getElementById('modalPort').value = '21';
    document.getElementById('modalUsername').value = '';
    document.getElementById('modalPassword').value = '';
    document.getElementById('modalPasswordHint').classList.add('hidden');
    document.getElementById('modalRemotePath').value = '/';
    document.getElementById('modalPassiveMode').checked = true;
    document.getElementById('modalIsDefault').checked = false;
    openModal('profileModal');
}

function editProfile(id) {
    const fd = new FormData();
    fd.append('action', 'ajax_get_profile');
    fd.append('profile_id', id);

    fetch('/dashboard/ftp.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success && data.profile) {
                const p = data.profile;
                document.getElementById('profileModalTitle').textContent = 'Edit Hosting Profile';
                document.getElementById('editProfileId').value = p.id;
                document.getElementById('modalProfileName').value = p.profile_name;
                document.getElementById('modalProvider').value = p.provider;
                document.getElementById('modalProtocol').value = p.protocol;
                document.getElementById('modalHost').value = p.host;
                document.getElementById('modalPort').value = p.port;
                document.getElementById('modalUsername').value = p.username;
                document.getElementById('modalPassword').value = '';
                document.getElementById('modalPasswordHint').classList.remove('hidden');
                document.getElementById('modalRemotePath').value = p.remote_path;
                document.getElementById('modalPassiveMode').checked = p.passive_mode == 1;
                document.getElementById('modalIsDefault').checked = p.is_default == 1;
                openModal('profileModal');
            } else {
                alert(data.error || 'Failed to load profile details');
            }
        });
}

function handleSaveProfile(e) {
    e.preventDefault();
    const form = document.getElementById('saveProfileForm');
    const fd = new FormData(form);
    fd.append('action', 'ajax_save_profile');

    const btn = document.getElementById('btnSaveProfile');
    btn.disabled = true;
    btn.textContent = 'Saving...';

    fetch('/dashboard/ftp.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.textContent = 'Save Hosting Profile';
            if (data.success) {
                closeModal('profileModal');
                location.reload();
            } else {
                alert(data.error || 'Failed to save profile');
            }
        }).catch(err => {
            btn.disabled = false;
            btn.textContent = 'Save Hosting Profile';
            alert('Server error: ' + err.message);
        });
}

function deleteProfile(id, name) {
    if (!confirm(`Are you sure you want to delete profile "${name}"?`)) return;
    const fd = new FormData();
    fd.append('action', 'ajax_delete_profile');
    fd.append('profile_id', id);

    fetch('/dashboard/ftp.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) location.reload();
            else alert(data.error || 'Failed to delete profile');
        });
}

function testSavedProfile(id) {
    const fd = new FormData();
    fd.append('action', 'ajax_test_connection');
    fd.append('profile_id', id);

    const btn = event.currentTarget;
    btn.disabled = true;
    btn.innerHTML = '<span class="text-[10px] animate-spin">⏳</span>';

    fetch('/dashboard/ftp.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>';
            if (data.success) {
                alert('✅ ' + data.message);
            } else {
                alert('❌ ' + data.error);
            }
        }).catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>';
            alert('Test connection failed: ' + err.message);
        });
}

function handleTestConnection() {
    const btn = document.getElementById('btnTestConn');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="animate-spin inline-block mr-1">⏳</span> Testing...';

    const fd = new FormData();
    fd.append('action', 'ajax_test_connection');

    const customSection = document.getElementById('customCredentialsSection');
    const isCustom = !customSection.classList.contains('hidden') || availableProfiles.length === 0;

    if (!isCustom) {
        const profId = document.getElementById('transferProfileSelect').value;
        fd.append('profile_id', profId);
    } else {
        fd.append('protocol', document.getElementById('customProtocol').value);
        fd.append('host', document.getElementById('customHost').value);
        fd.append('port', document.getElementById('customPort').value);
        fd.append('username', document.getElementById('customUsername').value);
        fd.append('password', document.getElementById('customPassword').value);
        fd.append('remote_path', document.getElementById('targetRemotePathInput').value);
        fd.append('passive_mode', document.getElementById('optPassiveMode').checked ? '1' : '0');
    }

    fetch('/dashboard/ftp.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.innerHTML = originalText;
            if (data.success) {
                alert('✅ ' + data.message);
            } else {
                alert('❌ ' + (data.error || 'Connection failed'));
            }
        }).catch(err => {
            btn.disabled = false;
            btn.innerHTML = originalText;
            alert('Connection test error: ' + err.message);
        });
}

// ================= LIVE TRANSFER PROGRESS =================
function logToTransferConsole(msg, type = 'info') {
    const logBox = document.getElementById('transferLiveLogs');
    const timeStr = new Date().toTimeString().split(' ')[0];
    const el = document.createElement('div');
    
    let colorClass = 'text-gray-300';
    if (type === 'success') colorClass = 'text-emerald-400';
    else if (type === 'error') colorClass = 'text-rose-400';
    else if (type === 'warning') colorClass = 'text-amber-300';
    else if (type === 'primary') colorClass = 'text-indigo-400 font-semibold';

    el.className = colorClass;
    el.innerHTML = `<span class="text-gray-500 font-mono">${timeStr}</span> ${msg}`;
    logBox.appendChild(el);
    logBox.scrollTop = logBox.scrollHeight;
}

function formatBytes(bytes, decimals = 2) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}

async function handleStartTransfer(e) {
    e.preventDefault();
    const proj = getSelectedProjectName();
    if (!proj) {
        alert('Please select a project to transfer.');
        return;
    }

    const remoteBase = document.getElementById('targetRemotePathInput').value.trim();
    if (!remoteBase) {
        alert('Please specify the destination remote path on the hosting server.');
        return;
    }

    activeTransferAborted = false;

    document.getElementById('transferLiveLogs').innerHTML = '';
    document.getElementById('transferProgressBar').style.width = '0%';
    document.getElementById('transferPercentText').textContent = '0%';
    document.getElementById('transferStatusText').innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg> Scanning project files...';
    document.getElementById('btnCancelTransfer').classList.remove('hidden');
    document.getElementById('btnFinishTransfer').classList.add('hidden');
    document.getElementById('transferCompleteSummary').textContent = '';

    openModal('ftpTransferProgressModal');

    logToTransferConsole(`Starting transfer pipeline for project: <strong>${proj}</strong>`, 'primary');
    logToTransferConsole(`Target Destination: <code>${remoteBase}</code>`, 'info');

    // 1. Scan files
    const scanFd = new FormData();
    scanFd.append('action', 'ajax_scan_project');
    scanFd.append('project', proj);
    scanFd.append('exclude_git', document.getElementById('optExcludeGit').checked ? '1' : '0');
    scanFd.append('exclude_node_modules', document.getElementById('optExcludeNodeModules').checked ? '1' : '0');
    scanFd.append('exclude_ide', '1');

    let scanData;
    try {
        const scanRes = await fetch('/dashboard/ftp.php', { method: 'POST', body: scanFd });
        scanData = await scanRes.json();
    } catch (err) {
        logToTransferConsole(`Project scan error: ${err.message}`, 'error');
        return;
    }

    if (!scanData.success || !scanData.files || scanData.files.length === 0) {
        logToTransferConsole(`No files found to transfer: ${scanData.error || 'Empty folder'}`, 'error');
        return;
    }

    let fileList = scanData.files;
    let totalBytes = scanData.total_bytes;
    let totalFiles = fileList.length;

    logToTransferConsole(`Scan complete: <strong>${totalFiles} files</strong> (${formatBytes(totalBytes)}) ready to transfer.`, 'success');

    // 2. Optional: DB export
    const includeDb = document.getElementById('optIncludeDb').checked;
    if (includeDb) {
        const selectedDb = document.getElementById('exportDbSelect').value;
        logToTransferConsole(`Exporting database [${selectedDb}] dump...`, 'info');
        const dbFd = new FormData();
        dbFd.append('action', 'ajax_export_db_for_transfer');
        dbFd.append('project', proj);
        dbFd.append('db_name', selectedDb);

        try {
            const dbRes = await fetch('/dashboard/ftp.php', { method: 'POST', body: dbFd });
            const dbData = await dbRes.json();
            if (dbData.success) {
                logToTransferConsole(`Database dump exported: ${dbData.file_name} (${dbData.formatted_size})`, 'success');
                fileList.unshift({
                    relative_path: dbData.file_name,
                    full_path: dbData.file_path,
                    size: dbData.size,
                    formatted_size: dbData.formatted_size
                });
                totalBytes += dbData.size;
                totalFiles++;
            } else {
                logToTransferConsole(`DB export skipped: ${dbData.error}`, 'warning');
            }
        } catch (err) {
            logToTransferConsole(`DB export skipped: ${err.message}`, 'warning');
        }
    }

    // 3. Sequential uploads
    const customSection = document.getElementById('customCredentialsSection');
    const isCustom = !customSection.classList.contains('hidden') || availableProfiles.length === 0;

    let profileId = null;
    let customCreds = {};

    if (!isCustom) {
        profileId = document.getElementById('transferProfileSelect').value;
    } else {
        customCreds = {
            protocol: document.getElementById('customProtocol').value,
            host: document.getElementById('customHost').value,
            port: document.getElementById('customPort').value,
            username: document.getElementById('customUsername').value,
            password: document.getElementById('customPassword').value,
            passive_mode: document.getElementById('optPassiveMode').checked ? '1' : '0'
        };
    }

    let uploadedBytes = 0;
    let uploadedCount = 0;
    let errorCount = 0;
    const startTime = Date.now();

    document.getElementById('transferStatusText').innerHTML = '<svg class="w-4 h-4 animate-spin text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg> Uploading files to server...';

    for (let i = 0; i < fileList.length; i++) {
        if (activeTransferAborted) {
            logToTransferConsole('Transfer aborted by user.', 'error');
            document.getElementById('transferStatusText').textContent = 'Transfer Aborted';
            break;
        }

        const file = fileList[i];
        document.getElementById('transferCurrentFileText').textContent = `Uploading [${i + 1}/${totalFiles}]: ${file.relative_path}`;

        const uploadFd = new FormData();
        uploadFd.append('action', 'ajax_upload_single_file');
        uploadFd.append('local_path', file.full_path);
        uploadFd.append('remote_rel_path', file.relative_path);
        uploadFd.append('custom_remote_base', remoteBase);

        if (profileId) {
            uploadFd.append('profile_id', profileId);
        } else {
            for (const k in customCreds) {
                uploadFd.append(k, customCreds[k]);
            }
        }

        try {
            const upRes = await fetch('/dashboard/ftp.php', { method: 'POST', body: uploadFd });
            const upData = await upRes.json();

            if (upData.success) {
                uploadedBytes += file.size;
                uploadedCount++;
                logToTransferConsole(`✔ Uploaded: <code>${file.relative_path}</code> (${file.formatted_size || formatBytes(file.size)})`, 'info');
            } else {
                errorCount++;
                logToTransferConsole(`✖ Failed: <code>${file.relative_path}</code> - ${upData.error}`, 'error');
            }
        } catch (err) {
            errorCount++;
            logToTransferConsole(`✖ Network Error on ${file.relative_path}: ${err.message}`, 'error');
        }

        const pct = totalBytes > 0 ? Math.round((uploadedBytes / totalBytes) * 100) : Math.round(((i + 1) / totalFiles) * 100);
        document.getElementById('transferProgressBar').style.width = pct + '%';
        document.getElementById('transferPercentText').textContent = pct + '%';
        document.getElementById('transferFilesCountText').textContent = `${uploadedCount}/${totalFiles} files transferred`;
        document.getElementById('transferBytesCountText').textContent = `${formatBytes(uploadedBytes)} / ${formatBytes(totalBytes)}`;

        const elapsedSec = (Date.now() - startTime) / 1000;
        if (elapsedSec > 0.5) {
            const speed = uploadedBytes / elapsedSec;
            document.getElementById('transferSpeedText').textContent = formatBytes(speed) + '/s';
            const remainingBytes = Math.max(0, totalBytes - uploadedBytes);
            const remainingSec = speed > 0 ? Math.round(remainingBytes / speed) : 0;
            document.getElementById('transferEtaText').textContent = remainingSec > 0 ? `ETA: ~${remainingSec}s` : 'Finishing...';
        }
    }

    const finalStatus = errorCount === 0 ? 'success' : (uploadedCount > 0 ? 'partial' : 'failed');
    const finalMsg = `Transferred ${uploadedCount}/${totalFiles} files (${formatBytes(uploadedBytes)}). Errors: ${errorCount}`;

    logToTransferConsole(`=========================================`, 'primary');
    if (finalStatus === 'success') {
        logToTransferConsole(`🎉 All files transferred successfully to <strong>${remoteBase}</strong>!`, 'success');
        document.getElementById('transferStatusText').innerHTML = '✅ Transfer Complete!';
    } else {
        logToTransferConsole(`Transfer completed with ${errorCount} errors. ${finalMsg}`, 'warning');
        document.getElementById('transferStatusText').innerHTML = '⚠️ Transfer Finished with Errors';
    }

    document.getElementById('transferCurrentFileText').textContent = 'Deployment complete.';
    document.getElementById('btnCancelTransfer').classList.add('hidden');
    document.getElementById('btnFinishTransfer').classList.remove('hidden');
    document.getElementById('transferCompleteSummary').textContent = `${uploadedCount} of ${totalFiles} files uploaded successfully.`;

    const logFd = new FormData();
    logFd.append('action', 'ajax_log_transfer');
    if (profileId) logFd.append('profile_id', profileId);
    logFd.append('project', proj);
    logFd.append('remote_path', remoteBase);
    logFd.append('files_count', uploadedCount);
    logFd.append('total_bytes', uploadedBytes);
    logFd.append('status', finalStatus);
    logFd.append('message', finalMsg);
    fetch('/dashboard/ftp.php', { method: 'POST', body: logFd }).catch(() => {});
}

function cancelActiveTransfer() {
    activeTransferAborted = true;
    logToTransferConsole('Aborting transfer...', 'error');
    document.getElementById('btnCancelTransfer').classList.add('hidden');
    document.getElementById('btnFinishTransfer').classList.remove('hidden');
}

function closeTransferModal() {
    activeTransferAborted = true;
    closeModal('ftpTransferProgressModal');
}

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('transferProjectSelect').value) {
        onProjectSelectChanged();
    }
    if ('<?= $activeTab ?>' === 'explorer') {
        loadRemoteDirectory('/');
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
