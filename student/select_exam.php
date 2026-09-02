<?php
require_once __DIR__ . '/../config/config.php';

if (!session_id()) session_start();
if (!isset($_SESSION['cbt_student'])) {
    header("Location: login.php");
    exit;
}

$student = $_SESSION['cbt_student'];
$conn = get_db_connection();
$school = get_school_config();
$school_name = $school ? $school['schoolname'] : 'EduPortal CBT';

$cid = $student['classid'];
$cgid = !empty($student['classgroupid']) ? $student['classgroupid'] : '';

// Fetch Active Exams matching Student Class Group or Class Arm
$res_exams = $conn->query("SELECT * FROM tbl_cbt_exams WHERE (classgroupid = '$cgid' OR classid = '$cid' OR classgroupid IS NULL OR classgroupid = '') AND is_active = 1 ORDER BY date_created DESC");
$exams = [];
if ($res_exams) {
    while ($ex = $res_exams->fetch_assoc()) {
        // Check if student already submitted this exam
        $check_res = $conn->query("SELECT resultid, score FROM tbl_cbt_results WHERE studentid = '" . $student['studentid'] . "' AND examid = '" . $ex['examid'] . "'");
        $ex['completed'] = ($check_res && $check_res->num_rows > 0);
        $ex['user_score'] = $ex['completed'] ? $check_res->fetch_assoc()['score'] : null;
        $exams[] = $ex;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select CBT Examination | <?= htmlspecialchars($school_name) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body style="display: block; padding: 20px 0;">
    <div class="container" style="max-width: 900px; margin: 0 auto; padding: 0 20px;">
        <!-- CANDIDATE & SCHOOL PROFILE HEADER CARD -->
        <div class="card" style="margin-bottom: 24px; padding: 28px 32px; background: rgba(17, 24, 39, 0.85); border: 1px solid var(--border-color);">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
                <div>
                    <div style="font-size: 13px; font-weight: 700; color: var(--primary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                        <i class="fa-solid fa-graduation-cap"></i> <?= htmlspecialchars($school_name) ?>
                    </div>
                    <h1 style="font-size: 24px; font-weight: 800; color: var(--text-main); margin-bottom: 8px; line-height: 1.2;">
                        Welcome, <?= htmlspecialchars($student['lastname'] . ' ' . $student['firstname'] . ' ' . $student['middlename']) ?>
                    </h1>
                    <div style="display: flex; gap: 20px; font-size: 14px; color: var(--text-muted); flex-wrap: wrap;">
                        <span><i class="fa-solid fa-users" style="color: var(--accent);"></i> Class: <strong style="color: var(--text-main); font-weight: 700;"><?= htmlspecialchars($student['classname']) ?></strong></span>
                        <span><i class="fa-solid fa-id-card" style="color: var(--primary);"></i> Reg No: <strong style="color: var(--accent); font-family: monospace; font-weight: 700;"><?= htmlspecialchars($student['regnumber']) ?></strong></span>
                    </div>
                </div>
                <div>
                    <a href="login.php?logout=1" class="btn btn-danger" style="padding: 10px 18px; font-size: 14px; font-weight: 700; text-decoration: none; display: inline-flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-right-from-bracket"></i> Logout
                    </a>
                </div>
            </div>
        </div>

        <div class="card" style="margin-bottom: 24px;">
            <h2 class="card-title" style="font-size: 20px;"><i class="fa-solid fa-book-open" style="color: var(--primary);"></i> Available Computer-Based Examinations</h2>
            <p class="card-desc">Select an active exam below to launch your CBT session. Ensure your computer remains connected to the local network server.</p>
        </div>

        <?php if (!empty($exams)): ?>
            <div class="grid-2" style="gap: 20px;">
                <?php foreach ($exams as $ex): ?>
                    <div class="card" style="display: flex; flex-direction: column; justify-content: space-between;">
                        <div>
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                                <h3 style="font-size: 18px; font-weight: 700; color: var(--text-main); line-height: 1.3;">
                                    <?= htmlspecialchars($ex['title']) ?>
                                </h3>
                                <?php if ($ex['completed']): ?>
                                    <span class="badge-offline" style="background: rgba(16, 185, 129, 0.2); color: var(--success); white-space: nowrap;">
                                        <i class="fa-solid fa-circle-check"></i> Completed
                                    </span>
                                <?php else: ?>
                                    <span class="badge-offline" style="white-space: nowrap;">
                                        <i class="fa-solid fa-clock"></i> Active
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div style="font-size: 14px; color: var(--text-muted); margin-bottom: 16px;">
                                Subject: <strong style="color: var(--text-main);"><?= htmlspecialchars($ex['subjectname']) ?></strong><br>
                                Target Assessment: <strong style="color: var(--accent);"><?= !empty($ex['markname']) ? htmlspecialchars($ex['markname']) : 'CBT Assessment' ?></strong>
                            </div>

                            <div class="grid-2" style="gap: 10px; margin-bottom: 20px;">
                                <div class="stat-box" style="padding: 10px;">
                                    <div class="stat-val" style="font-size: 18px;"><?= $ex['duration_minutes'] ?> Mins</div>
                                    <div class="stat-lbl">Duration</div>
                                </div>
                                <div class="stat-box" style="padding: 10px;">
                                    <div class="stat-val" style="font-size: 18px;"><?= $ex['total_questions'] ?> Qs</div>
                                    <div class="stat-lbl">Questions</div>
                                </div>
                            </div>
                        </div>

                        <div>
                            <?php if ($ex['completed']): ?>
                                <div style="text-align: center; padding: 12px; background: rgba(255,255,255,0.04); border-radius: 8px; font-size: 15px;">
                                    Your Score: <strong style="font-size: 20px; color: var(--success);"><?= number_format($ex['user_score'], 1) ?>%</strong>
                                </div>
                            <?php else: ?>
                                <a href="exam.php?examid=<?= $ex['examid'] ?>" class="btn btn-primary" style="width: 100%; text-decoration: none; text-align: center; padding: 12px;">
                                    <i class="fa-solid fa-play"></i> Start Examination Now
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="card" style="text-align: center; padding: 48px;">
                <i class="fa-solid fa-folder-open" style="font-size: 48px; color: var(--text-muted); margin-bottom: 16px;"></i>
                <h2>No Active CBT Exams Found</h2>
                <p style="color: var(--text-muted); max-width: 400px; margin: 8px auto 0;">There are currently no active exams configured for your class group. Please contact your exam invigilator.</p>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>