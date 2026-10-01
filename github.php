<?php
require_once __DIR__ . '/includes/core.php';
require_once __DIR__ . '/includes/github.php';
require_permission('can_github_sync', 'Access denied. You do not have permission to use GitHub Sync.');

$pageTitle = 'GitHub Sync';
$activeNav = 'github';

$settings = get_github_settings();
$isGuest = is_guest();

// Handle Form Submissions & AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // AJAX: Single Project Sync
    if ($action === 'ajax_sync_project') {
        header('Content-Type: application/json');
        if ($isGuest) {
            echo json_encode(['success' => false, 'error' => 'Sync is disabled in Guest Mode.']);
            exit;
        }

        $projectName = trim($_POST['project_name'] ?? '');
        $customMsg = trim($_POST['commit_message'] ?? '');
        if (empty($projectName)) {
            echo json_encode(['success' => false, 'error' => 'Project name is required.']);
            exit;
        }

        $res = sync_project_to_github($projectName, $customMsg);
        echo json_encode($res);
        exit;
    }

    // AJAX: Toggle Global Auto-Sync & Interval
    if ($action === 'ajax_toggle_global_auto_sync') {
        header('Content-Type: application/json');
        if ($isGuest) {
            echo json_encode(['success' => false, 'error' => 'Disabled in Guest Mode.']);
            exit;
        }
        $enabled = isset($_POST['enabled']) && $_POST['enabled'] == '1' ? 1 : 0;
        $interval = trim($_POST['interval'] ?? $settings['auto_sync_interval'] ?? 'hourly');
        $validIntervals = ['every_15min', 'every_30min', 'hourly', 'every_6hours', 'daily'];
        if (!in_array($interval, $validIntervals)) {
            $interval = 'hourly';
        }
        
        $settings['auto_sync_enabled'] = $enabled;
        $settings['auto_sync_interval'] = $interval;
        $ok = save_github_settings($settings);
        
        echo json_encode([
            'success' => $ok, 
            'enabled' => $enabled, 
            'interval' => $interval,
            'message' => $enabled ? "Automated Sync activated ({$interval})." : "Automated Sync paused."
        ]);
        exit;
    }

    // AJAX: Toggle Single Project Auto-Sync
    if ($action === 'ajax_toggle_project_sync') {
        header('Content-Type: application/json');
        if ($isGuest) {
            echo json_encode(['success' => false, 'error' => 'Disabled in Guest Mode.']);
            exit;
        }
        $projectName = trim($_POST['project_name'] ?? '');
        $enabled = isset($_POST['enabled']) && $_POST['enabled'] == '1' ? 1 : 0;
        if (empty($projectName)) {
            echo json_encode(['success' => false, 'error' => 'Project name required.']);
            exit;
        }
        
        $ok = toggle_project_sync($projectName, $enabled);
        echo json_encode([
            'success' => $ok,
            'project' => $projectName,
            'enabled' => $enabled,
            'message' => $enabled ? "Auto-sync enabled for '{$projectName}'." : "Auto-sync paused for '{$projectName}'."
        ]);
        exit;
    }

    // AJAX: Auto-Create All Missing / Unlinked Repos
    if ($action === 'ajax_auto_create_missing') {
        header('Content-Type: application/json');
        if ($isGuest) {
            echo json_encode(['success' => false, 'error' => 'Action disabled in Guest Mode.']);
            exit;
        }

        $res = auto_create_unlinked_projects(true);
        $logs = [];
        $logs[] = "[" . date('H:i:s') . "] Auto-create scan completed.";
        $logs[] = "Total unlinked projects processed: " . $res['total'];
        $logs[] = "Successfully created & pushed: " . $res['created'];
        $logs[] = "Errors / throttled: " . $res['errors'];

        if (!empty($res['details'])) {
            foreach ($res['details'] as $pName => $pResult) {
                if (!empty($pResult['logs'])) {
                    foreach ($pResult['logs'] as $l) {
                        $logs[] = $l;
                    }
                }
            }
        }

        if ($res['total'] === 0) {
            echo json_encode([
                'success' => true,
                'message' => 'All existing projects already have GitHub repositories linked!',
                'logs' => $logs
            ]);
        } elseif ($res['errors'] === 0) {
            echo json_encode([
                'success' => true,
                'message' => "Successfully created and backed up {$res['created']} repository(ies) on GitHub.",
                'logs' => $logs
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'error' => "Created {$res['created']} repo(s) with {$res['errors']} issue(s).",
                'logs' => $logs
            ]);
        }
        exit;
    }

    // AJAX: Create Repo & Link
    if ($action === 'ajax_create_and_link') {
        header('Content-Type: application/json');
        if ($isGuest) {
            echo json_encode(['success' => false, 'error' => 'Action is disabled in Guest Mode.']);
            exit;
        }

        $projectName = trim($_POST['project_name'] ?? '');
        $repoName = trim($_POST['repo_name'] ?? $projectName);
        $repoDesc = trim($_POST['repo_description'] ?? "Automated backup for {$projectName}");
        $isPrivate = ($_POST['visibility'] ?? 'private') === 'private';
        $branch = trim($_POST['branch'] ?? 'main') ?: 'main';
        $includeDb = isset($_POST['include_db']) && $_POST['include_db'] == '1' ? 1 : 0;
        $dbName = trim($_POST['db_name'] ?? '');
        $initialSync = isset($_POST['initial_sync']) && $_POST['initial_sync'] == '1' ? 1 : 0;

        $logs = [];
        $logs[] = "[" . date('H:i:s') . "] Starting creation for '{$projectName}'...";

        if (empty($projectName) || !is_dir(WWW_ROOT . '/' . $projectName)) {
            echo json_encode(['success' => false, 'error' => 'Invalid project directory.', 'logs' => $logs]);
            exit;
        }

        if (empty($settings['github_token'])) {
            echo json_encode(['success' => false, 'error' => 'Please configure your GitHub Token in Settings.', 'logs' => $logs]);
            exit;
        }

        $logs[] = "[" . date('H:i:s') . "] Requesting GitHub API to create repository '{$repoName}' (" . ($isPrivate ? 'private' : 'public') . ")...";
        $createRes = create_github_repo($settings['github_token'], $repoName, $repoDesc, $isPrivate);
        if (!$createRes['success']) {
            $logs[] = "[!] GitHub API error: " . $createRes['error'];
            echo json_encode(['success' => false, 'error' => $createRes['error'], 'logs' => $logs]);
            exit;
        }

        $repoUrl = $createRes['repo']['clone_url'];
        $logs[] = "[✓] GitHub repository ready: " . $createRes['repo']['html_url'];
        $logs[] = "[" . date('H:i:s') . "] Initializing local Git repository and setting remote...";

        $linkRes = initialize_and_link_repo($projectName, $repoUrl, $branch, $includeDb, $dbName);
        if (!$linkRes['success']) {
            $logs[] = "[!] Failed to link repository: " . $linkRes['error'];
            echo json_encode(['success' => false, 'error' => $linkRes['error'], 'logs' => $logs]);
            exit;
        }
        $logs[] = "[✓] Local Git configured and linked to remote.";

        if ($initialSync) {
            $logs[] = "[" . date('H:i:s') . "] Running initial commit and pushing to GitHub (branch: {$branch})...";
            $syncRes = sync_project_to_github($projectName, "Initial project backup");
            if (!empty($syncRes['logs'])) {
                foreach ($syncRes['logs'] as $l) {
                    $logs[] = "  " . $l;
                }
            }
            if ($syncRes['success']) {
                $logs[] = "[✓] Initial commit and push completed successfully!";
                echo json_encode([
                    'success' => true,
                    'message' => "Repository '{$repoName}' created and initial backup pushed.",
                    'logs' => $logs,
                    'repo_url' => $createRes['repo']['html_url']
                ]);
            } else {
                $logs[] = "[!] Initial push encountered an issue: " . $syncRes['error'];
                echo json_encode([
                    'success' => false,
                    'error' => $syncRes['error'],
                    'logs' => $logs,
                    'repo_url' => $createRes['repo']['html_url']
                ]);
            }
        } else {
            $logs[] = "[✓] Repository linked successfully.";
            echo json_encode([
                'success' => true,
                'message' => "Repository '{$repoName}' created and connected.",
                'logs' => $logs,
                'repo_url' => $createRes['repo']['html_url']
            ]);
        }
        exit;
    }

    // AJAX: Link Existing Repo
    if ($action === 'ajax_link_existing') {
        header('Content-Type: application/json');
        if ($isGuest) {
            echo json_encode(['success' => false, 'error' => 'Action is disabled in Guest Mode.']);
            exit;
        }

        $projectName = trim($_POST['project_name'] ?? '');
        $repoUrl = trim($_POST['repo_url'] ?? '');
        $branch = trim($_POST['branch'] ?? 'main') ?: 'main';
        $includeDb = isset($_POST['include_db']) && $_POST['include_db'] == '1' ? 1 : 0;
        $dbName = trim($_POST['db_name'] ?? '');
        $pushNow = isset($_POST['push_now']) && $_POST['push_now'] == '1' ? 1 : 0;

        $logs = [];
        $logs[] = "[" . date('H:i:s') . "] Linking '{$projectName}' to repository...";

        if (empty($projectName) || !is_dir(WWW_ROOT . '/' . $projectName) || empty($repoUrl)) {
            echo json_encode(['success' => false, 'error' => 'Project name and Repository URL are required.', 'logs' => $logs]);
            exit;
        }

        $linkRes = initialize_and_link_repo($projectName, $repoUrl, $branch, $includeDb, $dbName);
        if (!$linkRes['success']) {
            $logs[] = "[!] Link error: " . $linkRes['error'];
            echo json_encode(['success' => false, 'error' => $linkRes['error'], 'logs' => $logs]);
            exit;
        }
        $logs[] = "[✓] Remote repository linked successfully.";

        if ($pushNow) {
            $logs[] = "[" . date('H:i:s') . "] Pushing code to GitHub remote...";
            $syncRes = sync_project_to_github($projectName, "Sync backup");
            if (!empty($syncRes['logs'])) {
                foreach ($syncRes['logs'] as $l) {
                    $logs[] = "  " . $l;
                }
            }
            if ($syncRes['success']) {
                $logs[] = "[✓] Successfully synced to GitHub!";
                echo json_encode([
                    'success' => true,
                    'message' => "Project linked and synced successfully.",
                    'logs' => $logs
                ]);
            } else {
                echo json_encode([
                    'success' => false,
                    'error' => $syncRes['error'],
                    'logs' => $logs
                ]);
            }
        } else {
            echo json_encode([
                'success' => true,
                'message' => "Project '{$projectName}' linked to GitHub.",
                'logs' => $logs
            ]);
        }
        exit;
    }

    // AJAX: Project Pull
    if ($action === 'ajax_pull_project') {
        header('Content-Type: application/json');
        if ($isGuest) {
            echo json_encode(['success' => false, 'error' => 'Pull is disabled in Guest Mode.']);
            exit;
        }

        $projectName = trim($_POST['project_name'] ?? '');
        if (empty($projectName)) {
            echo json_encode(['success' => false, 'error' => 'Project name is required.']);
            exit;
        }

        $res = pull_project_from_github($projectName);
        echo json_encode($res);
        exit;
    }

    // Save Settings
    if ($action === 'save_settings') {
        require_not_guest();
        $token = trim($_POST['github_token'] ?? '');
        $committerName = trim($_POST['git_committer_name'] ?? 'XAMPP Dashboard');
        $committerEmail = trim($_POST['git_committer_email'] ?? 'admin@workspace.local');
        $visibility = in_array($_POST['default_visibility'] ?? '', ['public', 'private']) ? $_POST['default_visibility'] : 'private';
        $autoSync = isset($_POST['auto_sync_enabled']) ? 1 : 0;
        $autoSyncInterval = in_array($_POST['auto_sync_interval'] ?? '', ['every_15min', 'every_30min', 'hourly', 'every_6hours', 'daily']) ? $_POST['auto_sync_interval'] : 'hourly';
        $autoCreateMissing = isset($_POST['auto_create_missing']) ? 1 : 0;
        $includeDb = isset($_POST['include_db_backup']) ? 1 : 0;

        $username = $settings['github_username'] ?? '';
        $avatar = $settings['github_avatar'] ?? '';

        if (!empty($token)) {
            $verify = verify_github_token($token);
            if ($verify['success']) {
                $username = $verify['user']['login'];
                $avatar = $verify['user']['avatar_url'];
                set_flash('success', "Connected as @{$username}.");
            } else {
                set_flash('error', "Token error: " . $verify['error']);
            }
        } else {
            $username = '';
            $avatar = '';
            set_flash('success', "GitHub settings updated.");
        }

        save_github_settings([
            'github_token' => $token,
            'github_username' => $username,
            'github_avatar' => $avatar,
            'default_visibility' => $visibility,
            'git_committer_name' => $committerName,
            'git_committer_email' => $committerEmail,
            'auto_sync_enabled' => $autoSync,
            'auto_sync_interval' => $autoSyncInterval,
            'auto_create_missing' => $autoCreateMissing,
            'include_db_backup' => $includeDb
        ]);

        header('Location: /dashboard/github.php');
        exit;
    }

    // Create New Repo & Link
    if ($action === 'create_and_link') {
        require_not_guest();
        $projectName = trim($_POST['project_name'] ?? '');
        $repoName = trim($_POST['repo_name'] ?? $projectName);
        $repoDesc = trim($_POST['repo_description'] ?? "Automated backup for {$projectName}");
        $isPrivate = ($_POST['visibility'] ?? 'private') === 'private';
        $branch = trim($_POST['branch'] ?? 'main') ?: 'main';
        $includeDb = isset($_POST['include_db']) ? 1 : 0;
        $dbName = trim($_POST['db_name'] ?? '');
        $initialSync = isset($_POST['initial_sync']) ? 1 : 0;

        if (empty($projectName) || !is_dir(WWW_ROOT . '/' . $projectName)) {
            set_flash('error', 'Invalid project.');
            header('Location: /dashboard/github.php');
            exit;
        }

        if (empty($settings['github_token'])) {
            set_flash('error', 'Please configure your GitHub Token first.');
            header('Location: /dashboard/github.php');
            exit;
        }

        $createRes = create_github_repo($settings['github_token'], $repoName, $repoDesc, $isPrivate);
        if (!$createRes['success']) {
            set_flash('error', "GitHub API error: " . $createRes['error']);
            header('Location: /dashboard/github.php');
            exit;
        }

        $repoUrl = $createRes['repo']['clone_url'];
        $linkRes = initialize_and_link_repo($projectName, $repoUrl, $branch, $includeDb, $dbName);
        if (!$linkRes['success']) {
            set_flash('error', "Failed to link repo: " . $linkRes['error']);
            header('Location: /dashboard/github.php');
            exit;
        }

        if ($initialSync) {
            $syncRes = sync_project_to_github($projectName, "Initial project backup");
            if ($syncRes['success']) {
                set_flash('success', "Repo '{$repoName}' created and initial backup pushed.");
            } else {
                set_flash('warning', "Repo created, but initial push had issues: " . $syncRes['error']);
            }
        } else {
            set_flash('success', "Repo '{$repoName}' created and linked.");
        }

        header('Location: /dashboard/github.php');
        exit;
    }

    // Link Existing Repository
    if ($action === 'link_existing') {
        require_not_guest();
        $projectName = trim($_POST['project_name'] ?? '');
        $repoUrl = trim($_POST['repo_url'] ?? '');
        $branch = trim($_POST['branch'] ?? 'main') ?: 'main';
        $includeDb = isset($_POST['include_db']) ? 1 : 0;
        $dbName = trim($_POST['db_name'] ?? '');
        $pushNow = isset($_POST['push_now']) ? 1 : 0;

        if (empty($projectName) || !is_dir(WWW_ROOT . '/' . $projectName) || empty($repoUrl)) {
            set_flash('error', 'Project name and Repository URL are required.');
            header('Location: /dashboard/github.php');
            exit;
        }

        $linkRes = initialize_and_link_repo($projectName, $repoUrl, $branch, $includeDb, $dbName);
        if (!$linkRes['success']) {
            set_flash('error', "Failed to link repository: " . $linkRes['error']);
            header('Location: /dashboard/github.php');
            exit;
        }

        if ($pushNow) {
            $syncRes = sync_project_to_github($projectName, "Sync backup");
            if ($syncRes['success']) {
                set_flash('success', "Project linked and synced successfully.");
            } else {
                set_flash('warning', "Linked, but sync failed: " . $syncRes['error']);
            }
        } else {
            set_flash('success', "Project '{$projectName}' linked to GitHub.");
        }

        header('Location: /dashboard/github.php');
        exit;
    }

    // Bulk Sync All Projects
    if ($action === 'sync_all') {
        require_not_guest();
        $res = sync_all_projects_to_github();
        if ($res['total'] === 0) {
            set_flash('warning', 'No configured repositories found.');
        } elseif ($res['errors'] === 0) {
            set_flash('success', "All {$res['synced']} project(s) synced successfully.");
        } else {
            set_flash('warning', "Synced {$res['synced']} project(s) with {$res['errors']} issue(s).");
        }
        header('Location: /dashboard/github.php');
        exit;
    }

    // Unlink Repository
    if ($action === 'unlink_repo') {
        require_not_guest();
        $projectName = trim($_POST['project_name'] ?? '');
        if (!empty($projectName)) {
            delete_project_repo_record($projectName);
            set_flash('success', "Repository unlinked from '{$projectName}'.");
        }
        header('Location: /dashboard/github.php');
        exit;
    }
}

