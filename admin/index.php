<?php
require_once __DIR__ . '/../config/config.php';

require_admin_login();

$conn = get_db_connection();
$school = get_school_config();
$admin = $_SESSION['cbt_admin'];

$students_count = $conn->query("SELECT COUNT(*) as cnt FROM tbl_cbt_students")->fetch_assoc()['cnt'];
$exams_count = $conn->query("SELECT COUNT(*) as cnt FROM tbl_cbt_exams")->fetch_assoc()['cnt'];
$pending_results_count = $conn->query("SELECT COUNT(*) as cnt FROM tbl_cbt_results WHERE sync_status = 0")->fetch_assoc()['cnt'];

// --- REPORT FILTER PARAMETERS ---
$selected_class   = isset($_GET['classid']) ? trim($_GET['classid']) : '';
$selected_session = isset($_GET['sessionid']) ? trim($_GET['sessionid']) : '';
$selected_term    = isset($_GET['termid']) ? trim($_GET['termid']) : '';
$selected_subject = isset($_GET['subjectid']) ? trim($_GET['subjectid']) : '';

// 1. Fetch Classes for filter dropdown
$report_classes = [];
$res_c = $conn->query("SELECT DISTINCT classgroupid, classgroupname FROM tbl_cbt_class_groups ORDER BY classgrouplevel ASC, classgroupname ASC");
if ($res_c && $res_c->num_rows > 0) {
    while ($c = $res_c->fetch_assoc()) $report_classes[] = ['value' => $c['classgroupid'], 'label' => $c['classgroupname']];
}
$res_st_c = $conn->query("SELECT DISTINCT classname FROM tbl_cbt_students WHERE classname IS NOT NULL AND classname != '' ORDER BY classname ASC");
if ($res_st_c) {
    while ($c = $res_st_c->fetch_assoc()) {
        $exists = false;
        foreach ($report_classes as $rc) {
            if ($rc['label'] === $c['classname']) { $exists = true; break; }
        }
        if (!$exists) $report_classes[] = ['value' => $c['classname'], 'label' => $c['classname']];
    }
}

// 2. Fetch Sessions for filter dropdown
$report_sessions = [];
$res_s = $conn->query("SELECT sessionid, sessionname FROM tbl_cbt_sessions ORDER BY sessionname DESC");
if ($res_s && $res_s->num_rows > 0) {
    while ($s = $res_s->fetch_assoc()) $report_sessions[] = ['value' => $s['sessionid'], 'label' => $s['sessionname']];
}
if (empty($report_sessions)) {
    $report_sessions = [
        ['value' => '68bab4c928f06277748994016768200', 'label' => '2025/2026 Academic Session'],
        ['value' => '68bab4c928f06277748994016768201', 'label' => '2026/2027 Academic Session']
    ];
}

// 3. Fetch Terms for filter dropdown
$report_terms = [];
$res_t = $conn->query("SELECT termid, termname FROM tbl_cbt_terms ORDER BY termposition ASC, termname ASC");
if ($res_t && $res_t->num_rows > 0) {
    while ($t = $res_t->fetch_assoc()) $report_terms[] = ['value' => $t['termid'], 'label' => $t['termname']];
}
if (empty($report_terms)) {
    $report_terms = [
        ['value' => '5613a794ef5ac183124439098039600', 'label' => 'First Term'],
        ['value' => '5613a794ef5ac183124439098039601', 'label' => 'Second Term'],
        ['value' => '5613a794ef5ac183124439098039602', 'label' => 'Third Term']
    ];
}

