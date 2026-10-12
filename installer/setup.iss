; ============================================================================
; CBT Offline — Inno Setup Installer Script
; Produces CBT-Setup.exe
;
; Prerequisites:
;   1. Inno Setup 6 installed (https://jrsoftware.org/isinfo.php)
;   2. Build directory produced by build.ps1:
;        build\
;          php\        — portable PHP 8.2 x64 NTS
;          mariadb\    — portable MariaDB x64
;          www\        — CBT app files
;          my.ini
;          php.ini     (already copied to php\php.ini by build script)
;          launcher.bat
;          stop.bat
;          CBTManager.exe
;          assets\favicon.ico
; ============================================================================

#define AppName      "CBT Offline Exam System"
#define AppShortName "CBT Offline"
#define AppVersion   "1.0.0"
#define AppPublisher "Platforms Innovations"
#define AppURL       "https://platformeduportal.com"
#define BuildDir     "build"

[Setup]
AppName                = {#AppName}
AppVersion             = {#AppVersion}
AppPublisherURL        = {#AppURL}
AppSupportURL          = {#AppURL}
AppUpdatesURL          = {#AppURL}
DefaultDirName         = {autopf}\CBT Offline
DefaultGroupName       = {#AppShortName}
DisableProgramGroupPage= yes
OutputDir              = dist
OutputBaseFilename     = CBT-Setup
; SetupIconFile -- uncomment and set path if you add a favicon.ico to the project
; SetupIconFile          = {#BuildDir}\assets\favicon.ico
Compression            = lzma2/ultra64
SolidCompression       = yes
WizardStyle            = modern
WizardResizable        = no
PrivilegesRequired     = admin
ArchitecturesAllowed   = x64compatible
ArchitecturesInstallIn64BitMode = x64compatible
UninstallDisplayIcon   = {app}\CBTManager.exe
UninstallDisplayName   = {#AppShortName}

; Wizard appearance
WizardImageFile        = compiler:WizClassicImage.bmp
WizardSmallImageFile   = compiler:WizClassicSmallImage.bmp

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "desktopicon";    Description: "Create a &desktop shortcut";          GroupDescription: "Additional icons:"
Name: "startupicon";    Description: "Start CBT Manager when &Windows starts"; GroupDescription: "Additional icons:"

[Files]
; --- PHP Runtime ---
Source: "{#BuildDir}\php\*";       DestDir: "{app}\php";       Flags: ignoreversion recursesubdirs createallsubdirs

; --- MariaDB ---
Source: "{#BuildDir}\mariadb\*";   DestDir: "{app}\mariadb";   Flags: ignoreversion recursesubdirs createallsubdirs

; --- CBT App Files ---
Source: "{#BuildDir}\www\*";       DestDir: "{app}\www";       Flags: ignoreversion recursesubdirs createallsubdirs

; --- Server Scripts & Config ---
Source: "{#BuildDir}\launcher.bat"; DestDir: "{app}";          Flags: ignoreversion
Source: "{#BuildDir}\stop.bat";     DestDir: "{app}";          Flags: ignoreversion
Source: "{#BuildDir}\my.ini";       DestDir: "{app}";          Flags: ignoreversion
Source: "{#BuildDir}\CBTManager.exe"; DestDir: "{app}";        Flags: ignoreversion

[Icons]
; Desktop shortcut — opens the tray manager
Name: "{autodesktop}\{#AppShortName}";       Filename: "{app}\CBTManager.exe"; Comment: "CBT Offline Exam Server"; Tasks: desktopicon; IconFilename: "{app}\CBTManager.exe"

; Start Menu entry
Name: "{group}\{#AppShortName}";             Filename: "{app}\CBTManager.exe"; Comment: "Launch CBT Offline Exam Server"
Name: "{group}\Admin Panel (Browser)";       Filename: "{app}\launcher.bat";   IconFilename: "{sys}\shell32.dll"; IconIndex: 13
Name: "{group}\Stop Server";                 Filename: "{app}\stop.bat";       IconFilename: "{sys}\shell32.dll"; IconIndex: 27
Name: "{group}\Uninstall {#AppShortName}";   Filename: "{uninstallexe}"

; Startup (auto-start with Windows)
Name: "{userstartup}\{#AppShortName}";       Filename: "{app}\CBTManager.exe"; Tasks: startupicon

[Registry]
; Allow app to be found by name in Windows search
Root: HKLM; Subkey: "SOFTWARE\{#AppPublisher}\{#AppShortName}"; ValueType: string; ValueName: "InstallPath"; ValueData: "{app}"; Flags: uninsdeletekey

[Run]
; 1. Write config.ini with database credentials
Filename: "{sys}\cmd.exe"; \
    Parameters: "/C echo [database]> ""{app}\www\config\config.ini"" && echo host=127.0.0.1>> ""{app}\www\config\config.ini"" && echo port=3306>> ""{app}\www\config\config.ini"" && echo user=cbt_user>> ""{app}\www\config\config.ini"" && echo pass=cbt_pass_2024!>> ""{app}\www\config\config.ini"" && echo name=db_cbt_offline>> ""{app}\www\config\config.ini"" && echo.>> ""{app}\www\config\config.ini"" && echo [server]>> ""{app}\www\config\config.ini"" && echo port=8080>> ""{app}\www\config\config.ini"""; \
    Flags: runhidden; StatusMsg: "Writing configuration..."

; 2. Open Windows Firewall rule for port 8080
Filename: "{sys}\netsh.exe"; \
    Parameters: "advfirewall firewall add rule name=""CBT Offline Server"" dir=in action=allow protocol=TCP localport=8080 profile=private,domain description=""CBT Offline Exam Server — allows student LAN access"""; \
    Flags: runhidden; StatusMsg: "Configuring firewall..."

; 3. Launch CBT Manager after install (tray app auto-starts server)
Filename: "{app}\CBTManager.exe"; \
    Description: "Launch CBT Manager (start the server)"; \
    Flags: nowait postinstall skipifsilent

[UninstallRun]
; Stop server before uninstall
Filename: "{sys}\cmd.exe"; Parameters: "/C ""{app}\stop.bat"""; Flags: runhidden; RunOnceId: StopServer

; Remove firewall rule
Filename: "{sys}\netsh.exe"; Parameters: "advfirewall firewall delete rule name=""CBT Offline Server"""; Flags: runhidden; RunOnceId: RemoveFirewall

[UninstallDelete]
; Clean up data directory on uninstall
Type: filesandordirs; Name: "{app}\data"

[Code]
// ============================================================
// Custom pages: show student URL after install
// ============================================================
function GetLanIP(): String;
var
  WshShell: Variant;
  oExec: Variant;
  sOutput, sLine: String;
  Lines: TStringList;
  i: Integer;
begin
  Result := '127.0.0.1';
  try
    WshShell := CreateOleObject('WScript.Shell');
    oExec    := WshShell.Exec('cmd /C ipconfig');
    sOutput  := oExec.StdOut.ReadAll();
    Lines    := TStringList.Create;
    try
      Lines.Text := sOutput;
      for i := 0 to Lines.Count - 1 do
      begin
        sLine := Lines[i];
        if Pos('IPv4 Address', sLine) > 0 then
        begin
          // Extract IP from "   IPv4 Address. . . . . . . . . . . : 192.168.x.x"
          sLine := Copy(sLine, Pos(': ', sLine) + 2, Length(sLine));
          sLine := Trim(sLine);
          if (sLine <> '127.0.0.1') and (sLine <> '') then
          begin
            Result := sLine;
            Break;
          end;
        end;
      end;
    finally
      Lines.Free;
    end;
  except
    // ignore
  end;
end;

procedure CurStepChanged(CurStep: TSetupStep);
var
  LanIP, StudentURL, Msg: String;
begin
  if CurStep = ssDone then
  begin
    LanIP      := GetLanIP();
    StudentURL := 'http://' + LanIP + ':8080';
    Msg := 'CBT Offline has been installed successfully!' + #13#10 + #13#10 +
           'The CBT Manager tray icon is now running in your taskbar.' + #13#10 + #13#10 +
           '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━' + #13#10 +
           '  Student URL (share this with students):' + #13#10 +
           '  ' + StudentURL                          + #13#10 +
           '━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━' + #13#10 + #13#10 +
           'FIRST TIME SETUP:' + #13#10 +
           '  1. Open your browser and go to: http://localhost:8080' + #13#10 +
           '  2. Enter your School ID to download school data.' + #13#10 +
           '  3. Share the URL above with student computers.';
    MsgBox(Msg, mbInformation, MB_OK);
  end;
end;