// Reload settings & scan projects
$settings = get_github_settings();
$projectList = [];
$totalProjects = 0;
$linkedProjects = 0;

foreach ($allProjects as $proj) {
    $info = get_project_git_info($proj['name']);
    $projMerged = array_merge($proj, $info);
    $projectList[] = $projMerged;
    $totalProjects++;
    if ($projMerged['is_configured']) {
        $linkedProjects++;
    }
}
$unlinkedProjects = max(0, $totalProjects - $linkedProjects);

include __DIR__ . '/includes/header.php';
?>

<!-- Minimal Header -->
<div class="mb-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div class="flex items-center gap-3">
        <h1 class="text-xl font-bold text-gray-900 tracking-tight flex items-center gap-2">
            <svg class="w-5 h-5 text-gray-900" fill="currentColor" viewBox="0 0 24 24">
                <path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.53 1.032 1.53 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z"/>
            </svg>
            <span>GitHub Sync</span>
        </h1>
        <?php if (!empty($settings['github_username'])): ?>
            <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                @<?= htmlspecialchars($settings['github_username']) ?>
            </span>
        <?php else: ?>
            <button onclick="openModal('githubSettingsModal')" class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700 border border-amber-200 hover:bg-amber-100 transition">
                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                Add Token
            </button>
        <?php endif; ?>
    </div>

    <div class="flex items-center flex-wrap gap-2">
        <!-- Auto-Sync Cron Live Toggle Card -->
        <div class="flex items-center gap-2 bg-white border border-gray-200 rounded-xl px-2.5 py-1.5 shadow-sm">
            <label class="switch" title="Toggle Automated Cron Sync">
                <input type="checkbox" id="globalAutoSyncToggle" onchange="toggleGlobalAutoSync(this.checked)" <?= !empty($settings['auto_sync_enabled']) ? 'checked' : '' ?>>
                <span class="switch-slider"></span>
            </label>
            <span class="text-xs font-semibold text-gray-700 select-none" id="globalAutoSyncStatusLabel"><?= !empty($settings['auto_sync_enabled']) ? 'Auto-Sync ON' : 'Auto-Sync OFF' ?></span>

            <div class="h-3.5 w-px bg-gray-200"></div>
            <select id="globalAutoSyncInterval" onchange="changeGlobalInterval(this.value)" class="text-[11px] font-medium text-gray-700 bg-gray-50 hover:bg-gray-100 border border-gray-200 rounded px-1.5 py-0.5 focus:outline-none cursor-pointer" title="Auto-Sync Frequency">
                <option value="every_15min" <?= (($settings['auto_sync_interval'] ?? '') === 'every_15min') ? 'selected' : '' ?>>Every 15m</option>
                <option value="every_30min" <?= (($settings['auto_sync_interval'] ?? '') === 'every_30min') ? 'selected' : '' ?>>Every 30m</option>
                <option value="hourly" <?= (($settings['auto_sync_interval'] ?? 'hourly') === 'hourly') ? 'selected' : '' ?>>Hourly</option>
                <option value="every_6hours" <?= (($settings['auto_sync_interval'] ?? '') === 'every_6hours') ? 'selected' : '' ?>>Every 6h</option>
                <option value="daily" <?= (($settings['auto_sync_interval'] ?? '') === 'daily') ? 'selected' : '' ?>>Daily</option>
            </select>
        </div>

        <?php if ($unlinkedProjects > 0): ?>
            <button type="button" onclick="triggerAutoCreateMissing()" class="btn btn-primary bg-indigo-600 hover:bg-indigo-700 text-white shadow-sm flex items-center gap-1.5" title="Automatically create GitHub repositories for all unlinked projects">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                <span>Auto-Create All (<?= $unlinkedProjects ?>)</span>
            </button>
        <?php endif; ?>

        <button onclick="openModal('githubSettingsModal')" class="btn btn-outline" title="GitHub Configuration">
            <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            <span>Settings</span>
        </button>
        <button onclick="openModal('linkRepoModal')" class="btn btn-outline" title="Connect Existing GitHub Repository">
            <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
            <span>Link URL</span>
        </button>
        <form method="POST" action="/dashboard/github.php" class="inline-block" onsubmit="return confirm('Sync all project repositories to GitHub now?');">
            <input type="hidden" name="action" value="sync_all">
            <button type="submit" class="btn btn-primary">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                <span>Sync All</span>
            </button>
        </form>
    </div>
