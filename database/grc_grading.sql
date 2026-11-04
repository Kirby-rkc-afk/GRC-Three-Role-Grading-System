-- =====================================================================
-- GLOBAL RECIPROCAL COLLEGES
-- Web-based Three-Role / Dual-Portal Grading System Database Schema
-- Database: grc_grading
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `grc_grading`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `grc_grading`;

-- ---------------------------------------------------------------------
-- Table: professors
-- Stores professor login credentials and academic profile details
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `professors` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `employee_id` VARCHAR(50) NOT NULL UNIQUE,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `full_name` VARCHAR(150) NOT NULL,
  `email` VARCHAR(190) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `department` VARCHAR(120) NOT NULL DEFAULT 'College of Computer Studies',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: students
-- Stores student login credentials and academic information
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `students` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `student_no` VARCHAR(50) NOT NULL UNIQUE,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(150) NULL,
  `course` VARCHAR(100) NOT NULL DEFAULT 'BS Information Technology',
  `year_level` VARCHAR(30) NOT NULL DEFAULT '3rd Year',
  `password_hash` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: subjects
-- Subjects/classes handled by professors
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subjects` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `professor_id` INT UNSIGNED NOT NULL,
  `subject_code` VARCHAR(50) NOT NULL,
  `subject_title` VARCHAR(150) NOT NULL,
  `section` VARCHAR(50) NOT NULL,
  `units` INT NOT NULL DEFAULT 3,
  `school_year` VARCHAR(20) NOT NULL,
  `semester` ENUM('1st Semester', '2nd Semester', 'Summer') NOT NULL DEFAULT '1st Semester',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_subject_professor` (`professor_id`),
  CONSTRAINT `fk_subjects_professor` FOREIGN KEY (`professor_id`) 
    REFERENCES `professors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: enrollments
-- Maps students to subjects. Unique per subject & student.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `enrollments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `professor_id` INT UNSIGNED NOT NULL,
  `subject_id` INT UNSIGNED NOT NULL,
  `student_id` INT UNSIGNED NOT NULL,
  `enrolled_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_subject_student` (`subject_id`, `student_id`),
  INDEX `idx_enrollment_professor` (`professor_id`),
  INDEX `idx_enrollment_student` (`student_id`),
  CONSTRAINT `fk_enrollments_professor` FOREIGN KEY (`professor_id`) 
    REFERENCES `professors` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_enrollments_subject` FOREIGN KEY (`subject_id`) 
    REFERENCES `subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_enrollments_student` FOREIGN KEY (`student_id`) 
    REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Table: grades
-- Contains raw component scores (0 to 100) per enrollment.
-- NULL represents unencoded score (displays as Incomplete/Pending).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `grades` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `enrollment_id` INT UNSIGNED NOT NULL UNIQUE,
  -- Midterm Period - Lecture (Weights: Att 15%, Rec 15%, Ass 5%, Quiz 30%, Prelim 10%, Mid 25%)
  `mid_lec_attendance` DECIMAL(5,2) NULL DEFAULT NULL,
  `mid_lec_recitation` DECIMAL(5,2) NULL DEFAULT NULL,
  `mid_lec_assignment` DECIMAL(5,2) NULL DEFAULT NULL,
  `mid_lec_quizzes` DECIMAL(5,2) NULL DEFAULT NULL,
  `mid_lec_prelim_exam` DECIMAL(5,2) NULL DEFAULT NULL,
  `mid_lec_midterm_exam` DECIMAL(5,2) NULL DEFAULT NULL,
  -- Midterm Period - Laboratory (Weights: Att 15%, Rec 15%, Ass 5%, Quiz 30%, Prelim 10%, Mid 25%)
  `mid_lab_attendance` DECIMAL(5,2) NULL DEFAULT NULL,
  `mid_lab_recitation` DECIMAL(5,2) NULL DEFAULT NULL,
  `mid_lab_assignment` DECIMAL(5,2) NULL DEFAULT NULL,
  `mid_lab_quizzes` DECIMAL(5,2) NULL DEFAULT NULL,
  `mid_lab_prelim_exam` DECIMAL(5,2) NULL DEFAULT NULL,
  `mid_lab_midterm_exam` DECIMAL(5,2) NULL DEFAULT NULL,
  -- Final Period - Lecture (Weights: Att 15%, Rec 15%, Ass 5%, Quiz 30%, Final 35%)
  `fnl_lec_attendance` DECIMAL(5,2) NULL DEFAULT NULL,
  `fnl_lec_recitation` DECIMAL(5,2) NULL DEFAULT NULL,
  `fnl_lec_assignment` DECIMAL(5,2) NULL DEFAULT NULL,
  `fnl_lec_quizzes` DECIMAL(5,2) NULL DEFAULT NULL,
  `fnl_lec_final_exam` DECIMAL(5,2) NULL DEFAULT NULL,
  -- Final Period - Laboratory (Weights: Att 15%, Rec 15%, Ass 5%, Quiz 30%, Final 35%)
  `fnl_lab_attendance` DECIMAL(5,2) NULL DEFAULT NULL,
  `fnl_lab_recitation` DECIMAL(5,2) NULL DEFAULT NULL,
  `fnl_lab_assignment` DECIMAL(5,2) NULL DEFAULT NULL,
  `fnl_lab_quizzes` DECIMAL(5,2) NULL DEFAULT NULL,
  `fnl_lab_final_exam` DECIMAL(5,2) NULL DEFAULT NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT `fk_grades_enrollment` FOREIGN KEY (`enrollment_id`) 
    REFERENCES `enrollments` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Seed Data: Demo Professor
-- Username: prof.juandc  (or email: prof@grc.edu.ph)
-- Password: Password123!
-- ---------------------------------------------------------------------
INSERT INTO `professors` (`id`, `employee_id`, `username`, `full_name`, `email`, `password_hash`, `department`) VALUES
(1, 'GRC-FAC-2024-001', 'prof.juandc', 'Prof. Juan Dela Cruz', 'prof@grc.edu.ph', '$2y$10$kE5e6epQJgZ6h8uh0Om5lOkAjk7qvOVlFc9HuqLZAlMCuLtUDoSR6', 'College of Computer Studies')
ON DUPLICATE KEY UPDATE `id` = `id`;

-- ---------------------------------------------------------------------
-- Seed Data: Sample Subjects
-- ---------------------------------------------------------------------
INSERT INTO `subjects` (`id`, `professor_id`, `subject_code`, `subject_title`, `section`, `units`, `school_year`, `semester`) VALUES
(1, 1, 'IT311', 'Web Systems and Technologies 2', 'BSIT 3A', 3, '2024-2025', '1st Semester'),
(2, 1, 'CS312', 'Database Management Systems 2', 'BSCS 3B', 3, '2024-2025', '1st Semester')
ON DUPLICATE KEY UPDATE `id` = `id`;

-- ---------------------------------------------------------------------
-- Seed Data: Demo Students
-- Passwords: Password123!
-- ---------------------------------------------------------------------
INSERT INTO `students` (`id`, `student_no`, `username`, `first_name`, `last_name`, `email`, `course`, `year_level`, `password_hash`) VALUES
(1, '2022-00101', 'maria.santos', 'Maria', 'Santos', 'maria.santos@student.grc.edu.ph', 'BS Information Technology', '3rd Year', '$2y$10$kE5e6epQJgZ6h8uh0Om5lOkAjk7qvOVlFc9HuqLZAlMCuLtUDoSR6'),
(2, '2022-00102', 'kenneth.reyes', 'John Kenneth', 'Reyes', 'kenneth.reyes@student.grc.edu.ph', 'BS Information Technology', '3rd Year', '$2y$10$kE5e6epQJgZ6h8uh0Om5lOkAjk7qvOVlFc9HuqLZAlMCuLtUDoSR6'),
(3, '2022-00103', 'angelica.ramos', 'Angelica', 'Ramos', 'angelica.ramos@student.grc.edu.ph', 'BS Computer Science', '3rd Year', '$2y$10$kE5e6epQJgZ6h8uh0Om5lOkAjk7qvOVlFc9HuqLZAlMCuLtUDoSR6'),
(4, '2022-00104', 'mark.garcia', 'Mark Anthony', 'Garcia', 'mark.garcia@student.grc.edu.ph', 'BS Computer Science', '3rd Year', '$2y$10$kE5e6epQJgZ6h8uh0Om5lOkAjk7qvOVlFc9HuqLZAlMCuLtUDoSR6')
ON DUPLICATE KEY UPDATE `id` = `id`;

-- ---------------------------------------------------------------------
-- Seed Data: Sample Enrollments
-- ---------------------------------------------------------------------
INSERT INTO `enrollments` (`id`, `professor_id`, `subject_id`, `student_id`) VALUES
(1, 1, 1, 1),
(2, 1, 1, 2),
(3, 1, 2, 3),
(4, 1, 2, 4)
ON DUPLICATE KEY UPDATE `id` = `id`;

-- ---------------------------------------------------------------------
-- Seed Data: Sample Grades
-- Enrollment 1: Complete scores (Calculates Midterm, Final, Course Grade)
-- Enrollment 2: Incomplete scores (Prelim exam and Final exam missing)
-- ---------------------------------------------------------------------
INSERT INTO `grades` (
  `enrollment_id`,
  `mid_lec_attendance`, `mid_lec_recitation`, `mid_lec_assignment`, `mid_lec_quizzes`, `mid_lec_prelim_exam`, `mid_lec_midterm_exam`,
  `mid_lab_attendance`, `mid_lab_recitation`, `mid_lab_assignment`, `mid_lab_quizzes`, `mid_lab_prelim_exam`, `mid_lab_midterm_exam`,
  `fnl_lec_attendance`, `fnl_lec_recitation`, `fnl_lec_assignment`, `fnl_lec_quizzes`, `fnl_lec_final_exam`,
  `fnl_lab_attendance`, `fnl_lab_recitation`, `fnl_lab_assignment`, `fnl_lab_quizzes`, `fnl_lab_final_exam`
) VALUES
(
  1,
  95.00, 90.00, 92.00, 88.00, 85.00, 90.00,
  95.00, 88.00, 90.00, 92.00, 86.00, 91.00,
  96.00, 92.00, 95.00, 90.00, 93.00,
  98.00, 90.00, 92.00, 94.00, 95.00
),
(
  2,
  90.00, 85.00, 88.00, 84.00, NULL, 87.00,
  92.00, 86.00, 85.00, 88.00, 82.00, 85.00,
  88.00, 85.00, 90.00, 82.00, NULL,
  90.00, 88.00, 85.00, 87.00, NULL
)
ON DUPLICATE KEY UPDATE `enrollment_id` = `enrollment_id`;
