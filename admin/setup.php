<?php
require_once __DIR__ . '/../config/config.php';

$error   = '';
$success = '';

if (isset($_GET['error']) && empty($error)) {
    $error = trim($_GET['error']);
}

$input_schoolid = isset($_POST['schoolid']) ? trim($_POST['schoolid']) : (isset($_GET['schoolid']) ? trim($_GET['schoolid']) : '');
$input_online_url = isset($_POST['online_url']) && !empty($_POST['online_url'])
    ? rtrim(trim($_POST['online_url']), '/')
    : (isset($_GET['online_url']) && !empty($_GET['online_url']) ? rtrim(trim($_GET['online_url']), '/') : DEFAULT_ONLINE_URL);

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $schoolid   = $input_schoolid;
    $online_url = $input_online_url;

    if (empty($schoolid)) {
        $error = "Please enter your School ID to proceed.";
    } else {
        // STEP 1: First confirm if the school id entered is valid BEFORE proceeding to create the offline database
        $apiUrl = $online_url . "/api/cbt_sync_api.php?action=pull_data&schoolid=" . urlencode($schoolid);

        $response = false;
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $response = curl_exec($ch);
            curl_close($ch);
        }
        if (!$response) {
            $response = @file_get_contents($apiUrl);
        }

        $api_data = json_decode($response, true);

        // Smart Fallback to localhost if testing with external default URL
        if ((!$api_data || !isset($api_data['status']) || $api_data['status'] !== 'success') && strpos($online_url, 'localhost') === false) {
            $localApiUrl = "http://localhost/eduportal/api/cbt_sync_api.php?action=pull_data&schoolid=" . urlencode($schoolid);
            if (function_exists('curl_init')) {
                $ch2 = curl_init();
                curl_setopt($ch2, CURLOPT_URL, $localApiUrl);
                curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch2, CURLOPT_TIMEOUT, 5);
                $res2 = curl_exec($ch2);
                curl_close($ch2);
                $api_data2 = json_decode($res2, true);
                if ($api_data2 && isset($api_data2['status'])) {
                    $api_data = $api_data2;
                    $online_url = "http://localhost/eduportal";
                }
            }
        }

        // Check if API returns invalid school id or other error
        if (!$api_data || !isset($api_data['status']) || $api_data['status'] !== 'success') {
            $err_msg = isset($api_data['message']) ? $api_data['message'] : "Invalid or unregistered School ID.";
            $error = (strpos($err_msg, 'API Sync Error:') !== false) ? $err_msg : "API Sync Error: " . $err_msg;
            // Do NOT proceed with installation and database creation!
        } else {
            // STEP 2: School ID confirmed valid! Proceed with offline database creation and schema initialization
            $conn = get_db_connection(true);

            // Initialize Database Schema
            $schemaSql = file_get_contents(__DIR__ . '/../database/schema.sql');
            $conn->multi_query($schemaSql);
            while ($conn->next_result()) {;} // flush multi queries

            // Store initial school ID and target URL
            $stmt = $conn->prepare("REPLACE INTO tbl_cbt_school (schoolid, schoolname, online_url, last_synced_at) VALUES (?, 'Initializing...', ?, NOW())");
            $stmt->bind_param("ss", $schoolid, $online_url);

            if ($stmt->execute()) {
                header("Location: ../api/sync_pull.php?schoolid=" . urlencode($schoolid) . "&execute=1");
                exit;
            } else {
                $error = "Failed to initialize local database: " . $conn->error;
            }
        }
    }
}

// Detect if running as bundled server (not localhost/80)
$isPackaged   = defined('CBT_SERVER_PORT') && CBT_SERVER_PORT !== 80;
$lanIP        = get_local_server_ip();
$serverPort   = defined('CBT_SERVER_PORT') ? CBT_SERVER_PORT : 80;
$studentUrl   = ($serverPort === 80) ? "http://{$lanIP}" : "http://{$lanIP}:{$serverPort}";
$localhostUrl = ($serverPort === 80) ? "http://localhost" : "http://localhost:{$serverPort}";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>First-Time Setup | CBT Offline Exam System</title>
    <link rel="stylesheet" href="../assets/css/style.css?v=<?= time() ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .lan-banner {
            background: linear-gradient(135deg, #0f4c2a 0%, #1a7a42 100%);
            border: 1px solid #2db865;
            border-radius: 12px;
            padding: 18px 20px;
            margin-bottom: 24px;
            text-align: center;
        }
        .lan-banner h3 {
            font-size: 13px;
            font-weight: 600;
            color: #7ff5a0;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin: 0 0 8px 0;
        }
        .lan-url-box {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            background: rgba(0,0,0,0.3);
            border-radius: 8px;
            padding: 10px 14px;
            margin: 6px 0;
        }
        .lan-url-box code {
            font-size: 20px;
            font-weight: 700;
            color: #ffffff;
            font-family: 'Courier New', monospace;
            letter-spacing: 1px;
        }
        .copy-btn {
            background: rgba(255,255,255,0.15);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
            border-radius: 6px;
            padding: 4px 10px;
            cursor: pointer;
            font-size: 12px;
            transition: background 0.2s;
        }
        .copy-btn:hover { background: rgba(255,255,255,0.3); }
        .lan-banner small { color: #a8f0c0; font-size: 12px; }
        .step-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 26px; height: 26px;
            background: var(--primary);
            border-radius: 50%;
            color: white;
            font-size: 13px;
            font-weight: 700;
            margin-right: 8px;
            flex-shrink: 0;
        }
        .steps-list { list-style: none; padding: 0; margin: 0; text-align: left; }
        .steps-list li {
            display: flex;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            font-size: 14px;
            color: var(--text-muted);
        }
        .steps-list li:last-child { border-bottom: none; }
    </style>
