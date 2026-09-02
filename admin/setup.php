<?php
require_once __DIR__ . '/../config/config.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $schoolid = trim($_POST['schoolid']);
    $online_url = isset($_POST['online_url']) && !empty($_POST['online_url']) ? rtrim(trim($_POST['online_url']), '/') : 'http://localhost/eduportal';

    if (empty($schoolid)) {
        $error = "Please enter your School ID to proceed.";
    } else {
        $conn = get_db_connection();
        
        // Initialize Database Schema
        $schemaSql = file_get_contents(__DIR__ . '/../database/schema.sql');
        $conn->multi_query($schemaSql);
        while ($conn->next_result()) {;} // flush multi queries

        // Store initial school ID and target URL
        $stmt = $conn->prepare("REPLACE INTO tbl_cbt_school (schoolid, schoolname, online_url, last_synced_at) VALUES (?, 'Initializing...', ?, NOW())");
        $stmt->bind_param("ss", $schoolid, $online_url);
        
        if ($stmt->execute()) {
            // Redirect to Data Fetcher
            header("Location: ../api/sync_pull.php?schoolid=" . urlencode($schoolid) . "&execute=1");
            exit;
        } else {
            $error = "Failed to initialize local database: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>First-Time Setup | Offline CBT System</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="centered-page" style="display: flex; flex-direction: column; justify-content: center; align-items: center; min-height: 100vh; margin: 0 auto; width: 100%; padding: 40px 20px; box-sizing: border-box;">
    <div class="container" style="max-width: 520px; width: 100%; margin: 0 auto;">
        <div class="card" style="text-align: center;">
            <div style="margin-bottom: 16px;">
                <i class="fa-solid fa-cloud-arrow-down" style="font-size: 56px; color: var(--primary);"></i>
            </div>
            <h1 class="card-title">Offline CBT Setup</h1>
            <p class="card-desc" style="margin-bottom: 28px;">Enter your unique <strong>School ID</strong> below. The application will connect online, fetch your school's database, student profiles, and exam questions, and download them locally for 100% offline examination.</p>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger" style="text-align: left;"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group" style="text-align: left;">
                    <label class="form-label" for="schoolid"><i class="fa-solid fa-school"></i> Enter School ID</label>
                    <input type="text" id="schoolid" name="schoolid" class="form-control" placeholder="e.g. 9272819806" value="9272819806" required autofocus style="font-size: 18px; padding: 14px; text-align: center; font-weight: 700; letter-spacing: 1px;">
                </div>

                <div style="margin-bottom: 20px; text-align: left;">
                    <button type="button" onclick="document.getElementById('advancedSettings').style.display = document.getElementById('advancedSettings').style.display === 'none' ? 'block' : 'none';" style="background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 13px;">
                        <i class="fa-solid fa-gear"></i> Server Host Settings (Optional)
                    </button>
                    <div id="advancedSettings" style="display: none; margin-top: 10px;">
                        <label class="form-label" for="online_url">Online Eduportal Server Base URL</label>
                        <input type="url" id="online_url" name="online_url" class="form-control" value="https://platformeduportal.com">
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="width: 100%; padding: 14px; font-size: 16px;">
                    <i class="fa-solid fa-download"></i> Fetch School Data & Initialize CBT
                </button>
            </form>

            <div style="margin-top: 24px; font-size: 13px; color: var(--text-muted);">
                <i class="fa-solid fa-wifi"></i> Requires temporary internet connection during initial setup
            </div>
        </div>
    </div>
</body>
</html>