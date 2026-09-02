<?php
require_once __DIR__ . '/../config/config.php';

require_admin_login();

$conn = get_db_connection();
$school = get_school_config();
$admin = $_SESSION['cbt_admin'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_reset'])) {
    $reset_type = isset($_POST['reset_type']) ? trim($_POST['reset_type']) : 'purge_pull';
    
    if ($reset_type === 'purge_pull') {
        $conn->query("TRUNCATE TABLE tbl_cbt_students");
        $conn->query("TRUNCATE TABLE tbl_cbt_class_groups");
        $conn->query("TRUNCATE TABLE tbl_cbt_mark_distributions");
        $conn->query("TRUNCATE TABLE tbl_cbt_subjects");
        $conn->query("TRUNCATE TABLE tbl_cbt_exams");
        $conn->query("TRUNCATE TABLE tbl_cbt_questions");
        $conn->query("TRUNCATE TABLE tbl_cbt_responses");
        
        $log_msg = "Local CBT rosters, subjects, exams, and question banks purged by administrator.";
        $conn->query("INSERT INTO tbl_cbt_sync_logs (sync_type, status, records_affected, message) VALUES ('PURGE', 'SUCCESS', 0, '$log_msg')");
        
        header("Location: ../api/sync_pull.php?execute=1&msg=Local data cleared. Pulling fresh data from online server now...");
        exit;
    } else if ($reset_type === 'full_reset') {
        $conn->query("TRUNCATE TABLE tbl_cbt_students");
        $conn->query("TRUNCATE TABLE tbl_cbt_class_groups");
        $conn->query("TRUNCATE TABLE tbl_cbt_mark_distributions");
        $conn->query("TRUNCATE TABLE tbl_cbt_subjects");
        $conn->query("TRUNCATE TABLE tbl_cbt_exams");
        $conn->query("TRUNCATE TABLE tbl_cbt_questions");
        $conn->query("TRUNCATE TABLE tbl_cbt_responses");
        $conn->query("TRUNCATE TABLE tbl_cbt_results");
        $conn->query("TRUNCATE TABLE tbl_cbt_sync_logs");
        $conn->query("TRUNCATE TABLE tbl_cbt_school");
        $conn->query("TRUNCATE TABLE tbl_cbt_admins");
        
        unset($_SESSION['cbt_admin']);
        header("Location: setup.php?reset_complete=1");
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Local CBT Data | Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body style="display: block; padding: 0;">
    <div class="app-layout">
        <!-- SIDEBAR NAVIGATION -->
        <aside class="sidebar">
            <div>
                <div class="sidebar-brand">
                    <i class="fa-solid fa-laptop-code"></i>
                    <div>
                        EduPortal
                        <span>Offline CBT Admin</span>
                    </div>
                </div>

                <div style="padding: 10px 14px; margin-bottom: 16px; background: rgba(255,255,255,0.03); border-radius: 8px; border: 1px solid var(--border-color);">
                    <div style="font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Admin Logged In:</div>
                    <div style="font-weight: 700; font-size: 13px; color: var(--text-main); text-overflow: ellipsis; overflow: hidden; white-space: nowrap;"><?= htmlspecialchars(!empty($admin['fullname']) ? $admin['fullname'] : $admin['username']) ?></div>
                </div>
                
                <nav class="sidebar-menu">
                    <a href="index.php" class="sidebar-link">
                        <i class="fa-solid fa-gauge-high"></i> Dashboard
                    </a>
                    <a href="exams.php" class="sidebar-link">
                        <i class="fa-solid fa-list-check"></i> Manage Exams
                    </a>
                    <a href="questions.php" class="sidebar-link">
                        <i class="fa-solid fa-layer-group"></i> Question Bank
                    </a>
                    <a href="students.php" class="sidebar-link">
                        <i class="fa-solid fa-users"></i> Student Roster
                    </a>
                    <a href="../api/sync_pull.php" class="sidebar-link">
                        <i class="fa-solid fa-cloud-arrow-down"></i> Pull Online Data
                    </a>
                    <a href="../api/sync_push.php" class="sidebar-link">
                        <i class="fa-solid fa-cloud-arrow-up"></i> Push Scores Online
                    </a>
                    <a href="reset.php" class="sidebar-link active">
                        <i class="fa-solid fa-rotate"></i> Reset Data
                    </a>
                    <a href="login.php?logout=1" class="sidebar-link" style="color: var(--danger); margin-top: 8px;">
                        <i class="fa-solid fa-right-from-bracket"></i> Logout
                    </a>
                </nav>
            </div>

            <div>
                <a href="../student/login.php" target="_blank" class="btn-launch-student">
                    <i class="fa-solid fa-up-right-from-square"></i> Launch Student Exam
                </a>
            </div>
        </aside>

        <!-- MAIN CONTENT AREA -->
        <main class="main-content">
            <div class="card" style="max-width: 800px;">
                <div class="card-title" style="color: var(--danger);"><i class="fa-solid fa-triangle-exclamation"></i> Reset & Purge Local CBT Data</div>
                <div class="card-desc">Clear locally cached student rosters, exam schedules, and question banks to pull fresh data from the online Eduportal server.</div>

                <form method="POST" action="reset.php" onsubmit="return confirm('Are you sure you want to purge local CBT data? This action cannot be undone.');">
                    <input type="hidden" name="confirm_reset" value="1">

                    <div style="background: rgba(15, 23, 42, 0.6); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; margin-bottom: 20px;">
                        <div style="display: flex; gap: 14px; margin-bottom: 16px;">
                            <input type="radio" id="purge_pull" name="reset_type" value="purge_pull" checked style="width: 20px; height: 20px; margin-top: 2px;">
                            <div>
                                <label for="purge_pull" style="font-weight: 700; font-size: 16px; color: var(--text-main); cursor: pointer;">
                                    <i class="fa-solid fa-arrows-rotate" style="color: var(--primary);"></i> Clear Data & Pull Fresh Online Data (Recommended)
                                </label>
                                <div style="font-size: 13px; color: var(--text-muted); margin-top: 4px; line-height: 1.4;">
                                    Clears student rosters, class groups, mark distributions, subjects, CBT exams, and question banks. Automatically connects to <strong>https://platformeduportal.com</strong> to pull fresh data immediately. Keeps local exam submission results intact.
                                </div>
                            </div>
                        </div>

                        <hr style="border: none; border-top: 1px solid var(--border-color); margin: 16px 0;">

                        <div style="display: flex; gap: 14px;">
                            <input type="radio" id="full_reset" name="reset_type" value="full_reset" style="width: 20px; height: 20px; margin-top: 2px;">
                            <div>
                                <label for="full_reset" style="font-weight: 700; font-size: 16px; color: var(--danger); cursor: pointer;">
                                    <i class="fa-solid fa-trash-can"></i> Complete Factory Reset & Wipe
                                </label>
                                <div style="font-size: 13px; color: var(--text-muted); margin-top: 4px; line-height: 1.4;">
                                    Empties all local offline database tables including student exam submission records. Returns the application to first-use state so you can re-enter a new School ID.
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-danger" style="width: 100%; padding: 14px; font-size: 16px;">
                        <i class="fa-solid fa-rotate"></i> Execute Reset & Purge Local Data Now
                    </button>
                    
                    <div style="margin-top: 16px; text-align: center;">
                        <a href="index.php" style="color: var(--text-muted); text-decoration: none; font-size: 14px;"><i class="fa-solid fa-arrow-left"></i> Cancel and Return to Dashboard</a>
                    </div>
                </form>
            </div>
        </main>
    </div>
</body>
</html>