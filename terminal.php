<?php
require_once __DIR__ . '/includes/core.php';
require_permission('can_terminal_exec', 'Access denied. You do not have permission to use the Web Terminal & CLI.');

$isGuest = is_guest();
$currentUser = current_user();

// Initialize or get current working directory from session
if (!isset($_SESSION['terminal_cwd']) || !is_dir($_SESSION['terminal_cwd'])) {
    $_SESSION['terminal_cwd'] = WWW_ROOT;
}

// Handle AJAX Command Execution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['exec_cmd'])) {
    header('Content-Type: application/json');
    $rawCmd = trim($_POST['cmd'] ?? '');

    if (empty($rawCmd)) {
        echo json_encode(['success' => true, 'output' => '', 'cwd' => $_SESSION['terminal_cwd']]);
        exit;
    }

    $currentCwd = $_SESSION['terminal_cwd'];
    if (!is_dir($currentCwd)) {
        $currentCwd = WWW_ROOT;
        $_SESSION['terminal_cwd'] = $currentCwd;
    }

    // Guest Mode Safety Filter
    if ($isGuest) {
        $dangerousPatterns = [
            '/\brm\s+(-[a-zA-Z]*r|--recursive)/i',
            '/\b(mkfs|dd|fdisk|parted)\b/i',
            '/\b(shutdown|reboot|poweroff|init)\b/i',
            '/\bchmod\s+(-R\s+)?(777|000)\b/i',
            '/\buser(add|del|mod)\b/i',
            '/\b(DROP|TRUNCATE)\s+(DATABASE|TABLE)\b/i'
        ];
        foreach ($dangerousPatterns as $pattern) {
            if (preg_match($pattern, $rawCmd)) {
                echo json_encode([
                    'success' => false,
                    'output' => "\033[1;31m[Guest Mode Restricted]\033[0m Destructive or system modifying command is blocked in Guest Mode.\n",
                    'cwd' => $currentCwd
                ]);
                exit;
            }
        }
    }

    // Handle internal 'cd' command specially to persist state
    if (preg_match('/^cd(?:\s+(.*))?$/', $rawCmd, $m)) {
        $target = trim($m[1] ?? '');
        if (empty($target) || $target === '~') {
            $newCwd = WWW_ROOT;
        } elseif (str_starts_with($target, '/')) {
            $newCwd = realpath($target);
        } else {
            $newCwd = realpath($currentCwd . '/' . $target);
        }

        if ($newCwd && is_dir($newCwd)) {
            $_SESSION['terminal_cwd'] = $newCwd;
            echo json_encode([
                'success' => true,
                'output' => "",
                'cwd' => $newCwd,
                'is_cd' => true
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'output' => "\033[1;31mcd: no such directory: " . htmlspecialchars($target) . "\033[0m\n",
                'cwd' => $currentCwd
            ]);
        }
        exit;
    }

    // Prepare descriptors and environment
    $descriptors = [
        0 => ["pipe", "r"], // stdin
        1 => ["pipe", "w"], // stdout
        2 => ["pipe", "w"], // stderr
    ];

    $env = array_merge($_ENV, [
        'HOME' => '/var/www',
        'TERM' => 'xterm-256color',
        'PATH' => '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
        'PWD' => $currentCwd,
        'LANG' => 'C.UTF-8',
        'LC_ALL' => 'C.UTF-8'
    ]);

    // Use bash if available, else sh
    $bashPath = file_exists('/bin/bash') ? '/bin/bash' : '/bin/sh';
    $process = proc_open($bashPath, $descriptors, $pipes, $currentCwd, $env);

    if (is_resource($process)) {
        // Send command and exit
        fwrite($pipes[0], $rawCmd . "\nexit $?\n");
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        $output = $stdout;
        if (!empty($stderr)) {
            $output .= ($output ? "\n" : "") . $stderr;
        }

        echo json_encode([
            'success' => ($exitCode === 0),
            'output' => $output,
            'exit_code' => $exitCode,
            'cwd' => $_SESSION['terminal_cwd']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'output' => "\033[1;31mFailed to spawn shell process.\033[0m\n",
            'cwd' => $currentCwd
        ]);
    }
    exit;
}

