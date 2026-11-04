<?php
/**
 * GLOBAL RECIPROCAL COLLEGES - Professor & Student Grading System
 * File: includes/auth.php
 * Role-Based Authentication, Session Security, and CSRF Protection
 */

declare(strict_types=1);

/**
 * Initializes a secure PHP session with hardened cookie flags.
 */
function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('GRC_GRADING_SESSION');
        ini_set('session.use_strict_mode','1');
        ini_set('session.use_only_cookies','1');
        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
                   || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

        $appPath=PHP_SAPI==='cli'?'/':rtrim(str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/')),'/').'/';
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => $appPath,
            'domain'   => '',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }
}

// Automatically start secure session
startSecureSession();

/**
 * Checks whether an authenticated user is currently logged in.
 *
 * @return bool
 */
function isLoggedIn(): bool
{
    return isset($_SESSION['user']) && !empty($_SESSION['user']['id']) && !empty($_SESSION['user']['role']);
}

/**
 * Retrieves the currently active user role: 'professor', 'student', or null.
 *
 * @return string|null
 */
function getUserRole(): ?string
{
    return isLoggedIn() ? (string)$_SESSION['user']['role'] : null;
}

/**
 * Returns the current authenticated user's session data.
 *
 * @return array|null
 */
function getCurrentUser(): ?array
{
    return isLoggedIn() ? $_SESSION['user'] : null;
}

/**
 * Enforces professor role access. Redirects to professor-login.php if unauthorized.
 *
 * @return array Professor session record
 */
function requireProfessor(): array
{
    if (!isLoggedIn() || getUserRole() !== 'professor') {
        $_SESSION['flash'] = [
            'type'    => 'danger',
            'message' => 'Kailangan mag-login bilang Propesor upang ma-access ang page na ito.'
        ];
        header('Location: professor-login.php');
        exit;
    }
    return $_SESSION['user'];
}

/**
 * Enforces student role access. Redirects to student-login.php if unauthorized.
 *
 * @return array Student session record
 */
function requireStudent(): array
{
    if (!isLoggedIn() || getUserRole() !== 'student') {
        $_SESSION['flash'] = [
            'type'    => 'danger',
            'message' => 'Kailangan mag-login bilang Estudyante upang ma-access ang iyong portal.'
        ];
        header('Location: student-login.php');
        exit;
    }
    return $_SESSION['user'];
}

/**
 * Authenticates a professor account and stores role-based session data.
 *
 * @param array $prof
 */
function loginProfessor(array $prof): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'          => (int)$prof['id'],
        'role'        => 'professor',
        'employee_id' => (string)($prof['employee_id'] ?? 'N/A'),
        'username'    => (string)($prof['username'] ?? $prof['email']),
        'full_name'   => (string)$prof['full_name'],
        'email'       => (string)$prof['email'],
        'department'  => (string)($prof['department'] ?? 'College of Computer Studies'),
        'logged_in_at'=> date('Y-m-d H:i:s'),
    ];
}

/**
 * Authenticates a student account and stores role-based session data.
 *
 * @param array $student
 */
function loginStudent(array $student): void
{
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'          => (int)$student['id'],
        'role'        => 'student',
        'student_no'  => (string)$student['student_no'],
        'username'    => (string)($student['username'] ?? $student['student_no']),
        'first_name'  => (string)$student['first_name'],
        'last_name'   => (string)$student['last_name'],
        'full_name'   => (string)($student['first_name'] . ' ' . $student['last_name']),
        'email'       => (string)($student['email'] ?? ''),
        'course'      => (string)($student['course'] ?? 'BS Information Technology'),
        'year_level'  => (string)($student['year_level'] ?? '3rd Year'),
        'logged_in_at'=> date('Y-m-d H:i:s'),
    ];
}

/**
 * Destroys user session and invalidates the session cookie.
 */
function logoutUser(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
}

/**
 * Generates or retrieves the active CSRF token.
 *
 * @return string
 */
function generateCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validates a submitted CSRF token using timing attack safe comparison.
 *
 * @param string|null $token
 * @return bool
 */
function verifyCsrfToken(?string $token): bool
{
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Generates a hidden HTML input field with the current CSRF token.
 *
 * @return string
 */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(generateCsrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
}
