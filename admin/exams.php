<?php
require_once __DIR__ . '/../config/config.php';

require_admin_login();

$conn = get_db_connection();
$school = get_school_config();
$schoolid = $school['schoolid'];
$admin = $_SESSION['cbt_admin'];

$msg = '';
$error = '';

// Ensure schedule columns exist in tbl_cbt_exams
$chk_col = $conn->query("SHOW COLUMNS FROM tbl_cbt_exams LIKE 'exam_date'");
if ($chk_col && $chk_col->num_rows === 0) {
    $conn->query("ALTER TABLE tbl_cbt_exams ADD COLUMN `exam_date` DATE DEFAULT NULL AFTER `markname`");
    $conn->query("ALTER TABLE tbl_cbt_exams ADD COLUMN `end_date` DATE DEFAULT NULL AFTER `exam_date`");
    $conn->query("ALTER TABLE tbl_cbt_exams ADD COLUMN `start_time` TIME DEFAULT '08:00:00' AFTER `end_date`");
    $conn->query("ALTER TABLE tbl_cbt_exams ADD COLUMN `end_time` TIME DEFAULT '17:00:00' AFTER `start_time`");
}

// Ensure tbl_cbt_session_config exists locally
$chk_sc = $conn->query("SHOW TABLES LIKE 'tbl_cbt_session_config'");
if (!$chk_sc || $chk_sc->num_rows === 0) {
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
}

// Helper: Fetch Eligible Terms by Class Group & Academic Session
if (!function_exists('get_eligible_terms')) {
    function get_eligible_terms($conn, $classgroupid, $sessionid) {
        $terms = [];
        if (empty($classgroupid) || empty($sessionid)) {
            return $terms;
        }

        $esc_cgid = $conn->real_escape_string($classgroupid);
        $esc_sess = $conn->real_escape_string($sessionid);

        $has_sc = false;
        $chk_sc_local = $conn->query("SHOW TABLES LIKE 'tbl_cbt_session_config'");
        if ($chk_sc_local && $chk_sc_local->num_rows > 0) {
            $has_sc = true;
        }

        $sc_subquery = $has_sc ? "SELECT sc.termid FROM tbl_cbt_session_config sc WHERE sc.classgroupid = '$esc_cgid' AND sc.sessionid = '$esc_sess' AND sc.termid != '' UNION " : "";

        $sort_clause = "ORDER BY 
            CASE 
                WHEN LOWER(t.termposition) = 'begining' OR LOWER(t.termposition) = 'beginning' OR t.termposition = '1' OR LOWER(t.termname) LIKE '%1st%' OR LOWER(t.termname) LIKE '%first%' THEN 1
                WHEN LOWER(t.termposition) = 'middle' OR t.termposition = '2' OR LOWER(t.termname) LIKE '%2nd%' OR LOWER(t.termname) LIKE '%second%' THEN 2
                WHEN LOWER(t.termposition) = 'last' OR t.termposition = '3' OR LOWER(t.termname) LIKE '%3rd%' OR LOWER(t.termname) LIKE '%third%' THEN 3
                ELSE 4
            END ASC, t.termname ASC";

        $sql = "SELECT DISTINCT t.termid, t.termname, t.termposition, t.is_current 
                FROM tbl_cbt_terms t
                WHERE t.termid IN (
                    $sc_subquery
                    SELECT md.termid FROM tbl_cbt_mark_distributions md 
                    WHERE (md.classgroupid = '$esc_cgid' OR md.classgroupid = '') 
                      AND (md.sessionid = '$esc_sess' OR md.sessionid = '') 
                      AND md.termid != ''
                    UNION
                    SELECT s.termid FROM tbl_cbt_students s 
                    WHERE (s.classgroupid = '$esc_cgid' OR s.classid = '$esc_cgid') 
                      AND s.sessionid = '$esc_sess' 
                      AND s.termid != ''
                    UNION
                    SELECT e.termid FROM tbl_cbt_exams e 
                    WHERE (e.classgroupid = '$esc_cgid' OR e.classid = '$esc_cgid') 
                      AND e.sessionid = '$esc_sess' 
                      AND e.termid != ''
                )
                $sort_clause";

        $res = $conn->query($sql);
        if ($res && $res->num_rows > 0) {
            while ($row = $res->fetch_assoc()) {
                $terms[] = [
                    'termid' => $row['termid'],
                    'termname' => $row['termname'],
                    'termposition' => $row['termposition'],
                    'is_current' => (int)$row['is_current']
                ];
            }
        }

        // Fallback: If no terms matched in session configs or mark dist, check active school term or terms list
        if (empty($terms)) {
            $fallback_sql = "SELECT DISTINCT termid, termname, termposition, is_current FROM tbl_cbt_terms WHERE is_current = 1 LIMIT 1";
            $res_fb = $conn->query($fallback_sql);
            if ($res_fb && $res_fb->num_rows > 0) {
                while ($row = $res_fb->fetch_assoc()) {
                    $terms[] = [
                        'termid' => $row['termid'],
                        'termname' => $row['termname'],
                        'termposition' => $row['termposition'],
                        'is_current' => (int)$row['is_current']
                    ];
                }
            }
        }

        return $terms;
    }
}

// Dynamic AJAX Endpoint: Fetch Eligible Terms by Class Group & Academic Session
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_eligible_terms') {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $cg_in = isset($_GET['classgroupid']) ? trim($_GET['classgroupid']) : '';
    $s_in = isset($_GET['sessionid']) ? trim($_GET['sessionid']) : '';
    $eligible_terms = get_eligible_terms($conn, $cg_in, $s_in);
    echo json_encode($eligible_terms);
    exit;
}

// Toggle Exam Activation
if (isset($_GET['toggle_active'])) {
    $examid = trim($_GET['toggle_active']);
    $conn->query("UPDATE tbl_cbt_exams SET is_active = IF(is_active=1, 0, 1) WHERE examid = '$examid'");
    header("Location: exams.php?msg=Status updated successfully");
    exit;
}

