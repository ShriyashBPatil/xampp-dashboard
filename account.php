<?php
require_once __DIR__ . '/includes/core.php';
require_login();

$currentUser = current_user();
$isAdmin = is_admin();
$isGuest = is_guest();
$authPdo = get_db_connection(AUTH_DB);

$canCreateUsers = $isAdmin || has_permission('can_users_create');
$canEditPrivileges = $isAdmin || has_permission('can_users_edit_privileges');
$canDeleteUsers = $isAdmin || has_permission('can_users_delete');
$canSuspendUsers = $isAdmin || has_permission('can_users_suspend');
$canResetPassword = $isAdmin || has_permission('can_users_reset_pwd');
$canManageAnyUser = $canCreateUsers || $canEditPrivileges || $canDeleteUsers || $canSuspendUsers || $canResetPassword;

$featureCategories = get_all_feature_privileges();
$allPrivilegeKeys = get_all_privilege_keys();

// Action: Toggle Account Suspension / Lock
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_suspend'])) {
    require_not_guest('Account suspension is disabled in Guest Mode.');
    if (!$canSuspendUsers) {
        set_flash('error', 'Access denied. You do not have permission to lock or suspend accounts.');
        header('Location: /dashboard/account.php');
        exit;
    }

    $targetUserId = (int)($_POST['user_id'] ?? 0);
    if ($targetUserId === (int)$currentUser['id']) {
        set_flash('error', 'You cannot suspend or lock your own active account.');
    } elseif ($targetUserId > 0) {
        try {
            $stmt = $authPdo->prepare("SELECT username, role, status FROM `users` WHERE `id` = ?");
            $stmt->execute([$targetUserId]);
            $targetUser = $stmt->fetch();

            if ($targetUser) {
                if ($targetUser['role'] === 'admin' && !$isAdmin) {
                    set_flash('error', 'Only administrators can suspend other admin accounts.');
                } else {
                    $newStatus = ($targetUser['status'] === 'suspended') ? 'active' : 'suspended';
                    $upd = $authPdo->prepare("UPDATE `users` SET `status` = ? WHERE `id` = ?");
                    $upd->execute([$newStatus, $targetUserId]);
                    
                    $actionText = ($newStatus === 'suspended') ? 'locked / suspended' : 'unlocked & activated';
                    set_flash('success', "Account for '{$targetUser['username']}' has been {$actionText}.");
                }
            } else {
                set_flash('error', 'User not found.');
            }
        } catch (Exception $e) {
            set_flash('error', 'Failed to update user status: ' . $e->getMessage());
        }
    }
    header('Location: /dashboard/account.php');
    exit;
}

// Action: Admin Reset User Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_reset_password'])) {
    require_not_guest('Password reset is disabled in Guest Mode.');
    if (!$canResetPassword) {
        set_flash('error', 'Access denied. You do not have permission to reset user passwords.');
        header('Location: /dashboard/account.php');
        exit;
    }

    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $newPass = trim($_POST['new_password'] ?? '');

    if ($targetUserId <= 0 || empty($newPass)) {
        set_flash('error', 'Please provide a valid user and new password.');
    } elseif (strlen($newPass) < 6) {
        set_flash('error', 'New password must be at least 6 characters long.');
    } else {
        try {
            $stmt = $authPdo->prepare("SELECT username, role FROM `users` WHERE `id` = ?");
            $stmt->execute([$targetUserId]);
            $targetUser = $stmt->fetch();

            if ($targetUser) {
                if ($targetUser['role'] === 'admin' && !$isAdmin) {
                    set_flash('error', 'Only administrators can reset passwords for admin accounts.');
                } else {
                    $newHash = password_hash($newPass, PASSWORD_DEFAULT);
                    $upd = $authPdo->prepare("UPDATE `users` SET password = ? WHERE id = ?");
                    $upd->execute([$newHash, $targetUserId]);
                    set_flash('success', "Password for user '{$targetUser['username']}' was reset successfully.");
                }
            } else {
                set_flash('error', 'User not found.');
            }
        } catch (Exception $e) {
            set_flash('error', 'Failed to reset password: ' . $e->getMessage());
        }
    }
    header('Location: /dashboard/account.php');
    exit;
}

