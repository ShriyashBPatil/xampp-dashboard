<?php
require_once __DIR__ . '/includes/core.php';
require_permission('can_system_settings', 'Access denied. You do not have permission to view or modify Universal Settings.');

$currentUser = current_user();
$isAdmin = is_admin();
$isGuest = is_guest();
$authPdo = get_db_connection(AUTH_DB);

// Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'save_settings') {
        if ($isGuest) {
            echo json_encode(['success' => false, 'error' => 'Modifications are disabled in Guest Mode.']);
            exit;
        }

        $fields = [
            'workspace_title'     => trim($_POST['workspace_title'] ?? 'Shriyash Patil'),
            'workspace_subtitle'  => trim($_POST['workspace_subtitle'] ?? 'Workspace Suite • Apache • MariaDB'),
            'server_domain'       => trim($_POST['server_domain'] ?? 'server.shriyashpatil.in:8181'),
            'default_remote_path' => trim($_POST['default_remote_path'] ?? '/'),
            'allow_guest_mode'    => isset($_POST['allow_guest_mode']) ? '1' : '0',
            'debug_mode'          => isset($_POST['debug_mode']) ? '1' : '0',
            'auto_patch_db'       => isset($_POST['auto_patch_db']) ? '1' : '0',
            'exclude_git'         => isset($_POST['exclude_git']) ? '1' : '0',
            'exclude_node_modules'=> isset($_POST['exclude_node_modules']) ? '1' : '0',
            'exclude_vendor'      => isset($_POST['exclude_vendor']) ? '1' : '0',
            'session_lifetime'    => (string)intval($_POST['session_lifetime'] ?? 86400),
        ];

        $res = save_system_settings($fields);
        echo json_encode($res);
        exit;
    }

    if ($action === 'reset_opcache') {
        if ($isGuest) {
            echo json_encode(['success' => false, 'error' => 'Disabled in Guest Mode.']);
            exit;
        }
        $ok = function_exists('opcache_reset') && @opcache_reset();
        echo json_encode(['success' => $ok, 'message' => $ok ? 'OPcache cleared.' : 'OPcache reset unavailable.']);
        exit;
    }

    if ($action === 'test_db_conn') {
        try {
            $testPdo = get_db_connection();
            $dbs = $testPdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
            echo json_encode(['success' => true, 'message' => 'MariaDB connected (' . count($dbs) . ' databases).']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success' => false, 'error' => 'Invalid action.']);
    exit;
}

// Current saved settings
$settings = get_system_settings();
$workspaceTitle = $settings['workspace_title'] ?? 'Shriyash Patil';
$workspaceSubtitle = $settings['workspace_subtitle'] ?? 'Workspace Suite • Apache • MariaDB';
$serverDomain = $settings['server_domain'] ?? 'server.shriyashpatil.in:8181';
$defaultRemotePath = $settings['default_remote_path'] ?? '/';
$allowGuestMode = ($settings['allow_guest_mode'] ?? '1') === '1';
$debugMode = ($settings['debug_mode'] ?? '0') === '1';
$autoPatchDb = ($settings['auto_patch_db'] ?? '1') === '1';
$excludeGit = ($settings['exclude_git'] ?? '1') === '1';
$excludeNodeModules = ($settings['exclude_node_modules'] ?? '1') === '1';
$excludeVendor = ($settings['exclude_vendor'] ?? '0') === '1';
$sessionLifetime = $settings['session_lifetime'] ?? '86400';

$pageTitle = 'Settings - ' . $workspaceTitle;
$activeNav = 'settings';
require_once __DIR__ . '/includes/header.php';
?>

<div class="max-w-4xl mx-auto space-y-6">

    <!-- Minimal Header -->
    <div class="flex items-center justify-between pb-4 border-b border-slate-200">
        <div>
            <h1 class="text-lg font-bold text-slate-900">Settings</h1>
            <p class="text-xs text-slate-500">Manage workspace preferences, deployments, and server defaults.</p>
        </div>
        <button type="button" onclick="saveAllSettings()" id="saveBtnTop" class="btn btn-primary text-xs py-1.5 px-3.5">
            <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            Save Changes
        </button>
    </div>

    <!-- Alert / Toast -->
    <div id="settingsToast" class="hidden p-3 rounded-lg border text-xs font-medium transition-all"></div>

    <form id="settingsForm" onsubmit="event.preventDefault(); saveAllSettings();" class="space-y-6">

        <!-- Section 1: Workspace & General -->
        <div class="bg-white border border-slate-200 rounded-xl p-5 space-y-4">
            <div class="text-xs font-bold text-slate-900 uppercase tracking-wider">General</div>
            
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1">Workspace Title</label>
                    <input type="text" name="workspace_title" value="<?= htmlspecialchars($workspaceTitle) ?>" class="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 outline-none focus:border-slate-800" placeholder="Shriyash Patil">
                </div>

                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1">Server Domain / Host</label>
                    <input type="text" name="server_domain" value="<?= htmlspecialchars($serverDomain) ?>" class="w-full text-xs font-mono border border-slate-300 rounded-lg px-3 py-2 outline-none focus:border-slate-800" placeholder="server.shriyashpatil.in:8181">
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-slate-700 mb-1">Subtitle / Tagline</label>
                <input type="text" name="workspace_subtitle" value="<?= htmlspecialchars($workspaceSubtitle) ?>" class="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 outline-none focus:border-slate-800" placeholder="Workspace Suite • Apache • MariaDB">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pt-2">
                <div>
                    <label class="block text-xs font-medium text-slate-700 mb-1">Session Timeout</label>
                    <select name="session_lifetime" class="w-full text-xs border border-slate-300 rounded-lg px-3 py-2 bg-white outline-none">
                        <option value="3600" <?= $sessionLifetime === '3600' ? 'selected' : '' ?>>1 Hour</option>
                        <option value="86400" <?= $sessionLifetime === '86400' ? 'selected' : '' ?>>24 Hours (Default)</option>
                        <option value="604800" <?= $sessionLifetime === '604800' ? 'selected' : '' ?>>7 Days</option>
                        <option value="2592000" <?= $sessionLifetime === '2592000' ? 'selected' : '' ?>>30 Days</option>
                    </select>
                </div>

                <div class="flex items-center justify-between pt-5 px-3 rounded-lg border border-slate-100 bg-slate-50/70">
                    <span class="text-xs font-medium text-slate-700">Allow Guest Preview</span>
                    <input type="checkbox" name="allow_guest_mode" value="1" <?= $allowGuestMode ? 'checked' : '' ?> class="w-4 h-4 text-slate-900 rounded border-slate-300 focus:ring-slate-900 cursor-pointer">
                </div>
            </div>
        </div>

        <!-- Section 2: Deployment & FTP -->
        <div class="bg-white border border-slate-200 rounded-xl p-5 space-y-4">
            <div class="text-xs font-bold text-slate-900 uppercase tracking-wider">Deployment & FTP Sync</div>

            <div>
                <label class="block text-xs font-medium text-slate-700 mb-1">Default Remote Target Path</label>
                <input type="text" name="default_remote_path" value="<?= htmlspecialchars($defaultRemotePath) ?>" class="w-full text-xs font-mono border border-slate-300 rounded-lg px-3 py-2 outline-none focus:border-slate-800" placeholder="/">
                <span class="text-[11px] text-slate-400">Root folder on the shared hosting server (e.g. <code>/</code>, <code>/public_html</code>).</span>
            </div>

            <div class="text-xs font-medium text-slate-700 pt-1">Default Exclusions</div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <label class="flex items-center gap-2 p-2.5 rounded-lg border border-slate-100 bg-slate-50/70 cursor-pointer">
                    <input type="checkbox" name="exclude_git" value="1" <?= $excludeGit ? 'checked' : '' ?> class="w-4 h-4 text-slate-900 rounded border-slate-300">
                    <span class="text-xs text-slate-700"><code>.git/</code> trees</span>
                </label>
                <label class="flex items-center gap-2 p-2.5 rounded-lg border border-slate-100 bg-slate-50/70 cursor-pointer">
                    <input type="checkbox" name="exclude_node_modules" value="1" <?= $excludeNodeModules ? 'checked' : '' ?> class="w-4 h-4 text-slate-900 rounded border-slate-300">
                    <span class="text-xs text-slate-700"><code>node_modules/</code></span>
                </label>
                <label class="flex items-center gap-2 p-2.5 rounded-lg border border-slate-100 bg-slate-50/70 cursor-pointer">
                    <input type="checkbox" name="exclude_vendor" value="1" <?= $excludeVendor ? 'checked' : '' ?> class="w-4 h-4 text-slate-900 rounded border-slate-300">
                    <span class="text-xs text-slate-700"><code>vendor/</code></span>
                </label>
            </div>
        </div>

        <!-- Section 3: Runtime Quick Actions & System Info -->
        <div class="bg-white border border-slate-200 rounded-xl p-5 space-y-4">
            <div class="text-xs font-bold text-slate-900 uppercase tracking-wider">System & Runtime</div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-center">
                <div class="p-3 bg-slate-50 rounded-lg border border-slate-100">
                    <div class="text-[10px] text-slate-400 uppercase font-semibold">PHP Version</div>
                    <div class="text-xs font-bold font-mono text-slate-800 mt-0.5">v<?= phpversion() ?></div>
                </div>
                <div class="p-3 bg-slate-50 rounded-lg border border-slate-100">
                    <div class="text-[10px] text-slate-400 uppercase font-semibold">Memory Limit</div>
                    <div class="text-xs font-bold font-mono text-slate-800 mt-0.5"><?= ini_get('memory_limit') ?></div>
                </div>
                <div class="p-3 bg-slate-50 rounded-lg border border-slate-100">
                    <div class="text-[10px] text-slate-400 uppercase font-semibold">Upload Max</div>
                    <div class="text-xs font-bold font-mono text-slate-800 mt-0.5"><?= ini_get('upload_max_filesize') ?></div>
                </div>
                <div class="p-3 bg-slate-50 rounded-lg border border-slate-100">
                    <div class="text-[10px] text-slate-400 uppercase font-semibold">Max Execution</div>
                    <div class="text-xs font-bold font-mono text-slate-800 mt-0.5"><?= ini_get('max_execution_time') ?>s</div>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3 pt-2">
                <button type="button" onclick="resetOpCache()" class="btn btn-outline text-xs py-1.5 px-3">
                    <svg class="w-3.5 h-3.5 mr-1 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Clear OPcache
                </button>
                <button type="button" onclick="testDatabase()" class="btn btn-outline text-xs py-1.5 px-3">
                    <svg class="w-3.5 h-3.5 mr-1 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Test MariaDB
                </button>
            </div>
        </div>

        <!-- Bottom Save Button -->
        <div class="flex items-center justify-end pt-2">
            <button type="button" onclick="saveAllSettings()" class="btn btn-primary text-xs py-2 px-5">
                <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                Save Settings
            </button>
        </div>
    </form>
</div>

<script>
function showToast(msg, isSuccess = true) {
    const toast = document.getElementById('settingsToast');
    if (!toast) return;
    toast.className = `p-3 rounded-lg border text-xs font-medium transition-all ${isSuccess ? 'bg-emerald-50 text-emerald-800 border-emerald-200' : 'bg-rose-50 text-rose-800 border-rose-200'}`;
    toast.innerHTML = `<div class="flex items-center gap-2"><span>${isSuccess ? '&#10003;' : '&#9888;'}</span><span>${msg}</span></div>`;
    toast.classList.remove('hidden');
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function saveAllSettings() {
    const form = document.getElementById('settingsForm');
    const formData = new FormData(form);
    formData.append('action', 'save_settings');

    fetch('/dashboard/settings.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showToast(data.message || 'Settings saved successfully.', true);
        } else {
            showToast(data.error || 'Failed to save settings.', false);
        }
    })
    .catch(err => showToast('Error: ' + err.message, false));
}

function resetOpCache() {
    const formData = new FormData();
    formData.append('action', 'reset_opcache');

    fetch('/dashboard/settings.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => showToast(data.message || (data.success ? 'OPcache cleared' : data.error), data.success))
    .catch(err => showToast(err.message, false));
}

function testDatabase() {
    const formData = new FormData();
    formData.append('action', 'test_db_conn');

    fetch('/dashboard/settings.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => showToast(data.message || data.error, data.success))
    .catch(err => showToast(err.message, false));
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
