<?php
require_once __DIR__ . '/../config/config.php';

require_admin_login();

$conn = get_db_connection();
$school = get_school_config();
$schoolid = $school['schoolid'];
$admin = $_SESSION['cbt_admin'];

$msg = '';
$error = '';

// Toggle Exam Activation
if (isset($_GET['toggle_active'])) {
    $examid = trim($_GET['toggle_active']);
    $conn->query("UPDATE tbl_cbt_exams SET is_active = IF(is_active=1, 0, 1) WHERE examid = '$examid'");
    header("Location: exams.php?msg=Status updated successfully");
    exit;
}

// Delete Exam
if (isset($_GET['delete_exam'])) {
    $examid = trim($_GET['delete_exam']);
    $conn->query("DELETE FROM tbl_cbt_exams WHERE examid = '$examid'");
    $conn->query("DELETE FROM tbl_cbt_questions WHERE examid = '$examid'");
    header("Location: exams.php?msg=Exam deleted successfully");
    exit;
}

// Save Exam (Create or Edit)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_exam'])) {
    $examid = !empty($_POST['examid']) ? trim($_POST['examid']) : 'EXAM_' . strtoupper(substr(md5(uniqid()), 0, 8));
    $title = trim($_POST['title']);
    $classgroupid = trim($_POST['classgroupid']);
    $subjectid = trim($_POST['subjectid']);
    $subjectname = trim($_POST['subjectname']);
    $markdistid = trim($_POST['markdistid']);
    
    // Fetch markname
    $markname = 'CBT FINAL';
    if (!empty($markdistid)) {
        $res_mn = $conn->query("SELECT markname FROM tbl_cbt_mark_distributions WHERE markdistid = '$markdistid' LIMIT 1");
        if ($res_mn && $res_mn->num_rows > 0) {
            $markname = $res_mn->fetch_assoc()['markname'];
        }
    }

    $duration = intval($_POST['duration_minutes']);
    $total_q = intval($_POST['total_questions']);
    $passmark = intval($_POST['passmark']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    $sess = !empty($_POST['sessionid']) ? trim($_POST['sessionid']) : (!empty($school['current_sessionid']) ? $school['current_sessionid'] : '68bab4c928f06277748994016768200');
    $term = !empty($_POST['termid']) ? trim($_POST['termid']) : (!empty($school['current_termid']) ? $school['current_termid'] : '5613a794ef5ac183124439098039600');
    $classid = $classgroupid;

    if (empty($title) || empty($subjectname) || empty($classgroupid) || $duration <= 0) {
        $error = "Please fill in all required exam fields correctly.";
    } else {
        $stmt = $conn->prepare("REPLACE INTO tbl_cbt_exams (examid, schoolid, title, sessionid, termid, classgroupid, classid, subjectid, subjectname, markdistid, markname, duration_minutes, total_questions, passmark, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssssssssiiii", $examid, $schoolid, $title, $sess, $term, $classgroupid, $classid, $subjectid, $subjectname, $markdistid, $markname, $duration, $total_q, $passmark, $is_active);
        
        if ($stmt->execute()) {
            $msg = "Exam configuration saved successfully!";
        } else {
            $error = "Failed to save exam: " . $conn->error;
        }
    }
}

// Fetch Academic Sessions for dropdown
$res_sess = $conn->query("SELECT sessionid, sessionname, is_current FROM tbl_cbt_sessions ORDER BY sessionname DESC");
$academic_sessions = [];
if ($res_sess && $res_sess->num_rows > 0) {
    while ($s = $res_sess->fetch_assoc()) {
        $academic_sessions[] = $s;
    }
}
if (empty($academic_sessions)) {
    $academic_sessions = [
        ['sessionid' => '68bab4c928f06277748994016768200', 'sessionname' => '2025/2026 Academic Session', 'is_current' => 1],
        ['sessionid' => '68bab4c928f06277748994016768201', 'sessionname' => '2026/2027 Academic Session', 'is_current' => 0]
    ];
}