// Action: Create New User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    require_not_guest('User creation is disabled in Guest Mode.');
    if (!$canCreateUsers) {
        set_flash('error', 'Access denied. You do not have permission to create users.');
        header('Location: /dashboard/account.php');
        exit;
    }

    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $requestedRole = in_array($_POST['role'] ?? '', ['admin', 'user']) ? $_POST['role'] : 'user';
    
    // Only super admins can create another admin
    $role = ($requestedRole === 'admin' && $isAdmin) ? 'admin' : 'user';
    $perms = ($role === 'admin') ? $allPrivilegeKeys : ($_POST['permissions'] ?? []);
    $permsJson = json_encode(array_values(array_intersect($perms, $allPrivilegeKeys)));

    if (empty($username) || empty($email) || empty($password)) {
        set_flash('error', 'All fields are required.');
    } elseif (!preg_match('/^[a-zA-Z0-9_-]{3,30}$/', $username)) {
        set_flash('error', 'Username must be 3-30 characters (letters, numbers, underscore, hyphen).');
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        set_flash('error', 'Please enter a valid email address.');
    } elseif (strlen($password) < 6) {
        set_flash('error', 'Password must be at least 6 characters long.');
    } else {
        try {
            $stmt = $authPdo->prepare("SELECT COUNT(*) FROM `users` WHERE `username` = ? OR `email` = ?");
            $stmt->execute([$username, $email]);
            if ($stmt->fetchColumn() > 0) {
                set_flash('error', 'Username or email is already registered.');
            } else {
                $passHash = password_hash($password, PASSWORD_DEFAULT);
                $ins = $authPdo->prepare("INSERT INTO `users` (username, email, password, role, permissions) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$username, $email, $passHash, $role, $permsJson]);
                set_flash('success', "User '{$username}' ({$role}) created with custom feature privileges.");
            }
        } catch (Exception $e) {
            set_flash('error', "Database error: " . $e->getMessage());
        }
    }
    header('Location: /dashboard/account.php');
    exit;
}

// Action: Update User Privileges & Role
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_privileges'])) {
    require_not_guest('Privilege adjustments are disabled in Guest Mode.');
    if (!$canEditPrivileges) {
        set_flash('error', 'Access denied. You do not have permission to modify user privileges.');
        header('Location: /dashboard/account.php');
        exit;
    }

    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $requestedRole = in_array($_POST['role'] ?? '', ['admin', 'user']) ? $_POST['role'] : 'user';
    $newRole = ($requestedRole === 'admin' && $isAdmin) ? 'admin' : 'user';
    $perms = ($newRole === 'admin') ? $allPrivilegeKeys : ($_POST['permissions'] ?? []);
    $permsJson = json_encode(array_values(array_intersect($perms, $allPrivilegeKeys)));

    if ($targetUserId > 0) {
        try {
            $upd = $authPdo->prepare("UPDATE `users` SET role = ?, permissions = ? WHERE id = ?");
            $upd->execute([$newRole, $permsJson, $targetUserId]);
            
            // If updating current user's session
            if ($targetUserId === (int)$currentUser['id']) {
                $_SESSION['user']['role'] = $newRole;
                $_SESSION['user']['permissions'] = $permsJson;
            }
            set_flash('success', 'User role & feature privileges updated successfully.');
        } catch (Exception $e) {
            set_flash('error', 'Failed to update privileges: ' . $e->getMessage());
        }
    }
    header('Location: /dashboard/account.php');
    exit;
}

// Action: Delete User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
    require_not_guest('User deletion is disabled in Guest Mode.');
    if (!$canDeleteUsers) {
        set_flash('error', 'Access denied. You do not have permission to delete users.');
        header('Location: /dashboard/account.php');
        exit;
    }

    $userId = (int)($_POST['user_id'] ?? 0);
    if ($userId === (int)$currentUser['id']) {
        set_flash('error', 'You cannot delete your own active account.');
    } elseif ($userId > 0) {
        try {
            $del = $authPdo->prepare("DELETE FROM `users` WHERE `id` = ?");
            $del->execute([$userId]);
            set_flash('success', 'User deleted successfully.');
        } catch (Exception $e) {
            set_flash('error', 'Failed to delete user: ' . $e->getMessage());
        }
    }
    header('Location: /dashboard/account.php');
    exit;
}