$pageTitle = 'Terminal & AGY CLI';
$activeNav = 'terminal';
include __DIR__ . '/includes/header.php';
?>

<div class="mb-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
    <div>
        <div class="flex items-center gap-2.5">
            <h1 class="text-2xl font-bold text-gray-900 tracking-tight">Interactive Terminal & AGY CLI</h1>
            <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold font-mono uppercase bg-indigo-50 text-indigo-700 border border-indigo-200">
                Bash &bull; agy v1.2.0
            </span>
            <?php if ($isGuest): ?>
                <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-bold uppercase bg-amber-50 text-amber-700 border border-amber-200">
                    Guest Mode (Restricted)
                </span>
            <?php endif; ?>
        </div>
        <p class="text-xs sm:text-sm text-gray-500 mt-1">Direct command execution, process inspector, and Google Antigravity (<code class="px-1 py-0.5 bg-gray-100 rounded text-indigo-600 font-mono text-xs">agy</code>) AI developer tooling.</p>
    </div>
    
    <div class="flex items-center gap-2">
        <button type="button" onclick="clearTerminal()" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-white border border-gray-200 rounded-lg text-xs font-semibold text-gray-700 hover:bg-gray-50 transition shadow-xs">
            <svg class="w-3.5 h-3.5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
            <span>Clear Output</span>
        </button>
        <button type="button" onclick="toggleTerminalFullscreen()" id="btnFullscreenTerm" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-600 hover:bg-indigo-700 text-white rounded-lg text-xs font-semibold transition shadow-xs">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 8V4m0 0h4M4 4l5 5m11-5h-4m4 0v4m0-4l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4"/></svg>
            <span id="fullscreenBtnText">Fullscreen</span>
        </button>
    </div>
</div>

<!-- Terminal Modes Tab Bar -->
<div class="flex items-center gap-1 mb-4 border-b border-gray-200">
    <a href="/dashboard/terminal.php" class="inline-flex items-center gap-2 px-4 py-2 text-xs font-bold text-indigo-600 border-b-2 border-indigo-600 bg-white rounded-t-lg transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        <span>Bash & AGY Shell</span>
    </a>
    <a href="/dashboard/database.php?tab=terminal" class="inline-flex items-center gap-2 px-4 py-2 text-xs font-semibold text-gray-500 hover:text-gray-900 border-b-2 border-transparent transition">
        <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/></svg>
        <span>MariaDB SQL Terminal</span>
        <span class="px-1.5 py-0.2 bg-emerald-100 text-emerald-800 rounded font-mono text-[10px] font-bold">CLI</span>
    </a>
</div>

