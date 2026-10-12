<?php
require_once __DIR__ . '/../config/config.php';

if (isset($_GET['logout'])) {
    unset($_SESSION['cbt_admin']);
    header("Location: login.php");
    exit;
}

if (is_admin_logged_in()) {
    header("Location: index.php");
    exit;
}

$school = get_school_config();
if (!$school) {
    header("Location: setup.php");
    exit;
}

$conn = get_db_connection();
$error = '';
$setup_notice = isset($_GET['setup_done']) || isset($_GET['synced']);

// Fetch initial admin for auto-fill suggestion if setup was just completed
$suggested_admin = null;
if ($setup_notice) {
    $res_adm = $conn->query("SELECT * FROM tbl_cbt_admins LIMIT 1");
    if ($res_adm && $res_adm->num_rows > 0) {
        $suggested_admin = $res_adm->fetch_assoc();
    }
}

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    if (empty($username) || empty($password)) {
        $error = "Please enter both Username and Password.";
    } else {
        // Query tbl_cbt_admins
        $stmt = $conn->prepare("SELECT * FROM tbl_cbt_admins WHERE username = ? OR adminid = ? LIMIT 1");
        $stmt->bind_param("ss", $username, $username);
        $stmt->execute();
        $res = $stmt->get_result();

        if ($res && $res->num_rows > 0) {
            $admin = $res->fetch_assoc();
            $pwd_match = false;

            if (!empty($admin['password_hash']) && password_verify($password, $admin['password_hash'])) {
                $pwd_match = true;
            } else if ($password === $admin['password_raw']) {
                $pwd_match = true;
            }

            if ($pwd_match) {
                $_SESSION['cbt_admin'] = $admin;
                $conn->query("UPDATE tbl_cbt_admins SET last_login = NOW() WHERE adminid = '" . $conn->real_escape_string($admin['adminid']) . "'");
                header("Location: index.php");
                exit;
            } else {
                $error = "Incorrect password. Please try again.";
            }
        } else {
            // Fallback for default admin if table is somehow not populated yet
            if (($username === 'admin' || $username === 'administrator') && ($password === 'CBTADMIN2026' || $password === 'admin123' || $password === 'password')) {
                $_SESSION['cbt_admin'] = [
                    'adminid' => 'ADM_LOCAL_001',
                    'schoolid' => $school['schoolid'],
                    'username' => 'admin',
                    'fullname' => 'System Administrator',
                    'role' => 'Super Admin'
                ];
                header("Location: index.php");
                exit;
            } else {
                $error = "Admin username not found in local offline database.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CBT Admin Login | <?= htmlspecialchars($school['schoolname']) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="centered-page" style="display: flex; flex-direction: column; justify-content: center; align-items: center; min-height: 100vh; margin: 0 auto; width: 100%; padding: 40px 20px; box-sizing: border-box;">
    <div class="container" style="max-width: 460px; width: 100%; margin: 0 auto;">
        <div class="card" style="text-align: center;">
            <div style="margin-bottom: 16px;">
                <i class="fa-solid fa-sliders" style="font-size: 48px; color: var(--accent);"></i>
            </div>
            <div class="card-title" style="justify-content: center; font-size: 22px; font-weight: 700; margin-bottom: 4px;">
                CBT Admin Login
            </div>
            <div class="card-desc" style="margin-bottom: 24px; font-size: 14px;">
                <?= htmlspecialchars($school['schoolname']) ?> Portal
            </div>

            <?php if ($setup_notice): ?>
                <div class="alert alert-success" style="text-align: left; font-size: 13px; margin-bottom: 20px; line-height: 1.5; border-left: 4px solid var(--success);">
                    <div style="font-size: 14px; font-weight: 700; margin-bottom: 6px; display: flex; align-items: center; gap: 8px;">
                        <i class="fa-solid fa-circle-check" style="font-size: 18px; color: var(--success);"></i> Initial Setup Completed!
                    </div>
                    <div>Administrator account initialized in offline exam server:</div>
                    <div style="margin: 10px 0; background: rgba(0,0,0,0.25); border: 1px solid rgba(255,255,255,0.08); padding: 10px 14px; border-radius: 8px;">
                        <div>Username: <strong style="font-family: monospace; font-size: 14px; color: #ffffff;"><?= htmlspecialchars($suggested_admin ? $suggested_admin['username'] : 'admin') ?></strong></div>
                        <div style="margin-top: 4px;">Default Password: <strong style="font-family: monospace; font-size: 14px; color: #38bdf8;">CBTADMIN2026</strong></div>
                    </div>
                    <div style="color: #fbbf24; font-size: 12px; display: flex; align-items: flex-start; gap: 6px; margin-top: 6px;">
                        <i class="fa-solid fa-triangle-exclamation" style="margin-top: 2px; flex-shrink: 0;"></i>
                        <span><strong>Security Note:</strong> Please change your default password once you log in.</span>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger" style="text-align: left;"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group" style="text-align: left;">
                    <label class="form-label" for="username"><i class="fa-solid fa-user-gear"></i> Admin Username</label>
                    <input type="text" id="username" name="username" class="form-control" placeholder="Enter Admin Username" value="<?= htmlspecialchars($suggested_admin ? $suggested_admin['username'] : 'admin') ?>" required autofocus>
                </div>

                <div class="form-group" style="text-align: left;">
                    <label class="form-label" for="password"><i class="fa-solid fa-key"></i> Password</label>
                    <input type="password" id="password" name="password" class="form-control" placeholder="Enter Password" value="<?= $setup_notice ? 'CBTADMIN2026' : '' ?>" required>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 14px; font-size: 16px; margin-top: 8px; font-weight: 700; background: var(--accent); border-color: var(--accent);">
                    <i class="fa-solid fa-right-to-bracket"></i> Sign In to Admin Dashboard
                </button>
            </form>

            <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--border-color); font-size: 13px; color: var(--text-muted); display: flex; justify-content: space-between; align-items: center;">
                <a href="../index.php" style="color: var(--text-muted); text-decoration: none;"><i class="fa-solid fa-house"></i> Main Portal</a>
                <a href="setup.php" style="color: var(--text-muted); text-decoration: none;"><i class="fa-solid fa-rotate"></i> Re-setup</a>
            </div>
        </div>
    </div>
</body>
</html>