// Action: Update Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    require_not_guest('Password changes are not available for Guest sessions.');
    $currentPass = trim($_POST['current_password'] ?? '');
    $newPass = trim($_POST['new_password'] ?? '');
    $confirmPass = trim($_POST['confirm_password'] ?? '');

    if (empty($currentPass) || empty($newPass)) {
        set_flash('error', 'Please fill in current and new password.');
    } elseif (strlen($newPass) < 6) {
        set_flash('error', 'New password must be at least 6 characters.');
    } elseif ($newPass !== $confirmPass) {
        set_flash('error', 'New passwords do not match.');
    } else {
        try {
            $stmt = $authPdo->prepare("SELECT password FROM `users` WHERE `id` = ?");
            $stmt->execute([$currentUser['id']]);
            $hash = $stmt->fetchColumn();

            if ($hash && password_verify($currentPass, $hash)) {
                $newHash = password_hash($newPass, PASSWORD_DEFAULT);
                $upd = $authPdo->prepare("UPDATE `users` SET password = ? WHERE id = ?");
                $upd->execute([$newHash, $currentUser['id']]);
                set_flash('success', 'Password updated successfully.');
            } else {
                set_flash('error', 'Current password is incorrect.');
            }
        } catch (Exception $e) {
            set_flash('error', 'Error updating password: ' . $e->getMessage());
        }
    }
    header('Location: /dashboard/account.php');
    exit;
}

// Fetch all users with permissions if user has any management privilege
$allUsersList = [];
if ($canManageAnyUser) {
    try {
        $allUsersList = $authPdo->query("SELECT id, username, email, role, status, permissions, created_at FROM `users` ORDER BY id DESC")->fetchAll();
    } catch (Exception $e) {}
}

$pageTitle = 'Account & Privileges - ' . get_system_setting('workspace_title', 'Shriyash Patil');
$activeNav = 'account';
include __DIR__ . '/includes/header.php';
?>

