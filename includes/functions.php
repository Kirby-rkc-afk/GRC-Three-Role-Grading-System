<?php
/**
 * GLOBAL RECIPROCAL COLLEGES - Professor & Student Grading System
 * File: includes/functions.php
 * Official Grading Formulas, Input Sanitization, and Transmutation Hooks
 */

declare(strict_types=1);

/**
 * =========================================================================
 * GRADING WEIGHT CONFIGURATION
 *
 * GRADING POLICY NOTE:
 * Sa reference document ng paaralan, nakasulat ang "Class Participation 75%",
 * ngunit ang itemized breakdown nito ay:
 *   Attendance (15%) + Recitation (15%) + Assignment (5%) + Quizzes (30%) = 65%
 * Kasama ang major examinations:
 *   Midterm: Prelim Exam (10%) + Midterm Exam (25%) = 35%  --> Kabuuan = 100%
 *   Final:   Final Exam (35%) = 35%                        --> Kabuuan = 100%
 *
 * Alinsunod sa panuntunan, ang itemized component weights ang ipinapatupad dito.
 * Kailangang kumpirmahin sa Academic Affairs / Dean's Office kung may opisyal
 * na rebisyon sa policy.
 * =========================================================================
 */

const MIDTERM_WEIGHTS = [
    'attendance'   => 0.15, // 15%
    'recitation'   => 0.15, // 15%
    'assignment'   => 0.05, // 5%
    'quizzes'      => 0.30, // 30%
    'prelim_exam'  => 0.10, // 10%
    'midterm_exam' => 0.25, // 25%
];

const FINAL_WEIGHTS = [
    'attendance' => 0.15, // 15%
    'recitation' => 0.15, // 15%
    'assignment' => 0.05, // 5%
    'quizzes'    => 0.30, // 30%
    'final_exam' => 0.35, // 35%
];

const LECTURE_WEIGHT    = 0.65; // 65%
const LABORATORY_WEIGHT = 0.35; // 35%

/**
 * Escapes characters for safe HTML output (XSS defense).
 *
 * @param mixed $value
 * @return string
 */
function h(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Sets a flash message in the active session.
 *
 * @param string $type success | danger | warning | info
 * @param string $message
 */
function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type'    => $type,
        'message' => $message,
    ];
}

/**
 * Retrieves and clears the active flash message from the session.
 *
 * @return array|null
 */
function getFlash(): ?array
{
    if (isset($_SESSION['flash'])) {
        $flash = $_SESSION['flash'];
        unset($_SESSION['flash']);
        return $flash;
    }
    return null;
}

/**
 * Validates a score input (0.00 to 100.00).
 * Empty string or null represents an unencoded score (returns null).
 *
 * @param mixed $value
 * @return float|null
 * @throws InvalidArgumentException
 */
function validateScore(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    if (!is_numeric($value)) {
        throw new InvalidArgumentException('Ang bawat score ay dapat numero mula 0 hanggang 100, o iwang blangko.');
    }

    $num = (float)$value;
    if ($num < 0 || $num > 100) {
        throw new InvalidArgumentException('Bawal ang score na mas mababa sa 0 o mas mataas sa 100.');
    }

    return round($num, 2);
}

/**
 * Calculates a component grade (Midterm Lecture, Midterm Lab, etc.).
 * Returns null if any sub-score is missing (Incomplete).
 *
 * @param array $scores
 * @param array $weights
 * @param string $prefix e.g. 'mid_lec_'
 * @return float|null
 */
function computeComponentGrade(array $scores, array $weights, string $prefix): ?float
{
    $total = 0.0;
    foreach ($weights as $key => $weight) {
        $fieldName = $prefix . $key;
        $score = $scores[$fieldName] ?? null;

        if ($score === null || $score === '') {
            return null; // May kulang pang score -> Incomplete
        }

        $total += ((float)$score) * $weight;
    }

    return round($total, 2);
}

/**
 * Period Grade = (Lecture Grade × 65%) + (Laboratory Grade × 35%)
 *
 * @param float|null $lectureGrade
 * @param float|null $labGrade
 * @return float|null
 */
function computePeriodGrade(?float $lectureGrade, ?float $labGrade): ?float
{
    if ($lectureGrade === null || $labGrade === null) {
        return null; // Incomplete
    }

    return round(($lectureGrade * LECTURE_WEIGHT) + ($labGrade * LABORATORY_WEIGHT), 2);
}

/**
 * Course Grade = (Midterm Grade + Final Grade) ÷ 2
 *
 * @param float|null $midtermGrade
 * @param float|null $finalGrade
 * @return float|null
 */
