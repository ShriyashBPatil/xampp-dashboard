<?php
require_once __DIR__ . '/includes/core.php';

// Redirect if already logged in
if (is_logged_in()) {
    header('Location: /dashboard/');
    exit;
}

// Handle Guest Mode login
if (isset($_GET['guest']) || (isset($_POST['guest_login']))) {
    login_as_guest();
    set_flash('success', 'Welcome! You are now exploring the dashboard in Guest Mode (Read-Only).');
    header('Location: /dashboard/');
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['guest_login'])) {
    $usernameOrEmail = strtolower(trim($_POST['username_or_email'] ?? ''));
    $password = trim($_POST['password'] ?? '');

    if (!empty($usernameOrEmail) && !empty($password)) {
        try {
            $authPdo = get_db_connection(AUTH_DB);
            $stmt = $authPdo->prepare("SELECT * FROM `users` WHERE LOWER(username) = ? OR LOWER(email) = ? LIMIT 1");
            $stmt->execute([$usernameOrEmail, $usernameOrEmail]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                if (($user['status'] ?? 'active') === 'suspended') {
                    $error = "This account has been locked or suspended by the workspace administrator. Please contact an admin for reactivation.";
                } else {
                    $_SESSION['user'] = [
                        'id' => (int)$user['id'],
                        'username' => $user['username'],
                        'email' => $user['email'],
                        'role' => $user['role'],
                        'status' => $user['status'] ?? 'active',
                        'permissions' => $user['permissions'] ?? '[]',
                        'created_at' => $user['created_at']
                    ];
                    session_write_close();
                    header('Location: /dashboard/');
                    exit;
                }
            } else {
                $error = "Incorrect username or password. Please try again.";
            }
        } catch (Exception $e) {
            $error = "Authentication error: " . $e->getMessage();
        }
    } else {
        $error = "Please enter both username and password.";
    }
}

$flash = get_flash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - Shriyash Patil Workspace</title>
    <link rel="icon" type="image/svg+xml" href="/dashboard/favicon.svg">
    <link rel="alternate icon" href="/dashboard/favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50 text-gray-800 font-['Plus_Jakarta_Sans',sans-serif] min-h-screen flex flex-col justify-center py-12 sm:px-6 lg:px-8">
    <div class="sm:mx-auto sm:w-full sm:max-w-md">
        <div class="flex justify-center mb-3">
            <div class="w-12 h-12 rounded-xl bg-slate-900 flex items-center justify-center shadow-md">
                <svg class="w-6 h-6 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect>
                    <rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect>
                    <line x1="6" y1="6" x2="6.01" y2="6"></line>
                    <line x1="6" y1="18" x2="6.01" y2="18"></line>
                </svg>
            </div>
        </div>
        <h2 class="text-center text-2xl font-bold tracking-tight text-gray-900">Shriyash Patil Workspace</h2>
        <p class="mt-1 text-center text-xs text-gray-500">Built by Shriyash Patil &bull; Management Suite</p>
    </div>

    <div class="mt-6 sm:mx-auto sm:w-full sm:max-w-md">
        <div class="bg-white py-8 px-6 shadow-sm border border-gray-200 rounded-2xl sm:px-10">
            <?php if ($flash): ?>
                <div class="mb-4 p-3 rounded-lg text-xs font-medium <?= $flash['type'] === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200' ?>">
                    <?= htmlspecialchars($flash['msg']) ?>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="mb-4 p-3 rounded-lg text-xs font-medium bg-rose-50 text-rose-700 border border-rose-200">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form class="space-y-4" method="POST" action="/dashboard/login.php">
                <div>
                    <label for="username_or_email" class="block text-xs font-semibold text-gray-700 uppercase tracking-wider">Username or Email</label>
                    <div class="mt-1">
                        <input id="username_or_email" name="username_or_email" type="text" placeholder="Enter your username or email" required autofocus class="w-full text-sm px-3.5 py-2.5 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                </div>

                <div>
                    <label for="password" class="block text-xs font-semibold text-gray-700 uppercase tracking-wider">Password</label>
                    <div class="mt-1">
                        <input id="password" name="password" type="password" placeholder="Enter your password" required class="w-full text-sm px-3.5 py-2.5 bg-gray-50 border border-gray-200 rounded-lg focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500">
                    </div>
                </div>

                <div class="pt-2">
                    <button type="submit" class="w-full flex justify-center py-2.5 px-4 border border-transparent rounded-lg shadow-sm text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition">
                        Sign In
                    </button>
                </div>
            </form>

            <!-- Divider -->
            <div class="relative my-6">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-gray-200"></div>
                </div>
                <div class="relative flex justify-center text-xs">
                    <span class="bg-white px-3 text-gray-400 font-medium uppercase tracking-wider">or preview mode</span>
                </div>
            </div>

            <!-- Guest Mode Button -->
            <form method="POST" action="/dashboard/login.php">
                <button type="submit" name="guest_login" value="1" class="w-full flex items-center justify-center gap-2 py-2.5 px-4 border border-gray-300 rounded-lg shadow-xs text-sm font-semibold text-gray-700 bg-white hover:bg-gray-50 hover:border-gray-400 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition">
                    <svg class="w-4 h-4 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                    <span>Continue as Guest</span>
                </button>
            </form>
            <p class="text-center text-[11px] text-gray-400 mt-2">Explore the workspace with read-only guest permissions</p>
        </div>
    </div>
</body>
</html>