<div class="max-w-5xl mx-auto space-y-6">

    <!-- Minimal Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-4 border-b border-slate-200">
        <div>
            <h1 class="text-lg font-bold text-slate-900">Account & Feature Privileges</h1>
            <p class="text-xs text-slate-500">Configure credentials, access control, and granular permissions for each feature.</p>
        </div>
        <div class="flex items-center gap-2">
            <?php if ($canCreateUsers): ?>
                <button type="button" onclick="openModal('createUserModal')" class="btn btn-primary text-xs py-1.5 px-3">
                    <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                    Create User
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main Cards Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- User Identity Card -->
        <div class="bg-white border border-slate-200 rounded-xl p-5 flex flex-col justify-between space-y-4 shadow-sm">
            <div>
                <div class="flex items-center gap-3.5 pb-4 border-b border-slate-100">
                    <div class="w-12 h-12 rounded-xl <?= $isGuest ? 'bg-amber-500' : 'bg-slate-900' ?> flex items-center justify-center text-white font-bold text-lg shadow-sm">
                        <?= strtoupper(substr($currentUser['username'] ?? 'G', 0, 1)) ?>
                    </div>
                    <div>
                        <div class="font-bold text-sm text-slate-900 flex items-center gap-1.5">
                            <span><?= htmlspecialchars($currentUser['username'] ?? 'Guest') ?></span>
                            <?php if (!$isGuest): ?>
                                <span class="w-2 h-2 rounded-full bg-emerald-500" title="Active Account"></span>
                            <?php endif; ?>
                        </div>
                        <div class="text-xs text-slate-400 font-mono truncate"><?= htmlspecialchars($currentUser['email'] ?? 'guest@workspace.local') ?></div>
                    </div>
                </div>

                <div class="space-y-3 pt-3 text-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-slate-400">Account Role</span>
                        <?php if ($isGuest): ?>
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-amber-50 text-amber-800 border border-amber-200 font-mono">Guest Mode</span>
                        <?php else: ?>
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-indigo-50 text-indigo-700 border border-indigo-200 font-mono"><?= htmlspecialchars($currentUser['role'] ?? 'user') ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-400">Account ID</span>
                        <span class="font-mono text-slate-700 font-semibold"><?= $isGuest ? 'Session' : ('#' . ($currentUser['id'] ?? '')) ?></span>
                    </div>
                    <div class="flex items-center justify-between">
                        <span class="text-slate-400">Granted Features</span>
                        <span class="font-mono font-semibold text-indigo-600">
                            <?= $isGuest ? '4 (Read-Only)' : (is_admin() ? 'All Features (20)' : count(get_user_permissions()) . ' Allowed') ?>
                        </span>
                    </div>
                </div>
            </div>

            <div class="pt-4 border-t border-slate-100 flex flex-col gap-2">
                <?php if ($isGuest): ?>
                    <a href="/dashboard/login.php" class="btn btn-primary text-xs py-2 w-full justify-center">
                        <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"/></svg>
                        Sign In as Admin
                    </a>
                    <a href="/dashboard/logout.php" class="btn btn-outline text-xs py-2 w-full justify-center">
                        Exit Guest Mode
                    </a>
                <?php else: ?>
                    <a href="/dashboard/logout.php" class="btn btn-outline text-rose-600 hover:bg-rose-50 hover:border-rose-300 text-xs py-2 w-full justify-center">
                        <svg class="w-3.5 h-3.5 mr-1 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                        Sign Out
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Security & Password / Guest Overview -->
        <div class="lg:col-span-2 bg-white border border-slate-200 rounded-xl p-5 shadow-sm space-y-4">
            <?php if ($isGuest): ?>
                <div class="space-y-3">
                    <div class="text-xs font-bold text-slate-900 uppercase tracking-wider flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                        Guest Mode Access Policy
                    </div>
                    <p class="text-xs text-slate-500">You are currently inspecting this server in preview mode.</p>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs pt-1">
                        <div class="p-3 bg-slate-50 rounded-lg border border-slate-100 space-y-1.5">
                            <div class="font-semibold text-slate-800 flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                Read-Only Permissions
                            </div>
                            <ul class="text-[11px] text-slate-600 space-y-1">
                                <li>• Preview live web apps and scripts</li>
                                <li>• Explore file structures & table schemas</li>
                                <li>• Run safe read SQL queries (SELECT)</li>
                            </ul>
                        </div>

                        <div class="p-3 bg-amber-50/70 rounded-lg border border-amber-200/70 space-y-1.5">
                            <div class="font-semibold text-amber-900 flex items-center gap-1.5">
                                <svg class="w-3.5 h-3.5 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                Restricted Operations
                            </div>
                            <ul class="text-[11px] text-amber-800 space-y-1">
                                <li>• 1-Click Auto Setup & ZIP deploy</li>
                                <li>• File creation, deletion, or edits</li>
                                <li>• Database write mutations (INSERT/DROP)</li>
                            </ul>
                        </div>
                    </div>

                    <div class="pt-2">
                        <a href="/dashboard/login.php" class="btn btn-primary text-xs py-2">
                            Sign In for Full Administrator Access &rarr;
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div>
                    <div class="text-xs font-bold text-slate-900 uppercase tracking-wider flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-slate-900"></span>
                        Change Password
                    </div>
                    <p class="text-xs text-slate-500 mt-0.5">Update your password to ensure secure access to the dashboard.</p>
                </div>

                <form method="POST" action="/dashboard/account.php" class="space-y-4 max-w-md pt-1">
                    <input type="hidden" name="change_password" value="1">
                    
                    <div>
                        <label class="block text-xs font-medium text-slate-700 mb-1">Current Password</label>
                        <input type="password" name="current_password" required class="w-full text-xs px-3 py-2 border border-slate-300 rounded-lg outline-none focus:border-slate-800" placeholder="••••••••">
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">New Password</label>
                            <input type="password" name="new_password" required class="w-full text-xs px-3 py-2 border border-slate-300 rounded-lg outline-none focus:border-slate-800" placeholder="Min. 6 characters">
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-slate-700 mb-1">Confirm New Password</label>
                            <input type="password" name="confirm_password" required class="w-full text-xs px-3 py-2 border border-slate-300 rounded-lg outline-none focus:border-slate-800" placeholder="Repeat new password">
                        </div>
                    </div>

                    <div class="pt-1">
                        <button type="submit" class="btn btn-primary text-xs py-2 px-4">
                            Update Password
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <!-- Users & Privileges Directory -->
    <?php if ($canManageAnyUser): ?>
        <div class="bg-white border border-slate-200 rounded-xl overflow-hidden shadow-sm">
            <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h2 class="text-xs font-bold text-slate-900 uppercase tracking-wider flex items-center gap-1.5">
                        <span class="w-2 h-2 rounded-full bg-indigo-600"></span>
                        User Accounts & Feature Access Privileges
                    </h2>
                    <p class="text-[11px] text-slate-500 mt-0.5">Manage accounts, lock or suspend access, reset passwords, and assign granular privileges.</p>
                </div>
                <span class="text-xs text-slate-400 font-mono"><?= count($allUsersList) ?> user(s)</span>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-xs">
                    <thead class="bg-slate-50/70 text-slate-500 font-medium">
                        <tr>
                            <th class="px-5 py-2.5 text-left">User</th>
                            <th class="px-5 py-2.5 text-left">Role</th>
                            <th class="px-5 py-2.5 text-left">Status</th>
                            <th class="px-5 py-2.5 text-left">Granted Feature Privileges</th>
                            <th class="px-5 py-2.5 text-left">Created</th>
                            <th class="px-5 py-2.5 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-mono">
                        <?php foreach ($allUsersList as $u): ?>
                            <?php 
                                $userPerms = ($u['role'] === 'admin') ? $allPrivilegeKeys : (json_decode($u['permissions'] ?? '[]', true) ?: []);
                                $isSuspended = ($u['status'] ?? 'active') === 'suspended';
                            ?>
                            <tr class="hover:bg-slate-50/80 transition <?= ($u['id'] == ($currentUser['id'] ?? 0)) ? 'bg-indigo-50/30' : ($isSuspended ? 'bg-rose-50/20' : '') ?>">
                                <td class="px-5 py-3">
                                    <div class="flex items-center gap-2">
                                        <div class="w-6 h-6 rounded-full <?= $isSuspended ? 'bg-rose-100 border-rose-200 text-rose-700' : 'bg-slate-100 border-slate-200 text-slate-700' ?> border flex items-center justify-center text-[10px] font-bold">
                                            <?= strtoupper(substr($u['username'], 0, 1)) ?>
                                        </div>
                                        <div>
                                            <div class="font-semibold text-slate-900 flex items-center gap-1">
                                                <span class="<?= $isSuspended ? 'line-through text-slate-400' : '' ?>"><?= htmlspecialchars($u['username']) ?></span>
                                                <?php if ($u['id'] == ($currentUser['id'] ?? 0)): ?>
                                                    <span class="text-[9px] text-indigo-700 bg-indigo-50 border border-indigo-200 px-1 py-0.2 rounded font-sans font-medium">You</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-[10px] text-slate-400"><?= htmlspecialchars($u['email']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-5 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold uppercase <?= $u['role'] === 'admin' ? 'bg-indigo-50 text-indigo-700 border border-indigo-200' : 'bg-slate-100 text-slate-600' ?>">
                                        <?= htmlspecialchars($u['role']) ?>
                                    </span>
                                </td>
                                <td class="px-5 py-3 font-sans">
                                    <?php if ($isSuspended): ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-rose-50 text-rose-700 border border-rose-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-rose-500 animate-pulse"></span>
                                            Suspended
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[10px] font-bold uppercase bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                            Active
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3 font-sans">
                                    <?php if ($u['role'] === 'admin'): ?>
                                        <span class="inline-flex items-center gap-1 text-[11px] font-semibold text-emerald-700 bg-emerald-50 border border-emerald-200 px-2 py-0.5 rounded">
                                            <svg class="w-3 h-3 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                            Full Admin Privileges (All Features Enabled)
                                        </span>
                                    <?php else: ?>
                                        <div class="flex flex-wrap gap-1 max-w-md">
                                            <?php if (empty($userPerms)): ?>
                                                <span class="text-[11px] text-slate-400 italic">No feature access assigned (View Only)</span>
                                            <?php else: ?>
                                                <?php 
                                                    $count = count($userPerms);
                                                    $shown = array_slice($userPerms, 0, 4);
                                                ?>
                                                <?php foreach ($shown as $pkey): ?>
                                                    <span class="px-1.5 py-0.5 bg-slate-100 text-slate-700 border border-slate-200 rounded text-[10px] font-mono font-medium">
                                                        <?= htmlspecialchars(str_replace('can_', '', $pkey)) ?>
                                                    </span>
                                                <?php endforeach; ?>
                                                <?php if ($count > 4): ?>
                                                    <span class="px-1.5 py-0.5 bg-indigo-50 text-indigo-700 border border-indigo-200 rounded text-[10px] font-mono font-bold">+<?= $count - 4 ?> more</span>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3 text-slate-500 font-mono text-[11px]"><?= date('M d, Y', strtotime($u['created_at'])) ?></td>
                                <td class="px-5 py-3 text-right space-x-1 whitespace-nowrap">
                                    <!-- Lock / Suspend Toggle Button -->
                                    <?php if ($canSuspendUsers && $u['id'] != ($currentUser['id'] ?? 0) && ($isAdmin || $u['role'] !== 'admin')): ?>
                                        <form method="POST" action="/dashboard/account.php" onsubmit="return confirm('<?= $isSuspended ? "Reactivate and unlock user '{$u['username']}'?" : "Lock and suspend user '{$u['username']}'? They will not be able to log in." ?>');" class="inline">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <input type="hidden" name="toggle_suspend" value="1">
                                            <button type="submit" class="p-1.5 <?= $isSuspended ? 'text-emerald-600 hover:bg-emerald-50' : 'text-amber-600 hover:bg-amber-50' ?> rounded transition cursor-pointer" title="<?= $isSuspended ? 'Unlock / Reactivate Account' : 'Lock / Suspend Account' ?>">
                                                <?php if ($isSuspended): ?>
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"/></svg>
                                                <?php else: ?>
                                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                                <?php endif; ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <!-- Reset Password Button -->
                                    <?php if ($canResetPassword && ($isAdmin || $u['role'] !== 'admin')): ?>
                                        <button type="button" onclick='openAdminResetPasswordModal(<?= json_encode($u) ?>)' class="p-1.5 text-slate-500 hover:text-amber-600 rounded hover:bg-amber-50 transition cursor-pointer inline-block" title="Reset User Password">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                                        </button>
                                    <?php endif; ?>

                                    <!-- Edit Privileges Button -->
                                    <?php if ($canEditPrivileges): ?>
                                        <button type="button" onclick='openPrivilegesModal(<?= json_encode($u) ?>)' class="p-1.5 text-slate-500 hover:text-indigo-600 rounded hover:bg-slate-100 transition cursor-pointer inline-block" title="Set Feature Privileges">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
                                        </button>
                                    <?php endif; ?>

                                    <!-- Delete User Button -->
                                    <?php if ($canDeleteUsers && $u['id'] != ($currentUser['id'] ?? 0)): ?>
                                        <form method="POST" action="/dashboard/account.php" onsubmit="return confirm('Delete user \'<?= htmlspecialchars(addslashes($u['username'])) ?>\'?');" class="inline">
                                            <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                            <button type="submit" name="delete_user" class="p-1.5 text-slate-400 hover:text-rose-600 rounded hover:bg-rose-50 transition cursor-pointer" title="Delete User">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