</div>

<!-- Minimal Search and Filter -->
<div class="bg-white border border-gray-200 rounded-xl p-3 shadow-sm mb-4 flex items-center justify-between gap-3">
    <div class="relative flex-1">
        <input type="text" id="repoFilterInput" onkeyup="filterRepoTable()" placeholder="Search project or repository..." class="w-full pl-8 pr-3 py-1.5 text-xs bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:outline-none focus:ring-1 focus:ring-indigo-500">
        <svg class="w-3.5 h-3.5 text-gray-400 absolute left-2.5 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
    </div>
    <div class="text-xs text-gray-500 flex-shrink-0 font-medium flex items-center gap-2">
        <span>Linked: <strong class="text-gray-900"><?= $linkedProjects ?></strong> / <?= $totalProjects ?></span>
        <?php if ($unlinkedProjects > 0): ?>
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold bg-amber-50 text-amber-700 border border-amber-200">
                <?= $unlinkedProjects ?> Unlinked
            </span>
        <?php endif; ?>
    </div>
</div>

<!-- Minimal Project Sync Table -->
<div class="bg-white border border-gray-200 rounded-xl shadow-sm overflow-hidden mb-8">
    <div class="overflow-x-auto">
        <table class="w-full text-left border-collapse" id="repoTable">
            <thead>
                <tr class="bg-gray-50/70 border-b border-gray-200 text-[11px] font-semibold text-gray-500 uppercase tracking-wider">
                    <th class="py-2.5 px-4">Project</th>
                    <th class="py-2.5 px-4">GitHub Repository</th>
                    <th class="py-2.5 px-4">Status & Branch</th>
                    <th class="py-2.5 px-4 text-center">Auto-Sync</th>
                    <th class="py-2.5 px-4">DB Backup</th>
                    <th class="py-2.5 px-4">Last Sync</th>
                    <th class="py-2.5 px-4 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 text-xs">
                <?php if (empty($projectList)): ?>
                    <tr>
                        <td colspan="7" class="py-8 text-center text-gray-400">
                            No project directories found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($projectList as $p): ?>
                        <tr class="hover:bg-gray-50/50 transition repo-row" data-name="<?= htmlspecialchars(strtolower($p['name'] . ' ' . ($p['display_remote_url'] ?? ''))) ?>">
                            <!-- Project Name -->
                            <td class="py-2.5 px-4 font-semibold text-gray-900">
                                <?= htmlspecialchars($p['name']) ?>
                            </td>

                            <!-- GitHub Repo Remote -->
                            <td class="py-2.5 px-4">
                                <?php if (!empty($p['display_remote_url'])): ?>
                                    <?php $cleanWebUrl = preg_replace('/\.git\/?$/i', '', $p['display_remote_url']); ?>
                                    <a href="<?= htmlspecialchars($cleanWebUrl) ?>" target="_blank" class="inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-800 font-mono text-[11px] hover:underline">
                                        <span><?= htmlspecialchars(preg_replace('#^https://github\.com/#', '', $cleanWebUrl)) ?></span>
                                        <svg class="w-3 h-3 opacity-60" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                    </a>
                                <?php else: ?>
                                    <span class="text-gray-400 text-[11px] italic">Not linked</span>
                                <?php endif; ?>
                            </td>

                            <!-- Branch & Status -->
                            <td class="py-2.5 px-4">
                                <?php if ($p['is_git']): ?>
                                    <div class="flex items-center gap-1.5">
                                        <span class="font-mono text-gray-700 bg-gray-100 px-1.5 py-0.5 rounded text-[10px] font-medium">
                                            <?= htmlspecialchars($p['branch']) ?>
                                        </span>
                                        <?php if ($p['has_uncommitted']): ?>
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-amber-50 text-amber-700 border border-amber-200">
                                                <?= $p['uncommitted_count'] ?> uncommitted
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                Clean
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="text-gray-400 text-[11px]">&mdash;</span>
                                <?php endif; ?>
                            </td>

                            <!-- Project Auto-Sync Toggle -->
                            <td class="py-2.5 px-4 text-center">
                                <?php if ($p['is_configured']): ?>
                                    <?php $isSyncOn = !isset($p['record']['is_sync_enabled']) || !empty($p['record']['is_sync_enabled']); ?>
                                    <label class="switch switch-sm switch-indigo" title="<?= $isSyncOn ? 'Auto-sync active for this project' : 'Auto-sync paused for this project' ?>">
                                        <input type="checkbox" onchange="toggleProjectSync('<?= htmlspecialchars(addslashes($p['name'])) ?>', this.checked, this)" <?= $isSyncOn ? 'checked' : '' ?>>
                                        <span class="switch-slider"></span>
                                    </label>
                                <?php else: ?>
                                    <span class="text-gray-400 text-[11px]">&mdash;</span>
                                <?php endif; ?>
                            </td>


                            <!-- Database Backup -->
                            <td class="py-2.5 px-4">
                                <?php
                                    $hasDbOption = !empty($p['record']['include_db']);
                                    $assignedDb = $p['record']['db_name'] ?? (in_array($p['name'], $dbList) ? $p['name'] : null);
                                ?>
                                <?php if ($assignedDb): ?>
                                    <span class="font-mono text-[11px] text-gray-700"><?= htmlspecialchars($assignedDb) ?></span>
                                    <span class="text-[10px] text-gray-400 block"><?= $hasDbOption ? 'dump: yes' : 'dump: no' ?></span>
                                <?php else: ?>
                                    <span class="text-gray-400 text-[11px]">&mdash;</span>
                                <?php endif; ?>
                            </td>

                            <!-- Last Sync -->
                            <td class="py-2.5 px-4">
                                <?php
                                    $syncStatus = $p['record']['last_sync_status'] ?? 'idle';
                                    $syncTime = !empty($p['record']['last_sync_at']) ? date('M d, H:i', strtotime($p['record']['last_sync_at'])) : null;
                                ?>
                                <?php if ($syncStatus === 'success'): ?>
                                    <span class="text-emerald-700 font-medium text-[11px]"><?= $syncTime ?: 'Synced' ?></span>
                                <?php elseif ($syncStatus === 'error'): ?>
                                    <span class="text-rose-600 font-medium text-[11px]" title="<?= htmlspecialchars($p['record']['last_sync_message'] ?? '') ?>">Failed</span>
                                <?php else: ?>
                                    <span class="text-gray-400 text-[11px]">&mdash;</span>
                                <?php endif; ?>
                            </td>

                            <!-- Actions -->
                            <td class="py-2.5 px-4 text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <?php if ($p['is_configured']): ?>
                                        <button type="button" onclick="triggerSingleSync('<?= htmlspecialchars(addslashes($p['name'])) ?>', this)" class="inline-flex items-center gap-1 px-2.5 py-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 rounded text-xs font-medium transition" title="Sync (Push)">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                            <span>Sync</span>
                                        </button>
                                        <button type="button" onclick="triggerSinglePull('<?= htmlspecialchars(addslashes($p['name'])) ?>', this)" class="p-1 text-gray-500 hover:text-gray-800 rounded hover:bg-gray-100 transition" title="Pull from GitHub">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                        </button>
                                        <button type="button" onclick="openConfigureModal('<?= htmlspecialchars(addslashes($p['name'])) ?>', '<?= htmlspecialchars(addslashes($p['display_remote_url'])) ?>', '<?= htmlspecialchars(addslashes($p['branch'])) ?>', '<?= htmlspecialchars(addslashes($assignedDb ?? '')) ?>', <?= $hasDbOption ? 1 : 0 ?>)" class="p-1 text-gray-400 hover:text-gray-700 rounded hover:bg-gray-100 transition" title="Edit Repo Settings">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                        </button>
                                    <?php else: ?>
                                        <button type="button" onclick="openCreateRepoModal('<?= htmlspecialchars(addslashes($p['name'])) ?>', '<?= htmlspecialchars(addslashes($assignedDb ?? '')) ?>')" class="inline-flex items-center gap-1 px-2.5 py-1 bg-gray-900 hover:bg-gray-800 text-white rounded text-xs font-medium transition">
                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                            <span>Create Repo</span>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL 1: GitHub Settings -->
