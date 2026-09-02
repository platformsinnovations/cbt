<?php
require_once __DIR__ . '/../config/config.php';

require_admin_login();

$school = get_school_config();
$conn = get_db_connection();
$schoolid = $school['schoolid'];
$online_url = rtrim($school['online_url'], '/');
$admin = $_SESSION['cbt_admin'];

$msg = '';
$error = '';

if (isset($_GET['execute']) || isset($_GET['auto'])) {
    $res = $conn->query("SELECT * FROM tbl_cbt_results WHERE sync_status = 0");
    if ($res && $res->num_rows > 0) {
        $results_payload = [];
        $result_ids = [];
        
        while ($r = $res->fetch_assoc()) {
            $results_payload[] = [
                'studentid' => $r['studentid'],
                'classgroupid' => $r['classgroupid'],
                'classid' => $r['classid'],
                'subjectid' => $r['subjectid'],
                'markdistid' => $r['markdistid'],
                'score' => doubleval($r['score']),
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
        curl_close($ch);

        $data = json_decode($response, true);

        if (!$data || !isset($data['status']) || $data['status'] !== 'success') {
            $err_msg = isset($data['message']) ? $data['message'] : "Failed to connect to online API endpoint ($apiUrl).";
            $error = "API Score Push Error: " . $err_msg;
        } else {
            $pushed_count = intval($data['pushed_count']);
            
            if (!empty($result_ids)) {
                $id_list = implode(',', $result_ids);
                $conn->query("UPDATE tbl_cbt_results SET sync_status = 1, synced_at = NOW() WHERE resultid IN ($id_list)");
            }

            $log_msg = "Successfully pushed $pushed_count exam score records to online portal via REST API.";
            $conn->query("INSERT INTO tbl_cbt_sync_logs (sync_type, status, records_affected, message) VALUES ('PUSH_API', 'SUCCESS', $pushed_count, '$log_msg')");
            $msg = $log_msg;
        }
    } else {
        $msg = "All local exam results are already up to date and synchronized with the online portal.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upstream Score Push | Offline CBT</title>
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
                    <a href="sync_pull.php" class="sidebar-link">
                        <i class="fa-solid fa-cloud-arrow-down"></i> Pull Online Data
                    </a>
                    <a href="sync_push.php" class="sidebar-link active">
                        <i class="fa-solid fa-cloud-arrow-up"></i> Push Scores Online
                    </a>
                    <a href="../admin/reset.php" class="sidebar-link">
                        <i class="fa-solid fa-rotate"></i> Reset Data
                    </a>
                    <a href="../admin/login.php?logout=1" class="sidebar-link" style="color: var(--danger); margin-top: 8px;">
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
                <div class="card-title"><i class="fa-solid fa-cloud-arrow-up"></i> REST API Upstream Score Push</div>
                <div class="card-desc">Batch upload local student CBT examination scores back to your online Eduportal portal via REST API.</div>

                <?php if (!empty($msg)): ?>
                    <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($msg) ?></div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
                <?php endif; ?>

                <a href="sync_push.php?execute=1" class="btn btn-primary" style="width: 100%; text-decoration: none;">
                    <i class="fa-solid fa-upload"></i> Push Scores Online via REST API Now
                </a>

                <div style="margin-top: 16px; text-align: center;">
                    <a href="../admin/index.php" style="color: var(--text-muted); text-decoration: none; font-size: 14px;"><i class="fa-solid fa-arrow-left"></i> Return to Admin Dashboard</a>
                </div>
            </div>
        </main>
    </div>
</body>
</html>