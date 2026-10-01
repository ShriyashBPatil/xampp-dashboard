<?php
require_once __DIR__ . '/includes/core.php';
require_permission('can_ssh_connect', 'Access denied. You do not have permission to use SSH Remote Sessions.');

$isGuest = is_guest();
$currentUser = current_user();

$pageTitle = 'Terminal';
$activeNav = 'ssh';
include __DIR__ . '/includes/header.php';
?>

<!-- xterm.js -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/xterm@5.3.0/css/xterm.css" />

<style>
    .terminal-wrapper {
        transition: all 0.2s ease;
        position: relative;
    }

    .terminal-wrapper.fullscreen {
        position: fixed !important;
        inset: 0 !important;
        width: 100vw !important;
        height: 100vh !important;
        z-index: 99999 !important;
        border-radius: 0 !important;
        border: none !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .terminal-container {
        height: 68vh;
        width: 100%;
        background-color: #0f172a;
        padding: 10px;
        box-sizing: border-box;
        touch-action: manipulation;
    }

    @media (max-width: 640px) {
        .terminal-container {
            height: calc(100dvh - 280px);
            min-height: 280px;
            padding: 8px;
        }
    }

    .terminal-wrapper.fullscreen .terminal-container {
        height: calc(100vh - 84px) !important;
    }

    .terminal-container .xterm-viewport {
        background-color: #0f172a !important;
        -webkit-overflow-scrolling: touch;
    }

    /* Minimal Tabs */
    .tab-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 12px;
        font-size: 12px;
        font-weight: 500;
        font-family: 'JetBrains Mono', monospace;
        border-right: 1px solid #e2e8f0;
        background-color: #f8fafc;
        color: #64748b;
        cursor: pointer;
        transition: all 0.15s ease;
        user-select: none;
        white-space: nowrap;
    }

    .tab-btn:hover {
        background-color: #f1f5f9;
        color: #0f172a;
    }

    .tab-btn.active {
        background-color: #ffffff;
        color: #0f172a;
        font-weight: 600;
    }

    .tab-close {
        color: #94a3b8;
        border-radius: 4px;
        padding: 0 4px;
        font-size: 13px;
        line-height: 1;
        transition: all 0.15s;
    }

    .tab-close:hover {
        color: #ef4444;
        background-color: #fee2e2;
    }

    /* Mobile Quick Keys */
    .mobile-key-btn {
        padding: 5px 9px;
        font-family: 'JetBrains Mono', monospace;
        font-size: 11px;
        font-weight: 600;
        background-color: #ffffff;
        color: #334155;
        border: 1px solid #cbd5e1;
        border-radius: 6px;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        touch-action: manipulation;
        user-select: none;
        transition: background-color 0.1s;
    }
    .mobile-key-btn:active {
        background-color: #e2e8f0;
        transform: translateY(1px);
    }
</style>

<!-- Minimal Page Header -->
<div class="mb-3 flex flex-wrap items-center justify-between gap-2">
    <div class="flex items-center gap-2">
        <h1 class="text-base sm:text-lg font-bold text-slate-900 tracking-tight">Terminal</h1>
        <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[11px] font-medium bg-slate-100 text-slate-700 border border-slate-200">
            <span id="status-dot" class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
            <span id="status-text">Connected</span>
        </span>
    </div>

    <!-- Minimal Controls -->
    <div class="flex items-center gap-1.5">
        <button onclick="createNewTab()" class="px-2.5 py-1 text-xs font-medium text-slate-700 bg-white border border-slate-200 hover:bg-slate-50 active:bg-slate-100 rounded-lg shadow-sm transition flex items-center gap-1">
            <svg class="w-3.5 h-3.5 text-slate-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
            <span class="hidden sm:inline">New Tab</span>
            <span class="sm:hidden">New</span>
        </button>
        <button onclick="clearConsole()" class="px-2.5 py-1 text-xs font-medium text-slate-700 bg-white border border-slate-200 hover:bg-slate-50 active:bg-slate-100 rounded-lg shadow-sm transition">
            Clear
        </button>
        <button onclick="toggleFullscreen()" class="px-2.5 py-1 text-xs font-medium text-slate-700 bg-white border border-slate-200 hover:bg-slate-50 active:bg-slate-100 rounded-lg shadow-sm transition">
            <span class="hidden sm:inline">Fullscreen</span>
            <span class="sm:hidden">FS</span>
        </button>
    </div>