<!-- AGY & Quick Shortcuts Toolbar -->
<div class="bg-white border border-gray-200 rounded-xl p-3 shadow-xs mb-4 flex flex-wrap items-center justify-between gap-3">
    <div class="flex flex-wrap items-center gap-1.5">
        <span class="text-xs font-bold text-gray-700 uppercase tracking-wider mr-1 flex items-center gap-1">
            <svg class="w-3.5 h-3.5 text-indigo-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/><path d="M2 12h20"/></svg>
            AGY Shortcuts:
        </span>
        <button type="button" onclick="runPresetCommand('agy --version')" class="px-2.5 py-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 rounded text-xs font-mono font-semibold transition cursor-pointer">agy --version</button>
        <button type="button" onclick="runPresetCommand('agy --help')" class="px-2.5 py-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 rounded text-xs font-mono font-semibold transition cursor-pointer">agy --help</button>
        <button type="button" onclick="runPresetCommand('which agy')" class="px-2.5 py-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 rounded text-xs font-mono font-semibold transition cursor-pointer">which agy</button>
        <button type="button" onclick="runPresetCommand('agy status')" class="px-2.5 py-1 bg-indigo-50 hover:bg-indigo-100 text-indigo-700 border border-indigo-200 rounded text-xs font-mono font-semibold transition cursor-pointer">agy status</button>
    </div>

    <div class="flex flex-wrap items-center gap-1.5">
        <span class="text-xs font-bold text-gray-500 uppercase tracking-wider mr-1">Shell:</span>
        <button type="button" onclick="runPresetCommand('ls -la')" class="px-2 py-1 bg-gray-100 hover:bg-gray-200 text-gray-800 rounded text-xs font-mono transition cursor-pointer">ls -la</button>
        <button type="button" onclick="runPresetCommand('pwd')" class="px-2 py-1 bg-gray-100 hover:bg-gray-200 text-gray-800 rounded text-xs font-mono transition cursor-pointer">pwd</button>
        <button type="button" onclick="runPresetCommand('php -v')" class="px-2 py-1 bg-gray-100 hover:bg-gray-200 text-gray-800 rounded text-xs font-mono transition cursor-pointer">php -v</button>
        <button type="button" onclick="runPresetCommand('git status')" class="px-2 py-1 bg-gray-100 hover:bg-gray-200 text-gray-800 rounded text-xs font-mono transition cursor-pointer">git status</button>
        <button type="button" onclick="runPresetCommand('mysql -h db -u root -e \'SHOW DATABASES;\'')" class="px-2 py-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-800 border border-emerald-200 rounded text-xs font-mono transition cursor-pointer">mysql dbs</button>
    </div>
</div>

<!-- Main Terminal Window Container -->
<div id="terminalContainer" class="bg-[#0f141c] border border-gray-800 rounded-2xl shadow-xl overflow-hidden flex flex-col transition-all duration-200" style="min-height: 520px; height: calc(100vh - 280px);">
    <!-- Terminal Titlebar -->
    <div class="bg-[#171d28] border-b border-gray-800/80 px-4 py-2.5 flex items-center justify-between gap-3 flex-shrink-0 select-none">
        <div class="flex items-center gap-2">
            <div class="flex items-center gap-1.5">
                <span class="w-3 h-3 rounded-full bg-[#ff5f56] inline-block"></span>
                <span class="w-3 h-3 rounded-full bg-[#ffbd2e] inline-block"></span>
                <span class="w-3 h-3 rounded-full bg-[#27c93f] inline-block"></span>
            </div>
            <span class="ml-2 font-mono text-xs font-semibold text-gray-300 flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                <span id="termSessionTitle">root@workspace:<span id="termCwdTitle" class="text-indigo-300"><?= htmlspecialchars($_SESSION['terminal_cwd']) ?></span></span>
            </span>
        </div>

        <div class="flex items-center gap-2">
            <!-- Theme selector -->
            <select id="termThemeSelect" onchange="setTerminalTheme(this.value)" class="bg-gray-800 text-gray-300 text-[11px] rounded px-2 py-1 border border-gray-700 focus:outline-none">
                <option value="cyber">Cyber Dark</option>
                <option value="dracula">Dracula</option>
                <option value="matrix">Matrix Green</option>
                <option value="solarized">Solarized Dark</option>
            </select>
            <!-- Font zoom -->
            <button type="button" onclick="adjustTermFont(-1)" class="w-6 h-6 flex items-center justify-center bg-gray-800 hover:bg-gray-700 text-gray-300 rounded text-xs font-bold" title="Decrease font size">A-</button>
            <button type="button" onclick="adjustTermFont(1)" class="w-6 h-6 flex items-center justify-center bg-gray-800 hover:bg-gray-700 text-gray-300 rounded text-xs font-bold" title="Increase font size">A+</button>
            <button type="button" onclick="copyTerminalText()" class="px-2 py-1 bg-gray-800 hover:bg-gray-700 text-gray-300 rounded text-[11px] font-medium" title="Copy output">Copy</button>
        </div>
    </div>

    <!-- Terminal Output Screen -->
    <div id="termScreen" class="flex-1 p-4 overflow-y-auto font-mono text-xs sm:text-sm leading-relaxed text-gray-200 select-text" style="scrollbar-width: thin; scrollbar-color: #334155 #0f141c;">
        <div class="text-emerald-400 font-bold mb-1">
            🚀 Shriyash Patil Workspace Terminal v2.4
        </div>
        <div class="text-gray-400 text-xs mb-3">
            Type <span class="text-indigo-400 font-semibold font-mono">agy --help</span> or <span class="text-indigo-400 font-semibold font-mono">agy &lt;command&gt;</span> to run Antigravity CLI tools. Interactive session active.
        </div>
        <div id="termHistory"></div>
        <div id="termCurrentPromptRow" class="flex items-start gap-2 mt-1">
            <span class="text-emerald-400 font-bold select-none flex-shrink-0" id="promptUserHost">root@workspace</span><span class="text-gray-500 select-none">:</span><span class="text-indigo-400 font-semibold select-none flex-shrink-0" id="promptCwdDisplay"><?= htmlspecialchars($_SESSION['terminal_cwd']) ?></span><span class="text-gray-400 select-none font-bold">$</span>
            <div class="flex-1 min-w-0 relative">
                <input type="text" id="termInput" autocomplete="off" spellcheck="false" autofocus class="w-full bg-transparent text-gray-100 font-mono outline-none border-none p-0 m-0 text-xs sm:text-sm leading-relaxed caret-indigo-400">
            </div>
        </div>
    </div>

    <!-- Terminal Footer Status Bar -->
    <div class="bg-[#121720] border-t border-gray-800 px-4 py-1.5 flex items-center justify-between text-[11px] font-mono text-gray-400 flex-shrink-0">
        <div class="flex items-center gap-3">
            <span class="flex items-center gap-1.5 text-emerald-400 font-semibold">
                <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                ONLINE
            </span>
            <span class="text-gray-600">|</span>
            <span>Shell: /bin/bash</span>
            <span class="text-gray-600">|</span>
            <span id="termStatusText">Ready for input</span>
        </div>
        <div class="flex items-center gap-3">
            <span class="text-gray-500 hidden sm:inline">Use &uarr;&darr; for history</span>
            <span id="termCwdBadge" class="text-indigo-300 font-semibold truncate max-w-xs"><?= htmlspecialchars($_SESSION['terminal_cwd']) ?></span>
        </div>
    </div>
