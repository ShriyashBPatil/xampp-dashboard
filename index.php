<?php
require_once __DIR__ . '/includes/core.php';
require_login();

// POST Action Handler for Auto Setup and ZIP upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_not_guest('Deployments, auto-setup, and database imports are disabled in Guest Mode.');
    $action = $_POST['action'] ?? '';
    $baseDir = realpath(__DIR__ . '/..');

    if ($action === 'auto_setup') {
        require_permission('can_autosetup');
        $sourceType = $_POST['setup_source_type'] ?? 'zip';
        $enableDb = isset($_POST['setup_enable_db']);
        $customProjectName = trim($_POST['setup_project_name'] ?? '');
        if (!$enableDb && !empty($_POST['setup_project_name_nodb'])) {
            $customProjectName = trim($_POST['setup_project_name_nodb']);
        }
        $customDbName = trim($_POST['setup_db_name'] ?? '');
        $autoPatch = $enableDb && isset($_POST['setup_auto_patch']);

        $projectFolderName = '';
        $extractedFilesCount = 0;
        $sqlToImport = null;
        $tempSqlUploaded = null;

        if ($enableDb && isset($_FILES['setup_sql_file']) && $_FILES['setup_sql_file']['error'] === UPLOAD_ERR_OK) {
            $tempSqlUploaded = $_FILES['setup_sql_file']['tmp_name'];
            $sqlToImport = $tempSqlUploaded;
        }

        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

        if ($sourceType === 'zip') {
            if (!isset($_FILES['setup_zip_file']) || $_FILES['setup_zip_file']['error'] !== UPLOAD_ERR_OK) {
                $errCode = $_FILES['setup_zip_file']['error'] ?? 'missing';
                $errMsg = 'Please select a valid .zip project archive for auto setup. (Upload code: ' . $errCode . ')';
                if ($isAjax) {
                    header('Content-Type: application/json', true, 400);
                    echo json_encode(['success' => false, 'error' => $errMsg]);
                    exit;
                }
                set_flash('error', $errMsg);
                header('Location: /dashboard/');
                exit;
            }

            $zipTmp = $_FILES['setup_zip_file']['tmp_name'];
            $zipOrig = $_FILES['setup_zip_file']['name'];
            $derivedName = !empty($customProjectName) ? $customProjectName : pathinfo($zipOrig, PATHINFO_FILENAME);
            $projectFolderName = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower(str_replace(' ', '-', $derivedName)));
            if (empty($projectFolderName)) $projectFolderName = 'app_' . date('Ymd_His');

            $targetPath = $baseDir . '/' . $projectFolderName;
            if (!is_dir($targetPath)) mkdir($targetPath, 0777, true);

            $zip = new ZipArchive();
            $extractionLogs = [];
            if ($zip->open($zipTmp) === true) {
                $extractedFilesCount = $zip->numFiles;
                $extractionLogs[] = "Opened ZIP archive with {$zip->numFiles} items.";
                
                // Sample up to first 8 extracted items for detailed logs
                $sampleCount = min(8, $zip->numFiles);
                for ($zi = 0; $zi < $sampleCount; $zi++) {
                    $stat = $zip->statIndex($zi);
                    if ($stat) {
                        $extractionLogs[] = "Extracted: " . $stat['name'] . " (" . $stat['size'] . " bytes)";
                    }
                }
                if ($zip->numFiles > $sampleCount) {
                    $extractionLogs[] = "... and " . ($zip->numFiles - $sampleCount) . " additional file(s) extracted.";
                }

                $zip->extractTo($targetPath);
                $zip->close();
                normalize_directory_structure($targetPath);
                $extractionLogs[] = "Normalized project directory structure and sanitized permissions.";
            } else {
                $errMsg = 'Failed to extract project zip archive. Ensure file is a valid ZIP.';
                if ($isAjax) {
                    header('Content-Type: application/json', true, 400);
                    echo json_encode(['success' => false, 'error' => $errMsg]);
                    exit;
                }
                set_flash('error', $errMsg);
                header('Location: /dashboard/');
                exit;
            }
        } elseif ($sourceType === 'folder') {
            if (!isset($_FILES['setup_folder_files']) || empty($_FILES['setup_folder_files']['name'][0])) {
                $errMsg = 'Please select a project folder for auto setup.';
                if ($isAjax) {
                    header('Content-Type: application/json', true, 400);
                    echo json_encode(['success' => false, 'error' => $errMsg]);
                    exit;
                }
                set_flash('error', $errMsg);
                header('Location: /dashboard/');
                exit;
            }

            $fFiles = $_FILES['setup_folder_files'];
            $firstPath = $fFiles['full_path'][0] ?? $fFiles['name'][0];
            $detectedRoot = explode('/', str_replace('\\', '/', $firstPath))[0];
            
            $derivedName = !empty($customProjectName) ? $customProjectName : $detectedRoot;
            $projectFolderName = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower(str_replace(' ', '-', $derivedName)));
            if (empty($projectFolderName)) $projectFolderName = 'folder_app_' . date('Ymd_His');

            $targetPath = $baseDir . '/' . $projectFolderName;
            if (!is_dir($targetPath)) mkdir($targetPath, 0777, true);

            $fCount = count($fFiles['name']);
            $extractionLogs = [];
            $extractionLogs[] = "Receiving {$fCount} directory files...";
            for ($i = 0; $i < $fCount; $i++) {
                if ($fFiles['error'][$i] !== UPLOAD_ERR_OK) continue;
                $rel = $fFiles['full_path'][$i] ?? $fFiles['name'][$i];
                $rel = str_replace('\\', '/', $rel);
                $parts = explode('/', $rel);
                if (count($parts) > 1) {
                    array_shift($parts);
                    $sub = implode('/', $parts);
                } else {
                    $sub = $parts[0];
                }

                $dest = $targetPath . '/' . $sub;
                $dir = dirname($dest);
                if (!is_dir($dir)) mkdir($dir, 0777, true);
                if (move_uploaded_file($fFiles['tmp_name'][$i], $dest)) {
                    $extractedFilesCount++;
                    if ($extractedFilesCount <= 6) {
                        $extractionLogs[] = "Placed file: " . $sub;
                    }
                }
            }
            if ($extractedFilesCount > 6) {
                $extractionLogs[] = "... and " . ($extractedFilesCount - 6) . " additional directory files placed.";
            }
            normalize_directory_structure($targetPath);
            $extractionLogs[] = "Project folder structure organized and permissions verified.";
        }

        // Database auto-creation & import (if database is enabled)
        $dbNameTarget = null;
        $dbImported = false;
        $queriesExecuted = 0;
        $patchedFiles = [];

        if ($enableDb) {
            // Auto-discover SQL if not uploaded explicitly
            if (!$sqlToImport) {
                $discoveredSql = glob($targetPath . '/*.sql');
                if (empty($discoveredSql)) $discoveredSql = glob($targetPath . '/*/*.sql');
                if (!empty($discoveredSql)) $sqlToImport = $discoveredSql[0];
            }

            $dbNameTarget = !empty($customDbName) ? preg_replace('/[^a-zA-Z0-9_]/', '', $customDbName) : preg_replace('/[^a-zA-Z0-9_]/', '_', $projectFolderName) . '_db';

            try {
                $rootPdo = get_db_connection();
                $rootPdo->exec("CREATE DATABASE IF NOT EXISTS `$dbNameTarget` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                $extractionLogs[] = "Created database '{$dbNameTarget}' with utf8mb4 collation.";

                if ($sqlToImport && file_exists($sqlToImport)) {
                    $queriesExecuted = run_sql_file($dbNameTarget, $sqlToImport);
                    $dbImported = true;
                    $extractionLogs[] = "Imported schema " . basename($sqlToImport) . " ({$queriesExecuted} SQL statements executed).";
                }
            } catch (Exception $e) {
                $extractionLogs[] = "Database setup warning: " . $e->getMessage();
            }

            // Auto config patch
            if ($autoPatch) {
                $patchedFiles = patch_project_configs($targetPath, $dbNameTarget);
                if (!empty($patchedFiles)) {
                    $extractionLogs[] = "Patched database configuration in: " . implode(', ', $patchedFiles);
                }
            }
        }

        // Final URLs
        $publicUrl = "http://server.shriyashpatil.in:8181/{$projectFolderName}";
        $localUrl = "http://localhost:8181/{$projectFolderName}";

        $_SESSION['auto_setup_result'] = [
            'project_name' => $projectFolderName,
            'db_name' => $dbNameTarget,
            'has_db' => $enableDb,
            'files_count' => $extractedFilesCount,
            'db_imported' => $dbImported,
            'queries_count' => $queriesExecuted,
            'patched_files' => $patchedFiles,
            'link_shriyash' => $publicUrl,
            'link_local' => $localUrl
        ];

        set_flash('success', "Auto Setup complete! Project '{$projectFolderName}' is ready.");

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'redirect' => '/dashboard/',
                'project_name' => $projectFolderName,
                'db_name' => $dbNameTarget,
                'files_count' => $extractedFilesCount,
                'logs' => $extractionLogs
            ]);
            exit;
        }

        header('Location: /dashboard/');
        exit;
    }

    if ($action === 'upload_zip' && isset($_FILES['zip_file'])) {
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');
        $zipTmp = $_FILES['zip_file']['tmp_name'];
        $zipOrig = $_FILES['zip_file']['name'];
        $customFolder = trim($_POST['target_folder'] ?? '');
        $folderName = !empty($customFolder) ? $customFolder : pathinfo($zipOrig, PATHINFO_FILENAME);
        $folderName = preg_replace('/[^a-zA-Z0-9_-]/', '', strtolower(str_replace(' ', '-', $folderName)));
        if (empty($folderName)) $folderName = 'app_' . date('Ymd_His');

        $targetDir = $baseDir . '/' . $folderName;
        if (!is_dir($targetDir)) mkdir($targetDir, 0777, true);

        $zip = new ZipArchive();
        $deployLogs = [];
        if ($zip->open($zipTmp) === true) {
            $deployLogs[] = "Opened ZIP archive with {$zip->numFiles} entries.";
            $sampleCount = min(8, $zip->numFiles);
            for ($zi = 0; $zi < $sampleCount; $zi++) {
                $stat = $zip->statIndex($zi);
                if ($stat) {
                    $deployLogs[] = "Extracted: " . $stat['name'] . " (" . $stat['size'] . " bytes)";
                }
            }
            if ($zip->numFiles > $sampleCount) {
                $deployLogs[] = "... and " . ($zip->numFiles - $sampleCount) . " additional file(s) extracted.";
            }

            $zip->extractTo($targetDir);
            $zip->close();
            normalize_directory_structure($targetDir);
            $deployLogs[] = "Normalized project directory structure.";
            set_flash('success', "ZIP deployed successfully to '/{$folderName}'.");
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'redirect' => '/dashboard/projects.php',
                    'project_name' => $folderName,
                    'logs' => $deployLogs
                ]);
                exit;
            }
        } else {
            if ($isAjax) {
                header('Content-Type: application/json', true, 400);
                echo json_encode(['success' => false, 'error' => 'Failed to extract ZIP archive.']);
                exit;
            }
            set_flash('error', "Failed to extract ZIP archive.");
        }
        header('Location: /dashboard/projects.php');
        exit;
    }

    if ($action === 'import_sql' && isset($_FILES['sql_file'])) {
        $targetDb = trim($_POST['target_db'] ?? '');
        if (!empty($targetDb) && isset($_FILES['sql_file']['tmp_name']) && is_uploaded_file($_FILES['sql_file']['tmp_name'])) {
            try {
                $count = run_sql_file($targetDb, $_FILES['sql_file']['tmp_name']);
                set_flash('success', "Imported {$count} SQL queries into database '{$targetDb}'.");
            } catch (Exception $e) {
                set_flash('error', "SQL import failed: " . $e->getMessage());
            }
        }
        header('Location: /dashboard/database.php?db=' . urlencode($targetDb));
        exit;
    }
}