</div>

<?php if ($isGuest): ?>
<div class="mb-3 bg-amber-50 border border-amber-200 text-amber-800 px-3.5 py-2.5 rounded-lg text-xs flex items-center gap-2">
    <span>Terminal access is restricted to Administrators.</span>
</div>
<?php endif; ?>

<!-- Clean White Container -->
<div id="terminal-card" class="terminal-wrapper bg-white border border-slate-200 rounded-xl shadow-sm overflow-hidden">
    <!-- Minimal Tab Header -->
    <div class="bg-slate-50 flex items-center justify-between border-b border-slate-200 overflow-x-auto scrollbar-none">
        <div class="flex items-center overflow-x-auto" id="tabs-container">
            <!-- Tabs injected dynamically -->
        </div>
        <button onclick="createNewTab()" class="p-2 text-slate-400 hover:text-slate-700 transition shrink-0" title="Add Tab">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
        </button>
    </div>

    <!-- Terminal Screen -->
    <div id="terminal" class="terminal-container" onclick="focusTerminal()"></div>

    <!-- Mobile Virtual Keys Bar (Touch-Optimized) -->
    <div class="bg-slate-100 border-t border-slate-200 px-2 py-1.5 flex items-center gap-1.5 overflow-x-auto select-none" id="mobile-keys-bar">
        <button class="mobile-key-btn" onclick="sendKey('\x1b')">ESC</button>
        <button class="mobile-key-btn" onclick="sendKey('\t')">TAB</button>
        <button class="mobile-key-btn text-rose-600" onclick="sendKey('\x03')">^C</button>
        <button class="mobile-key-btn text-indigo-600" onclick="sendKey('\x04')">^D</button>
        <button class="mobile-key-btn" onclick="sendKey('\x1b[A')">↑</button>
        <button class="mobile-key-btn" onclick="sendKey('\x1b[B')">↓</button>
        <button class="mobile-key-btn" onclick="sendKey('\x1b[D')">←</button>
        <button class="mobile-key-btn" onclick="sendKey('\x1b[C')">→</button>
        <button class="mobile-key-btn" onclick="sendKey('/')">/</button>
        <button class="mobile-key-btn" onclick="sendKey('-')">-</button>
        <button class="mobile-key-btn" onclick="sendKey('|')">|</button>
        <button class="mobile-key-btn" onclick="sendKey('~')">~</button>
    </div>
</div>

<!-- Dependencies -->
<script src="https://cdn.jsdelivr.net/npm/xterm@5.3.0/lib/xterm.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xterm-addon-fit@0.8.0/lib/xterm-addon-fit.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/socket.io/4.7.2/socket.io.js"></script>

