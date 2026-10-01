<?php
require_once __DIR__ . '/includes/core.php';
require_permission('can_files_browse', 'Access denied. You do not have permission to view code in the Editor.');

$filePath = trim($_GET['file'] ?? '');
if (strpos($filePath, '..') !== false || empty($filePath)) {
    header('Location: /dashboard/explorer.php');
    exit;
}

$fullPath = WWW_ROOT . '/' . ltrim($filePath, '/');
if (!file_exists($fullPath) || !is_file($fullPath)) {
    set_flash('error', "File not found: " . htmlspecialchars($filePath));
    header('Location: /dashboard/explorer.php');
    exit;
}

$isGuest = is_guest();

// Handle AJAX or POST save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_file'])) {
    require_not_guest('File saving is disabled in Guest Mode.');
    require_permission('can_files_edit', 'Access denied. You do not have permission to edit and save files.');
    $content = $_POST['file_content'] ?? '';
    $saved = @file_put_contents($fullPath, $content);
    
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        if ($saved !== false) {
            echo json_encode(['success' => true, 'message' => 'File saved successfully', 'modified' => date('Y-m-d H:i:s')]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Failed to save file. Check file permissions.']);
        }
        exit;
    }
    
    if ($saved !== false) {
        set_flash('success', "File '" . basename($filePath) . "' saved successfully.");
    } else {
        set_flash('error', "Could not write to file. Check file permissions.");
    }
    header('Location: /dashboard/editor.php?file=' . urlencode($filePath));
    exit;
}

$content = file_get_contents($fullPath);
$ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
$fileName = basename($filePath);
$dirName = dirname($filePath) === '.' ? '' : dirname($filePath);
$fileSize = filesize($fullPath);
$fileModified = date('Y-m-d H:i:s', filemtime($fullPath));

// Determine Ace/Code editor mode based on extension
$modeMap = [
    'php' => 'php',
    'js' => 'javascript',
    'json' => 'json',
    'css' => 'css',
    'scss' => 'scss',
    'html' => 'html',
    'htm' => 'html',
    'sql' => 'sql',
    'sh' => 'sh',
    'bash' => 'sh',
    'py' => 'python',
    'xml' => 'xml',
    'svg' => 'svg',
    'md' => 'markdown',
    'markdown' => 'markdown',
    'yaml' => 'yaml',
    'yml' => 'yaml',
    'env' => 'text',
    'htaccess' => 'apache_conf',
    'txt' => 'text',
    'ini' => 'ini',
    'conf' => 'text'
];
$editorMode = $modeMap[$ext] ?? 'text';
$pageTitle = 'Edit: ' . $fileName;
$activeNav = 'explorer';
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title><?= htmlspecialchars($fileName) ?> &bull; Code Editor</title>
    <link rel="icon" type="image/svg+xml" href="/dashboard/favicon.svg">
    <link rel="alternate icon" href="/dashboard/favicon.ico">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Ace Editor for High-Performance Desktop & Mobile Code Editing -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.7/ace.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.7/ext-language_tools.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.7/theme-monokai.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.7/theme-github.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/ace/1.32.7/theme-dracula.min.js"></script>
    <style>
        :root {
            --editor-top-h: 56px;
            --editor-bot-h: 36px;
        }
        * { box-sizing: border-box; }
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            overflow: hidden;
            background-color: #1e1e2e;
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            -webkit-text-size-adjust: 100%;
        }
        #editor-container {
            width: 100%;
            height: calc(100vh - var(--editor-top-h) - var(--editor-bot-h));
            font-size: 14px;
            font-family: 'JetBrains Mono', monospace;
        }
        @media (max-width: 640px) {
            #editor-container {
                font-size: 13px;
            }
        }
        .ace_editor {
            line-height: 1.5;
        }
        .toast-notification {
            transform: translateY(-100%);
            transition: transform 0.25s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.25s;
        }
        .toast-notification.active {
            transform: translateY(0);
        }
    </style>