</head>
<body class="centered-page" style="display:flex;flex-direction:column;justify-content:center;align-items:center;min-height:100vh;margin:0 auto;width:100%;padding:40px 20px;box-sizing:border-box;">
    <div class="container" style="max-width:560px;width:100%;margin:0 auto;">
        <div class="card" style="text-align:center;">

            <!-- Icon -->
            <div style="margin-bottom:16px;">
                <i class="fa-solid fa-server" style="font-size:52px;color:var(--primary);"></i>
            </div>

            <h1 class="card-title">CBT Offline — First Time Setup</h1>
            <p class="card-desc" style="margin-bottom:20px;">
                Welcome! Complete the steps below to get your exam server ready.
                This only takes a few minutes.
            </p>

            <!-- LAN URL Banner (only shown in packaged mode) -->
            <?php if ($isPackaged): ?>
            <div class="lan-banner">
                <h3><i class="fa-solid fa-wifi"></i> &nbsp;Student Access URL</h3>
                <div class="lan-url-box">
                    <code id="studentUrlCode"><?= htmlspecialchars($studentUrl) ?></code>
                    <button class="copy-btn" onclick="copyUrl()" title="Copy URL">
                        <i class="fa-regular fa-copy"></i> Copy
                    </button>
                </div>
                <small>Share this URL with student computers on the same network</small>
            </div>
            <?php endif; ?>

            <!-- Setup Steps -->
            <div style="background:rgba(255,255,255,0.04);border-radius:10px;padding:16px;margin-bottom:24px;">
                <ul class="steps-list">
                    <li><span class="step-badge">1</span> Enter your School ID below</li>
                    <li><span class="step-badge">2</span> System fetches your school data from the online portal</li>
                    <li><span class="step-badge">3</span> Students log in using the URL above and take exams offline</li>
                </ul>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger" style="text-align:left;">
                    <i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group" style="text-align:left;">
                    <label class="form-label" for="schoolid">
                        <i class="fa-solid fa-school"></i> School ID
                    </label>
                    <input type="text" id="schoolid" name="schoolid" class="form-control"
                           placeholder="Enter School ID" value="<?= htmlspecialchars($input_schoolid) ?>" required autofocus
                           style="font-size:18px;padding:14px;text-align:center;font-weight:700;letter-spacing:1px;">
                </div>

                <div style="margin-bottom:20px;text-align:left;">
                    <button type="button"
                        onclick="document.getElementById('advancedSettings').style.display = document.getElementById('advancedSettings').style.display === 'none' ? 'block' : 'none';"
                        style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:13px;">
                        <i class="fa-solid fa-gear"></i> Advanced — Server Host Settings
                    </button>
                    <div id="advancedSettings" style="<?= (!empty($input_online_url) && $input_online_url !== DEFAULT_ONLINE_URL) ? 'block' : 'none' ?>;margin-top:10px;">
                        <label class="form-label" for="online_url">Online Portal Base URL</label>
                        <input type="url" id="online_url" name="online_url" class="form-control"
                               value="<?= htmlspecialchars($input_online_url) ?>">
                        <small style="color:var(--text-muted);">Only change this if your school uses a custom portal URL.</small>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%;padding:14px;font-size:16px;">
                    <i class="fa-solid fa-download"></i> Fetch School Data &amp; Initialize
                </button>
            </form>

            <div style="margin-top:24px;font-size:13px;color:var(--text-muted);">
                <i class="fa-solid fa-wifi"></i> Requires a temporary internet connection during initial setup only
            </div>

        </div>
    </div>

    <script>
    function copyUrl() {
        const url = document.getElementById('studentUrlCode')?.textContent || '';
        navigator.clipboard.writeText(url).then(() => {
            const btn = document.querySelector('.copy-btn');
            btn.innerHTML = '<i class="fa-solid fa-check"></i> Copied!';
            setTimeout(() => btn.innerHTML = '<i class="fa-regular fa-copy"></i> Copy', 2000);
        });
    }
    </script>
</body>
</html>