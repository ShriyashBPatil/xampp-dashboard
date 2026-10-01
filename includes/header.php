<?php
// Shared Header Template
$activeNav = $activeNav ?? 'home';
$flash = get_flash();
$currentUser = current_user();
$isGuest = is_guest();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Shriyash Patil Workspace') ?></title>
    <link rel="icon" type="image/svg+xml" href="/dashboard/favicon.svg">
    <link rel="alternate icon" href="/dashboard/favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --bg: #ffffff;
            --surface: #ffffff;
            --surface-subtle: #f8fafc;
            --surface-hover: #f1f5f9;
            --border: #e2e8f0;
            --border-light: #f1f5f9;
            --border-hover: #cbd5e1;
            
            --primary: #0f172a;
            --primary-hover: #1e293b;
            --primary-light: #f8fafc;
            --primary-border: #e2e8f0;

            --accent: #2563eb;
            --accent-light: #eff6ff;

            --text-primary: #0f172a;
            --text-secondary: #475569;
            --text-muted: #94a3b8;

            --success: #059669;
            --success-bg: #f0fdf4;
            --success-border: #bbf7d0;

            --danger: #e11d48;
            --danger-bg: #fff1f2;
            --danger-border: #fecdd3;

            --radius-xl: 14px;
            --radius-lg: 10px;
            --radius-md: 8px;
            --radius-sm: 6px;

            --shadow-subtle: 0 1px 2px rgba(0, 0, 0, 0.04);
            --shadow-card: 0 1px 3px rgba(0, 0, 0, 0.05);
            --shadow-modal: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
        }

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        html {
            width: 100%;
            overflow-x: hidden;
            -webkit-text-size-adjust: 100%;
        }
        
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: #f8fafc;
            color: var(--text-secondary);
            line-height: 1.5;
            min-height: 100vh;
            width: 100%;
            overflow-x: hidden;
            display: flex;
            flex-direction: column;
            align-items: stretch;
            -webkit-font-smoothing: antialiased;
        }

        .container {
            max-width: 1400px;
            width: 100%;
            margin-left: auto;
            margin-right: auto;
            padding-left: 20px;
            padding-right: 20px;
            box-sizing: border-box;
        }
        @media (min-width: 640px) {
            .container { 
                padding-left: 36px; 
                padding-right: 36px; 
            }
        }
        @media (min-width: 1536px) {
            .container {
                max-width: 1500px;
                padding-left: 48px;
                padding-right: 48px;
            }
        }

        /* Top Navbar */
        header {
            background-color: #ffffff;
            border-bottom: 1px solid var(--border);
            position: sticky;
            top: 0;
            z-index: 40;
            width: 100%;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.03);
        }
        .header-inner {
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 64px;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            color: var(--text-primary);
        }
        .brand-logo-wrap {
            width: 38px;
            height: 38px;
            border-radius: var(--radius-md);
            background: #0f172a;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--shadow-subtle);
        }
        .brand-text {
            display: flex;
            flex-direction: column;
        }
        .brand-title {
            font-size: 1.05rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .brand-pill {
            font-size: 0.65rem;
            font-weight: 700;
            padding: 1px 6px;
            background: var(--surface-subtle);
            border: 1px solid var(--border);
            border-radius: 9999px;
            color: var(--text-secondary);
            font-family: 'JetBrains Mono', monospace;
        }
        .brand-subtitle {
            font-size: 0.72rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        .header-actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .hamburger-btn {
            display: none;
            background: none;
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 8px;
            color: var(--text-primary);
            cursor: pointer;
        }

        /* Buttons */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 13px;
            border-radius: var(--radius-md);
            font-size: 0.82rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.15s ease;
            white-space: nowrap;
            cursor: pointer;
            border: 1px solid transparent;
            font-family: inherit;
        }
        .btn-primary {
            background: #4f46e5;
            color: #ffffff;
        }
        .btn-primary:hover {
            background: #4338ca;
        }
        .btn-outline {
            background: #ffffff;
            color: var(--text-primary);
            border: 1px solid var(--border);
        }
        .btn-outline:hover {
            background: var(--surface-subtle);
            border-color: var(--border-hover);
        }
        .btn-outline.active {
            background: #eef2ff;
            color: #4338ca;
            font-weight: 700;
            border-color: #c7d2fe;
        }

        /* Toggle Switches */
        .switch {
            position: relative;
            display: inline-flex;
            align-items: center;
            width: 36px;
            height: 20px;
            flex-shrink: 0;
            cursor: pointer;
            user-select: none;
            vertical-align: middle;
        }
        .switch.switch-sm {
            width: 30px;
            height: 16px;
        }
        .switch.switch-md {
            width: 40px;
            height: 22px;
        }
        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
            position: absolute;
            pointer-events: none;
        }
        .switch-slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #cbd5e1;
            transition: background-color 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 9999px;
        }
        .switch-slider:before {
            position: absolute;
            content: "";
            height: 14px;
            width: 14px;
            left: 3px;
            top: 3px;
            background-color: #ffffff;
            transition: transform 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 50%;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.18), 0 1px 2px rgba(0, 0, 0, 0.08);
        }
        .switch.switch-sm .switch-slider:before {
            height: 10px;
            width: 10px;
            left: 3px;
            top: 3px;
        }
        .switch.switch-md .switch-slider:before {
            height: 16px;
            width: 16px;
            left: 3px;
            top: 3px;
        }
        .switch input:checked + .switch-slider {
            background-color: #10b981;
        }
        .switch.switch-indigo input:checked + .switch-slider {
            background-color: #4f46e5;
        }
        .switch input:checked + .switch-slider:before {
            transform: translateX(16px);
        }
        .switch.switch-sm input:checked + .switch-slider:before {
            transform: translateX(14px);
        }
        .switch.switch-md input:checked + .switch-slider:before {
            transform: translateX(18px);
        }
        .switch input:focus-visible + .switch-slider {
            outline: 2px solid #6366f1;
            outline-offset: 2px;
        }


        /* Flash Message Alert */
        .alert-box {
            padding: 12px 16px;
            border-radius: var(--radius-md);
            margin: 18px 0 6px 0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .alert-success { background: var(--success-bg); color: #166534; border: 1px solid var(--success-border); }
        .alert-error { background: var(--danger-bg); color: #9f1239; border: 1px solid var(--danger-border); }

        /* Modals */
        .modal-backdrop {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(4px);
            z-index: 999;
            align-items: center;
            justify-content: center;
            padding: 12px;
            overflow-y: auto;
        }
        .modal-backdrop.active {
            display: flex;
        }
        .modal-dialog {
            background: #ffffff;
            border-radius: var(--radius-xl);
            width: 100%;
            max-width: 540px;
            max-height: calc(100vh - 24px);
            display: flex;
            flex-direction: column;
            box-shadow: var(--shadow-modal);
            border: 1px solid var(--border);
            overflow: hidden;
            margin: auto;
            animation: modalPop 0.18s cubic-bezier(0.16, 1, 0.3, 1);
            transition: max-width 0.25s ease, height 0.25s ease, border-radius 0.25s ease;
        }
        .modal-dialog.modal-fullscreen {
            max-width: 100vw !important;
            width: 100vw !important;
            height: 100vh !important;
            max-height: 100vh !important;
            margin: 0 !important;
            border-radius: 0 !important;
            border: none !important;
        }
        .modal-dialog.modal-lg {
            max-width: 860px;
        }
        .modal-dialog.modal-xl {
            max-width: 1100px;
        }
        .modal-dialog form {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }
        @keyframes modalPop {
            from { opacity: 0; transform: scale(0.97) translateY(6px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 14px 18px;
            border-bottom: 1px solid var(--border-light);
            flex-shrink: 0;
            background: #ffffff;
        }
        .modal-title {
            font-size: 0.98rem;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .modal-header-actions {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .modal-tool-btn {
            background: none;
            border: 1px solid var(--border-light);
            border-radius: var(--radius-sm);
            width: 28px;
            height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.15s;
        }
        .modal-tool-btn:hover {
            color: var(--text-primary);
            background: var(--surface-subtle);
            border-color: var(--border);
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--text-muted);
            cursor: pointer;
            line-height: 1;
            padding: 4px;
            border-radius: 4px;
            transition: all 0.15s;
        }
        .modal-close:hover { color: var(--text-primary); background: var(--surface-subtle); }
        .modal-body {
            padding: 16px 18px;
            display: flex;
            flex-direction: column;
            gap: 12px;
            overflow-y: auto;
            flex: 1;
            min-height: 0;
            scrollbar-width: thin;
        }
        .modal-footer {
            padding: 12px 18px;
            border-top: 1px solid var(--border-light);
            background: var(--surface-subtle);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
            flex-shrink: 0;
        }

        .tab-nav {
            display: flex;
            background: var(--surface-subtle);
            padding: 3px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border-light);
            gap: 4px;
        }
        .tab-btn {
            flex: 1;
            padding: 7px 12px;
            font-size: 0.78rem;
            border: none;
            background: none;
            border-radius: var(--radius-sm);
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            transition: all 0.15s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
        }
        .tab-btn.active {
            background: #ffffff;
            color: var(--text-primary);
            box-shadow: 0 1px 2px rgba(0,0,0,0.06);
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        .form-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-primary);
        }
        .form-hint {
            font-size: 0.74rem;
            color: var(--text-muted);
        }
        .form-input, .form-select, .form-textarea {
            width: 100%;
            padding: 9px 12px;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
            font-family: inherit;
            font-size: 0.86rem;
            color: var(--text-primary);
            background: #ffffff;
            transition: border-color 0.15s;
        }
        .form-input:focus, .form-select:focus, .form-textarea:focus {
            outline: none;
            border-color: #4f46e5;
            box-shadow: 0 0 0 2px rgba(79, 70, 229, 0.15);
        }
        .form-textarea {
            font-family: 'JetBrains Mono', monospace;
            min-height: 100px;
            resize: vertical;
        }

        .dropzone {
            border: 1px dashed var(--border-hover);
            border-radius: var(--radius-lg);
            padding: 22px 16px;
            text-align: center;
            background: var(--surface-subtle);
            cursor: pointer;
            transition: all 0.15s;
        }
        .dropzone:hover {
            border-color: #4f46e5;
            background: #eef2ff;
        }
        .dropzone-icon {
            display: flex;
            justify-content: center;
            margin-bottom: 6px;
        }

        .mobile-drawer-overlay {
            display: none;
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.4);
            backdrop-filter: blur(4px);
            z-index: 999;
            opacity: 0;
            transition: opacity 0.2s ease;
        }
        .mobile-drawer-overlay.active {
            display: block;
            opacity: 1;
        }

        .mobile-drawer {
            position: fixed;
            top: 0;
            right: -300px;
            width: 280px;
            max-width: 85%;
            height: 100%;
            background: #ffffff;
            box-shadow: -4px 0 24px rgba(0, 0, 0, 0.15);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            transition: right 0.25s cubic-bezier(0.16, 1, 0.3, 1);
            overflow-y: auto;
        }
        .mobile-drawer.active {
            right: 0;
        }
        .drawer-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
        }
        .drawer-title {
            font-size: 0.95rem;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .drawer-close {
            background: none;
            border: none;
            font-size: 1.25rem;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px;
        }
        .drawer-body {
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .drawer-link {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 14px;
            border-radius: var(--radius-md);
            text-decoration: none;
            color: var(--text-primary);
            font-size: 0.88rem;
            font-weight: 600;
            background: var(--surface-subtle);
            border: 1px solid var(--border-light);
            transition: all 0.15s ease;
            cursor: pointer;
        }
        .drawer-link:hover, .drawer-link.active {
            background: #eef2ff;
            border-color: #c7d2fe;
            color: #4338ca;
        }
        .drawer-link.primary {
            background: #4f46e5;
            color: #ffffff;
            border-color: #4f46e5;
        }
        /* Nav Dropdown Menus */
        .nav-dropdown-group {
            position: relative;
            display: inline-block;
        }
        .nav-dropdown-trigger {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 11px;
            border-radius: var(--radius-md);
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            background: #ffffff;
            color: var(--text-primary);
            border: 1px solid var(--border);
            transition: all 0.15s ease;
            white-space: nowrap;
            user-select: none;
        }
        .nav-dropdown-trigger:hover, .nav-dropdown-group.open .nav-dropdown-trigger {
            background: var(--surface-subtle);
            border-color: var(--border-hover);
        }
        .nav-dropdown-trigger.active {
            background: #eef2ff;
            color: #4338ca;
            font-weight: 700;
            border-color: #c7d2fe;
        }
        .nav-dropdown-menu {
            position: absolute;
            top: 100%;
            left: 0;
            margin-top: 4px;
            min-width: 255px;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.12), 0 4px 6px -2px rgba(15, 23, 42, 0.05);
            padding: 6px;
            display: none;
            flex-direction: column;
            gap: 3px;
            z-index: 1000;
            animation: dropdownFade 0.15s cubic-bezier(0.16, 1, 0.3, 1);
        }
        /* Invisible pseudo-bridge to prevent mouseleave jitter */
        .nav-dropdown-menu::before {
            content: '';
            position: absolute;
            top: -8px;
            left: 0;
            right: 0;
            height: 8px;
        }
        /* Right alignment for rightmost dropdowns */
        .nav-dropdown-group.align-right .nav-dropdown-menu {
            left: auto;
            right: 0;
        }
        .nav-dropdown-group:hover .nav-dropdown-menu,
        .nav-dropdown-group.open .nav-dropdown-menu {
            display: flex;
        }
        @keyframes dropdownFade {
            from { opacity: 0; transform: translateY(-3px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .nav-dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 10px;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.12s ease;
            color: var(--text-primary);
        }
        .nav-dropdown-item:hover {
            background: #f8fafc;
        }
        .nav-dropdown-item.active {
            background: #eff6ff;
        }
        .nav-dropdown-item.active .nav-item-title {
            color: #2563eb;
            font-weight: 700;
        }
        .nav-item-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        .nav-item-title {
            font-size: 0.82rem;
            font-weight: 600;
            color: #0f172a;
            line-height: 1.25;
        }
        .nav-item-desc {
            font-size: 0.7rem;
            color: #64748b;
            line-height: 1.3;
            margin-top: 1px;
        }
        .drawer-section-title {
            font-size: 0.65rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--text-muted);
            padding: 8px 6px 4px 6px;
            margin-top: 4px;
        }

        @media (max-width: 900px) {
            .hamburger-btn { display: flex; }
            .header-actions { display: none; }
        }
    </style>
</head>
<body>

    <!-- Header -->
    <header>
        <div class="container header-inner">
            <a href="/dashboard/" class="brand">
                <div class="brand-logo-wrap">
                    <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect>
                        <rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect>
                        <line x1="6" y1="6" x2="6.01" y2="6"></line>
                        <line x1="6" y1="18" x2="6.01" y2="18"></line>
                    </svg>
                </div>
                <div class="brand-text">
                    <div class="brand-title">
                        <span>Shriyash Patil</span>
                        <span class="brand-pill">v<?= $phpVersion ?></span>
                    </div>
                    <div class="brand-subtitle">Workspace Suite &bull; Apache &bull; MariaDB</div>
                </div>
            </a>

            <!-- Categorized Desktop Navigation -->
            <div class="header-actions">
                <!-- Direct Core Links -->
                <a href="/dashboard/" class="btn btn-outline <?= $activeNav === 'home' ? 'active' : '' ?>">
                    <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                    <span>Overview</span>
                </a>
                <a href="/dashboard/projects.php" class="btn btn-outline <?= $activeNav === 'projects' ? 'active' : '' ?>">
                    <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                    <span>Projects</span>
                </a>
                <?php if (has_permission('can_files_browse')): ?>
                    <a href="/dashboard/explorer.php" class="btn btn-outline <?= $activeNav === 'explorer' ? 'active' : '' ?>">
                        <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        <span>Files</span>
                    </a>
                <?php endif; ?>

                <!-- Category 1: Deploy & Sync Dropdown -->
                <?php 
                    $canFtp = has_permission('can_ftp_deploy') || has_permission('can_ftp_explorer');
                    $canGit = has_permission('can_github_sync');
                ?>
                <?php if ($canFtp || $canGit): ?>
                    <?php $isDeployActive = in_array($activeNav, ['ftp', 'github']); ?>
                    <div class="nav-dropdown-group">
                        <button type="button" class="nav-dropdown-trigger <?= $isDeployActive ? 'active' : '' ?>">
                            <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                            <span>Deploy & Sync</span>
                            <svg class="w-3.5 h-3.5 text-gray-400 ml-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div class="nav-dropdown-menu">
                            <?php if ($canFtp): ?>
                                <a href="/dashboard/ftp.php" class="nav-dropdown-item <?= $activeNav === 'ftp' ? 'active' : '' ?>">
                                    <div class="nav-item-icon bg-indigo-50 text-indigo-600">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                                    </div>
                                    <div>
                                        <div class="nav-item-title">FTP Hosting Deploy</div>
                                        <div class="nav-item-desc">Deploy folders & remote filesystem explorer</div>
                                    </div>
                                </a>
                            <?php endif; ?>
                            <?php if ($canGit): ?>
                                <a href="/dashboard/github.php" class="nav-dropdown-item <?= $activeNav === 'github' ? 'active' : '' ?>">
                                    <div class="nav-item-icon bg-gray-100 text-gray-700">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.53 1.032 1.53 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z"/></svg>
                                    </div>
                                    <div>
                                        <div class="nav-item-title">GitHub Sync</div>
                                        <div class="nav-item-desc">Automated backups & repository sync</div>
                                    </div>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Category 2: Database Dropdown -->
                <?php if (has_permission('can_db_view')): ?>
                    <?php $isDbActive = in_array($activeNav, ['database']); ?>
                    <div class="nav-dropdown-group">
                        <button type="button" class="nav-dropdown-trigger <?= $isDbActive ? 'active' : '' ?>">
                            <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                            <span>Database</span>
                            <svg class="w-3.5 h-3.5 text-gray-400 ml-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div class="nav-dropdown-menu">
                            <a href="/dashboard/database.php" class="nav-dropdown-item <?= $activeNav === 'database' ? 'active' : '' ?>">
                                <div class="nav-item-icon bg-emerald-50 text-emerald-600">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                                </div>
                                <div>
                                    <div class="nav-item-title">MariaDB Manager</div>
                                    <div class="nav-item-desc">Tables, schema browser & SQL console</div>
                                </div>
                            </a>
                            <a href="http://server.shriyashpatil.in:2555" target="_blank" class="nav-dropdown-item">
                                <div class="nav-item-icon bg-purple-50 text-purple-600">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                </div>
                                <div>
                                    <div class="nav-item-title flex items-center gap-1">
                                        <span>phpMyAdmin Panel</span>
                                        <svg class="w-3 h-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                    </div>
                                    <div class="nav-item-desc">External database UI (Port 2555)</div>
                                </div>
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Category 3: Servers & CLI Dropdown -->
                <?php 
                    $canTerminal = has_permission('can_terminal_exec');
                    $canSSH = has_permission('can_ssh_connect');
                ?>
                <?php if ($canTerminal || $canSSH): ?>
                    <?php $isToolsActive = in_array($activeNav, ['terminal', 'ssh']); ?>
                    <div class="nav-dropdown-group align-right">
                        <button type="button" class="nav-dropdown-trigger <?= $isToolsActive ? 'active' : '' ?>">
                            <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            <span>Servers & CLI</span>
                            <svg class="w-3.5 h-3.5 text-gray-400 ml-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                        </button>
                        <div class="nav-dropdown-menu">
                            <?php if ($canTerminal): ?>
                                <a href="/dashboard/terminal.php" class="nav-dropdown-item <?= $activeNav === 'terminal' ? 'active' : '' ?>">
                                    <div class="nav-item-icon bg-purple-50 text-purple-600">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                    </div>
                                    <div>
                                        <div class="nav-item-title">Terminal & AGY CLI</div>
                                        <div class="nav-item-desc">Web bash terminal & AI agent shell</div>
                                    </div>
                                </a>
                            <?php endif; ?>
                            <?php if ($canSSH): ?>
                                <a href="/dashboard/ssh.php" class="nav-dropdown-item <?= $activeNav === 'ssh' ? 'active' : '' ?>">
                                    <div class="nav-item-icon bg-blue-50 text-blue-600">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/></svg>
                                    </div>
                                    <div>
                                        <div class="nav-item-title">SSH Client</div>
                                        <div class="nav-item-desc">Multi-host remote SSH sessions</div>
                                    </div>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Category 4: Account & Setup Dropdown (Grouped Settings, Account, Auto Setup) -->
                <?php $isSystemActive = in_array($activeNav, ['account', 'settings']); ?>
                <div class="nav-dropdown-group align-right">
                    <button type="button" class="nav-dropdown-trigger <?= $isSystemActive ? 'active' : '' ?>">
                        <svg class="w-4 h-4 text-slate-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                        <span><?= $isGuest ? 'Guest' : ($currentUser ? htmlspecialchars($currentUser['username']) : 'Account') ?></span>
                        <?php if ($isGuest): ?>
                            <span class="text-[9px] font-bold uppercase tracking-wider bg-amber-100 text-amber-800 border border-amber-300 px-1 py-0.2 rounded font-mono">Guest</span>
                        <?php else: ?>
                            <span class="w-2 h-2 rounded-full bg-emerald-500 inline-block"></span>
                        <?php endif; ?>
                        <svg class="w-3.5 h-3.5 text-gray-400 ml-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                    <div class="nav-dropdown-menu">
                        <!-- 1-Click Auto Setup Option -->
                        <?php if (has_permission('can_autosetup')): ?>
                            <button type="button" onclick="openModal('autoSetupModal')" class="nav-dropdown-item w-full text-left cursor-pointer">
                                <div class="nav-item-icon bg-indigo-50 text-indigo-600">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                </div>
                                <div>
                                    <div class="nav-item-title text-indigo-600 font-bold">1-Click Auto Setup</div>
                                    <div class="nav-item-desc">Import ZIP/Folder with automatic DB provisioning</div>
                                </div>
                            </button>
                        <?php endif; ?>

                        <!-- Account & Profile -->
                        <a href="/dashboard/account.php" class="nav-dropdown-item <?= $activeNav === 'account' ? 'active' : '' ?>">
                            <div class="nav-item-icon bg-slate-100 text-slate-700">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            </div>
                            <div>
                                <div class="nav-item-title">Account & Users</div>
                                <div class="nav-item-desc"><?= $isGuest ? 'Guest session (Read-Only)' : 'Manage credentials and team access' ?></div>
                            </div>
                        </a>

                        <!-- Universal Settings -->
                        <?php if (has_permission('can_system_settings')): ?>
                            <a href="/dashboard/settings.php" class="nav-dropdown-item <?= $activeNav === 'settings' ? 'active' : '' ?>">
                                <div class="nav-item-icon bg-slate-100 text-slate-700">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                </div>
                                <div>
                                    <div class="nav-item-title">Settings</div>
                                    <div class="nav-item-desc">Workspace, deployment & server defaults</div>
                                </div>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Mobile Hamburger Toggle Button -->
            <button class="hamburger-btn" onclick="toggleMobileDrawer()" aria-label="Toggle Navigation Menu">
                <svg class="w-5 h-5 text-gray-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
        </div>
    </header>

    <!-- Guest Mode Notification Bar -->
    <?php if ($isGuest): ?>
        <div class="bg-amber-500 text-white text-xs py-2 px-4 shadow-sm border-b border-amber-600/30">
            <div class="container flex flex-col sm:flex-row items-center justify-between gap-2 text-center sm:text-left">
                <div class="flex items-center gap-2 font-medium">
                    <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-amber-600/60 font-bold text-[11px]">&#9888;</span>
                    <span><strong>Guest Mode Active:</strong> You have read-only access to preview dashboards, files, and databases. Modifications and deployments are restricted.</span>
                </div>
                <div class="flex items-center gap-3">
                    <a href="/dashboard/login.php" class="underline font-bold hover:text-amber-100 transition whitespace-nowrap">Sign In with Account &rarr;</a>
                    <span class="opacity-60">•</span>
                    <a href="/dashboard/logout.php" class="underline hover:text-amber-100 transition whitespace-nowrap">Exit Guest Mode</a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Mobile Navigation Drawer (Categorized) -->
    <div class="mobile-drawer-overlay" id="drawerOverlay" onclick="toggleMobileDrawer()"></div>
    <div class="mobile-drawer" id="mobileDrawer">
        <div class="drawer-header">
            <div class="drawer-title">
                <svg class="w-4 h-4 text-gray-700" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="2" width="20" height="8" rx="2" ry="2"></rect><rect x="2" y="14" width="20" height="8" rx="2" ry="2"></rect></svg>
                <span>Workspace Menu</span>
            </div>
            <button class="drawer-close" onclick="toggleMobileDrawer()">&times;</button>
        </div>
        <div class="drawer-body">
            <?php if ($isGuest): ?>
                <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-xs text-amber-800 font-medium mb-1">
                    <strong>Guest Mode:</strong> Read-only preview access active.
                    <div class="mt-1.5 pt-1.5 border-t border-amber-200">
                        <a href="/dashboard/login.php" class="text-amber-900 font-bold underline">Sign In with Admin Account &rarr;</a>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (has_permission('can_autosetup')): ?>
                <button onclick="toggleMobileDrawer(); openModal('autoSetupModal');" class="drawer-link primary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                    <span>1-Click Auto Setup</span>
                </button>
            <?php endif; ?>

            <!-- Section: Workspace -->
            <div class="drawer-section-title">Workspace Core</div>
            <a href="/dashboard/" onclick="toggleMobileDrawer()" class="drawer-link <?= $activeNav === 'home' ? 'active' : '' ?>">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                <span>Overview Dashboard</span>
            </a>
            <a href="/dashboard/projects.php" onclick="toggleMobileDrawer()" class="drawer-link <?= $activeNav === 'projects' ? 'active' : '' ?>">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z"/></svg>
                <span>Projects & Apps</span>
            </a>
            <?php if (has_permission('can_files_browse')): ?>
                <a href="/dashboard/explorer.php" onclick="toggleMobileDrawer()" class="drawer-link <?= $activeNav === 'explorer' ? 'active' : '' ?>">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    <span>File Explorer & Editor</span>
                </a>
            <?php endif; ?>

            <!-- Section: Deploy & Sync -->
            <?php if ($canFtp || $canGit): ?>
                <div class="drawer-section-title">Deployment & Cloud</div>
                <?php if ($canFtp): ?>
                    <a href="/dashboard/ftp.php" onclick="toggleMobileDrawer()" class="drawer-link <?= $activeNav === 'ftp' ? 'active' : '' ?>">
                        <svg class="w-4 h-4 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
                        <span>FTP Hosting Deploy</span>
                    </a>
                <?php endif; ?>
                <?php if ($canGit): ?>
                    <a href="/dashboard/github.php" onclick="toggleMobileDrawer()" class="drawer-link <?= $activeNav === 'github' ? 'active' : '' ?>">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path fill-rule="evenodd" clip-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12.017c0 4.425 2.865 8.18 6.839 9.504.5.092.682-.217.682-.483 0-.237-.008-.868-.013-1.703-2.782.605-3.369-1.343-3.369-1.343-.454-1.158-1.11-1.466-1.11-1.466-.908-.62.069-.608.069-.608 1.003.07 1.53 1.032 1.53 1.032.892 1.53 2.341 1.088 2.91.832.092-.647.35-1.088.636-1.338-2.22-.253-4.555-1.113-4.555-4.951 0-1.093.39-1.988 1.029-2.688-.103-.253-.446-1.272.098-2.65 0 0 .84-.27 2.75 1.026A9.564 9.564 0 0112 6.844c.85.004 1.705.115 2.504.337 1.909-1.296 2.747-1.027 2.747-1.027.546 1.379.202 2.398.1 2.651.64.7 1.028 1.595 1.028 2.688 0 3.848-2.339 4.695-4.566 4.943.359.309.678.92.678 1.855 0 1.338-.012 2.419-.012 2.747 0 .268.18.58.688.482A10.019 10.019 0 0022 12.017C22 6.484 17.522 2 12 2z"/></svg>
                        <span>GitHub Sync</span>
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Section: Database -->
            <?php if (has_permission('can_db_view')): ?>
                <div class="drawer-section-title">Database & Storage</div>
                <a href="/dashboard/database.php" onclick="toggleMobileDrawer()" class="drawer-link <?= $activeNav === 'database' ? 'active' : '' ?>">
                    <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
                    <span>MariaDB Manager</span>
                </a>
                <a href="http://server.shriyashpatil.in:2555" target="_blank" class="drawer-link">
                    <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                    <span>phpMyAdmin Panel</span>
                </a>
            <?php endif; ?>

            <!-- Section: Servers & CLI -->
            <?php if ($canTerminal || $canSSH): ?>
                <div class="drawer-section-title">Servers & Terminal</div>
                <?php if ($canTerminal): ?>
                    <a href="/dashboard/terminal.php" onclick="toggleMobileDrawer()" class="drawer-link <?= $activeNav === 'terminal' ? 'active' : '' ?>">
                        <svg class="w-4 h-4 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                        <span>Terminal & AGY CLI</span>
                    </a>
                <?php endif; ?>
                <?php if ($canSSH): ?>
                    <a href="/dashboard/ssh.php" onclick="toggleMobileDrawer()" class="drawer-link <?= $activeNav === 'ssh' ? 'active' : '' ?>">
                        <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/></svg>
                        <span>SSH Multi-Host Client</span>
                    </a>
                <?php endif; ?>
            <?php endif; ?>

            <!-- Section: System & Account -->
            <div class="drawer-section-title">System & Account</div>
            <?php if (has_permission('can_system_settings')): ?>
                <a href="/dashboard/settings.php" onclick="toggleMobileDrawer()" class="drawer-link <?= $activeNav === 'settings' ? 'active' : '' ?>">
                    <svg class="w-4 h-4 text-slate-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                    <span>Settings</span>
                </a>
            <?php endif; ?>
            <a href="/dashboard/account.php" onclick="toggleMobileDrawer()" class="drawer-link <?= $activeNav === 'account' ? 'active' : '' ?>">
                <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                <span>Account & Users <?= $isGuest ? '(Guest)' : '' ?></span>
            </a>
        </div>
    </div>

    <main class="container py-8 flex-1">
        <!-- Flash Alert -->
        <?php if ($flash): ?>
            <div class="alert-box alert-<?= htmlspecialchars($flash['type']) ?> mb-6">
                <div style="display:flex; align-items:center; gap:8px;">
                    <?php if ($flash['type'] === 'success'): ?>
                        <svg class="w-5 h-5 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    <?php else: ?>
                        <svg class="w-5 h-5 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <?php endif; ?>
                    <span><?= htmlspecialchars($flash['msg']) ?></span>
                </div>
                <button onclick="this.parentElement.remove()" style="background:none; border:none; cursor:pointer; font-size:1.1rem; color:inherit;">&times;</button>
            </div>
        <?php endif; ?>
