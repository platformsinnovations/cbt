<?php
// =============================================================================
// CBT Offline — Configuration & Helper Functions
// Compatible with PHP 5.6+ for development, optimised for PHP 8.x in bundle.
// Supports both development (XAMPP) and the bundled self-contained installer.
// =============================================================================

date_default_timezone_set('Africa/Lagos');

// Application Semantic Version (SemVer 2.0.0)
define('CBT_VERSION', '1.0.0');

if (!session_id()) {
    session_start();
}

// ---------------------------------------------------------------------------
// 1. Load configuration from config.ini (written by installer) if it exists.
//    Fall back to hardcoded XAMPP defaults for local development.
// ---------------------------------------------------------------------------
$_cbt_ini_path = __DIR__ . '/config.ini';
if (file_exists($_cbt_ini_path)) {
    $_cbt_cfg = parse_ini_file($_cbt_ini_path, true);
    define('DB_HOST',         isset($_cbt_cfg['database']['host']) ? $_cbt_cfg['database']['host'] : '127.0.0.1');
    define('DB_PORT',   (int)(isset($_cbt_cfg['database']['port']) ? $_cbt_cfg['database']['port'] : 3306));
    define('DB_USER',         isset($_cbt_cfg['database']['user']) ? $_cbt_cfg['database']['user'] : 'root');
    define('DB_PASS',         isset($_cbt_cfg['database']['pass']) ? $_cbt_cfg['database']['pass'] : '');
    define('DB_NAME',         isset($_cbt_cfg['database']['name']) ? $_cbt_cfg['database']['name'] : 'db_cbt_offline');
    define('CBT_SERVER_PORT',(int)(isset($_cbt_cfg['server']['port'])? $_cbt_cfg['server']['port'] : 8080));
} else {
    // Development fallback (XAMPP)
    define('DB_HOST', 'localhost');
    define('DB_PORT', 3306);
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_NAME', 'db_cbt_offline');
    define('CBT_SERVER_PORT', 80);
}

// ---------------------------------------------------------------------------
// 2. Auto-detect the server base URL so LAN students get correct links.
//    On XAMPP dev: http://localhost/cbt
//    On bundled server: http://192.168.x.x:8080
// ---------------------------------------------------------------------------
/**
 * Detects the web subpath of the CBT installation (e.g. '/cbt' on XAMPP, or '' at root).
 */
function get_cbt_subpath() {
    $cbt_root_dir = str_replace('\\', '/', dirname(__DIR__));
    $cbt_real = realpath($cbt_root_dir);
    if ($cbt_real !== false) {
        $cbt_real = str_replace('\\', '/', $cbt_real);
    } else {
        $cbt_real = $cbt_root_dir;
    }

    if (isset($_SERVER['DOCUMENT_ROOT']) && !empty($_SERVER['DOCUMENT_ROOT'])) {
        $doc_real = realpath($_SERVER['DOCUMENT_ROOT']);
        if ($doc_real !== false) {
            $doc_real = str_replace('\\', '/', $doc_real);
        } else {
            $doc_real = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
        }
        $doc_real = rtrim($doc_real, '/');

        if (!empty($doc_real) && strncasecmp($cbt_real, $doc_real, strlen($doc_real)) === 0) {
            $sub = substr($cbt_real, strlen($doc_real));
            return rtrim($sub, '/');
        }
    }

    // Secondary fallback based on SCRIPT_NAME / PHP_SELF
    if (isset($_SERVER['SCRIPT_NAME']) && !empty($_SERVER['SCRIPT_NAME'])) {
        $script_dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
        $known_subdirs = array('/admin', '/student', '/api', '/config');
        foreach ($known_subdirs as $kd) {
            if (substr($script_dir, -strlen($kd)) === $kd) {
                return rtrim(substr($script_dir, 0, -strlen($kd)), '/');
            }
        }
        if ($script_dir !== '/' && $script_dir !== '\\' && $script_dir !== '.') {
            return rtrim($script_dir, '/');
        }
    }

    return '';
}

function get_server_base_url() {
    $subpath = get_cbt_subpath();

    if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $_SERVER['HTTP_HOST'] . $subpath;
    }
    // Fallback: build from server IP
    $ip   = get_local_server_ip();
    $port = CBT_SERVER_PORT;
    if ($port === 80 || $port === 443) {
        return "http://{$ip}" . $subpath;
    }
    return "http://{$ip}:{$port}" . $subpath;
}

/**
 * Returns the LAN IP address of this machine — what students type in their browser.
 * Prefers the non-loopback IP on the LAN adapter.
 */