<div id="githubSettingsModal" class="modal-backdrop">
    <div class="modal-dialog" style="max-width: 480px;">
        <div class="modal-header">
            <div class="modal-title">GitHub Settings</div>
            <button type="button" onclick="closeModal('githubSettingsModal')" class="modal-close">&times;</button>
        </div>

        <form method="POST" action="/dashboard/github.php">
            <input type="hidden" name="action" value="save_settings">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Personal Access Token (PAT)</label>
                    <input type="password" name="github_token" value="<?= htmlspecialchars($settings['github_token']) ?>" placeholder="ghp_..." class="form-input font-mono">
                    <span class="form-hint">Requires <code class="font-mono">repo</code>, <code class="font-mono">read:user</code> scopes.</span>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="form-group">
                        <label class="form-label">Default Visibility</label>
                        <select name="default_visibility" class="form-select">
                            <option value="private" <?= ($settings['default_visibility'] === 'private') ? 'selected' : '' ?>>Private</option>
                            <option value="public" <?= ($settings['default_visibility'] === 'public') ? 'selected' : '' ?>>Public</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Committer Name</label>
                        <input type="text" name="git_committer_name" value="<?= htmlspecialchars($settings['git_committer_name']) ?>" class="form-input">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Committer Email</label>
                    <input type="email" name="git_committer_email" value="<?= htmlspecialchars($settings['git_committer_email']) ?>" class="form-input">
                </div>

                <div class="border-t border-gray-100 pt-3 mt-2 space-y-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="text-xs font-semibold text-gray-900">Automated Background Sync (Cron)</div>
                            <div class="text-[11px] text-gray-500">Automatically backup MySQL and push changes on schedule</div>
                        </div>
                        <label class="switch switch-md" title="Enable automated cron sync">
                            <input type="checkbox" name="auto_sync_enabled" value="1" <?= !empty($settings['auto_sync_enabled']) ? 'checked' : '' ?>>
                            <span class="switch-slider"></span>
                        </label>
                    </div>


                    <div class="form-group">
                        <label class="form-label">Sync Frequency</label>
                        <select name="auto_sync_interval" class="form-select">
                            <option value="every_15min" <?= (($settings['auto_sync_interval'] ?? '') === 'every_15min') ? 'selected' : '' ?>>Every 15 Minutes</option>
                            <option value="every_30min" <?= (($settings['auto_sync_interval'] ?? '') === 'every_30min') ? 'selected' : '' ?>>Every 30 Minutes</option>
                            <option value="hourly" <?= (($settings['auto_sync_interval'] ?? 'hourly') === 'hourly') ? 'selected' : '' ?>>Every 1 Hour (Recommended)</option>
                            <option value="every_6hours" <?= (($settings['auto_sync_interval'] ?? '') === 'every_6hours') ? 'selected' : '' ?>>Every 6 Hours</option>
                            <option value="daily" <?= (($settings['auto_sync_interval'] ?? '') === 'daily') ? 'selected' : '' ?>>Daily (Midnight)</option>
                        </select>
                    </div>
                </div>

                <label class="flex items-center gap-2 text-xs font-medium text-gray-700 cursor-pointer pt-2 border-t border-gray-100">
                    <input type="checkbox" name="auto_create_missing" <?= !empty($settings['auto_create_missing']) ? 'checked' : '' ?> class="rounded text-indigo-600">
                    <span>Automatically create GitHub repositories for newly added projects during sync</span>
                </label>

                <label class="flex items-center gap-2 text-xs font-medium text-gray-700 cursor-pointer pt-1">
                    <input type="checkbox" name="include_db_backup" <?= !empty($settings['include_db_backup']) ? 'checked' : '' ?> class="rounded text-indigo-600">
                    <span>Automatically dump DB (<code class="font-mono">database_backup.sql</code>) on sync</span>
                </label>
            </div>

            <div class="modal-footer">
                <button type="button" onclick="closeModal('githubSettingsModal')" class="btn btn-outline">Cancel</button>
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 2: Create & Link Repo Wizard -->
<div id="createRepoModal" class="modal-backdrop">
    <div class="modal-dialog" style="max-width: 480px;">
        <div class="modal-header">
            <div class="modal-title">Create GitHub Repository</div>
            <button type="button" onclick="closeModal('createRepoModal')" class="modal-close">&times;</button>
        </div>

        <form method="POST" action="/dashboard/github.php" onsubmit="handleCreateRepoSubmit(event, this)">
            <input type="hidden" name="action" value="ajax_create_and_link">
            <input type="hidden" name="project_name" id="modalCreateProjectName">

            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Project</label>
                    <input type="text" id="modalCreateProjectDisplay" readonly class="form-input bg-gray-50 text-gray-600 font-mono">
                </div>

                <div class="form-group">
                    <label class="form-label">GitHub Repository Name</label>
                    <input type="text" name="repo_name" id="modalCreateRepoName" required class="form-input font-mono">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="form-group">
                        <label class="form-label">Visibility</label>
                        <select name="visibility" class="form-select">
                            <option value="private" selected>Private</option>
                            <option value="public">Public</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Database</label>
                        <select name="db_name" id="modalCreateDbSelect" class="form-select font-mono">
                            <option value="">None</option>
                            <?php foreach ($dbList as $db): ?>
                                <option value="<?= htmlspecialchars($db) ?>"><?= htmlspecialchars($db) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="space-y-1.5 pt-1">
                    <label class="flex items-center gap-2 text-xs font-medium text-gray-700 cursor-pointer">
                        <input type="checkbox" name="include_db" value="1" checked class="rounded text-indigo-600">
                        <span>Include MySQL database dump</span>
                    </label>
                    <label class="flex items-center gap-2 text-xs font-medium text-gray-700 cursor-pointer">
                        <input type="checkbox" name="initial_sync" value="1" checked class="rounded text-indigo-600">
                        <span>Push initial commit immediately</span>
                    </label>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" onclick="closeModal('createRepoModal')" class="btn btn-outline">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnCreateRepoSubmit">Create & Connect</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 3: Connect Existing Repo Modal -->
