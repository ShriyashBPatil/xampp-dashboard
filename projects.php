<?php
require_once __DIR__ . '/includes/core.php';
require_once __DIR__ . '/includes/github.php';
require_login();

// Handle Delete Project
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_project'])) {
    require_not_guest('Project deletion is disabled in Guest Mode.');
    $targetDir = rtrim($_POST['delete_project'], '/');
    $dropDb = isset($_POST['drop_associated_db']) ? trim($_POST['db_name_to_drop'] ?? '') : null;

    if (strpos($targetDir, '..') === false && !in_array($targetDir, ['', '.', '..', 'dashboard'])) {
        $fullPath = WWW_ROOT . '/' . $targetDir;
        if (is_dir($fullPath)) {
            deleteDirectoryRecursive($fullPath);
            $msg = "Project '{$targetDir}' deleted successfully.";

            if ($dropDb && preg_match('/^[a-zA-Z0-9_]+$/', $dropDb)) {
                $systemDbs = ['information_schema', 'mysql', 'performance_schema', 'sys', AUTH_DB];
                if (!in_array($dropDb, $systemDbs)) {
                    try {
                        $rootPdo = get_db_connection();
                        $rootPdo->exec("DROP DATABASE IF EXISTS `$dropDb`");
                        $msg .= " Associated database '{$dropDb}' was also dropped.";
                    } catch (Exception $e) {}
                }
            }

            set_flash('success', $msg);
        } elseif (is_file($fullPath)) {
            unlink($fullPath);
            set_flash('success', "Script '{$targetDir}' deleted successfully.");
        }
    }
    header('Location: /dashboard/projects.php');
    exit;
}

$pageTitle = 'Projects & Scripts';
$activeNav = 'projects';
include __DIR__ . '/includes/header.php';
?>

<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Projects & Applications</h1>
        <p class="text-sm text-gray-500 mt-1">Manage all web apps, PHP applications, and standalone scripts in <code class="px-1.5 py-0.5 bg-gray-100 rounded text-xs text-gray-700">/var/www/html/</code></p>
    </div>
    <div class="flex items-center gap-2 flex-wrap">
        <a href="/dashboard/ftp.php" class="inline-flex items-center gap-2 px-3.5 py-2 bg-white border border-gray-300 rounded-lg text-sm font-medium text-indigo-700 hover:bg-indigo-50 hover:border-indigo-300 transition shadow-sm">
            <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
            <span>FTP Deploy</span>
        </a>
        <a href="/dashboard/github.php" class="inline-flex items-center gap-2 px-3.5 py-2 bg-white border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 transition shadow-sm">
            <svg class="w-4 h-4 text-gray-700" fill="currentColor" viewBox="0 0 24 24"><path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.53 1.032 1.53 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z"/></svg>
            <span>GitHub Sync</span>
        </a>
        <button onclick="openModal('uploadZipModal')" class="inline-flex items-center gap-2 px-3.5 py-2 bg-white border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 hover:border-gray-400 transition-colors shadow-sm">
            <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
            <span>Upload App</span>
        </button>
        <button onclick="openModal('autoSetupModal')" class="inline-flex items-center gap-2 px-3.5 py-2 bg-indigo-600 rounded-lg text-sm font-medium text-white hover:bg-indigo-700 transition-colors shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            <span>Auto Setup App</span>
        </button>
    </div>
</div>

<!-- Search and Filter Bar -->
<div class="bg-white border border-gray-200 rounded-xl p-3.5 shadow-sm mb-6 flex flex-col sm:flex-row gap-3 items-stretch sm:items-center justify-between">
    <div class="relative flex-1">
        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        </div>
        <input type="text" id="projectSearchInput" onkeyup="filterProjects()" placeholder="Search project name, scripts, paths..." class="w-full pl-9 pr-3 py-1.5 text-sm bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
    </div>
    <div class="text-xs text-gray-500 flex items-center gap-3 px-1">
        <span>Total Directories: <strong class="text-gray-900"><?= count($allProjects) ?></strong></span>
        <span>•</span>
        <span>Single Scripts: <strong class="text-gray-900"><?= count($standaloneScripts) ?></strong></span>
    </div>
</div>