function computeCourseGrade(?float $midtermGrade, ?float $finalGrade): ?float
{
    if ($midtermGrade === null || $finalGrade === null) {
        return null; // Incomplete
    }

    return round(($midtermGrade + $finalGrade) / 2, 2);
}

/**
 * Computes full breakdown of student grades from a `grades` table row.
 *
 * @param array $gradeRow
 * @return array
 */
function computeStudentGrades(array $gradeRow): array
{
    // Midterm Lecture & Laboratory
    $midLec = computeComponentGrade($gradeRow, MIDTERM_WEIGHTS, 'mid_lec_');
    $midLab = computeComponentGrade($gradeRow, MIDTERM_WEIGHTS, 'mid_lab_');
    $midtermGrade = computePeriodGrade($midLec, $midLab);

    // Final Lecture & Laboratory
    $fnlLec = computeComponentGrade($gradeRow, FINAL_WEIGHTS, 'fnl_lec_');
    $fnlLab = computeComponentGrade($gradeRow, FINAL_WEIGHTS, 'fnl_lab_');
    $finalGrade = computePeriodGrade($fnlLec, $fnlLab);

    // Overall Course Grade
    $courseGrade = computeCourseGrade($midtermGrade, $finalGrade);

    return [
        'mid_lec_grade'  => $midLec,
        'mid_lab_grade'  => $midLab,
        'midterm_grade'  => $midtermGrade,
        'midterm_status' => ($midtermGrade !== null) ? 'Complete' : 'Incomplete',

        'fnl_lec_grade'  => $fnlLec,
        'fnl_lab_grade'  => $fnlLab,
        'final_grade'    => $finalGrade,
        'final_status'   => ($finalGrade !== null)   ? 'Complete' : 'Incomplete',

        'course_grade'   => $courseGrade,
        'course_status'  => ($courseGrade !== null)  ? 'Complete' : 'Incomplete',
        'transmuted'     => getTransmutedGrade($courseGrade),
        'remarks'        => getRemarks($courseGrade),
    ];
}

/**
 * =========================================================================
 * TRANSMUTATION TABLE PLACEHOLDER FUNCTION
 *
 * TANDAAN: Hindi ibinigay ang eksaktong conversion table sa reference.
 * Huwag mag-imbento ng unofficial conversion values.
 *
 * Kasalukuyang ibinabalik nito ang raw weighted percentage. Kapag inilabas
 * na ang opisyal na transmutation matrix ng paaralan (hal. 97.5-100 -> 1.00),
 * i-implement ang mapping sa loob ng function na ito.
 * =========================================================================
 *
 * @param float|null $rawGrade
 * @return string
 */
function getTransmutedGrade(?float $rawGrade): string
{
    if ($rawGrade === null) {
        return 'Incomplete';
    }

    // LUGAR PARA SA OPISYAL NA TRANSMUTATION MATRIX:
    // Halimbawa:
    // if ($rawGrade >= 97.5) return '1.00';
    // if ($rawGrade >= 94.5) return '1.25';
    // if ($rawGrade >= 91.5) return '1.50';
    // ...

    return number_format($rawGrade, 2) . '% (Raw)';
}

/**
 * Returns academic status remarks.
 *
 * @param float|null $courseGrade
 * @return string PASSED | FAILED | INCOMPLETE
 */
function getRemarks(?float $courseGrade): string
{
    if ($courseGrade === null) {
        return 'INCOMPLETE';
    }

    return ($courseGrade >= 75.00) ? 'PASSED' : 'FAILED';
}

/**
 * Formats a grade into display string or 'Incomplete'.
 *
 * @param float|null $grade
 * @param bool $withPercent
 * @return string
 */
function formatGradeDisplay(?float $grade, bool $withPercent = true): string
{
    if ($grade === null) {
        return 'Incomplete';
    }
    return number_format($grade, 2) . ($withPercent ? '%' : '');
}

/**
 * Verifies if a subject belongs to the currently logged-in professor.
 *
 * @param PDO $pdo
 * @param int $subjectId
 * @param int $professorId
 * @return bool
 */
function isSubjectOwnedByProfessor(PDO $pdo, int $subjectId, int $professorId): bool
{
    $stmt = $pdo->prepare('SELECT id FROM subjects WHERE id = ? AND professor_id = ? LIMIT 1');
    $stmt->execute([$subjectId, $professorId]);
    return (bool)$stmt->fetchColumn();
}
