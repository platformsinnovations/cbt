<?php
require_once __DIR__ . '/../config/config.php';

require_admin_login();

$conn = get_db_connection();
$school = get_school_config();
$admin = $_SESSION['cbt_admin'];

if (!function_exists('get_mark_distributions_for_class_session_term')) {
    function get_mark_distributions_for_class_session_term($conn, $classid, $sessionid, $termid) {
        $items = [];
        if (empty($classid) || empty($sessionid) || empty($termid)) {
            return $items;
        }

        $resolved_cg = $classid;
        // Check if classid is a classgroupid, classname, or classid
        $cg_chk = $conn->query("SELECT classgroupid FROM tbl_cbt_class_groups WHERE classgroupid = '" . $conn->real_escape_string($classid) . "' OR classgroupname = '" . $conn->real_escape_string($classid) . "' LIMIT 1");
        if ($cg_chk && $cg_chk->num_rows > 0) {
            $resolved_cg = $cg_chk->fetch_assoc()['classgroupid'];
        } else {
            $cg_chk2 = $conn->query("SELECT classgroupid FROM tbl_cbt_classes WHERE classid = '" . $conn->real_escape_string($classid) . "' OR classname = '" . $conn->real_escape_string($classid) . "' LIMIT 1");
            if ($cg_chk2 && $cg_chk2->num_rows > 0) {
                $resolved_cg = $cg_chk2->fetch_assoc()['classgroupid'];
            } else {
                $cg_chk3 = $conn->query("SELECT classgroupid FROM tbl_cbt_students WHERE classname = '" . $conn->real_escape_string($classid) . "' AND classgroupid != '' LIMIT 1");
                if ($cg_chk3 && $cg_chk3->num_rows > 0) {
                    $resolved_cg = $cg_chk3->fetch_assoc()['classgroupid'];
                }
            }
        }

        $esc_cg = $conn->real_escape_string($resolved_cg);
        $esc_c  = $conn->real_escape_string($classid);
        $esc_s  = $conn->real_escape_string($sessionid);
        $esc_t  = $conn->real_escape_string($termid);

        // 1. Fetch from tbl_cbt_mark_distributions
        $sql_md = "SELECT DISTINCT markdistid, markname, markscore 
                   FROM tbl_cbt_mark_distributions 
                   WHERE (classgroupid = '$esc_cg' OR classgroupid = '$esc_c')
                     AND sessionid = '$esc_s'
                     AND termid = '$esc_t'
                   ORDER BY markscore ASC, markname ASC";
        $res_md = $conn->query($sql_md);
        if ($res_md && $res_md->num_rows > 0) {
            while ($m = $res_md->fetch_assoc()) {
                $score_suffix = (!empty($m['markscore']) && intval($m['markscore']) > 0) ? ' (' . intval($m['markscore']) . ' marks)' : '';
                $items[] = [
                    'value' => $m['markdistid'],
                    'label' => $m['markname'] . $score_suffix,
                    'name'  => $m['markname']
                ];
            }
        }

        // 2. Fetch from tbl_cbt_exams for this class, session, and term
        $sql_ex = "SELECT DISTINCT markdistid, markname 
                   FROM tbl_cbt_exams 
                   WHERE (classgroupid = '$esc_cg' OR classid = '$esc_c' OR classgroupid = '$esc_c')
                     AND sessionid = '$esc_s'
                     AND termid = '$esc_t'
                     AND markdistid IS NOT NULL AND markdistid != ''
                   ORDER BY markname ASC";
        $res_ex = $conn->query($sql_ex);
        if ($res_ex && $res_ex->num_rows > 0) {
            while ($em = $res_ex->fetch_assoc()) {
                $exists = false;
                foreach ($items as $it) {
                    if ($it['value'] === $em['markdistid']) { $exists = true; break; }
                }
                if (!$exists) {
                    $items[] = [
                        'value' => $em['markdistid'],
                        'label' => $em['markname'],
                        'name'  => $em['markname']
                    ];
                }
            }
        }

        // 3. Fallback: if classgroup has mark distributions regardless of session/term
        if (empty($items)) {
            $sql_md_fb = "SELECT DISTINCT markdistid, markname, markscore 
                          FROM tbl_cbt_mark_distributions 
                          WHERE (classgroupid = '$esc_cg' OR classgroupid = '$esc_c')
                          ORDER BY markscore ASC, markname ASC";
            $res_fb = $conn->query($sql_md_fb);
            if ($res_fb && $res_fb->num_rows > 0) {
                while ($m = $res_fb->fetch_assoc()) {
                    $score_suffix = (!empty($m['markscore']) && intval($m['markscore']) > 0) ? ' (' . intval($m['markscore']) . ' marks)' : '';
                    $items[] = [
                        'value' => $m['markdistid'],
                        'label' => $m['markname'] . $score_suffix,
                        'name'  => $m['markname']
                    ];
                }
            }
        }

        return $items;
    }
}

// --- DYNAMIC AJAX ENDPOINT: FETCH MARK DISTRIBUTIONS BY CLASS, SESSION & TERM ---
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_mark_distributions') {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    $c_in = isset($_GET['classid']) ? trim($_GET['classid']) : '';
    $s_in = isset($_GET['sessionid']) ? trim($_GET['sessionid']) : '';
    $t_in = isset($_GET['termid']) ? trim($_GET['termid']) : '';
    $dists = get_mark_distributions_for_class_session_term($conn, $c_in, $s_in, $t_in);
    echo json_encode($dists);
    exit;
}