$setupResult = $_SESSION['auto_setup_result'] ?? null;
unset($_SESSION['auto_setup_result']);

$pageTitle = 'Shriyash Patil Workspace';
$activeNav = 'home';
include __DIR__ . '/includes/header.php';
?>

<!-- Setup Result Card -->
<?php if ($setupResult): ?>
    <div class="bg-white border border-emerald-200 rounded-xl p-6 mb-8 shadow-sm" id="setupSuccessCard">
        <div class="flex items-start justify-between gap-4">
            <div class="flex items-start gap-3">
                <div class="w-10 h-10 rounded-lg bg-emerald-50 border border-emerald-100 flex items-center justify-center text-emerald-600 flex-shrink-0">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                </div>
                <div>
                    <h2 class="text-base font-bold text-gray-900">Application Auto-Setup Succeeded!</h2>
                    <p class="text-xs text-gray-500 mt-0.5">
                        Project <strong><?= htmlspecialchars($setupResult['project_name']) ?></strong> is live
                        <?php if (!empty($setupResult['db_name'])): ?>
                            with database <strong><?= htmlspecialchars($setupResult['db_name']) ?></strong>.
                        <?php else: ?>
                            (Standalone / No database).
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <button onclick="document.getElementById('setupSuccessCard').remove()" class="text-gray-400 hover:text-gray-600 text-lg leading-none">&times;</button>
        </div>

        <div class="mt-4 bg-gray-50 rounded-lg p-3 border border-gray-200 flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-2">
            <span class="font-mono text-xs font-semibold text-gray-800 truncate"><?= htmlspecialchars($setupResult['link_shriyash']) ?></span>
            <div class="flex items-center gap-2">
                <button onclick="copyToClipboard('<?= htmlspecialchars(addslashes($setupResult['link_shriyash'])) ?>', this)" class="px-3 py-1 bg-white border border-gray-300 rounded text-xs font-medium text-gray-700 hover:bg-gray-50 transition">Copy Link</button>
                <a href="<?= htmlspecialchars($setupResult['link_shriyash']) ?>" target="_blank" class="px-3 py-1 bg-indigo-600 rounded text-xs font-medium text-white hover:bg-indigo-700 transition">Open App</a>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Welcome & Overview Metrics -->