</div>

<style>
/* Terminal Themes */
.term-theme-cyber { background-color: #0f141c !important; color: #f1f5f9 !important; }
.term-theme-dracula { background-color: #282a36 !important; color: #f8f8f2 !important; }
.term-theme-matrix { background-color: #051405 !important; color: #39ff14 !important; }
.term-theme-solarized { background-color: #002b36 !important; color: #93a1a1 !important; }

.term-fullscreen {
    position: fixed !important;
    top: 0 !important;
    left: 0 !important;
    right: 0 !important;
    bottom: 0 !important;
    width: 100vw !important;
    height: 100vh !important;
    z-index: 99999 !important;
    border-radius: 0 !important;
    margin: 0 !important;
}
</style>

<script>
let termHistoryIndex = -1;
let commandHistory = JSON.parse(localStorage.getItem('terminal_cmd_history') || '[]');
let currentCwd = <?= json_encode($_SESSION['terminal_cwd']) ?>;
let isExecuting = false;
let currentFontSize = parseInt(localStorage.getItem('terminal_font_size') || '13');

const termInput = document.getElementById('termInput');
const termScreen = document.getElementById('termScreen');
const termHistory = document.getElementById('termHistory');
const promptCwdDisplay = document.getElementById('promptCwdDisplay');
const termCwdTitle = document.getElementById('termCwdTitle');
const termCwdBadge = document.getElementById('termCwdBadge');
const termStatusText = document.getElementById('termStatusText');

// Apply initial font size
applyFontSize();

// Focus input on click anywhere inside terminal
termScreen.addEventListener('click', function(e) {
    if (window.getSelection().toString().length === 0) {
        termInput.focus();
    }
});

// Key navigation & input handling
termInput.addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        const cmd = termInput.value.trim();
        if (cmd) {
            commandHistory.push(cmd);
            if (commandHistory.length > 200) commandHistory.shift();
            localStorage.setItem('terminal_cmd_history', JSON.stringify(commandHistory));
        }
        termHistoryIndex = -1;
        executeCommand(cmd);
    } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        if (commandHistory.length === 0) return;
        if (termHistoryIndex === -1) {
            termHistoryIndex = commandHistory.length - 1;
        } else if (termHistoryIndex > 0) {
            termHistoryIndex--;
        }
        termInput.value = commandHistory[termHistoryIndex] || '';
    } else if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (termHistoryIndex !== -1) {
            if (termHistoryIndex < commandHistory.length - 1) {
                termHistoryIndex++;
                termInput.value = commandHistory[termHistoryIndex] || '';
            } else {
                termHistoryIndex = -1;
                termInput.value = '';
            }
        }
    } else if (e.key === 'c' && e.ctrlKey) {
        // Ctrl+C
        e.preventDefault();
        appendCommandRow(termInput.value + ' ^C', '');
        termInput.value = '';
        isExecuting = false;
        termStatusText.textContent = 'Cancelled';
        scrollTerminalToBottom();
    } else if (e.key === 'l' && e.ctrlKey) {
        // Ctrl+L (Clear)
        e.preventDefault();
        clearTerminal();
    }
});

