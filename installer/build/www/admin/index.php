<?php
require_once __DIR__ . '/../config/config.php';

require_admin_login();

$conn = get_db_connection();
$school = get_school_config();
$admin = $_SESSION['cbt_admin'];

$students_count = $conn->query("SELECT COUNT(*) as cnt FROM tbl_cbt_students")->fetch_assoc()['cnt'];
$exams_count = $conn->query("SELECT COUNT(*) as cnt FROM tbl_cbt_exams")->fetch_assoc()['cnt'];
$pending_results_count = $conn->query("SELECT COUNT(*) as cnt FROM tbl_cbt_results WHERE sync_status = 0")->fetch_assoc()['cnt'];
$total_results_count = $conn->query("SELECT COUNT(*) as cnt FROM tbl_cbt_results")->fetch_assoc()['cnt'];

// --- RESOLVE ACTIVE SESSION & ACTIVE TERM ---
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

// Check if admin is currently using the default initial password
$is_default_password = false;
$cur_admin_chk = $conn->query("SELECT password_raw, password_hash FROM tbl_cbt_admins WHERE adminid = '" . $conn->real_escape_string($admin['adminid']) . "' OR username = '" . $conn->real_escape_string($admin['username']) . "' LIMIT 1");
if ($cur_admin_chk && $cur_admin_chk->num_rows > 0) {
    $cur_adm = $cur_admin_chk->fetch_assoc();
    if ($cur_adm['password_raw'] === 'CBTADMIN2026' || (!empty($cur_adm['password_hash']) && password_verify('CBTADMIN2026', $cur_adm['password_hash']))) {
        $is_default_password = true;
    }
}

