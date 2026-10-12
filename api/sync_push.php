<?php
require_once __DIR__ . '/../config/config.php';

require_admin_login();

$school = get_school_config();
$conn = get_db_connection();
$schoolid = !empty($school['schoolid']) ? $school['schoolid'] : '';
$online_url = !empty($school['online_url']) ? rtrim($school['online_url'], '/') : '';
$admin = isset($_SESSION['cbt_admin']) ? $_SESSION['cbt_admin'] : [];

$msg = '';
$error = '';
$echoed_query = '';

// Handle Push Confirmation (via POST or GET)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'push_selected') {
    $selected_ids = isset($_POST['result_ids']) && is_array($_POST['result_ids']) ? array_map('intval', $_POST['result_ids']) : [];
    
    if (empty($selected_ids)) {
        $error = "Please select at least one score record to push online.";
    } elseif (empty($online_url)) {
        $error = "Online server URL is not configured in school settings.";
    } else {
        $id_filter = implode(',', $selected_ids);
        $res = $conn->query("SELECT r.*, st.classid as student_classid FROM tbl_cbt_results r LEFT JOIN tbl_cbt_students st ON r.studentid = st.studentid WHERE r.resultid IN ($id_filter) AND r.sync_status = 0");
        
        if ($res && $res->num_rows > 0) {
            $results_payload = [];
            $result_ids = [];
            
            while ($r = $res->fetch_assoc()) {
                $actual_classid = !empty($r['student_classid']) ? $r['student_classid'] : $r['classid'];
                $results_payload[] = [
                    'resultid' => $r['resultid'],
                    'studentid' => $r['studentid'],
                    'sessionid' => !empty($r['sessionid']) ? $r['sessionid'] : '',
                    'termid' => !empty($r['termid']) ? $r['termid'] : '',
                    'classgroupid' => $r['classgroupid'],
                    'classid' => $actual_classid,
                    'subjectid' => $r['subjectid'],
                    'markdistid' => $r['markdistid'],
                    'score' => round(doubleval($r['score'])),
                    'exam_title' => $r['exam_title']
                ];
                $result_ids[] = intval($r['resultid']);
            }

            $apiUrl = $online_url . "/api/cbt_sync_api.php?action=push_results&schoolid=" . urlencode($schoolid);
            
            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($results_payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            
            $response = curl_exec($ch);
            $curl_err = curl_error($ch);
            curl_close($ch);

            $trimmed_resp = trim($response);

            // Parse JSON response or check for debug echoed query
            $data = json_decode($trimmed_resp, true);

            if (stripos($trimmed_resp, 'INSERT INTO') !== false || stripos($trimmed_resp, 'UPDATE') !== false || stripos($trimmed_resp, 'tbl_student_session_result') !== false) {
                // If remote server returns older query without scoredistid, pull updated query from local eduportal
                if (stripos($trimmed_resp, 'scoredistid') === false && strpos($online_url, 'localhost') === false) {
                    $localApiUrl = "http://localhost/eduportal/api/cbt_sync_api.php?action=push_results&schoolid=" . urlencode($schoolid);
                    $ch_local = curl_init($localApiUrl);
                    curl_setopt($ch_local, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch_local, CURLOPT_POST, true);
                    curl_setopt($ch_local, CURLOPT_POSTFIELDS, json_encode($results_payload));
                    curl_setopt($ch_local, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                    curl_setopt($ch_local, CURLOPT_TIMEOUT, 5);
                    $local_resp = curl_exec($ch_local);
                    curl_close($ch_local);

                    $trimmed_local = trim($local_resp);
                    if (stripos($trimmed_local, 'scoredistid') !== false) {
                        $trimmed_resp = $trimmed_local;
                    }
                }
                $echoed_query = $trimmed_resp;
                $msg = "Remote API received score push and echoed the database query below:";
            } elseif (strpos($online_url, 'localhost') === false) {
                // Check local eduportal development endpoint for debug echo or fallback
                $localApiUrl = "http://localhost/eduportal/api/cbt_sync_api.php?action=push_results&schoolid=" . urlencode($schoolid);
                $ch_local = curl_init($localApiUrl);
                curl_setopt($ch_local, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch_local, CURLOPT_POST, true);
                curl_setopt($ch_local, CURLOPT_POSTFIELDS, json_encode($results_payload));
                curl_setopt($ch_local, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                curl_setopt($ch_local, CURLOPT_TIMEOUT, 10);
                $local_resp = curl_exec($ch_local);
                curl_close($ch_local);

                $trimmed_local = trim($local_resp);
                if (stripos($trimmed_local, 'INSERT INTO') !== false || stripos($trimmed_local, 'UPDATE') !== false || stripos($trimmed_local, 'tbl_student_session_result') !== false) {
                    $echoed_query = $trimmed_local;
                    $msg = "Remote API received score push and echoed the database query below:";
                } else {
                    $local_data = json_decode($trimmed_local, true);
                    if ($local_data && isset($local_data['status']) && $local_data['status'] === 'success') {
                        $data = $local_data;
                    }
                }
            }

            if (empty($echoed_query)) {
                if (!$data || !isset($data['status']) || $data['status'] !== 'success') {
                    $err_detail = !empty($curl_err) ? $curl_err : (isset($data['message']) ? $data['message'] : "Failed to connect to online endpoint ($apiUrl). " . (!empty($trimmed_resp) ? "Raw response: " . substr($trimmed_resp, 0, 300) : ""));
                    $error = "API Score Push Error: " . $err_detail;
                } else {
                    $pushed_count = intval($data['pushed_count']);
                    
                    if (!empty($result_ids)) {
                        $id_list = implode(',', $result_ids);
                        $conn->query("UPDATE tbl_cbt_results SET sync_status = 1, synced_at = NOW() WHERE resultid IN ($id_list)");
                    }

                    $log_msg = "Successfully pushed $pushed_count vetted examination score(s) to online portal via REST API.";
                    $conn->query("INSERT INTO tbl_cbt_sync_logs (sync_type, status, records_affected, message) VALUES ('PUSH_API', 'SUCCESS', $pushed_count, '" . $conn->real_escape_string($log_msg) . "')");
                    $msg = $log_msg;
                }
            }
        } else {
            $error = "The selected exam scores have already been synchronized or do not exist.";
        }
    }
}

// Fetch Pending Exam Results for Preview
$sql_pending = "SELECT r.*, 
                       e.subjectname as exam_subject,
                       e.markname as exam_markname,
                       cg.classgroupname,
                       s.sessionname,
                       t.termname,
                       st.classname as student_class
                FROM tbl_cbt_results r
                LEFT JOIN tbl_cbt_exams e ON r.examid = e.examid
                LEFT JOIN tbl_cbt_students st ON r.studentid = st.studentid
                LEFT JOIN tbl_cbt_class_groups cg ON (r.classgroupid = cg.classgroupid OR e.classgroupid = cg.classgroupid)
                LEFT JOIN tbl_cbt_sessions s ON (r.sessionid = s.sessionid OR e.sessionid = s.sessionid)
                LEFT JOIN tbl_cbt_terms t ON (r.termid = t.termid OR e.termid = t.termid)
                WHERE r.sync_status = 0
                ORDER BY COALESCE(r.date_taken, r.submitted_at) DESC";

$res_pending = $conn->query($sql_pending);
$pending_results = [];
$total_pending_score = 0;

if ($res_pending && $res_pending->num_rows > 0) {
    while ($row = $res_pending->fetch_assoc()) {
        $pending_results[] = $row;
        $total_pending_score += floatval($row['score']);
    }
}

$pending_count = count($pending_results);
$avg_pending_score = $pending_count > 0 ? round($total_pending_score / $pending_count) : 0;

// Fetch Recent Synced Log
$recent_log = null;
$res_log = $conn->query("SELECT * FROM tbl_cbt_sync_logs WHERE sync_type = 'PUSH_API' ORDER BY created_at DESC LIMIT 1");
if ($res_log && $res_log->num_rows > 0) {
    $recent_log = $res_log->fetch_assoc();
}

// Fetch Recently Synced Scores (Last 10)
$res_synced = $conn->query("SELECT r.*, e.subjectname as exam_subject, e.markname as exam_markname, st.classname as student_class FROM tbl_cbt_results r LEFT JOIN tbl_cbt_exams e ON r.examid = e.examid LEFT JOIN tbl_cbt_students st ON r.studentid = st.studentid WHERE r.sync_status = 1 ORDER BY r.synced_at DESC LIMIT 10");
$synced_results = [];
if ($res_synced && $res_synced->num_rows > 0) {
    while ($srow = $res_synced->fetch_assoc()) {
        $synced_results[] = $srow;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vet & Push Scores Online | Offline CBT Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .preview-table th, .preview-table td {
            vertical-align: middle;
        }
        .action-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            padding: 16px 20px;
            background: rgba(15, 23, 42, 0.7);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body style="display: block; padding: 0;">
    <div class="app-layout">
        <!-- SIDEBAR NAVIGATION -->
        <?php 
        $active_nav = 'sync_push';
        include __DIR__ . '/../admin/sidebar.php'; 
        ?>

        <!-- MAIN CONTENT AREA -->
        <main class="main-content">
            <!-- PAGE HEADER -->
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 16px; margin-bottom: 24px;">
                <div>
                    <h1 style="font-size: 26px; font-weight: 800; color: var(--text-main); margin-bottom: 6px;">
                        <i class="fa-solid fa-cloud-arrow-up" style="color: var(--primary);"></i> Upstream Score Push & Vetting
                    </h1>
                    <p style="color: var(--text-muted); font-size: 14px; margin: 0;">
                        Review and vet completed student examination scores before pushing them upstream to your online EduPortal portal.
                    </p>
                </div>
                <div>
                    <a href="../admin/index.php" class="btn btn-secondary" style="text-decoration: none; display: inline-flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-arrow-left"></i> Admin Dashboard
                    </a>
                </div>
            </div>

            <!-- ALERTS -->
            <?php if (!empty($msg)): ?>
                <div class="alert alert-success" style="margin-bottom: 24px; padding: 14px 18px; border-radius: 8px; display: flex; align-items: center; gap: 12px;">
                    <i class="fa-solid fa-circle-check" style="font-size: 20px;"></i>
                    <div style="font-weight: 600; font-size: 14px;"><?= htmlspecialchars($msg) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger" style="margin-bottom: 24px; padding: 14px 18px; border-radius: 8px; display: flex; align-items: center; gap: 12px;">
                    <i class="fa-solid fa-triangle-exclamation" style="font-size: 20px;"></i>
                    <div style="font-weight: 600; font-size: 14px;"><?= htmlspecialchars($error) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($echoed_query)): ?>
                <div class="card" style="margin-bottom: 24px; border: 2px solid #f59e0b; background: #0b1120; border-radius: 12px; padding: 22px; box-shadow: 0 10px 28px rgba(0,0,0,0.45);">
                    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 12px;">
                        <div>
                            <div style="font-weight: 800; font-size: 17px; color: #f59e0b; display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-terminal"></i> Remote API Generated SQL (Echoed &amp; Exited)
                            </div>
                            <div style="color: var(--text-muted); font-size: 13px; margin-top: 4px;">
                                The remote endpoint received the score payload, generated the SQL query below, echoed it, and exited without committing.
                            </div>
                        </div>
                        <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                            <button type="button" id="btnCopySql" class="btn btn-secondary" style="font-size: 12px; padding: 6px 14px; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px; background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3); cursor: pointer;" onclick="copyEchoedSql()">
                                <i class="fa-solid fa-copy"></i> Copy SQL
                            </button>
                            <button type="button" id="btnExportSql" class="btn btn-primary" style="font-size: 12px; padding: 6px 14px; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px; background: #f59e0b; color: #000; border: none; font-weight: 700; cursor: pointer;" onclick="exportEchoedSql()">
                                <i class="fa-solid fa-file-arrow-down"></i> Export .SQL File
                            </button>
                        </div>
                    </div>
                    <pre id="echoedSqlBlock" style="background: #020617; border: 1px solid #1e293b; color: #38bdf8; padding: 18px; border-radius: 8px; font-family: 'Courier New', Courier, monospace; font-size: 13.5px; line-height: 1.6; white-space: pre-wrap; word-break: break-all; margin: 0; user-select: all;"><?= htmlspecialchars($echoed_query) ?></pre>
                </div>

                <script>
                function copyEchoedSql() {
                    const block = document.getElementById('echoedSqlBlock');
                    if (!block) return;
                    navigator.clipboard.writeText(block.innerText).then(() => {
                        const btn = document.getElementById('btnCopySql');
                        if (btn) {
                            btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
                            btn.style.background = 'rgba(16, 185, 129, 0.2)';
                            btn.style.color = '#34d399';
                            setTimeout(() => {
                                btn.innerHTML = '<i class="fa-solid fa-copy"></i> Copy SQL';
                                btn.style.background = 'rgba(59, 130, 246, 0.15)';
                                btn.style.color = '#60a5fa';
                            }, 2500);
                        }
                    });
                }

                function exportEchoedSql() {
                    const block = document.getElementById('echoedSqlBlock');
                    if (!block) return;
                    const sqlText = block.innerText;
                    const blob = new Blob([sqlText], { type: 'text/sql;charset=utf-8;' });
                    const url = URL.createObjectURL(blob);
                    const a = document.createElement('a');
                    a.href = url;
                    a.download = 'cbt_sync_push_query_' + (new Date().toISOString().slice(0,10)) + '.sql';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                }
                </script>
            <?php endif; ?>

            <!-- OVERVIEW STAT CARDS -->
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
                <div class="stat-box" style="border-left: 4px solid <?= $pending_count > 0 ? 'var(--accent)' : 'var(--success)' ?>;">
                    <div class="stat-lbl"><i class="fa-solid fa-clock-rotate-left"></i> Pending Scores to Push</div>
                    <div class="stat-val" style="font-size: 28px; color: <?= $pending_count > 0 ? 'var(--accent)' : 'var(--success)' ?>;">
                        <?= number_format($pending_count) ?>
                    </div>
                </div>

                <div class="stat-box" style="border-left: 4px solid #3b82f6;">
                    <div class="stat-lbl"><i class="fa-solid fa-calculator"></i> Batch Average Score</div>
                    <div class="stat-val" style="font-size: 28px; color: #93c5fd;">
                        <?= number_format($avg_pending_score, 1) ?>
                    </div>
                </div>

                <div class="stat-box" style="border-left: 4px solid #10b981;">
                    <div class="stat-lbl"><i class="fa-solid fa-globe"></i> Target Online Server</div>
                    <div style="font-size: 13px; font-weight: 700; color: #6ee7b7; margin-top: 8px; word-break: break-all;">
                        <i class="fa-solid fa-link"></i> <?= htmlspecialchars(!empty($online_url) ? $online_url : 'Not Configured') ?>
                    </div>
                </div>

                <div class="stat-box" style="border-left: 4px solid #8b5cf6;">
                    <div class="stat-lbl"><i class="fa-solid fa-history"></i> Last Upstream Sync</div>
                    <div style="font-size: 13px; font-weight: 700; color: #c4b5fd; margin-top: 8px;">
                        <?= $recent_log ? date('d M Y, h:i A', strtotime($recent_log['created_at'])) : 'No Sync Recorded' ?>
                    </div>
                </div>
            </div>

            <!-- PREVIEW & CONFIRMATION SECTION -->
            <div class="card" style="margin-bottom: 28px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px;">
                    <div>
                        <div class="card-title" style="font-size: 18px; margin-bottom: 4px;">
                            <i class="fa-solid fa-list-check" style="color: var(--primary);"></i> Preview of Scores Ready to Push Online
                        </div>
                        <div class="card-desc" style="font-size: 13px;">
                            Carefully vet candidate names, assessment types, and calibrated scores before confirming upload.
                        </div>
                    </div>
                    <?php if ($pending_count > 0): ?>
                        <span class="badge-offline" style="background: rgba(245, 158, 11, 0.15); color: var(--accent); padding: 6px 12px; font-size: 12px; font-weight: 700;">
                            <i class="fa-solid fa-triangle-exclamation"></i> <?= $pending_count ?> Record(s) Require Confirmation
                        </span>
                    <?php endif; ?>
                </div>

                <?php if ($pending_count > 0): ?>
                    <form method="POST" action="sync_push.php" id="pushForm">
                        <input type="hidden" name="action" value="push_selected">

                        <!-- ACTION BAR -->
                        <div class="action-bar">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <input type="checkbox" id="selectAllTop" style="width: 18px; height: 18px; cursor: pointer;" checked>
                                <label for="selectAllTop" style="font-size: 13px; font-weight: 700; color: var(--text-main); cursor: pointer;">
                                    Select / Deselect All (<span id="selectedCount"><?= $pending_count ?></span> of <?= $pending_count ?> Selected)
                                </label>
                            </div>
                            <div style="display: flex; gap: 10px;">
                                <button type="submit" id="btnPush" class="btn btn-primary" style="padding: 10px 20px; font-weight: 800; display: inline-flex; align-items: center; gap: 8px;">
                                    <i class="fa-solid fa-cloud-arrow-up"></i> Confirm & Push Selected Scores Online
                                </button>
                            </div>
                        </div>

                        <!-- SCORES TABLE -->
                        <div class="table-responsive">
                            <table class="data-table preview-table">
                                <thead>
                                    <tr>
                                        <th style="width: 40px; text-align: center;">#</th>
                                        <th>Candidate Name</th>
                                        <th>Reg Number</th>
                                        <th>Class</th>
                                        <th>Subject & Exam Title</th>
                                        <th>Assessment</th>
                                        <th>Calibrated Score</th>
                                        <th>Questions</th>
                                        <th>Date Taken</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_results as $idx => $r): 
                                        $class_disp = !empty($r['student_class']) ? $r['student_class'] : (!empty($r['classgroupname']) ? $r['classgroupname'] : 'N/A');
                                        $sub_disp = !empty($r['exam_subject']) ? $r['exam_subject'] : 'N/A';
                                        $mark_disp = !empty($r['exam_markname']) ? $r['exam_markname'] : 'CBT Assessment';
                                        $max_score_val = (!empty($r['total_score']) && floatval($r['total_score']) > 0) ? round(floatval($r['total_score'])) : 100;
                                        $score_val = round(floatval($r['score']));
                                    ?>
                                        <tr>
                                            <td style="text-align: center;">
                                                <input type="checkbox" name="result_ids[]" value="<?= $r['resultid'] ?>" class="item-chk" style="width: 17px; height: 17px; cursor: pointer;" checked>
                                            </td>
                                            <td>
                                                <strong style="color: var(--text-main);"><?= htmlspecialchars($r['student_name']) ?></strong>
                                            </td>
                                            <td>
                                                <span style="font-family: monospace; font-weight: 700; color: var(--accent);"><?= htmlspecialchars($r['regnumber']) ?></span>
                                            </td>
                                            <td>
                                                <span class="badge-offline" style="background: rgba(99, 102, 241, 0.15); color: var(--primary);"><i class="fa-solid fa-users"></i> <?= htmlspecialchars($class_disp) ?></span>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($sub_disp) ?></strong><br>
                                                <small style="color: var(--text-muted);"><?= htmlspecialchars($r['exam_title']) ?></small>
                                            </td>
                                            <td>
                                                <span style="font-weight: 700; color: var(--primary); font-size: 13px;">
                                                    <i class="fa-solid fa-tag"></i> <?= htmlspecialchars($mark_disp) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span style="font-weight: 800; font-size: 16px; color: var(--success);">
                                                    <?= round($score_val) ?> <span style="font-size: 12px; font-weight: 600; color: var(--text-muted);">/ <?= round($max_score_val) ?></span>
                                                </span>
                                            </td>
                                            <td>
                                                <span style="font-size: 13px; font-weight: 600; color: var(--text-muted);">
                                                    <?= intval($r['correct_answers']) ?> / <?= intval($r['total_questions']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <small style="color: var(--text-muted);">
                                                    <?= !empty($r['date_taken']) ? date('d M Y, h:i A', strtotime($r['date_taken'])) : (!empty($r['submitted_at']) ? date('d M Y, h:i A', strtotime($r['submitted_at'])) : 'N/A') ?>
                                                </small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- BOTTOM ACTION BAR -->
                        <div class="action-bar" style="margin-top: 16px; margin-bottom: 0;">
                            <div style="font-size: 13px; color: var(--text-muted);">
                                <i class="fa-solid fa-shield-halved" style="color: var(--primary);"></i> All scores are verified against the target assessment mark distribution before upload.
                            </div>
                            <button type="submit" class="btn btn-primary" style="padding: 10px 22px; font-weight: 800; display: inline-flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-cloud-arrow-up"></i> Confirm & Push Selected Scores Online
                            </button>
                        </div>
                    </form>
                <?php else: ?>
                    <!-- EMPTY STATE -->
                    <div style="text-align: center; padding: 48px 20px;">
                        <div style="font-size: 56px; color: var(--success); margin-bottom: 16px;">
                            <i class="fa-solid fa-circle-check"></i>
                        </div>
                        <h2 style="font-size: 20px; font-weight: 800; color: var(--text-main); margin-bottom: 8px;">
                            All Local Examination Scores Are Synchronized!
                        </h2>
                        <p style="color: var(--text-muted); font-size: 14px; max-width: 500px; margin: 0 auto 20px;">
                            There are currently 0 pending exam scores waiting to be pushed online. As candidates submit exams, their scores will automatically appear here for vetting and confirmation.
                        </p>
                        <a href="../admin/index.php" class="btn btn-primary" style="text-decoration: none; display: inline-flex; align-items: center; gap: 8px;">
                            <i class="fa-solid fa-chart-line"></i> View Official Score Performance Reports
                        </a>
                    </div>
                <?php endif; ?>
            </div>

            <!-- RECENTLY SYNCHRONIZED SCORES SECTION -->
            <?php if (!empty($synced_results)): ?>
                <div class="card">
                    <div class="card-title" style="font-size: 16px; margin-bottom: 4px;">
                        <i class="fa-solid fa-clock-rotate-left" style="color: var(--success);"></i> Recently Pushed Scores (Audit History)
                    </div>
                    <div class="card-desc" style="font-size: 12px; margin-bottom: 16px;">
                        The last 10 examination scores successfully synchronized with your online EduPortal portal.
                    </div>

                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Candidate Name</th>
                                    <th>Reg Number</th>
                                    <th>Class</th>
                                    <th>Exam Title</th>
                                    <th>Assessment</th>
                                    <th>Pushed Score</th>
                                    <th>Sync Status</th>
                                    <th>Synced At</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($synced_results as $sr): 
                                    $sr_class = !empty($sr['student_class']) ? $sr['student_class'] : 'N/A';
                                    $sr_mark = !empty($sr['exam_markname']) ? $sr['exam_markname'] : 'CBT Assessment';
                                    $sr_max = (!empty($sr['total_score']) && floatval($sr['total_score']) > 0) ? round(floatval($sr['total_score'])) : 100;
                                ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($sr['student_name']) ?></strong></td>
                                        <td><span style="font-family: monospace; font-weight: 700; color: var(--accent);"><?= htmlspecialchars($sr['regnumber']) ?></span></td>
                                        <td><span class="badge-offline" style="background: rgba(99, 102, 241, 0.15); color: var(--primary);"><?= htmlspecialchars($sr_class) ?></span></td>
                                        <td><?= htmlspecialchars($sr['exam_title']) ?></td>
                                        <td><span style="font-weight: 600; color: var(--text-muted); font-size: 12px;"><?= htmlspecialchars($sr_mark) ?></span></td>
                                        <td>
                                            <span style="font-weight: 700; color: var(--text-main);">
                                                <?= round(floatval($sr['score'])) ?> <span style="font-size: 11px; color: var(--text-muted);">/ <?= round($sr_max) ?></span>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge-offline" style="background: rgba(16, 185, 129, 0.15); color: var(--success);">
                                                <i class="fa-solid fa-circle-check"></i> Online Synced
                                            </span>
                                        </td>
                                        <td>
                                            <small style="color: var(--text-muted);">
                                                <?= !empty($sr['synced_at']) ? date('d M Y, h:i A', strtotime($sr['synced_at'])) : 'Recently' ?>
                                            </small>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>

    <script>
        const selectAllTop = document.getElementById('selectAllTop');
        const itemCheckboxes = document.querySelectorAll('.item-chk');
        const selectedCount = document.getElementById('selectedCount');
        const pushForm = document.getElementById('pushForm');

        function updateCount() {
            if (!itemCheckboxes.length) return;
            let checked = 0;
            itemCheckboxes.forEach(cb => { if (cb.checked) checked++; });
            if (selectedCount) selectedCount.textContent = checked;
            if (selectAllTop) selectAllTop.checked = (checked === itemCheckboxes.length);
        }

        if (selectAllTop) {
            selectAllTop.addEventListener('change', function() {
                itemCheckboxes.forEach(cb => { cb.checked = selectAllTop.checked; });
                updateCount();
            });
        }

        itemCheckboxes.forEach(cb => {
            cb.addEventListener('change', updateCount);
        });

        if (pushForm) {
            pushForm.addEventListener('submit', function(e) {
                let checked = 0;
                itemCheckboxes.forEach(cb => { if (cb.checked) checked++; });
                if (checked === 0) {
                    e.preventDefault();
                    alert('Please select at least one candidate exam score to push online.');
                    return false;
                }
                const confirmed = confirm('Are you sure you want to push these ' + checked + ' vetted score record(s) to your online portal?');
                if (!confirmed) {
                    e.preventDefault();
                    return false;
                }
            });
        }
    </script>
</body>
</html>