<div class="mb-8">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Overview Dashboard</h1>
            <p class="text-sm text-gray-500 mt-1">Unified control panel for Apache, MariaDB, PHP 8.2, and local workspace applications.</p>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="openModal('autoSetupModal')" class="inline-flex items-center gap-2 px-3.5 py-2 bg-indigo-600 rounded-lg text-sm font-medium text-white hover:bg-indigo-700 transition shadow-sm">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                1-Click Auto Setup
            </button>
        </div>
    </div>

    <!-- Quick Stats Grid -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Web Applications</span>
                <div class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                </div>
            </div>
            <div class="mt-2 text-2xl font-bold text-gray-900"><?= count($allProjects) ?></div>
            <span class="text-xs text-gray-400 mt-1 block"><?= count($standaloneScripts) ?> standalone scripts</span>
        </div>

        <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">MariaDB Databases</span>
                <div class="w-8 h-8 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                </div>
            </div>
            <div class="mt-2 text-2xl font-bold text-gray-900"><?= count($dbList) ?></div>
            <span class="text-xs text-emerald-600 font-medium mt-1 block">Connected (db:3306)</span>
        </div>

        <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">Web Server</span>
                <div class="w-8 h-8 rounded-lg bg-blue-50 text-blue-600 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/></svg>
                </div>
            </div>
            <div class="mt-2 text-2xl font-bold text-gray-900">Apache 2.4</div>
            <span class="text-xs text-gray-400 mt-1 block">PHP <?= $phpVersion ?> &bull; Port 8181</span>
        </div>

        <div class="bg-white border border-gray-200 rounded-xl p-4 shadow-sm">
            <div class="flex items-center justify-between">
                <span class="text-xs font-semibold text-gray-500 uppercase tracking-wider">phpMyAdmin</span>
                <div class="w-8 h-8 rounded-lg bg-purple-50 text-purple-600 flex items-center justify-center">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                </div>
            </div>
            <div class="mt-2 text-2xl font-bold text-gray-900">Port 2555</div>
            <a href="http://server.shriyashpatil.in:2555" target="_blank" class="text-xs text-indigo-600 hover:underline mt-1 block font-medium">Launch phpMyAdmin &rarr;</a>
        </div>
    </div>