// --- RESOLVE ACTIVE SESSION & ACTIVE TERM (FOR SIDEBAR DISPLAY) ---
$active_session_name = '';
$active_session_id = !empty($school['current_sessionid']) ? $school['current_sessionid'] : '';
if (!empty($active_session_id)) {
    $s_chk = $conn->query("SELECT sessionname FROM tbl_cbt_sessions WHERE sessionid = '" . $conn->real_escape_string($active_session_id) . "' LIMIT 1");
    if ($s_chk && $s_chk->num_rows > 0) {
        $active_session_name = $s_chk->fetch_assoc()['sessionname'];
    }
}
if (empty($active_session_name)) {
    $s_chk2 = $conn->query("SELECT sessionid, sessionname FROM tbl_cbt_sessions WHERE is_current = 1 LIMIT 1");
    if ($s_chk2 && $s_chk2->num_rows > 0) {
        $s_row2 = $s_chk2->fetch_assoc();
        $active_session_id = $s_row2['sessionid'];
        $active_session_name = $s_row2['sessionname'];
    }
}

$active_term_name = '';
$active_term_id = !empty($school['current_termid']) ? $school['current_termid'] : '';
if (!empty($active_term_id)) {
    $t_chk = $conn->query("SELECT termname FROM tbl_cbt_terms WHERE termid = '" . $conn->real_escape_string($active_term_id) . "' LIMIT 1");
    if ($t_chk && $t_chk->num_rows > 0) {
        $active_term_name = $t_chk->fetch_assoc()['termname'];
    }
}
if (empty($active_term_name)) {
    $t_chk2 = $conn->query("SELECT termid, termname FROM tbl_cbt_terms WHERE is_current = 1 LIMIT 1");
    if ($t_chk2 && $t_chk2->num_rows > 0) {
        $t_row2 = $t_chk2->fetch_assoc();
        $active_term_id = $t_row2['termid'];
        $active_term_name = $t_row2['termname'];
    }
}

// --- REPORT FILTER PARAMETERS IN ORDER (DO NOT SELECT ANY BY DEFAULT) ---
// 1. Class / Arm
$selected_class    = isset($_GET['classid']) ? trim($_GET['classid']) : '';
// 2. Academic Session
$selected_session  = isset($_GET['sessionid']) ? trim($_GET['sessionid']) : '';
// 3. Academic Term
$selected_term     = isset($_GET['termid']) ? trim($_GET['termid']) : '';
// 4. Subject
$selected_subject  = isset($_GET['subjectid']) ? trim($_GET['subjectid']) : '';
// 5. Mark Distribution (derived from Class ID, Session ID & Term ID)
$selected_markdist = isset($_GET['markdistid']) ? trim($_GET['markdistid']) : '';

// 1. Fetch Classes for filter dropdown
$report_classes = [];
$res_c = $conn->query("SELECT DISTINCT classgroupid, classgroupname FROM tbl_cbt_class_groups ORDER BY classgrouplevel ASC, classgroupname ASC");
if ($res_c && $res_c->num_rows > 0) {
    while ($c = $res_c->fetch_assoc()) {
        $report_classes[] = ['value' => $c['classgroupid'], 'label' => $c['classgroupname']];
    }
}
$res_st_c = $conn->query("SELECT DISTINCT classname FROM tbl_cbt_students WHERE classname IS NOT NULL AND classname != '' ORDER BY classname ASC");
if ($res_st_c) {
    while ($c = $res_st_c->fetch_assoc()) {
        $exists = false;
        foreach ($report_classes as $rc) {
            if ($rc['label'] === $c['classname']) { $exists = true; break; }
        }
        if (!$exists) {
            $report_classes[] = ['value' => $c['classname'], 'label' => $c['classname']];
        }
    }
}

// 2. Fetch Sessions for filter dropdown
$report_sessions = [];
$res_s = $conn->query("SELECT sessionid, sessionname, is_current FROM tbl_cbt_sessions ORDER BY sessionname DESC");
if ($res_s && $res_s->num_rows > 0) {
    while ($s = $res_s->fetch_assoc()) {
        $report_sessions[] = [
            'value' => $s['sessionid'],
            'label' => $s['sessionname']
        ];
    }
}
if (empty($report_sessions)) {
    $report_sessions = [
        ['value' => '68bab4c928f06277748994016768200', 'label' => '2025/2026 Academic Session'],
        ['value' => '68bab4c928f06277748994016768201', 'label' => '2026/2027 Academic Session']
    ];
}

// 3. Fetch Terms for filter dropdown
$report_terms = [];
$res_t = $conn->query("SELECT termid, termname, is_current FROM tbl_cbt_terms ORDER BY termposition ASC, termname ASC");
if ($res_t && $res_t->num_rows > 0) {
    while ($t = $res_t->fetch_assoc()) {
        $report_terms[] = [
            'value' => $t['termid'],
            'label' => $t['termname']
        ];
    }
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
    while ($sb = $res_sub->fetch_assoc()) {
        $report_subjects[] = ['value' => $sb['subjectid'], 'label' => $sb['subjectname']];
    }
}
$res_ex_sub = $conn->query("SELECT DISTINCT subjectname FROM tbl_cbt_exams WHERE subjectname IS NOT NULL AND subjectname != '' ORDER BY subjectname ASC");
if ($res_ex_sub) {
    while ($sb = $res_ex_sub->fetch_assoc()) {
        $exists = false;
        foreach ($report_subjects as $rsb) {
            if ($rsb['label'] === $sb['subjectname']) { $exists = true; break; }
        }
        if (!$exists) {
            $report_subjects[] = ['value' => $sb['subjectname'], 'label' => $sb['subjectname']];
        }
    }
}

