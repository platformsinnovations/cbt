<?php
// Configuration & Helper functions for Standalone Offline CBT
if (!session_id()) {
    session_start();
}

define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'db_cbt_offline');
define('CBT_BASE_URL', 'http://localhost/cbt');

function get_db_connection($create_db_if_missing = true) {
    static $conn = null;
    if ($conn === null) {
        $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS);
        if ($conn->connect_error) {
            die("Database Connection Error: " . $conn->connect_error);
        }
        
        if ($create_db_if_missing) {
            $conn->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        }
        
        $conn->select_db(DB_NAME);
    }
    return $conn;
}

function get_school_config() {
    $conn = get_db_connection();
    $res = $conn->query("SELECT * FROM tbl_cbt_school LIMIT 1");
    if ($res && $res->num_rows > 0) {
        return $res->fetch_assoc();
    }
    return null;
}

function is_system_initialized() {
    $school = get_school_config();
    return !empty($school['schoolid']);
}

function is_admin_logged_in() {
    return isset($_SESSION['cbt_admin']) && !empty($_SESSION['cbt_admin']['username']);
}

function require_admin_login() {
    if (!is_system_initialized()) {
        header("Location: " . CBT_BASE_URL . "/admin/setup.php");
        exit;
    }
    if (!is_admin_logged_in()) {
        header("Location: " . CBT_BASE_URL . "/admin/login.php");
        exit;
    }
}

define('DEFAULT_ONLINE_URL', 'https://platformeduportal.com');