</div>

<!-- Quick Navigation Cards -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
    <a href="/dashboard/projects.php" class="group bg-white border border-gray-200 rounded-xl p-5 hover:border-indigo-300 hover:shadow-md transition">
        <div class="w-10 h-10 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center mb-3 group-hover:bg-indigo-600 group-hover:text-white transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
        </div>
        <h3 class="text-sm font-bold text-gray-900 group-hover:text-indigo-600 transition">Projects & Apps &rarr;</h3>
        <p class="text-xs text-gray-500 mt-1">Manage, launch, search, and delete all deployed applications.</p>
    </a>

    <a href="/dashboard/explorer.php" class="group bg-white border border-gray-200 rounded-xl p-5 hover:border-indigo-300 hover:shadow-md transition">
        <div class="w-10 h-10 rounded-lg bg-amber-50 text-amber-600 flex items-center justify-center mb-3 group-hover:bg-amber-600 group-hover:text-white transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        </div>
        <h3 class="text-sm font-bold text-gray-900 group-hover:text-amber-600 transition">File Explorer &rarr;</h3>
        <p class="text-xs text-gray-500 mt-1">Browse directories, upload files, and edit scripts inline.</p>
    </a>

    <a href="/dashboard/database.php" class="group bg-white border border-gray-200 rounded-xl p-5 hover:border-indigo-300 hover:shadow-md transition">
        <div class="w-10 h-10 rounded-lg bg-emerald-50 text-emerald-600 flex items-center justify-center mb-3 group-hover:bg-emerald-600 group-hover:text-white transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
        </div>
        <h3 class="text-sm font-bold text-gray-900 group-hover:text-emerald-600 transition">Database &rarr;</h3>
        <p class="text-xs text-gray-500 mt-1">Inspect tables, execute SQL queries, and manage schemas.</p>
    </a>

    <a href="/dashboard/terminal.php" class="group bg-white border border-gray-200 rounded-xl p-5 hover:border-indigo-300 hover:shadow-md transition">
        <div class="w-10 h-10 rounded-lg bg-purple-50 text-purple-600 flex items-center justify-center mb-3 group-hover:bg-purple-600 group-hover:text-white transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        </div>
        <h3 class="text-sm font-bold text-gray-900 group-hover:text-purple-600 transition">Terminal & AGY &rarr;</h3>
        <p class="text-xs text-gray-500 mt-1">Direct shell console, bash commands, and Antigravity AI CLI.</p>
    </a>
