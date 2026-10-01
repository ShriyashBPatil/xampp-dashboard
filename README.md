# 🎛️ XAMPP & LAMP Web Control Panel & Dashboard

<p align="center">
  <strong>A modern, full-featured web hosting & server administration dashboard for XAMPP / LAMP / LEMP stacks built with PHP, MariaDB, TailwindCSS, and Python WebSockets.</strong>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.1+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP 8.1+">
  <img src="https://img.shields.io/badge/MariaDB-MySQL-003545?style=for-the-badge&logo=mariadb&logoColor=white" alt="MariaDB">
  <img src="https://img.shields.io/badge/TailwindCSS-Responsive-38B2AC?style=for-the-badge&logo=tailwind-css&logoColor=white" alt="TailwindCSS">
  <img src="https://img.shields.io/badge/Socket.IO-WebTerminal-010101?style=for-the-badge&logo=socketdotio&logoColor=white" alt="WebSockets">
  <img src="https://img.shields.io/badge/License-MIT-green?style=for-the-badge" alt="MIT License">
</p>

---

## 🌟 Overview

**XAMPP Dashboard** is a complete, developer-friendly server management suite and control panel designed to replace legacy tools. It transforms local and VPS web servers into an automated hosting platform with project management, database administration, web file explorer, in-browser code editor, GitHub repository synchronization, FTP & SSH managers, and an interactive real-time web terminal.

---

## ✨ Features & Capabilities

- 🚀 **One-Click Auto Setup & Deployments**:
  - Deploy PHP, HTML, or full-stack web applications from `.zip` archives or direct uploads.
  - Automatic SQL database provisioning, schema imports, and connection string patching.
- 🗄️ **Integrated Database Manager**:
  - Full-featured phpMyAdmin alternative with table management, SQL query runner, export/import utilities, and live record editing.
- 📁 **Web File Explorer & Code Editor**:
  - File and folder management (upload, rename, delete, duplicate, permissions, archive/extract).
  - Built-in syntax-highlighted code editor for editing PHP, HTML, JS, CSS, and configs directly in the browser.
- 🐙 **GitHub Integration & Auto-Sync Cron**:
  - Manage and link GitHub repositories directly to web folders.
  - Automated deployment synchronization via background cron (`github-sync-cron.php`).
- 💻 **Live Web Terminal & PTY Bridge**:
  - WebSocket-powered real-time interactive terminal connected to the host via Python `bridge.py`.
- 🌐 **FTP & SSH Management**:
  - Browser-based FTP client for remote server transfers and SSH session manager.
- 👥 **Multi-User Accounts & Role Permissions**:
  - User authentication with Guest Mode, Administrator privileges, and granular permission controls.
- 🎨 **Modern Dark/Light UI**:
  - Built with responsive Tailwind CSS, smooth transitions, and intuitive modal workflows.

---

## 📂 Project Architecture

```
xampp-dashboard/
├── index.php             # Main Dashboard, server telemetry & auto-setup deployer
├── database.php          # Database browser, table designer & SQL query editor
├── explorer.php          # Interactive file manager & directory tree
├── editor.php            # In-browser source code editor
├── github.php            # GitHub repo management & commit sync
├── github-sync-cron.php  # Automated webhook / cron updater
├── ftp.php               # Web FTP client & file transfers
├── ssh.php               # Web SSH client
├── terminal.php          # In-browser terminal (connects to Python bridge)
├── projects.php          # Project catalog, vhost & domain manager
├── account.php           # User profile & credentials manager
├── settings.php          # Global control panel configuration
├── login.php / logout.php # Authentication handlers
├── includes/             # Shared modular layout & core logic
│   ├── core.php          # Session, auth, DB helpers, permission engine
│   ├── header.php        # Navigation bar & theme assets
│   └── footer.php        # Global footer & scripts
└── terminal-bridge/      # Python WebSocket PTY bridge daemon
    ├── bridge.py         # Socket.IO PTY server
    └── requirements.txt  # Bridge dependencies
```

---

## 🛠️ Installation & Setup

### 1. Requirements
- PHP 8.0+ with `pdo_mysql`, `curl`, `zip`, `mbstring` extensions
- Apache / Nginx / Caddy web server (or standard XAMPP / LAMP stack)
- MariaDB or MySQL server
- Python 3.8+ (for Web Terminal bridge daemon)

### 2. Clone into your Web Root
Clone this repository into your web root directory (e.g. `htdocs/dashboard` or `/var/www/html/dashboard`):
```bash
cd /var/www/html
git clone https://github.com/ShriyashBPatil/xampp-dashboard.git dashboard
```

### 3. Setup Python Terminal Bridge (Optional, for Live Terminal)
```bash
cd dashboard/terminal-bridge
python3 -m venv venv
source venv/bin/activate
pip install -r requirements.txt
python3 bridge.py
```

### 4. Access the Dashboard
Open your browser and navigate to:
```
http://localhost/dashboard
```
The database and initial user tables will automatically initialize on first load.

---

## 📜 License

Distributed under the **MIT License**.

---

## 👨‍💻 Author

**SHRIYASH PATIL**
- GitHub: [@ShriyashBPatil](https://github.com/ShriyashBPatil)
- Website: [shriyashpatil.in](https://shriyashpatil.in)
