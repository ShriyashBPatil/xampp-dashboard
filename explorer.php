<?php
require_once __DIR__ . '/includes/core.php';
require_permission('can_files_browse', 'Access denied. You do not have permission to browse files.');

// Safe Explorer Navigation Paths
$relDir = trim($_GET['dir'] ?? '', '/\\');
if (strpos($relDir, '..') !== false) {
    $relDir = '';
}

$currentDir = WWW_ROOT . ($relDir ? '/' . $relDir : '');
if (!is_dir($currentDir)) {
    $currentDir = WWW_ROOT;
    $relDir = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_not_guest('File and folder operations (create, rename, upload, delete, save) are disabled in Guest Mode.');
}

// Action: Save File Content
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_file'])) {
    require_permission('can_files_edit', 'Access denied. You do not have permission to edit files.');
    $targetFile = trim($_POST['file_path'] ?? '');
    $fileContent = $_POST['file_content'] ?? '';
    if (strpos($targetFile, '..') === false) {
        $fullPath = WWW_ROOT . '/' . ltrim($targetFile, '/');
        if (file_exists($fullPath) && is_file($fullPath)) {
            file_put_contents($fullPath, $fileContent);
            set_flash('success', "File '{$targetFile}' saved successfully.");
        }
    }
    header('Location: /dashboard/explorer.php?dir=' . urlencode(dirname($targetFile) === '.' ? '' : dirname($targetFile)) . '&file=' . urlencode($targetFile));
    exit;
}

// Action: Create New Folder
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_folder'])) {
    require_permission('can_files_create', 'Access denied. You do not have permission to create folders.');
    $folderName = trim($_POST['folder_name'] ?? '');
    if (preg_match('/^[a-zA-Z0-9_\-\.]+$/', $folderName)) {
        $newPath = $currentDir . '/' . $folderName;
        if (!file_exists($newPath)) {
            mkdir($newPath, 0777, true);
            set_flash('success', "Folder '{$folderName}' created successfully.");
        } else {
            set_flash('error', "Folder already exists.");
        }
    } else {
        set_flash('error', "Invalid folder name.");
    }
    header('Location: /dashboard/explorer.php?dir=' . urlencode($relDir));
    exit;
}

// Action: Create New File
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_file'])) {
    require_permission('can_files_create', 'Access denied. You do not have permission to create files.');
    $fileName = trim($_POST['file_name'] ?? '');
    if (preg_match('/^[a-zA-Z0-9_\-\.]+$/', $fileName)) {
        $newPath = $currentDir . '/' . $fileName;
        if (!file_exists($newPath)) {
            file_put_contents($newPath, "<?php\n// " . htmlspecialchars($fileName) . "\n");
            set_flash('success', "File '{$fileName}' created successfully.");
        } else {
            set_flash('error', "File already exists.");
        }
    } else {
        set_flash('error', "Invalid file name.");
    }
    header('Location: /dashboard/explorer.php?dir=' . urlencode($relDir) . '&file=' . urlencode(($relDir ? $relDir . '/' : '') . $fileName));
    exit;
}

// Action: Rename Item (Folder or File)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['rename_item'])) {
    require_permission('can_files_create', 'Access denied. You do not have permission to rename items.');
    $oldName = trim($_POST['old_name'] ?? '');
    $newName = trim($_POST['new_name'] ?? '');
    if (strpos($oldName, '..') === false && preg_match('/^[a-zA-Z0-9_\-\.]+$/', $newName)) {
        $oldPath = $currentDir . '/' . $oldName;
        $newPath = $currentDir . '/' . $newName;
        if (file_exists($oldPath) && !file_exists($newPath)) {
            if (rename($oldPath, $newPath)) {
                set_flash('success', "Renamed '{$oldName}' to '{$newName}'.");
            } else {
                set_flash('error', "Failed to rename item. Check permissions.");
            }
        } else {
            set_flash('error', "Rename failed. Destination name already exists.");
        }
    } else {
        set_flash('error', "Invalid item name specified.");
    }
    header('Location: /dashboard/explorer.php?dir=' . urlencode($relDir));
    exit;
}

// Action: Delete Item (Folder or File)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    require_permission('can_files_delete', 'Access denied. You do not have permission to delete items.');
    $delName = trim($_POST['item_name'] ?? '');
    if (strpos($delName, '..') === false && !in_array($delName, ['', '.', '..', 'dashboard'])) {
        $delPath = $currentDir . '/' . $delName;
        if (is_dir($delPath)) {
            deleteDirectoryRecursive($delPath);
            set_flash('success', "Directory '{$delName}' and its contents deleted.");
        } elseif (is_file($delPath)) {
            unlink($delPath);
            set_flash('success', "File '{$delName}' deleted.");
        }
    } else {
        set_flash('error', "Cannot delete system protected directory.");
    }
    header('Location: /dashboard/explorer.php?dir=' . urlencode($relDir));
    exit;
}

