<?php
require_once __DIR__ . '/../config/config.php';

require_admin_login();

$conn = get_db_connection();
$school = get_school_config();
$schoolid = $school['schoolid'];
$admin = $_SESSION['cbt_admin'];

$msg = '';
$error = '';

$selected_examid = isset($_GET['examid']) ? trim($_GET['examid']) : '';

// Download Sample Template CSV
if (isset($_GET['download_sample'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=cbt_questions_sample_template.csv');
    $output = fopen('php://output', 'w');
    fputs($output, "\xEF\xBB\xBF");
    fputcsv($output, array('Question Text', 'Option A', 'Option B', 'Option C', 'Option D', 'Option E', 'Correct Option', 'Mark'));
    fputcsv($output, array('What is the capital of Nigeria?', 'Lagos', 'Abuja', 'Kano', 'Ibadan', 'Port Harcourt', 'B', '1'));
    fputcsv($output, array('Which process do plants use to convert sunlight into food energy?', 'Respiration', 'Photosynthesis', 'Transpiration', 'Osmosis', '', 'B', '2'));
    fputcsv($output, array('Simplify the algebraic expression: 3x + 5x - 2x.', '6x', '10x', '5x', '8x', '', 'A', '1'));
    fputcsv($output, array('Which element has the chemical symbol Fe?', 'Iron', 'Gold', 'Silver', 'Copper', 'Lead', 'A', '1'));
    fclose($output);
    exit;
}

// Mass Excel/CSV Question Import Handling
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_mass_questions'])) {
    $examid = trim($_POST['examid']);
    if (empty($examid)) {
        $error = "Please select an examination before uploading questions.";
    } else if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
        $error = "Please choose a valid Excel/CSV file to upload.";
    } else {
        $tmpFile = $_FILES['excel_file']['tmp_name'];
        $handle = fopen($tmpFile, 'r');
        if ($handle === false) {
            $error = "Unable to read uploaded file.";
        } else {
            $imported_count = 0;
            $row_num = 0;
            
            $first_line = fgets($handle);
            rewind($handle);
            $sep = ',';
            if (strpos($first_line, "\t") !== false) {
                $sep = "\t";
            } else if (strpos($first_line, ";") !== false && strpos($first_line, ",") === false) {
                $sep = ";";
            }

            $stmt = $conn->prepare("REPLACE INTO tbl_cbt_questions (questionid, examid, question_text, image_url, option_a, option_b, option_c, option_d, option_e, correct_option, mark) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $image_url = null;

            while (($data = fgetcsv($handle, 4096, $sep)) !== false) {
                $row_num++;
                if ($row_num === 1 && (strripos($data[0], 'Question') !== false || strripos($data[0], 'Text') !== false)) {
                    continue;
                }
                if (empty($data) || (count($data) === 1 && trim($data[0]) === '')) {
                    continue;
                }

                $q_text = isset($data[0]) ? trim($data[0]) : '';
                $opt_a  = isset($data[1]) ? trim($data[1]) : '';
                $opt_b  = isset($data[2]) ? trim($data[2]) : '';
                $opt_c  = isset($data[3]) ? trim($data[3]) : '';
                $opt_d  = isset($data[4]) ? trim($data[4]) : '';
                $opt_e  = isset($data[5]) && !empty($data[5]) ? trim($data[5]) : null;
                
                $correct = isset($data[6]) ? strtoupper(trim($data[6])) : 'A';
                if (!in_array($correct, array('A', 'B', 'C', 'D', 'E'))) {
                    $correct = 'A';
                }
                
                $mark = isset($data[7]) && intval($data[7]) > 0 ? intval($data[7]) : 1;

                if (!empty($q_text) && !empty($opt_a) && !empty($opt_b)) {
                    $questionid = $examid . '_Q' . strtoupper(substr(md5(uniqid(rand(), true)), 0, 8));
                    $stmt->bind_param("ssssssssssi", $questionid, $examid, $q_text, $image_url, $opt_a, $opt_b, $opt_c, $opt_d, $opt_e, $correct, $mark);
                    if ($stmt->execute()) {
                        $imported_count++;
                    }
                }
            }
            fclose($handle);

            if ($imported_count > 0) {
                $q_cnt = $conn->query("SELECT COUNT(*) as cnt FROM tbl_cbt_questions WHERE examid = '$examid'")->fetch_assoc()['cnt'];
                $conn->query("UPDATE tbl_cbt_exams SET total_questions = $q_cnt WHERE examid = '$examid'");
                header("Location: questions.php?examid=" . urlencode($examid) . "&msg=" . urlencode("Successfully mass-imported $imported_count questions into question bank."));
                exit;
            } else {
                $error = "No valid questions were imported. Please check the structure of your Excel/CSV file.";
            }
        }
    }
}

// Delete Question
if (isset($_GET['delete_q'])) {
    $qid = trim($_GET['delete_q']);
    $conn->query("DELETE FROM tbl_cbt_questions WHERE questionid = '$qid'");
    header("Location: questions.php?examid=" . urlencode($selected_examid) . "&msg=Question deleted successfully");
    exit;
}

// Save Question (Create or Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_question'])) {
    $examid = trim($_POST['examid']);
    $questionid = !empty($_POST['questionid']) ? trim($_POST['questionid']) : $examid . '_Q' . strtoupper(substr(md5(uniqid()), 0, 6));
    $question_text = trim($_POST['question_text']);
    $option_a = trim($_POST['option_a']);
    $option_b = trim($_POST['option_b']);
    $option_c = trim($_POST['option_c']);
    $option_d = trim($_POST['option_d']);
    $option_e = !empty($_POST['option_e']) ? trim($_POST['option_e']) : null;
    $correct_option = strtoupper(trim($_POST['correct_option']));
    $mark = intval($_POST['mark']) > 0 ? intval($_POST['mark']) : 1;
    $image_url = null;

    // Diagram Image Upload Handling
    if (isset($_FILES['diagram_image']) && $_FILES['diagram_image']['error'] === UPLOAD_ERR_OK) {
        $imgDir = __DIR__ . '/../assets/images/questions/';
        if (!file_exists($imgDir)) {
            mkdir($imgDir, 0777, true);
        }
        $ext = pathinfo($_FILES['diagram_image']['name'], PATHINFO_EXTENSION);
        $filename = $questionid . '_' . time() . '.' . $ext;
        $targetPath = $imgDir . $filename;
        if (move_uploaded_file($_FILES['diagram_image']['tmp_name'], $targetPath)) {
            $image_url = 'assets/images/questions/' . $filename;
        }
    } else if (!empty($_POST['existing_image_url'])) {
        $image_url = trim($_POST['existing_image_url']);
    }

    if (empty($examid) || empty($question_text) || empty($option_a) || empty($option_b) || empty($correct_option)) {
        $error = "Please fill in the question text, at least Options A & B, and select the correct answer option.";
    } else {
        $stmt = $conn->prepare("REPLACE INTO tbl_cbt_questions (questionid, examid, question_text, image_url, option_a, option_b, option_c, option_d, option_e, correct_option, mark) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("ssssssssssi", $questionid, $examid, $question_text, $image_url, $option_a, $option_b, $option_c, $option_d, $option_e, $correct_option, $mark);
        
        if ($stmt->execute()) {
            $q_cnt = $conn->query("SELECT COUNT(*) as cnt FROM tbl_cbt_questions WHERE examid = '$examid'")->fetch_assoc()['cnt'];
            $conn->query("UPDATE tbl_cbt_exams SET total_questions = $q_cnt WHERE examid = '$examid'");
            
            header("Location: questions.php?examid=" . urlencode($examid) . "&msg=Question saved successfully");
            exit;
        } else {
            $error = "Failed to save question: " . $conn->error;
        }
    }
}

// Fetch All Exams for Dropdown Filter
$res_exams = $conn->query("SELECT examid, title, subjectname FROM tbl_cbt_exams ORDER BY title ASC");
$all_exams = [];
if ($res_exams) {
    while ($ex = $res_exams->fetch_assoc()) {
        $all_exams[] = $ex;
    }
}

// Fetch Editing Question details if edit_q set
$edit_q = null;
if (isset($_GET['edit_q'])) {
    $edit_qid = trim($_GET['edit_q']);
    $res_ed = $conn->query("SELECT * FROM tbl_cbt_questions WHERE questionid = '$edit_qid'");
    if ($res_ed && $res_ed->num_rows > 0) {
        $edit_q = $res_ed->fetch_assoc();
        $selected_examid = $edit_q['examid'];
    }
}

if (isset($_GET['msg'])) {
    $msg = htmlspecialchars($_GET['msg']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Question Bank Setup | Offline Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body style="display: block; padding: 0;">
    <div class="app-layout">
        <!-- SIDEBAR NAVIGATION -->
        <?php 
        $active_nav = 'questions';
        include __DIR__ . '/sidebar.php'; 
        ?>

        <!-- MAIN CONTENT AREA -->
        <main class="main-content">
            <?php if (!empty($msg)): ?>
                <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($msg) ?></div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <!-- EXAM FILTER HEADER -->
            <div class="card" style="margin-bottom: 24px; padding: 20px 32px;">
                <form method="GET" action="questions.php" style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
                    <label for="examFilter" style="font-weight: 700; font-size: 16px; white-space: nowrap;"><i class="fa-solid fa-filter" style="color: var(--primary);"></i> Select Exam Question Bank:</label>
                    <select id="examFilter" name="examid" class="form-control" style="background: rgba(15, 23, 42, 0.9); font-size: 16px; font-weight: 600; flex: 1; min-width: 280px;" required>
                        <option value="">-- Select Question Bank --</option>
                        <?php foreach ($all_exams as $ex): ?>
                            <option value="<?= htmlspecialchars($ex['examid']) ?>" <?= ($selected_examid === $ex['examid']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($ex['title']) ?> (<?= htmlspecialchars($ex['subjectname']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary" style="padding: 10px 22px; font-weight: 700; display: inline-flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-folder-open"></i> Load Question Bank
                    </button>
                </form>
            </div>

            <?php if (!empty($selected_examid)): ?>
            <div class="grid-2" style="grid-template-columns: 420px 1fr; align-items: start;">
                <div>
                    <!-- MASS EXCEL UPLOAD CARD -->
                    <div class="card" style="margin-bottom: 24px;">
                        <div class="card-title"><i class="fa-solid fa-file-excel" style="color: var(--success);"></i> Mass Excel/CSV Upload</div>
                        <div class="card-desc">Bulk import questions from an Excel or CSV file into this exam question bank.</div>

                        <div style="margin-bottom: 16px; background: rgba(16, 185, 129, 0.08); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: 8px; padding: 12px 14px; font-size: 13px; color: var(--text-main);">
                            <div style="font-weight: 700; color: var(--success); margin-bottom: 4px;"><i class="fa-solid fa-download"></i> Download Sample Excel File</div>
                            Get the pre-formatted sample Excel/CSV template with standard headers:
                            <div style="margin-top: 10px;">
                                <a href="questions.php?download_sample=1" class="btn" style="padding: 8px 12px; font-size: 13px; background: var(--success); color: #fff; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; font-weight: 700; border-radius: 6px;">
                                    <i class="fa-solid fa-file-arrow-down"></i> Download Sample File (.csv)
                                </a>
                            </div>
                        </div>

                        <form method="POST" action="questions.php?examid=<?= urlencode($selected_examid) ?>" enctype="multipart/form-data">
                            <input type="hidden" name="upload_mass_questions" value="1">
                            <input type="hidden" name="examid" value="<?= htmlspecialchars($selected_examid) ?>">

                            <div class="form-group">
                                <label class="form-label" for="excel_file"><i class="fa-solid fa-file-csv"></i> Select Excel / CSV File</label>
                                <input type="file" id="excel_file" name="excel_file" class="form-control" accept=".csv, .xlsx, .xls, .txt" required>
                            </div>

                            <button type="submit" class="btn btn-primary" style="width: 100%; background: var(--primary); font-weight: 700;">
                                <i class="fa-solid fa-upload"></i> Upload & Import Questions
                            </button>
                        </form>
                    </div>

                    <!-- CREATE / EDIT QUESTION FORM -->
                    <div class="card">
                        <div class="card-title"><?= $edit_q ? '<i class="fa-solid fa-pen-to-square"></i> Edit Question' : '<i class="fa-solid fa-circle-plus"></i> Add Single Question' ?></div>
                        <div class="card-desc">Add or modify question text, options A–E, diagram image, and correct answer key offline.</div>

                    <form method="POST" action="questions.php?examid=<?= urlencode($selected_examid) ?>" enctype="multipart/form-data">
                        <input type="hidden" name="save_question" value="1">
                        <input type="hidden" name="examid" value="<?= htmlspecialchars($selected_examid) ?>">
                        <?php if ($edit_q): ?>
                            <input type="hidden" name="questionid" value="<?= htmlspecialchars($edit_q['questionid']) ?>">
                            <input type="hidden" name="existing_image_url" value="<?= htmlspecialchars($edit_q['image_url']) ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <label class="form-label" for="question_text">Question Text</label>
                            <textarea id="question_text" name="question_text" class="form-control" rows="4" placeholder="Enter question body text..." required><?= $edit_q ? htmlspecialchars($edit_q['question_text']) : '' ?></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="diagram_image"><i class="fa-solid fa-image"></i> Question Diagram / Image (Optional)</label>
                            <input type="file" id="diagram_image" name="diagram_image" class="form-control" accept="image/*">
                            <?php if ($edit_q && !empty($edit_q['image_url'])): ?>
                                <div style="margin-top: 8px; font-size: 13px; color: var(--success);"><i class="fa-solid fa-paperclip"></i> Attached: <?= htmlspecialchars($edit_q['image_url']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="option_a">Option A</label>
                            <input type="text" id="option_a" name="option_a" class="form-control" placeholder="Text choice for A" value="<?= $edit_q ? htmlspecialchars($edit_q['option_a']) : '' ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="option_b">Option B</label>
                            <input type="text" id="option_b" name="option_b" class="form-control" placeholder="Text choice for B" value="<?= $edit_q ? htmlspecialchars($edit_q['option_b']) : '' ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="option_c">Option C</label>
                            <input type="text" id="option_c" name="option_c" class="form-control" placeholder="Text choice for C" value="<?= $edit_q ? htmlspecialchars($edit_q['option_c']) : '' ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="option_d">Option D</label>
                            <input type="text" id="option_d" name="option_d" class="form-control" placeholder="Text choice for D" value="<?= $edit_q ? htmlspecialchars($edit_q['option_d']) : '' ?>">
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="option_e">Option E (Optional)</label>
                            <input type="text" id="option_e" name="option_e" class="form-control" placeholder="Text choice for E" value="<?= $edit_q ? htmlspecialchars($edit_q['option_e']) : '' ?>">
                        </div>

                        <div class="grid-2" style="gap: 12px;">
                            <div class="form-group">
                                <label class="form-label" for="correct_option">Correct Key</label>
                                <select id="correct_option" name="correct_option" class="form-control" required style="background: rgba(15, 23, 42, 0.9); font-weight: 700;">
                                    <option value="A" <?= ($edit_q && $edit_q['correct_option'] === 'A') ? 'selected' : '' ?>>Option A</option>
                                    <option value="B" <?= ($edit_q && $edit_q['correct_option'] === 'B') ? 'selected' : '' ?>>Option B</option>
                                    <option value="C" <?= ($edit_q && $edit_q['correct_option'] === 'C') ? 'selected' : '' ?>>Option C</option>
                                    <option value="D" <?= ($edit_q && $edit_q['correct_option'] === 'D') ? 'selected' : '' ?>>Option D</option>
                                    <option value="E" <?= ($edit_q && $edit_q['correct_option'] === 'E') ? 'selected' : '' ?>>Option E</option>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="mark">Mark Allocation</label>
                                <input type="number" id="mark" name="mark" class="form-control" value="<?= $edit_q ? intval($edit_q['mark']) : '1' ?>" required min="1">
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">
                            <i class="fa-solid fa-floppy-disk"></i> <?= $edit_q ? 'Update Question' : 'Save Question to Bank' ?>
                        </button>
                        <?php if ($edit_q): ?>
                            <a href="questions.php?examid=<?= urlencode($selected_examid) ?>" class="btn" style="width: 100%; margin-top: 8px; background: rgba(255,255,255,0.08); text-align: center; color: var(--text-muted); text-decoration: none;">Cancel Editing</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- QUESTIONS LIST -->
                <div class="card">
                    <div class="card-title"><i class="fa-solid fa-layer-group"></i> Question Items in Bank</div>
                    <div class="card-desc">Manage all question items, diagram attachments, and correct answer keys for this exam.</div>

                    <?php
                    $res_qs = $conn->query("SELECT * FROM tbl_cbt_questions WHERE examid = '$selected_examid' ORDER BY questionid ASC");
                    if ($res_qs && $res_qs->num_rows > 0):
                        $qnum = 1;
                        while ($q = $res_qs->fetch_assoc()):
                    ?>
                        <div style="background: rgba(15, 23, 42, 0.4); border: 1px solid var(--border-color); border-radius: 12px; padding: 20px; margin-bottom: 16px;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 12px;">
                                <div style="font-weight: 700; font-size: 16px; color: var(--primary);">
                                    Question <?= $qnum++ ?> <span style="font-size: 13px; color: var(--text-muted); font-weight: 400;">(<?= $q['mark'] ?> Mark<?= $q['mark'] > 1 ? 's' : '' ?>)</span>
                                </div>
                                <div style="display: flex; gap: 8px;">
                                    <a href="questions.php?examid=<?= urlencode($selected_examid) ?>&edit_q=<?= $q['questionid'] ?>" class="btn" style="padding: 4px 10px; font-size: 12px; background: rgba(255,255,255,0.1); color: var(--text-main); text-decoration: none;">
                                        <i class="fa-solid fa-pen"></i> Edit
                                    </a>
                                    <a href="questions.php?examid=<?= urlencode($selected_examid) ?>&delete_q=<?= $q['questionid'] ?>" onclick="return confirm('Delete this question from local question bank?');" class="btn btn-danger" style="padding: 4px 10px; font-size: 12px; text-decoration: none;">
                                        <i class="fa-solid fa-trash"></i>
                                    </a>
                                </div>
                            </div>

                            <div style="font-size: 15px; margin-bottom: 14px; line-height: 1.5; color: var(--text-main);">
                                <?= nl2br(htmlspecialchars($q['question_text'])) ?>
                            </div>

                            <?php if (!empty($q['image_url'])): ?>
                                <div style="margin-bottom: 14px;">
                                    <img src="../<?= htmlspecialchars($q['image_url']) ?>" alt="Diagram" style="max-width: 100%; max-height: 200px; border-radius: 8px; border: 1px solid var(--border-color);">
                                </div>
                            <?php endif; ?>

                            <div class="grid-2" style="gap: 8px; font-size: 14px;">
                                <div style="padding: 8px 12px; border-radius: 6px; background: <?= $q['correct_option'] === 'A' ? 'rgba(16, 185, 129, 0.2)' : 'rgba(255,255,255,0.03)' ?>; border: 1px solid <?= $q['correct_option'] === 'A' ? 'var(--success)' : 'var(--border-color)' ?>;">
                                    <strong>A:</strong> <?= htmlspecialchars($q['option_a']) ?>
                                </div>
                                <div style="padding: 8px 12px; border-radius: 6px; background: <?= $q['correct_option'] === 'B' ? 'rgba(16, 185, 129, 0.2)' : 'rgba(255,255,255,0.03)' ?>; border: 1px solid <?= $q['correct_option'] === 'B' ? 'var(--success)' : 'var(--border-color)' ?>;">
                                    <strong>B:</strong> <?= htmlspecialchars($q['option_b']) ?>
                                </div>
                                <?php if (!empty($q['option_c'])): ?>
                                    <div style="padding: 8px 12px; border-radius: 6px; background: <?= $q['correct_option'] === 'C' ? 'rgba(16, 185, 129, 0.2)' : 'rgba(255,255,255,0.03)' ?>; border: 1px solid <?= $q['correct_option'] === 'C' ? 'var(--success)' : 'var(--border-color)' ?>;">
                                        <strong>C:</strong> <?= htmlspecialchars($q['option_c']) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($q['option_d'])): ?>
                                    <div style="padding: 8px 12px; border-radius: 6px; background: <?= $q['correct_option'] === 'D' ? 'rgba(16, 185, 129, 0.2)' : 'rgba(255,255,255,0.03)' ?>; border: 1px solid <?= $q['correct_option'] === 'D' ? 'var(--success)' : 'var(--border-color)' ?>;">
                                        <strong>D:</strong> <?= htmlspecialchars($q['option_d']) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php 
                        endwhile;
                    else:
                    ?>
                        <div style="text-align: center; color: var(--text-muted); padding: 40px;">
                            <i class="fa-solid fa-folder-open" style="font-size: 40px; margin-bottom: 12px; display: block;"></i>
                            No questions in question bank for this exam yet. Use the form on the left to add questions.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php else: ?>
                <!-- NO QUESTION BANK SELECTED PROMPT -->
                <div class="card" style="text-align: center; padding: 60px 20px; max-width: 800px; margin: 0 auto;">
                    <div style="font-size: 56px; color: var(--primary); margin-bottom: 16px;">
                        <i class="fa-solid fa-layer-group"></i>
                    </div>
                    <h2 style="font-size: 22px; font-weight: 700; margin-bottom: 8px; color: var(--text-main);">No Question Bank Loaded</h2>
                    <p style="color: var(--text-muted); max-width: 480px; margin: 0 auto; line-height: 1.6; font-size: 15px;">
                        Please select an examination from the dropdown menu above and click <strong>"Load Question Bank"</strong> to view questions or perform a mass Excel/CSV upload.
                    </p>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>