// Delete Single Exam
if (isset($_GET['delete_exam'])) {
    $examid = trim($_GET['delete_exam']);
    $esc_id = $conn->real_escape_string($examid);
    $conn->query("DELETE FROM tbl_cbt_exams WHERE examid = '$esc_id'");
    $conn->query("DELETE FROM tbl_cbt_questions WHERE examid = '$esc_id'");
    $conn->query("DELETE FROM tbl_cbt_responses WHERE examid = '$esc_id'");
    $conn->query("DELETE FROM tbl_cbt_results WHERE examid = '$esc_id'");
    header("Location: exams.php?msg=" . urlencode("Exam deleted successfully"));
    exit;
}

// Bulk Delete Exams
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_delete_exams'])) {
    if (!empty($_POST['selected_exams']) && is_array($_POST['selected_exams'])) {
        $clean_ids = [];
        foreach ($_POST['selected_exams'] as $raw_id) {
            $raw_id = trim($raw_id);
            if (!empty($raw_id)) {
                $clean_ids[] = "'" . $conn->real_escape_string($raw_id) . "'";
            }
        }

        if (!empty($clean_ids)) {
            $id_list = implode(',', $clean_ids);
            $conn->query("DELETE FROM tbl_cbt_exams WHERE examid IN ($id_list)");
            $conn->query("DELETE FROM tbl_cbt_questions WHERE examid IN ($id_list)");
            $conn->query("DELETE FROM tbl_cbt_responses WHERE examid IN ($id_list)");
            $conn->query("DELETE FROM tbl_cbt_results WHERE examid IN ($id_list)");

            $deleted_count = count($clean_ids);
            header("Location: exams.php?msg=" . urlencode("Successfully deleted {$deleted_count} exam(s) and their associated questions."));
            exit;
        } else {
            $error = "No valid exams were selected for deletion.";
        }
    } else {
        $error = "Please select at least one exam to delete.";
    }
}