<div id="linkRepoModal" class="modal-backdrop">
    <div class="modal-dialog" style="max-width: 480px;">
        <div class="modal-header">
            <div class="modal-title">Connect Existing Repo</div>
            <button type="button" onclick="closeModal('linkRepoModal')" class="modal-close">&times;</button>
        </div>

        <form method="POST" action="/dashboard/github.php" onsubmit="handleLinkRepoSubmit(event, this)">
            <input type="hidden" name="action" value="ajax_link_existing">

            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Project Directory</label>
                    <select name="project_name" required class="form-select font-mono">
                        <?php foreach ($projectList as $p): ?>
                            <option value="<?= htmlspecialchars($p['name']) ?>"><?= htmlspecialchars($p['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Repository URL</label>
                    <input type="text" name="repo_url" required placeholder="https://github.com/user/repo.git" class="form-input font-mono">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="form-group">
                        <label class="form-label">Branch</label>
                        <input type="text" name="branch" value="main" class="form-input font-mono">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Database</label>
                        <select name="db_name" class="form-select font-mono">
                            <option value="">None</option>
                            <?php foreach ($dbList as $db): ?>
                                <option value="<?= htmlspecialchars($db) ?>"><?= htmlspecialchars($db) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <label class="flex items-center gap-2 text-xs font-medium text-gray-700 cursor-pointer pt-1">
                    <input type="checkbox" name="push_now" value="1" checked class="rounded text-indigo-600">
                    <span>Push code to repository immediately</span>
                </label>
            </div>

            <div class="modal-footer">
                <button type="button" onclick="closeModal('linkRepoModal')" class="btn btn-outline">Cancel</button>
                <button type="submit" class="btn btn-primary" id="btnLinkRepoSubmit">Link</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL 4: Configure / Edit Repo Settings Modal -->
<div id="configureRepoModal" class="modal-backdrop">
    <div class="modal-dialog" style="max-width: 480px;">
        <div class="modal-header">
            <div class="modal-title">Edit Repo Configuration</div>
            <button type="button" onclick="closeModal('configureRepoModal')" class="modal-close">&times;</button>
        </div>

        <form method="POST" action="/dashboard/github.php" onsubmit="handleConfigRepoSubmit(event, this)">
            <input type="hidden" name="action" value="ajax_link_existing">
            <input type="hidden" name="project_name" id="modalConfigProjectName">

            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Project</label>
                    <input type="text" id="modalConfigProjectDisplay" readonly class="form-input bg-gray-50 text-gray-600 font-mono">
                </div>

                <div class="form-group">
                    <label class="form-label">Repository URL</label>
                    <input type="text" name="repo_url" id="modalConfigRepoUrl" required class="form-input font-mono">
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="form-group">
                        <label class="form-label">Branch</label>
                        <input type="text" name="branch" id="modalConfigBranch" value="main" class="form-input font-mono">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Database</label>
                        <select name="db_name" id="modalConfigDbSelect" class="form-select font-mono">
                            <option value="">None</option>
                            <?php foreach ($dbList as $db): ?>
                                <option value="<?= htmlspecialchars($db) ?>"><?= htmlspecialchars($db) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <label class="flex items-center gap-2 text-xs font-medium text-gray-700 cursor-pointer pt-1">
                    <input type="checkbox" name="include_db" id="modalConfigIncludeDb" value="1" class="rounded text-indigo-600">
                    <span>Include database dump</span>
                </label>
            </div>

            <div class="modal-footer justify-between">
                <button type="button" onclick="confirmUnlinkProject()" class="text-xs text-red-600 hover:text-red-700 font-medium">Unlink</button>
                <div class="flex items-center gap-2">
                    <button type="button" onclick="closeModal('configureRepoModal')" class="btn btn-outline">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Unlink Form -->
<form method="POST" action="/dashboard/github.php" id="unlinkForm" style="display:none;">
    <input type="hidden" name="action" value="unlink_repo">
    <input type="hidden" name="project_name" id="unlinkProjectName">
</form>

<!-- Modern Progress & Real-time Log Modal -->
<div id="syncLogModal" class="modal-backdrop">
    <div class="modal-dialog" style="max-width: 580px;">
        <div class="modal-header">
            <div class="flex items-center gap-2.5">
                <div id="syncStatusIcon" class="w-6 h-6 rounded-full flex items-center justify-center bg-indigo-50 text-indigo-600">
                    <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                </div>
                <div>
                    <div class="modal-title" id="syncLogTitle">Processing...</div>
                    <div class="text-[11px] text-gray-500 font-normal" id="syncStageText">Initializing task...</div>
                </div>
            </div>
            <button type="button" onclick="closeModal('syncLogModal')" class="modal-close">&times;</button>
        </div>

        <div class="modal-body space-y-3">
            <!-- Progress Bar Card -->
            <div class="bg-gray-50 border border-gray-200/80 rounded-xl p-3">
                <div class="flex items-center justify-between text-xs font-semibold text-gray-700 mb-1.5">
                    <span id="progressBarLabel">Progress</span>
                    <span id="progressBarPercent" class="font-mono text-indigo-600">15%</span>
                </div>
                <div class="w-full bg-gray-200 rounded-full h-2.5 overflow-hidden">
                    <div id="syncProgressBar" class="bg-indigo-600 h-2.5 rounded-full transition-all duration-500 ease-out" style="width: 15%"></div>
                </div>
            </div>

            <!-- Terminal Output Console -->
            <div class="rounded-xl overflow-hidden border border-gray-800 bg-gray-950 shadow-inner">
                <div class="bg-gray-900/90 px-3 py-1.5 border-b border-gray-800 flex items-center justify-between">
                    <div class="flex items-center gap-1.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-red-500/80"></span>
                        <span class="w-2.5 h-2.5 rounded-full bg-amber-500/80"></span>
                        <span class="w-2.5 h-2.5 rounded-full bg-emerald-500/80"></span>
                        <span class="text-[10px] text-gray-400 font-mono ml-2">Console Output</span>
                    </div>
                    <span class="text-[10px] text-gray-500 font-mono" id="syncLogTime">Live</span>
                </div>
                <div id="syncLogContent" class="p-3.5 text-gray-200 font-mono text-[11px] min-h-[160px] max-h-[260px] overflow-y-auto whitespace-pre-wrap leading-relaxed space-y-1">
                    Initializing...
                </div>
            </div>
        </div>

        <div class="modal-footer justify-between">
            <div id="syncLogExtraAction">
                <!-- Optional View Repo link dynamically injected -->
            </div>
            <div class="flex items-center gap-2">
                <button type="button" id="btnSyncModalClose" onclick="closeModal('syncLogModal')" class="btn btn-outline">Close</button>
                <button type="button" id="btnSyncModalDone" onclick="window.location.reload()" class="btn btn-primary" style="display:none;">Done & Refresh</button>
            </div>
        </div>
    </div>
</div>

<script>
function filterRepoTable() {
    const term = document.getElementById('repoFilterInput').value.toLowerCase().trim();
    const rows = document.querySelectorAll('.repo-row');
    rows.forEach(row => {
        const name = row.getAttribute('data-name') || '';
        row.style.display = name.includes(term) ? '' : 'none';
    });
}

function openCreateRepoModal(projectName, dbName) {
    document.getElementById('modalCreateProjectName').value = projectName;
    document.getElementById('modalCreateProjectDisplay').value = projectName;
    let repoName = projectName.replace(/[^a-zA-Z0-9_\.-]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
    document.getElementById('modalCreateRepoName').value = repoName;
    if (dbName) {
        document.getElementById('modalCreateDbSelect').value = dbName;
    }
    openModal('createRepoModal');
}

function openConfigureModal(projectName, repoUrl, branch, dbName, includeDb) {
    document.getElementById('modalConfigProjectName').value = projectName;
    document.getElementById('modalConfigProjectDisplay').value = projectName;
    document.getElementById('modalConfigRepoUrl').value = repoUrl;
    document.getElementById('modalConfigBranch').value = branch || 'main';
    document.getElementById('modalConfigDbSelect').value = dbName || '';
    document.getElementById('modalConfigIncludeDb').checked = Boolean(includeDb);
    openModal('configureRepoModal');
}

function confirmUnlinkProject() {
    const proj = document.getElementById('modalConfigProjectName').value;
    if (confirm("Unlink '" + proj + "' from its GitHub repository?")) {
        document.getElementById('unlinkProjectName').value = proj;
        document.getElementById('unlinkForm').submit();
    }
}

// Progress Bar & Log Modal Helpers
let progressInterval = null;

function setProgress(percent, stageText) {
    const bar = document.getElementById('syncProgressBar');
    const label = document.getElementById('progressBarPercent');
    const stage = document.getElementById('syncStageText');
    if (bar) bar.style.width = Math.min(100, Math.max(0, percent)) + '%';
    if (label) label.innerText = Math.round(percent) + '%';
    if (stage && stageText) stage.innerText = stageText;
}

function appendLogLine(line, type = 'info') {
    const logBox = document.getElementById('syncLogContent');
    if (!logBox) return;
    
    let colorClass = 'text-gray-300';
    if (line.includes('[✓]') || line.includes('✓') || type === 'success') {
        colorClass = 'text-emerald-400 font-medium';
    } else if (line.includes('[!]') || line.includes('warning') || type === 'warning') {
        colorClass = 'text-amber-300 font-medium';
    } else if (line.includes('[✗]') || line.includes('Error') || line.includes('failed') || type === 'error') {
        colorClass = 'text-rose-400 font-medium';
    }

    const div = document.createElement('div');
    div.className = colorClass;
    div.textContent = line;
    logBox.appendChild(div);
    logBox.scrollTop = logBox.scrollHeight;
}

function openProgressModal(title, initialStage) {
    if (progressInterval) clearInterval(progressInterval);
    
    document.getElementById('syncLogTitle').innerText = title;
    document.getElementById('syncStageText').innerText = initialStage || 'Starting...';
    document.getElementById('syncLogContent').innerHTML = '';
    document.getElementById('syncLogExtraAction').innerHTML = '';
    
    const icon = document.getElementById('syncStatusIcon');
    icon.className = 'w-6 h-6 rounded-full flex items-center justify-center bg-indigo-50 text-indigo-600';
    icon.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';
    
    const bar = document.getElementById('syncProgressBar');
    bar.className = 'bg-indigo-600 h-2.5 rounded-full transition-all duration-500 ease-out';
    setProgress(15, initialStage);

    document.getElementById('btnSyncModalDone').style.display = 'none';
    document.getElementById('btnSyncModalClose').style.display = 'inline-flex';

    openModal('syncLogModal');
}

function startSimulatedProgress(stages) {
    let currentIdx = 0;
    progressInterval = setInterval(() => {
        if (currentIdx < stages.length) {
            const stage = stages[currentIdx];
            setProgress(stage.pct, stage.text);
            appendLogLine("[" + new Date().toLocaleTimeString() + "] " + stage.text);
            currentIdx++;
        }
    }, 1800);
}

function finishProgress(isSuccess, message, logs, repoUrl) {
    if (progressInterval) clearInterval(progressInterval);
    
    const icon = document.getElementById('syncStatusIcon');
    const bar = document.getElementById('syncProgressBar');
    const logBox = document.getElementById('syncLogContent');
    logBox.innerHTML = '';

    if (logs && Array.isArray(logs)) {
        logs.forEach(l => appendLogLine(l));
    }

    if (isSuccess) {
        setProgress(100, 'Completed successfully');
        icon.className = 'w-6 h-6 rounded-full flex items-center justify-center bg-emerald-100 text-emerald-600';
        icon.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>';
        bar.className = 'bg-emerald-500 h-2.5 rounded-full transition-all duration-300';
        appendLogLine('✓ ' + (message || 'Operation completed successfully!'), 'success');

        if (repoUrl) {
            document.getElementById('syncLogExtraAction').innerHTML = '<a href="' + repoUrl + '" target="_blank" class="inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800 font-medium hover:underline"><span>View Repo on GitHub &rarr;</span></a>';
        }

        document.getElementById('btnSyncModalDone').style.display = 'inline-flex';
        document.getElementById('btnSyncModalClose').style.display = 'none';
    } else {
        icon.className = 'w-6 h-6 rounded-full flex items-center justify-center bg-rose-100 text-rose-600';
        icon.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>';
        bar.className = 'bg-rose-500 h-2.5 rounded-full transition-all duration-300';
        document.getElementById('syncStageText').innerText = 'Encountered an error';
        appendLogLine('✗ ' + (message || 'An error occurred during operation.'), 'error');

        document.getElementById('btnSyncModalDone').style.display = 'inline-flex';
        document.getElementById('btnSyncModalDone').innerText = 'Reload Page';
    }
}

// Handle AJAX Create Repo
function handleCreateRepoSubmit(e, form) {
    e.preventDefault();
    const projectName = form.querySelector('[name="project_name"]').value;
    const repoName = form.querySelector('[name="repo_name"]').value;
    const initialSync = form.querySelector('[name="initial_sync"]')?.checked;

    closeModal('createRepoModal');
    openProgressModal("Creating Repository: " + repoName, "Connecting to GitHub API...");
    appendLogLine("[" + new Date().toLocaleTimeString() + "] Preparing repository creation for '" + projectName + "'...");

    startSimulatedProgress([
        { pct: 30, text: "Sending request to GitHub API..." },
        { pct: 55, text: "Initializing local Git tracking..." },
        { pct: 75, text: initialSync ? "Dumping database & committing initial backup..." : "Configuring remote origin..." },
        { pct: 90, text: initialSync ? "Pushing files to GitHub remote origin/main..." : "Finalizing configuration..." }
    ]);

    const fd = new FormData(form);
    fetch('/dashboard/github.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        finishProgress(data.success, data.success ? data.message : data.error, data.logs, data.repo_url);
    })
    .catch(err => {
        finishProgress(false, "Network error: " + err, ["Failed to communicate with server: " + err]);
    });
}

// Handle AJAX Link Repo
function handleLinkRepoSubmit(e, form) {
    e.preventDefault();
    const projectName = form.querySelector('[name="project_name"]').value;
    const pushNow = form.querySelector('[name="push_now"]')?.checked;

    closeModal('linkRepoModal');
    openProgressModal("Linking " + projectName, "Configuring remote repository...");
    appendLogLine("[" + new Date().toLocaleTimeString() + "] Linking '" + projectName + "' to GitHub remote...");

    startSimulatedProgress([
        { pct: 40, text: "Configuring Git remote URL..." },
        { pct: 70, text: pushNow ? "Dumping DB & creating backup commit..." : "Verifying remote repository..." },
        { pct: 90, text: pushNow ? "Pushing branches to GitHub..." : "Saving configuration..." }
    ]);

    const fd = new FormData(form);
    fetch('/dashboard/github.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        finishProgress(data.success, data.success ? data.message : data.error, data.logs);
    })
    .catch(err => {
        finishProgress(false, "Network error: " + err, ["Failed to link repository: " + err]);
    });
}

// Handle AJAX Config Edit
function handleConfigRepoSubmit(e, form) {
    e.preventDefault();
    const projectName = form.querySelector('[name="project_name"]').value;

    closeModal('configureRepoModal');
    openProgressModal("Updating " + projectName, "Saving configuration...");

    const fd = new FormData(form);
    fetch('/dashboard/github.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        finishProgress(data.success, data.success ? data.message : data.error, data.logs);
    })
    .catch(err => {
        finishProgress(false, "Error: " + err, ["Failed to update configuration: " + err]);
    });
}

