<?php
session_start();

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

app_dev_auto_login();

if (!isset($_SESSION['user_id'], $_SESSION['role']) || $_SESSION['role'] !== 'student') {
    header('Location: index.php');
    exit;
}

$student_pk = (int)($_SESSION['user_pk'] ?? 0);
if ($student_pk <= 0) {
    $conn = db();
    $user_code = (string)($_SESSION['user_id'] ?? '');
    $stmt = $conn->prepare('SELECT id FROM users WHERE user_code = ? AND role = "student" LIMIT 1');
    $stmt->bind_param('s', $user_code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $student_pk = (int)($row['id'] ?? 0);
    if ($student_pk > 0) {
        $_SESSION['user_pk'] = $student_pk;
    }
}

$redirect = (string)($_SERVER['HTTP_REFERER'] ?? 'student_dashboard.php');
if ($redirect === '' || strpos($redirect, 'mark_notification_read') !== false) {
    $redirect = 'student_dashboard.php';
}

if ($student_pk <= 0) {
    header('Location: ' . $redirect);
    exit;
}

$mark_all = isset($_GET['all']) && $_GET['all'] === '1' || isset($_POST['all']) && $_POST['all'] === '1';
$notification_id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

try {
    $conn = db();
    if ($mark_all) {
        $stmt = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?');
        $stmt->bind_param('i', $student_pk);
        $stmt->execute();
    } elseif ($notification_id > 0) {
        $stmt = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ? LIMIT 1');
        $stmt->bind_param('ii', $notification_id, $student_pk);
        $stmt->execute();
    }
} catch (Throwable $e) {
    error_log((string)$e);
}

header('Location: ' . $redirect);
exit;