// Action: Upload Files to Current Dir (via standard POST or AJAX)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_files']) && !empty($_FILES['files']['name'][0])) {
    require_permission('can_files_upload', 'Access denied. You do not have permission to upload files.');
    $uploadedCount = 0;
    $errors = [];
    foreach ($_FILES['files']['name'] as $idx => $fname) {
        $fname = basename($fname);
        $tmpName = $_FILES['files']['tmp_name'][$idx];
        $err = $_FILES['files']['error'][$idx];
        if ($err === UPLOAD_ERR_OK && $fname && is_uploaded_file($tmpName)) {
            move_uploaded_file($tmpName, $currentDir . '/' . $fname);
            $uploadedCount++;
        } elseif ($fname) {
            $errors[] = $fname;
        }
    }

    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['success' => true, 'count' => $uploadedCount, 'errors' => $errors]);
        exit;
    }

    if ($uploadedCount > 0) {
        set_flash('success', "Uploaded {$uploadedCount} file(s) successfully.");
    } else {
        set_flash('error', "Upload failed or no files selected.");
    }
    header('Location: /dashboard/explorer.php?dir=' . urlencode($relDir));
    exit;
}

// Action: Download Folder as ZIP
if (isset($_GET['action']) && $_GET['action'] === 'download_zip') {
    require_permission('can_files_download', 'Access denied. You do not have permission to download folder archives.');
    $targetDirRel = trim($_GET['dir'] ?? '', '/\\');
    if (strpos($targetDirRel, '..') === false) {
        $folderFullPath = WWW_ROOT . ($targetDirRel ? '/' . $targetDirRel : '');
        if (is_dir($folderFullPath)) {
            $folderBaseName = $targetDirRel ? basename($targetDirRel) : 'workspace_root';
            $zipFileName = $folderBaseName . '_' . date('Ymd_His') . '.zip';
            $tempZipPath = sys_get_temp_dir() . '/' . uniqid('zip_', true) . '.zip';

            $zipSuccess = false;

            if (class_exists('ZipArchive')) {
                $zip = new ZipArchive();
                if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true) {
                    $files = new RecursiveIteratorIterator(
                        new RecursiveDirectoryIterator($folderFullPath, RecursiveDirectoryIterator::SKIP_DOTS),
                        RecursiveIteratorIterator::LEAVES_ONLY
                    );

                    foreach ($files as $file) {
                        if (!$file->isDir()) {
                            $filePath = $file->getRealPath();
                            $relativePath = substr($filePath, strlen($folderFullPath) + 1);
                            $zip->addFile($filePath, $relativePath);
                        }
                    }
                    $zip->close();
                    $zipSuccess = file_exists($tempZipPath) && filesize($tempZipPath) > 0;
                }
            }

            // Fallback to system zip CLI if ZipArchive failed
            if (!$zipSuccess) {
                $cmd = "cd " . escapeshellarg($folderFullPath) . " && zip -r " . escapeshellarg($tempZipPath) . " . 2>&1";
                @exec($cmd, $out, $ret);
                $zipSuccess = ($ret === 0 && file_exists($tempZipPath) && filesize($tempZipPath) > 0);
            }

            if ($zipSuccess) {
                // Clear output buffers
                while (ob_get_level()) {
                    ob_end_clean();
                }
                header('Content-Description: File Transfer');
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $zipFileName . '"');
                header('Expires: 0');
                header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
                header('Pragma: public');
                header('Content-Length: ' . filesize($tempZipPath));
                readfile($tempZipPath);
                @unlink($tempZipPath);
                exit;
            } else {
                set_flash('error', 'Failed to generate ZIP archive for folder: ' . htmlspecialchars($folderBaseName));
                header('Location: /dashboard/explorer.php?dir=' . urlencode($relDir));
                exit;
            }
        }
    }
}

// Action: Download File
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    require_permission('can_files_download', 'Access denied. You do not have permission to download files.');
    $dlFile = trim($_GET['file'] ?? '');
    if (strpos($dlFile, '..') === false) {
        $fullPath = WWW_ROOT . '/' . ltrim($dlFile, '/');
        if (file_exists($fullPath) && is_file($fullPath)) {
            while (ob_get_level()) {
                ob_end_clean();
            }
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . basename($fullPath) . '"');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($fullPath));
            readfile($fullPath);
            exit;
        }
    }
}

// Active File Editing
$editingFile = trim($_GET['file'] ?? '');
$editingContent = '';
$isEditing = false;

if ($editingFile && strpos($editingFile, '..') === false) {
    $editingFullPath = WWW_ROOT . '/' . ltrim($editingFile, '/');
    if (file_exists($editingFullPath) && is_file($editingFullPath)) {
        $isEditing = true;
        $editingContent = file_get_contents($editingFullPath);
    }
}

