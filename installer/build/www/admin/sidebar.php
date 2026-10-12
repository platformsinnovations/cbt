<?php
// =============================================================================
// Unified Sidebar Navigation for Offline CBT Admin & Sync Views
// =============================================================================
if (!isset($admin) && isset($_SESSION['cbt_admin'])) {
    $admin = $_SESSION['cbt_admin'];
}

$script_name = basename($_SERVER['SCRIPT_NAME'], '.php');
$current_nav = isset($active_nav) ? $active_nav : $script_name;

$is_in_api = (basename(dirname($_SERVER['SCRIPT_NAME'])) === 'api');
$admin_path = $is_in_api ? '../admin/' : '';
$api_path = $is_in_api ? '' : '../api/';
$student_path = '../student/';

$academic_period = function_exists('get_active_academic_period') ? get_active_academic_period() : array();
$nav_session_name = isset($active_session_name) && !empty($active_session_name) 
    ? $active_session_name 
    : (isset($academic_period['session_name']) ? $academic_period['session_name'] : '');
$nav_term_name = isset($active_term_name) && !empty($active_term_name) 
    ? $active_term_name 
    : (isset($academic_period['term_name']) ? $academic_period['term_name'] : '');
?>
<!-- SIDEBAR NAVIGATION -->
<aside class="sidebar no-print">
    <div>
        <div class="sidebar-brand">
            <i class="fa-solid fa-laptop-code"></i>
            <div>
                EduPortal
                <span>Offline CBT Admin <small style="color: var(--accent); font-weight: 700; font-size: 10px;">v<?= defined('CBT_VERSION') ? CBT_VERSION : '1.0.0' ?></small></span>
            </div>
        </div>
        
        <?php if (!empty($admin)): ?>
            <div style="padding: 10px 14px; margin-bottom: 12px; background: rgba(255,255,255,0.03); border-radius: 8px; border: 1px solid var(--border-color);">
                <div style="font-size: 10px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px;">Admin Logged In:</div>
                <div style="font-weight: 700; font-size: 13px; color: var(--text-main); text-overflow: ellipsis; overflow: hidden; white-space: nowrap;">
                    <?= htmlspecialchars(!empty($admin['fullname']) ? $admin['fullname'] : $admin['username']) ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($nav_session_name) || !empty($nav_term_name)): ?>
            <div style="padding: 10px 14px; margin-bottom: 16px; background: rgba(37,99,235,0.08); border-radius: 8px; border: 1px solid rgba(59,130,246,0.3);">
                <div style="font-size: 10px; color: #93c5fd; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700;">
                    <i class="fa-solid fa-calendar-check"></i> Academic Period
                </div>
                <?php if (!empty($nav_session_name)): ?>
                    <div style="font-weight: 700; font-size: 13px; color: #ffffff; margin-top: 3px;">
                        <?= htmlspecialchars($nav_session_name) ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($nav_term_name)): ?>
                    <div style="font-size: 12px; color: #34d399; font-weight: 600; margin-top: 2px;">
                        <?= htmlspecialchars($nav_term_name) ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <nav class="sidebar-menu">
            <a href="<?= $admin_path ?>index.php" class="sidebar-link <?= ($current_nav === 'index' || $current_nav === 'dashboard') ? 'active' : '' ?>">
                <i class="fa-solid fa-gauge-high"></i> Dashboard
            </a>
            <a href="<?= $admin_path ?>reports.php" class="sidebar-link <?= ($current_nav === 'reports') ? 'active' : '' ?>">
                <i class="fa-solid fa-chart-pie"></i> Reports
            </a>
            <a href="<?= $admin_path ?>exams.php" class="sidebar-link <?= ($current_nav === 'exams') ? 'active' : '' ?>">
                <i class="fa-solid fa-list-check"></i> Manage Exams
            </a>
            <a href="<?= $admin_path ?>questions.php" class="sidebar-link <?= ($current_nav === 'questions') ? 'active' : '' ?>">
                <i class="fa-solid fa-layer-group"></i> Question Bank
            </a>
            <a href="<?= $admin_path ?>students.php" class="sidebar-link <?= ($current_nav === 'students') ? 'active' : '' ?>">
                <i class="fa-solid fa-users"></i> Student Roster
            </a>
            <a href="<?= $api_path ?>sync_pull.php" class="sidebar-link <?= ($current_nav === 'sync_pull') ? 'active' : '' ?>">
                <i class="fa-solid fa-cloud-arrow-down"></i> Pull Online Data
            </a>
            <a href="<?= $api_path ?>sync_push.php" class="sidebar-link <?= ($current_nav === 'sync_push') ? 'active' : '' ?>">
                <i class="fa-solid fa-cloud-arrow-up"></i> Push Scores Online
            </a>
            <a href="<?= $admin_path ?>change_password.php" class="sidebar-link <?= ($current_nav === 'change_password') ? 'active' : '' ?>">
                <i class="fa-solid fa-key"></i> Change Password
            </a>
            <a href="<?= $admin_path ?>reset.php" class="sidebar-link <?= ($current_nav === 'reset') ? 'active' : '' ?>">
                <i class="fa-solid fa-rotate"></i> Reset Data
            </a>
            <a href="<?= $admin_path ?>login.php?logout=1" class="sidebar-link" style="color: var(--danger); margin-top: 8px;">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
        </nav>
    </div>

    <div>
        <a href="<?= $student_path ?>login.php" target="_blank" class="btn-launch-student">
            <i class="fa-solid fa-up-right-from-square"></i> Launch Student Exam
        </a>
    </div>
</aside>