</head>
<body class="flex flex-col h-full bg-[#181825] text-gray-200 select-none">

    <!-- Top Navigation & Action Header -->
    <header class="h-[56px] bg-[#11111b] border-b border-gray-800 px-3 sm:px-4 flex items-center justify-between gap-2 z-30 shrink-0">
        <div class="flex items-center gap-2 sm:gap-3 min-w-0">
            <a href="/dashboard/explorer.php?dir=<?= urlencode($dirName) ?>" title="Return to Explorer" class="p-1.5 sm:px-2.5 sm:py-1.5 rounded-lg bg-gray-800 hover:bg-gray-700 text-gray-300 hover:text-white text-xs font-semibold flex items-center gap-1.5 transition shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
                <span class="hidden sm:inline">Explorer</span>
            </a>
            <div class="flex items-center gap-1.5 min-w-0">
                <span class="w-2.5 h-2.5 rounded-full bg-indigo-500 shrink-0"></span>
                <div class="min-w-0 flex flex-col sm:flex-row sm:items-center sm:gap-2">
                    <span class="text-xs sm:text-sm font-mono font-bold text-white truncate"><?= htmlspecialchars($fileName) ?></span>
                    <span class="text-[10px] sm:text-xs text-gray-400 font-mono truncate hidden md:inline">/<?= htmlspecialchars($filePath) ?></span>
                    <?php if ($isGuest): ?>
                        <span class="text-[10px] font-bold uppercase tracking-wider bg-amber-500/20 text-amber-300 border border-amber-500/40 px-1.5 py-0.5 rounded font-mono">Guest Mode (Read-Only)</span>
                    <?php endif; ?>
                </div>
                <span id="unsavedIndicator" class="hidden text-amber-400 font-bold text-xs" title="Unsaved changes">*</span>
            </div>
        </div>

        <!-- Header Actions: Theme, Font, Save, Explorer -->
        <div class="flex items-center gap-1.5 sm:gap-2 shrink-0">
            <!-- Theme Selector -->
            <select id="themeSelect" onchange="changeEditorTheme(this.value)" class="bg-gray-800 text-gray-300 text-xs rounded-lg px-2 py-1.5 border border-gray-700 focus:outline-none focus:ring-1 focus:ring-indigo-500 hidden sm:block">
                <option value="ace/theme/dracula" selected>Dracula</option>
                <option value="ace/theme/monokai">Monokai</option>
                <option value="ace/theme/github">Light (GitHub)</option>
            </select>

            <!-- Font Sizer (Mobile friendly) -->
            <button type="button" onclick="adjustFontSize(-1)" class="w-7 h-7 sm:w-8 sm:h-8 flex items-center justify-center bg-gray-800 hover:bg-gray-700 rounded-lg text-gray-300 text-xs font-bold transition" title="Decrease font size">A-</button>
            <button type="button" onclick="adjustFontSize(1)" class="w-7 h-7 sm:w-8 sm:h-8 flex items-center justify-center bg-gray-800 hover:bg-gray-700 rounded-lg text-gray-300 text-xs font-bold transition" title="Increase font size">A+</button>

            <!-- Save Button -->
            <?php if ($isGuest): ?>
                <button type="button" onclick="showToast('File saving is disabled in Guest Mode (Read-Only).', false)" class="px-3 sm:px-4 py-1.5 bg-gray-700 text-gray-400 rounded-lg text-xs font-bold flex items-center gap-1.5 cursor-not-allowed">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    <span>Read Only</span>
                </button>
            <?php else: ?>
                <button type="button" id="saveBtn" onclick="saveEditorContent()" class="px-3 sm:px-4 py-1.5 bg-indigo-600 hover:bg-indigo-500 text-white rounded-lg text-xs font-bold flex items-center gap-1.5 shadow-md hover:shadow-indigo-500/20 transition cursor-pointer active:scale-95">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
                    <span>Save</span>
                    <span class="hidden md:inline text-[10px] opacity-70 font-normal">(Ctrl+S)</span>
                </button>
            <?php endif; ?>
        </div>
    </header>

    <!-- Toast Notification Overlay -->
    <div id="toast" class="toast-notification fixed top-16 left-1/2 -translate-x-1/2 z-50 px-4 py-2 rounded-xl text-xs font-semibold shadow-xl border flex items-center gap-2 pointer-events-none opacity-0 bg-emerald-950 text-emerald-200 border-emerald-700">
        <svg class="w-4 h-4 text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
        <span id="toastMsg">Saved successfully!</span>
    </div>

    <!-- Main Ace Editor Container -->
    <main class="flex-1 w-full relative">
        <div id="editor-container"><?= htmlspecialchars($content) ?></div>
    </main>

    <!-- Bottom Status Bar -->
    <footer class="h-[36px] bg-[#11111b] border-t border-gray-800 px-3 sm:px-4 flex items-center justify-between text-xs text-gray-400 font-mono z-30 shrink-0">
        <div class="flex items-center gap-3 truncate">
            <span class="flex items-center gap-1">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                <span id="cursorPos">Ln 1, Col 1</span>
            </span>
            <span class="hidden sm:inline">&bull;</span>
            <span class="hidden sm:inline" id="fileStats"><?= count(explode("\n", $content)) ?> lines &bull; <?= number_format($fileSize / 1024, 1) ?> KB</span>
        </div>
        <div class="flex items-center gap-3">
            <span class="uppercase font-semibold text-gray-300 text-[11px] bg-gray-800 px-2 py-0.5 rounded border border-gray-700"><?= htmlspecialchars($editorMode) ?></span>
            <span id="saveStatus" class="text-gray-400 text-[11px]">Ready</span>
        </div>
    </footer>

    <script>
        const editor = ace.edit("editor-container");
        editor.setTheme("ace/theme/dracula");
        editor.session.setMode("ace/mode/<?= $editorMode ?>");
        editor.setOptions({
            fontSize: "14px",
            showPrintMargin: false,
            wrap: true,
            tabSize: 4,
            useSoftTabs: true,
            showLineNumbers: true,
            showGutter: true,
            highlightActiveLine: true,
            enableBasicAutocompletion: true,
            enableLiveAutocompletion: true,
            enableSnippets: true
        });

        let isDirty = false;
        const unsavedIndicator = document.getElementById('unsavedIndicator');
        const saveStatus = document.getElementById('saveStatus');
        const cursorPos = document.getElementById('cursorPos');

        editor.selection.on('changeCursor', function() {
            const pos = editor.selection.getCursor();
            cursorPos.textContent = `Ln ${pos.row + 1}, Col ${pos.column + 1}`;
        });

        editor.on('change', function() {
            if (!isDirty) {
                isDirty = true;
                unsavedIndicator.classList.remove('hidden');
                saveStatus.textContent = 'Modified';
                saveStatus.className = 'text-amber-400 text-[11px]';
            }
        });

        // Keybinding: Ctrl+S or Cmd+S
        editor.commands.addCommand({
            name: 'save',
            bindKey: {win: 'Ctrl-S', mac: 'Command-S'},
            exec: function(editor) {
                saveEditorContent();
            }
        });

        function adjustFontSize(delta) {
            let currentSize = parseInt(editor.getFontSize()) || 14;
            let newSize = Math.max(10, Math.min(28, currentSize + delta));
            editor.setFontSize(newSize + "px");
            localStorage.setItem('code_editor_fontsize', newSize);
        }

        // Load saved font size
        const savedFontSize = localStorage.getItem('code_editor_fontsize');
        if (savedFontSize) {
            editor.setFontSize(savedFontSize + "px");
        }

        function changeEditorTheme(theme) {
            editor.setTheme(theme);
            localStorage.setItem('code_editor_theme', theme);
        }

        const savedTheme = localStorage.getItem('code_editor_theme');
        if (savedTheme) {
            editor.setTheme(savedTheme);
            const select = document.getElementById('themeSelect');
            if (select) select.value = savedTheme;
        }

        function showToast(msg, isSuccess = true) {
            const toast = document.getElementById('toast');
            const toastMsg = document.getElementById('toastMsg');
            toastMsg.textContent = msg;
            
            if (isSuccess) {
                toast.className = 'toast-notification fixed top-16 left-1/2 -translate-x-1/2 z-50 px-4 py-2 rounded-xl text-xs font-semibold shadow-xl border flex items-center gap-2 pointer-events-none opacity-100 active bg-emerald-950 text-emerald-200 border-emerald-700';
            } else {
                toast.className = 'toast-notification fixed top-16 left-1/2 -translate-x-1/2 z-50 px-4 py-2 rounded-xl text-xs font-semibold shadow-xl border flex items-center gap-2 pointer-events-none opacity-100 active bg-red-950 text-red-200 border-red-700';
            }

            setTimeout(() => {
                toast.classList.remove('active');
                toast.classList.add('opacity-0');
            }, 2500);
        }

        function saveEditorContent() {
            const saveBtn = document.getElementById('saveBtn');
            const content = editor.getValue();
            saveBtn.disabled = true;
            saveStatus.textContent = 'Saving...';
            saveStatus.className = 'text-indigo-400 text-[11px] animate-pulse';

            const formData = new FormData();
            formData.append('save_file', '1');
            formData.append('file_path', <?= json_encode($filePath) ?>);
            formData.append('file_content', content);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', '/dashboard/editor.php?file=' + encodeURIComponent(<?= json_encode($filePath) ?>), true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

            xhr.onload = function() {
                saveBtn.disabled = false;
                if (xhr.status >= 200 && xhr.status < 300) {
                    isDirty = false;
                    unsavedIndicator.classList.add('hidden');
                    saveStatus.textContent = 'Saved';
                    saveStatus.className = 'text-emerald-400 text-[11px]';
                    showToast('File saved successfully!', true);
                } else {
                    saveStatus.textContent = 'Save Failed';
                    saveStatus.className = 'text-red-400 text-[11px]';
                    showToast('Failed to save file. Check permissions.', false);
                }
            };

            xhr.onerror = function() {
                saveBtn.disabled = false;
                saveStatus.textContent = 'Error';
                saveStatus.className = 'text-red-400 text-[11px]';
                showToast('Network error while saving.', false);
            };

            xhr.send(formData);
        }

        // Warn before leaving if unsaved changes
        window.addEventListener('beforeunload', function(e) {
            if (isDirty) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    </script>
</body>
</html>