function get_local_server_ip() {
    // Try to get real LAN IP via a UDP trick (no actual packet sent)
    if (function_exists('socket_create')) {
        $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        if ($sock) {
            @socket_connect($sock, '8.8.8.8', 80);
            @socket_getsockname($sock, $addr);
            @socket_close($sock);
            if (!empty($addr) && $addr !== '0.0.0.0') {
                return $addr;
            }
        }
    }

    // Fallback: parse hostname
    $host = gethostname();
    if ($host) {
        $ip = gethostbyname($host);
        if ($ip !== $host) {
            return $ip;
        }
    }
    return '127.0.0.1';
}

define('CBT_BASE_URL', get_server_base_url());

// ---------------------------------------------------------------------------
// 3. Database connection (mysqli — unchanged from original)
// ---------------------------------------------------------------------------
function get_db_connection($create_db_if_missing = true) {
    static $conn = null;
    if ($conn === null) {
        try {
            $conn = @new mysqli(DB_HOST, DB_USER, DB_PASS, '', DB_PORT);
            if ($conn->connect_error) {
                die("Database Connection Error: " . htmlspecialchars($conn->connect_error) .
                    "<br><small>Host: " . htmlspecialchars(DB_HOST . ":" . DB_PORT) . "</small>");
            }
        } catch (mysqli_sql_exception $e) {
            die("<div style='font-family:sans-serif;padding:30px;max-width:600px;margin:50px auto;background:#fff3cd;border:1px solid #ffeeba;border-radius:8px;'>" .
                "<h3 style='color:#856404;margin-top:0;'>Database Connection Error</h3>" .
                "<p>" . htmlspecialchars($e->getMessage()) . "</p>" .
                "<p><small>Please make sure MariaDB is running on " . htmlspecialchars(DB_HOST . ":" . DB_PORT) . ".</small></p>" .
                "</div>");
        }
        if ($create_db_if_missing) {
            $conn->query("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "`
                          DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
        }
        $conn->select_db(DB_NAME);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

// ---------------------------------------------------------------------------
// 4. App helper functions (unchanged)
// ---------------------------------------------------------------------------
function get_school_config() {
    $conn = get_db_connection();
    $res  = $conn->query("SELECT * FROM tbl_cbt_school LIMIT 1");
    if ($res && $res->num_rows > 0) {
        return $res->fetch_assoc();
    }
    return null;
}

function is_system_initialized() {
    try {
        $school = get_school_config();
        return !empty($school['schoolid']);
    } catch (Exception $e) {
        return false;
    }
}

function get_active_academic_period() {
    $conn = get_db_connection();
    $school = get_school_config();
    $active_session_name = '';
    $active_session_id = !empty($school['current_sessionid']) ? $school['current_sessionid'] : '';
    if (!empty($active_session_id)) {
        $s_chk = $conn->query("SELECT sessionname FROM tbl_cbt_sessions WHERE sessionid = '" . $conn->real_escape_string($active_session_id) . "' LIMIT 1");
        if ($s_chk && $s_chk->num_rows > 0) {
            $active_session_name = $s_chk->fetch_assoc()['sessionname'];
        }
    }
    if (empty($active_session_name)) {
        $s_chk2 = $conn->query("SELECT sessionid, sessionname FROM tbl_cbt_sessions WHERE is_current = 1 LIMIT 1");
        if ($s_chk2 && $s_chk2->num_rows > 0) {
            $s_row2 = $s_chk2->fetch_assoc();
            $active_session_id = $s_row2['sessionid'];
            $active_session_name = $s_row2['sessionname'];
        }
    }

    $active_term_name = '';
    $active_term_id = !empty($school['current_termid']) ? $school['current_termid'] : '';
    if (!empty($active_term_id)) {
        $t_chk = $conn->query("SELECT termname FROM tbl_cbt_terms WHERE termid = '" . $conn->real_escape_string($active_term_id) . "' LIMIT 1");
        if ($t_chk && $t_chk->num_rows > 0) {
            $active_term_name = $t_chk->fetch_assoc()['termname'];
        }
    }
    if (empty($active_term_name)) {
        $t_chk2 = $conn->query("SELECT termid, termname FROM tbl_cbt_terms WHERE is_current = 1 LIMIT 1");
        if ($t_chk2 && $t_chk2->num_rows > 0) {
            $t_row2 = $t_chk2->fetch_assoc();
            $active_term_id = $t_row2['termid'];
            $active_term_name = $t_row2['termname'];
        }
    }

    return array(
        'session_id' => $active_session_id,
        'session_name' => $active_session_name,
        'term_id' => $active_term_id,
        'term_name' => $active_term_name
    );
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