<script>
    const TABS_KEY = 'xampp_term_tabs_minimal';
    const ACTIVE_KEY = 'xampp_term_active_minimal';

    let tabs = [];
    let activeTabId = null;
    let isFullscreen = false;

    try {
        const saved = localStorage.getItem(TABS_KEY);
        if (saved) tabs = JSON.parse(saved);
        activeTabId = localStorage.getItem(ACTIVE_KEY) || (tabs[0] ? tabs[0].id : null);
    } catch (e) {
        tabs = [];
        activeTabId = null;
    }

    const isMobile = /iPhone|iPad|iPod|Android/i.test(navigator.userAgent) || window.innerWidth < 640;

    const term = new Terminal({
        cursorBlink: true,
        cursorStyle: 'block',
        theme: {
            background: '#0f172a',
            foreground: '#f8fafc',
            cursor: '#38bdf8',
            selectionBackground: '#1e3a8a',
            black: '#1e293b',
            red: '#f43f5e',
            green: '#10b981',
            yellow: '#f59e0b',
            blue: '#38bdf8',
            magenta: '#c084fc',
            cyan: '#2dd4bf',
            white: '#f8fafc',
            brightBlack: '#475569',
            brightRed: '#fb7185',
            brightGreen: '#34d399',
            brightYellow: '#fbbf24',
            brightBlue: '#60a5fa',
            brightMagenta: '#d8b4fe',
            brightCyan: '#5eead4',
            brightWhite: '#ffffff'
        },
        fontSize: isMobile ? 12 : 13.5,
        lineHeight: 1.25,
        fontFamily: "'JetBrains Mono', Menlo, monospace"
    });

    const fitAddon = new FitAddon.FitAddon();
    term.loadAddon(fitAddon);
    term.open(document.getElementById('terminal'));
    setTimeout(() => fitAddon.fit(), 80);

    const socket = io({
        path: '/socket.io',
        transports: ['websocket', 'polling'],
        reconnection: true
    });

    function focusTerminal() {
        term.focus();
    }

    function sendKey(keyStr) {
        if (socket.connected && activeTabId) {
            socket.emit('pty-input', { session_id: activeTabId, input: keyStr });
            term.focus();
        }
    }

    function saveState() {
        localStorage.setItem(TABS_KEY, JSON.stringify(tabs.map(t => ({ id: t.id, name: t.name }))));
        if (activeTabId) localStorage.setItem(ACTIVE_KEY, activeTabId);
    }

    function renderTabs() {
        const container = document.getElementById('tabs-container');
        if (!container) return;
        
        let html = '';
        tabs.forEach((tab, i) => {
            const isActive = (tab.id === activeTabId);
            html += `
            <div class="tab-btn ${isActive ? 'active' : ''}" onclick="switchTab('${tab.id}')" ondblclick="renameTab('${tab.id}')">
                <span class="w-1.5 h-1.5 rounded-full ${isActive ? 'bg-emerald-500' : (tab.hasUnread ? 'bg-amber-500' : 'bg-slate-300')}"></span>
                <span>${tab.name || ('Tab ' + (i + 1))}</span>
                <span class="tab-close" onclick="closeTab(event, '${tab.id}')">&times;</span>
            </div>`;
        });
        container.innerHTML = html;
    }

    function switchTab(tabId) {
        if (activeTabId === tabId) return;
        activeTabId = tabId;
        const t = tabs.find(x => x.id === tabId);
        if (t) t.hasUnread = false;
        saveState();
        renderTabs();

        term.reset();
        fitAddon.fit();
        if (socket.connected) {
            socket.emit('resume-terminal', {
                session_id: tabId,
                cols: term.cols || 80,
                rows: term.rows || 24
            });
        }
    }

    function createNewTab(customName = null) {
        const tabName = customName || `Tab ${tabs.length + 1}`;
        if (!socket.connected) socket.connect();
        
        fitAddon.fit();
        socket.emit('start-terminal', {
            cols: term.cols || 80,
            rows: term.rows || 24,
            cwd: '/root',
            force_new: true,
            label: tabName
        });
    }

    function closeTab(e, tabId) {
        if (e) e.stopPropagation();
        socket.emit('kill-session', { session_id: tabId });

        tabs = tabs.filter(t => t.id !== tabId);
        if (activeTabId === tabId) {
            activeTabId = tabs.length > 0 ? tabs[tabs.length - 1].id : null;
        }
        saveState();
        renderTabs();

        if (activeTabId) {
            switchTab(activeTabId);
        } else {
            createNewTab();
        }
    }

    function renameTab(tabId) {
        const tab = tabs.find(t => t.id === tabId);
        if (!tab) return;
        const newName = prompt('Rename tab:', tab.name);
        if (newName && newName.trim()) {
            tab.name = newName.trim();
            saveState();
            renderTabs();
        }
    }

    socket.on('connect', () => {
        document.getElementById('status-dot').className = 'w-1.5 h-1.5 rounded-full bg-emerald-500';
        document.getElementById('status-text').innerText = 'Connected';
        fitAddon.fit();

        if (tabs.length === 0 || !activeTabId) {
            createNewTab('Tab 1');
        } else {
            tabs.forEach(t => {
                socket.emit('start-terminal', {
                    session_id: t.id,
                    cols: term.cols || 80,
                    rows: term.rows || 24,
                    cwd: '/root',
                    force_new: false
                });
            });
            renderTabs();
        }
    });

    socket.on('session-started', (data) => {
        const existing = tabs.find(t => t.id === data.session_id);
        if (!existing) {
            tabs.push({ id: data.session_id, name: `Tab ${tabs.length + 1}`, history: '', hasUnread: false });
        }
        activeTabId = data.session_id;
        saveState();
        renderTabs();
        term.reset();
        fitAddon.fit();
        term.focus();
    });

    socket.on('session-resumed', (data) => {
        let tab = tabs.find(t => t.id === data.session_id);
        if (!tab) {
            tab = { id: data.session_id, name: `Tab ${tabs.length + 1}`, history: data.history || '', hasUnread: false };
            tabs.push(tab);
        } else {
            tab.history = data.history || '';
        }

        if (data.session_id === activeTabId) {
            term.reset();
            if (data.history) term.write(data.history);
            fitAddon.fit();
            socket.emit('resize', { session_id: activeTabId, cols: term.cols, rows: term.rows });
            term.focus();
        }
        saveState();
        renderTabs();
    });

    socket.on('session-not-found', (data) => {
        tabs = tabs.filter(t => t.id !== data.session_id);
        if (activeTabId === data.session_id) activeTabId = tabs.length > 0 ? tabs[0].id : null;
        saveState();
        renderTabs();
        if (!activeTabId) createNewTab();
        else switchTab(activeTabId);
    });

    socket.on('pty-output', (data) => {
        const targetTab = tabs.find(t => t.id === data.session_id);
        if (targetTab) {
            targetTab.history = (targetTab.history || '') + data.output;
            if (targetTab.history.length > 300000) targetTab.history = targetTab.history.slice(-200000);
        }

        if (data.session_id === activeTabId || !data.session_id) {
            term.write(data.output);
        } else if (targetTab) {
            targetTab.hasUnread = true;
            renderTabs();
        }
    });

    socket.on('disconnect', () => {
        document.getElementById('status-dot').className = 'w-1.5 h-1.5 rounded-full bg-amber-500';
        document.getElementById('status-text').innerText = 'Paused';
    });

    term.onData(data => {
        if (socket.connected && activeTabId) {
            socket.emit('pty-input', { session_id: activeTabId, input: data });
        }
    });

    function handleResize() {
        fitAddon.fit();
        if (socket.connected && activeTabId) {
            socket.emit('resize', { session_id: activeTabId, cols: term.cols, rows: term.rows });
        }
    }

    window.addEventListener('resize', handleResize);
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', handleResize);
    }

    // Auto-resume on tab visibility change
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            if (!socket.connected) socket.connect();
            else if (activeTabId) {
                fitAddon.fit();
                socket.emit('resume-terminal', { session_id: activeTabId, cols: term.cols || 80, rows: term.rows || 24 });
            }
        }
    });

    window.addEventListener('focus', () => {
        if (!socket.connected) socket.connect();
        else if (activeTabId) {
            socket.emit('resume-terminal', { session_id: activeTabId, cols: term.cols || 80, rows: term.rows || 24 });
        }
    });

    function clearConsole() {
        term.clear();
        term.focus();
    }

    function toggleFullscreen() {
        const card = document.getElementById('terminal-card');
        isFullscreen = !isFullscreen;
        if (isFullscreen) {
            card.classList.add('fullscreen');
            document.body.style.overflow = 'hidden';
        } else {
            card.classList.remove('fullscreen');
            document.body.style.overflow = '';
        }
        setTimeout(() => {
            fitAddon.fit();
            if (activeTabId) socket.emit('resize', { session_id: activeTabId, cols: term.cols, rows: term.rows });
            term.focus();
        }, 100);
    }

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && isFullscreen) toggleFullscreen();
    });

    renderTabs();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