</div>

<!-- Modal: Admin Reset User Password -->
<?php if ($canResetPassword): ?>
    <div id="adminResetPasswordModal" class="modal-backdrop">
        <div class="modal-dialog" style="max-width: 440px;">
            <div class="modal-header">
                <div class="modal-title">
                    <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
                    <span>Reset Password: <span id="resetPwdModalUsername" class="font-mono text-amber-700 font-bold"></span></span>
                </div>
                <button type="button" onclick="closeModal('adminResetPasswordModal')" class="modal-close">&times;</button>
            </div>

            <form method="POST" action="/dashboard/account.php">
                <input type="hidden" name="admin_reset_password" value="1">
                <input type="hidden" name="user_id" id="resetPwdModalUserId" value="">
                
                <div class="modal-body space-y-4">
                    <p class="text-xs text-slate-500 leading-relaxed">
                        Enter a new password for this user. They will immediately be required to use this new password on their next login.
                    </p>

                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" placeholder="At least 6 characters" required minlength="6" class="form-input" autofocus>
                    </div>

                    <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                        <button type="button" onclick="closeModal('adminResetPasswordModal')" class="btn btn-outline text-xs">Cancel</button>
                        <button type="submit" class="btn btn-primary text-xs bg-amber-600 hover:bg-amber-700 border-amber-600 text-white">Reset Password</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- Modal: Create User -->
