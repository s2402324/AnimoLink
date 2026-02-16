<?php
session_start();

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';

app_dev_auto_login();

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: index.php');
    exit;
}

$request_id = (int)($_GET['request_id'] ?? 0);
if ($request_id <= 0) {
    http_response_code(400);
    exit('Missing request_id');
}

try {
    $conn = db();

    $stmt = $conn->prepare(
        'SELECT
            mr.id,
            mr.status,
            mr.illness,
            mr.symptoms,
            mr.illness_date,
            mr.submitted_date,
            mr.approved_date,
            mr.valid_until,
            u.id AS student_pk,
            u.user_code AS student_id,
            u.full_name AS student_name,
            u.course AS student_course,
            u.year_level AS student_year_level
         FROM medical_requests mr
         JOIN users u ON u.id = mr.student_user_id
         WHERE mr.id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $req = $stmt->get_result()->fetch_assoc();

    if (!$req) {
        http_response_code(404);
        exit('Request not found');
    }

    if ((string)$req['status'] !== 'Approved') {
        http_response_code(403);
        exit('Certificate is only available for approved requests.');
    }

    // Access control:
    // - clinic can view any
    // - student can view only their own
    if ((string)$_SESSION['role'] === 'student') {
        $student_pk = (int)($_SESSION['user_pk'] ?? 0);
        if ($student_pk <= 0) {
            $user_code = (string)($_SESSION['user_id'] ?? '');
            $stmt = $conn->prepare('SELECT id FROM users WHERE user_code = ? AND role="student" LIMIT 1');
            $stmt->bind_param('s', $user_code);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $student_pk = (int)($row['id'] ?? 0);
            if ($student_pk > 0) {
                $_SESSION['user_pk'] = $student_pk;
            }
        }
        if ($student_pk <= 0 || $student_pk !== (int)$req['student_pk']) {
            http_response_code(403);
            exit('Forbidden');
        }
    } elseif ((string)$_SESSION['role'] !== 'clinic') {
        http_response_code(403);
        exit('Forbidden');
    }

    // Ensure certificate record exists (idempotent)
    $year = date('Y');
    $cert_code = null;
    $stmt = $conn->prepare('SELECT certificate_code FROM medical_certificates WHERE request_id = ? LIMIT 1');
    $stmt->bind_param('i', $request_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row && !empty($row['certificate_code'])) {
        $cert_code = (string)$row['certificate_code'];
    } else {
        $issued_by = (int)($_SESSION['role'] === 'clinic' ? ($_SESSION['user_pk'] ?? 0) : 0);
        if ($issued_by <= 0) {
            $issued_by = null;
        }
        $cert_code = 'MC-' . $year . '-' . str_pad((string)$request_id, 6, '0', STR_PAD_LEFT);
        $stmt = $conn->prepare(
            'INSERT INTO medical_certificates (request_id, certificate_code, issued_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE certificate_code = VALUES(certificate_code), issued_by = VALUES(issued_by)'
        );
        // bind_param doesn't accept null for integer easily; use 0 then NULLIF in SQL? keep simple:
        $issued_by_int = $issued_by ?? 0;
        $stmt->bind_param('isi', $request_id, $cert_code, $issued_by_int);
        $stmt->execute();
    }

    $student_name = (string)($req['student_name'] ?? '');
    $student_id = (string)($req['student_id'] ?? '');
    $course = (string)($req['student_course'] ?? '');
    $year_level = (string)($req['student_year_level'] ?? '');
    $illness = (string)($req['illness'] ?? '');
    $symptoms = (string)($req['symptoms'] ?? '');
    $approved_date = (string)($req['approved_date'] ?? '');
    $valid_until = (string)($req['valid_until'] ?? '');
} catch (Throwable $e) {
    error_log((string)$e);
    http_response_code(500);
    exit('Server error');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Certificate</title>
    <style>
        body { font-family: Arial, sans-serif; background:#f5f6f7; margin:0; padding:24px; color:#111; }
        .sheet { max-width: 900px; margin: 0 auto; background:#fff; border-radius: 12px; padding: 28px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); }
        .row { display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; }
        .title { text-align:center; margin: 6px 0 18px; }
        .title h1 { margin:0; font-size: 22px; letter-spacing: 0.5px; }
        .muted { color:#666; font-size: 12px; }
        .meta { font-size: 13px; line-height: 1.6; }
        .box { border: 1px solid #e8eaee; border-radius: 10px; padding: 14px; margin-top: 12px; }
        .k { font-weight: 700; }
        .actions { display:flex; justify-content:center; gap:10px; margin-top: 16px; }
        .btn { border: 1px solid #ddd; background:#fff; padding: 10px 14px; border-radius: 10px; cursor:pointer; font-weight:700; }
        .btn-primary { background:#0f7a4a; color:#fff; border-color:#0f7a4a; }
        @media print {
            body { background:#fff; padding:0; }
            .actions { display:none; }
            .sheet { box-shadow:none; border-radius:0; padding: 18px; }
        }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="row">
            <div class="muted">Campus Clinic · Health & Wellness Center</div>
            <div class="muted">Certificate No: <span class="k"><?php echo htmlspecialchars($cert_code, ENT_QUOTES, 'UTF-8'); ?></span></div>
        </div>

        <div class="title">
            <h1>MEDICAL CERTIFICATE</h1>
            <div class="muted">Generated by MediClear</div>
        </div>

        <div class="box meta">
            <div class="row">
                <div><span class="k">Student:</span> <?php echo htmlspecialchars($student_name, ENT_QUOTES, 'UTF-8'); ?></div>
                <div><span class="k">Student ID:</span> <?php echo htmlspecialchars($student_id, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
            <div class="row" style="margin-top:6px;">
                <div><span class="k">Course:</span> <?php echo htmlspecialchars($course !== '' ? $course : '—', ENT_QUOTES, 'UTF-8'); ?></div>
                <div><span class="k">Year level:</span> <?php echo htmlspecialchars($year_level !== '' ? $year_level : '—', ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>

        <div class="box meta">
            <div><span class="k">Illness:</span> <?php echo htmlspecialchars($illness, ENT_QUOTES, 'UTF-8'); ?></div>
            <div style="margin-top:6px;"><span class="k">Symptoms / Notes:</span> <?php echo nl2br(htmlspecialchars($symptoms, ENT_QUOTES, 'UTF-8')); ?></div>
        </div>

        <div class="box meta">
            <div class="row">
                <div><span class="k">Approved date:</span> <?php echo htmlspecialchars($approved_date, ENT_QUOTES, 'UTF-8'); ?></div>
                <div><span class="k">Valid until:</span> <?php echo htmlspecialchars($valid_until, ENT_QUOTES, 'UTF-8'); ?></div>
            </div>
        </div>

        <div class="box meta">
            This is to certify that the above-named student has been assessed and is granted medical clearance
            within the validity period indicated above.
        </div>

        <div class="actions">
            <button class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button>
            <button class="btn" onclick="window.close()">Close</button>
        </div>
    </div>
</body>
</html>

