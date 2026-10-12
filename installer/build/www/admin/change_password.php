<?php
require_once __DIR__ . '/../config/config.php';

require_admin_login();

$conn = get_db_connection();
$school = get_school_config();
$admin = $_SESSION['cbt_admin'];

$error = '';
$success = '';

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $current_password = isset($_POST['current_password']) ? trim($_POST['current_password']) : '';
    $new_password = isset($_POST['new_password']) ? trim($_POST['new_password']) : '';
    $confirm_password = isset($_POST['confirm_password']) ? trim($_POST['confirm_password']) : '';

    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = "All fields are required.";
    } else if (strlen($new_password) < 6) {
        $error = "New password must be at least 6 characters long.";
    } else if ($new_password !== $confirm_password) {
        $error = "New password and confirmation do not match.";
    } else {
        // Fetch current admin record from DB
        $stmt = $conn->prepare("SELECT * FROM tbl_cbt_admins WHERE adminid = ? OR username = ? LIMIT 1");
        $stmt->bind_param("ss", $admin['adminid'], $admin['username']);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows > 0) {
            $db_admin = $res->fetch_assoc();
            $curr_match = false;

            if (!empty($db_admin['password_hash']) && password_verify($current_password, $db_admin['password_hash'])) {
                $curr_match = true;
            } else if ($current_password === $db_admin['password_raw']) {
                $curr_match = true;
            }

            if (!$curr_match) {
                $error = "Current password is incorrect.";
            } else {
                // Securely hash the new password
                $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $new_raw = $new_password;

                $update_stmt = $conn->prepare("UPDATE tbl_cbt_admins SET password_hash = ?, password_raw = ? WHERE adminid = ?");
                $update_stmt->bind_param("sss", $new_hash, $new_raw, $db_admin['adminid']);
                
                if ($update_stmt->execute()) {
                    $_SESSION['cbt_admin']['password_raw'] = $new_raw;
                    $_SESSION['cbt_admin']['password_hash'] = $new_hash;
                    $success = "Password changed successfully! Keep your new password safe.";
                } else {
                    $error = "Failed to update password in offline database: " . $conn->error;
                }
            }
        } else {
            $error = "Admin account not found in database.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Admin Password | <?= htmlspecialchars($school['schoolname']) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body style="display: block; padding: 0;">
    <div class="app-layout">
        <!-- SIDEBAR NAVIGATION -->
        <?php 
        $active_nav = 'change_password';
        include __DIR__ . '/sidebar.php'; 
        ?>

        <!-- MAIN CONTENT AREA -->
        <main class="main-content">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                <div>
                    <h1 style="font-size: 24px; font-weight: 700; margin: 0; display: flex; align-items: center; gap: 10px;">
                        <i class="fa-solid fa-key" style="color: var(--accent);"></i> Change Admin Password
                    </h1>
                    <div style="color: var(--text-muted); font-size: 14px; margin-top: 4px;">
                        Update login credentials for offline administrator: <strong><?= htmlspecialchars($admin['username']) ?></strong>
                    </div>
                </div>
                <div>
                    <a href="index.php" class="btn btn-outline" style="text-decoration: none;">
                        <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
                    </a>
                </div>
            </div>

            <div class="card" style="max-width: 600px;">
                <div class="card-title">
                    <i class="fa-solid fa-shield-halved"></i> Update Account Security
                </div>
                <div class="card-desc" style="margin-bottom: 20px;">
                    Enter your current password (default: <code>CBTADMIN2026</code>) and choose a secure new password.
                </div>

                <?php if (!empty($success)): ?>
                    <div class="alert alert-success" style="margin-bottom: 20px; border-left: 4px solid var(--success);">
                        <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($success) ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger" style="margin-bottom: 20px; border-left: 4px solid var(--danger);">
                        <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="form-group">
                        <label class="form-label" for="current_password">
                            <i class="fa-solid fa-lock"></i> Current Password
                        </label>
                        <input type="password" id="current_password" name="current_password" class="form-control" placeholder="Enter current password (e.g. CBTADMIN2026)" required autofocus>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="new_password">
                            <i class="fa-solid fa-key"></i> New Password
                        </label>
                        <input type="password" id="new_password" name="new_password" class="form-control" placeholder="Enter new password (min. 6 characters)" minlength="6" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="confirm_password">
                            <i class="fa-solid fa-circle-check"></i> Confirm New Password
                        </label>
                        <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Re-enter new password" minlength="6" required>
                    </div>

                    <div style="margin-top: 24px; display: flex; gap: 12px; align-items: center;">
                        <button type="submit" class="btn btn-primary" style="padding: 12px 24px; font-weight: 700; background: var(--accent); border-color: var(--accent);">
                            <i class="fa-solid fa-floppy-disk"></i> Save New Password
                        </button>
                        <a href="index.php" class="btn btn-outline" style="text-decoration: none;">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </main>
    </div>
</body>
</html>
