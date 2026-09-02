<?php
require_once __DIR__ . '/config/config.php';

if (!is_system_initialized()) {
    header("Location: admin/setup.php");
    exit;
}

header("Location: student/login.php");
exit;