// Live AJAX Single Sync
function triggerSingleSync(projectName, btn) {
    openProgressModal("Syncing: " + projectName, "Dumping database & committing changes...");
    appendLogLine("[" + new Date().toLocaleTimeString() + "] Starting synchronization for '" + projectName + "'...");

    startSimulatedProgress([
        { pct: 35, text: "Dumping MySQL database backup..." },
        { pct: 60, text: "Scanning working tree and creating commit..." },
        { pct: 85, text: "Pushing objects to GitHub remote origin..." }
    ]);

    const fd = new FormData();
    fd.append('action', 'ajax_sync_project');
    fd.append('project_name', projectName);

    fetch('/dashboard/github.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        finishProgress(data.success, data.success ? data.message : data.error, data.logs);
    })
    .catch(err => {
        finishProgress(false, "Sync failed: " + err, ["Error: " + err]);
    });
}

// Live AJAX Single Pull
function triggerSinglePull(projectName, btn) {
    if (!confirm("Pull latest changes for '" + projectName + "' from GitHub?")) return;

    openProgressModal("Pulling: " + projectName, "Fetching latest commits...");
    appendLogLine("[" + new Date().toLocaleTimeString() + "] Running git pull for '" + projectName + "'...");

    setProgress(50, "Fetching and merging from origin...");

    const fd = new FormData();
    fd.append('action', 'ajax_pull_project');
    fd.append('project_name', projectName);

    fetch('/dashboard/github.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        const logs = data.output ? data.output.split('\n') : [];
        finishProgress(data.success, data.success ? "Pull successful" : data.error, logs);
    })
    .catch(err => {
        finishProgress(false, "Pull failed: " + err, ["Error: " + err]);
    });
}

