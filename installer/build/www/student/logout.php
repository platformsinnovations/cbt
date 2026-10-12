<?php
require_once __DIR__ . '/../config/config.php';
unset($_SESSION['cbt_student']);
header("Location: login.php");
exit;