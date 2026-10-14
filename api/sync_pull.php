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
        if (isset($_GET['schoolid'])) {
            header("Location: ../admin/setup.php?error=" . urlencode($error) . "&schoolid=" . urlencode($schoolid));
            exit;
        }
    } else {
        $pulled_students = 0;
        $pulled_exams = 0;
        $pulled_questions = 0;
        $pulled_sessions = 0;
        $pulled_terms = 0;

        // Resolve Active Session & Term from API payload
        $active_session_id = '';
        $active_term_id = '';

        if (!empty($data['active_session']) && is_array($data['active_session'])) {
            $active_session_id = !empty($data['active_session']['sessionid']) ? (string)$data['active_session']['sessionid'] : '';
        } else if (!empty($data['current_sessionid'])) {
            $active_session_id = (string)$data['current_sessionid'];
        }

        if (!empty($data['active_term']) && is_array($data['active_term'])) {
            $active_term_id = !empty($data['active_term']['termid']) ? (string)$data['active_term']['termid'] : '';
        } else if (!empty($data['current_termid'])) {
            $active_term_id = (string)$data['current_termid'];
        }

        // A. Store School Profile with current_sessionid and current_termid
        $sp = $data['school'];
        $stmt_sch = $conn->prepare("REPLACE INTO tbl_cbt_school (schoolid, schoolname, schooladdress, logo_path, online_url, current_sessionid, current_termid, last_synced_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())");
        $stmt_sch->bind_param("sssssss", $sp['schoolid'], $sp['schoolname'], $sp['schooladdress'], $sp['logo_path'], $online_url, $active_session_id, $active_term_id);
        $stmt_sch->execute();

        // A2. Store Academic Sessions
        if (!empty($data['sessions']) && is_array($data['sessions'])) {
            $conn->query("CREATE TABLE IF NOT EXISTS `tbl_cbt_sessions` (
                `sessionid` VARCHAR(50) NOT NULL PRIMARY KEY,
                `sessionname` VARCHAR(100) NOT NULL,
                `is_current` TINYINT(1) DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $stmt_sess = $conn->prepare("REPLACE INTO tbl_cbt_sessions (sessionid, sessionname, is_current) VALUES (?, ?, ?)");
            foreach ($data['sessions'] as $sess) {
                $is_curr = (!empty($sess['is_current']) || (string)$sess['sessionid'] === $active_session_id) ? 1 : 0;
                $stmt_sess->bind_param("ssi", $sess['sessionid'], $sess['sessionname'], $is_curr);
                $stmt_sess->execute();
                $pulled_sessions++;
            }
        }

        // A3. Store Academic Terms
        if (!empty($data['terms']) && is_array($data['terms'])) {
            $conn->query("CREATE TABLE IF NOT EXISTS `tbl_cbt_terms` (
                `termid` VARCHAR(50) NOT NULL PRIMARY KEY,
                `termname` VARCHAR(100) NOT NULL,
                `termposition` VARCHAR(50),
                `is_current` TINYINT(1) DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

            $col_t_curr = $conn->query("SHOW COLUMNS FROM `tbl_cbt_terms` LIKE 'is_current'");
            if (!$col_t_curr || $col_t_curr->num_rows === 0) {
                $conn->query("ALTER TABLE `tbl_cbt_terms` ADD COLUMN `is_current` TINYINT(1) DEFAULT 0");
            }

            $stmt_trm = $conn->prepare("REPLACE INTO tbl_cbt_terms (termid, termname, termposition, is_current) VALUES (?, ?, ?, ?)");
            foreach ($data['terms'] as $trm) {
                $tpos = !empty($trm['termposition']) ? $trm['termposition'] : '';
                $is_curr = (!empty($trm['is_current']) || (string)$trm['termid'] === $active_term_id) ? 1 : 0;
                $stmt_trm->bind_param("sssi", $trm['termid'], $trm['termname'], $tpos, $is_curr);
                $stmt_trm->execute();
                $pulled_terms++;
            }
        }

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
            $col_md_sess = $conn->query("SHOW COLUMNS FROM `tbl_cbt_mark_distributions` LIKE 'sessionid'");
            if (!$col_md_sess || $col_md_sess->num_rows === 0) {
                $conn->query("ALTER TABLE `tbl_cbt_mark_distributions` ADD COLUMN `sessionid` VARCHAR(50) DEFAULT '' AFTER `markdistid`");
            }
            $col_md_term = $conn->query("SHOW COLUMNS FROM `tbl_cbt_mark_distributions` LIKE 'termid'");
            if (!$col_md_term || $col_md_term->num_rows === 0) {
                $conn->query("ALTER TABLE `tbl_cbt_mark_distributions` ADD COLUMN `termid` VARCHAR(50) DEFAULT '' AFTER `sessionid`");
            }

            // Clean up any legacy rows with empty session/term so filtering works accurately
            $conn->query("DELETE FROM tbl_cbt_mark_distributions WHERE sessionid = '' OR termid = ''");

            $stmt_md = $conn->prepare("REPLACE INTO tbl_cbt_mark_distributions (markdistid, sessionid, termid, classgroupid, markname, markscore) VALUES (?, ?, ?, ?, ?, ?)");
            foreach ($data['mark_distributions'] as $md) {
                $md_sess = !empty($md['sessionid']) ? (string)$md['sessionid'] : (!empty($active_session_id) ? (string)$active_session_id : '');
                $md_term = !empty($md['termid']) ? (string)$md['termid'] : (!empty($active_term_id) ? (string)$active_term_id : '');
                $score = intval($md['markscore']);
                $stmt_md->bind_param("sssssi", $md['markdistid'], $md_sess, $md_term, $md['classgroupid'], $md['markname'], $score);
                $stmt_md->execute();
            }
        }

        // D2. Store Session Configurations (tbl_cbt_session_config)
        $conn->query("CREATE TABLE IF NOT EXISTS `tbl_cbt_session_config` (
            `sessionconfigid` VARCHAR(50) NOT NULL PRIMARY KEY,
            `schoolid` VARCHAR(50) NOT NULL,
            `sessionid` VARCHAR(50) NOT NULL,
            `classgroupid` VARCHAR(50) NOT NULL,
            `termgroupid` VARCHAR(50) DEFAULT '',
            `termid` VARCHAR(50) NOT NULL,
            `scoredistid` VARCHAR(50) DEFAULT '',
            `annualpercentage` VARCHAR(10) DEFAULT '',
            KEY `idx_cg_sess` (`classgroupid`, `sessionid`),
            KEY `idx_term` (`termid`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

        $pulled_session_configs = 0;
        if (!empty($data['session_configs']) && is_array($data['session_configs'])) {
            $stmt_sc = $conn->prepare("REPLACE INTO tbl_cbt_session_config (sessionconfigid, schoolid, sessionid, classgroupid, termgroupid, termid, scoredistid, annualpercentage) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($data['session_configs'] as $sc) {
                $sc_ann = !empty($sc['annualpercentage']) ? (string)$sc['annualpercentage'] : '';
                $sc_tg = !empty($sc['termgroupid']) ? (string)$sc['termgroupid'] : '';
                $stmt_sc->bind_param("ssssssss", 
                    $sc['sessionconfigid'], 
                    $sc['schoolid'], 
                    $sc['sessionid'], 
                    $sc['classgroupid'], 
                    $sc_tg, 
                    $sc['termid'], 
                    $sc['scoredistid'], 
                    $sc_ann
                );
                $stmt_sc->execute();
                $pulled_session_configs++;
            }
        }

        // Ensure sessionid and termid columns exist on tbl_cbt_students
        $col_s_sess = $conn->query("SHOW COLUMNS FROM `tbl_cbt_students` LIKE 'sessionid'");
        if (!$col_s_sess || $col_s_sess->num_rows === 0) {
            $conn->query("ALTER TABLE `tbl_cbt_students` ADD COLUMN `sessionid` VARCHAR(50) DEFAULT '' AFTER `schoolid`");
        }
        $col_s_term = $conn->query("SHOW COLUMNS FROM `tbl_cbt_students` LIKE 'termid'");
        if (!$col_s_term || $col_s_term->num_rows === 0) {
            $conn->query("ALTER TABLE `tbl_cbt_students` ADD COLUMN `termid` VARCHAR(50) DEFAULT '' AFTER `sessionid`");
        }

        // E. Store Active Students Roster (Filtered by Active Session ID & Active Term ID)
        if (!empty($data['students']) && is_array($data['students'])) {
            $stmt_std = $conn->prepare("REPLACE INTO tbl_cbt_students (studentid, schoolid, sessionid, termid, regnumber, firstname, lastname, middlename, classgroupid, classid, classname, password_raw, pin, gender) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($data['students'] as $st) {
                // Filter by active session ID offline
                if (!empty($active_session_id) && !empty($st['sessionid']) && (string)$st['sessionid'] !== (string)$active_session_id) {
                    continue;
                }
                // Filter by active term ID offline if student record has a specific term
                if (!empty($active_term_id) && !empty($st['termid']) && (string)$st['termid'] !== (string)$active_term_id) {
                    continue;
                }

                $st_sess = !empty($st['sessionid']) ? $st['sessionid'] : $active_session_id;
                $st_term = !empty($st['termid']) ? $st['termid'] : $active_term_id;
                $pwd = !empty($st['password_raw']) ? $st['password_raw'] : '123456';
                $pin = !empty($st['pin']) ? $st['pin'] : '1234';
                $cgid = !empty($st['classgroupid']) ? $st['classgroupid'] : '';
                $stmt_std->bind_param("ssssssssssssss", $st['studentid'], $st['schoolid'], $st_sess, $st_term, $st['regnumber'], $st['firstname'], $st['lastname'], $st['middlename'], $cgid, $st['classid'], $st['classname'], $pwd, $pin, $st['gender']);
                $stmt_std->execute();
                $pulled_students++;
            }
        }

        // Ensure schedule columns exist in tbl_cbt_exams
        $chk_ex_date = $conn->query("SHOW COLUMNS FROM `tbl_cbt_exams` LIKE 'exam_date'");
        if (!$chk_ex_date || $chk_ex_date->num_rows === 0) {
            $conn->query("ALTER TABLE `tbl_cbt_exams` ADD COLUMN `exam_date` DATE DEFAULT NULL AFTER `markname`");
            $conn->query("ALTER TABLE `tbl_cbt_exams` ADD COLUMN `end_date` DATE DEFAULT NULL AFTER `exam_date`");
            $conn->query("ALTER TABLE `tbl_cbt_exams` ADD COLUMN `start_time` TIME DEFAULT '08:00:00' AFTER `end_date`");
            $conn->query("ALTER TABLE `tbl_cbt_exams` ADD COLUMN `end_time` TIME DEFAULT '17:00:00' AFTER `start_time`");
        }

        // F. Store Active CBT Exams
        if (!empty($data['exams']) && is_array($data['exams'])) {
            $stmt_ex = $conn->prepare("REPLACE INTO tbl_cbt_exams (examid, schoolid, title, sessionid, termid, classgroupid, classid, subjectid, subjectname, markdistid, markname, exam_date, end_date, start_time, end_time, duration_minutes, total_questions, passmark, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($data['exams'] as $ex) {
                $cgid = !empty($ex['classgroupid']) ? $ex['classgroupid'] : '';
                $cid = !empty($ex['classid']) ? $ex['classid'] : '';
                $mdid = !empty($ex['markdistid']) ? $ex['markdistid'] : '';
                $mname = !empty($ex['markname']) ? $ex['markname'] : '';
                $ex_date = !empty($ex['exam_date']) ? $ex['exam_date'] : date('Y-m-d');
                $end_date = !empty($ex['end_date']) ? $ex['end_date'] : $ex_date;
                $start_time = !empty($ex['start_time']) ? $ex['start_time'] : '08:00:00';
                $end_time = !empty($ex['end_time']) ? $ex['end_time'] : '17:00:00';
                if (strlen($start_time) === 5) $start_time .= ':00';
                if (strlen($end_time) === 5) $end_time .= ':00';
                $stmt_ex->bind_param("sssssssssssssssiiii", $ex['examid'], $ex['schoolid'], $ex['title'], $ex['sessionid'], $ex['termid'], $cgid, $cid, $ex['subjectid'], $ex['subjectname'], $mdid, $mname, $ex_date, $end_date, $start_time, $end_time, $ex['duration_minutes'], $ex['total_questions'], $ex['passmark'], $ex['is_active']);
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
                $default_admin_pwd = 'CBTADMIN2026';
                $pwd_raw = $default_admin_pwd;
                $pwd_hash = password_hash($default_admin_pwd, PASSWORD_DEFAULT);
                $fname = !empty($adm['fullname']) ? $adm['fullname'] : (!empty($adm['name']) ? $adm['name'] : 'System Administrator');
                $arole = !empty($adm['role']) ? substr($adm['role'], 0, 255) : 'Admin';

                // If admin already changed password from default, preserve it across re-syncs
                $esc_u = $conn->real_escape_string($uname);
                $chk_ex = $conn->query("SELECT password_raw, password_hash FROM tbl_cbt_admins WHERE schoolid = '$schoolid' AND username = '$esc_u' LIMIT 1");
                if ($chk_ex && $chk_ex->num_rows > 0) {
                    $ex_row = $chk_ex->fetch_assoc();
                    if (!empty($ex_row['password_hash']) && !password_verify('CBTADMIN2026', $ex_row['password_hash']) && $ex_row['password_raw'] !== 'CBTADMIN2026') {
                        $pwd_raw = $ex_row['password_raw'];
                        $pwd_hash = $ex_row['password_hash'];
                    }
                }
                
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
            $def_pwd_raw = 'CBTADMIN2026';
            $def_pwd_hash = password_hash($def_pwd_raw, PASSWORD_DEFAULT);
            $def_fname = 'School CBT Admin';
            $def_role = 'Super Admin';

            $stmt_def = $conn->prepare("REPLACE INTO tbl_cbt_admins (adminid, schoolid, username, password_raw, password_hash, fullname, role) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt_def->bind_param("sssssss", $def_aid, $schoolid, $def_uname, $def_pwd_raw, $def_pwd_hash, $def_fname, $def_role);
            $stmt_def->execute();
            $pulled_admins++;
        }

        // Log Success
        $act_sname = !empty($data['active_session']['sessionname']) ? $data['active_session']['sessionname'] : $active_session_id;
        $act_tname = !empty($data['active_term']['termname']) ? $data['active_term']['termname'] : $active_term_id;
        $log_msg = "Successfully fetched REST API data for " . $sp['schoolname'] . ": " . number_format($pulled_students) . " active students (Session: $act_sname, Term: $act_tname), $pulled_sessions sessions, $pulled_terms terms, $pulled_exams CBT exams, and $pulled_questions questions imported.";
        $conn->query("INSERT INTO tbl_cbt_sync_logs (sync_type, status, records_affected, message) VALUES ('PULL_API', 'SUCCESS', " . ($pulled_students + $pulled_exams) . ", '" . $conn->real_escape_string($log_msg) . "')");
        
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
        <?php 
        $active_nav = 'sync_pull';
        include __DIR__ . '/../admin/sidebar.php'; 
        ?>

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