function executeCommand(cmd) {
    if (!cmd) {
        appendCommandRow('', '');
        return;
    }

    if (cmd === 'clear') {
        clearTerminal();
        termInput.value = '';
        return;
    }

    isExecuting = true;
    termStatusText.textContent = 'Running: ' + cmd;
    termInput.disabled = true;

    const rowEl = appendCommandRow(cmd, '<span class="text-indigo-400 animate-pulse">Running process...</span>');
    termInput.value = '';
    scrollTerminalToBottom();

    const formData = new FormData();
    formData.append('exec_cmd', '1');
    formData.append('cmd', cmd);

    fetch('/dashboard/terminal.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: formData
    })
    .then(res => res.json())
    .then(data => {
        isExecuting = false;
        termInput.disabled = false;
        termInput.focus();
        termStatusText.textContent = 'Ready for input';

        if (data.cwd) {
            currentCwd = data.cwd;
            promptCwdDisplay.textContent = currentCwd;
            termCwdTitle.textContent = currentCwd;
            termCwdBadge.textContent = currentCwd;
        }

        const formattedOutput = convertAnsiToHtml(data.output || '');
        const outputContainer = rowEl.querySelector('.cmd-output');
        if (outputContainer) {
            outputContainer.innerHTML = formattedOutput;
        }
        scrollTerminalToBottom();
    })
    .catch(err => {
        isExecuting = false;
        termInput.disabled = false;
        termInput.focus();
        termStatusText.textContent = 'Execution error';

        const outputContainer = rowEl.querySelector('.cmd-output');
        if (outputContainer) {
            outputContainer.innerHTML = `<span class="text-red-400 font-bold">Error: ${err.message}</span>`;
        }
        scrollTerminalToBottom();
    });
}

function runPresetCommand(cmd) {
    termInput.value = cmd;
    termInput.focus();
    executeCommand(cmd);
}

function appendCommandRow(cmd, initialOutput) {
    const row = document.createElement('div');
    row.className = 'mb-2.5';
    row.innerHTML = `
        <div class="flex items-center gap-2 select-none">
            <span class="text-emerald-400 font-bold flex-shrink-0">root@workspace</span><span class="text-gray-500">:</span><span class="text-indigo-400 font-semibold flex-shrink-0">${escapeHtml(currentCwd)}</span><span class="text-gray-400 font-bold">$</span>
            <span class="text-gray-100 font-semibold select-text font-mono">${escapeHtml(cmd)}</span>
        </div>
        <div class="cmd-output pl-2 pt-1 font-mono whitespace-pre-wrap leading-relaxed select-text text-gray-300 text-xs sm:text-sm">${initialOutput}</div>
    `;
    termHistory.appendChild(row);
    return row;
}

