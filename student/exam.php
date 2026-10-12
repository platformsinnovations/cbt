<?php
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['cbt_student'])) {
    header("Location: login.php");
    exit;
}

$student = $_SESSION['cbt_student'];
$conn = get_db_connection();
$school = get_school_config();
$school_name = $school ? $school['schoolname'] : 'EduPortal CBT';

$examid = isset($_GET['examid']) ? trim($_GET['examid']) : '';
if (empty($examid)) {
    header("Location: select_exam.php");
    exit;
}

// Fetch Exam
$stmt_ex = $conn->prepare("SELECT * FROM tbl_cbt_exams WHERE examid = ? AND is_active = 1");
$stmt_ex->bind_param("s", $examid);
$stmt_ex->execute();
$exam = $stmt_ex->get_result()->fetch_assoc();

if (!$exam) {
    die("Selected exam is not active or available.");
}

// Check if student already completed this exam
$chk_sub = $conn->query("SELECT score, total_score, total_questions, correct_answers, date_taken, submitted_at FROM tbl_cbt_results WHERE studentid = '" . $conn->real_escape_string($student['studentid']) . "' AND examid = '" . $conn->real_escape_string($examid) . "' LIMIT 1");
if ($chk_sub && $chk_sub->num_rows > 0) {
    $sub_row = $chk_sub->fetch_assoc();

    // Resolve Target Mark Distribution Score
    $mark_dist_score = 0;
    if (!empty($exam['markdistid'])) {
        $mdid_esc = $conn->real_escape_string($exam['markdistid']);
        $cgid_esc = !empty($exam['classgroupid']) ? $conn->real_escape_string($exam['classgroupid']) : (!empty($student['classgroupid']) ? $conn->real_escape_string($student['classgroupid']) : '');
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
    if ($mark_dist_score <= 0 && !empty($exam['markname'])) {
        if (preg_match('/(\d+)\s*(?:marks?|pts?|points?)/i', $exam['markname'], $matches)) {
            $mark_dist_score = floatval($matches[1]);
        }
    }
    if ($mark_dist_score <= 0) {
        $mark_dist_score = (!empty($sub_row['total_score']) && floatval($sub_row['total_score']) > 0) ? floatval($sub_row['total_score']) : 100.0;
    }

    $prev_correct = isset($sub_row['correct_answers']) ? intval($sub_row['correct_answers']) : 0;
    $prev_total = (isset($sub_row['total_questions']) && intval($sub_row['total_questions']) > 0) 
                    ? intval($sub_row['total_questions']) 
                    : (intval($exam['total_questions']) > 0 ? intval($exam['total_questions']) : 1);
    
    $prev_score = round(($prev_correct / $prev_total) * $mark_dist_score);
    $prev_max_score = round($mark_dist_score);
    $prev_percentage = round(($prev_correct / $prev_total) * 100, 2);
    $prev_date = !empty($sub_row['date_taken']) ? $sub_row['date_taken'] : $sub_row['submitted_at'];
    $prev_date_str = !empty($prev_date) ? date('d M Y, h:i A', strtotime($prev_date)) : '';

    die("<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Examination Completed | " . htmlspecialchars($school_name) . "</title>
    <link rel='stylesheet' href='../assets/css/style.css'>
    <link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css'>
</head>
<body class='centered-page' style='display:flex; justify-content:center; align-items:center; min-height:100vh; margin:0; padding:20px; box-sizing:border-box;'>
    <div class='card' style='max-width:540px; width:100%; text-align:center; padding:36px 28px;'>
        <div style='font-size:54px; color:var(--success); margin-bottom:14px;'><i class='fa-solid fa-circle-check'></i></div>
        <h2 style='font-size:22px; font-weight:800; color:var(--text-main); margin-bottom:8px;'>Examination Already Completed</h2>
        <p style='color:var(--text-muted); font-size:14px; margin-bottom:20px;'>You have already completed and submitted <strong>" . htmlspecialchars($exam['title']) . "</strong>. Retakes are not permitted.</p>
        <div class='stat-box' style='padding:20px; margin-bottom:24px;'>
            <div class='stat-lbl' style='font-size:12px; text-transform:uppercase; letter-spacing:0.5px;'>Your Recorded Score</div>
            <div class='stat-val' style='font-size:38px; color:var(--success); font-weight:800; margin:6px 0;'>
                " . round($prev_score) . " <span style='font-size:20px; color:var(--text-muted); font-weight:600;'>/ " . round($prev_max_score) . "</span>
            </div>
            " . ($prev_total > 0 ? "<div style='font-size:13px; color:var(--text-muted);'>Answered correctly: <strong style='color:var(--text-main);'>" . $prev_correct . " / " . $prev_total . "</strong> questions</div>" : "") . "
            " . (!empty($prev_date_str) ? "<div style='font-size:11px; color:var(--text-muted); margin-top:6px;'>Submitted on: " . $prev_date_str . "</div>" : "") . "
        </div>
        <a href='select_exam.php' class='btn btn-primary' style='display:inline-flex; align-items:center; justify-content:center; gap:8px; width:100%; text-decoration:none;'>
            <i class='fa-solid fa-arrow-left'></i> Return to Exam List
        </a>
    </div>
</body>
</html>");
}

// Check schedule window
$now_ts = time();
$ex_date = !empty($exam['exam_date']) ? $exam['exam_date'] : date('Y-m-d', strtotime($exam['date_created']));
$end_date = !empty($exam['end_date']) ? $exam['end_date'] : $ex_date;
$start_time = !empty($exam['start_time']) ? $exam['start_time'] : '00:00:00';
$end_time = !empty($exam['end_time']) ? $exam['end_time'] : '23:59:59';
$start_ts = strtotime($ex_date . ' ' . $start_time);
$end_ts = strtotime($end_date . ' ' . $end_time);

if ($now_ts < $start_ts) {
    die("<div style='max-width:520px; margin:60px auto; padding:32px; background:#111827; color:#f8fafc; font-family:sans-serif; border-radius:16px; text-align:center; border:1px solid #374151;'>
        <div style='font-size:40px; color:#60a5fa; margin-bottom:12px;'><i class='fa-solid fa-calendar-clock'></i></div>
        <h2 style='margin-bottom:8px;'>Upcoming Examination</h2>
        <p style='color:#94a3b8; margin-bottom:16px;'>This examination is scheduled for <strong>" . date('M d, Y \a\t h:i A', $start_ts) . "</strong> and is not open yet.</p>
        <a href='select_exam.php' style='display:inline-block; padding:10px 20px; background:#6366f1; color:#fff; text-decoration:none; border-radius:8px; font-weight:700;'>Return to Exam List</a>
    </div>");
}

if ($now_ts > $end_ts) {
    die("<div style='max-width:520px; margin:60px auto; padding:32px; background:#111827; color:#f8fafc; font-family:sans-serif; border-radius:16px; text-align:center; border:1px solid #374151;'>
        <div style='font-size:40px; color:#ef4444; margin-bottom:12px;'><i class='fa-solid fa-clock-rotate-left'></i></div>
        <h2 style='margin-bottom:8px;'>Examination Period Closed</h2>
        <p style='color:#94a3b8; margin-bottom:16px;'>The scheduled window for this exam closed on <strong>" . date('M d, Y \a\t h:i A', $end_ts) . "</strong>. You can no longer start this exam.</p>
        <a href='select_exam.php' style='display:inline-block; padding:10px 20px; background:#6366f1; color:#fff; text-decoration:none; border-radius:8px; font-weight:700;'>Return to Exam List</a>
    </div>");
}

// Fetch Questions
$stmt_q = $conn->prepare("SELECT * FROM tbl_cbt_questions WHERE examid = ? ORDER BY questionid ASC");
$stmt_q->bind_param("s", $examid);
$stmt_q->execute();
$res_q = $stmt_q->get_result();

$questions = [];
while ($q = $res_q->fetch_assoc()) {
    $questions[] = $q;
}

if (empty($questions)) {
    die("This examination does not have any questions configured in the question bank yet. Please contact your administrator.");
}

// Fetch existing responses for crash recovery
$stmt_resp = $conn->prepare("SELECT questionid, selected_option, is_bookmarked FROM tbl_cbt_responses WHERE studentid = ? AND examid = ?");
$stmt_resp->bind_param("ss", $student['studentid'], $examid);
$stmt_resp->execute();
$res_resp = $stmt_resp->get_result();

$saved_answers = [];
$saved_bookmarks = [];
while ($r = $res_resp->fetch_assoc()) {
    if (!empty($r['selected_option'])) {
        $saved_answers[$r['questionid']] = $r['selected_option'];
    }
    if ($r['is_bookmarked']) {
        $saved_bookmarks[$r['questionid']] = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($exam['title']) ?> | Offline CBT Engine</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .exam-layout {
            display: grid;
            grid-template-columns: 1fr 340px;
            gap: 24px;
        }
        .question-card {
            background: var(--bg-card);
            backdrop-filter: blur(16px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 32px;
            display: flex;
            flex-direction: column;
        }
        .option-btn {
            background: rgba(15, 23, 42, 0.5);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 16px;
            cursor: pointer;
            transition: all 0.2s;
            color: var(--text-main);
        }
        .option-btn:last-child {
            margin-bottom: 0;
        }
        .option-btn:hover {
            border-color: var(--primary);
            background: rgba(99, 102, 241, 0.1);
        }
        .option-btn.selected {
            border-color: var(--primary);
            background: rgba(99, 102, 241, 0.25);
            box-shadow: 0 0 0 2px var(--primary);
        }
        .option-letter {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.08);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }
        .option-btn.selected .option-letter {
            background: var(--primary);
            color: #ffffff;
        }
        .palette-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 10px;
            margin-top: 16px;
        }
        .palette-num {
            aspect-ratio: 1;
            border-radius: 8px;
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            color: var(--text-muted);
        }
        .palette-num.answered {
            background: rgba(16, 185, 129, 0.25);
            border-color: var(--success);
            color: #ffffff;
        }
        .palette-num.current {
            border-color: var(--primary);
            box-shadow: 0 0 10px var(--primary);
            color: #ffffff;
            font-weight: 700;
        }
        .palette-num.bookmarked {
            background: rgba(245, 158, 11, 0.25);
            border-color: var(--warning);
            color: #ffffff;
        }
        .timer-badge {
            font-size: 24px;
            font-weight: 700;
            color: var(--primary);
            font-family: monospace;
            background: rgba(15, 23, 42, 0.8);
            border: 1px solid var(--border-color);
            padding: 8px 16px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
    </style>
</head>
<body>
    <div class="navbar" style="padding: 12px 24px;">
        <div class="brand" style="display: flex; align-items: center; gap: 12px;">
            <i class="fa-solid fa-laptop-code" style="font-size: 24px; color: var(--primary);"></i>
            <div>
                <div style="font-size: 16px; font-weight: 800; color: var(--text-main); line-height: 1.1;">
                    <?= htmlspecialchars($exam['subjectname']) ?>
                </div>
                <div style="font-size: 12px; color: var(--text-muted); font-weight: 500; margin-top: 2px;">
                    <?= htmlspecialchars($exam['title']) ?>
                </div>
            </div>
        </div>

        <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
            <!-- EXAM METADATA: SUBJECT, TOTAL QUESTIONS, DURATION -->
            <div style="display: flex; align-items: center; gap: 12px; background: rgba(15, 23, 42, 0.6); padding: 8px 16px; border-radius: 10px; border: 1px solid var(--border-color); font-size: 13px;">
                <span><i class="fa-solid fa-book" style="color: var(--primary);"></i> <strong>Subject:</strong> <?= htmlspecialchars($exam['subjectname']) ?></span>
                <span style="color: var(--border-color);">|</span>
                <span><i class="fa-solid fa-circle-question" style="color: var(--accent);"></i> <strong>Total Questions:</strong> <?= count($questions) ?></span>
                <span style="color: var(--border-color);">|</span>
                <span><i class="fa-solid fa-hourglass-half" style="color: var(--warning);"></i> <strong>Duration:</strong> <?= intval($exam['duration_minutes']) ?> Mins</span>
            </div>

            <!-- COUNTDOWN TIMER BADGE -->
            <div class="timer-badge" id="timerBadge" style="padding: 8px 16px; font-size: 20px; font-weight: 800; font-family: monospace;">
                <i class="fa-solid fa-stopwatch" id="timerIcon" style="color: var(--warning);"></i> <span id="timerText">00:00</span>
            </div>

            <button onclick="confirmSubmitExam()" class="btn btn-danger" style="font-weight: 700; padding: 10px 18px;">
                <i class="fa-solid fa-paper-plane"></i> Submit Exam
            </button>
        </div>
    </div>

    <!-- 5-MINUTE LOW TIME WARNING POP-UP MODAL -->
    <div id="timeWarningModal" onclick="if(event.target === this) dismissWarningModal()" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.8); backdrop-filter: blur(8px); z-index: 9999; justify-content: center; align-items: center;">
        <div style="position: relative; background: var(--bg-card); border: 2px solid var(--warning); border-radius: 16px; width: 90%; max-width: 480px; padding: 32px; text-align: center; box-shadow: 0 20px 40px rgba(0,0,0,0.7); animation: modalFadeIn 0.3s ease-out;">
            <!-- TOP RIGHT CLOSE BUTTON (X) -->
            <button onclick="dismissWarningModal()" type="button" aria-label="Close" style="position: absolute; top: 16px; right: 16px; background: rgba(255,255,255,0.08); border: 1px solid var(--border-color); color: var(--text-muted); width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 16px; cursor: pointer; transition: all 0.2s;" onmouseover="this.style.color='#ef4444'; this.style.background='rgba(239, 68, 68, 0.2)';" onmouseout="this.style.color='var(--text-muted)'; this.style.background='rgba(255,255,255,0.08)';">
                <i class="fa-solid fa-xmark"></i>
            </button>

            <div style="font-size: 56px; color: var(--warning); margin-bottom: 16px;">
                <i class="fa-solid fa-triangle-exclamation"></i>
            </div>
            <h2 style="font-size: 22px; font-weight: 800; color: var(--text-main); margin-bottom: 8px;">
                5 Minutes Remaining!
            </h2>
            <p style="color: var(--text-muted); font-size: 15px; line-height: 1.6; margin-bottom: 24px;">
                Attention Candidate! You have <strong>5 minutes left</strong> before your examination automatically submits. Please review your answers and prepare to complete your session.
            </p>
            <button onclick="dismissWarningModal()" class="btn btn-primary" style="width: 100%; padding: 14px; font-size: 16px; font-weight: 800; background: var(--warning); border-color: var(--warning); color: #000; cursor: pointer;">
                <i class="fa-solid fa-circle-check"></i> Got It, Continue Exam
            </button>
        </div>
    </div>

    <div class="container" style="max-width: 1300px; margin-top: 24px;">
        <!-- PROMINENT SCHOOL NAME, CANDIDATE DETAILS, EXAM METADATA & TIMER HEADER CARD -->
        <div class="card no-print" style="margin-bottom: 24px; padding: 22px 28px; background: rgba(17, 24, 39, 0.85); border: 1px solid var(--border-color);">
            <!-- SCHOOL NAME HEADER BANNER -->
            <div style="font-size: 14px; font-weight: 800; color: var(--primary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 14px; padding-bottom: 10px; border-bottom: 1px dashed var(--border-color); display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-graduation-cap" style="font-size: 18px; color: var(--primary);"></i> <?= htmlspecialchars($school_name) ?>
            </div>

            <!-- CANDIDATE DETAILS SECTION -->
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-color); padding-bottom: 14px; margin-bottom: 16px; flex-wrap: wrap; gap: 16px;">
                <div style="display: flex; align-items: center; gap: 12px;">
                    <div style="width: 42px; height: 42px; border-radius: 50%; background: rgba(99, 102, 241, 0.2); border: 1px solid var(--primary); display: flex; align-items: center; justify-content: center;">
                        <i class="fa-solid fa-user-graduate" style="font-size: 20px; color: var(--primary);"></i>
                    </div>
                    <div>
                        <div style="font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Candidate Details:</div>
                        <div style="font-size: 18px; font-weight: 800; color: var(--text-main);">
                            <?= htmlspecialchars($student['lastname'] . ' ' . $student['firstname'] . ' ' . $student['middlename']) ?>
                        </div>
                    </div>
                </div>

                <div style="display: flex; align-items: center; gap: 24px; font-size: 14px; color: var(--text-muted); flex-wrap: wrap;">
                    <span><i class="fa-solid fa-id-card" style="color: var(--primary);"></i> Registration No: <strong style="color: var(--accent); font-family: monospace; font-size: 15px; font-weight: 700;"><?= htmlspecialchars($student['regnumber']) ?></strong></span>
                    <span><i class="fa-solid fa-users" style="color: var(--success);"></i> Class: <strong style="color: var(--text-main); font-weight: 700;"><?= htmlspecialchars($student['classname']) ?></strong></span>
                </div>
            </div>

            <!-- ACTIVE EXAMINATION SESSION DETAILS -->
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
                <div>
                    <div style="font-size: 12px; font-weight: 700; color: var(--primary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">
                        <i class="fa-solid fa-graduation-cap"></i> Active Examination Session
                    </div>
                    <div style="font-size: 22px; font-weight: 800; color: var(--text-main);">
                        Subject: <span style="color: var(--accent);"><?= htmlspecialchars($exam['subjectname']) ?></span>
                    </div>
                    <div style="font-size: 13px; color: var(--text-muted); margin-top: 2px;">
                        Exam Title: <?= htmlspecialchars($exam['title']) ?> &nbsp;|&nbsp; Assessment: <?= !empty($exam['markname']) ? htmlspecialchars($exam['markname']) : 'CBT Assessment' ?>
                    </div>
                </div>

                <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
                    <div style="background: rgba(15, 23, 42, 0.7); border: 1px solid var(--border-color); padding: 10px 18px; border-radius: 12px; text-align: center;">
                        <div style="font-size: 11px; color: var(--text-muted); font-weight: 700; text-transform: uppercase;"><i class="fa-solid fa-circle-question" style="color: var(--accent);"></i> Total Questions</div>
                        <div style="font-size: 20px; font-weight: 800; color: var(--text-main); margin-top: 2px;"><?= count($questions) ?> Qs</div>
                    </div>

                    <div style="background: rgba(15, 23, 42, 0.7); border: 1px solid var(--border-color); padding: 10px 18px; border-radius: 12px; text-align: center;">
                        <div style="font-size: 11px; color: var(--text-muted); font-weight: 700; text-transform: uppercase;"><i class="fa-solid fa-hourglass-half" style="color: var(--warning);"></i> Duration</div>
                        <div style="font-size: 20px; font-weight: 800; color: var(--text-main); margin-top: 2px;"><?= intval($exam['duration_minutes']) ?> Mins</div>
                    </div>

                    <div id="timerCardBox" style="background: rgba(99, 102, 241, 0.15); border: 1px solid var(--primary); padding: 10px 22px; border-radius: 12px; text-align: center;">
                        <div style="font-size: 11px; color: var(--text-muted); font-weight: 700; text-transform: uppercase;"><i class="fa-solid fa-stopwatch" id="timerIcon2" style="color: var(--warning);"></i> Time Remaining</div>
                        <div id="timerText2" style="font-size: 24px; font-weight: 900; font-family: monospace; color: var(--primary); margin-top: 2px;">00:00</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="exam-layout">
            <!-- QUESTION VIEW -->
            <div class="question-card">
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid var(--border-color); padding-bottom: 16px;">
                        <div style="font-size: 18px; font-weight: 700; color: var(--primary);" id="qHeaderText">Question 1 of <?= count($questions) ?></div>
                    </div>

                    <div style="font-size: 18px; line-height: 1.6; margin-bottom: 28px;" id="questionBodyText">
                        Loading Question...
                    </div>

                    <div id="optionsContainer">
                        <!-- Options A-D inserted dynamically -->
                    </div>
                </div>

                <div style="display: flex; justify-content: space-between; margin-top: 24px; border-top: 1px solid var(--border-color); padding-top: 20px;">
                    <button class="btn" id="prevBtn" onclick="navigateQuestion(-1)" style="background: rgba(255,255,255,0.08);"><i class="fa-solid fa-chevron-left"></i> Previous</button>
                    <button class="btn btn-primary" id="nextBtn" onclick="navigateQuestion(1)">Next Question <i class="fa-solid fa-chevron-right"></i></button>
                </div>
            </div>

            <!-- SIDEBAR QUESTION PALETTE -->
            <div class="card" style="padding: 24px;">
                <div style="font-weight: 700; font-size: 16px; margin-bottom: 12px;"><i class="fa-solid fa-grip"></i> Question Palette</div>
                <div style="font-size: 13px; color: var(--text-muted); margin-bottom: 16px; display: flex; gap: 16px; flex-wrap: wrap;">
                    <span><span style="display:inline-block; width:10px; height:10px; background:var(--success); border-radius:2px;"></span> Answered</span>
                    <span><span style="display:inline-block; width:10px; height:10px; background:var(--border-color); border-radius:2px;"></span> Unanswered</span>
                </div>

                <div class="palette-grid" id="paletteGrid">
                    <!-- Palette items inserted dynamically -->
                </div>

                <div style="margin-top: 32px; border-top: 1px solid var(--border-color); padding-top: 20px;">
                    <div style="font-size: 14px; color: var(--text-muted); margin-bottom: 6px;">Candidate Details:</div>
                    <div style="font-weight: 700; font-size: 15px;"><?= htmlspecialchars($student['lastname'] . ' ' . $student['firstname']) ?></div>
                    <div style="font-size: 13px; color: var(--text-muted);"><?= htmlspecialchars($student['regnumber']) ?></div>
                </div>
            </div>
        </div>
    </div>

    <form id="submitExamForm" method="POST" action="submit.php">
        <input type="hidden" name="examid" value="<?= htmlspecialchars($examid) ?>">
        <input type="hidden" name="time_spent" id="timeSpentInput" value="0">
    </form>

    <script>
        const examId = "<?= $examid ?>";
        const questionsData = <?= json_encode($questions) ?>;
        const savedAnswers = <?= json_encode($saved_answers) ?>;
        const savedBookmarks = <?= json_encode($saved_bookmarks) ?>;
        const durationMinutes = <?= intval($exam['duration_minutes']) ?>;
        const windowRemainingSeconds = <?= max(0, $end_ts - $now_ts) ?>;

        let currentIndex = 0;
        let answers = savedAnswers || {};
        let bookmarks = savedBookmarks || {};
        let timeRemainingSeconds = Math.min(durationMinutes * 60, windowRemainingSeconds);
        let totalTimeSpentSeconds = 0;
        let warningShown = false;

        // Initialize Timer from LocalStorage (Crash Recovery)
        const cachedTime = localStorage.getItem('cbt_time_' + examId);
        if (cachedTime && parseInt(cachedTime, 10) > 0) {
            timeRemainingSeconds = Math.min(parseInt(cachedTime, 10), windowRemainingSeconds);
            if (timeRemainingSeconds <= 300) {
                warningShown = true; // Avoid re-triggering modal on page reload if already under 5 mins
            }
        }

        function updateTimerDisplay(totalSec) {
            if (totalSec < 0) totalSec = 0;
            const h = Math.floor(totalSec / 3600);
            const m = Math.floor((totalSec % 3600) / 60);
            const s = totalSec % 60;

            const timerBadge = document.getElementById('timerBadge');
            const timerText = document.getElementById('timerText');
            const timerText2 = document.getElementById('timerText2');
            const timerCardBox = document.getElementById('timerCardBox');
            const timerIcon = document.getElementById('timerIcon');
            const timerIcon2 = document.getElementById('timerIcon2');

            let timeStr = '';
            if (h > 0) {
                timeStr = (h < 10 ? '0' : '') + h + ':' + (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
            } else {
                timeStr = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
            }

            if (timerText) timerText.textContent = timeStr;
            if (timerText2) timerText2.textContent = timeStr;

            // Trigger 5-Minute Warning Modal (at <= 300 seconds)
            if (totalSec <= 300 && totalSec > 0 && !warningShown) {
                warningShown = true;
                showWarningModal();
            }

            // Low time warning visual state (< 5 mins)
            if (totalSec <= 300) {
                if (timerBadge) {
                    timerBadge.style.background = 'rgba(239, 68, 68, 0.25)';
                    timerBadge.style.borderColor = 'var(--danger)';
                    timerBadge.style.color = '#ef4444';
                }
                if (timerCardBox) {
                    timerCardBox.style.background = 'rgba(239, 68, 68, 0.25)';
                    timerCardBox.style.borderColor = 'var(--danger)';
                }
                if (timerText2) timerText2.style.color = '#ef4444';
                if (timerIcon) timerIcon.style.color = '#ef4444';
                if (timerIcon2) timerIcon2.style.color = '#ef4444';
            } else {
                if (timerBadge) {
                    timerBadge.style.background = 'rgba(15, 23, 42, 0.8)';
                    timerBadge.style.borderColor = 'var(--border-color)';
                    timerBadge.style.color = 'var(--primary)';
                }
                if (timerCardBox) {
                    timerCardBox.style.background = 'rgba(99, 102, 241, 0.15)';
                    timerCardBox.style.borderColor = 'var(--primary)';
                }
                if (timerText2) timerText2.style.color = 'var(--primary)';
                if (timerIcon) timerIcon.style.color = 'var(--warning)';
                if (timerIcon2) timerIcon2.style.color = 'var(--warning)';
            }
        }

        function showWarningModal() {
            const modal = document.getElementById('timeWarningModal');
            if (modal) modal.style.display = 'flex';
        }

        function dismissWarningModal() {
            const modal = document.getElementById('timeWarningModal');
            if (modal) modal.style.display = 'none';
        }

        // Close warning popup when Escape key is pressed
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                dismissWarningModal();
            }
        });

        function startTimer() {
            updateTimerDisplay(timeRemainingSeconds);
            const interval = setInterval(() => {
                timeRemainingSeconds--;
                totalTimeSpentSeconds++;
                localStorage.setItem('cbt_time_' + examId, timeRemainingSeconds);

                updateTimerDisplay(timeRemainingSeconds);

                if (timeRemainingSeconds <= 0) {
                    clearInterval(interval);
                    localStorage.removeItem('cbt_time_' + examId);
                    alert('Time is up! Your examination will now be submitted automatically.');
                    autoSubmitExam();
                }
            }, 1000);
        }

        function renderQuestion(index) {
            currentIndex = index;
            const q = questionsData[index];

            document.getElementById('qHeaderText').textContent = `Question ${index + 1} of ${questionsData.length}`;
            document.getElementById('questionBodyText').textContent = q.question_text;

            // Options
            const container = document.getElementById('optionsContainer');
            container.innerHTML = '';

            const options = [
                { key: 'A', text: q.option_a },
                { key: 'B', text: q.option_b },
                { key: 'C', text: q.option_c },
                { key: 'D', text: q.option_d }
            ];

            if (q.option_e) options.push({ key: 'E', text: q.option_e });

            options.forEach(opt => {
                if (!opt.text) return;
                const btn = document.createElement('div');
                btn.className = 'option-btn' + (answers[q.questionid] === opt.key ? ' selected' : '');
                btn.onclick = () => selectOption(q.questionid, opt.key);
                btn.innerHTML = `
                    <div class="option-letter">${opt.key}</div>
                    <div style="font-size: 16px; font-weight: 500;">${opt.text}</div>
                `;
                container.appendChild(btn);
            });

            // Navigation Buttons
            document.getElementById('prevBtn').disabled = index === 0;
            document.getElementById('nextBtn').textContent = (index === questionsData.length - 1) ? 'Review & Finish' : 'Next Question';

            renderPalette();
        }

        function selectOption(questionid, optionKey) {
            answers[questionid] = optionKey;
            
            // AJAX 3-Layer Save
            fetch('save_answer.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    examid: examId,
                    questionid: questionid,
                    selected_option: optionKey,
                    is_bookmarked: bookmarks[questionid] ? 1 : 0
                })
            });

            renderQuestion(currentIndex);
        }

        function toggleBookmark() {
            const qid = questionsData[currentIndex].questionid;
            bookmarks[qid] = !bookmarks[qid];
            
            fetch('save_answer.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    examid: examId,
                    questionid: qid,
                    selected_option: answers[qid] || '',
                    is_bookmarked: bookmarks[qid] ? 1 : 0
                })
            });

            renderQuestion(currentIndex);
        }

        function navigateQuestion(step) {
            const nextIdx = currentIndex + step;
            if (nextIdx >= 0 && nextIdx < questionsData.length) {
                renderQuestion(nextIdx);
            } else if (nextIdx >= questionsData.length) {
                confirmSubmitExam();
            }
        }

        function renderPalette() {
            const grid = document.getElementById('paletteGrid');
            grid.innerHTML = '';

            questionsData.forEach((q, i) => {
                const item = document.createElement('div');
                let cls = 'palette-num';
                if (i === currentIndex) cls += ' current';
                if (answers[q.questionid]) cls += ' answered';
                if (bookmarks[q.questionid]) cls += ' bookmarked';

                item.className = cls;
                item.textContent = i + 1;
                item.onclick = () => renderQuestion(i);
                grid.appendChild(item);
            });
        }

        function confirmSubmitExam() {
            const answeredCount = Object.keys(answers).length;
            const totalCount = questionsData.length;
            if (confirm(`You have answered ${answeredCount} of ${totalCount} questions. Are you sure you want to submit your examination now?`)) {
                autoSubmitExam();
            }
        }

        function autoSubmitExam() {
            document.getElementById('timeSpentInput').value = totalTimeSpentSeconds;
            document.getElementById('submitExamForm').submit();
        }

        // Initialize
        renderQuestion(0);
        startTimer();
    </script>
</body>
</html>