// List contents of current directory
$items = scandir($currentDir);
$dirsList = [];
$filesList = [];

foreach ($items as $item) {
    if ($item === '.' || ($item === '..' && empty($relDir))) continue;
    $itemPath = $currentDir . '/' . $item;
    $isDir = is_dir($itemPath);
    
    $info = [
        'name' => $item,
        'is_dir' => $isDir,
        'size' => $isDir ? '-' : formatBytes(filesize($itemPath)),
        'modified' => date('Y-m-d H:i', filemtime($itemPath)),
        'ext' => $isDir ? '' : strtolower(pathinfo($item, PATHINFO_EXTENSION)),
        'path' => $relDir ? $relDir . '/' . $item : $item
    ];

    if ($isDir) {
        $dirsList[] = $info;
    } else {
        $filesList[] = $info;
    }
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

$pageTitle = 'File Explorer & Editor';
$activeNav = 'explorer';
include __DIR__ . '/includes/header.php';
?>

<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 tracking-tight">File Explorer & Editor</h1>
        <p class="text-sm text-gray-500 mt-1">Browse, edit, and manage code files in <code class="px-1.5 py-0.5 bg-gray-100 rounded text-xs text-gray-700 font-mono">/var/www/html/<?= htmlspecialchars($relDir) ?></code></p>
    </div>
    <div class="flex items-center gap-2">
        <?php if (!empty($relDir)): ?>
            <a href="/dashboard/ftp.php?project=<?= urlencode($relDir) ?>" class="inline-flex items-center gap-1.5 px-3 py-2 bg-indigo-50 border border-indigo-200 rounded-lg text-xs font-semibold text-indigo-700 hover:bg-indigo-100 transition shadow-sm cursor-pointer" title="Transfer this directory to shared hosting via FTP">
                <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                FTP Deploy
            </a>
        <?php else: ?>
            <a href="/dashboard/ftp.php" class="inline-flex items-center gap-1.5 px-3 py-2 bg-white border border-gray-300 rounded-lg text-xs font-medium text-indigo-700 hover:bg-indigo-50 hover:border-indigo-300 transition shadow-sm cursor-pointer" title="Deploy project to shared hosting">
                <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                FTP Transfer
            </a>
        <?php endif; ?>
        <a href="/dashboard/explorer.php?action=download_zip&dir=<?= urlencode($relDir) ?>" class="inline-flex items-center gap-1.5 px-3 py-2 bg-white border border-gray-300 rounded-lg text-xs font-medium text-gray-700 hover:bg-gray-50 transition shadow-sm cursor-pointer" title="Download current folder as ZIP archive">
            <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
            <span>Download ZIP</span>
        </a>
        <button type="button" onclick="openModal('newFolderModal')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-white border border-gray-300 rounded-lg text-xs font-medium text-gray-700 hover:bg-gray-50 transition shadow-sm cursor-pointer">
            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
            New Folder
        </button>
        <button type="button" onclick="openModal('newFileModal')" class="inline-flex items-center gap-1.5 px-3 py-2 bg-white border border-gray-300 rounded-lg text-xs font-medium text-gray-700 hover:bg-gray-50 transition shadow-sm cursor-pointer">
            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
            New File
        </button>
        <button type="button" onclick="openModal('uploadFilesModal')" class="inline-flex items-center gap-1.5 px-3.5 py-2 bg-indigo-600 rounded-lg text-xs font-medium text-white hover:bg-indigo-700 transition shadow-sm cursor-pointer">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
            Upload Files
        </button>
    </div>
</div>

<!-- Breadcrumbs Bar -->
<div class="bg-white border border-gray-200 rounded-xl p-3 shadow-sm mb-6 flex items-center gap-2 text-xs font-mono overflow-x-auto">
    <a href="/dashboard/explorer.php" class="flex items-center gap-1 text-indigo-600 hover:underline font-semibold">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
        <span>root</span>
    </a>
    <?php
    $parts = array_filter(explode('/', $relDir));
    $built = '';
    foreach ($parts as $p):
        $built .= ($built ? '/' : '') . $p;
    ?>
        <span class="text-gray-300">/</span>
        <a href="/dashboard/explorer.php?dir=<?= urlencode($built) ?>" class="text-gray-700 hover:text-indigo-600 hover:underline"><?= htmlspecialchars($p) ?></a>
    <?php endforeach; ?>
</div>

<div class="grid grid-cols-1 <?= $isEditing ? 'lg:grid-cols-2' : '' ?> gap-6">
    <!-- File Navigator Table -->
    <div class="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm">
        <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-900 flex items-center gap-2">
                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                Files & Folders
            </h3>
            <span class="text-xs text-gray-400 font-mono"><?= count($dirsList) + count($filesList) ?> items</span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 text-xs">
                <thead class="bg-gray-50 text-gray-500 font-medium">
                    <tr>
                        <th class="px-4 py-2.5 text-left">Name</th>
                        <th class="px-4 py-2.5 text-left">Size</th>
                        <th class="px-4 py-2.5 text-left">Modified</th>
                        <th class="px-4 py-2.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 font-mono">
                    <?php if ($relDir): ?>
                        <tr class="hover:bg-gray-50">
                            <td colspan="4" class="px-4 py-2">
                                <a href="/dashboard/explorer.php?dir=<?= urlencode(dirname($relDir) === '.' ? '' : dirname($relDir)) ?>" class="flex items-center gap-2 text-indigo-600 font-medium hover:underline">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12"/></svg>
                                    <span>.. (Parent Directory)</span>
                                </a>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <!-- Directories -->
                    <?php foreach ($dirsList as $d): ?>
                        <?php if ($d['name'] === '..') continue; ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2.5">
                                <a href="/dashboard/explorer.php?dir=<?= urlencode($d['path']) ?>" class="flex items-center gap-2 text-gray-900 font-semibold hover:text-indigo-600">
                                    <svg class="w-4 h-4 text-amber-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                    <span class="truncate"><?= htmlspecialchars($d['name']) ?>/</span>
                                </a>
                            </td>
                            <td class="px-4 py-2.5 text-gray-400">-</td>
                            <td class="px-4 py-2.5 text-gray-500"><?= $d['modified'] ?></td>
                            <td class="px-4 py-2.5 text-right space-x-1 whitespace-nowrap">
                                <a href="/dashboard/explorer.php?action=download_zip&dir=<?= urlencode($d['path']) ?>" class="p-1 inline-block text-gray-400 hover:text-indigo-600 rounded hover:bg-gray-100" title="Download Folder as ZIP">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                </a>
                                <button type="button" onclick="openRenameModal('<?= htmlspecialchars(addslashes($d['name'])) ?>')" class="p-1 text-gray-400 hover:text-gray-700 rounded hover:bg-gray-100 cursor-pointer inline-block" title="Rename Folder">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                </button>
                                <form method="POST" action="/dashboard/explorer.php?dir=<?= urlencode($relDir) ?>" onsubmit="return confirm('Delete directory <?= htmlspecialchars(addslashes($d['name'])) ?> and all its files?');" class="inline">
                                    <input type="hidden" name="delete_item" value="1">
                                    <input type="hidden" name="item_name" value="<?= htmlspecialchars($d['name']) ?>">
                                    <button type="submit" class="p-1 text-gray-400 hover:text-red-600 rounded hover:bg-red-50 cursor-pointer inline-block" title="Delete Folder">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <!-- Files -->
                    <?php foreach ($filesList as $f): ?>
                        <tr class="hover:bg-gray-50 <?= ($editingFile === $f['path']) ? 'bg-indigo-50/50' : '' ?>">
                            <td class="px-4 py-2.5">
                                <div class="flex items-center gap-2">
                                    <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    <a href="/dashboard/editor.php?file=<?= urlencode($f['path']) ?>" target="_blank" class="truncate text-gray-700 hover:text-indigo-600 font-medium hover:underline" title="Open in Code Editor (New Window)">
                                        <?= htmlspecialchars($f['name']) ?>
                                    </a>
                                </div>
                            </td>
                            <td class="px-4 py-2.5 text-gray-500"><?= $f['size'] ?></td>
                            <td class="px-4 py-2.5 text-gray-500"><?= $f['modified'] ?></td>
                            <td class="px-4 py-2.5 text-right space-x-1 whitespace-nowrap">
                                <a href="/dashboard/editor.php?file=<?= urlencode($f['path']) ?>" target="_blank" class="p-1 inline-block text-indigo-600 hover:text-indigo-800 rounded hover:bg-indigo-50" title="Edit in New Window (Mobile Friendly Editor)">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                </a>
                                <a href="/dashboard/explorer.php?dir=<?= urlencode($relDir) ?>&file=<?= urlencode($f['path']) ?>" class="p-1 inline-block text-gray-500 hover:text-gray-800 rounded hover:bg-gray-100" title="Quick Edit (Split View)">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                </a>
                                <a href="/dashboard/explorer.php?action=download&file=<?= urlencode($f['path']) ?>" class="p-1 inline-block text-gray-400 hover:text-indigo-600 rounded hover:bg-gray-100" title="Download File">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                </a>
                                <button type="button" onclick="openRenameModal('<?= htmlspecialchars(addslashes($f['name'])) ?>')" class="p-1 text-gray-400 hover:text-gray-700 rounded hover:bg-gray-100 cursor-pointer inline-block" title="Rename File">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                                </button>
                                <form method="POST" action="/dashboard/explorer.php?dir=<?= urlencode($relDir) ?>" onsubmit="return confirm('Delete file <?= htmlspecialchars(addslashes($f['name'])) ?>?');" class="inline">
                                    <input type="hidden" name="delete_item" value="1">
                                    <input type="hidden" name="item_name" value="<?= htmlspecialchars($f['name']) ?>">
                                    <button type="submit" class="p-1 text-gray-400 hover:text-red-600 rounded hover:bg-red-50 cursor-pointer inline-block" title="Delete File">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Inline Code Editor -->
    <?php if ($isEditing): ?>
        <div class="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm flex flex-col">
            <form method="POST" action="/dashboard/explorer.php?dir=<?= urlencode($relDir) ?>" class="flex flex-col h-full">
                <input type="hidden" name="save_file" value="1">
                <input type="hidden" name="file_path" value="<?= htmlspecialchars($editingFile) ?>">
                
                <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between bg-gray-50">
                    <div class="flex items-center gap-2 min-w-0">
                        <svg class="w-4 h-4 text-indigo-600 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        <span class="text-xs font-mono font-bold text-gray-900 truncate"><?= htmlspecialchars($editingFile) ?></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="/dashboard/editor.php?file=<?= urlencode($editingFile) ?>" target="_blank" class="px-2.5 py-1 text-xs text-indigo-600 hover:text-indigo-800 rounded bg-indigo-50 hover:bg-indigo-100 transition flex items-center gap-1 font-medium" title="Open in dedicated editor">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                            <span>Open in New Window</span>
                        </a>
                        <a href="/dashboard/explorer.php?dir=<?= urlencode($relDir) ?>" class="px-2.5 py-1 text-xs text-gray-500 hover:text-gray-800 rounded bg-gray-200/60 hover:bg-gray-200 transition">Close</a>
                        <button type="submit" class="px-3.5 py-1 bg-indigo-600 hover:bg-indigo-700 text-white rounded text-xs font-semibold shadow-sm transition cursor-pointer">Save</button>
                    </div>
                </div>

                <div class="p-2 flex-1">
                    <textarea name="file_content" rows="22" class="w-full h-full font-mono text-xs bg-gray-900 text-gray-100 p-3 rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-500"><?= htmlspecialchars($editingContent) ?></textarea>
                </div>
            </form>
        </div>
    <?php endif; ?>
</div>

<!-- Modal: New Folder -->
<div id="newFolderModal" class="modal-backdrop">
    <div class="modal-dialog" style="max-width: 480px;">
        <div class="modal-header">
            <div class="modal-title">
                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m-9 1V7a2 2 0 012-2h6l2 2h6a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2z"/></svg>
                <span>Create New Folder</span>
            </div>
            <button type="button" onclick="closeModal('newFolderModal')" class="modal-close">&times;</button>
        </div>
        <form method="POST" action="/dashboard/explorer.php?dir=<?= urlencode($relDir) ?>">
            <input type="hidden" name="create_folder" value="1">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Folder Name</label>
                    <input type="text" name="folder_name" placeholder="e.g. assets, api, templates" required class="form-input font-mono" autofocus>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px;">
                    <button type="button" onclick="closeModal('newFolderModal')" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create Folder</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal: New File -->
<div id="newFileModal" class="modal-backdrop">
    <div class="modal-dialog" style="max-width: 480px;">
        <div class="modal-header">
            <div class="modal-title">
                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                <span>Create New File</span>
            </div>
            <button type="button" onclick="closeModal('newFileModal')" class="modal-close">&times;</button>
        </div>
        <form method="POST" action="/dashboard/explorer.php?dir=<?= urlencode($relDir) ?>">
            <input type="hidden" name="create_file" value="1">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">File Name (with extension)</label>
                    <input type="text" name="file_name" placeholder="e.g. index.php, style.css, script.js" required class="form-input font-mono" autofocus>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px;">
                    <button type="button" onclick="closeModal('newFileModal')" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create File</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Rename Item -->
<div id="renameModal" class="modal-backdrop">
    <div class="modal-dialog" style="max-width: 480px;">
        <div class="modal-header">
            <div class="modal-title">
                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                <span>Rename Item</span>
            </div>
            <button type="button" onclick="closeModal('renameModal')" class="modal-close">&times;</button>
        </div>
        <form method="POST" action="/dashboard/explorer.php?dir=<?= urlencode($relDir) ?>">
            <input type="hidden" name="rename_item" value="1">
            <input type="hidden" name="old_name" id="renameOldName">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">New Name</label>
                    <input type="text" name="new_name" id="renameNewName" required class="form-input font-mono" autofocus>
                </div>
                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px;">
                    <button type="button" onclick="closeModal('renameModal')" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">Rename</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Upload Files with Live Status & Progress -->
<!-- Modal: Upload Files with Live Status & Progress -->
<div id="uploadFilesModal" class="modal-backdrop">
    <div class="modal-dialog" id="uploadFilesModalDialog" style="max-width: 560px;">
        <div class="modal-header">
            <div class="modal-title">
                <svg class="w-5 h-5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                <span>Upload Files to Directory</span>
            </div>
            <div class="modal-header-actions">
                <button type="button" class="modal-tool-btn" id="btnFullscreenUploadFiles" onclick="toggleModalFullscreen('uploadFilesModalDialog', this)" title="Toggle Fullscreen">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-5h-4m4 0v4m0-4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/></svg>
                </button>
                <button type="button" onclick="closeModal('uploadFilesModal')" class="modal-close">&times;</button>
            </div>
        </div>
        <form id="explorerUploadForm" method="POST" action="/dashboard/explorer.php?dir=<?= urlencode($relDir) ?>" enctype="multipart/form-data" onsubmit="handleAjaxFileUpload(event)">
            <input type="hidden" name="upload_files" value="1">
            <div class="modal-body">
                <!-- Dropzone Area -->
                <label class="dropzone" id="explorerDropZone" style="display:block;">
                    <div class="dropzone-icon text-indigo-500">
                        <svg class="w-8 h-8 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                    </div>
                    <div style="font-weight:600; font-size:0.88rem; color:var(--text-primary);" id="explorerUploadDropText">Choose or drag file(s) here</div>
                    <div style="font-size:0.75rem; color:var(--text-muted); margin-top:4px;">Upload limit: 256 MB &bull; Multiple files supported</div>
                    <input type="file" id="explorerFileInput" name="files[]" multiple style="display:none;" onchange="displaySelectedFiles(this)" required>
                </label>

                <!-- Selected Files List Container -->
                <div id="selectedFilesContainer" class="hidden">
                    <div class="flex items-center justify-between text-xs font-semibold text-gray-700 mb-2">
                        <span>Selected Files (<span id="selectedCount">0</span>)</span>
                        <span id="selectedTotalSize" class="text-gray-400 font-mono text-[11px]">0 KB</span>
                    </div>
                    <div id="selectedFilesList" class="max-h-36 overflow-y-auto space-y-1.5 border border-gray-100 rounded-lg p-2 bg-gray-50/70 text-xs font-mono">
                    </div>
                </div>

                <!-- Live Upload Progress Bar & Timestamped Logs Terminal -->
                <div id="uploadProgressContainer" class="hidden flex flex-col gap-2">
                    <div class="flex items-center justify-between text-xs font-semibold text-gray-700">
                        <span id="uploadStatusText" class="flex items-center gap-1.5 text-indigo-600">
                            <svg class="w-3.5 h-3.5 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path></svg>
                            Uploading files...
                        </span>
                        <div class="flex items-center gap-2">
                            <span id="uploadSpeedText" class="text-xs font-mono font-normal text-gray-500">0 KB/s</span>
                            <span id="uploadPercentText" class="font-bold text-gray-900 font-mono">0%</span>
                        </div>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-2 overflow-hidden">
                        <div id="uploadProgressBar" class="bg-indigo-600 h-2 rounded-full transition-all duration-150" style="width: 0%;"></div>
                    </div>
                    <div class="flex items-center justify-between text-[11px] font-mono text-gray-400">
                        <span id="uploadBytesText">0 B / 0 B</span>
                        <span id="uploadEtaText">Calculating...</span>
                    </div>

                    <!-- Real-time Timestamped Logs Terminal -->
                    <div class="mt-1 border border-gray-200 rounded-lg bg-gray-900 text-gray-100 p-2.5 font-mono text-[11px] overflow-hidden">
                        <div class="flex items-center justify-between text-gray-400 border-b border-gray-800 pb-1.5 mb-1.5">
                            <span class="flex items-center gap-1.5 font-semibold text-gray-300">
                                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse inline-block"></span>
                                Explorer Upload Logs
                            </span>
                            <span class="text-[10px] text-gray-500">Live Timestamped</span>
                        </div>
                        <div id="explorerUploadLogsConsole" class="space-y-1 max-h-36 overflow-y-auto pr-1" style="scrollbar-width: thin;">
                            <div class="text-gray-400"><span class="text-gray-500">--:--:--</span> Ready to upload...</div>
                        </div>
                    </div>
                </div>

                <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 10px;">
                    <button type="button" onclick="closeModal('uploadFilesModal')" class="btn btn-outline" id="btnCancelUpload">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="btnSubmitUpload">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                        <span>Start Upload</span>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function openRenameModal(name) {
    document.getElementById('renameOldName').value = name;
    document.getElementById('renameNewName').value = name;
    openModal('renameModal');
}

function displaySelectedFiles(input) {
    const container = document.getElementById('selectedFilesContainer');
    const list = document.getElementById('selectedFilesList');
    const countSpan = document.getElementById('selectedCount');
    const sizeSpan = document.getElementById('selectedTotalSize');
    const dropText = document.getElementById('explorerUploadDropText');

    if (input.files && input.files.length > 0) {
        list.innerHTML = '';
        let totalBytes = 0;
        countSpan.textContent = input.files.length;
        dropText.textContent = input.files.length + ' file(s) selected';

        Array.from(input.files).forEach((file, idx) => {
            totalBytes += file.size;
            const item = document.createElement('div');
            item.className = 'flex items-center justify-between bg-white px-2.5 py-1.5 rounded border border-gray-200';
            item.innerHTML = `
                <div class="flex items-center gap-1.5 min-w-0 pr-2">
                    <svg class="w-3.5 h-3.5 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <span class="truncate text-gray-800 font-medium">${file.name}</span>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                    <span class="text-gray-400 text-[10px]">${formatJsBytes(file.size)}</span>
                    <span class="text-emerald-600 text-[10px] font-semibold">Ready</span>
                </div>
            `;
            list.appendChild(item);
        });

        sizeSpan.textContent = formatJsBytes(totalBytes);
        container.classList.remove('hidden');
    } else {
        container.classList.add('hidden');
        dropText.textContent = 'Choose or drag file(s) here';
    }
}

function formatJsBytes(bytes) {
    if (bytes === 0) return '0 B';
    const k = 1024;
    const sizes = ['B', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
}

function toggleModalFullscreen(modalDialogId, btnElement) {
    const dialog = document.getElementById(modalDialogId);
    if (!dialog) return;
    const isFullscreen = dialog.classList.toggle('modal-fullscreen');
    if (btnElement) {
        if (isFullscreen) {
            btnElement.innerHTML = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 9L4 4m0 0l5 5M4 4v5m0-5h5m6 6l5-5m0 0l-5 5m5-5v5m0-5h-5M9 15l-5 5m0 0l5-5m-5 5v-5m0 5h5m6-6l5 5m0 0l-5-5m5 5v-5m0 5h-5"/></svg>';
            btnElement.title = 'Exit Fullscreen';
        } else {
            btnElement.innerHTML = '<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-5h-4m4 0v4m0-4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/></svg>';
            btnElement.title = 'Toggle Fullscreen';
        }
    }
}

function appendModalLog(consoleId, message, type = 'info') {
    const consoleEl = document.getElementById(consoleId);
    if (!consoleEl) return;
    const d = new Date();
    const timeStr = d.toTimeString().split(' ')[0] + '.' + String(d.getMilliseconds()).padStart(3, '0');
    const row = document.createElement('div');
    row.className = 'flex items-start gap-2 py-0.5 border-b border-gray-800/40 text-[11px] leading-relaxed';
    
    let colorClass = 'text-gray-300';
    let badge = '';
    if (type === 'success') {
        colorClass = 'text-emerald-400 font-medium';
        badge = '<span class="text-emerald-500 font-bold">[SUCCESS]</span> ';
    } else if (type === 'error') {
        colorClass = 'text-red-400 font-medium';
        badge = '<span class="text-red-500 font-bold">[ERROR]</span> ';
    } else if (type === 'warn') {
        colorClass = 'text-amber-300';
        badge = '<span class="text-amber-400">[WARN]</span> ';
    } else if (type === 'step') {
        colorClass = 'text-indigo-300 font-semibold';
        badge = '<span class="text-indigo-400 font-bold">[STEP]</span> ';
    }

    row.innerHTML = `<span class="text-gray-500 flex-shrink-0 select-none">${timeStr}</span> <span class="${colorClass} flex-1 break-all">${badge}${message}</span>`;
    consoleEl.appendChild(row);
    consoleEl.scrollTop = consoleEl.scrollHeight;
}

function handleAjaxFileUpload(e) {
    e.preventDefault();
    const form = document.getElementById('explorerUploadForm');
    const fileInput = document.getElementById('explorerFileInput');
    const modalDialog = document.getElementById('uploadFilesModalDialog');
    
    if (!fileInput.files || fileInput.files.length === 0) {
        alert('Please select at least one file to upload.');
        return;
    }

    // Expand modal to comfortable size when progress starts
    if (modalDialog && !modalDialog.classList.contains('modal-fullscreen')) {
        modalDialog.classList.add('modal-lg');
    }

    const progressContainer = document.getElementById('uploadProgressContainer');
    const progressBar = document.getElementById('uploadProgressBar');
    const percentText = document.getElementById('uploadPercentText');
    const speedText = document.getElementById('uploadSpeedText');
    const bytesText = document.getElementById('uploadBytesText');
    const etaText = document.getElementById('uploadEtaText');
    const statusText = document.getElementById('uploadStatusText');
    const logsConsole = document.getElementById('explorerUploadLogsConsole');
    const submitBtn = document.getElementById('btnSubmitUpload');
    const cancelBtn = document.getElementById('btnCancelUpload');

    if (logsConsole) logsConsole.innerHTML = '';
    progressContainer.classList.remove('hidden');
    submitBtn.disabled = true;
    submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
    cancelBtn.style.display = 'none';

    appendModalLog('explorerUploadLogsConsole', `Selected ${fileInput.files.length} file(s) for upload to directory.`, 'step');
    Array.from(fileInput.files).forEach((f, idx) => {
        appendModalLog('explorerUploadLogsConsole', `[#${idx + 1}] ${f.name} (${formatJsBytes(f.size)})`);
    });

    const formData = new FormData(form);
    const xhr = new XMLHttpRequest();
    const startTime = Date.now();
    let lastTime = startTime;
    let lastLoaded = 0;
    let logged25 = false, logged50 = false, logged75 = false;

    appendModalLog('explorerUploadLogsConsole', 'Step 1/2: Uploading file payload to server...', 'step');

    xhr.upload.addEventListener('progress', function(event) {
        if (event.lengthComputable) {
            const currentTime = Date.now();
            const timeDiff = (currentTime - lastTime) / 1000;
            
            if (timeDiff >= 0.2 || event.loaded === event.total) {
                const bytesDiff = event.loaded - lastLoaded;
                const speed = timeDiff > 0 ? (bytesDiff / timeDiff) : 0;
                speedText.textContent = formatJsBytes(speed) + '/s';

                const remainingBytes = event.total - event.loaded;
                if (speed > 0 && remainingBytes > 0) {
                    const etaSeconds = Math.ceil(remainingBytes / speed);
                    etaText.textContent = etaSeconds < 60 ? etaSeconds + 's remaining' : Math.ceil(etaSeconds / 60) + 'm remaining';
                } else if (event.loaded === event.total) {
                    etaText.textContent = 'Uploaded';
                }

                lastTime = currentTime;
                lastLoaded = event.loaded;
            }

            const percent = Math.round((event.loaded / event.total) * 100);
            progressBar.style.width = percent + '%';
            percentText.textContent = percent + '%';
            bytesText.textContent = formatJsBytes(event.loaded) + ' / ' + formatJsBytes(event.total);

            if (percent >= 25 && !logged25) {
                logged25 = true;
                appendModalLog('explorerUploadLogsConsole', `Upload progress: 25% completed (${formatJsBytes(event.loaded)})`);
            } else if (percent >= 50 && !logged50) {
                logged50 = true;
                appendModalLog('explorerUploadLogsConsole', `Upload progress: 50% completed (${formatJsBytes(event.loaded)})`);
            } else if (percent >= 75 && !logged75) {
                logged75 = true;
                appendModalLog('explorerUploadLogsConsole', `Upload progress: 75% completed (${formatJsBytes(event.loaded)})`);
            }

            if (percent === 100) {
                statusText.innerHTML = '<span class="text-emerald-600 font-semibold">Processing & saving files...</span>';
                etaText.textContent = 'Saving...';
                appendModalLog('explorerUploadLogsConsole', 'Step 2/2: Upload completed. Writing files to disk and finalizing permissions...', 'step');
            }
        }
    });

    xhr.addEventListener('load', function() {
        let resData = null;
        try {
            resData = JSON.parse(xhr.responseText);
        } catch(e) {}

        if (xhr.status >= 200 && xhr.status < 300) {
            statusText.innerHTML = '<span class="text-emerald-600 font-semibold">Upload complete! Refreshing...</span>';
            progressBar.classList.remove('bg-indigo-600');
            progressBar.classList.add('bg-emerald-600');
            const count = (resData && resData.count) ? resData.count : fileInput.files.length;
            appendModalLog('explorerUploadLogsConsole', `Successfully saved ${count} file(s) to directory!`, 'success');
            appendModalLog('explorerUploadLogsConsole', 'Refreshing file explorer list...', 'info');
            setTimeout(() => {
                window.location.reload();
            }, 700);
        } else {
            statusText.innerHTML = '<span class="text-red-600 font-semibold">Upload error occurred.</span>';
            appendModalLog('explorerUploadLogsConsole', 'Upload failed: Server returned HTTP ' + xhr.status, 'error');
            submitBtn.disabled = false;
            submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            cancelBtn.style.display = '';
        }
    });

    xhr.addEventListener('error', function() {
        statusText.innerHTML = '<span class="text-red-600 font-semibold">Network connection failed.</span>';
        appendModalLog('explorerUploadLogsConsole', 'Network error or connection timed out.', 'error');
        submitBtn.disabled = false;
        submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        cancelBtn.style.display = '';
    });

    const explorerTargetUrl = form.getAttribute('action') || (window.location.pathname + window.location.search);
    xhr.open('POST', explorerTargetUrl, true);
    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    xhr.send(formData);
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
