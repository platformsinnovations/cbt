<?php
require_once __DIR__ . '/../config/config.php';

$school = get_school_config();
if (!$school) {
    header("Location: ../admin/setup.php");
    exit;
}

if (isset($_GET['logout'])) {
    unset($_SESSION['cbt_student']);
    header("Location: login.php");
    exit;
}

$conn = get_db_connection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $regnumber = trim($_POST['regnumber']);

    if (empty($regnumber)) {
        $error = "Please enter your Registration/Admission Number.";
    } else {
        $stmt = $conn->prepare("SELECT * FROM tbl_cbt_students WHERE regnumber = ? OR studentid = ? LIMIT 1");
        $stmt->bind_param("ss", $regnumber, $regnumber);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows > 0) {
            $student = $res->fetch_assoc();
            $_SESSION['cbt_student'] = $student;
            header("Location: select_exam.php");
            exit;
        } else {
            $error = "Student Registration Number not found in local offline database. Please check your Registration Number or contact your exam invigilator.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student CBT Login | Offline System</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="centered-page" style="display: flex; flex-direction: column; justify-content: center; align-items: center; min-height: 100vh; margin: 0 auto; width: 100%; padding: 40px 20px; box-sizing: border-box;">
    <div class="container" style="max-width: 460px; width: 100%; margin: 0 auto;">
        <div class="card" style="text-align: center;">
            <div style="margin-bottom: 16px;">
                <i class="fa-solid fa-laptop-code" style="font-size: 48px; color: var(--primary);"></i>
            </div>
            <div class="card-title"><?= htmlspecialchars($school['schoolname']) ?></div>
            <div class="card-desc" style="margin-bottom: 28px;">Offline Computer-Based Testing Portal</div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger" style="text-align: left;"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group" style="text-align: left;">
                    <label class="form-label" for="regnumber" style="font-size: 15px; font-weight: 700;"><i class="fa-solid fa-id-card" style="color: var(--primary);"></i> Enter Registration / Admission Number</label>
                    <input type="text" id="regnumber" name="regnumber" class="form-control" placeholder="e.g. 20140361LC" style="font-size: 16px; padding: 14px;" required autofocus>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 14px; font-size: 16px; font-weight: 700; margin-top: 10px;">
                    <i class="fa-solid fa-right-to-bracket"></i> Login to Take Exam
                </button>
            </form>

            <div style="margin-top: 24px; font-size: 13px; color: var(--text-muted);">
                <i class="fa-solid fa-shield-halved"></i> 100% Offline Secured LAN Connection
            </div>
        </div>
    </div>
</body>
</html>