function clearTerminal() {
    termHistory.innerHTML = '';
    termStatusText.textContent = 'Screen cleared';
    scrollTerminalToBottom();
}

function scrollTerminalToBottom() {
    setTimeout(() => {
        termScreen.scrollTop = termScreen.scrollHeight;
    }, 10);
}

function adjustTermFont(delta) {
    currentFontSize = Math.max(10, Math.min(24, currentFontSize + delta));
    localStorage.setItem('terminal_font_size', currentFontSize);
    applyFontSize();
}

function applyFontSize() {
    termScreen.style.fontSize = currentFontSize + 'px';
    termInput.style.fontSize = currentFontSize + 'px';
}

function toggleTerminalFullscreen() {
    const container = document.getElementById('terminalContainer');
    const isFull = container.classList.toggle('term-fullscreen');
    const btnText = document.getElementById('fullscreenBtnText');
    if (isFull) {
        btnText.textContent = 'Exit Fullscreen';
        document.body.style.overflow = 'hidden';
    } else {
        btnText.textContent = 'Fullscreen';
        document.body.style.overflow = '';
    }
    scrollTerminalToBottom();
}

function setTerminalTheme(theme) {
    const screen = document.getElementById('termScreen');
    screen.className = screen.className.replace(/term-theme-\w+/g, '');
    screen.classList.add('term-theme-' + theme);
    localStorage.setItem('terminal_theme', theme);
}

function copyTerminalText() {
    const text = termScreen.innerText;
    navigator.clipboard.writeText(text).then(() => {
        termStatusText.textContent = 'Copied terminal output to clipboard!';
        setTimeout(() => { termStatusText.textContent = 'Ready for input'; }, 2000);
    });
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

// Convert ANSI escape codes to styled HTML
function convertAnsiToHtml(text) {
    if (!text) return '';
    
    // Replace standard ANSI colors and formats
    let out = escapeHtml(text);
    
    // Bold / Dim / Underline
    out = out.replace(/\033\[1m/g, '<span class="font-bold text-white">')
             .replace(/\033\[2m/g, '<span class="opacity-70">')
             .replace(/\033\[4m/g, '<span class="underline">');
             
    // Standard Colors
    out = out.replace(/\033\[30m/g, '<span class="text-gray-900">')
             .replace(/\033\[31m/g, '<span class="text-rose-400">')
             .replace(/\033\[32m/g, '<span class="text-emerald-400">')
             .replace(/\033\[33m/g, '<span class="text-amber-400">')
             .replace(/\033\[34m/g, '<span class="text-blue-400">')
             .replace(/\033\[35m/g, '<span class="text-purple-400">')
             .replace(/\033\[36m/g, '<span class="text-cyan-400">')
             .replace(/\033\[37m/g, '<span class="text-gray-200">');

    // Bold Colors
    out = out.replace(/\033\[1;30m/g, '<span class="font-bold text-gray-500">')
             .replace(/\033\[1;31m/g, '<span class="font-bold text-red-400">')
             .replace(/\033\[1;32m/g, '<span class="font-bold text-emerald-400">')
             .replace(/\033\[1;33m/g, '<span class="font-bold text-amber-300">')
             .replace(/\033\[1;34m/g, '<span class="font-bold text-blue-300">')
             .replace(/\033\[1;35m/g, '<span class="font-bold text-pink-400">')
             .replace(/\033\[1;36m/g, '<span class="font-bold text-cyan-300">')
             .replace(/\033\[1;37m/g, '<span class="font-bold text-white">');

    // Backgrounds & Reset
    out = out.replace(/\033\[0?m/g, '</span>')
             .replace(/\033\[[0-9;]*[a-zA-Z]/g, ''); // strip any remaining ANSI codes

    return out;
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