// 4. Fetch Subjects for filter dropdown
$report_subjects = [];
$res_sub = $conn->query("SELECT DISTINCT subjectid, subjectname FROM tbl_cbt_subjects ORDER BY subjectname ASC");
if ($res_sub && $res_sub->num_rows > 0) {
    while ($sb = $res_sub->fetch_assoc()) $report_subjects[] = ['value' => $sb['subjectid'], 'label' => $sb['subjectname']];
}
$res_ex_sub = $conn->query("SELECT DISTINCT subjectname FROM tbl_cbt_exams WHERE subjectname IS NOT NULL AND subjectname != '' ORDER BY subjectname ASC");
if ($res_ex_sub) {
    while ($sb = $res_ex_sub->fetch_assoc()) {
        $exists = false;
        foreach ($report_subjects as $rsb) {
            if ($rsb['label'] === $sb['subjectname']) { $exists = true; break; }
        }
        if (!$exists) $report_subjects[] = ['value' => $sb['subjectname'], 'label' => $sb['subjectname']];
    }
}

// --- BUILD REPORT QUERY ---
$where_clauses = ["1=1"];
if (!empty($selected_class)) {
    $esc_class = $conn->real_escape_string($selected_class);
    $where_clauses[] = "(r.classid = '$esc_class' OR r.classgroupid = '$esc_class' OR e.classgroupid = '$esc_class' OR e.classid = '$esc_class' OR cg.classgroupname = '$esc_class' OR st.classname = '$esc_class')";
}
if (!empty($selected_session)) {
    $esc_sess = $conn->real_escape_string($selected_session);
    $where_clauses[] = "(e.sessionid = '$esc_sess' OR s.sessionname LIKE '%$esc_sess%')";
}
if (!empty($selected_term)) {
    $esc_term = $conn->real_escape_string($selected_term);
    $where_clauses[] = "(e.termid = '$esc_term' OR t.termname LIKE '%$esc_term%')";
}
if (!empty($selected_subject)) {
    $esc_sub = $conn->real_escape_string($selected_subject);
    $where_clauses[] = "(r.subjectid = '$esc_sub' OR e.subjectid = '$esc_sub' OR e.subjectname = '$esc_sub' OR sb.subjectname = '$esc_sub')";
}

$where_sql = implode(' AND ', $where_clauses);

$sql_report = "SELECT r.*, e.title as exam_title_full, e.passmark, e.subjectname as exam_subject, 
                      cg.classgroupname, s.sessionname, t.termname, st.classname as student_class
               FROM tbl_cbt_results r
               LEFT JOIN tbl_cbt_exams e ON r.examid = e.examid
               LEFT JOIN tbl_cbt_students st ON r.studentid = st.studentid
               LEFT JOIN tbl_cbt_class_groups cg ON (r.classgroupid = cg.classgroupid OR e.classgroupid = cg.classgroupid)
               LEFT JOIN tbl_cbt_sessions s ON e.sessionid = s.sessionid
               LEFT JOIN tbl_cbt_terms t ON e.termid = t.termid
               LEFT JOIN tbl_cbt_subjects sb ON (r.subjectid = sb.subjectid OR e.subjectid = sb.subjectid)
               WHERE $where_sql
               ORDER BY r.date_taken DESC";

$res_report = $conn->query($sql_report);
$report_results = [];
$total_score_sum = 0;
$max_score = 0;
$passed_count = 0;

if ($res_report && $res_report->num_rows > 0) {
    while ($row = $res_report->fetch_assoc()) {
        $report_results[] = $row;
        $total_score_sum += floatval($row['score']);
        if (floatval($row['score']) > $max_score) {
            $max_score = floatval($row['score']);
        }
        $passmark = !empty($row['passmark']) ? floatval($row['passmark']) : 50;
        if (floatval($row['score']) >= $passmark) {
            $passed_count++;
        }
    }
}

