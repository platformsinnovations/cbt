<?php
require_once __DIR__ . '/../config/config.php';

$conn = get_db_connection();
$school = get_school_config();

$schoolid = isset($_GET['schoolid']) ? trim($_GET['schoolid']) : ($school ? $school['schoolid'] : '');
if (empty($schoolid)) {
    header("Location: ../admin/setup.php");
    exit;
}

$online_url = $school ? rtrim($school['online_url'], '/') : 'https://platformeduportal.com';
$msg = '';
$error = '';

if (isset($_GET['execute']) || isset($_GET['auto'])) {
    $apiUrl = $online_url . "/api/cbt_sync_api.php?action=pull_data&schoolid=" . urlencode($schoolid);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    curl_close($ch);

    if (empty($response) && function_exists('file_get_contents')) {
        $response = @file_get_contents($apiUrl);
    }

    $data = json_decode($response, true);

    // Smart Fallback to local server if remote URL returns empty roster during testing
    if ((!$data || !isset($data['status']) || $data['status'] !== 'success' || empty($data['students'])) && strpos($online_url, 'localhost') === false) {
        $localApiUrl = "http://localhost/eduportal/api/cbt_sync_api.php?action=pull_data&schoolid=" . urlencode($schoolid);
        $ch2 = curl_init();
        curl_setopt($ch2, CURLOPT_URL, $localApiUrl);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
        $res2 = curl_exec($ch2);
        curl_close($ch2);

        $data2 = json_decode($res2, true);
        if ($data2 && isset($data2['status']) && $data2['status'] === 'success' && !empty($data2['students'])) {
            $data = $data2;
        }
    }

    if (!$data || !isset($data['status']) || $data['status'] !== 'success') {
        $err_msg = isset($data['message']) ? $data['message'] : "Failed to connect to online API endpoint ($apiUrl).";
        $error = "API Sync Error: " . $err_msg;
    } else {
        $pulled_students = 0;
        $pulled_exams = 0;
        $pulled_questions = 0;

        // A. Store School Profile
        $sp = $data['school'];
        $stmt_sch = $conn->prepare("REPLACE INTO tbl_cbt_school (schoolid, schoolname, schooladdress, logo_path, online_url, last_synced_at) VALUES (?, ?, ?, ?, ?, NOW())");
        $stmt_sch->bind_param("sssss", $sp['schoolid'], $sp['schoolname'], $sp['schooladdress'], $sp['logo_path'], $online_url);
        $stmt_sch->execute();

        // B. Store Class Groups
        if (!empty($data['class_groups']) && is_array($data['class_groups'])) {
            $stmt_cg = $conn->prepare("REPLACE INTO tbl_cbt_class_groups (classgroupid, classgroupname, classgrouplevel, schoolid) VALUES (?, ?, ?, ?)");
            foreach ($data['class_groups'] as $cg) {
                $level = intval($cg['classgrouplevel']);
                $stmt_cg->bind_param("ssis", $cg['classgroupid'], $cg['classgroupname'], $level, $schoolid);
                $stmt_cg->execute();
            }
        }

        // C. Store School Subjects
        if (!empty($data['subjects']) && is_array($data['subjects'])) {
            $stmt_sb = $conn->prepare("REPLACE INTO tbl_cbt_subjects (subjectid, schoolid, subjectname, subjectabbreviation) VALUES (?, ?, ?, ?)");
            foreach ($data['subjects'] as $sb) {
                $abbr = !empty($sb['subjectabbreviation']) ? $sb['subjectabbreviation'] : '';
                $stmt_sb->bind_param("ssss", $sb['subjectid'], $schoolid, $sb['subjectname'], $abbr);
                $stmt_sb->execute();
            }
        }

        // D. Store Mark Distributions
        if (!empty($data['mark_distributions']) && is_array($data['mark_distributions'])) {
            $stmt_md = $conn->prepare("REPLACE INTO tbl_cbt_mark_distributions (markdistid, classgroupid, markname, markscore) VALUES (?, ?, ?, ?)");
            foreach ($data['mark_distributions'] as $md) {
                $score = intval($md['markscore']);
                $stmt_md->bind_param("sssi", $md['markdistid'], $md['classgroupid'], $md['markname'], $score);
                $stmt_md->execute();
            }
        }

        // E. Store Active Students Roster
        if (!empty($data['students']) && is_array($data['students'])) {
            $stmt_std = $conn->prepare("REPLACE INTO tbl_cbt_students (studentid, schoolid, regnumber, firstname, lastname, middlename, classgroupid, classid, classname, password_raw, pin, gender) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($data['students'] as $st) {
                $pwd = !empty($st['password_raw']) ? $st['password_raw'] : '123456';
                $pin = !empty($st['pin']) ? $st['pin'] : '1234';
                $cgid = !empty($st['classgroupid']) ? $st['classgroupid'] : '';
                $stmt_std->bind_param("ssssssssssss", $st['studentid'], $st['schoolid'], $st['regnumber'], $st['firstname'], $st['lastname'], $st['middlename'], $cgid, $st['classid'], $st['classname'], $pwd, $pin, $st['gender']);
                $stmt_std->execute();
                $pulled_students++;
            }
        }

        // F. Store Active CBT Exams
        if (!empty($data['exams']) && is_array($data['exams'])) {
            $stmt_ex = $conn->prepare("REPLACE INTO tbl_cbt_exams (examid, schoolid, title, sessionid, termid, classgroupid, classid, subjectid, subjectname, markdistid, markname, duration_minutes, total_questions, passmark, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($data['exams'] as $ex) {
                $cgid = !empty($ex['classgroupid']) ? $ex['classgroupid'] : '';
                $cid = !empty($ex['classid']) ? $ex['classid'] : '';
                $mdid = !empty($ex['markdistid']) ? $ex['markdistid'] : '';
                $mname = !empty($ex['markname']) ? $ex['markname'] : '';
                $stmt_ex->bind_param("sssssssssssiiii", $ex['examid'], $ex['schoolid'], $ex['title'], $ex['sessionid'], $ex['termid'], $cgid, $cid, $ex['subjectid'], $ex['subjectname'], $mdid, $mname, $ex['duration_minutes'], $ex['total_questions'], $ex['passmark'], $ex['is_active']);
                $stmt_ex->execute();
                $pulled_exams++;
            }
        }

        // G. Store Question Bank
        if (!empty($data['questions']) && is_array($data['questions'])) {
            $stmt_q = $conn->prepare("REPLACE INTO tbl_cbt_questions (questionid, examid, question_text, image_url, option_a, option_b, option_c, option_d, option_e, correct_option, mark) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($data['questions'] as $q) {
                $stmt_q->bind_param("ssssssssssi", $q['questionid'], $q['examid'], $q['question_text'], $q['image_url'], $q['option_a'], $q['option_b'], $q['option_c'], $q['option_d'], $q['option_e'], $q['correct_option'], $q['mark']);
                $stmt_q->execute();
                $pulled_questions++;
            }
        }

        // H. Store Admin Users / Credentials
        $conn->query("CREATE TABLE IF NOT EXISTS `tbl_cbt_admins` (
            `adminid` VARCHAR(50) NOT NULL PRIMARY KEY,
            `schoolid` VARCHAR(50) NOT NULL,
            `username` VARCHAR(100) NOT NULL,
            `password_raw` VARCHAR(100) NOT NULL,
            `password_hash` VARCHAR(255) DEFAULT '',
            `fullname` VARCHAR(150) NOT NULL,
            `role` VARCHAR(50) DEFAULT 'Admin',
            `last_login` DATETIME NULL,
            UNIQUE KEY `idx_school_username` (`schoolid`, `username`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pulled_admins = 0;
        $admin_list = [];
        if (!empty($data['admins']) && is_array($data['admins'])) {
            $admin_list = $data['admins'];
        } else if (!empty($data['admin']) && is_array($data['admin'])) {
            $admin_list = [$data['admin']];
        }

        if (!empty($admin_list)) {
            $stmt_adm = $conn->prepare("REPLACE INTO tbl_cbt_admins (adminid, schoolid, username, password_raw, password_hash, fullname, role) VALUES (?, ?, ?, ?, ?, ?, ?)");
            foreach ($admin_list as $adm) {
                $aid = !empty($adm['adminid']) ? $adm['adminid'] : 'ADM_' . strtoupper(substr(md5(uniqid()), 0, 8));
                $uname = !empty($adm['username']) ? $adm['username'] : (!empty($adm['email']) ? $adm['email'] : 'admin');
                $pwd_raw = !empty($adm['password_raw']) ? $adm['password_raw'] : (!empty($adm['password']) ? $adm['password'] : 'admin123');
                $pwd_hash = password_hash($pwd_raw, PASSWORD_DEFAULT);
                $fname = !empty($adm['fullname']) ? $adm['fullname'] : (!empty($adm['name']) ? $adm['name'] : 'System Administrator');
                $arole = !empty($adm['role']) ? $adm['role'] : 'Admin';
                
                $stmt_adm->bind_param("sssssss", $aid, $schoolid, $uname, $pwd_raw, $pwd_hash, $fname, $arole);
                $stmt_adm->execute();
                $pulled_admins++;
            }
        }

        // Fallback: Guarantee at least one Admin account exists locally
        $chk_adm = $conn->query("SELECT COUNT(*) as cnt FROM tbl_cbt_admins WHERE schoolid = '$schoolid'");
        if (!$chk_adm || $chk_adm->fetch_assoc()['cnt'] == 0) {
            $def_aid = 'ADM_' . $schoolid . '_001';
            $def_uname = 'admin';
            $def_pwd_raw = 'admin123';
            $def_pwd_hash = password_hash($def_pwd_raw, PASSWORD_DEFAULT);
            $def_fname = 'School CBT Admin';
            $def_role = 'Super Admin';

            $stmt_def = $conn->prepare("REPLACE INTO tbl_cbt_admins (adminid, schoolid, username, password_raw, password_hash, fullname, role) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt_def->bind_param("sssssss", $def_aid, $schoolid, $def_uname, $def_pwd_raw, $def_pwd_hash, $def_fname, $def_role);
            $stmt_def->execute();
            $pulled_admins++;
        }

        // Log Success
        $log_msg = "Successfully fetched REST API data for " . $sp['schoolname'] . ": " . number_format($pulled_students) . " students, $pulled_exams CBT exams, and $pulled_questions questions imported.";
        $conn->query("INSERT INTO tbl_cbt_sync_logs (sync_type, status, records_affected, message) VALUES ('PULL_API', 'SUCCESS', " . ($pulled_students + $pulled_exams) . ", '$log_msg')");
        
        if (isset($_GET['schoolid']) || isset($_GET['auto'])) {
            header("Location: ../admin/login.php?setup_done=1");
            exit;
        }

        $msg = $log_msg;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Downstream Data Pull | Offline CBT</title>
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
                
                <?php if (is_admin_logged_in()): ?>
                    <div style="padding: 10px 14px; margin-bottom: 16px; background: rgba(255,255,255,0.03); border-radius: 8px; border: 1px solid var(--border-color);">
                        <div style="font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Admin Logged In:</div>
                        <div style="font-weight: 700; font-size: 13px; color: var(--text-main); text-overflow: ellipsis; overflow: hidden; white-space: nowrap;"><?= htmlspecialchars(!empty($_SESSION['cbt_admin']['fullname']) ? $_SESSION['cbt_admin']['fullname'] : $_SESSION['cbt_admin']['username']) ?></div>
                    </div>
                <?php endif; ?>

                <nav class="sidebar-menu">
                    <a href="../admin/index.php" class="sidebar-link">
                        <i class="fa-solid fa-gauge-high"></i> Dashboard
                    </a>
                    <a href="../admin/exams.php" class="sidebar-link">
                        <i class="fa-solid fa-list-check"></i> Manage Exams
                    </a>
                    <a href="../admin/questions.php" class="sidebar-link">
                        <i class="fa-solid fa-layer-group"></i> Question Bank
                    </a>
                    <a href="../admin/students.php" class="sidebar-link">
                        <i class="fa-solid fa-users"></i> Student Roster
                    </a>
                    <a href="sync_pull.php" class="sidebar-link active">
                        <i class="fa-solid fa-cloud-arrow-down"></i> Pull Online Data
                    </a>
                    <a href="sync_push.php" class="sidebar-link">
                        <i class="fa-solid fa-cloud-arrow-up"></i> Push Scores Online
                    </a>
                    <a href="../admin/reset.php" class="sidebar-link">
                        <i class="fa-solid fa-rotate"></i> Reset Data
                    </a>
                    <?php if (is_admin_logged_in()): ?>
                        <a href="../admin/login.php?logout=1" class="sidebar-link" style="color: var(--danger); margin-top: 8px;">
                            <i class="fa-solid fa-right-from-bracket"></i> Logout
                        </a>
                    <?php endif; ?>
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
                <div class="card-title"><i class="fa-solid fa-cloud-arrow-down"></i> REST API Downstream Sync</div>
                <div class="card-desc">Fetch school roster, class groups, mark distributions, active CBT exams, and question banks via secure Eduportal REST API endpoint.</div>

                <?php if (!empty($msg)): ?>
                    <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($msg) ?></div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <div class="stat-box" style="margin-bottom: 24px; text-align: left;">
                    <div style="font-weight: 600; font-size: 16px; margin-bottom: 8px;"><i class="fa-solid fa-circle-info" style="color: var(--primary);"></i> Current API Endpoint Status</div>
                    <div style="font-size: 14px; color: var(--text-muted);">
                        School ID: <strong><?= htmlspecialchars($schoolid) ?></strong><br>
                        Online API: <strong><?= htmlspecialchars($online_url) ?>/api/cbt_sync_api.php</strong><br>
                        Last Synced: <strong><?= !empty($school['last_synced_at']) ? date('d M Y, h:i A', strtotime($school['last_synced_at'])) : 'Never' ?></strong>
                    </div>
                </div>

                <a href="sync_pull.php?execute=1" class="btn btn-primary" style="width: 100%; text-decoration: none;">
                    <i class="fa-solid fa-arrows-rotate"></i> Fetch Online School Data via REST API
                </a>
                
                <div style="margin-top: 16px; text-align: center;">
                    <a href="../admin/index.php" style="color: var(--text-muted); text-decoration: none; font-size: 14px;"><i class="fa-solid fa-arrow-left"></i> Return to Admin Dashboard</a>
                </div>
            </div>
        </main>
    </div>
</body>
</html>