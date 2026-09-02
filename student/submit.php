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
$res_chk = $conn->query("SELECT * FROM tbl_cbt_results WHERE studentid = '" . $student['studentid'] . "' AND examid = '$examid'");
if ($res_chk && $res_chk->num_rows > 0) {
    $existing = $res_chk->fetch_assoc();
    $score_percentage = $existing['score'];
} else {
    // Auto-Grade Exam Responses
    $res_q = $conn->query("SELECT questionid, correct_option, mark FROM tbl_cbt_questions WHERE examid = '$examid'");
    $total_possible_marks = 0;
    $earned_marks = 0;

    if ($res_q && $res_q->num_rows > 0) {
        while ($q = $res_q->fetch_assoc()) {
            $qid = $q['questionid'];
            $mark = intval($q['mark']) > 0 ? intval($q['mark']) : 1;
            $correct = strtoupper(trim($q['correct_option']));
            $total_possible_marks += $mark;

            // Fetch student answer
            $res_ans = $conn->query("SELECT selected_option FROM tbl_cbt_responses WHERE studentid = '" . $student['studentid'] . "' AND examid = '$examid' AND questionid = '$qid' LIMIT 1");
            if ($res_ans && $res_ans->num_rows > 0) {
                $user_opt = strtoupper(trim($res_ans->fetch_assoc()['selected_option']));
                if ($user_opt === $correct) {
                    $earned_marks += $mark;
                }
            }
        }
    }

    $score_actual = floatval($earned_marks);
    $total_actual = floatval($total_possible_marks > 0 ? $total_possible_marks : 10);
    $score_percentage = ($total_actual > 0) ? round(($earned_marks / $total_actual) * 100, 2) : 0.00;

    $s_name = trim($student['lastname'] . ' ' . $student['firstname'] . ' ' . (isset($student['middlename']) ? $student['middlename'] : ''));
    $cgid = !empty($exam['classgroupid']) ? $exam['classgroupid'] : (!empty($student['classgroupid']) ? $student['classgroupid'] : '');
    $cid = $student['classid'];
    $subid = $exam['subjectid'];
    $mdid = !empty($exam['markdistid']) ? $exam['markdistid'] : 'CBT_FINAL';
    $time_spent = isset($_POST['time_spent']) ? intval($_POST['time_spent']) : 0;

    // Delete existing result if student retakes exam
    $stmt_del = $conn->prepare("DELETE FROM tbl_cbt_results WHERE studentid = ? AND examid = ?");
    if ($stmt_del) {
        $stmt_del->bind_param("ss", $student['studentid'], $examid);
        $stmt_del->execute();
    }

    $stmt = $conn->prepare("INSERT INTO tbl_cbt_results (studentid, regnumber, student_name, examid, exam_title, classgroupid, classid, subjectid, markdistid, score, total_score, total_questions, correct_answers, time_spent_seconds, date_taken, sync_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 0)");
    if ($stmt) {
        $stmt->bind_param("ssssssssddiiii", 
            $student['studentid'], 
            $student['regnumber'], 
            $s_name, 
            $examid, 
            $exam['title'], 
            $cgid, 
            $cid, 
            $subid, 
            $mdid, 
            $score_actual, 
            $total_actual, 
            $total_possible_marks, 
            $earned_marks, 
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
            <h1 class="card-title">Examination Completed!</h1>
            <p class="card-desc">Your answers for <strong><?= htmlspecialchars($exam['title']) ?></strong> have been auto-graded and saved locally.</p>

            <div class="stat-box" style="margin: 24px 0;">
                <div class="stat-lbl">Final Score Percentage</div>
                <div class="stat-val" style="font-size: 48px; color: <?= $score_percentage >= $exam['passmark'] ? 'var(--success)' : 'var(--danger)' ?>;">
                    <?= number_format($score_percentage, 1) ?>%
                </div>
                <div style="margin-top: 8px; font-weight: 600;">
                    <?php if ($score_percentage >= $exam['passmark']): ?>
                        <span style="color: var(--success);"><i class="fa-solid fa-award"></i> PASSED (Pass Mark: <?= $exam['passmark'] ?>%)</span>
                    <?php else: ?>
                        <span style="color: var(--danger);"><i class="fa-solid fa-circle-xmark"></i> PASS MARK NOT MET (<?= $exam['passmark'] ?>%)</span>
                    <?php endif; ?>
                </div>
            </div>

            <a href="select_exam.php" class="btn btn-primary" style="width: 100%; text-decoration: none;">
                <i class="fa-solid fa-arrow-left"></i> Return to Exam Dashboard
            </a>
        </div>
    </div>
</body>
</html>