$filtered_count = count($report_results);
$avg_score = $filtered_count > 0 ? round($total_score_sum / $filtered_count, 2) : 0;
$pass_rate = $filtered_count > 0 ? round(($passed_count / $filtered_count) * 100, 1) : 0;
$has_report_filter = (!empty($selected_class) || !empty($selected_session) || !empty($selected_term) || !empty($selected_subject));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard & Score Reports | Offline CBT</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @media print {
            .sidebar, .no-print, .btn, form { display: none !important; }
            .app-layout { display: block !important; }
            .main-content { padding: 0 !important; margin: 0 !important; }
            .card { border: none !important; box-shadow: none !important; background: #ffffff !important; color: #000000 !important; }
            .data-table th, .data-table td { color: #000000 !important; border-bottom: 1px solid #ccc !important; }
            .print-header { display: block !important; text-align: center; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; }
        }
        .print-header { display: none; }
    </style>
</head>
<body style="display: block; padding: 0;">
    <div class="app-layout">
        <!-- SIDEBAR NAVIGATION -->
        <aside class="sidebar no-print">
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
                    <a href="index.php" class="sidebar-link active">
                        <i class="fa-solid fa-gauge-high"></i> Dashboard & Reports
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
                    <a href="reset.php" class="sidebar-link">
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
            <!-- PRINTABLE HEADER -->
            <div class="print-header">
                <h1 style="font-size: 22px; font-weight: 800; text-transform: uppercase;"><?= htmlspecialchars($school['schoolname']) ?></h1>
                <h2 style="font-size: 16px; font-weight: 700; margin-top: 4px;">OFFICIAL STUDENT CBT SCORE PERFORMANCE REPORT</h2>
                <div style="font-size: 12px; color: #555; margin-top: 4px;">
                    Generated on <?= date('F d, Y h:i A') ?> &nbsp;|&nbsp; Filtered by Class, Session, Term & Subject
                </div>
            </div>

            <div class="card no-print" style="margin-bottom: 28px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h1 class="card-title" style="font-size: 24px;"><?= htmlspecialchars($school['schoolname']) ?></h1>
                    <div style="font-size: 14px; color: var(--text-muted); margin-top: 4px;">
                        School ID: <strong><?= htmlspecialchars($school['schoolid']) ?></strong> &nbsp;|&nbsp; 
                        Online Server: <strong><?= htmlspecialchars($school['online_url']) ?></strong>
                    </div>
                </div>
                <div>
                    <a href="../api/sync_pull.php" class="btn btn-primary" style="text-decoration: none;">
                        <i class="fa-solid fa-arrows-rotate"></i> Sync Data Downstream
                    </a>
                </div>
            </div>

            <!-- STATS OVERVIEW GRID -->
            <div class="grid-3 no-print" style="margin-bottom: 28px;">
                <div class="stat-box">
                    <div class="stat-lbl"><i class="fa-solid fa-user-graduate"></i> Synced Students</div>
                    <div class="stat-val"><?= number_format($students_count) ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-lbl"><i class="fa-solid fa-file-pen"></i> Available CBT Exams</div>
                    <div class="stat-val"><?= number_format($exams_count) ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-lbl"><i class="fa-solid fa-cloud-arrow-up"></i> Pending Score Push</div>
                    <div class="stat-val" style="color: <?= $pending_results_count > 0 ? 'var(--accent)' : 'var(--success)' ?>;">
                        <?= number_format($pending_results_count) ?>
                    </div>
                </div>
            </div>

            <!-- SCORE PERFORMANCE REPORT & FILTER SECTION -->
            <div class="card" style="margin-bottom: 28px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 20px;" class="no-print">
                    <div>
                        <div class="card-title"><i class="fa-solid fa-chart-pie" style="color: var(--primary);"></i> Student CBT Score Performance Report</div>
                        <div class="card-desc">Filter student exam scores by Class, Academic Session, Academic Term, and Subject.</div>
                    </div>
                    <?php if (!empty($report_results)): ?>
                        <button onclick="window.print()" class="btn btn-primary" style="background: var(--success); border-color: var(--success); font-weight: 700;">
                            <i class="fa-solid fa-print"></i> Print Official Report
                        </button>
                    <?php endif; ?>
                </div>

                <!-- FILTER FORM -->
                <form method="GET" action="index.php" class="no-print" style="margin-bottom: 24px; background: rgba(15, 23, 42, 0.6); padding: 20px; border-radius: 12px; border: 1px solid var(--border-color);">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                        <!-- FILTER BY CLASS -->
                        <div>
                            <label class="form-label" for="classid"><i class="fa-solid fa-users"></i> Class / Arm</label>
                            <select id="classid" name="classid" class="form-control" style="background: rgba(15, 23, 42, 0.9); font-weight: 600;">
                                <option value="">-- All Classes --</option>
                                <?php foreach ($report_classes as $c): ?>
                                    <option value="<?= htmlspecialchars($c['value']) ?>" <?= ($selected_class === $c['value'] || $selected_class === $c['label']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- FILTER BY ACADEMIC SESSION -->
                        <div>
                            <label class="form-label" for="sessionid"><i class="fa-solid fa-calendar-days"></i> Academic Session</label>
                            <select id="sessionid" name="sessionid" class="form-control" style="background: rgba(15, 23, 42, 0.9); font-weight: 600;">
                                <option value="">-- All Sessions --</option>
                                <?php foreach ($report_sessions as $s): ?>
                                    <option value="<?= htmlspecialchars($s['value']) ?>" <?= ($selected_session === $s['value'] || $selected_session === $s['label']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- FILTER BY ACADEMIC TERM -->
                        <div>
                            <label class="form-label" for="termid"><i class="fa-solid fa-clock-rotate-left"></i> Academic Term</label>
                            <select id="termid" name="termid" class="form-control" style="background: rgba(15, 23, 42, 0.9); font-weight: 600;">
                                <option value="">-- All Terms --</option>
                                <?php foreach ($report_terms as $t): ?>
                                    <option value="<?= htmlspecialchars($t['value']) ?>" <?= ($selected_term === $t['value'] || $selected_term === $t['label']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($t['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- FILTER BY SUBJECT -->
                        <div>
                            <label class="form-label" for="subjectid"><i class="fa-solid fa-book"></i> Subject</label>
                            <select id="subjectid" name="subjectid" class="form-control" style="background: rgba(15, 23, 42, 0.9); font-weight: 600;">
                                <option value="">-- All Subjects --</option>
                                <?php foreach ($report_subjects as $sb): ?>
                                    <option value="<?= htmlspecialchars($sb['value']) ?>" <?= ($selected_subject === $sb['value'] || $selected_subject === $sb['label']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sb['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 20px; align-items: center;">
                        <button type="submit" class="btn btn-primary" style="padding: 10px 24px; font-weight: 700;">
                            <i class="fa-solid fa-sliders"></i> Generate Score Report
                        </button>
                        <?php if ($has_report_filter): ?>
                            <a href="index.php" class="btn" style="background: rgba(255,255,255,0.08); color: var(--text-muted); text-decoration: none; padding: 10px 18px;">
                                <i class="fa-solid fa-rotate-left"></i> Reset Filters
                            </a>
                        <?php endif; ?>
                    </div>
                </form>

                <!-- REPORT SUMMARY STATISTICS STATS CARDS -->
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px;">
                    <div class="stat-box" style="padding: 16px;">
                        <div class="stat-lbl"><i class="fa-solid fa-user-check"></i> Filtered Candidates</div>
                        <div class="stat-val" style="font-size: 24px;"><?= number_format($filtered_count) ?></div>
                    </div>

                    <div class="stat-box" style="padding: 16px;">
                        <div class="stat-lbl"><i class="fa-solid fa-calculator"></i> Average Score</div>
                        <div class="stat-val" style="font-size: 24px; color: var(--primary);"><?= number_format($avg_score, 1) ?>%</div>
                    </div>

                    <div class="stat-box" style="padding: 16px;">
                        <div class="stat-lbl"><i class="fa-solid fa-trophy"></i> Highest Score</div>
                        <div class="stat-val" style="font-size: 24px; color: var(--success);"><?= number_format($max_score, 1) ?>%</div>
                    </div>

                    <div class="stat-box" style="padding: 16px;">
                        <div class="stat-lbl"><i class="fa-solid fa-award"></i> Candidate Pass Rate</div>
                        <div class="stat-val" style="font-size: 24px; color: <?= $pass_rate >= 50 ? 'var(--success)' : 'var(--danger)' ?>;">
                            <?= number_format($pass_rate, 1) ?>% <span style="font-size: 13px; font-weight: 600; color: var(--text-muted);"> (<?= $passed_count ?> Passed)</span>
                        </div>
                    </div>
                </div>

                <!-- SCORES REPORT TABLE -->
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Candidate Name</th>
                                <th>Reg Number</th>
                                <th>Class</th>
                                <th>Subject & Exam Title</th>
                                <th>Session & Term</th>
                                <th>Score (%)</th>
                                <th>Date Taken</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($report_results)): ?>
                                <?php foreach ($report_results as $r): 
                                    $class_disp = !empty($r['student_class']) ? $r['student_class'] : (!empty($r['classgroupname']) ? $r['classgroupname'] : 'N/A');
                                    $sub_disp = !empty($r['sub_name']) ? $r['sub_name'] : (!empty($r['exam_subject']) ? $r['exam_subject'] : 'N/A');
                                    $sess_disp = !empty($r['sessionname']) ? $r['sessionname'] : '2025/2026 Session';
                                    $term_disp = !empty($r['termname']) ? $r['termname'] : 'First Term';
                                    $passmark = !empty($r['passmark']) ? floatval($r['passmark']) : 50;
                                    $is_passed = floatval($r['score']) >= $passmark;
                                ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($r['student_name']) ?></strong></td>
                                        <td><span style="font-family: monospace; font-weight: 700; color: var(--accent);"><?= htmlspecialchars($r['regnumber']) ?></span></td>
                                        <td><span class="badge-offline" style="background: rgba(99, 102, 241, 0.15); color: var(--primary);"><i class="fa-solid fa-users"></i> <?= htmlspecialchars($class_disp) ?></span></td>
                                        <td>
                                            <strong><?= htmlspecialchars($sub_disp) ?></strong><br>
                                            <small style="color: var(--text-muted);"><?= htmlspecialchars(!empty($r['exam_title_full']) ? $r['exam_title_full'] : $r['exam_title']) ?></small>
                                        </td>
                                        <td>
                                            <small style="font-weight: 600; color: var(--text-muted); display: block;">
                                                <i class="fa-solid fa-calendar"></i> <?= htmlspecialchars($sess_disp) ?><br>
                                                <i class="fa-solid fa-clock-rotate-left"></i> <?= htmlspecialchars($term_disp) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span style="font-weight: 800; font-size: 16px; color: <?= $is_passed ? 'var(--success)' : 'var(--danger)' ?>;">
                                                <?= number_format($r['score'], 1) ?>%
                                            </span>
                                            <div style="font-size: 10px; font-weight: 700; margin-top: 2px;">
                                                <?php if ($is_passed): ?>
                                                    <span style="color: var(--success);"><i class="fa-solid fa-check"></i> PASSED</span>
                                                <?php else: ?>
                                                    <span style="color: var(--danger);"><i class="fa-solid fa-xmark"></i> FAILED</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td><?= !empty($r['date_taken']) ? date('d M Y, h:i A', strtotime($r['date_taken'])) : (!empty($r['submitted_at']) ? date('d M Y, h:i A', strtotime($r['submitted_at'])) : 'N/A') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 48px;">
                                        <i class="fa-solid fa-chart-line" style="font-size: 40px; margin-bottom: 12px; color: var(--text-muted);"></i>
                                        <div style="font-size: 16px; font-weight: 700;">No Exam Scores Match Selected Report Filter</div>
                                        <p style="margin-top: 6px; font-size: 13px;">Try selecting a different Class, Session, Term, or Subject from the filter bar above.</p>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</body>
</html>