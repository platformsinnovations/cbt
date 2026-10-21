<?php
require_once __DIR__ . '/../config/config.php';

$is_logged_in = is_admin_logged_in();
$conn = get_db_connection();
$school = get_school_config();
$admin = $is_logged_in ? $_SESSION['cbt_admin'] : null;

// Detect Server LAN IP
$server_ip = isset($_SERVER['SERVER_ADDR']) && $_SERVER['SERVER_ADDR'] !== '::1' && $_SERVER['SERVER_ADDR'] !== '127.0.0.1' 
    ? $_SERVER['SERVER_ADDR'] 
    : gethostbyname(gethostname());
if ($server_ip === '::1' || $server_ip === '127.0.0.1') {
    $server_ip = '192.168.x.x (Check via ipconfig)';
}
$server_port = (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] !== '80' && $_SERVER['SERVER_PORT'] !== '443') ? ':' . $_SERVER['SERVER_PORT'] : '';
$student_lan_url = ($server_ip !== '192.168.x.x (Check via ipconfig)') 
    ? "http://{$server_ip}{$server_port}/cbt/student/" 
    : "http://<SERVER-IP>/cbt/student/";

// Quick counts
$student_count = 0;
$exam_count = 0;
$res_s = $conn->query("SELECT COUNT(*) as c FROM tbl_cbt_students");
if ($res_s) $student_count = $res_s->fetch_assoc()['c'];
$res_e = $conn->query("SELECT COUNT(*) as c FROM tbl_cbt_exams WHERE is_active = 1");
if ($res_e) $exam_count = $res_e->fetch_assoc()['c'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usage Instructions & Setup Workflow | <?= htmlspecialchars($school ? $school['schoolname'] : 'EduPortal CBT') ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .workflow-phase {
            margin-bottom: 36px;
        }
        .phase-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--border-color);
        }
        .phase-badge {
            background: rgba(99, 102, 241, 0.2);
            color: #818cf8;
            font-size: 12px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 4px 10px;
            border-radius: 6px;
            border: 1px solid rgba(99, 102, 241, 0.4);
        }
        .phase-title {
            font-size: 18px;
            font-weight: 700;
            color: var(--text-main);
            margin: 0;
        }
        .step-card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px 24px;
            margin-bottom: 16px;
            display: grid;
            grid-template-columns: 48px 1fr auto;
            gap: 20px;
            align-items: start;
            transition: all 0.2s ease;
        }
        .step-card:hover {
            border-color: rgba(99, 102, 241, 0.4);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.25);
        }
        .step-number {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: rgba(99, 102, 241, 0.15);
            border: 2px solid var(--primary);
            color: #ffffff;
            font-size: 18px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .step-body h3 {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .step-desc {
            color: var(--text-muted);
            font-size: 14px;
            line-height: 1.6;
        }
        .step-desc ul {
            margin: 8px 0 0 18px;
            padding: 0;
        }
        .step-desc li {
            margin-bottom: 4px;
        }
        .step-action {
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-width: 150px;
            text-align: right;
        }
        .tip-box {
            background: rgba(16, 185, 129, 0.08);
            border-left: 3px solid var(--success);
            padding: 10px 14px;
            border-radius: 0 8px 8px 0;
            font-size: 13px;
            color: #a7f3d0;
            margin-top: 10px;
        }
        .warning-box {
            background: rgba(245, 158, 11, 0.08);
            border-left: 3px solid var(--accent);
            padding: 10px 14px;
            border-radius: 0 8px 8px 0;
            font-size: 13px;
            color: #fde68a;
            margin-top: 10px;
        }
        .quick-nav {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }
        .quick-nav a {
            background: rgba(255, 255, 255, 0.04);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 8px 14px;
            color: var(--text-muted);
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: all 0.15s ease;
        }
        .quick-nav a:hover {
            background: rgba(99, 102, 241, 0.15);
            color: var(--text-main);
            border-color: var(--primary);
        }

        /* PRINT STYLES FOR IN-LAB CHECKLIST PRINTING */
        @media print {
            body {
                background: #ffffff !important;
                color: #000000 !important;
            }
            .sidebar, .quick-nav, .no-print {
                display: none !important;
            }
            .main-content {
                margin-left: 0 !important;
                padding: 0 !important;
                background: #ffffff !important;
            }
            .step-card {
                background: #ffffff !important;
                border: 1px solid #cccccc !important;
                color: #000000 !important;
                box-shadow: none !important;
                page-break-inside: avoid;
                margin-bottom: 12px !important;
                padding: 12px !important;
                grid-template-columns: 36px 1fr !important;
            }
            .step-action {
                display: none !important;
            }
            .step-number {
                border-color: #333333 !important;
                color: #000000 !important;
                width: 32px !important;
                height: 32px !important;
                font-size: 14px !important;
            }
            .step-body h3, .phase-title {
                color: #000000 !important;
            }
            .step-desc, .step-desc li {
                color: #333333 !important;
            }
            .tip-box, .warning-box {
                background: #f3f4f6 !important;
                color: #111827 !important;
                border-left-color: #4b5563 !important;
            }
        }
    </style>
</head>
<body style="display: block; padding: 0;">
<?php if ($is_logged_in): ?>
    <div class="app-layout">
        <!-- SIDEBAR NAVIGATION -->
        <?php 
        $active_nav = 'instructions';
        include __DIR__ . '/sidebar.php'; 
        ?>

        <!-- MAIN CONTENT AREA -->
        <main class="main-content">
<?php else: ?>
    <div style="min-height: 100vh; background: var(--bg-dark);">
        <header class="no-print" style="padding: 16px 24px; background: #111827; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center;">
            <div style="display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 18px; color: var(--text-main);">
                <i class="fa-solid fa-laptop-code" style="color: var(--primary);"></i> EduPortal Offline CBT
            </div>
            <div>
                <a href="login.php" class="btn btn-primary" style="text-decoration: none;">
                    <i class="fa-solid fa-right-to-bracket"></i> Sign In to Admin
                </a>
            </div>
        </header>
        <main class="main-content" style="margin-left: 0; max-width: 1100px; margin: 0 auto; padding: 32px 20px;">
<?php endif; ?>
            <!-- PAGE HEADER -->
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
                <div>
                    <h1 style="font-size: 24px; font-weight: 800; margin: 0; display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid fa-circle-question" style="color: var(--accent);"></i> System Usage & Setup Instructions
                    </h1>
                    <div style="color: var(--text-muted); font-size: 14px; margin-top: 6px;">
                        Complete, sequential operational checklist for Administrators, IT Officers, and Exam Invigilators.
                    </div>
                </div>
                <div class="no-print" style="display: flex; gap: 10px;">
                    <button onclick="window.print()" class="btn btn-outline" style="border: 1px solid var(--border-color); background: rgba(255,255,255,0.05); color: var(--text-main);">
                        <i class="fa-solid fa-print"></i> Print Checklist
                    </button>
                    <a href="index.php" class="btn btn-primary" style="text-decoration: none;">
                        <i class="fa-solid fa-gauge-high"></i> Dashboard
                    </a>
                </div>
            </div>

            <!-- QUICK JUMP PILLS -->
            <div class="quick-nav no-print">
                <a href="#phase1"><i class="fa-solid fa-server"></i> Phase 1: Server Setup</a>
                <a href="#phase2"><i class="fa-solid fa-cloud-arrow-down"></i> Phase 2: Data Ingestion</a>
                <a href="#phase3"><i class="fa-solid fa-list-check"></i> Phase 3: Exam Verification</a>
                <a href="#phase4"><i class="fa-solid fa-network-wired"></i> Phase 4: Exam-Day LAN</a>
                <a href="#phase5"><i class="fa-solid fa-chart-pie"></i> Phase 5: Reports & Sync</a>
                <a href="#faq"><i class="fa-solid fa-life-ring"></i> Troubleshooting & FAQs</a>
            </div>

            <!-- ========================================================= -->
            <!-- PHASE 1: INITIAL SYSTEM INITIALIZATION & SECURITY         -->
            <!-- ========================================================= -->
            <div id="phase1" class="workflow-phase">
                <div class="phase-header">
                    <span class="phase-badge">Phase 1</span>
                    <h2 class="phase-title">Initial Server Initialization & Security</h2>
                </div>

                <!-- STEP 1 -->
                <div class="step-card">
                    <div class="step-number">1</div>
                    <div class="step-body">
                        <h3><i class="fa-solid fa-screwdriver-wrench" style="color: var(--primary);"></i> Run the Setup Wizard</h3>
                        <div class="step-desc">
                            Connect your offline server to your official school profile on the central cloud server.
                            <ul>
                                <li>Enter your official <strong>School ID</strong> and central EduPortal URL.</li>
                                <li>The wizard automatically verifies credentials, initializes the local offline MySQL database tables, and sets up the primary administrator account.</li>
                            </ul>
                            <div class="tip-box">
                                <i class="fa-solid fa-lightbulb"></i> <strong>Note:</strong> This only needs to be run once during initial server commissioning or when re-initializing a new testing server.
                            </div>
                        </div>
                    </div>
                    <div class="step-action no-print">
                        <a href="setup.php" class="btn btn-outline" style="border: 1px solid var(--border-color); background: rgba(255,255,255,0.05); color: var(--text-main); font-size: 13px; text-decoration: none;">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i> Setup Wizard
                        </a>
                    </div>
                </div>

                <!-- STEP 2 -->
                <div class="step-card">
                    <div class="step-number">2</div>
                    <div class="step-body">
                        <h3><i class="fa-solid fa-key" style="color: var(--accent);"></i> Change Default Admin Password</h3>
                        <div class="step-desc">
                            Secure your offline server before conducting any live exams.
                            <ul>
                                <li>The default password created by the installer is <code>CBTADMIN2026</code>.</li>
                                <li>Replace it with a confidential password known only to authorized exam supervisors.</li>
                            </ul>
                            <div class="warning-box">
                                <i class="fa-solid fa-triangle-exclamation"></i> <strong>Security Alert:</strong> A red security warning badge remains visible on your dashboard until the default password is changed.
                            </div>
                        </div>
                    </div>
                    <div class="step-action no-print">
                        <a href="change_password.php" class="btn btn-primary" style="font-size: 13px; text-decoration: none;">
                            <i class="fa-solid fa-lock"></i> Change Password
                        </a>
                    </div>
                </div>
            </div>

            <!-- ========================================================= -->
            <!-- PHASE 2: CLOUD DATA INGESTION (PULL FROM EDUPORTAL)       -->
            <!-- ========================================================= -->
            <div id="phase2" class="workflow-phase">
                <div class="phase-header">
                    <span class="phase-badge">Phase 2</span>
                    <h2 class="phase-title">Data Ingestion (Pull from Cloud Portal)</h2>
                </div>

                <!-- STEP 3 -->
                <div class="step-card">
                    <div class="step-number">3</div>
                    <div class="step-body">
                        <h3><i class="fa-solid fa-cloud-arrow-down" style="color: #60a5fa;"></i> Pull Online Academic Data Downstream</h3>
                        <div class="step-desc">
                            Download all up-to-date academic records from the online EduPortal server directly into this local server.
                            <ul>
                                <li>Pulls <strong>Active Academic Sessions & Terms</strong>.</li>
                                <li>Pulls <strong>Classes, Class Groups, and Arms</strong>.</li>
                                <li>Pulls <strong>Registered Students / Candidates Roster</strong>.</li>
                                <li>Pulls <strong>Exams, Questions Bank (with Options A–E), and Mark Distribution criteria</strong>.</li>
                            </ul>
                            <div class="tip-box">
                                <i class="fa-solid fa-wifi"></i> Requires temporary internet access on the host server to perform the sync. Once downloaded, all exams run 100% offline without internet.
                            </div>
                        </div>
                    </div>
                    <div class="step-action no-print">
                        <a href="../api/sync_pull.php" class="btn btn-primary" style="font-size: 13px; text-decoration: none; background: #2563eb;">
                            <i class="fa-solid fa-cloud-arrow-down"></i> Pull Online Data
                        </a>
                    </div>
                </div>

                <!-- STEP 4 -->
                <div class="step-card">
                    <div class="step-number">4</div>
                    <div class="step-body">
                        <h3><i class="fa-solid fa-users" style="color: var(--success);"></i> Verify Student Candidate Roster</h3>
                        <div class="step-desc">
                            Confirm that all eligible candidates have been successfully populated in the offline database.
                            <ul>
                                <li>Filter by <strong>Class Arm</strong> to verify candidate headcounts.</li>
                                <li>Check that every candidate's <strong>Registration / Admission Number</strong> matches their official exam slip.</li>
                                <li>Current Offline Candidates Count: <strong><?= number_format($student_count) ?> students</strong>.</li>
                            </ul>
                        </div>
                    </div>
                    <div class="step-action no-print">
                        <a href="students.php" class="btn btn-outline" style="border: 1px solid var(--border-color); background: rgba(255,255,255,0.05); color: var(--text-main); font-size: 13px; text-decoration: none;">
                            <i class="fa-solid fa-user-check"></i> View Students
                        </a>
                    </div>
                </div>
            </div>

            <!-- ========================================================= -->
            <!-- PHASE 3: EXAM VERIFICATION & SCHEDULING                   -->
            <!-- ========================================================= -->
            <div id="phase3" class="workflow-phase">
                <div class="phase-header">
                    <span class="phase-badge">Phase 3</span>
                    <h2 class="phase-title">Exam Verification & Scheduling</h2>
                </div>

                <!-- STEP 5 -->
                <div class="step-card">
                    <div class="step-number">5</div>
                    <div class="step-body">
                        <h3><i class="fa-solid fa-list-check" style="color: var(--primary);"></i> Configure & Activate Scheduled Exams</h3>
                        <div class="step-desc">
                            Set the rules and time windows under which students are permitted to take their exams.
                            <ul>
                                <li><strong>Status:</strong> Ensure the exam is toggled to <strong>Active</strong>.</li>
                                <li><strong>Exam Date & Time Window:</strong> Specify the permitted date (e.g. today) and time duration (e.g. <code>08:00 AM to 04:00 PM</code>).</li>
                                <li><strong>Duration (Minutes):</strong> Set the allocated countdown timer per student (e.g. <code>45</code> or <code>60</code> minutes).</li>
                                <li><strong>Target Assessment:</strong> Confirm the Mark Distribution value (e.g., 20 marks, 40 marks, or 100 marks).</li>
                            </ul>
                        </div>
                    </div>
                    <div class="step-action no-print">
                        <a href="exams.php" class="btn btn-primary" style="font-size: 13px; text-decoration: none;">
                            <i class="fa-solid fa-sliders"></i> Manage Exams
                        </a>
                    </div>
                </div>

                <!-- STEP 6 -->
                <div class="step-card">
                    <div class="step-number">6</div>
                    <div class="step-body">
                        <h3><i class="fa-solid fa-layer-group" style="color: var(--accent);"></i> Review Question Bank & Diagrams</h3>
                        <div class="step-desc">
                            Perform a quality assurance check on the examination questions before students sit for it.
                            <ul>
                                <li>Select the exam from the dropdown in the Question Bank.</li>
                                <li>Verify that questions, options (A to D or E), and correct answer keys are properly set.</li>
                                <li>Verify that question diagram attachments (if any) render cleanly offline.</li>
                            </ul>
                        </div>
                    </div>
                    <div class="step-action no-print">
                        <a href="questions.php" class="btn btn-outline" style="border: 1px solid var(--border-color); background: rgba(255,255,255,0.05); color: var(--text-main); font-size: 13px; text-decoration: none;">
                            <i class="fa-solid fa-eye"></i> Question Bank
                        </a>
                    </div>
                </div>
            </div>

            <!-- ========================================================= -->
            <!-- PHASE 4: EXAMINATION DAY LAB / LAN DEPLOYMENT             -->
            <!-- ========================================================= -->
            <div id="phase4" class="workflow-phase">
                <div class="phase-header">
                    <span class="phase-badge">Phase 4</span>
                    <h2 class="phase-title">Examination Day Lab / LAN Deployment</h2>
                </div>

                <!-- STEP 7 -->
                <div class="step-card">
                    <div class="step-number">7</div>
                    <div class="step-body">
                        <h3><i class="fa-solid fa-network-wired" style="color: #38bdf8;"></i> Connect Lab Computers to Host Server LAN</h3>
                        <div class="step-desc">
                            Connect student client computers to the same Local Area Network (Wi-Fi or Ethernet Switch) as the host server.
                            <ul>
                                <li>The server operates fully offline; <strong>no active internet connection is required</strong> during the test.</li>
                                <li>Your Server Local IP Address is detected as: <code style="font-size: 14px; font-weight: 700; color: #38bdf8; background: rgba(56, 189, 248, 0.1); padding: 2px 6px; border-radius: 4px;"><?= htmlspecialchars($server_ip) ?></code></li>
                                <li>On each candidate computer, open Chrome / Edge and type this URL:
                                    <div style="margin: 8px 0; background: rgba(15, 23, 42, 0.8); border: 1px dashed var(--border-color); padding: 10px 14px; border-radius: 8px; font-family: monospace; font-size: 14px; color: #34d399;">
                                        <?= htmlspecialchars($student_lan_url) ?>
                                    </div>
                                </li>
                            </ul>
                        </div>
                    </div>
                    <div class="step-action no-print">
                        <a href="../student/login.php" target="_blank" class="btn btn-primary" style="font-size: 13px; text-decoration: none; background: #059669;">
                            <i class="fa-solid fa-up-right-from-square"></i> Test Student Portal
                        </a>
                    </div>
                </div>

                <!-- STEP 8 -->
                <div class="step-card">
                    <div class="step-number">8</div>
                    <div class="step-body">
                        <h3><i class="fa-solid fa-user-graduate" style="color: var(--primary);"></i> Candidate Exam Execution & Crash Recovery</h3>
                        <div class="step-desc">
                            How students take the test and how invigilators handle technical glitches:
                            <ul>
                                <li><strong>Candidate Login:</strong> Students enter only their <strong>Registration / Admission Number</strong> (e.g. <code>20140361LC</code>). No password is required.</li>
                                <li><strong>Start Exam:</strong> Students select their assigned exam card and click <em>"Start Examination Now"</em>.</li>
                                <li><strong>Automatic Real-Time Auto-Save:</strong> Every option clicked is instantly saved to the offline database in the background.</li>
                                <li><strong>Crash Recovery / Power Outage Handling:</strong> If a student's PC shuts down or browser closes accidentally, <strong>no answers are lost</strong>. Simply turn the PC back on, reopen the browser, and log the student back in with their Registration Number. Their session and timer resume seamlessly!</li>
                                <li><strong>Auto-Submission:</strong> When the countdown timer reaches 00:00, the exam automatically submits and logs the final score.</li>
                            </ul>
                        </div>
                    </div>
                    <div class="step-action no-print">
                        <a href="index.php" class="btn btn-outline" style="border: 1px solid var(--border-color); background: rgba(255,255,255,0.05); color: var(--text-main); font-size: 13px; text-decoration: none;">
                            <i class="fa-solid fa-desktop"></i> Live Monitor
                        </a>
                    </div>
                </div>
            </div>

            <!-- ========================================================= -->
            <!-- PHASE 5: POST-EXAM REPORTS & CLOUD PUSH                   -->
            <!-- ========================================================= -->
            <div id="phase5" class="workflow-phase">
                <div class="phase-header">
                    <span class="phase-badge">Phase 5</span>
                    <h2 class="phase-title">Post-Exam Reports & Cloud Push</h2>
                </div>

                <!-- STEP 9 -->
                <div class="step-card">
                    <div class="step-number">9</div>
                    <div class="step-body">
                        <h3><i class="fa-solid fa-chart-pie" style="color: var(--accent);"></i> Review Performance Reports & Export Scores</h3>
                        <div class="step-desc">
                            Inspect candidate results, print physical master sheets, or export to spreadsheets.
                            <ul>
                                <li>Open <strong>Reports</strong> and select the 5 cascading filters: <em>Class/Arm, Session, Term, Subject, and Mark Distribution</em>.</li>
                                <li>View overall analytics: <strong>Filtered Candidates, Average Score, Highest Score, and Pass Rate</strong>.</li>
                                <li>Click <strong>Print Score Sheet</strong> for a clean black-and-white physical copy formatted for school leadership.</li>
                                <li>Click <strong>Export to Excel (.xls)</strong> to download a formatted spreadsheet with Registration numbers, raw scores, and pass/fail remarks.</li>
                            </ul>
                        </div>
                    </div>
                    <div class="step-action no-print">
                        <a href="reports.php" class="btn btn-primary" style="font-size: 13px; text-decoration: none;">
                            <i class="fa-solid fa-file-excel"></i> View Reports
                        </a>
                    </div>
                </div>

                <!-- STEP 10 -->
                <div class="step-card">
                    <div class="step-number">10</div>
                    <div class="step-body">
                        <h3><i class="fa-solid fa-cloud-arrow-up" style="color: #34d399;"></i> Push Final Scores Online to EduPortal</h3>
                        <div class="step-desc">
                            Upload all completed offline examination results back to the central EduPortal cloud database.
                            <ul>
                                <li>Connect the server computer back to the internet.</li>
                                <li>Go to <strong>Push Scores Online</strong> and select the completed exam from the dropdown.</li>
                                <li>Click <strong>"Push Exam Scores to Cloud Portal"</strong>.</li>
                                <li>Review the real-time query log to verify that all candidate scores show <code>SUCCESS</code>.</li>
                            </ul>
                        </div>
                    </div>
                    <div class="step-action no-print">
                        <a href="../api/sync_push.php" class="btn btn-primary" style="font-size: 13px; text-decoration: none; background: #059669;">
                            <i class="fa-solid fa-cloud-arrow-up"></i> Push Scores Online
                        </a>
                    </div>
                </div>

                <!-- STEP 11 -->
                <div class="step-card">
                    <div class="step-number">11</div>
                    <div class="step-body">
                        <h3><i class="fa-solid fa-rotate" style="color: var(--text-muted);"></i> End-of-Term Maintenance & Reset (Optional)</h3>
                        <div class="step-desc">
                            Prepare the offline server for the next examination cycle or school term.
                            <ul>
                                <li>Once scores have been pushed online and verified, navigate to <strong>Reset Data</strong>.</li>
                                <li>Choose to clear temporary candidate responses (`tbl_cbt_responses`) or reset student examination attempts when preparing for a brand new academic term.</li>
                            </ul>
                            <div class="warning-box">
                                <i class="fa-solid fa-triangle-exclamation"></i> <strong>Caution:</strong> Always ensure you have pushed all scores online before clearing local test data.
                            </div>
                        </div>
                    </div>
                    <div class="step-action no-print">
                        <a href="reset.php" class="btn btn-outline" style="border: 1px solid var(--border-color); background: rgba(255,255,255,0.05); color: var(--danger); font-size: 13px; text-decoration: none;">
                            <i class="fa-solid fa-trash-can"></i> Reset Menu
                        </a>
                    </div>
                </div>
            </div>

            <!-- ========================================================= -->
            <!-- TROUBLESHOOTING & FAQS                                    -->
            <!-- ========================================================= -->
            <div id="faq" class="workflow-phase">
                <div class="phase-header">
                    <span class="phase-badge" style="background: rgba(245, 158, 11, 0.2); color: var(--accent); border-color: rgba(245, 158, 11, 0.4);">Support</span>
                    <h2 class="phase-title">Troubleshooting & Exam-Day FAQs</h2>
                </div>

                <div class="card" style="padding: 24px;">
                    <div style="margin-bottom: 20px;">
                        <h4 style="color: var(--text-main); font-size: 15px; margin-bottom: 6px;">
                            <i class="fa-solid fa-circle-question" style="color: var(--primary);"></i> Q: A student's computer rebooted during the exam. What should we do?
                        </h4>
                        <p style="color: var(--text-muted); font-size: 14px; line-height: 1.5; margin: 0;">
                            <strong>Answer:</strong> Do not panic. Simply reopen the browser on that computer (or move the student to any other working PC on the network), go to the student portal URL, and have the student enter their Registration Number again. The system will restore all previously selected answers and resume their session with their remaining timer intact.
                        </p>
                    </div>

                    <div style="margin-bottom: 20px;">
                        <h4 style="color: var(--text-main); font-size: 15px; margin-bottom: 6px;">
                            <i class="fa-solid fa-circle-question" style="color: var(--primary);"></i> Q: The student portal says "Student Registration Number not found in local offline database".
                        </h4>
                        <p style="color: var(--text-muted); font-size: 14px; line-height: 1.5; margin: 0;">
                            <strong>Answer:</strong> 
                            1. Check for typos in the admission number (it must match exactly as registered).<br>
                            2. Check the <a href="students.php" style="color: var(--primary);">Student Roster</a> to verify if the student was included in the downstream pull.<br>
                            3. If the student was added to EduPortal recently, connect the server to the internet and execute <strong>Pull Online Data</strong> again to synchronize the roster.
                        </p>
                    </div>

                    <div>
                        <h4 style="color: var(--text-main); font-size: 15px; margin-bottom: 6px;">
                            <i class="fa-solid fa-circle-question" style="color: var(--primary);"></i> Q: Client computers cannot reach the server URL (e.g., "Site can't be reached").
                        </h4>
                        <p style="color: var(--text-muted); font-size: 14px; line-height: 1.5; margin: 0;">
                            <strong>Answer:</strong>
                            1. Verify both the server PC and client PCs are connected to the same Wi-Fi network or switch.<br>
                            2. Verify Windows Firewall on the server PC allows incoming connections on port 80 (Apache HTTP Server).<br>
                            3. Run <code>ipconfig</code> in Windows PowerShell to verify the server's current IPv4 address.
                        </p>
                    </div>
                </div>
            </div>

        </main>
    </div>
</body>
</html>