// Fetch 10 most recent submissions for quick overview
$recent_submissions = [];
$res_rec = $conn->query("SELECT r.*, e.title as exam_title_full, e.subjectname as exam_subject, e.markname as exam_markname, st.classname as student_class
                        FROM tbl_cbt_results r
                        LEFT JOIN tbl_cbt_exams e ON r.examid = e.examid
                        LEFT JOIN tbl_cbt_students st ON r.studentid = st.studentid
                        ORDER BY COALESCE(r.date_taken, r.submitted_at) DESC
                        LIMIT 10");
if ($res_rec && $res_rec->num_rows > 0) {
    while ($r = $res_rec->fetch_assoc()) {
        $recent_submissions[] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Offline CBT</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body style="display: block; padding: 0;">
    <div class="app-layout">
        <!-- SIDEBAR NAVIGATION -->
        <?php 
        $active_nav = 'index';
        include __DIR__ . '/sidebar.php'; 
        ?>

        <!-- MAIN CONTENT AREA -->
        <main class="main-content">
            <?php if ($is_default_password): ?>
                <div class="alert no-print" style="margin-bottom: 24px; background: rgba(245, 158, 11, 0.12); border: 1px solid rgba(245, 158, 11, 0.4); border-left: 4px solid #f59e0b; padding: 16px 20px; border-radius: 8px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                    <div style="display: flex; align-items: center; gap: 14px;">
                        <i class="fa-solid fa-triangle-exclamation" style="font-size: 24px; color: #f59e0b; flex-shrink: 0;"></i>
                        <div>
                            <div style="font-weight: 700; font-size: 14px; color: #f59e0b; margin-bottom: 2px;">Security Notice: Default Admin Password Active</div>
                            <div style="font-size: 13px; color: var(--text-muted); line-height: 1.4;">
                                You are currently using the default setup password (<strong>CBTADMIN2026</strong>). Please change your password now to secure your offline CBT server.
                            </div>
                        </div>
                    </div>
                    <div>
                        <a href="change_password.php" class="btn btn-warning" style="background: #f59e0b; color: #000; font-weight: 700; white-space: nowrap; text-decoration: none; padding: 8px 16px; border-radius: 6px; font-size: 13px; display: inline-flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-key"></i> Change Password Now
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <div class="card" style="margin-bottom: 24px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <div>
                    <h1 class="card-title" style="font-size: 24px;"><?= htmlspecialchars($school['schoolname']) ?></h1>
                    <div style="font-size: 14px; color: var(--text-muted); margin-top: 4px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
                        <span>School ID: <strong><?= htmlspecialchars($school['schoolid']) ?></strong></span>
                        <span>&bull;</span>
                        <span>Online Server: <strong><?= htmlspecialchars($school['online_url']) ?></strong></span>
                    </div>
                    <?php if (!empty($active_session_name) || !empty($active_term_name)): ?>
                        <div style="margin-top: 10px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center;">
                            <?php if (!empty($active_session_name)): ?>
                                <span style="background: rgba(37, 99, 235, 0.15); border: 1px solid rgba(59, 130, 246, 0.4); color: #93c5fd; padding: 4px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                                    <i class="fa-solid fa-calendar-check"></i> Active Session: <strong><?= htmlspecialchars($active_session_name) ?></strong>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($active_term_name)): ?>
                                <span style="background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.4); color: #6ee7b7; padding: 4px 12px; border-radius: 6px; font-size: 13px; font-weight: 600; display: inline-flex; align-items: center; gap: 6px;">
                                    <i class="fa-solid fa-clock-rotate-left"></i> Active Term: <strong><?= htmlspecialchars($active_term_name) ?></strong>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <a href="reports.php" class="btn btn-primary" style="text-decoration: none; background: #6366f1; border-color: #6366f1;">
                        <i class="fa-solid fa-chart-pie"></i> Score Reports
                    </a>
                    <a href="../api/sync_pull.php" class="btn btn-primary" style="text-decoration: none;">
                        <i class="fa-solid fa-arrows-rotate"></i> Sync Data
                    </a>
                </div>
            </div>

            <!-- STATS OVERVIEW GRID -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 28px;">
                <div class="stat-box" style="border-left: 4px solid #3b82f6;">
                    <div class="stat-lbl"><i class="fa-solid fa-calendar-check" style="color: #60a5fa;"></i> Active Session</div>
                    <div class="stat-val" style="font-size: 20px; color: #93c5fd;"><?= htmlspecialchars(!empty($active_session_name) ? $active_session_name : 'Not Set') ?></div>
                </div>
                <div class="stat-box" style="border-left: 4px solid #10b981;">
                    <div class="stat-lbl"><i class="fa-solid fa-clock-rotate-left" style="color: #34d399;"></i> Active Term</div>
                    <div class="stat-val" style="font-size: 20px; color: #6ee7b7;"><?= htmlspecialchars(!empty($active_term_name) ? $active_term_name : 'Not Set') ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-lbl"><i class="fa-solid fa-user-graduate"></i> Active Students</div>
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

            <!-- PROMINENT REPORT SHORTCUT BANNER CARD -->
            <div class="card" style="margin-bottom: 28px; background: linear-gradient(135deg, rgba(30, 41, 59, 0.9) 0%, rgba(15, 23, 42, 0.95) 100%); border: 1px solid rgba(99, 102, 241, 0.3); padding: 24px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
                    <div style="display: flex; align-items: center; gap: 20px;">
                        <div style="width: 56px; height: 56px; border-radius: 12px; background: rgba(99, 102, 241, 0.15); border: 1px solid rgba(99, 102, 241, 0.3); display: flex; align-items: center; justify-content: center; font-size: 26px; color: #818cf8; flex-shrink: 0;">
                            <i class="fa-solid fa-chart-pie"></i>
                        </div>
                        <div>
                            <h2 style="font-size: 18px; font-weight: 700; color: #ffffff; margin-bottom: 4px;">Student CBT Score Performance Reports</h2>
                            <p style="font-size: 14px; color: var(--text-muted); margin: 0; line-height: 1.5;">
                                Filter student score results on demand by Class, Mark Distribution (Continuous Assessment & Exam), Session, Term, and Subject.
                            </p>
                        </div>
                    </div>
                    <div>
                        <a href="reports.php" class="btn btn-primary" style="padding: 12px 24px; font-weight: 700; font-size: 14px; text-decoration: none; display: inline-flex; align-items: center; gap: 10px;">
                            <i class="fa-solid fa-sliders"></i> Go to Score Reports <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>

            <!-- RECENT EXAM SUBMISSIONS TABLE -->
            <div class="card" style="margin-bottom: 28px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 20px;">
                    <div>
                        <div class="card-title"><i class="fa-solid fa-clock-rotate-left" style="color: var(--primary);"></i> Recent Exam Submissions</div>
                        <div class="card-desc">Showing the latest 10 candidate submissions recorded on this offline server.</div>
                    </div>
                    <div>
                        <a href="reports.php" class="btn btn-secondary" style="font-size: 13px; text-decoration: none; padding: 8px 16px;">
                            <i class="fa-solid fa-list-check"></i> View Full Performance Reports
                        </a>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Candidate Name</th>
                                <th>Reg Number</th>
                                <th>Class</th>
                                <th>Subject & Exam Title</th>
                                <th>Mark Distribution</th>
                                <th>Score</th>
                                <th>Date Taken</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($recent_submissions)): ?>
                                <?php foreach ($recent_submissions as $r): 
                                    $class_disp = !empty($r['student_class']) ? $r['student_class'] : 'N/A';
                                    $sub_disp = !empty($r['sub_name']) ? $r['sub_name'] : (!empty($r['exam_subject']) ? $r['exam_subject'] : 'N/A');
                                    $mark_disp = !empty($r['exam_markname']) ? $r['exam_markname'] : 'Continuous Assessment';
                                    $max_score_val = (!empty($r['total_score']) && floatval($r['total_score']) > 0) ? round(floatval($r['total_score'])) : 100;
                                    $score_val = round(floatval($r['score']));
                                    $pct_val = ($max_score_val > 0) ? round(($score_val / $max_score_val) * 100, 1) : 0;
                                    $is_passed = ($pct_val >= 50);
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
                                            <span class="badge-offline" style="background: rgba(16, 185, 129, 0.15); color: #34d399; font-weight: 600;">
                                                <i class="fa-solid fa-chart-simple"></i> <?= htmlspecialchars($mark_disp) ?>
                                            </span>
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
                                    <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 40px;">
                                        <i class="fa-solid fa-inbox" style="font-size: 36px; margin-bottom: 10px; color: var(--text-muted);"></i>
                                        <div style="font-weight: 600;">No student exam submissions recorded yet.</div>
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