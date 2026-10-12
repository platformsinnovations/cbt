-- --------------------------------------------------------
-- Standalone Offline CBT System Database Schema
-- Database Name: db_cbt_offline
-- Location: C:\xampp\htdocs\cbt\database\schema.sql
-- --------------------------------------------------------

CREATE DATABASE IF NOT EXISTS `db_cbt_offline` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `db_cbt_offline`;

-- 1. SCHOOL CONFIGURATION
CREATE TABLE IF NOT EXISTS `tbl_cbt_school` (
    `schoolid` VARCHAR(50) NOT NULL PRIMARY KEY,
    `schoolname` VARCHAR(255) NOT NULL,
    `schooladdress` TEXT,
    `logo_path` VARCHAR(255),
    `online_url` VARCHAR(255) NOT NULL,
    `current_sessionid` VARCHAR(50),
    `current_termid` VARCHAR(50),
    `sync_key` VARCHAR(100),
    `last_synced_at` DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. CLASS GROUPS
CREATE TABLE IF NOT EXISTS `tbl_cbt_class_groups` (
    `classgroupid` VARCHAR(50) NOT NULL PRIMARY KEY,
    `classgroupname` VARCHAR(100) NOT NULL,
    `classgrouplevel` INT DEFAULT 0,
    `schoolid` VARCHAR(50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. MARK DISTRIBUTIONS
CREATE TABLE IF NOT EXISTS `tbl_cbt_mark_distributions` (
    `markdistid` VARCHAR(50) NOT NULL,
    `sessionid` VARCHAR(50) DEFAULT '',
    `termid` VARCHAR(50) DEFAULT '',
    `classgroupid` VARCHAR(50) NOT NULL,
    `markname` VARCHAR(100) NOT NULL,
    `markscore` INT DEFAULT 0,
    PRIMARY KEY (`markdistid`, `sessionid`, `termid`, `classgroupid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. ACADEMIC SESSIONS & TERMS
CREATE TABLE IF NOT EXISTS `tbl_cbt_sessions` (
    `sessionid` VARCHAR(50) NOT NULL PRIMARY KEY,
    `sessionname` VARCHAR(100) NOT NULL,
    `is_current` TINYINT(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `tbl_cbt_terms` (
    `termid` VARCHAR(50) NOT NULL PRIMARY KEY,
    `termname` VARCHAR(100) NOT NULL,
    `termposition` VARCHAR(50),
    `is_current` TINYINT(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. CLASSES
CREATE TABLE IF NOT EXISTS `tbl_cbt_classes` (
    `classid` VARCHAR(50) NOT NULL PRIMARY KEY,
    `classname` VARCHAR(100) NOT NULL,
    `classgroupid` VARCHAR(50),
    `classcategoryid` VARCHAR(50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. SUBJECTS
CREATE TABLE IF NOT EXISTS `tbl_cbt_subjects` (
    `subjectid` VARCHAR(50) NOT NULL PRIMARY KEY,
    `schoolid` VARCHAR(50),
    `subjectname` VARCHAR(150) NOT NULL,
    `subjectcode` VARCHAR(50),
    `subjectabbreviation` VARCHAR(50)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. STUDENTS ROSTER
CREATE TABLE IF NOT EXISTS `tbl_cbt_students` (
    `studentid` VARCHAR(50) NOT NULL PRIMARY KEY,
    `schoolid` VARCHAR(50) NOT NULL,
    `sessionid` VARCHAR(50) DEFAULT '',
    `termid` VARCHAR(50) DEFAULT '',
    `regnumber` VARCHAR(50) NOT NULL,
    `firstname` VARCHAR(100) NOT NULL,
    `lastname` VARCHAR(100) NOT NULL,
    `middlename` VARCHAR(100) DEFAULT '',
    `classgroupid` VARCHAR(50) DEFAULT '',
    `classid` VARCHAR(50) NOT NULL,
    `classname` VARCHAR(50) NOT NULL,
    `password_raw` VARCHAR(100),
    `pin` VARCHAR(50),
    `gender` VARCHAR(20) DEFAULT 'Male',
    UNIQUE KEY `idx_school_reg` (`schoolid`, `regnumber`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. EXAMS
CREATE TABLE IF NOT EXISTS `tbl_cbt_exams` (
    `examid` VARCHAR(50) NOT NULL PRIMARY KEY,
    `schoolid` VARCHAR(50) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `sessionid` VARCHAR(50) NOT NULL,
    `termid` VARCHAR(50) NOT NULL,
    `classgroupid` VARCHAR(50) DEFAULT '',
    `classid` VARCHAR(50) NOT NULL,
    `subjectid` VARCHAR(50) NOT NULL,
    `subjectname` VARCHAR(150) NOT NULL,
    `markdistid` VARCHAR(50) DEFAULT '',
    `markname` VARCHAR(100) DEFAULT '',
    `exam_date` DATE DEFAULT NULL,
    `end_date` DATE DEFAULT NULL,
    `start_time` TIME DEFAULT '08:00:00',
    `end_time` TIME DEFAULT '17:00:00',
    `duration_minutes` INT NOT NULL DEFAULT 60,
    `total_questions` INT NOT NULL DEFAULT 40,
    `passmark` INT DEFAULT 50,
    `randomize_questions` TINYINT(1) DEFAULT 1,
    `randomize_options` TINYINT(1) DEFAULT 1,
    `is_active` TINYINT(1) DEFAULT 1,
    `date_created` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. QUESTIONS & OPTIONS
CREATE TABLE IF NOT EXISTS `tbl_cbt_questions` (
    `questionid` VARCHAR(50) NOT NULL PRIMARY KEY,
    `examid` VARCHAR(50) NOT NULL,
    `question_text` LONGTEXT NOT NULL,
    `image_url` VARCHAR(255) DEFAULT NULL,
    `option_a` TEXT,
    `option_b` TEXT,
    `option_c` TEXT,
    `option_d` TEXT,
    `option_e` TEXT,
    `correct_option` CHAR(1) NOT NULL,
    `mark` INT DEFAULT 1,
    KEY `idx_exam` (`examid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. STUDENT EXAM RESPONSES (CRASH RECOVERY & DETAILED LOG)
CREATE TABLE IF NOT EXISTS `tbl_cbt_responses` (
    `responseid` INT AUTO_INCREMENT PRIMARY KEY,
    `studentid` VARCHAR(50) NOT NULL,
    `examid` VARCHAR(50) NOT NULL,
    `questionid` VARCHAR(50) NOT NULL,
    `selected_option` CHAR(1) DEFAULT NULL,
    `is_bookmarked` TINYINT(1) DEFAULT 0,
    `last_updated` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `idx_student_question` (`studentid`, `examid`, `questionid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 11. COMPLETED EXAM RESULTS (LOCAL & PENDING SYNC)
CREATE TABLE IF NOT EXISTS `tbl_cbt_results` (
    `resultid` INT AUTO_INCREMENT PRIMARY KEY,
    `studentid` VARCHAR(50) NOT NULL,
    `regnumber` VARCHAR(50) NOT NULL,
    `student_name` VARCHAR(255) NOT NULL,
    `examid` VARCHAR(50) NOT NULL,
    `exam_title` VARCHAR(255) NOT NULL,
    `sessionid` VARCHAR(50) DEFAULT '',
    `termid` VARCHAR(50) DEFAULT '',
    `classgroupid` VARCHAR(50) DEFAULT '',
    `classid` VARCHAR(50) NOT NULL,
    `subjectid` VARCHAR(50) NOT NULL,
    `markdistid` VARCHAR(50) DEFAULT '',
    `score` DOUBLE(5,2) NOT NULL,
    `total_score` DOUBLE(5,2) DEFAULT 100.00,
    `total_questions` INT DEFAULT 0,
    `correct_answers` INT DEFAULT 0,
    `time_spent_seconds` INT DEFAULT 0,
    `date_taken` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `status` VARCHAR(20) DEFAULT 'COMPLETED',
    `sync_status` TINYINT(1) DEFAULT 0, -- 0: Pending Push, 1: Synced
    `submitted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `synced_at` DATETIME NULL,
    UNIQUE KEY `idx_student_exam` (`studentid`, `examid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 12. SYNC LOGS
CREATE TABLE IF NOT EXISTS `tbl_cbt_sync_logs` (
    `logid` INT AUTO_INCREMENT PRIMARY KEY,
    `sync_type` VARCHAR(50) NOT NULL, -- PULL or PUSH
    `status` VARCHAR(20) NOT NULL,   -- SUCCESS or ERROR
    `records_affected` INT DEFAULT 0,
    `message` TEXT,
    `timestamp` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 13. ADMIN USERS & SYNCED CREDENTIALS
CREATE TABLE IF NOT EXISTS `tbl_cbt_admins` (
    `adminid` VARCHAR(50) NOT NULL PRIMARY KEY,
    `schoolid` VARCHAR(50) NOT NULL,
    `username` VARCHAR(100) NOT NULL,
    `password_raw` VARCHAR(100) NOT NULL,
    `password_hash` VARCHAR(255) DEFAULT '',
    `fullname` VARCHAR(150) NOT NULL,
    `role` VARCHAR(255) DEFAULT 'Admin',
    `last_login` DATETIME NULL,
    UNIQUE KEY `idx_school_username` (`schoolid`, `username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;