// 5. Fetch Mark Distributions using the values of Class ID, Session ID & Term ID
$report_markdists = [];
if (!empty($selected_class) && !empty($selected_session) && !empty($selected_term)) {
    $report_markdists = get_mark_distributions_for_class_session_term($conn, $selected_class, $selected_session, $selected_term);
}

// Resolve friendly labels for print header and excel export
$filter_class_label = 'All Classes';
if (!empty($selected_class)) {
    foreach ($report_classes as $c) {
        if ($selected_class === (string)$c['value'] || $selected_class === (string)$c['label']) {
            $filter_class_label = $c['label'];
            break;
        }
    }
}

$filter_session_label = 'All Sessions';
if (!empty($selected_session)) {
    foreach ($report_sessions as $s) {
        if ($selected_session === (string)$s['value'] || $selected_session === (string)$s['label']) {
            $filter_session_label = $s['label'];
            break;
        }
    }
}

$filter_term_label = 'All Terms';
if (!empty($selected_term)) {
    foreach ($report_terms as $t) {
        if ($selected_term === (string)$t['value'] || $selected_term === (string)$t['label']) {
            $filter_term_label = $t['label'];
            break;
        }
    }
}

$filter_subject_label = 'All Subjects';
if (!empty($selected_subject)) {
    foreach ($report_subjects as $sb) {
        if ($selected_subject === (string)$sb['value'] || $selected_subject === (string)$sb['label']) {
            $filter_subject_label = $sb['label'];
            break;
        }
    }
}

$filter_markdist_label = 'All Mark Distributions';
if (!empty($selected_markdist)) {
    foreach ($report_markdists as $m) {
        if ($selected_markdist === (string)$m['value'] || (isset($m['name']) && $selected_markdist === (string)$m['name'])) {
            $filter_markdist_label = $m['label'];
            break;
        }
    }
}

// --- CHECK IF REPORT IS FILTERED (DO NOT PRELOAD SCORES BY DEFAULT) ---
$is_filtered = (!empty($selected_class) || !empty($selected_session) || !empty($selected_term) || !empty($selected_subject) || !empty($selected_markdist));

$report_results = [];
$total_score_sum = 0;
$max_score = 0;
$passed_count = 0;
$filtered_count = 0;
$avg_score = 0;
$pass_rate = 0;

if ($is_filtered) {
    $where_clauses = ["1=1"];

    // 1. Class filter
    if (!empty($selected_class)) {
        $esc_class = $conn->real_escape_string($selected_class);
        $where_clauses[] = "(r.classid = '$esc_class' OR r.classgroupid = '$esc_class' OR e.classgroupid = '$esc_class' OR e.classid = '$esc_class' OR cg.classgroupname = '$esc_class' OR st.classname = '$esc_class')";
    }

    // 2. Session filter
    if (!empty($selected_session)) {
        $esc_sess = $conn->real_escape_string($selected_session);
        $where_clauses[] = "(e.sessionid = '$esc_sess' OR s.sessionname LIKE '%$esc_sess%')";
    }

    // 3. Term filter
    if (!empty($selected_term)) {
        $esc_term = $conn->real_escape_string($selected_term);
        $where_clauses[] = "(e.termid = '$esc_term' OR t.termname LIKE '%$esc_term%')";
    }

    // 4. Subject filter
    if (!empty($selected_subject)) {
        $esc_sub = $conn->real_escape_string($selected_subject);
        $where_clauses[] = "(r.subjectid = '$esc_sub' OR e.subjectid = '$esc_sub' OR e.subjectname = '$esc_sub' OR sb.subjectname = '$esc_sub')";
    }

    // 5. Mark Distribution filter
    if (!empty($selected_markdist)) {
        $esc_md = $conn->real_escape_string($selected_markdist);
        $md_name = '';
        foreach ($report_markdists as $rm) {
            if ($rm['value'] === $selected_markdist && !empty($rm['name'])) {
                $md_name = $conn->real_escape_string($rm['name']);
                break;
            }
        }
        if (!empty($md_name)) {
            $where_clauses[] = "(r.markdistid = '$esc_md' OR e.markdistid = '$esc_md' OR e.markname = '$esc_md' OR e.markname = '$md_name' OR e.markname LIKE '%$md_name%')";
        } else {
            $where_clauses[] = "(r.markdistid = '$esc_md' OR e.markdistid = '$esc_md' OR e.markname = '$esc_md' OR e.markname LIKE '%$esc_md%')";
        }
    }

    $where_sql = implode(' AND ', $where_clauses);

    $sql_report = "SELECT r.*, e.title as exam_title_full, e.passmark, e.subjectname as exam_subject, e.markname as exam_markname,
                          cg.classgroupname, s.sessionname, t.termname, st.classname as student_class
                   FROM tbl_cbt_results r
                   LEFT JOIN tbl_cbt_exams e ON r.examid = e.examid
                   LEFT JOIN tbl_cbt_students st ON r.studentid = st.studentid
                   LEFT JOIN tbl_cbt_class_groups cg ON (r.classgroupid = cg.classgroupid OR e.classgroupid = cg.classgroupid)
                   LEFT JOIN tbl_cbt_sessions s ON e.sessionid = s.sessionid
                   LEFT JOIN tbl_cbt_terms t ON e.termid = t.termid
                   LEFT JOIN tbl_cbt_subjects sb ON (r.subjectid = sb.subjectid OR e.subjectid = sb.subjectid)
                   WHERE $where_sql
                   ORDER BY COALESCE(r.date_taken, r.submitted_at) DESC";

    $res_report = $conn->query($sql_report);

    if ($res_report && $res_report->num_rows > 0) {
        while ($row = $res_report->fetch_assoc()) {
            $report_results[] = $row;
            $row_score = round(floatval($row['score']));
            $row_max = (!empty($row['total_score']) && floatval($row['total_score']) > 0) ? round(floatval($row['total_score'])) : 100;
            $row_pct = ($row_max > 0) ? ($row_score / $row_max) * 100 : 0;
            $total_score_sum += $row_score;
            if ($row_score > $max_score) {
                $max_score = $row_score;
            }
            if ($row_pct >= 50) {
                $passed_count++;
            }
        }
    }

    $filtered_count = count($report_results);
    $avg_score = $filtered_count > 0 ? round($total_score_sum / $filtered_count) : 0;
    $pass_rate = $filtered_count > 0 ? round(($passed_count / $filtered_count) * 100, 1) : 0;
}