<?php if ($canCreateUsers): ?>
    <div id="createUserModal" class="modal-backdrop">
        <div class="modal-dialog" style="max-width: 580px;">
            <div class="modal-header">
                <div class="modal-title">
                    <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
                    <span>Create User & Set Privileges</span>
                </div>
                <button type="button" onclick="closeModal('createUserModal')" class="modal-close">&times;</button>
            </div>

            <form method="POST" action="/dashboard/account.php">
                <input type="hidden" name="create_user" value="1">
                
                <div class="modal-body space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Username</label>
                            <input type="text" name="username" placeholder="e.g. dev_user" required class="form-input" autofocus>
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Email Address</label>
                            <input type="email" name="email" placeholder="dev@example.com" required class="form-input">
                        </div>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Password</label>
                            <input type="password" name="password" placeholder="At least 6 characters" required class="form-input">
                        </div>

                        <div class="form-group" style="margin-bottom: 0;">
                            <label class="form-label">Role</label>
                            <select name="role" id="createUserRoleSelect" onchange="toggleCreatePrivileges(this.value)" class="form-select">
                                <option value="user">User (Custom Feature Privileges)</option>
                                <?php if ($isAdmin): ?>
                                    <option value="admin">Administrator (Full Access)</option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Granular Categorized Feature Checklist -->
                    <div id="createUserPrivilegesBox" class="p-3 bg-slate-50 border border-slate-200 rounded-xl space-y-3 max-h-72 overflow-y-auto">
                        <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                            <span class="text-[11px] font-bold text-slate-800 uppercase tracking-wider">Granular Feature Permissions</span>
                            <button type="button" onclick="toggleAllCreatePerms(true)" class="text-[10px] text-indigo-600 font-semibold hover:underline">Select All</button>
                        </div>
                        <?php foreach ($featureCategories as $catTitle => $catPerms): ?>
                            <div class="space-y-1.5 pt-1">
                                <div class="text-[10px] font-bold text-indigo-900 uppercase tracking-wider"><?= htmlspecialchars($catTitle) ?></div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <?php foreach ($catPerms as $pkey => $pdata): ?>
                                        <label class="flex items-start gap-2 p-2 bg-white border border-slate-200 rounded-lg text-xs cursor-pointer hover:border-indigo-300 transition">
                                            <input type="checkbox" name="permissions[]" value="<?= $pkey ?>" checked class="w-3.5 h-3.5 mt-0.5 text-indigo-600 rounded border-slate-300 create-perm-chk">
                                            <div>
                                                <div class="font-semibold text-slate-900 text-[11px]"><?= htmlspecialchars($pdata['name']) ?></div>
                                                <div class="text-[10px] text-slate-400 leading-tight mt-0.5"><?= htmlspecialchars($pdata['desc']) ?></div>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                        <button type="button" onclick="closeModal('createUserModal')" class="btn btn-outline text-xs">Cancel</button>
                        <button type="submit" class="btn btn-primary text-xs">Create User</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<!-- Modal: Edit User Privileges -->