// Global Auto-Sync Toggle
function toggleGlobalAutoSync(enabled) {
    const statusLabel = document.getElementById('globalAutoSyncStatusLabel');
    const intervalSelect = document.getElementById('globalAutoSyncInterval');
    const interval = intervalSelect ? intervalSelect.value : 'hourly';
    
    if (statusLabel) statusLabel.innerText = enabled ? 'Saving...' : 'Saving...';

    const fd = new FormData();
    fd.append('action', 'ajax_toggle_global_auto_sync');
    fd.append('enabled', enabled ? 1 : 0);
    fd.append('interval', interval);

    fetch('/dashboard/github.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (statusLabel) {
            statusLabel.innerText = data.enabled ? 'Auto-Sync ON' : 'Auto-Sync OFF';
        }
    })
    .catch(err => {
        if (statusLabel) statusLabel.innerText = 'Sync Error';
    });
}

// Global Auto-Sync Interval Change
function changeGlobalInterval(interval) {
    const toggle = document.getElementById('globalAutoSyncToggle');
    const isEnabled = toggle ? toggle.checked : false;

    const fd = new FormData();
    fd.append('action', 'ajax_toggle_global_auto_sync');
    fd.append('enabled', isEnabled ? 1 : 0);
    fd.append('interval', interval);

    fetch('/dashboard/github.php', { method: 'POST', body: fd });
}

