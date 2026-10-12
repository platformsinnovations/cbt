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

$cid = $conn->real_escape_string($student['classid']);
$cgid = !empty($student['classgroupid']) ? $conn->real_escape_string($student['classgroupid']) : '';

// Fetch Active Exams matching Student Class Group or Class Arm that have questions in the questions bank
$res_exams = $conn->query("SELECT * FROM tbl_cbt_exams WHERE (classgroupid = '$cgid' OR classid = '$cid' OR classgroupid IS NULL OR classgroupid = '') AND is_active = 1 AND EXISTS (SELECT 1 FROM tbl_cbt_questions WHERE tbl_cbt_questions.examid = tbl_cbt_exams.examid) ORDER BY exam_date ASC, start_time ASC, date_created DESC");
$exams = [];
$now_ts = time();

if ($res_exams) {
    while ($ex = $res_exams->fetch_assoc()) {
        // Resolve Target Mark Distribution Score for this Exam
        $mark_dist_score = 0;
        if (!empty($ex['markdistid'])) {
            $mdid_esc = $conn->real_escape_string($ex['markdistid']);
            $cgid_esc = !empty($ex['classgroupid']) ? $conn->real_escape_string($ex['classgroupid']) : (!empty($student['classgroupid']) ? $conn->real_escape_string($student['classgroupid']) : '');
            $md_q = $conn->query("SELECT markscore FROM tbl_cbt_mark_distributions WHERE markdistid = '$mdid_esc' AND (classgroupid = '$cgid_esc' OR classgroupid = '') LIMIT 1");
            if (!$md_q || $md_q->num_rows === 0) {
                $md_q = $conn->query("SELECT markscore FROM tbl_cbt_mark_distributions WHERE markdistid = '$mdid_esc' LIMIT 1");
            }
            if ($md_q && $md_q->num_rows > 0) {
                $md_row = $md_q->fetch_assoc();
                if (floatval($md_row['markscore']) > 0) {
                    $mark_dist_score = floatval($md_row['markscore']);
                }
            }
        }
        if ($mark_dist_score <= 0 && !empty($ex['markname'])) {
            if (preg_match('/(\d+)\s*(?:marks?|pts?|points?)/i', $ex['markname'], $matches)) {
                $mark_dist_score = floatval($matches[1]);
            }
        }

        // Check if student already submitted this exam
        $check_res = $conn->query("SELECT resultid, score, total_score, total_questions, correct_answers, date_taken, submitted_at FROM tbl_cbt_results WHERE studentid = '" . $conn->real_escape_string($student['studentid']) . "' AND examid = '" . $conn->real_escape_string($ex['examid']) . "' LIMIT 1");
        $res_row = ($check_res && $check_res->num_rows > 0) ? $check_res->fetch_assoc() : null;
        $ex['completed'] = ($res_row !== null);

        if ($mark_dist_score <= 0) {
            $mark_dist_score = ($ex['completed'] && !empty($res_row['total_score']) && floatval($res_row['total_score']) > 0) ? floatval($res_row['total_score']) : 100.0;
        }

        if ($ex['completed']) {
            $c_ans = isset($res_row['correct_answers']) ? intval($res_row['correct_answers']) : 0;
            $t_qst = (isset($res_row['total_questions']) && intval($res_row['total_questions']) > 0) 
                        ? intval($res_row['total_questions']) 
                        : (intval($ex['total_questions']) > 0 ? intval($ex['total_questions']) : 1);
            
            // Final score calculated as (Total Correct / Total Questions) * Mark Distribution Score
            $score_val = round(($c_ans / $t_qst) * $mark_dist_score);
            $max_val = round($mark_dist_score);
            $pct_val = round(($c_ans / $t_qst) * 100, 2);

            $ex['user_score'] = $score_val;
            $ex['user_max_score'] = $max_val;
            $ex['user_percentage'] = $pct_val;
            $ex['correct_answers'] = $c_ans;
            $ex['result_total_q'] = $t_qst;
            $ex['date_completed'] = !empty($res_row['date_taken']) ? $res_row['date_taken'] : $res_row['submitted_at'];
        } else {
            $ex['user_score'] = null;
            $ex['user_max_score'] = round($mark_dist_score);
            $ex['user_percentage'] = 0;
            $ex['correct_answers'] = 0;
            $ex['result_total_q'] = intval($ex['total_questions']);
            $ex['date_completed'] = null;
        }

        // Calculate schedule timestamps
        $ex_date = !empty($ex['exam_date']) ? $ex['exam_date'] : date('Y-m-d', strtotime($ex['date_created']));
        $end_date = !empty($ex['end_date']) ? $ex['end_date'] : $ex_date;
        $start_time = !empty($ex['start_time']) ? $ex['start_time'] : '00:00:00';
        $end_time = !empty($ex['end_time']) ? $ex['end_time'] : '23:59:59';

        $start_ts = strtotime($ex_date . ' ' . $start_time);
        $end_ts = strtotime($end_date . ' ' . $end_time);

        $ex['start_ts'] = $start_ts;
        $ex['end_ts'] = $end_ts;

        if ($ex['completed']) {
            $ex['timing_status'] = 'completed'; // Student took exam -> shows score
        } elseif ($now_ts < $start_ts) {
            $ex['timing_status'] = 'upcoming';  // Date of exam not yet reached -> shows Upcoming Exam
        } elseif ($now_ts > $end_ts) {
            $ex['timing_status'] = 'past';      // Did not take exam within specified date and time -> shows Past Exam
        } else {
            $ex['timing_status'] = 'active';    // Within specified date and time duration -> can start
        }

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
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px; gap: 8px;">
                                <h3 style="font-size: 18px; font-weight: 700; color: var(--text-main); line-height: 1.3;">
                                    <?= htmlspecialchars($ex['title']) ?>
                                </h3>
                                <?php if ($ex['timing_status'] === 'completed'): ?>
                                    <span class="badge-offline" style="background: rgba(16, 185, 129, 0.2); color: var(--success); white-space: nowrap;">
                                        <i class="fa-solid fa-circle-check"></i> Completed
                                    </span>
                                <?php elseif ($ex['timing_status'] === 'upcoming'): ?>
                                    <span class="badge-offline" style="background: rgba(59, 130, 246, 0.2); color: #60a5fa; white-space: nowrap;">
                                        <i class="fa-solid fa-calendar-clock"></i> Upcoming Exam
                                    </span>
                                <?php elseif ($ex['timing_status'] === 'past'): ?>
                                    <span class="badge-offline" style="background: rgba(239, 68, 68, 0.2); color: var(--danger); white-space: nowrap;">
                                        <i class="fa-solid fa-clock-rotate-left"></i> Past Exam
                                    </span>
                                <?php else: ?>
                                    <span class="badge-offline" style="background: rgba(16, 185, 129, 0.2); color: var(--success); white-space: nowrap;">
                                        <i class="fa-solid fa-bolt"></i> Active Now
                                    </span>
                                <?php endif; ?>
                            </div>

                            <div style="font-size: 14px; color: var(--text-muted); margin-bottom: 14px;">
                                Subject: <strong style="color: var(--text-main);"><?= htmlspecialchars($ex['subjectname']) ?></strong><br>
                                Target Assessment: <strong style="color: var(--accent);"><?= !empty($ex['markname']) ? htmlspecialchars($ex['markname']) : 'CBT Assessment' ?></strong>
                            </div>

                            <!-- SCHEDULED DATE & TIME DURATION WINDOW -->
                            <div style="background: rgba(15, 23, 42, 0.6); border: 1px solid var(--border-color); border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; font-size: 13px;">
                                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px; color: var(--text-main);">
                                    <i class="fa-solid fa-calendar-day" style="color: var(--primary);"></i>
                                    <strong>Exam Date:</strong> 
                                    <span><?= date('D, M d, Y', $ex['start_ts']) ?><?= (!empty($ex['end_date']) && $ex['end_date'] !== $ex['exam_date']) ? ' to ' . date('D, M d, Y', $ex['end_ts']) : '' ?></span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 8px; color: var(--text-muted);">
                                    <i class="fa-solid fa-clock" style="color: var(--accent);"></i>
                                    <strong>Time Window:</strong>
                                    <span style="color: var(--text-main); font-weight: 600;"><?= date('h:i A', $ex['start_ts']) ?> - <?= date('h:i A', $ex['end_ts']) ?></span>
                                </div>
                            </div>

                            <div class="grid-2" style="gap: 10px; margin-bottom: 20px;">
                                <div class="stat-box" style="padding: 10px;">
                                    <div class="stat-val" style="font-size: 18px;"><?= $ex['duration_minutes'] ?> Mins</div>
                                    <div class="stat-lbl">Exam Duration</div>
                                </div>
                                <div class="stat-box" style="padding: 10px;">
                                    <div class="stat-val" style="font-size: 18px;"><?= $ex['total_questions'] ?> Qs</div>
                                    <div class="stat-lbl">Questions</div>
                                </div>
                            </div>
                        </div>

                        <div>
                            <?php if ($ex['timing_status'] === 'completed'): ?>
                                <div style="text-align: center; padding: 14px 12px; background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.3); border-radius: 10px;">
                                    <div style="font-size: 11px; font-weight: 700; color: #34d399; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 3px;">
                                        <i class="fa-solid fa-square-poll-vertical"></i> Exam Score
                                    </div>
                                    <div style="font-size: 26px; font-weight: 800; color: var(--success); line-height: 1.1; margin: 4px 0;">
                                        <?= round($ex['user_score']) ?> <span style="font-size: 16px; color: var(--text-muted); font-weight: 600;">/ <?= round($ex['user_max_score']) ?></span>
                                    </div>
                                    <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 4px;">
                                        Correct: <strong style="color: var(--text-main);"><?= $ex['correct_answers'] ?> / <?= $ex['result_total_q'] ?></strong> questions
                                    </div>
                                    <?php if (!empty($ex['date_completed'])): ?>
                                        <div style="font-size: 11px; color: var(--text-muted);">
                                            Submitted on <?= date('d M Y, h:i A', strtotime($ex['date_completed'])) ?>
                                        </div>
                                    <?php endif; ?>
                                    <div style="margin-top: 8px; font-size: 12px; font-weight: 700; color: var(--success); display: flex; align-items: center; justify-content: center; gap: 6px;">
                                        <i class="fa-solid fa-circle-check"></i> Completed — Retakes Not Permitted
                                    </div>
                                </div>
                            <?php elseif ($ex['timing_status'] === 'upcoming'): ?>
                                <div style="text-align: center; padding: 12px; background: rgba(59, 130, 246, 0.08); border: 1px dashed rgba(59, 130, 246, 0.4); border-radius: 8px; font-size: 13px; color: #93c5fd;">
                                    <i class="fa-solid fa-hourglass-start"></i> <strong>Upcoming Exam</strong><br>
                                    <span>Opens on <?= date('M d, Y \a\t h:i A', $ex['start_ts']) ?></span>
                                </div>
                            <?php elseif ($ex['timing_status'] === 'past'): ?>
                                <div style="text-align: center; padding: 12px; background: rgba(239, 68, 68, 0.08); border: 1px dashed rgba(239, 68, 68, 0.3); border-radius: 8px; font-size: 13px; color: #f87171;">
                                    <i class="fa-solid fa-clock-rotate-left"></i> <strong>Past Exam</strong><br>
                                    <span>Closed on <?= date('M d, Y \a\t h:i A', $ex['end_ts']) ?></span>
                                </div>
                            <?php else: ?>
                                <a href="exam.php?examid=<?= $ex['examid'] ?>" class="btn btn-primary" style="width: 100%; text-decoration: none; text-align: center; padding: 12px; font-weight: 700; display: inline-flex; align-items: center; justify-content: center; gap: 8px;">
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