<?php if ($canEditPrivileges): ?>
    <div id="editPrivilegesModal" class="modal-backdrop">
        <div class="modal-dialog" style="max-width: 580px;">
            <div class="modal-header">
                <div class="modal-title">
                    <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
                    <span>Edit Feature Privileges: <span id="privModalUsername" class="font-mono text-indigo-600 font-bold"></span></span>
                </div>
                <button type="button" onclick="closeModal('editPrivilegesModal')" class="modal-close">&times;</button>
            </div>

            <form method="POST" action="/dashboard/account.php">
                <input type="hidden" name="update_privileges" value="1">
                <input type="hidden" name="user_id" id="privModalUserId" value="">
                
                <div class="modal-body space-y-4">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label class="form-label">User Role</label>
                        <select name="role" id="privModalRoleSelect" onchange="toggleEditPrivileges(this.value)" class="form-select">
                            <option value="user">User (Custom Feature Privileges)</option>
                            <?php if ($isAdmin): ?>
                                <option value="admin">Administrator (Full Access to Everything)</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div id="privModalCheckboxesBox" class="p-3 bg-slate-50 border border-slate-200 rounded-xl space-y-3 max-h-80 overflow-y-auto">
                        <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                            <span class="text-[11px] font-bold text-slate-800 uppercase tracking-wider">Granted Feature Access</span>
                            <div class="flex items-center gap-2">
                                <button type="button" onclick="toggleAllEditPerms(true)" class="text-[10px] text-indigo-600 font-semibold hover:underline">Select All</button>
                                <span class="text-slate-300">•</span>
                                <button type="button" onclick="toggleAllEditPerms(false)" class="text-[10px] text-slate-500 font-semibold hover:underline">Deselect All</button>
                            </div>
                        </div>

                        <?php foreach ($featureCategories as $catTitle => $catPerms): ?>
                            <div class="space-y-1.5 pt-1">
                                <div class="text-[10px] font-bold text-indigo-900 uppercase tracking-wider"><?= htmlspecialchars($catTitle) ?></div>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                    <?php foreach ($catPerms as $pkey => $pdata): ?>
                                        <label class="flex items-start gap-2 p-2 bg-white border border-slate-200 rounded-lg text-xs cursor-pointer hover:border-indigo-300 transition">
                                            <input type="checkbox" name="permissions[]" value="<?= $pkey ?>" id="perm_check_<?= $pkey ?>" class="w-3.5 h-3.5 mt-0.5 text-indigo-600 rounded border-slate-300 edit-perm-chk">
                                            <div>
                                                <div class="font-semibold text-slate-900 text-[11px]"><?= htmlspecialchars($pdata['name']) ?></div>
                                                <div class="text-[10px] text-slate-400 leading-tight mt-0.5"><?= htmlspecialchars($pdata['desc']) ?></div>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                        <button type="button" onclick="closeModal('editPrivilegesModal')" class="btn btn-outline text-xs">Cancel</button>
                        <button type="submit" class="btn btn-primary text-xs">Save Privileges</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<script>
