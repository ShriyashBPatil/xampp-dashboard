<?php
// CLI and Cron Automated GitHub Sync Script for XAMPP Dashboard
if (php_sapi_name() !== 'cli' && (!isset($_GET['token']) || $_GET['token'] !== 'cron_auto_sync_secret')) {
    // Basic protection if called via HTTP
    header('HTTP/1.0 403 Forbidden');
    echo "Forbidden: CLI access only.\n";
    exit;
}

require_once __DIR__ . '/includes/core.php';
require_once __DIR__ . '/includes/github.php';

echo "====================================================\n";
echo " XAMPP Dashboard - Automated GitHub Sync Utility\n";
echo " Time: " . date('Y-m-d H:i:s') . "\n";
echo "====================================================\n\n";

$settings = get_github_settings();
$isForce = in_array('--force', $argv ?? []) || isset($_GET['force']);

if (empty($settings['auto_sync_enabled']) && !$isForce) {
    echo "[!] Notice: Automated GitHub Sync is currently DISABLED (Paused) in Dashboard Settings.\n";
    echo "    To run anyway, use: php github-sync-cron.php --force\n";
    exit(0);
}

if (empty($settings['github_token'])) {
    echo "[!] Warning: GitHub Personal Access Token is not configured. Sync may fail for private repositories.\n";
}

echo "[*] Auto-Sync Interval Setting: " . htmlspecialchars($settings['auto_sync_interval'] ?? 'hourly') . "\n";
echo "[*] Scanning project directories in " . WWW_ROOT . "...\n";
$res = sync_all_projects_to_github($isForce);

echo "\n[*] Sync Summary:\n";
echo "    Total Projects Processed: {$res['total']}\n";
echo "    Successfully Synced:      {$res['synced']}\n";
echo "    Errors / Warnings:        {$res['errors']}\n";
if (!empty($res['skipped'])) {
    echo "    Projects Skipped (Paused):{$res['skipped']}\n";
}
echo "\n";

if (!empty($res['details'])) {
    foreach ($res['details'] as $proj => $syncResult) {
        $statusIcon = $syncResult['success'] ? '[✓]' : '[✗]';
        echo "{$statusIcon} Project: {$proj}\n";
        if (!empty($syncResult['logs'])) {
            foreach ($syncResult['logs'] as $logLine) {
                echo "    - {$logLine}\n";
            }
        }
        if (!$syncResult['success'] && !empty($syncResult['error'])) {
            echo "    ! Error: {$syncResult['error']}\n";
        }
        echo "\n";
    }
}

echo "[✓] Automated sync finished at " . date('Y-m-d H:i:s') . "\n";

