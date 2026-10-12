; ============================================================================
; CBT Offline — System Tray Manager
; Compile with AutoHotkey v1 (ahk2exe) to produce CBTManager.exe
; Provides Start/Stop controls and shows the LAN URL for students.
; ============================================================================
#NoEnv
#SingleInstance Force
#Persistent
SetWorkingDir %A_ScriptDir%

; --- Configuration ---
APP_DIR     := A_ScriptDir . "\"
SERVER_PORT := 8080
APP_NAME    := "CBT Offline Server"
ICON_FILE   := APP_DIR . "assets\favicon.ico"

; --- Resolve LAN IP ---
LAN_IP := GetLanIP()
STUDENT_URL := "http://" . LAN_IP . ":" . SERVER_PORT
ADMIN_URL   := "http://localhost:" . SERVER_PORT . "/admin/"

; --- Check if server is running on startup ---
IsRunning := IsServerRunning()

; ============================================================================
; System Tray Setup
; ============================================================================
Menu, Tray, NoStandard
Menu, Tray, Tip, %APP_NAME%

if FileExist(ICON_FILE)
    Menu, Tray, Icon, %ICON_FILE%
else
    Menu, Tray, Icon, shell32.dll, 14   ; Network icon fallback

; Build the tray menu
RebuildMenu()

; Show balloon on startup
if IsRunning
    TrayTip, %APP_NAME%, Server is running!`nStudents connect to:`n%STUDENT_URL%, 5, 1
else
    TrayTip, %APP_NAME%, Server is stopped. Right-click tray icon to start., 5, 2

; ============================================================================
; Auto-start server if not already running
; ============================================================================
if !IsRunning
    StartServer()

; ============================================================================
; Timer: refresh status every 5 seconds
; ============================================================================
SetTimer, RefreshStatus, 5000
return

; ============================================================================
; Functions
; ============================================================================

RebuildMenu() {
    global IsRunning, APP_NAME, STUDENT_URL, ADMIN_URL, LAN_IP, SERVER_PORT
    Menu, Tray, DeleteAll

    if IsRunning {
        statusText := "✅  Server: RUNNING"
    } else {
        statusText := "🔴  Server: STOPPED"
    }

    lanText := "📡  Students URL: " . STUDENT_URL

    Menu, Tray, Add, %APP_NAME%, DoNothing
    Menu, Tray, Disable, %APP_NAME%
    Menu, Tray, Add
    Menu, Tray, Add, %statusText%, DoNothing
    Menu, Tray, Disable, %statusText%
    Menu, Tray, Add, %lanText%, CopyStudentURL
    Menu, Tray, Add
    if IsRunning {
        Menu, Tray, Add, 🌐  Open Student Login, OpenStudentPage
        Menu, Tray, Add, ⚙️  Open Admin Panel, OpenAdminPage
        Menu, Tray, Add
        Menu, Tray, Add, ⏹  Stop Server, StopServer
    } else {
        Menu, Tray, Add, ▶  Start Server, StartServer
    }
    Menu, Tray, Add
    Menu, Tray, Add, 🔄  Refresh Status, RefreshStatus
    Menu, Tray, Add, ❌  Exit Manager, ExitApp
}

StartServer() {
    global APP_DIR, IsRunning
    Run, %A_ComSpec% /C "%APP_DIR%launcher.bat", %APP_DIR%, Hide
    Sleep, 5000   ; Wait for services to start
    IsRunning := 1
    RebuildMenu()
    TrayTip, CBT Server Started, Server is running. Students can now connect., 4, 1
}

StopServer() {
    global APP_DIR, IsRunning
    Run, %A_ComSpec% /C "%APP_DIR%stop.bat", %APP_DIR%, Hide
    Sleep, 2000
    IsRunning := 0
    RebuildMenu()
    TrayTip, CBT Server Stopped, The server has been stopped., 3, 2
}

OpenStudentPage() {
    global STUDENT_URL
    Run, %STUDENT_URL%
}

OpenAdminPage() {
    global ADMIN_URL
    Run, %ADMIN_URL%
}

CopyStudentURL() {
    global STUDENT_URL
    Clipboard := STUDENT_URL
    TrayTip, Copied!, Student URL copied to clipboard:`n%STUDENT_URL%, 3, 1
}

RefreshStatus() {
    global IsRunning
    wasRunning := IsRunning
    IsRunning := IsServerRunning()
    if (wasRunning != IsRunning)
        RebuildMenu()
}

IsServerRunning() {
    global SERVER_PORT
    ; Check if php.exe process is running
    for proc in ComObjGet("winmgmts:").ExecQuery("SELECT * FROM Win32_Process WHERE Name='php.exe'") {
        return 1
    }
    return 0
}

GetLanIP() {
    ; Use WScript.Shell to run ipconfig and parse the IPv4 address on LAN adapter
    objShell := ComObjCreate("WScript.Shell")
    objExec  := objShell.Exec("cmd /C ipconfig")
    output   := objExec.StdOut.ReadAll()

    ; Find first non-loopback IPv4 address
    Loop, Parse, output, `n, `r
    {
        if InStr(A_LoopField, "IPv4 Address") {
            RegExMatch(A_LoopField, "(\d+\.\d+\.\d+\.\d+)", match)
            ip := match1
            if (ip != "" && ip != "127.0.0.1")
                return ip
        }
    }
    return "127.0.0.1"
}

; ============================================================================
; Menu callback stubs
; ============================================================================
DoNothing:
    return

ExitApp:
    MsgBox, 4, Exit CBT Manager, Stop the server before exiting?`n`nClick YES to stop the server and exit.`nClick NO to exit the tray app but leave the server running.
    IfMsgBox Yes
    {
        StopServer()
        Sleep, 1500
    }
    ExitApp