// Save Exam (Create or Edit)
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_exam'])) {
    $examid = !empty($_POST['examid']) ? trim($_POST['examid']) : 'EXAM_' . strtoupper(substr(md5(uniqid()), 0, 8));
    $title = trim($_POST['title']);
    $classgroupid = trim($_POST['classgroupid']);
    $subjectid = trim($_POST['subjectid']);
    $subjectname = trim($_POST['subjectname']);
    $markdistid = trim($_POST['markdistid']);
    $markname = isset($_POST['markname']) ? trim($_POST['markname']) : '';

    $sess = isset($_POST['sessionid']) ? trim($_POST['sessionid']) : '';
    $term = isset($_POST['termid']) ? trim($_POST['termid']) : '';
    $classid = $classgroupid;

    // Fetch markname if not submitted or empty
    if (empty($markname) && !empty($markdistid)) {
        $esc_mdid = $conn->real_escape_string($markdistid);
        $esc_cgid = $conn->real_escape_string($classgroupid);
        $esc_sess = $conn->real_escape_string($sess);
        $esc_term = $conn->real_escape_string($term);

        $res_mn = $conn->query("SELECT markname FROM tbl_cbt_mark_distributions WHERE markdistid = '$esc_mdid' AND classgroupid = '$esc_cgid' AND (sessionid = '$esc_sess' OR sessionid = '') AND (termid = '$esc_term' OR termid = '') LIMIT 1");
        if (!$res_mn || $res_mn->num_rows === 0) {
            $res_mn = $conn->query("SELECT markname FROM tbl_cbt_mark_distributions WHERE markdistid = '$esc_mdid' AND (classgroupid = '$esc_cgid' OR classgroupid = '') LIMIT 1");
        }
        if (!$res_mn || $res_mn->num_rows === 0) {
            $res_mn = $conn->query("SELECT markname FROM tbl_cbt_mark_distributions WHERE markdistid = '$esc_mdid' LIMIT 1");
        }
        if ($res_mn && $res_mn->num_rows > 0) {
            $markname = $res_mn->fetch_assoc()['markname'];
        }
    }

    // Fallback if markname is still empty or default ID pattern
    if (empty($markname) || $markname === 'CBT FINAL') {
        if (strpos($markdistid, '1CA') !== false) {
            $markname = 'FIRST CA';
        } else if (strpos($markdistid, '2CA') !== false) {
            $markname = 'SECOND CA';
        } else if (strpos($markdistid, 'EXAM') !== false) {
            $markname = 'EXAM';
        } else if (empty($markname)) {
            $markname = 'CBT FINAL';
        }
    }

    $duration = intval($_POST['duration_minutes']);
    $total_q = intval($_POST['total_questions']);
    $passmark = isset($_POST['passmark']) ? intval($_POST['passmark']) : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;

    $exam_date = !empty($_POST['exam_date']) ? trim($_POST['exam_date']) : date('Y-m-d');
    $end_date = !empty($_POST['end_date']) ? trim($_POST['end_date']) : $exam_date;
    $start_time = !empty($_POST['start_time']) ? trim($_POST['start_time']) : '08:00';
    $end_time = !empty($_POST['end_time']) ? trim($_POST['end_time']) : '17:00';

    if (strlen($start_time) === 5) $start_time .= ':00';
    if (strlen($end_time) === 5) $end_time .= ':00';

    if (empty($title) || empty($subjectname) || empty($classgroupid) || empty($sess) || empty($term) || empty($markdistid) || empty($exam_date) || empty($start_time) || empty($end_time) || $duration <= 0) {
        $error = "Please fill in all required exam fields, including Exam Date, Start Time, End Time, Target Class Group, Academic Session, Academic Term, Subject, and Mark Distribution.";
    } else {
        // DUPLICATE EXAM CHECK:
        // Users should not be able to create the Same Exam for the Same Class for the Same Subject for the Same Mark Distribution
        $esc_schoolid = $conn->real_escape_string($schoolid);
        $esc_sess = $conn->real_escape_string($sess);
        $esc_term = $conn->real_escape_string($term);
        $esc_cgid = $conn->real_escape_string($classgroupid);
        $esc_subid = $conn->real_escape_string($subjectid);
        $esc_subname = $conn->real_escape_string($subjectname);
        $esc_mdid = $conn->real_escape_string($markdistid);
        $esc_mname = $conn->real_escape_string($markname);
        $esc_eid = $conn->real_escape_string($examid);

        $dup_check = $conn->query("SELECT examid, title, subjectname, markname FROM tbl_cbt_exams 
            WHERE schoolid = '$esc_schoolid' 
            AND sessionid = '$esc_sess' 
            AND termid = '$esc_term' 
            AND classgroupid = '$esc_cgid' 
            AND (subjectid = '$esc_subid' OR subjectname = '$esc_subname') 
            AND (markdistid = '$esc_mdid' OR markname = '$esc_mname') 
            AND examid != '$esc_eid' 
            LIMIT 1");

        if ($dup_check && $dup_check->num_rows > 0) {
            $dup_row = $dup_check->fetch_assoc();
            $error = "Duplicate Exam Detected: An exam ('" . htmlspecialchars($dup_row['title']) . "') has already been created for this Class Group, Subject (" . htmlspecialchars($dup_row['subjectname']) . "), and Mark Distribution (" . htmlspecialchars($dup_row['markname']) . ") in this academic session/term.";
        } else {
            $stmt = $conn->prepare("REPLACE INTO tbl_cbt_exams (examid, schoolid, title, sessionid, termid, classgroupid, classid, subjectid, subjectname, markdistid, markname, exam_date, end_date, start_time, end_time, duration_minutes, total_questions, passmark, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("sssssssssssssssiiii", $examid, $schoolid, $title, $sess, $term, $classgroupid, $classid, $subjectid, $subjectname, $markdistid, $markname, $exam_date, $end_date, $start_time, $end_time, $duration, $total_q, $passmark, $is_active);
            
            if ($stmt->execute()) {
                $msg = "Exam configuration saved successfully!";
            } else {
                $error = "Failed to save exam: " . $conn->error;
            }
        }
    }
}

// Fetch Academic Sessions for dropdown
$res_sess = $conn->query("SELECT sessionid, sessionname, is_current FROM tbl_cbt_sessions ORDER BY sessionname DESC");
$academic_sessions = [];
if ($res_sess && $res_sess->num_rows > 0) {
    while ($s = $res_sess->fetch_assoc()) {
        $academic_sessions[] = $s;
    }
}
if (empty($academic_sessions)) {
    $academic_sessions = [
        ['sessionid' => '68bab4c928f06277748994016768200', 'sessionname' => '2025/2026 Academic Session', 'is_current' => 1],
        ['sessionid' => '68bab4c928f06277748994016768201', 'sessionname' => '2026/2027 Academic Session', 'is_current' => 0]
    ];
}

// Fetch Academic Terms for dropdown
$res_term = $conn->query("SELECT termid, termname, termposition FROM tbl_cbt_terms ORDER BY termposition ASC, termname ASC");
$academic_terms = [];
if ($res_term && $res_term->num_rows > 0) {
    while ($t = $res_term->fetch_assoc()) {
        $academic_terms[] = $t;
    }
}
if (empty($academic_terms)) {
    $academic_terms = [
        ['termid' => '5613a794ef5ac183124439098039600', 'termname' => 'First Term'],
        ['termid' => '5613a794ef5ac183124439098039601', 'termname' => 'Second Term'],
        ['termid' => '5613a794ef5ac183124439098039602', 'termname' => 'Third Term']
    ];
}

// Fetch School Subjects for dropdown
$res_sub = $conn->query("SELECT subjectid, subjectname, subjectabbreviation FROM tbl_cbt_subjects ORDER BY subjectname ASC");
$school_subjects = [];
if ($res_sub && $res_sub->num_rows > 0) {
    while ($sb = $res_sub->fetch_assoc()) {
        $school_subjects[] = $sb;
    }
}

// Fetch Class Groups for dropdown
$res_cg = $conn->query("SELECT classgroupid, classgroupname FROM tbl_cbt_class_groups ORDER BY classgroupname ASC");
$class_groups = [];
if ($res_cg && $res_cg->num_rows > 0) {
    while ($cg = $res_cg->fetch_assoc()) {
        $class_groups[] = $cg;
    }
}
if (empty($class_groups)) {
    $class_groups = [
        ['classgroupid' => '5613a8d09a236551044049063135000', 'classgroupname' => 'JSS 1'],
        ['classgroupid' => '5613a8d974596468749944047656600', 'classgroupname' => 'JSS 2'],
        ['classgroupid' => '5613a8e2adc66987306843071178200', 'classgroupname' => 'JSS 3'],
        ['classgroupid' => '5613a8e7412ee460972648026699000', 'classgroupname' => 'SS 1'],
        ['classgroupid' => '5613a8eb3f9f5758577815026059700', 'classgroupname' => 'SS 2'],
        ['classgroupid' => '5613a8ef6056c055355753039460400', 'classgroupname' => 'SS 3']
    ];
}

// Fetch Mark Distributions
$res_md = $conn->query("SELECT markdistid, sessionid, termid, classgroupid, markname, markscore FROM tbl_cbt_mark_distributions ORDER BY markname ASC");
$mark_distributions = [];
if ($res_md && $res_md->num_rows > 0) {
    while ($md = $res_md->fetch_assoc()) {
        $mark_distributions[] = $md;
    }
}

// Fetch Editing Exam details if edit parameter set
$edit_exam = null;
if (isset($_GET['edit'])) {
    $edit_id = trim($_GET['edit']);
    $res_ed = $conn->query("SELECT * FROM tbl_cbt_exams WHERE examid = '$edit_id'");
    if ($res_ed && $res_ed->num_rows > 0) {
        $edit_exam = $res_ed->fetch_assoc();
    }
}

$initial_eligible_terms = [];
if ($edit_exam && !empty($edit_exam['classgroupid']) && !empty($edit_exam['sessionid'])) {
    $initial_eligible_terms = get_eligible_terms($conn, $edit_exam['classgroupid'], $edit_exam['sessionid']);
}

if (isset($_GET['msg'])) {
    $msg = htmlspecialchars($_GET['msg']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage CBT Exams | Offline Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body style="display: block; padding: 0;">
    <div class="app-layout">
        <!-- SIDEBAR NAVIGATION -->
        <?php 
        $active_nav = 'exams';
        include __DIR__ . '/sidebar.php'; 
        ?>

        <!-- MAIN CONTENT AREA -->
        <main class="main-content">
            <?php if (!empty($msg)): ?>
                <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($msg) ?></div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="grid-2" style="grid-template-columns: 380px 1fr; align-items: start;">
                <!-- CREATE / EDIT EXAM FORM -->
                <div class="card">
                    <div class="card-title"><?= $edit_exam ? '<i class="fa-solid fa-pen-to-square"></i> Edit Exam' : '<i class="fa-solid fa-circle-plus"></i> Create New Exam' ?></div>
                    <div class="card-desc">Configure exam duration, target class group, subject, and mark distribution offline.</div>

                    <form method="POST" action="exams.php">
                        <input type="hidden" name="save_exam" value="1">
                        <?php if ($edit_exam): ?>
                            <input type="hidden" name="examid" value="<?= htmlspecialchars($edit_exam['examid']) ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <label class="form-label" for="title">Exam Title</label>
                            <input type="text" id="title" name="title" class="form-control" placeholder="e.g. JSS 2 Basic Science Term 3 Exam" value="<?= $edit_exam ? htmlspecialchars($edit_exam['title']) : '' ?>" required>
                        </div>

                        <!-- 1. TARGET CLASS GROUP SELECTOR (Selected First) -->
                        <div class="form-group">
                            <label class="form-label" for="classgroupid"><i class="fa-solid fa-users"></i> Target Class Group</label>
                            <select id="classgroupid" name="classgroupid" class="form-control" onchange="fetchEligibleTerms()" required style="background: rgba(15, 23, 42, 0.9); font-weight: 600;">
                                <option value="">-- Select Target Class Group --</option>
                                <?php foreach ($class_groups as $cg): ?>
                                    <option value="<?= htmlspecialchars($cg['classgroupid']) ?>" <?= ($edit_exam && $edit_exam['classgroupid'] === $cg['classgroupid']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cg['classgroupname']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 2. ACADEMIC SESSION & TERM SELECTORS -->
                        <div class="grid-2" style="gap: 12px;">
                            <div class="form-group">
                                <label class="form-label" for="sessionid"><i class="fa-solid fa-calendar-days"></i> Academic Session</label>
                                <select id="sessionid" name="sessionid" class="form-control" onchange="fetchEligibleTerms()" required style="background: rgba(15, 23, 42, 0.9); font-weight: 600;">
                                    <option value="">-- Select Academic Session --</option>
                                    <?php foreach ($academic_sessions as $s): ?>
                                        <option value="<?= htmlspecialchars($s['sessionid']) ?>" <?= ($edit_exam && $edit_exam['sessionid'] === $s['sessionid']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($s['sessionname']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="termid">
                                    <i class="fa-solid fa-clock-rotate-left"></i> Academic Term
                                    <span id="termCountBadge" style="font-size: 11px; font-weight: 600; color: var(--accent); margin-left: 6px;"></span>
                                </label>
                                <select id="termid" name="termid" class="form-control" onchange="updateMarkDistributions()" required style="background: rgba(15, 23, 42, 0.9); font-weight: 600;">
                                    <?php if ($edit_exam && !empty($initial_eligible_terms)): ?>
                                        <option value="">-- Select Academic Term --</option>
                                        <?php foreach ($initial_eligible_terms as $t): ?>
                                            <option value="<?= htmlspecialchars($t['termid']) ?>" <?= ($edit_exam['termid'] === $t['termid']) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($t['termname']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php elseif ($edit_exam && !empty($edit_exam['termid'])): ?>
                                        <option value="<?= htmlspecialchars($edit_exam['termid']) ?>" selected>
                                            <?= htmlspecialchars(!empty($edit_exam['termname']) ? $edit_exam['termname'] : 'Selected Term') ?>
                                        </option>
                                    <?php else: ?>
                                        <option value="">-- Select Class Group & Session First --</option>
                                    <?php endif; ?>
                                </select>
                            </div>
                        </div>

                        <!-- 3. TARGET SUBJECT SELECTOR -->
                        <div class="form-group">
                            <label class="form-label" for="subjectid">Target Subject</label>
                            <select id="subjectid" name="subjectid" class="form-control" onchange="updateSubjectName(this)" required style="background: rgba(15, 23, 42, 0.9); font-weight: 600;">
                                <option value="">-- Select Subject --</option>
                                <?php foreach ($school_subjects as $sb): ?>
                                    <option value="<?= htmlspecialchars($sb['subjectid']) ?>" data-name="<?= htmlspecialchars($sb['subjectname']) ?>" <?= ($edit_exam && ($edit_exam['subjectid'] === $sb['subjectid'] || $edit_exam['subjectname'] === $sb['subjectname'])) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sb['subjectname']) ?> <?= !empty($sb['subjectabbreviation']) ? '('.htmlspecialchars($sb['subjectabbreviation']).')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" id="subjectname" name="subjectname" value="<?= $edit_exam ? htmlspecialchars($edit_exam['subjectname']) : '' ?>">
                        </div>

                        <!-- 4. TARGET MARK DISTRIBUTION SELECTOR -->
                        <div class="form-group">
                            <label class="form-label" for="markdistid">Target Mark Distribution</label>
                            <select id="markdistid" name="markdistid" class="form-control" onchange="updateMarkDistName(this)" required style="background: rgba(15, 23, 42, 0.9); font-weight: 600;">
                                <option value="">-- Select Mark Distribution --</option>
                            </select>
                            <input type="hidden" id="markname" name="markname" value="<?= $edit_exam ? htmlspecialchars($edit_exam['markname']) : '' ?>">
                        </div>

                        <!-- 5. EXAM SCHEDULE: DATE & TIME DURATION WINDOW -->
                        <div class="grid-2" style="gap: 12px;">
                            <div class="form-group">
                                <label class="form-label" for="exam_date"><i class="fa-solid fa-calendar-day"></i> Exam Date</label>
                                <input type="date" id="exam_date" name="exam_date" class="form-control" onchange="syncEndDate(this.value)" value="<?= $edit_exam && !empty($edit_exam['exam_date']) ? htmlspecialchars($edit_exam['exam_date']) : date('Y-m-d') ?>" required style="background: rgba(15, 23, 42, 0.9); font-weight: 600;">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="end_date"><i class="fa-solid fa-calendar-check"></i> End Date</label>
                                <input type="date" id="end_date" name="end_date" class="form-control" value="<?= $edit_exam && !empty($edit_exam['end_date']) ? htmlspecialchars($edit_exam['end_date']) : ($edit_exam && !empty($edit_exam['exam_date']) ? htmlspecialchars($edit_exam['exam_date']) : date('Y-m-d')) ?>" required style="background: rgba(15, 23, 42, 0.9); font-weight: 600;">
                            </div>
                        </div>

                        <div class="grid-2" style="gap: 12px;">
                            <div class="form-group">
                                <label class="form-label" for="start_time"><i class="fa-solid fa-clock"></i> Start Time</label>
                                <input type="time" id="start_time" name="start_time" class="form-control" value="<?= $edit_exam && !empty($edit_exam['start_time']) ? htmlspecialchars(substr($edit_exam['start_time'], 0, 5)) : '08:00' ?>" required style="background: rgba(15, 23, 42, 0.9); font-weight: 600;">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="end_time"><i class="fa-solid fa-hourglass-end"></i> End Time</label>
                                <input type="time" id="end_time" name="end_time" class="form-control" value="<?= $edit_exam && !empty($edit_exam['end_time']) ? htmlspecialchars(substr($edit_exam['end_time'], 0, 5)) : '17:00' ?>" required style="background: rgba(15, 23, 42, 0.9); font-weight: 600;">
                            </div>
                        </div>

                        <div class="grid-2" style="gap: 12px;">
                            <div class="form-group">
                                <label class="form-label" for="duration_minutes"><i class="fa-solid fa-stopwatch"></i> Exam Duration (Mins)</label>
                                <input type="number" id="duration_minutes" name="duration_minutes" class="form-control" value="<?= $edit_exam ? intval($edit_exam['duration_minutes']) : '40' ?>" required min="1">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="total_questions"><i class="fa-solid fa-list-ol"></i> Total Questions</label>
                                <input type="number" id="total_questions" name="total_questions" class="form-control" value="<?= $edit_exam ? intval($edit_exam['total_questions']) : '10' ?>" required min="1">
                            </div>
                        </div>

                        <div class="form-group" style="display: flex; align-items: center; gap: 10px; margin-top: 10px;">
                            <input type="checkbox" id="is_active" name="is_active" value="1" <?= (!$edit_exam || $edit_exam['is_active'] == 1) ? 'checked' : '' ?> style="width: 18px; height: 18px; cursor: pointer;">
                            <label for="is_active" style="cursor: pointer; font-weight: 600;">Activate Exam Immediately</label>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">
                            <i class="fa-solid fa-floppy-disk"></i> <?= $edit_exam ? 'Save Changes' : 'Create Exam' ?>
                        </button>
                        <?php if ($edit_exam): ?>
                            <a href="exams.php" class="btn" style="width: 100%; margin-top: 8px; background: rgba(255,255,255,0.08); text-align: center; color: var(--text-muted); text-decoration: none;">Cancel Editing</a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- EXAMS LIST TABLE -->
                <div class="card">
                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 8px;">
                        <div class="card-title" style="margin-bottom: 0;">
                            <i class="fa-solid fa-list-check"></i> Configured Offline CBT Exams
                        </div>
                    </div>
                    <div class="card-desc">List of all active and inactive CBT exams configured locally on this server.</div>

                    <!-- BULK ACTIONS TOOLBAR -->
                    <div id="bulkActionsToolbar" style="display: none; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; padding: 12px 18px; background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.35); border-radius: 10px; margin-bottom: 16px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span style="display: inline-flex; align-items: center; justify-content: center; min-width: 26px; height: 26px; padding: 0 8px; border-radius: 999px; background: var(--danger); color: #fff; font-weight: 800; font-size: 12px;" id="selectedCountBadge">0</span>
                            <span style="font-weight: 600; font-size: 14px; color: var(--text-main);">
                                <span id="selectedCountText">0</span> exam(s) selected
                            </span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <button type="button" onclick="deselectAllExams()" class="btn" style="background: rgba(255, 255, 255, 0.08); color: var(--text-muted); font-size: 13px; padding: 7px 14px;">
                                <i class="fa-solid fa-xmark"></i> Clear Selection
                            </button>
                            <button type="button" onclick="confirmBulkDelete()" class="btn btn-danger" id="bulkDeleteBtn" style="font-size: 13px; padding: 7px 16px; font-weight: 700;">
                                <i class="fa-solid fa-trash-can"></i> Delete Selected (<span id="bulkDeleteCount">0</span>)
                            </button>
                        </div>
                    </div>

                    <form id="bulkExamsForm" method="POST" action="exams.php">
                        <input type="hidden" name="bulk_delete_exams" value="1">
                        <div class="table-responsive">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th style="width: 40px; text-align: center;">
                                            <input type="checkbox" id="selectAllExams" title="Select All Exams" style="cursor: pointer; width: 16px; height: 16px; accent-color: var(--primary);">
                                        </th>
                                        <th>Exam Title & Session</th>
                                        <th>Class Group</th>
                                        <th>Mark Distribution</th>
                                        <th>Duration</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $res_all = $conn->query("SELECT e.*, cg.classgroupname, s.sessionname, t.termname, md.markname as dist_markname 
                                        FROM tbl_cbt_exams e 
                                        LEFT JOIN tbl_cbt_class_groups cg ON e.classgroupid = cg.classgroupid 
                                        LEFT JOIN tbl_cbt_sessions s ON e.sessionid = s.sessionid 
                                        LEFT JOIN tbl_cbt_terms t ON e.termid = t.termid 
                                        LEFT JOIN tbl_cbt_mark_distributions md ON e.markdistid = md.markdistid 
                                            AND (e.sessionid = md.sessionid OR md.sessionid = '') 
                                            AND (e.termid = md.termid OR md.termid = '') 
                                            AND (e.classgroupid = md.classgroupid OR md.classgroupid = '')
                                        GROUP BY e.examid
                                        ORDER BY e.date_created DESC");
                                    if ($res_all && $res_all->num_rows > 0):
                                        while ($ex = $res_all->fetch_assoc()):
                                            $q_count = $conn->query("SELECT COUNT(*) as cnt FROM tbl_cbt_questions WHERE examid = '" . $ex['examid'] . "'")->fetch_assoc()['cnt'];
                                            $cg_display = !empty($ex['classgroupname']) ? $ex['classgroupname'] : 'All Arms';
                                            
                                            // Resolve true mark distribution name
                                            $md_display = !empty($ex['markname']) && $ex['markname'] !== 'CBT FINAL' 
                                                ? $ex['markname'] 
                                                : (!empty($ex['dist_markname']) ? $ex['dist_markname'] : (!empty($ex['markname']) ? $ex['markname'] : 'CBT FINAL'));

                                            if ($md_display === 'CBT FINAL' && !empty($ex['markdistid'])) {
                                                if (strpos($ex['markdistid'], '1CA') !== false) {
                                                    $md_display = 'FIRST CA';
                                                } else if (strpos($ex['markdistid'], '2CA') !== false) {
                                                    $md_display = 'SECOND CA';
                                                } else if (strpos($ex['markdistid'], 'EXAM') !== false) {
                                                    $md_display = 'EXAM';
                                                }
                                            }

                                            $sess_display = !empty($ex['sessionname']) ? $ex['sessionname'] : '2025/2026 Session';
                                            $term_display = !empty($ex['termname']) ? $ex['termname'] : 'First Term';
                                    ?>
                                        <tr id="row-<?= htmlspecialchars($ex['examid']) ?>" style="transition: background-color 0.15s ease;">
                                            <td style="text-align: center;">
                                                <input type="checkbox" name="selected_exams[]" value="<?= htmlspecialchars($ex['examid']) ?>" class="exam-checkbox" onchange="handleRowCheckboxChange(this)" style="cursor: pointer; width: 16px; height: 16px; accent-color: var(--primary);">
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($ex['title']) ?></strong><br>
                                                <small style="color: var(--text-muted);"><?= htmlspecialchars($ex['subjectname']) ?></small>
                                                <small style="color: var(--accent); font-size: 11px; font-weight: 600; display: block; margin-top: 2px;">
                                                    <i class="fa-solid fa-calendar"></i> <?= htmlspecialchars($sess_display) ?> &nbsp;|&nbsp; 
                                                    <i class="fa-solid fa-clock-rotate-left"></i> <?= htmlspecialchars($term_display) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="badge-offline" style="background: rgba(99, 102, 241, 0.15); color: var(--primary);"><i class="fa-solid fa-users"></i> <?= htmlspecialchars($cg_display) ?></span>
                                            </td>
                                            <td>
                                                <span style="font-weight: 700; font-size: 13px; color: var(--accent);"><i class="fa-solid fa-award"></i> <?= htmlspecialchars($md_display) ?></span>
                                            </td>
                                            <td>
                                                <strong><?= $ex['duration_minutes'] ?> Mins</strong> <small style="color: var(--text-muted);">(<?= $q_count ?> Qs)</small>
                                                <div style="font-size: 11px; color: var(--text-muted); margin-top: 4px; line-height: 1.4;">
                                                    <i class="fa-solid fa-calendar-day" style="color: var(--primary);"></i> <?= !empty($ex['exam_date']) ? date('M d, Y', strtotime($ex['exam_date'])) : 'Not set' ?><br>
                                                    <i class="fa-solid fa-clock" style="color: var(--accent);"></i> <?= !empty($ex['start_time']) ? date('h:i A', strtotime($ex['start_time'])) : '08:00 AM' ?> - <?= !empty($ex['end_time']) ? date('h:i A', strtotime($ex['end_time'])) : '05:00 PM' ?>
                                                </div>
                                            </td>
                                            <td>
                                                <a href="exams.php?toggle_active=<?= $ex['examid'] ?>" style="text-decoration: none;">
                                                    <?php if ($ex['is_active'] == 1): ?>
                                                        <span class="badge-offline" style="display: inline-flex;"><i class="fa-solid fa-circle-check"></i> Active</span>
                                                    <?php else: ?>
                                                        <span style="color: var(--text-muted); font-size: 13px; font-weight: 600;"><i class="fa-solid fa-circle-pause"></i> Inactive</span>
                                                    <?php endif; ?>
                                                </a>
                                                <?php
                                                $now_ts = time();
                                                $s_d = !empty($ex['exam_date']) ? $ex['exam_date'] : date('Y-m-d');
                                                $e_d = !empty($ex['end_date']) ? $ex['end_date'] : $s_d;
                                                $s_t = !empty($ex['start_time']) ? $ex['start_time'] : '00:00:00';
                                                $e_t = !empty($ex['end_time']) ? $ex['end_time'] : '23:59:59';
                                                $s_full = strtotime($s_d . ' ' . $s_t);
                                                $e_full = strtotime($e_d . ' ' . $e_t);
                                                if ($now_ts < $s_full): ?>
                                                    <span style="color: #60a5fa; font-size: 11px; font-weight: 700; display: block; margin-top: 3px;"><i class="fa-solid fa-calendar-clock"></i> Upcoming</span>
                                                <?php elseif ($now_ts > $e_full): ?>
                                                    <span style="color: #f87171; font-size: 11px; font-weight: 700; display: block; margin-top: 3px;"><i class="fa-solid fa-clock-rotate-left"></i> Past</span>
                                                <?php else: ?>
                                                    <span style="color: var(--success); font-size: 11px; font-weight: 700; display: block; margin-top: 3px;"><i class="fa-solid fa-bolt"></i> Live Window</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="display: flex; gap: 6px;">
                                                    <a href="questions.php?examid=<?= $ex['examid'] ?>" class="btn" style="padding: 6px 10px; font-size: 12px; background: rgba(99, 102, 241, 0.2); color: var(--primary); text-decoration: none;" title="Manage Questions">
                                                        <i class="fa-solid fa-circle-question"></i> Questions
                                                    </a>
                                                    <a href="exams.php?edit=<?= $ex['examid'] ?>" class="btn" style="padding: 6px 10px; font-size: 12px; background: rgba(255, 255, 255, 0.1); color: var(--text-main); text-decoration: none;" title="Edit Exam">
                                                        <i class="fa-solid fa-pen"></i>
                                                    </a>
                                                    <a href="exams.php?delete_exam=<?= $ex['examid'] ?>" onclick="return confirm('Are you sure you want to delete this exam and all its questions?');" class="btn btn-danger" style="padding: 6px 10px; font-size: 12px; text-decoration: none;" title="Delete Exam">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </a>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php 
                                        endwhile;
                                    else:
                                    ?>
                                        <tr>
                                            <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 32px;">
                                                No CBT exams found locally. Use the form on the left to create an exam or perform a Downstream Sync.
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                </div>
            </div>
        </main>
    </div>

    <!-- DYNAMIC MARK DISTRIBUTION FILTER JS -->
    <script>
        const allMarkDistributions = <?= json_encode($mark_distributions) ?>;
        const currentEditMarkDistId = <?= json_encode($edit_exam ? $edit_exam['markdistid'] : '') ?>;
        const currentEditTermId = <?= json_encode($edit_exam ? $edit_exam['termid'] : '') ?>;

        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>"']/g, function(m) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
            });
        }

        function updateSubjectName(selectElem) {
            const selectedOpt = selectElem.options[selectElem.selectedIndex];
            const name = selectedOpt.getAttribute('data-name') || selectedOpt.text;
            document.getElementById('subjectname').value = name;
        }

        function updateMarkDistName(selectElem) {
            const selectedOpt = selectElem.options[selectElem.selectedIndex];
            const hiddenInput = document.getElementById('markname');
            if (selectedOpt && selectElem.selectedIndex > 0) {
                const name = selectedOpt.getAttribute('data-name') || selectedOpt.text.replace(/\s*\(\d+\s*Marks\)/i, '').trim();
                if (hiddenInput) {
                    hiddenInput.value = name;
                }
            } else if (hiddenInput) {
                hiddenInput.value = '';
            }
        }

        // Fetch Eligible Terms based on selected Target Class Group and Academic Session
        function fetchEligibleTerms(selectedTermToRestore = null) {
            const classgroupElem = document.getElementById('classgroupid');
            const sessionElem = document.getElementById('sessionid');
            const termElem = document.getElementById('termid');
            const badge = document.getElementById('termCountBadge');

            const classgroupid = classgroupElem ? classgroupElem.value.trim() : '';
            const sessionid = sessionElem ? sessionElem.value.trim() : '';

            if (!classgroupid && !sessionid) {
                termElem.innerHTML = '<option value="">-- Select Class Group & Session First --</option>';
                if (badge) badge.textContent = '';
                updateMarkDistributions();
                return;
            }

            if (!classgroupid) {
                termElem.innerHTML = '<option value="">-- Select Target Class Group First --</option>';
                if (badge) badge.textContent = '';
                updateMarkDistributions();
                return;
            }

            if (!sessionid) {
                termElem.innerHTML = '<option value="">-- Select Academic Session First --</option>';
                if (badge) badge.textContent = '';
                updateMarkDistributions();
                return;
            }

            // Determine which term to select after loading
            const currentTerm = (selectedTermToRestore !== null && selectedTermToRestore !== undefined) 
                ? selectedTermToRestore 
                : (termElem ? termElem.value.trim() : '');

            termElem.innerHTML = '<option value="">-- Loading Eligible Terms... --</option>';
            if (badge) badge.textContent = '(Fetching...)';

            fetch(`exams.php?ajax=get_eligible_terms&classgroupid=${encodeURIComponent(classgroupid)}&sessionid=${encodeURIComponent(sessionid)}`)
                .then(res => res.json())
                .then(terms => {
                    let optionsHtml = '';
                    if (Array.isArray(terms) && terms.length > 0) {
                        optionsHtml = '<option value="">-- Select Academic Term --</option>';
                        let matched = false;
                        terms.forEach(t => {
                            const isSelected = (currentTerm && String(t.termid).trim() === String(currentTerm).trim());
                            if (isSelected) matched = true;
                            optionsHtml += `<option value="${escapeHtml(t.termid)}" ${isSelected ? 'selected' : ''}>${escapeHtml(t.termname)}</option>`;
                        });

                        // If only 1 eligible term, auto-select it!
                        if (terms.length === 1 && !matched) {
                            optionsHtml = `<option value="${escapeHtml(terms[0].termid)}" selected>${escapeHtml(terms[0].termname)}</option>`;
                            matched = true;
                        }

                        termElem.innerHTML = optionsHtml;
                        if (badge) badge.textContent = `(${terms.length} eligible term${terms.length === 1 ? '' : 's'})`;
                    } else {
                        termElem.innerHTML = '<option value="">-- No Eligible Terms Found for Selected Session --</option>';
                        if (badge) badge.textContent = '(0 eligible)';
                    }

                    // Update Mark Distributions based on the newly updated term selection
                    updateMarkDistributions();
                })
                .catch(err => {
                    console.error('Failed to load eligible terms:', err);
                    termElem.innerHTML = '<option value="">-- Failed to Load Terms --</option>';
                    if (badge) badge.textContent = '';
                    updateMarkDistributions();
                });
        }

        function updateMarkDistributions() {
            const sessionElem = document.getElementById('sessionid');
            const termElem = document.getElementById('termid');
            const classgroupElem = document.getElementById('classgroupid');

            const sessionid = sessionElem ? sessionElem.value.trim() : '';
            const termid = termElem ? termElem.value.trim() : '';
            const classgroupid = classgroupElem ? classgroupElem.value.trim() : '';

            const markDistSelect = document.getElementById('markdistid');
            const hiddenMarkName = document.getElementById('markname');

            if (!classgroupid) {
                markDistSelect.innerHTML = '<option value="">-- Select Target Class Group First --</option>';
                if (hiddenMarkName) hiddenMarkName.value = '';
                return;
            }

            if (!sessionid || !termid) {
                markDistSelect.innerHTML = '<option value="">-- Select Academic Session & Term First --</option>';
                if (hiddenMarkName) hiddenMarkName.value = '';
                return;
            }

            markDistSelect.innerHTML = '<option value="">-- Select Mark Distribution --</option>';

            // Filter mark distributions strictly by selected SessionID, TermID, and ClassGroupID
            const filtered = allMarkDistributions.filter(md => {
                return (String(md.classgroupid).trim() === classgroupid) && 
                       (String(md.sessionid).trim() === sessionid) && 
                       (String(md.termid).trim() === termid);
            });

            // Deduplicate for the dropdown display
            const seen = {};
            const uniqueDist = [];
            filtered.forEach(md => {
                const key = md.markdistid;
                if (!seen[key]) {
                    seen[key] = true;
                    uniqueDist.push(md);
                }
            });

            if (uniqueDist.length > 0) {
                uniqueDist.forEach(md => {
                    const opt = document.createElement('option');
                    opt.value = md.markdistid;
                    opt.setAttribute('data-name', md.markname);
                    opt.textContent = `${md.markname} (${md.markscore} Marks)`;
                    if (md.markdistid === currentEditMarkDistId) {
                        opt.selected = true;
                        if (hiddenMarkName) hiddenMarkName.value = md.markname;
                    }
                    markDistSelect.appendChild(opt);
                });

                // Auto-select if only 1 mark distribution available and none selected yet
                if (uniqueDist.length === 1 && markDistSelect.selectedIndex <= 0) {
                    markDistSelect.selectedIndex = 1;
                    if (hiddenMarkName) hiddenMarkName.value = uniqueDist[0].markname;
                }
            } else {
                markDistSelect.innerHTML = '<option value="">-- No Mark Distribution Found for Selected Session & Term --</option>';
                if (hiddenMarkName) hiddenMarkName.value = '';
            }

            if (markDistSelect.selectedIndex > 0) {
                updateMarkDistName(markDistSelect);
            }
        }

        // BULK EXAMS SELECTION & DELETE LOGIC
        function updateBulkToolbar() {
            const checkboxes = document.querySelectorAll('.exam-checkbox');
            const checked = document.querySelectorAll('.exam-checkbox:checked');
            const toolbar = document.getElementById('bulkActionsToolbar');
            const selectAll = document.getElementById('selectAllExams');
            const count = checked.length;

            if (toolbar) {
                toolbar.style.display = count > 0 ? 'flex' : 'none';
            }

            const countText = document.getElementById('selectedCountText');
            const countBadge = document.getElementById('selectedCountBadge');
            const btnCount = document.getElementById('bulkDeleteCount');

            if (countText) countText.textContent = count;
            if (countBadge) countBadge.textContent = count;
            if (btnCount) btnCount.textContent = count;

            if (selectAll) {
                if (checkboxes.length > 0 && checked.length === checkboxes.length) {
                    selectAll.checked = true;
                    selectAll.indeterminate = false;
                } else if (checked.length > 0) {
                    selectAll.checked = false;
                    selectAll.indeterminate = true;
                } else {
                    selectAll.checked = false;
                    selectAll.indeterminate = false;
                }
            }

            // Highlight selected rows
            checkboxes.forEach(cb => {
                const tr = cb.closest('tr');
                if (tr) {
                    if (cb.checked) {
                        tr.style.backgroundColor = 'rgba(99, 102, 241, 0.12)';
                    } else {
                        tr.style.backgroundColor = '';
                    }
                }
            });
        }

        function handleRowCheckboxChange(elem) {
            updateBulkToolbar();
        }

        function deselectAllExams() {
            const checkboxes = document.querySelectorAll('.exam-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = false;
            });
            const selectAll = document.getElementById('selectAllExams');
            if (selectAll) {
                selectAll.checked = false;
                selectAll.indeterminate = false;
            }
            updateBulkToolbar();
        }

        function confirmBulkDelete() {
            const checked = document.querySelectorAll('.exam-checkbox:checked');
            const count = checked.length;
            if (count === 0) {
                alert('Please select at least one exam to delete.');
                return;
            }

            const confirmMsg = "Are you sure you want to permanently delete the " + count + " selected exam(s)?\n\n" +
                "WARNING: All questions, student test responses, and score results associated with these exams will also be permanently deleted.\n\n" +
                "This action cannot be undone.";

            if (confirm(confirmMsg)) {
                const form = document.getElementById('bulkExamsForm');
                if (form) {
                    form.submit();
                }
            }
        }

        function syncEndDate(val) {
            const endInput = document.getElementById('end_date');
            if (endInput && !endInput.dataset.userModified) {
                endInput.value = val;
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const endInput = document.getElementById('end_date');
            if (endInput) {
                endInput.addEventListener('input', function() {
                    this.dataset.userModified = '1';
                });
            }

            if (currentEditTermId) {
                // In edit mode: term is preloaded in HTML, ensure mark distributions are populated and selected
                updateMarkDistributions();
            } else {
                const cg = document.getElementById('classgroupid');
                const sess = document.getElementById('sessionid');
                if (cg && sess && cg.value && sess.value) {
                    fetchEligibleTerms();
                } else {
                    updateMarkDistributions();
                }
            }
            const subSelect = document.getElementById('subjectid');
            if (subSelect && subSelect.selectedIndex > 0) {
                updateSubjectName(subSelect);
            }

            const selectAllElem = document.getElementById('selectAllExams');
            if (selectAllElem) {
                selectAllElem.addEventListener('change', function() {
                    const checkboxes = document.querySelectorAll('.exam-checkbox');
                    checkboxes.forEach(cb => {
                        cb.checked = selectAllElem.checked;
                    });
                    updateBulkToolbar();
                });
            }
        });
    </script>
</body>
</html>