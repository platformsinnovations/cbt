<?php
require_once __DIR__ . '/../config/config.php';

if (!session_id()) session_start();
if (!isset($_SESSION['cbt_student'])) {
    header("Location: login.php");
    exit;
}

$student = $_SESSION['cbt_student'];
$conn = get_db_connection();

$examid = isset($_REQUEST['examid']) ? trim($_REQUEST['examid']) : '';
if (empty($examid)) {
    header("Location: select_exam.php");
    exit;
}

// Fetch Exam
$res_ex = $conn->query("SELECT * FROM tbl_cbt_exams WHERE examid = '$examid' LIMIT 1");
if (!$res_ex || $res_ex->num_rows === 0) {
    header("Location: select_exam.php");
    exit;
}
$exam = $res_ex->fetch_assoc();

// Check if result already submitted
$res_chk = $conn->query("SELECT * FROM tbl_cbt_results WHERE studentid = '" . $conn->real_escape_string($student['studentid']) . "' AND examid = '" . $conn->real_escape_string($examid) . "' LIMIT 1");
if ($res_chk && $res_chk->num_rows > 0) {
    $existing = $res_chk->fetch_assoc();
    $score = round(floatval($existing['score']));
    $total_score = !empty($existing['total_score']) && floatval($existing['total_score']) > 0 ? round(floatval($existing['total_score'])) : 100;
    $earned_marks = isset($existing['correct_answers']) ? intval($existing['correct_answers']) : 0;
    $total_questions = isset($existing['total_questions']) ? intval($existing['total_questions']) : 0;
    $is_already_submitted = true;
} else {
    $is_already_submitted = false;

    // 1. Determine Mark Distribution Score (Weight)
    $mark_dist_score = 0;
    if (!empty($exam['markdistid'])) {
        $mdid_esc = $conn->real_escape_string($exam['markdistid']);
        $cgid_esc = !empty($exam['classgroupid']) ? $conn->real_escape_string($exam['classgroupid']) : '';
        
        $md_q = $conn->query("SELECT markscore, markname FROM tbl_cbt_mark_distributions WHERE markdistid = '$mdid_esc' AND (classgroupid = '$cgid_esc' OR classgroupid = '') LIMIT 1");
        if (!$md_q || $md_q->num_rows === 0) {
            $md_q = $conn->query("SELECT markscore, markname FROM tbl_cbt_mark_distributions WHERE markdistid = '$mdid_esc' LIMIT 1");
        }
        if ($md_q && $md_q->num_rows > 0) {
            $md_row = $md_q->fetch_assoc();
            if (floatval($md_row['markscore']) > 0) {
                $mark_dist_score = floatval($md_row['markscore']);
            }
        }
    }

    // Fallback: check if markname contains number e.g. "CA 1 (20 marks)"
    if ($mark_dist_score <= 0 && !empty($exam['markname'])) {
        if (preg_match('/(\d+)\s*(?:marks?|pts?|points?)/i', $exam['markname'], $matches)) {
            $mark_dist_score = floatval($matches[1]);
        }
    }

    if ($mark_dist_score <= 0) {
        $mark_dist_score = 100.0;
    }

    // 2. Auto-Grade Exam Responses
    $res_q = $conn->query("SELECT questionid, correct_option, mark FROM tbl_cbt_questions WHERE examid = '$examid'");
    $total_questions = 0;
    $total_possible_marks = 0;
    $earned_marks = 0;
    $correct_count = 0;

    if ($res_q && $res_q->num_rows > 0) {
        while ($q = $res_q->fetch_assoc()) {
            $qid = $q['questionid'];
            $mark = intval($q['mark']) > 0 ? intval($q['mark']) : 1;
            $correct = strtoupper(trim($q['correct_option']));
            $total_questions++;
            $total_possible_marks += $mark;

            // Fetch student answer
            $res_ans = $conn->query("SELECT selected_option FROM tbl_cbt_responses WHERE studentid = '" . $student['studentid'] . "' AND examid = '$examid' AND questionid = '$qid' LIMIT 1");
            if ($res_ans && $res_ans->num_rows > 0) {
                $user_opt = strtoupper(trim($res_ans->fetch_assoc()['selected_option']));
                if ($user_opt === $correct) {
                    $correct_count++;
                    $earned_marks += $mark;
                }
            }
        }
    }

    // 3. Calibrate Score: (Total Correct / Total Questions) X Mark Distribution Score
    $q_base = ($total_questions > 0) ? $total_questions : 1;
    $calibrated_score = round(($correct_count / $q_base) * $mark_dist_score);
    $total_score = round($mark_dist_score);
    $score = $calibrated_score;
    $earned_marks = $correct_count;

    $s_name = trim($student['lastname'] . ' ' . $student['firstname'] . ' ' . (isset($student['middlename']) ? $student['middlename'] : ''));
    $cgid = !empty($student['classgroupid']) ? $student['classgroupid'] : (!empty($exam['classgroupid']) ? $exam['classgroupid'] : '');
    $cid = !empty($student['classid']) ? $student['classid'] : (!empty($exam['classid']) ? $exam['classid'] : '');
    $subid = $exam['subjectid'];
    $mdid = !empty($exam['markdistid']) ? $exam['markdistid'] : 'CBT_FINAL';
    $sessid = !empty($exam['sessionid']) ? $exam['sessionid'] : '';
    $trmid = !empty($exam['termid']) ? $exam['termid'] : '';
    $time_spent = isset($_POST['time_spent']) ? intval($_POST['time_spent']) : 0;

    $stmt = $conn->prepare("INSERT INTO tbl_cbt_results (studentid, regnumber, student_name, examid, exam_title, sessionid, termid, classgroupid, classid, subjectid, markdistid, score, total_score, total_questions, correct_answers, time_spent_seconds, date_taken, status, sync_status, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'COMPLETED', 0, NOW())");
    if ($stmt) {
        $stmt->bind_param("sssssssssssddiii", 
            $student['studentid'], 
            $student['regnumber'], 
            $s_name, 
            $examid, 
            $exam['title'], 
            $sessid,
            $trmid,
            $cgid, 
            $cid, 
            $subid, 
            $mdid, 
            $calibrated_score, 
            $total_score, 
            $total_questions, 
            $correct_count, 
            $time_spent
        );
        $stmt->execute();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Exam Submission Completed | Student Portal</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="centered-page" style="display: flex; flex-direction: column; justify-content: center; align-items: center; min-height: 100vh; margin: 0 auto; width: 100%; padding: 40px 20px; box-sizing: border-box;">
    <div class="container" style="max-width: 600px; width: 100%; margin: 0 auto;">
        <div class="card" style="text-align: center;">
            <div style="font-size: 64px; color: var(--success); margin-bottom: 16px;">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <h1 class="card-title"><?= $is_already_submitted ? 'Examination Already Completed!' : 'Examination Completed!' ?></h1>
            <p class="card-desc">
                <?= $is_already_submitted 
                    ? 'You have already taken and submitted <strong>' . htmlspecialchars($exam['title']) . '</strong>. Retakes are not permitted.' 
                    : 'Your answers for <strong>' . htmlspecialchars($exam['title']) . '</strong> have been auto-graded and saved locally.' ?>
            </p>

            <div class="stat-box" style="margin: 24px 0;">
                <div class="stat-lbl">Final Calibrated Score</div>
                <div class="stat-val" style="font-size: 44px; color: var(--primary); font-weight: 800; margin: 6px 0;">
                    <?= round($score) ?> <span style="font-size: 22px; color: var(--text-muted); font-weight: 600;">/ <?= round($total_score) ?></span>
                </div>
                <?php if ($total_questions > 0): ?>
                    <div style="font-size: 14px; color: var(--text-muted); margin-top: 6px;">
                        Answered correctly: <strong style="color: var(--text-main);"><?= $earned_marks ?> / <?= $total_questions ?></strong> questions
                    </div>
                <?php endif; ?>
                <div style="margin-top: 10px; font-weight: 600;">
                    <span style="color: var(--success);"><i class="fa-solid fa-circle-check"></i> <?= $is_already_submitted ? 'Exam Score Recorded — Retakes Not Permitted' : 'Examination Successfully Submitted' ?></span>
                </div>
            </div>

            <a href="select_exam.php" class="btn btn-primary" style="width: 100%; text-decoration: none;">
                <i class="fa-solid fa-arrow-left"></i> Return to Exam Dashboard
            </a>
        </div>
    </div>
</body>
</html>