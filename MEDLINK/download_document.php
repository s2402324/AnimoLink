<?php
session_start();

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

app_dev_auto_login();

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    http_response_code(401);
    exit('Not authenticated');
}

$doc_id = (int)($_GET['id'] ?? 0);
if ($doc_id <= 0) {
    http_response_code(400);
    exit('Missing document id');
}

try {
    $conn = db();

    // Load document + owning request
    $stmt = $conn->prepare(
        'SELECT
            d.id,
            d.request_id,
            d.original_name,
            d.stored_path,
            d.mime_type,
            d.size_bytes,
            mr.student_user_id
         FROM medical_request_documents d
         JOIN medical_requests mr ON mr.id = d.request_id
         WHERE d.id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $doc_id);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();

    if (!$doc) {
        http_response_code(404);
        exit('Document not found');
    }

    $role = (string)$_SESSION['role'];
    if ($role === 'student') {
        $student_pk = (int)($_SESSION['user_pk'] ?? 0);
        if ($student_pk <= 0) {
            // fallback
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

        if ($student_pk <= 0 || (int)$doc['student_user_id'] !== $student_pk) {
            http_response_code(403);
            exit('Forbidden');
        }
    } elseif ($role !== 'clinic') {
        http_response_code(403);
        exit('Forbidden');
    }

    $stored_path = (string)($doc['stored_path'] ?? '');
    $abs_path = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $stored_path);
    if ($stored_path === '' || !is_file($abs_path)) {
        http_response_code(404);
        exit('File missing on server');
    }

    $mime = (string)($doc['mime_type'] ?? '');
    if ($mime === '') {
        $mime = 'application/octet-stream';
    }

    $download_name = (string)($doc['original_name'] ?? ('document-' . $doc_id));
    $size = (int)($doc['size_bytes'] ?? filesize($abs_path));

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . $size);
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $download_name) . '"');
    header('X-Content-Type-Options: nosniff');

    readfile($abs_path);
    exit;
} catch (Throwable $e) {
    error_log((string)$e);
    http_response_code(500);
    exit('Server error');
}