// Fetch Academic Terms for dropdown
$res_term = $conn->query("SELECT termid, termname, termposition FROM tbl_cbt_terms ORDER BY termposition ASC, termname ASC");
$academic_terms = [];
if ($res_term && $res_term->num_rows > 0) {
    while ($t = $res_term->fetch_assoc()) {
        $academic_terms[] = $t;
    }
}
if (empty($academic_terms)) {
    $academic_terms = [
        ['termid' => '5613a794ef5ac183124439098039600', 'termname' => 'First Term'],
        ['termid' => '5613a794ef5ac183124439098039601', 'termname' => 'Second Term'],
        ['termid' => '5613a794ef5ac183124439098039602', 'termname' => 'Third Term']
    ];
}

// Fetch School Subjects for dropdown
$res_sub = $conn->query("SELECT subjectid, subjectname, subjectabbreviation FROM tbl_cbt_subjects ORDER BY subjectname ASC");
$school_subjects = [];
if ($res_sub && $res_sub->num_rows > 0) {
    while ($sb = $res_sub->fetch_assoc()) {
        $school_subjects[] = $sb;
    }
}

// Fetch Class Groups for dropdown
$res_cg = $conn->query("SELECT classgroupid, classgroupname FROM tbl_cbt_class_groups ORDER BY classgrouplevel ASC, classgroupname ASC");
$class_groups = [];
if ($res_cg && $res_cg->num_rows > 0) {
    while ($cg = $res_cg->fetch_assoc()) {
        $class_groups[] = $cg;
    }
}
if (empty($class_groups)) {
    $class_groups = [
        ['classgroupid' => '5613a8d09a236551044049063135000', 'classgroupname' => 'JSS 1'],
        ['classgroupid' => '5613a8d974596468749944047656600', 'classgroupname' => 'JSS 2'],
        ['classgroupid' => '5613a8e2adc66987306843071178200', 'classgroupname' => 'JSS 3'],
        ['classgroupid' => '5613a8e7412ee460972648026699000', 'classgroupname' => 'SS 1'],
        ['classgroupid' => '5613a8eb3f9f5758577815026059700', 'classgroupname' => 'SS 2'],
        ['classgroupid' => '5613a8ef6056c055355753039460400', 'classgroupname' => 'SS 3']
    ];
}

// Fetch Mark Distributions
$res_md = $conn->query("SELECT markdistid, classgroupid, markname, markscore FROM tbl_cbt_mark_distributions ORDER BY markname ASC");
$mark_distributions = [];
if ($res_md && $res_md->num_rows > 0) {
    while ($md = $res_md->fetch_assoc()) {
        $mark_distributions[] = $md;
    }
}

