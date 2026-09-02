<?php
require_once __DIR__ . '/../config/config.php';

require_admin_login();

$conn = get_db_connection();
$school = get_school_config();
$schoolid = $school['schoolid'];
$admin = $_SESSION['cbt_admin'];

$selected_class = isset($_GET['classid']) ? trim($_GET['classid']) : '';
$search_query = isset($_GET['q']) ? trim($_GET['q']) : '';

// Fetch all distinct classes / class names for filter dropdown with student counts
$all_classes = [];
$seen_classes = [];

// 1. Classes with actual students
$res_c1 = $conn->query("SELECT DISTINCT classid, classname, classgroupid FROM tbl_cbt_students WHERE classname IS NOT NULL AND classname != '' ORDER BY classname ASC");
if ($res_c1) {
    while ($c = $res_c1->fetch_assoc()) {
        $val = !empty($c['classid']) ? $c['classid'] : (!empty($c['classgroupid']) ? $c['classgroupid'] : $c['classname']);
        if (!isset($seen_classes[$val])) {
            $esc_v = $conn->real_escape_string($val);
            $esc_n = $conn->real_escape_string($c['classname']);
            $count = $conn->query("SELECT COUNT(*) as cnt FROM tbl_cbt_students WHERE classid = '$esc_v' OR classgroupid = '$esc_v' OR classname = '$esc_n'")->fetch_assoc()['cnt'];
            $seen_classes[$val] = true;
            $all_classes[] = [
                'value' => $val,
                'name'  => $c['classname'],
                'label' => $c['classname'] . " (" . number_format($count) . " student" . ($count === 1 ? "" : "s") . ")"
            ];
        }
    }
}

// 2. Class Groups from tbl_cbt_class_groups
$res_c2 = $conn->query("SELECT DISTINCT classgroupid, classgroupname FROM tbl_cbt_class_groups WHERE classgroupname IS NOT NULL AND classgroupname != '' ORDER BY classgroupname ASC");
if ($res_c2) {
    while ($c = $res_c2->fetch_assoc()) {
        $val = $c['classgroupid'];
        if (!isset($seen_classes[$val])) {
            $esc_v = $conn->real_escape_string($val);
            $esc_n = $conn->real_escape_string($c['classgroupname']);
            $count = $conn->query("SELECT COUNT(*) as cnt FROM tbl_cbt_students WHERE classgroupid = '$esc_v' OR classid = '$esc_v' OR classname = '$esc_n' OR classname LIKE '%$esc_n%'")->fetch_assoc()['cnt'];
            $seen_classes[$val] = true;
            $all_classes[] = [
                'value' => $val,
                'name'  => $c['classgroupname'],
                'label' => $c['classgroupname'] . ($count > 0 ? " (" . number_format($count) . " students)" : " (0 students)")
            ];
        }
    }
}

// 3. Classes from tbl_cbt_classes
$res_c3 = $conn->query("SELECT DISTINCT classid, classname FROM tbl_cbt_classes WHERE classname IS NOT NULL AND classname != '' ORDER BY classname ASC");
if ($res_c3) {
    while ($c = $res_c3->fetch_assoc()) {
        $val = !empty($c['classid']) ? $c['classid'] : $c['classname'];
        if (!isset($seen_classes[$val])) {
            $esc_v = $conn->real_escape_string($val);
            $esc_n = $conn->real_escape_string($c['classname']);
            $count = $conn->query("SELECT COUNT(*) as cnt FROM tbl_cbt_students WHERE classid = '$esc_v' OR classname = '$esc_n'")->fetch_assoc()['cnt'];
            $seen_classes[$val] = true;
            $all_classes[] = [
                'value' => $val,
                'name'  => $c['classname'],
                'label' => $c['classname'] . ($count > 0 ? " (" . number_format($count) . " students)" : " (0 students)")
            ];
        }
    }
}

// Build student query (Only execute if filter is applied)
$has_filter = (!empty($selected_class) || !empty($search_query));
$students = [];
$selected_class_name = '';

