# CBT Offline — Installer Build Guide

This directory contains everything needed to produce `CBT-Setup.exe` — a self-contained Windows installer that customers can run on any PC **without installing PHP, MySQL, or XAMPP**.

---

## How it Works

The installer bundles:
| Component | Purpose |
|---|---|
| **PHP 8.2 (portable)** | Runs the PHP app — no system install |
| **MariaDB 10.11 (portable)** | Database — no system install, no Windows service |
| **CBT App (`www/`)** | Your exam system PHP files |
| **CBTManager.exe** | System tray app — shows LAN IP, Start/Stop server |
| **launcher.bat** | Starts MariaDB + PHP built-in server |
| **Windows Firewall rule** | Opens port 8080 so students on the LAN can connect |

**Student computers** need nothing installed — they just open any browser and go to `http://[SERVER-IP]:8080`.

---

## One-Time Setup (Developer Machine)

### 1. Install Prerequisites

| Tool | Download | Notes |
|---|---|---|
| **Inno Setup 6** | https://jrsoftware.org/isinfo.php | Free, install at default location |
| **AutoHotkey v1** | https://www.autohotkey.com/download/1.x/ | v1 (not v2), install at default location |

### 2. Build the Installer

Open PowerShell **as Administrator** and run:

```powershell
cd c:\xampp\htdocs\cbt\installer
.\build.ps1
```

The script will:
1. Download **PHP 8.2** (~30MB) to `tmp/`
2. Download **MariaDB 10.11** (~60MB) to `tmp/`
3. Assemble the `build/` directory
4. Compile `CBTManager.ahk` → `CBTManager.exe`
5. Run Inno Setup → produce **`dist/CBT-Setup.exe`**

> Downloads are cached in `tmp/` — subsequent builds are fast.

### 3. Test

- Copy `dist/CBT-Setup.exe` to a **clean Windows 10/11 machine** (no XAMPP)
- Run as Administrator
- Follow the installer steps
- CBT Manager tray icon will appear → server starts automatically
- Open browser → `http://localhost:8080` → complete first-time setup

---

## File Reference

```
installer/
├── build.ps1          ← Main build script (run this)
├── setup.iss          ← Inno Setup script (produces CBT-Setup.exe)
├── CBTManager.ahk     ← System tray app source (AutoHotkey v1)
├── launcher.bat       ← Starts MariaDB + PHP server
├── stop.bat           ← Stops MariaDB + PHP server
├── php.ini            ← PHP config (bundled into php/ dir)
├── my.ini             ← MariaDB config (bundled into install root)
│
├── build/             ← Assembled build (created by build.ps1)
│   ├── php/           ← Portable PHP 8.2
│   ├── mariadb/       ← Portable MariaDB 10.11
│   ├── www/           ← CBT app files
│   ├── CBTManager.exe ← Compiled tray app
│   ├── launcher.bat
│   ├── stop.bat
│   └── my.ini
│
├── dist/              ← Final output (created by build.ps1)
│   └── CBT-Setup.exe  ← Distribute this file to customers!
│
└── tmp/               ← Download cache (safe to delete)
    ├── php.zip
    └── mariadb.zip
```

---

## Customer Distribution

Hand the customer a single file: **`CBT-Setup.exe`**

**Customer experience:**
1. Double-click `CBT-Setup.exe` → click Yes on UAC prompt
2. Installer runs → takes ~1-2 minutes
3. A green tray icon appears in the system tray
4. The admin opens `http://localhost:8080` → enters School ID → fetches data
5. Students on LAN open `http://192.168.x.x:8080` (URL shown in tray icon)

---

## Troubleshooting

| Problem | Solution |
|---|---|
| Students can't connect | Check Windows Firewall — port 8080 must be open. Run `launcher.bat` as admin. |
| MariaDB won't start | Check `data/logs/mariadb.log` for errors |
| PHP errors | Check `data/logs/php_errors.log` |
| Wrong LAN IP shown | Tray icon auto-detects — refresh or check `ipconfig` manually |
| Port 8080 already in use | Edit `SERVER_PORT` in `launcher.bat` and `config.ini` |
