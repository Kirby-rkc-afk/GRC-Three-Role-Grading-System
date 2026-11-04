# GRC Three-Role Grading System

An automated, role-based academic grading and verification portal for **Global Reciprocal Colleges (GRC)** supporting **Administrators (Registrar)**, **Professors (Faculty)**, and **Students** through one unified interface and sign-in flow.

- **Complete Demo & Persona Guide (Taglish / Defense Ready):** [`docs/DEMO_AND_PERSONA_GUIDE.md`](docs/DEMO_AND_PERSONA_GUIDE.md)
- **System Architecture & Verification Reference:** [`docs/SYSTEM_ARCHITECTURE.md`](docs/SYSTEM_ARCHITECTURE.md)

---

## Key Highlights & Automation

1. **One Shared Sign-In & Automatic Role Routing (`index.php`):**
   - Students, Professors, and Administrators sign in from a single page using their **School/Account ID** (`ADMIN-YYYY-MM-#####`, `PROF-YYYY-MM-#####`, `STUD-YYYY-MM-#####`), **Institutional Email** (`@grc.edu.ph`), or **Username**.
   - Role selection is resolved server-side from verified credentials.
2. **Administrator-Assigned Classes with Pre-Configured GRC Defaults:**
   - Administrators create class offerings and assign them to active professors (`registrar.php?view=classes`).
   - Every class is automatically initialized with **Midterm (50%)** and **Final (50%)** periods, GRC criteria weights (`Attendance 15%`, `Recitation 15%`, `Assignment 15%`, `Quizzes 25%`, `Major Exam 30%`), `75%` passing rate, and the **GRC `1.00–5.00` Grade Transmutation Table**.
3. **Streamlined Professor Workflow (`professor-dashboard.php`):**
   - **Bulk Student Paste (`enroll_many`):** Paste student numbers in one batch; duplicate subjects in the same academic term (`school_year` + `semester` + `subject_code`) are automatically prevented.
   - **1-Click Daily Attendance (`take_attendance_today`):** Creates today's meeting and marks all active students **Present** (`5/5` pts) immediately; exceptions are marked with 1-click `Present`, `Late`, `Absent`, or `Excused` pills.
   - **Reviewed Online Attendance Import:** Supports UTF-8 CSV and Google Meet Attendance Tracker PDFs with automatic identity matching and duration-based status calculation.
   - **Single-Input Score Sheet:** Type a numeric score (`0` to `Max`), `M` (Missing), `E` (Excused), or leave blank (Not Graded), or use 1-click quick pills (`Max`, `M`, `E`, `Clear`, and `Fill ungraded with max`).
   - **1-Click Copy Setup & Live Weight Counter:** Copy criteria weights and policies from another class in one click, with real-time `100%` weight validation.
4. **Immutable Grade Release & Student Portal (`student-dashboard.php`):**
   - Administrators verify submitted grade sheets and publish immutable release snapshots.
   - Students view only their own released grades, Term Weighted Average (GWA), and per-criterion score evidence without seeing draft edits or duplicate subjects.

---

## Quick Start

1. Start **Apache** and **MySQL** in XAMPP and open:
   ```text
   http://localhost/GRC_Three_Role_Grading_System-main/index.php
   ```
2. **Database Configuration (`includes/db.php`):**
   - Environment variables: `GRC_DB_HOST` (`127.0.0.1`), `GRC_DB_PORT` (`3306`), `GRC_DB_NAME` (`grc_grading`), `GRC_DB_USER` (`root`), `GRC_DB_PASS` (empty by default).
   - Required PHP extensions: `pdo_mysql`, `mbstring`, `iconv`.
3. **First-Time Setup / Demo Seeding:**
   - If the database has no administrator yet, `index.php` links to `setup.php` to create the first Registrar account.
   - For local demo datasets, run:
     ```powershell
     C:\xampp\php\php.exe database/seed_demo.php --apply
     C:\xampp\php\php.exe database/seed_masterlist_demo.php --apply
     C:\xampp\php\php.exe database/seed_realistic_demo.php --apply
     ```

---

## Persona Workflows

### 1. Administrator / Registrar (`registrar.php`)
- **Dashboard:** Monitor total students, faculty, classes, pending account approvals, and submitted grade sheets awaiting verification.
- **Accounts (`?view=accounts`):** Create Faculty, Student, and Administrator accounts with automatic `ROLE-YYYY-MM-#####` IDs and `@grc.edu.ph` emails; approve/deactivate access; issue 24-hour one-time activation/recovery codes (`manage-account.php`).
- **Classes & Grades (`?view=classes`):** Browse subjects and assigned professors, create and assign class offerings, inspect class student masterlists (`class.php`), review individual student grade breakdowns (`student-review.php`), reassign draft classes (`reassign-class.php`), and **Release** or **Return** grade sheets.
- **Activity Log & Backup (`?view=audit`, `?view=maintenance`):** Inspect the read-only audit trail and download JSON recovery backups (`backup-download.php`).

### 2. Professor / Faculty (`professor-dashboard.php`)
- **My Classes:** Open assigned classes in a unified class workspace.
- **Students (`?view=roster`):** Bulk-paste student numbers to enroll students, or drop/restore enrollments while retaining historical attendance and score evidence.
- **Attendance (`?view=attendance`):** Use 1-click **Take attendance today**, record per-student attendance with 1-click status pills, or import online attendance CSV/PDF reports (`?view=import`).
- **Scores (`?view=activities`):** Create quizzes, recitations, assignments, and canonical major exams (`Prelim`, `Midterm`, `Final`); encode scores via the single-input score sheet and quick-mark pills.
- **Grades (`?view=grades`):** Review auto-computed Midterm, Final, and Course grades, follow direct shortcut links for any incomplete criteria, and submit complete grade sheets to the Registrar.
- **Settings (`?view=settings`):** Adjust criteria weights with a live `100%` counter, configure attendance points and grade conversion bands, or copy setup from another class.

### 3. Student (`student-dashboard.php`)
- **My Academic Record:** View enrolled subjects by academic term, Released Units, and Term Weighted Average.
- **Released Subject Evidence:** Inspect released Midterm, Final, Course percentage, `1.00–5.00` converted grade, and per-activity/per-meeting score evidence.
- **Reports & Printing:** Print term results or download individual released grade reports (`report.php`).

---

## Recovery Backup & Restore

Restore a downloaded Registrar backup into a **new, empty database** from the command line:

```powershell
C:\xampp\php\php.exe restore-backup.php C:\private\GRC-backup.json grc_recovery_review
```

---

## Verification & Automated Tests

All mutation tests run against isolated, disposable databases and automatically clean up after execution:

```powershell
C:\xampp\php\php.exe tests/classbook_test.php
C:\xampp\php\php.exe tests/period_grading_test.php
C:\xampp\php\php.exe tests/account_ids_test.php
C:\xampp\php\php.exe tests/class_offering_test.php
C:\xampp\php\php.exe tests/demo_seed_test.php
C:\xampp\php\php.exe tests/school_email_test.php
C:\xampp\php\php.exe tests/simplified_professor_flow_test.php
```