// Project Auto-Sync Toggle
function toggleProjectSync(projectName, enabled, checkbox) {
    const fd = new FormData();
    fd.append('action', 'ajax_toggle_project_sync');
    fd.append('project_name', projectName);
    fd.append('enabled', enabled ? 1 : 0);

    if (checkbox) checkbox.disabled = true;
    fetch('/dashboard/github.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (checkbox) {
            checkbox.disabled = false;
            checkbox.parentElement.title = data.enabled ? 'Auto-sync active for this project' : 'Auto-sync paused for this project';
        }
    })
    .catch(err => {
        if (checkbox) {
            checkbox.disabled = false;
            checkbox.checked = !enabled;
        }
    });
}

// Auto-create all missing repos
function triggerAutoCreateMissing() {
    if (!confirm("Automatically create GitHub repositories and push initial backups for all unlinked projects?")) return;

    openProgressModal("Auto-Creating Missing Repositories", "Scanning project folders...");
    appendLogLine("[" + new Date().toLocaleTimeString() + "] Starting automated repository creation for unlinked projects...");

    startSimulatedProgress([
        { pct: 20, text: "Scanning unlinked projects..." },
        { pct: 45, text: "Creating remote repositories via GitHub API..." },
        { pct: 70, text: "Initializing local Git and dumping MySQL databases..." },
        { pct: 90, text: "Committing and pushing backups to GitHub..." }
    ]);

    const fd = new FormData();
    fd.append('action', 'ajax_auto_create_missing');

    fetch('/dashboard/github.php', {
        method: 'POST',
        body: fd
    })
    .then(r => r.json())
    .then(data => {
        finishProgress(data.success, data.success ? data.message : data.error, data.logs);
    })
    .catch(err => {
        finishProgress(false, "Operation failed: " + err, ["Error: " + err]);
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