// --- EXPORT TO EXCEL HANDLER ---
if (isset($_GET['export']) && $_GET['export'] === 'excel' && $is_filtered) {
    $filename = 'Student_CBT_Score_Report_' . date('Ymd_His') . '.xls';
    if (!headers_sent()) {
        header('Content-Type: application/vnd.ms-excel; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: max-age=0');
        header('Pragma: public');
    }
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Microsoft Excel
    ?>
    <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
        <style>
            body { font-family: Calibri, Arial, sans-serif; font-size: 11pt; color: #000000; }
            table { border-collapse: collapse; width: 100%; }
            th { background-color: #1e3a8a; color: #ffffff; font-weight: bold; border: 1px solid #000000; text-align: center; padding: 7px 10px; }
            td { border: 1px solid #cbd5e1; padding: 6px 8px; vertical-align: middle; }
            .title { font-size: 16pt; font-weight: bold; text-align: center; color: #1e3a8a; height: 35px; }
            .subtitle { font-size: 12pt; font-weight: bold; text-align: center; color: #334155; height: 25px; }
            .meta { font-size: 10pt; color: #475569; text-align: center; height: 25px; }
            .summary { font-size: 10pt; color: #1e293b; text-align: center; background-color: #f1f5f9; height: 24px; }
            .text-center { text-align: center; }
            .text-right { text-align: right; }
            .passed { color: #059669; font-weight: bold; }
            .failed { color: #dc2626; font-weight: bold; }
        </style>
    </head>
    <body>
        <table>
            <tr>
                <td colspan="12" class="title"><?= htmlspecialchars($school['schoolname']) ?></td>
            </tr>
            <tr>
                <td colspan="12" class="subtitle">OFFICIAL STUDENT CBT SCORE PERFORMANCE REPORT</td>
            </tr>
            <tr>
                <td colspan="12" class="meta">
                    <strong>Class:</strong> <?= htmlspecialchars($filter_class_label) ?> | 
                    <strong>Session:</strong> <?= htmlspecialchars($filter_session_label) ?> | 
                    <strong>Term:</strong> <?= htmlspecialchars($filter_term_label) ?> | 
                    <strong>Subject:</strong> <?= htmlspecialchars($filter_subject_label) ?> | 
                    <strong>Mark Distribution:</strong> <?= htmlspecialchars($filter_markdist_label) ?> | 
                    <strong>Generated:</strong> <?= date('F d, Y h:i A') ?>
                </td>
            </tr>
            <tr>
                <td colspan="12" class="summary">
                    Total Candidates: <strong><?= $filtered_count ?></strong> | 
                    Average Score: <strong><?= round($avg_score) ?></strong> | 
                    Highest Score: <strong><?= round($max_score) ?></strong> | 
                    Pass Rate: <strong><?= $pass_rate ?>%</strong> (<?= $passed_count ?> Passed)
                </td>
            </tr>
            <tr><td colspan="12"></td></tr>
            <thead>
                <tr>
                    <th>S/N</th>
                    <th>Candidate Name</th>
                    <th>Registration Number</th>
                    <th>Class / Arm</th>
                    <th>Subject</th>
                    <th>Exam Title</th>
                    <th>Mark Distribution</th>
                    <th>Score</th>
                    <th>Total Score</th>
                    <th>Percentage (%)</th>
                    <th>Remark</th>
                    <th>Date Taken</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                if (!empty($report_results)):
                    $sn = 1;
                    foreach ($report_results as $r): 
                        $class_disp = !empty($r['student_class']) ? $r['student_class'] : (!empty($r['classgroupname']) ? $r['classgroupname'] : 'N/A');
                        $sub_disp = !empty($r['sub_name']) ? $r['sub_name'] : (!empty($r['exam_subject']) ? $r['exam_subject'] : 'N/A');
                        $mark_disp = !empty($r['exam_markname']) ? $r['exam_markname'] : 'Continuous Assessment';
                        $score_val = round(floatval($r['score']));
                        $max_val = (!empty($r['total_score']) && floatval($r['total_score']) > 0) ? round(floatval($r['total_score'])) : 100;
                        $pct = ($max_val > 0) ? round(($score_val / $max_val) * 100, 1) : 0;
                        $passed = ($pct >= 50);
                ?>
                <tr>
                    <td class="text-center"><?= $sn++ ?></td>
                    <td><strong><?= htmlspecialchars($r['student_name']) ?></strong></td>
                    <td class="text-center" style="mso-number-format:'\@'; font-weight: bold;"><?= htmlspecialchars($r['regnumber']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($class_disp) ?></td>
                    <td><?= htmlspecialchars($sub_disp) ?></td>
                    <td><?= htmlspecialchars(!empty($r['exam_title_full']) ? $r['exam_title_full'] : $r['exam_title']) ?></td>
                    <td class="text-center"><?= htmlspecialchars($mark_disp) ?></td>
                    <td class="text-center" style="font-weight: bold; font-size: 12pt;"><?= $score_val ?></td>
                    <td class="text-center"><?= $max_val ?></td>
                    <td class="text-center"><?= $pct ?>%</td>
                    <td class="text-center <?= $passed ? 'passed' : 'failed' ?>"><?= $passed ? 'PASSED' : 'FAILED' ?></td>
                    <td class="text-center"><?= !empty($r['date_taken']) ? date('d-M-Y h:i A', strtotime($r['date_taken'])) : (!empty($r['submitted_at']) ? date('d-M-Y h:i A', strtotime($r['submitted_at'])) : 'N/A') ?></td>
                </tr>
                <?php 
                    endforeach;
                else: 
                ?>
                <tr>
                    <td colspan="12" class="text-center" style="padding: 20px;">No exam scores match selected criteria.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student CBT Score Performance Reports | EduPortal</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ============================================================
           PRINT REPORT STYLES: PURE WHITE BACKGROUND, CRISP CLEAN TABLE
           ============================================================ */
        @media print {
            @page {
                size: A4 portrait;
                margin: 10mm 10mm;
            }
            html, body {
                background: #ffffff !important;
                background-color: #ffffff !important;
                color: #000000 !important;
                font-family: Arial, Helvetica, sans-serif !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }
            /* Hide sidebar, filter form, buttons, and stats boxes */
            .sidebar, .no-print, .btn, form, .stat-box {
                display: none !important;
            }
            .app-layout, .main-content, .card {
                display: block !important;
                background: #ffffff !important;
                background-color: #ffffff !important;
                color: #000000 !important;
                padding: 0 !important;
                margin: 0 !important;
                border: none !important;
                box-shadow: none !important;
                width: 100% !important;
            }
            .table-responsive {
                display: block !important;
                width: 100% !important;
                overflow: visible !important;
                margin: 0 !important;
                padding: 0 !important;
                border: none !important;
            }
            .data-table {
                width: 100% !important;
                border-collapse: collapse !important;
                margin-top: 10px !important;
                color: #000000 !important;
                background: #ffffff !important;
                border: 1.5px solid #000000 !important;
            }
            .data-table th {
                background: #f1f5f9 !important;
                background-color: #f1f5f9 !important;
                color: #000000 !important;
                font-weight: 700 !important;
                font-size: 11px !important;
                text-transform: uppercase !important;
                border: 1px solid #000000 !important;
                padding: 6px 8px !important;
                text-align: left !important;
            }
            .data-table td {
                background: #ffffff !important;
                background-color: #ffffff !important;
                color: #000000 !important;
                font-size: 11px !important;
                border: 1px solid #94a3b8 !important;
                padding: 6px 8px !important;
                vertical-align: middle !important;
            }
            .badge-offline {
                background: transparent !important;
                color: #000000 !important;
                border: none !important;
                padding: 0 !important;
                font-weight: 600 !important;
            }
            .badge-offline i {
                display: none !important;
            }
            .print-header {
                display: block !important;
                text-align: center !important;
                margin-bottom: 12px !important;
                padding-bottom: 10px !important;
                border-bottom: 2px solid #000000 !important;
                color: #000000 !important;
                background: #ffffff !important;
            }
            .print-header h1 {
                font-size: 18px !important;
                font-weight: 800 !important;
                text-transform: uppercase !important;
                color: #000000 !important;
                margin: 0 0 4px 0 !important;
            }
            .print-header h2 {
                font-size: 13px !important;
                font-weight: 700 !important;
                color: #1e293b !important;
                text-transform: uppercase !important;
                margin: 0 0 6px 0 !important;
                letter-spacing: 0.5px !important;
            }
            .print-filters-box {
                display: block !important;
                margin-top: 6px !important;
                font-size: 11px !important;
                color: #334155 !important;
            }
        }
        .print-header { display: none; }
    </style>
</head>
<body style="display: block; padding: 0;">
    <div class="app-layout">
        <!-- SIDEBAR NAVIGATION -->
        <?php 
        $active_nav = 'reports';
        include __DIR__ . '/sidebar.php'; 
        ?>

        <!-- MAIN CONTENT AREA -->
        <main class="main-content">
            <!-- PRINTABLE HEADER (APPEARS CLEANLY ON WHITE PAPER) -->
            <div class="print-header">
                <h1><?= htmlspecialchars($school['schoolname']) ?></h1>
                <h2>OFFICIAL STUDENT CBT SCORE PERFORMANCE REPORT</h2>
                <div class="print-filters-box">
                    <strong>Class:</strong> <?= htmlspecialchars($filter_class_label) ?> &nbsp;|&nbsp;
                    <strong>Session:</strong> <?= htmlspecialchars($filter_session_label) ?> &nbsp;|&nbsp;
                    <strong>Term:</strong> <?= htmlspecialchars($filter_term_label) ?> &nbsp;|&nbsp;
                    <strong>Subject:</strong> <?= htmlspecialchars($filter_subject_label) ?> &nbsp;|&nbsp;
                    <strong>Mark Distribution:</strong> <?= htmlspecialchars($filter_markdist_label) ?>
                </div>
                <div style="font-size: 10px; color: #475569; margin-top: 4px;">
                    Date Generated: <?= date('F d, Y h:i A') ?> &nbsp;|&nbsp; Candidates: <strong><?= $filtered_count ?></strong> &nbsp;|&nbsp; Average Score: <strong><?= round($avg_score) ?></strong> &nbsp;|&nbsp; Highest: <strong><?= round($max_score) ?></strong> &nbsp;|&nbsp; Pass Rate: <strong><?= $pass_rate ?>%</strong> (<?= $passed_count ?> Passed)
                </div>
            </div>

            <!-- PAGE HEADER (SCREEN VIEW) -->
            <div class="card no-print" style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h1 class="card-title" style="font-size: 24px;">
                        <i class="fa-solid fa-chart-pie" style="color: var(--primary);"></i> Student CBT Score Performance Report
                    </h1>
                    <div style="font-size: 14px; color: var(--text-muted); margin-top: 4px;">
                        Filter student performance analytics by Class / Arm, Academic Session, Academic Term, Subject, and Mark Distribution.
                    </div>
                </div>
                <?php if ($is_filtered && !empty($report_results)): ?>
                    <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                        <a href="reports.php?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'excel']))) ?>" class="btn" style="background: #10b981; border: 1px solid #059669; color: #ffffff; font-weight: 700; text-decoration: none; padding: 10px 18px; border-radius: 6px; font-size: 13px; display: inline-flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-file-excel" style="font-size: 15px;"></i> Export to Excel
                        </a>
                        <button onclick="window.print()" class="btn btn-primary" style="background: var(--primary); border-color: var(--primary); font-weight: 700; padding: 10px 18px; font-size: 13px; display: inline-flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-print"></i> Print Official Report
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <!-- FILTER SECTION CARD -->
            <div class="card" style="margin-bottom: 28px;">
                <div style="margin-bottom: 16px;" class="no-print">
                    <div style="font-weight: 700; font-size: 16px; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-sliders" style="color: var(--primary);"></i> Filter Parameters
                    </div>
                    <div style="font-size: 13px; color: var(--text-muted); margin-top: 2px;">
                        Parameters are ordered sequentially. Selecting Class, Session, and Term dynamically resolves the corresponding Mark Distribution.
                    </div>
                </div>

                <!-- FILTER FORM -->
                <form method="GET" action="reports.php" id="reportFilterForm" class="no-print" style="margin-bottom: 24px; background: rgba(15, 23, 42, 0.6); padding: 20px; border-radius: 12px; border: 1px solid var(--border-color);">
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px;">
                        <!-- 1. FILTER BY CLASS / ARM -->
                        <div>
                            <label class="form-label" for="classid"><i class="fa-solid fa-users"></i> Class / Arm</label>
                            <select id="classid" name="classid" class="form-control" style="background: rgba(15, 23, 42, 0.9); font-weight: 600;">
                                <option value="">-- Select Class --</option>
                                <?php foreach ($report_classes as $c): ?>
                                    <option value="<?= htmlspecialchars($c['value']) ?>" <?= ($selected_class !== '' && ($selected_class === (string)$c['value'] || $selected_class === (string)$c['label'])) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($c['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 2. FILTER BY ACADEMIC SESSION -->
                        <div>
                            <label class="form-label" for="sessionid"><i class="fa-solid fa-calendar-days"></i> Academic Session</label>
                            <select id="sessionid" name="sessionid" class="form-control" style="background: rgba(15, 23, 42, 0.9); font-weight: 600;">
                                <option value="">-- Select Session --</option>
                                <?php foreach ($report_sessions as $s): ?>
                                    <option value="<?= htmlspecialchars($s['value']) ?>" <?= ($selected_session !== '' && ($selected_session === (string)$s['value'] || $selected_session === (string)$s['label'])) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 3. FILTER BY ACADEMIC TERM -->
                        <div>
                            <label class="form-label" for="termid"><i class="fa-solid fa-clock-rotate-left"></i> Academic Term</label>
                            <select id="termid" name="termid" class="form-control" style="background: rgba(15, 23, 42, 0.9); font-weight: 600;">
                                <option value="">-- Select Term --</option>
                                <?php foreach ($report_terms as $t): ?>
                                    <option value="<?= htmlspecialchars($t['value']) ?>" <?= ($selected_term !== '' && ($selected_term === (string)$t['value'] || $selected_term === (string)$t['label'])) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($t['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 4. FILTER BY SUBJECT -->
                        <div>
                            <label class="form-label" for="subjectid"><i class="fa-solid fa-book"></i> Subject</label>
                            <select id="subjectid" name="subjectid" class="form-control" style="background: rgba(15, 23, 42, 0.9); font-weight: 600;">
                                <option value="">-- Select Subject --</option>
                                <?php foreach ($report_subjects as $sb): ?>
                                    <option value="<?= htmlspecialchars($sb['value']) ?>" <?= ($selected_subject !== '' && ($selected_subject === (string)$sb['value'] || $selected_subject === (string)$sb['label'])) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sb['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- 5. FILTER BY MARK DISTRIBUTION (SHOWN LAST, DERIVED FROM CLASS, SESSION & TERM) -->
                        <div>
                            <label class="form-label" for="markdistid">
                                <i class="fa-solid fa-chart-simple"></i> Mark Distribution
                            </label>
                            <select id="markdistid" name="markdistid" class="form-control" style="background: rgba(15, 23, 42, 0.9); font-weight: 600;">
                                <?php if (empty($selected_class) || empty($selected_session) || empty($selected_term)): ?>
                                    <option value="">-- Select Class, Session & Term First --</option>
                                <?php else: ?>
                                    <option value="">-- Select Mark Distribution --</option>
                                    <?php if (!empty($report_markdists)): ?>
                                        <?php foreach ($report_markdists as $m): ?>
                                            <option value="<?= htmlspecialchars($m['value']) ?>" <?= ($selected_markdist !== '' && ($selected_markdist === (string)$m['value'] || (isset($m['name']) && $selected_markdist === (string)$m['name']))) ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($m['label']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <option value="">-- No Mark Distributions Configured --</option>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>

                    <div style="display: flex; gap: 12px; margin-top: 20px; align-items: center; flex-wrap: wrap;">
                        <button type="submit" class="btn btn-primary" style="padding: 10px 24px; font-weight: 700;">
                            <i class="fa-solid fa-sliders"></i> Generate Score Report
                        </button>
                        <?php if ($is_filtered && !empty($report_results)): ?>
                            <a href="reports.php?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['export' => 'excel']))) ?>" class="btn" style="background: #10b981; border: 1px solid #059669; color: #ffffff; font-weight: 700; text-decoration: none; padding: 10px 18px; border-radius: 6px; font-size: 13px; display: inline-flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-file-excel"></i> Export to Excel
                            </a>
                        <?php endif; ?>
                        <?php if ($is_filtered): ?>
                            <a href="reports.php" class="btn" style="background: rgba(255,255,255,0.08); color: var(--text-muted); text-decoration: none; padding: 10px 18px;">
                                <i class="fa-solid fa-rotate-left"></i> Reset Filters
                            </a>
                        <?php endif; ?>
                    </div>
                </form>

                <?php if ($is_filtered): ?>
                    <!-- REPORT SUMMARY STATISTICS STATS CARDS (HIDDEN AUTOMATICALLY WHEN PRINTED) -->
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin-bottom: 24px;" class="no-print">
                        <div class="stat-box" style="padding: 16px;">
                            <div class="stat-lbl"><i class="fa-solid fa-user-check"></i> Filtered Candidates</div>
                            <div class="stat-val" style="font-size: 24px;"><?= number_format($filtered_count) ?></div>
                        </div>

                        <div class="stat-box" style="padding: 16px;">
                            <div class="stat-lbl"><i class="fa-solid fa-calculator"></i> Average Score</div>
                            <div class="stat-val" style="font-size: 24px; color: var(--primary);"><?= round($avg_score) ?></div>
                        </div>

                        <div class="stat-box" style="padding: 16px;">
                            <div class="stat-lbl"><i class="fa-solid fa-trophy"></i> Highest Score</div>
                            <div class="stat-val" style="font-size: 24px; color: var(--success);"><?= round($max_score) ?></div>
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
                                    <th style="width: 45px; text-align: center;">S/N</th>
                                    <th>Candidate Name</th>
                                    <th>Reg Number</th>
                                    <th>Class</th>
                                    <th>Subject & Exam Title</th>
                                    <th>Mark Distribution</th>
                                    <th>Session & Term</th>
                                    <th>Score</th>
                                    <th>Date Taken</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($report_results)): ?>
                                    <?php 
                                    $sn = 1;
                                    foreach ($report_results as $r): 
                                        $class_disp = !empty($r['student_class']) ? $r['student_class'] : (!empty($r['classgroupname']) ? $r['classgroupname'] : 'N/A');
                                        $sub_disp = !empty($r['sub_name']) ? $r['sub_name'] : (!empty($r['exam_subject']) ? $r['exam_subject'] : 'N/A');
                                        $sess_disp = !empty($r['sessionname']) ? $r['sessionname'] : '2025/2026 Session';
                                        $term_disp = !empty($r['termname']) ? $r['termname'] : 'First Term';
                                        $mark_disp = !empty($r['exam_markname']) ? $r['exam_markname'] : 'Continuous Assessment';
                                        $max_score_val = (!empty($r['total_score']) && floatval($r['total_score']) > 0) ? round(floatval($r['total_score'])) : 100;
                                        $score_val = round(floatval($r['score']));
                                        $pct_val = ($max_score_val > 0) ? round(($score_val / $max_score_val) * 100, 1) : 0;
                                        $is_passed = ($pct_val >= 50);
                                    ?>
                                        <tr>
                                            <td style="text-align: center; font-weight: bold;"><?= $sn++ ?></td>
                                            <td><strong><?= htmlspecialchars($r['student_name']) ?></strong></td>
                                            <td><span style="font-family: monospace; font-weight: 700; color: #f59e0b;" class="reg-number-cell"><?= htmlspecialchars($r['regnumber']) ?></span></td>
                                            <td><span class="badge-offline" style="background: rgba(99, 102, 241, 0.15); color: var(--primary);"><i class="fa-solid fa-users"></i> <?= htmlspecialchars($class_disp) ?></span></td>
                                            <td>
                                                <strong><?= htmlspecialchars($sub_disp) ?></strong><br>
                                                <small style="color: var(--text-muted);"><?= htmlspecialchars(!empty($r['exam_title_full']) ? $r['exam_title_full'] : $r['exam_title']) ?></small>
                                            </td>
                                            <td>
                                                <span class="badge-offline" style="background: rgba(16, 185, 129, 0.15); color: #34d399; font-weight: 600;">
                                                    <i class="fa-solid fa-chart-simple"></i> <?= htmlspecialchars($mark_disp) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small style="font-weight: 600; color: var(--text-muted); display: block;">
                                                    <i class="fa-solid fa-calendar"></i> <?= htmlspecialchars($sess_disp) ?><br>
                                                    <i class="fa-solid fa-clock-rotate-left"></i> <?= htmlspecialchars($term_disp) ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span style="font-weight: 800; font-size: 16px; color: <?= $is_passed ? 'var(--success)' : 'var(--danger)' ?>;">
                                                    <?= round($score_val) ?> <span style="font-size: 12px; font-weight: 600; color: var(--text-muted);">/ <?= round($max_score_val) ?></span>
                                                </span>
                                            </td>
                                            <td><?= !empty($r['date_taken']) ? date('d M Y, h:i A', strtotime($r['date_taken'])) : (!empty($r['submitted_at']) ? date('d M Y, h:i A', strtotime($r['submitted_at'])) : 'N/A') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="9" style="text-align: center; color: var(--text-muted); padding: 48px;">
                                            <i class="fa-solid fa-chart-line" style="font-size: 40px; margin-bottom: 12px; color: var(--text-muted);"></i>
                                            <div style="font-size: 16px; font-weight: 700;">No Exam Scores Match Selected Filter Parameters</div>
                                            <p style="margin-top: 6px; font-size: 13px;">Try selecting a different Class, Session, Term, Subject, or Mark Distribution from the filter bar above.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <!-- UNFILTERED EMPTY STATE: SCORES ARE NOT PRELOADED UNTIL FILTERED -->
                    <div style="text-align: center; padding: 60px 20px; background: rgba(15, 23, 42, 0.4); border-radius: 12px; border: 1px dashed var(--border-color); margin-top: 10px;">
                        <div style="width: 64px; height: 64px; border-radius: 50%; background: rgba(59, 130, 246, 0.12); color: var(--primary); display: inline-flex; align-items: center; justify-content: center; font-size: 28px; margin-bottom: 16px;">
                            <i class="fa-solid fa-filter"></i>
                        </div>
                        <h3 style="font-size: 18px; font-weight: 700; margin-bottom: 8px; color: var(--text-main);">Select Filter Parameters to View Scores</h3>
                        <p style="color: var(--text-muted); font-size: 14px; max-width: 520px; margin: 0 auto 20px; line-height: 1.5;">
                            Student scores are not preloaded. Choose a <strong>Class / Arm</strong>, <strong>Academic Session</strong>, <strong>Academic Term</strong>, <strong>Subject</strong>, or <strong>Mark Distribution</strong> above and click <strong>Generate Score Report</strong>.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- DYNAMIC CLIENT-SIDE MARK DISTRIBUTION UPDATER -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const classSelect = document.getElementById('classid');
        const sessionSelect = document.getElementById('sessionid');
        const termSelect = document.getElementById('termid');
        const markdistSelect = document.getElementById('markdistid');

        if (!markdistSelect) return;

        let preservedSelectedMd = "<?= addslashes($selected_markdist) ?>";

        function updateMarkDistributions() {
            const classVal = classSelect ? classSelect.value.trim() : '';
            const sessVal = sessionSelect ? sessionSelect.value.trim() : '';
            const termVal = termSelect ? termSelect.value.trim() : '';
            const currentSelected = markdistSelect.value || preservedSelectedMd;

            if (!classVal || !sessVal || !termVal) {
                markdistSelect.innerHTML = '<option value="">-- Select Class, Session & Term First --</option>';
                return;
            }

            markdistSelect.innerHTML = '<option value="">Loading Mark Distributions...</option>';

            fetch('reports.php?ajax=get_mark_distributions&classid=' + encodeURIComponent(classVal) + '&sessionid=' + encodeURIComponent(sessVal) + '&termid=' + encodeURIComponent(termVal))
                .then(res => res.json())
                .then(data => {
                    let optionsHtml = '<option value="">-- Select Mark Distribution --</option>';
                    if (Array.isArray(data) && data.length > 0) {
                        data.forEach(item => {
                            const isSel = (currentSelected === item.value || (item.name && currentSelected === item.name)) ? 'selected' : '';
                            optionsHtml += `<option value="${escapeHtml(item.value)}" ${isSel}>${escapeHtml(item.label)}</option>`;
                        });
                    } else {
                        optionsHtml = '<option value="">-- No Mark Distributions Configured --</option>';
                    }
                    markdistSelect.innerHTML = optionsHtml;
                })
                .catch(err => {
                    console.error('Failed to load mark distributions:', err);
                    markdistSelect.innerHTML = '<option value="">-- Select Mark Distribution --</option>';
                });
        }

        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>"']/g, function(m) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m];
            });
        }

        if (classSelect) {
            classSelect.addEventListener('change', function() {
                preservedSelectedMd = '';
                updateMarkDistributions();
            });
        }
        if (sessionSelect) {
            sessionSelect.addEventListener('change', function() {
                preservedSelectedMd = '';
                updateMarkDistributions();
            });
        }
        if (termSelect) {
            termSelect.addEventListener('change', function() {
                preservedSelectedMd = '';
                updateMarkDistributions();
            });
        }
    });
    </script>
</body>
</html>