if ($has_filter) {
    $where_clauses = ["1=1"];
    if (!empty($selected_class)) {
        $esc_class = $conn->real_escape_string($selected_class);
        
        // Find human readable name of selected class/group if an ID was passed
        $cg_res = $conn->query("SELECT classgroupname FROM tbl_cbt_class_groups WHERE classgroupid = '$esc_class' LIMIT 1");
        if ($cg_res && $cg_res->num_rows > 0) {
            $selected_class_name = $cg_res->fetch_assoc()['classgroupname'];
        } else {
            foreach ($all_classes as $ac) {
                if ($ac['value'] === $selected_class) {
                    $selected_class_name = $ac['name'];
                    break;
                }
            }
        }
        if (empty($selected_class_name)) $selected_class_name = $selected_class;

        $esc_name = $conn->real_escape_string($selected_class_name);
        $where_clauses[] = "(classid = '$esc_class' OR classgroupid = '$esc_class' OR classname = '$esc_class' OR classname = '$esc_name' OR classname LIKE '%$esc_name%')";
    }
    
    if (!empty($search_query)) {
        $esc_q = $conn->real_escape_string($search_query);
        $where_clauses[] = "(regnumber LIKE '%$esc_q%' OR firstname LIKE '%$esc_q%' OR lastname LIKE '%$esc_q%' OR studentid LIKE '%$esc_q%')";
    }

    $where_sql = implode(' AND ', $where_clauses);
    $res_students = $conn->query("SELECT * FROM tbl_cbt_students WHERE $where_sql ORDER BY classname ASC, regnumber ASC");
    if ($res_students) {
        while ($st = $res_students->fetch_assoc()) {
            $students[] = $st;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Roster & CBT Exam Slips | Offline Admin</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @media print {
            body {
                background: #fff !important;
                color: #000 !important;
            }
            .sidebar, .navbar, .no-print, .btn, .card-filter {
                display: none !important;
            }
            .main-content {
                margin: 0 !important;
                padding: 0 !important;
                width: 100% !important;
            }
            .print-grid {
                display: grid !important;
                grid-template-columns: repeat(2, 1fr) !important;
                gap: 15px !important;
                page-break-inside: auto;
            }
            .exam-slip {
                border: 2px dashed #333 !important;
                border-radius: 8px !important;
                padding: 16px !important;
                background: #fff !important;
                color: #000 !important;
                page-break-inside: avoid;
            }
            .slip-title {
                font-size: 16px !important;
                font-weight: bold !important;
                border-bottom: 1px solid #ccc;
                padding-bottom: 6px;
                margin-bottom: 8px;
                text-align: center;
            }
            .slip-detail {
                font-size: 13px !important;
                margin-bottom: 4px;
            }
            .slip-pin {
                margin-top: 10px;
                background: #f0f0f0 !important;
                padding: 8px;
                text-align: center;
                border-radius: 6px;
                font-weight: bold;
                font-size: 14px !important;
                color: #000 !important;
                border: 1px solid #ccc !important;
            }
        }

        .exam-slip-preview {
            background: rgba(15, 23, 42, 0.6);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            position: relative;
        }
    </style>
</head>
<body style="display: block; padding: 0;">
    <div class="app-layout">
        <!-- SIDEBAR NAVIGATION -->
        <aside class="sidebar no-print">
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
                    <a href="exams.php" class="sidebar-link">
                        <i class="fa-solid fa-list-check"></i> Manage Exams
                    </a>
                    <a href="questions.php" class="sidebar-link">
                        <i class="fa-solid fa-layer-group"></i> Question Bank
                    </a>
                    <a href="students.php" class="sidebar-link active">
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
            <!-- FILTER & SEARCH CARD -->
            <div class="card card-filter no-print" style="margin-bottom: 24px; padding: 24px 32px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <div class="card-title" style="margin-bottom: 4px;"><i class="fa-solid fa-users" style="color: var(--primary);"></i> Student Management & CBT Exam Registration Slips</div>
                        <div class="card-desc">Filter students by class, view registration credentials, or print exam admission slips.</div>
                    </div>
                    <?php if (!empty($students)): ?>
                        <button onclick="window.print()" class="btn btn-primary" style="background: var(--success); border-color: var(--success); font-weight: 700; padding: 10px 18px;">
                            <i class="fa-solid fa-print"></i> Print Exam Admission Slips
                        </button>
                    <?php endif; ?>
                </div>

                <form method="GET" action="students.php" style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
                    <div style="flex: 1; min-width: 220px;">
                        <label class="form-label" for="classid"><i class="fa-solid fa-filter"></i> Filter by Class</label>
                        <select id="classid" name="classid" class="form-control" onchange="this.form.submit()" style="background: rgba(15, 23, 42, 0.9); font-size: 15px; font-weight: 600;">
                            <option value="">-- Select Class --</option>
                            <?php foreach ($all_classes as $c): ?>
                                <option value="<?= htmlspecialchars($c['value']) ?>" <?= ($selected_class === $c['value'] || $selected_class === $c['label']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['label']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div style="flex: 1.5; min-width: 250px;">
                        <label class="form-label" for="q"><i class="fa-solid fa-magnifying-glass"></i> Search Student</label>
                        <input type="text" id="q" name="q" class="form-control" placeholder="Search by Reg No, First Name, Last Name..." value="<?= htmlspecialchars($search_query) ?>">
                    </div>

                    <div style="margin-top: 24px;">
                        <button type="submit" class="btn btn-primary" style="padding: 10px 20px; font-weight: 700;">
                            <i class="fa-solid fa-sliders"></i> Apply Filter
                        </button>
                        <?php if (!empty($selected_class) || !empty($search_query)): ?>
                            <a href="students.php" class="btn" style="background: rgba(255,255,255,0.08); color: var(--text-muted); text-decoration: none; padding: 10px 16px; margin-left: 8px;">Reset</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <!-- PRINTABLE SLIPS CONTAINER / ROSTER -->
            <?php if ($has_filter): ?>
                <?php if (!empty($students)): ?>
                    <div class="print-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 20px;">
                        <?php foreach ($students as $st): ?>
                            <div class="exam-slip exam-slip-preview">
                                <div class="slip-title" style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; border-bottom: 1px solid var(--border-color); padding-bottom: 8px;">
                                    <div style="font-weight: 700; font-size: 15px; color: var(--text-main);">
                                        <i class="fa-solid fa-graduation-cap" style="color: var(--primary);"></i> <?= htmlspecialchars($school['schoolname']) ?>
                                    </div>
                                    <span class="badge-offline" style="font-size: 10px; padding: 2px 6px;">CBT SLIP</span>
                                </div>

                                <div class="slip-detail" style="font-size: 14px; margin-bottom: 6px;">
                                    <strong>Student Name:</strong> <?= htmlspecialchars($st['lastname'] . ' ' . $st['firstname'] . ' ' . $st['middlename']) ?>
                                </div>
                                <div class="slip-detail" style="font-size: 14px; margin-bottom: 6px;">
                                    <strong>Registration No:</strong> <span style="color: var(--accent); font-weight: 700; font-family: monospace; font-size: 15px;"><?= htmlspecialchars($st['regnumber']) ?></span>
                                </div>
                                <div class="slip-detail" style="font-size: 13px; margin-bottom: 6px; color: var(--text-muted);">
                                    <strong>Class:</strong> <?= htmlspecialchars($st['classname']) ?> &nbsp;|&nbsp; <strong>Gender:</strong> <?= htmlspecialchars(isset($st['gender']) ? $st['gender'] : 'N/A') ?>
                                </div>

                                <div class="slip-pin" style="margin-top: 12px; background: rgba(99, 102, 241, 0.15); border: 1px dashed var(--primary); padding: 10px; border-radius: 8px; text-align: center;">
                                    <div style="font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">CBT Login Reg Number</div>
                                    <div style="font-size: 18px; font-weight: 800; color: var(--accent); font-family: monospace; letter-spacing: 1px; margin-top: 2px;">
                                        <?= htmlspecialchars($st['regnumber']) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="card" style="text-align: center; padding: 48px;">
                        <i class="fa-solid fa-users-slash" style="font-size: 48px; color: var(--text-muted); margin-bottom: 16px;"></i>
                        <h2>No Students Found in <?= htmlspecialchars(!empty($selected_class_name) ? $selected_class_name : 'Selected Filter') ?></h2>
                        <p style="color: var(--text-muted); max-width: 460px; margin: 8px auto 16px; line-height: 1.5;">
                            No student records match <strong><?= htmlspecialchars(!empty($selected_class_name) ? $selected_class_name : $selected_class) ?></strong> in the local database.
                        </p>
                        <?php
                        // Check if students exist in other classes to offer quick switch button
                        $res_avail = $conn->query("SELECT classid, classname, COUNT(*) as cnt FROM tbl_cbt_students GROUP BY classname ORDER BY cnt DESC LIMIT 1");
                        if ($res_avail && $res_avail->num_rows > 0):
                            $avail = $res_avail->fetch_assoc();
                        ?>
                            <div style="margin-top: 12px;">
                                <a href="students.php?classid=<?= urlencode(!empty($avail['classid']) ? $avail['classid'] : $avail['classname']) ?>" class="btn btn-primary" style="padding: 10px 18px; font-weight: 700;">
                                    <i class="fa-solid fa-arrow-right"></i> View <?= htmlspecialchars($avail['classname']) ?> (<?= number_format($avail['cnt']) ?> Students Registered)
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <!-- NO FILTER APPLIED PROMPT -->
                <div class="card" style="text-align: center; padding: 60px 20px; max-width: 800px; margin: 0 auto;">
                    <div style="font-size: 56px; color: var(--primary); margin-bottom: 16px;">
                        <i class="fa-solid fa-users"></i>
                    </div>
                    <h2 style="font-size: 22px; font-weight: 700; margin-bottom: 8px; color: var(--text-main);">No Class Selected</h2>
                    <p style="color: var(--text-muted); max-width: 480px; margin: 0 auto; line-height: 1.6; font-size: 15px;">
                        Please select a class from the <strong>"Filter by Class"</strong> dropdown above or enter a search query to load students and print CBT exam registration slips.
                    </p>
                </div>
            <?php endif; ?>
        </main>
    </div>
</body>
</html>