// Fetch Editing Exam details if edit parameter set
$edit_exam = null;
if (isset($_GET['edit'])) {
    $edit_id = trim($_GET['edit']);
    $res_ed = $conn->query("SELECT * FROM tbl_cbt_exams WHERE examid = '$edit_id'");
    if ($res_ed && $res_ed->num_rows > 0) {
        $edit_exam = $res_ed->fetch_assoc();
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
    <title>Manage CBT Exams | Offline Admin</title>
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
                    <a href="index.php" class="sidebar-link">
                        <i class="fa-solid fa-gauge-high"></i> Dashboard
                    </a>
                    <a href="exams.php" class="sidebar-link active">
                        <i class="fa-solid fa-list-check"></i> Manage Exams
                    </a>
                    <a href="questions.php" class="sidebar-link">
                        <i class="fa-solid fa-layer-group"></i> Question Bank
                    </a>
                    <a href="students.php" class="sidebar-link">
                        <i class="fa-solid fa-users"></i> Student Roster
                    </a>
                    <a href="../api/sync_pull.php" class="sidebar-link">
                        <i class="fa-solid fa-cloud-arrow-down"></i> Pull Online Data
                    </a>
                    <a href="../api/sync_push.php" class="sidebar-link">
                        <i class="fa-solid fa-cloud-arrow-up"></i> Push Scores Online
                    </a>
                    <a href="reset.php" class="sidebar-link">
                        <i class="fa-solid fa-rotate"></i> Reset Data
                    </a>
                    <a href="login.php?logout=1" class="sidebar-link" style="color: var(--danger); margin-top: 8px;">
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
            <?php if (!empty($msg)): ?>
                <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($msg) ?></div>
            <?php endif; ?>
            <?php if (!empty($error)): ?>
                <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="grid-2" style="grid-template-columns: 380px 1fr; align-items: start;">
                <!-- CREATE / EDIT EXAM FORM -->
                <div class="card">
                    <div class="card-title"><?= $edit_exam ? '<i class="fa-solid fa-pen-to-square"></i> Edit Exam' : '<i class="fa-solid fa-circle-plus"></i> Create New Exam' ?></div>
                    <div class="card-desc">Configure exam duration, target class group, subject, and mark distribution offline.</div>

                    <form method="POST" action="exams.php">
                        <input type="hidden" name="save_exam" value="1">
                        <?php if ($edit_exam): ?>
                            <input type="hidden" name="examid" value="<?= htmlspecialchars($edit_exam['examid']) ?>">
                        <?php endif; ?>

                        <div class="form-group">
                            <label class="form-label" for="title">Exam Title</label>
                            <input type="text" id="title" name="title" class="form-control" placeholder="e.g. JSS 2 Basic Science Term 3 Exam" value="<?= $edit_exam ? htmlspecialchars($edit_exam['title']) : '' ?>" required>
                        </div>

                        <!-- ACADEMIC SESSION & TERM SELECTORS -->
                        <div class="grid-2" style="gap: 12px;">
                            <div class="form-group">
                                <label class="form-label" for="sessionid"><i class="fa-solid fa-calendar-days"></i> Academic Session</label>
                                <select id="sessionid" name="sessionid" class="form-control" style="background: rgba(15, 23, 42, 0.9); font-weight: 600;">
                                    <?php foreach ($academic_sessions as $s): ?>
                                        <option value="<?= htmlspecialchars($s['sessionid']) ?>" <?= (($edit_exam && $edit_exam['sessionid'] === $s['sessionid']) || (!$edit_exam && !empty($s['is_current']))) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($s['sessionname']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label class="form-label" for="termid"><i class="fa-solid fa-clock-rotate-left"></i> Academic Term</label>
                                <select id="termid" name="termid" class="form-control" style="background: rgba(15, 23, 42, 0.9); font-weight: 600;">
                                    <?php foreach ($academic_terms as $t): ?>
                                        <option value="<?= htmlspecialchars($t['termid']) ?>" <?= ($edit_exam && $edit_exam['termid'] === $t['termid']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($t['termname']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <!-- TARGET SUBJECT SELECTOR -->
                        <div class="form-group">
                            <label class="form-label" for="subjectid">Target Subject</label>
                            <select id="subjectid" name="subjectid" class="form-control" onchange="updateSubjectName(this)" required style="background: rgba(15, 23, 42, 0.9); font-weight: 600;">
                                <option value="">-- Select Subject --</option>
                                <?php foreach ($school_subjects as $sb): ?>
                                    <option value="<?= htmlspecialchars($sb['subjectid']) ?>" data-name="<?= htmlspecialchars($sb['subjectname']) ?>" <?= ($edit_exam && ($edit_exam['subjectid'] === $sb['subjectid'] || $edit_exam['subjectname'] === $sb['subjectname'])) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($sb['subjectname']) ?> <?= !empty($sb['subjectabbreviation']) ? '('.htmlspecialchars($sb['subjectabbreviation']).')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" id="subjectname" name="subjectname" value="<?= $edit_exam ? htmlspecialchars($edit_exam['subjectname']) : '' ?>">
                        </div>

                        <!-- TARGET CLASS GROUP SELECTOR -->
                        <div class="form-group">
                            <label class="form-label" for="classgroupid">Target Class Group</label>
                            <select id="classgroupid" name="classgroupid" class="form-control" onchange="updateMarkDistributions()" required style="background: rgba(15, 23, 42, 0.9); font-weight: 600;">
                                <option value="">-- Select Target Class Group --</option>
                                <?php foreach ($class_groups as $cg): ?>
                                    <option value="<?= htmlspecialchars($cg['classgroupid']) ?>" <?= ($edit_exam && $edit_exam['classgroupid'] === $cg['classgroupid']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($cg['classgroupname']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- TARGET MARK DISTRIBUTION SELECTOR -->
                        <div class="form-group">
                            <label class="form-label" for="markdistid">Target Mark Distribution</label>
                            <select id="markdistid" name="markdistid" class="form-control" required style="background: rgba(15, 23, 42, 0.9); font-weight: 600;">
                                <option value="">-- Select Mark Distribution --</option>
                            </select>
                        </div>

                        <div class="grid-2" style="gap: 12px;">
                            <div class="form-group">
                                <label class="form-label" for="duration_minutes">Duration (Mins)</label>
                                <input type="number" id="duration_minutes" name="duration_minutes" class="form-control" value="<?= $edit_exam ? intval($edit_exam['duration_minutes']) : '40' ?>" required min="1">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="total_questions">Total Questions</label>
                                <input type="number" id="total_questions" name="total_questions" class="form-control" value="<?= $edit_exam ? intval($edit_exam['total_questions']) : '10' ?>" required min="1">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="passmark">Pass Mark (%)</label>
                            <input type="number" id="passmark" name="passmark" class="form-control" value="<?= $edit_exam ? intval($edit_exam['passmark']) : '50' ?>" required min="1" max="100">
                        </div>

                        <div class="form-group" style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" id="is_active" name="is_active" value="1" <?= (!$edit_exam || $edit_exam['is_active'] == 1) ? 'checked' : '' ?> style="width: 18px; height: 18px; cursor: pointer;">
                            <label for="is_active" style="cursor: pointer; font-weight: 600;">Activate Exam Immediately</label>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 10px;">
                            <i class="fa-solid fa-floppy-disk"></i> <?= $edit_exam ? 'Save Changes' : 'Create Exam' ?>
                        </button>
                        <?php if ($edit_exam): ?>
                            <a href="exams.php" class="btn" style="width: 100%; margin-top: 8px; background: rgba(255,255,255,0.08); text-align: center; color: var(--text-muted); text-decoration: none;">Cancel Editing</a>
                        <?php endif; ?>
                    </form>
                </div>

                <!-- EXAMS LIST TABLE -->
                <div class="card">
                    <div class="card-title"><i class="fa-solid fa-list-check"></i> Configured Offline CBT Exams</div>
                    <div class="card-desc">List of all active and inactive CBT exams configured locally on this server.</div>

                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Exam Title & Session</th>
                                    <th>Class Group</th>
                                    <th>Mark Distribution</th>
                                    <th>Duration</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $res_all = $conn->query("SELECT e.*, cg.classgroupname, s.sessionname, t.termname 
                                    FROM tbl_cbt_exams e 
                                    LEFT JOIN tbl_cbt_class_groups cg ON e.classgroupid = cg.classgroupid 
                                    LEFT JOIN tbl_cbt_sessions s ON e.sessionid = s.sessionid 
                                    LEFT JOIN tbl_cbt_terms t ON e.termid = t.termid 
                                    ORDER BY e.date_created DESC");
                                if ($res_all && $res_all->num_rows > 0):
                                    while ($ex = $res_all->fetch_assoc()):
                                        $q_count = $conn->query("SELECT COUNT(*) as cnt FROM tbl_cbt_questions WHERE examid = '" . $ex['examid'] . "'")->fetch_assoc()['cnt'];
                                        $cg_display = !empty($ex['classgroupname']) ? $ex['classgroupname'] : 'All Arms';
                                        $md_display = !empty($ex['markname']) ? $ex['markname'] : 'CBT FINAL';
                                        $sess_display = !empty($ex['sessionname']) ? $ex['sessionname'] : '2025/2026 Session';
                                        $term_display = !empty($ex['termname']) ? $ex['termname'] : 'First Term';
                                ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($ex['title']) ?></strong><br>
                                            <small style="color: var(--text-muted);"><?= htmlspecialchars($ex['subjectname']) ?></small>
                                            <small style="color: var(--accent); font-size: 11px; font-weight: 600; display: block; margin-top: 2px;">
                                                <i class="fa-solid fa-calendar"></i> <?= htmlspecialchars($sess_display) ?> &nbsp;|&nbsp; 
                                                <i class="fa-solid fa-clock-rotate-left"></i> <?= htmlspecialchars($term_display) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge-offline" style="background: rgba(99, 102, 241, 0.15); color: var(--primary);"><i class="fa-solid fa-users"></i> <?= htmlspecialchars($cg_display) ?></span>
                                        </td>
                                        <td>
                                            <span style="font-weight: 700; font-size: 13px; color: var(--accent);"><i class="fa-solid fa-award"></i> <?= htmlspecialchars($md_display) ?></span>
                                        </td>
                                        <td><?= $ex['duration_minutes'] ?> Mins (<?= $q_count ?> Qs)</td>
                                        <td>
                                            <a href="exams.php?toggle_active=<?= $ex['examid'] ?>" style="text-decoration: none;">
                                                <?php if ($ex['is_active'] == 1): ?>
                                                    <span class="badge-offline" style="display: inline-flex;"><i class="fa-solid fa-circle-check"></i> Active</span>
                                                <?php else: ?>
                                                    <span style="color: var(--text-muted); font-size: 13px; font-weight: 600;"><i class="fa-solid fa-circle-pause"></i> Inactive</span>
                                                <?php endif; ?>
                                            </a>
                                        </td>
                                        <td>
                                            <div style="display: flex; gap: 6px;">
                                                <a href="questions.php?examid=<?= $ex['examid'] ?>" class="btn" style="padding: 6px 10px; font-size: 12px; background: rgba(99, 102, 241, 0.2); color: var(--primary); text-decoration: none;" title="Manage Questions">
                                                    <i class="fa-solid fa-circle-question"></i> Questions
                                                </a>
                                                <a href="exams.php?edit=<?= $ex['examid'] ?>" class="btn" style="padding: 6px 10px; font-size: 12px; background: rgba(255, 255, 255, 0.1); color: var(--text-main); text-decoration: none;" title="Edit Exam">
                                                    <i class="fa-solid fa-pen"></i>
                                                </a>
                                                <a href="exams.php?delete_exam=<?= $ex['examid'] ?>" onclick="return confirm('Are you sure you want to delete this exam and all its questions?');" class="btn btn-danger" style="padding: 6px 10px; font-size: 12px; text-decoration: none;" title="Delete Exam">
                                                    <i class="fa-solid fa-trash"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php 
                                    endwhile;
                                else:
                                ?>
                                    <tr>
                                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 32px;">
                                            No CBT exams found locally. Use the form on the left to create an exam or perform a Downstream Sync.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- DYNAMIC MARK DISTRIBUTION FILTER JS -->
    <script>
        const allMarkDistributions = <?= json_encode($mark_distributions) ?>;
        const currentEditMarkDistId = <?= json_encode($edit_exam ? $edit_exam['markdistid'] : '') ?>;

        function updateSubjectName(selectElem) {
            const selectedOpt = selectElem.options[selectElem.selectedIndex];
            const name = selectedOpt.getAttribute('data-name') || selectedOpt.text;
            document.getElementById('subjectname').value = name;
        }

        function updateMarkDistributions() {
            const classgroupid = document.getElementById('classgroupid').value;
            const markDistSelect = document.getElementById('markdistid');
            markDistSelect.innerHTML = '<option value="">-- Select Mark Distribution --</option>';

            if (!classgroupid) return;

            const filtered = allMarkDistributions.filter(md => md.classgroupid === classgroupid);
            if (filtered.length > 0) {
                filtered.forEach(md => {
                    const opt = document.createElement('option');
                    opt.value = md.markdistid;
                    opt.textContent = `${md.markname} (${md.markscore} Marks)`;
                    if (md.markdistid === currentEditMarkDistId) {
                        opt.selected = true;
                    }
                    markDistSelect.appendChild(opt);
                });
            } else {
                const defaults = [
                    { id: `MD_1CA_${classgroupid}`, name: 'FIRST CA (20 Marks)' },
                    { id: `MD_2CA_${classgroupid}`, name: 'SECOND CA (20 Marks)' },
                    { id: `MD_EXAM_${classgroupid}`, name: 'EXAM (60 Marks)' }
                ];
                defaults.forEach(d => {
                    const opt = document.createElement('option');
                    opt.value = d.id;
                    opt.textContent = d.name;
                    if (d.id === currentEditMarkDistId) opt.selected = true;
                    markDistSelect.appendChild(opt);
                });
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            updateMarkDistributions();
            const subSelect = document.getElementById('subjectid');
            if (subSelect && subSelect.selectedIndex > 0) {
                updateSubjectName(subSelect);
            }
        });
    </script>
</body>
</html>