</div>

<!-- Recent Projects Preview -->
<div class="bg-white border border-gray-200 rounded-xl p-5 shadow-sm mb-8">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-sm font-bold text-gray-900">Recent Applications</h3>
        <a href="/dashboard/projects.php" class="text-xs font-semibold text-indigo-600 hover:underline">View All (<?= count($allProjects) ?>) &rarr;</a>
    </div>
    
    <?php if (empty($allProjects)): ?>
        <p class="text-xs text-gray-400 italic">No applications found in /var/www/html/.</p>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
            <?php foreach (array_slice($allProjects, 0, 6) as $p): ?>
                <div class="p-3 bg-gray-50 border border-gray-100 rounded-lg flex items-center justify-between">
                    <div class="flex items-center gap-2.5 min-w-0 pr-2">
                        <div class="w-7 h-7 rounded-md bg-white border border-gray-200 flex items-center justify-center flex-shrink-0 p-1 overflow-hidden shadow-xs">
                            <?php if (!empty($p['favicon'])): ?>
                                <img src="<?= htmlspecialchars($p['favicon']) ?>" alt="<?= htmlspecialchars($p['name']) ?>" class="w-full h-full object-contain" onerror="this.onerror=null; this.parentElement.className='w-7 h-7 rounded-md bg-white border border-gray-200 flex items-center justify-center flex-shrink-0 text-indigo-500'; this.parentElement.innerHTML='<svg class=\'w-4 h-4\' fill=\'none\' stroke=\'currentColor\' viewBox=\'0 0 24 24\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z\'/></svg>'">
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center text-indigo-500">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="min-w-0">
                            <span class="font-bold text-xs text-gray-900 block truncate"><?= htmlspecialchars($p['name']) ?></span>
                            <span class="font-mono text-[11px] text-gray-400 block truncate"><?= htmlspecialchars($p['display_url']) ?></span>
                        </div>
                    </div>
                    <div class="flex items-center gap-1.5 flex-shrink-0">
                        <a href="/dashboard/ftp.php?project=<?= urlencode($p['name']) ?>" class="p-1 text-indigo-700 bg-indigo-50 hover:bg-indigo-100 border border-indigo-200/60 rounded transition" title="Transfer to Shared Hosting via FTP">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                        </a>
                        <a href="/dashboard/explorer.php?dir=<?= urlencode($p['name']) ?>" class="p-1 text-amber-700 bg-amber-50 hover:bg-amber-100 border border-amber-200/60 rounded transition" title="Open in File Explorer">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                        </a>
                        <button type="button" onclick="openDeleteProjectModal('<?= htmlspecialchars(addslashes($p['name'])) ?>')" class="p-1 text-gray-400 hover:text-red-600 rounded hover:bg-white transition" title="Delete Project">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                        <a href="<?= htmlspecialchars($p['url']) ?>" target="_blank" class="px-2.5 py-1 bg-white border border-gray-200 rounded text-xs font-semibold text-gray-700 hover:bg-gray-100">Launch</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