<!-- Project Directories Grid -->
<div class="mb-8">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-base font-semibold text-gray-900 flex items-center gap-2">
            <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
            Project Folders
        </h2>
    </div>

    <?php if (empty($allProjects)): ?>
        <div class="bg-white border border-gray-200 rounded-xl p-10 text-center">
            <div class="w-12 h-12 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-3 text-gray-400">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
            </div>
            <h3 class="text-sm font-semibold text-gray-900">No project directories found</h3>
            <p class="text-xs text-gray-500 mt-1 max-w-sm mx-auto">Upload a ZIP file, drop a project folder, or run the Auto Setup wizard to deploy your first application.</p>
            <div class="mt-4">
                <button onclick="openModal('autoSetupModal')" class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 transition-colors shadow-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    Auto Setup App
                </button>
            </div>
        </div>
    <?php else: ?>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" id="projectGrid">
            <?php foreach ($allProjects as $proj): ?>
                <div class="project-card bg-white border border-gray-200 rounded-xl p-5 hover:border-gray-300 hover:shadow-sm transition flex flex-col justify-between" data-name="<?= htmlspecialchars(strtolower($proj['name'])) ?>">
                    <div>
                        <div class="flex items-start justify-between gap-2 mb-3">
                            <div class="flex items-center gap-2.5 min-w-0">
                                <div class="w-9 h-9 rounded-lg bg-gray-50 border border-gray-200 flex items-center justify-center flex-shrink-0 p-1.5 overflow-hidden shadow-xs">
                                    <?php if (!empty($proj['favicon'])): ?>
                                        <img src="<?= htmlspecialchars($proj['favicon']) ?>" alt="<?= htmlspecialchars($proj['name']) ?>" class="w-full h-full object-contain" onerror="this.onerror=null; this.parentElement.className='w-9 h-9 rounded-lg bg-indigo-50 border border-indigo-100 flex items-center justify-center text-indigo-600 flex-shrink-0'; this.parentElement.innerHTML='<svg class=\'w-5 h-5\' fill=\'none\' stroke=\'currentColor\' viewBox=\'0 0 24 24\'><path stroke-linecap=\'round\' stroke-linejoin=\'round\' stroke-width=\'2\' d=\'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z\'/></svg>'">
                                    <?php else: ?>
                                        <div class="w-full h-full flex items-center justify-center text-indigo-600">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="min-w-0">
                                    <h3 class="text-sm font-bold text-gray-900 truncate" title="<?= htmlspecialchars($proj['name']) ?>">
                                        <?= htmlspecialchars($proj['name']) ?>
                                    </h3>
                                    <p class="text-xs text-gray-400 font-mono truncate"><?= htmlspecialchars($proj['display_url']) ?></p>
                                </div>
                            </div>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-medium bg-emerald-50 text-emerald-700 border border-emerald-200 flex-shrink-0">
                                Active
                            </span>
                        </div>

                        <div class="grid grid-cols-2 gap-2 my-3 text-xs bg-gray-50 rounded-lg p-2.5 border border-gray-100">
                            <div>
                                <span class="text-gray-400 block text-[10px] uppercase font-semibold">Entry Point</span>
                                <span class="font-mono text-gray-700 truncate block"><?= htmlspecialchars($proj['entry']) ?></span>
                            </div>
                            <div>
                                <span class="text-gray-400 block text-[10px] uppercase font-semibold">Last Modified</span>
                                <span class="text-gray-700 truncate block"><?= $proj['updated'] ?></span>
                            </div>
                        </div>
                    </div>

                    <div class="pt-3 border-t border-gray-100 flex items-center justify-between gap-2">
                        <?php 
                            $gitInfo = get_project_git_info($proj['name']);
                            $repoUrlDisplay = $gitInfo['display_remote_url'] ?? '';
                        ?>
                        <div class="flex items-center gap-1.5 min-w-0">
                            <a href="/dashboard/explorer.php?dir=<?= urlencode($proj['name']) ?>" class="inline-flex items-center gap-1.5 px-2.5 py-1.5 bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-200/70 rounded-lg text-xs font-semibold transition flex-shrink-0" title="Open in File Explorer">
                                <svg class="w-3.5 h-3.5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                                <span>Explorer</span>
                            </a>
                            <a href="/dashboard/ftp.php?project=<?= urlencode($proj['name']) ?>" class="inline-flex items-center gap-1 px-2 py-1.5 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200/80 rounded-lg text-xs font-medium transition flex-shrink-0" title="Transfer project to shared hosting via FTP">
                                <svg class="w-3.5 h-3.5 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                                <span>FTP</span>
                            </a>
                            <?php if (!empty($repoUrlDisplay)): ?>
                                <a href="<?= htmlspecialchars(preg_replace('/\.git\/?$/i', '', $repoUrlDisplay)) ?>" target="_blank" class="inline-flex items-center gap-1 px-2 py-1.5 bg-gray-50 hover:bg-gray-100 text-gray-700 border border-gray-200 rounded-lg text-xs font-medium transition truncate" title="View Repository on GitHub">
                                    <svg class="w-3.5 h-3.5 text-gray-600 flex-shrink-0" fill="currentColor" viewBox="0 0 24 24"><path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.53 1.032 1.53 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z"/></svg>
                                    <span class="truncate">GitHub</span>
                                </a>
                            <?php else: ?>
                                <a href="/dashboard/github.php" class="inline-flex items-center gap-1 px-2 py-1.5 bg-gray-50 hover:bg-indigo-50 hover:text-indigo-600 text-gray-400 border border-dashed border-gray-300 rounded-lg text-xs font-medium transition" title="Link to GitHub Repository">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                    <span>Sync</span>
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-1.5 flex-shrink-0">
                            <button type="button" onclick="openDeleteProjectModal('<?= htmlspecialchars(addslashes($proj['name'])) ?>')" class="p-1.5 text-gray-400 hover:text-red-600 rounded hover:bg-red-50 transition" title="Delete Project">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                            <a href="<?= htmlspecialchars($proj['url']) ?>" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-gray-900 hover:bg-gray-800 text-white rounded-lg text-xs font-medium transition shadow-sm">
                                <span>Launch</span>
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Standalone Scripts -->
<?php if (!empty($standaloneScripts)): ?>
    <div class="mb-8">
        <h2 class="text-base font-semibold text-gray-900 flex items-center gap-2 mb-4">
            <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            Root Standalone Scripts
        </h2>
        <div class="bg-white border border-gray-200 rounded-xl divide-y divide-gray-100 overflow-hidden shadow-sm">
            <?php foreach ($standaloneScripts as $script): ?>
                <div class="p-4 flex items-center justify-between hover:bg-gray-50 transition">
                    <div class="flex items-center gap-3">
                        <div class="w-8 h-8 rounded-lg bg-gray-100 flex items-center justify-center text-gray-500">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </div>
                        <div>
                            <span class="text-sm font-medium text-gray-900 font-mono"><?= htmlspecialchars($script['name']) ?></span>
                            <span class="text-xs text-gray-400 block font-mono"><?= htmlspecialchars($script['display_url']) ?></span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="openDeleteProjectModal('<?= htmlspecialchars(addslashes($script['name'])) ?>')" class="p-1.5 text-gray-400 hover:text-red-600 rounded hover:bg-red-50 transition" title="Delete Script">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                        <a href="/dashboard/ftp.php?project=<?= urlencode($script['name']) ?>" class="p-1.5 text-indigo-600 hover:text-indigo-900 rounded hover:bg-indigo-50 transition" title="Transfer Script via FTP">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                        </a>
                        <a href="/dashboard/explorer.php?file=<?= urlencode($script['name']) ?>" class="p-1.5 text-gray-500 hover:text-gray-900 rounded hover:bg-gray-100 transition" title="Edit Script">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                        </a>
                        <a href="<?= htmlspecialchars($script['url']) ?>" target="_blank" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-lg text-xs font-medium transition">
                            <span>Open</span>
                            <svg class="w-3 h-3 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<script>
function filterProjects() {
    const term = document.getElementById('projectSearchInput').value.toLowerCase().trim();
    const cards = document.querySelectorAll('.project-card');
    cards.forEach(card => {
        const name = card.getAttribute('data-name') || '';
        if (name.includes(term)) {
            card.style.display = '';
        } else {
            card.style.display = 'none';
        }
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
