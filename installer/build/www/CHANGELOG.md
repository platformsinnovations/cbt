# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [1.0.0] - 2026-10-12

### Added
- **Student Examination Engine:**
  - Secure offline candidate login via Admission / Registration Number.
  - Timed examination execution with automatic submission upon timer expiry.
  - Crash recovery system persisting student responses (`tbl_cbt_responses`) in real time.
  - Dynamic question and option shuffling per candidate session.
  - Accurate calibrated score computation using the standard formula:
    $$\text{Final Score} = \left(\frac{\text{Total Correct Answers}}{\text{Total Questions}}\right) \times \text{Mark Distribution Value}$$
    with whole-number rounding.

- **Admin Management & Dashboard:**
  - Unified dark-mode dashboard with system statistics (Active Students, Available Exams, Pending Score Pushes, and Active Session/Term).
  - Exam creator and Question Bank manager supporting multi-choice options (A–E) and image attachments.
  - Candidate roster management with class group categorization and password/PIN support.
  - Security alert banner prompting administrators to change default setup credentials.
  - Server data reset and administrator password management tools.

- **Score Performance Reports:**
  - Dedicated **Reports** section accessible from the sidebar menu.
  - On-demand score generation (scores are not preloaded until filtered).
  - Cascading 5-parameter filter bar:
    1. **Class / Arm**
    2. **Academic Session**
    3. **Academic Term**
    4. **Subject**
    5. **Mark Distribution** *(dynamically resolved from selected Class, Session & Term)*.
  - Real-time candidate analytics: Filtered Candidates, Average Score, Highest Score, and Pass Rate.
  - Clean, high-contrast black-and-white printable score sheet removing screen dark backgrounds and horizontal scrollbars.
  - **Export to Excel (`.xls`)** with structured columns, candidate registration numbers formatted as text, and pass/fail remarks.

- **Online EduPortal Sync:**
  - Bi-directional sync connecting local offline servers to the remote EduPortal backend.
  - Downstream pull of academic sessions, terms, classes, exams, questions, and students.
  - Upstream push of student examination results with complete sync payloads and query logs.

- **Deployment & Portability:**
  - Self-contained standalone Windows distribution package under `installer/`.
  - Configurable server port and database parameters via `config.ini`.
