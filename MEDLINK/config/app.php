<?php
declare(strict_types=1);

/**
 * Application helpers.
 *
 * DEV MODE (OFF by default):
 * - Set environment variable APP_DEV=1 to enable design/dev bypass for auth.
 *   Examples:
 *   - XAMPP Apache: use SetEnv APP_DEV 1 in httpd.conf / vhost / .htaccess (if allowed)
 *   - CLI: $env:APP_DEV="1"
 */

function app_is_dev(): bool
{
    $raw = getenv('APP_DEV');
    if ($raw === false || $raw === '') {
        // Some hosts expose env vars via $_SERVER
        $raw = (string)($_SERVER['APP_DEV'] ?? '');
    }

    $raw = strtolower(trim((string)$raw));
    return in_array($raw, ['1', 'true', 'yes', 'on', 'dev'], true);
}

/**
 * In dev mode, allow UI access without a real account.
 * Optional: pass ?dev_role=student or ?dev_role=clinic on any page.
 */
function app_dev_auto_login(): void
{
    if (!app_is_dev()) {
        return;
    }

    if (isset($_SESSION['user_id'], $_SESSION['role'])) {
        return;
    }

    $role = strtolower((string)($_GET['dev_role'] ?? 'student'));
    if (!in_array($role, ['student', 'clinic'], true)) {
        $role = 'student';
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = 'DEV';
    $_SESSION['user_pk'] = 0;
    $_SESSION['role'] = $role;
    $_SESSION['full_name'] = 'Design Mode';
}