function toggleCreatePrivileges(role) {
    const box = document.getElementById('createUserPrivilegesBox');
    if (box) box.style.display = (role === 'admin') ? 'none' : 'block';
}

function toggleEditPrivileges(role) {
    const box = document.getElementById('privModalCheckboxesBox');
    if (box) box.style.display = (role === 'admin') ? 'none' : 'block';
}

function toggleAllCreatePerms(checked) {
    document.querySelectorAll('.create-perm-chk').forEach(c => c.checked = checked);
}

function toggleAllEditPerms(checked) {
    document.querySelectorAll('.edit-perm-chk').forEach(c => c.checked = checked);
}

function openAdminResetPasswordModal(user) {
    const userIdInput = document.getElementById('resetPwdModalUserId');
    const usernameSpan = document.getElementById('resetPwdModalUsername');
    if (userIdInput) userIdInput.value = user.id;
    if (usernameSpan) usernameSpan.textContent = user.username;
    openModal('adminResetPasswordModal');
}

function openPrivilegesModal(user) {
    document.getElementById('privModalUserId').value = user.id;
    document.getElementById('privModalUsername').textContent = user.username;
    document.getElementById('privModalRoleSelect').value = user.role;

    let perms = [];
    try {
        perms = user.role === 'admin' ? <?= json_encode($allPrivilegeKeys) ?> : JSON.parse(user.permissions || '[]');
    } catch(e) { perms = []; }

    const allKeys = <?= json_encode($allPrivilegeKeys) ?>;
    allKeys.forEach(k => {
        const chk = document.getElementById('perm_check_' + k);
        if (chk) {
            chk.checked = perms.includes(k);
        }
    });

    toggleEditPrivileges(user.role);
    openModal('editPrivilegesModal');
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
