<?php
session_start();

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
app_dev_auto_login();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'clinic') {
    header('Location: index.php');
    exit;
}

$action_error = null;
$action_success = null;

// Resolve clinic staff numeric PK
$clinic_pk = (int)($_SESSION['user_pk'] ?? 0);
try {
    if ($clinic_pk <= 0) {
        $conn = db();
        $user_code = (string)($_SESSION['user_id'] ?? '');
        if ($user_code !== '') {
            $stmt = $conn->prepare('SELECT id, full_name FROM users WHERE user_code = ? AND role = "clinic" LIMIT 1');
            $stmt->bind_param('s', $user_code);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $clinic_pk = (int)($row['id'] ?? 0);
            if ($clinic_pk > 0) {
                $_SESSION['user_pk'] = $clinic_pk;
                if (!empty($row['full_name'])) {
                    $_SESSION['full_name'] = (string)$row['full_name'];
                }
            }
        }
    }

    // Handle approve / reject actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        $request_pk = (int)($_POST['request_pk'] ?? 0);

        if (!in_array($action, ['approve', 'reject'], true)) {
            $action_error = 'Invalid action.';
        } elseif ($request_pk <= 0) {
            $action_error = 'Missing request id.';
        } elseif ($clinic_pk <= 0) {
            $action_error = 'Staff account not found in database.';
        } else {
            $conn = db();
            $today = date('Y-m-d');

            // Only allow actions on Pending requests
            $stmt = $conn->prepare('SELECT id, status, student_user_id FROM medical_requests WHERE id = ? LIMIT 1');
            $stmt->bind_param('i', $request_pk);
            $stmt->execute();
            $req = $stmt->get_result()->fetch_assoc();

            if (!$req) {
                $action_error = 'Request not found.';
            } elseif ((string)$req['status'] !== 'Pending') {
                $action_error = 'Only pending requests can be updated.';
            } elseif ($action === 'approve') {
                $valid_until = date('Y-m-d', strtotime($today . ' +14 days'));
                $stmt = $conn->prepare(
                    'UPDATE medical_requests
                     SET status="Approved",
                         approved_date=?,
                         valid_until=?,
                         rejected_date=NULL,
                         rejection_reason=NULL,
                         reviewed_by=?
                     WHERE id=?'
                );
                $stmt->bind_param('ssii', $today, $valid_until, $clinic_pk, $request_pk);
                $stmt->execute();

                // Create certificate record (idempotent)
                $year = date('Y');
                $cert_code = 'MC-' . $year . '-' . str_pad((string)$request_pk, 6, '0', STR_PAD_LEFT);
                $stmt = $conn->prepare(
                    'INSERT INTO medical_certificates (request_id, certificate_code, issued_by)
                     VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE certificate_code = VALUES(certificate_code), issued_by = VALUES(issued_by)'
                );
                $stmt->bind_param('isi', $request_pk, $cert_code, $clinic_pk);
                $stmt->execute();

                // Notify student
                $student_id = (int)($req['student_user_id'] ?? 0);
                if ($student_id > 0) {
                    $title = 'Request Approved';
                    $message = 'Your medical request REQ-' . str_pad((string)$request_pk, 3, '0', STR_PAD_LEFT) . ' was approved. Certificate is valid until ' . $valid_until . '.';
                    $stmt = $conn->prepare('INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)');
                    $stmt->bind_param('iss', $student_id, $title, $message);
                    $stmt->execute();
                }

                $action_success = 'Request approved.';
            } else { // reject
                $reason = trim((string)($_POST['rejection_reason'] ?? ''));
                if ($reason === '') {
                    $action_error = 'Rejection reason is required.';
                } else {
                    $stmt = $conn->prepare(
                        'UPDATE medical_requests
                         SET status="Rejected",
                             rejected_date=?,
                             rejection_reason=?,
                             approved_date=NULL,
                             valid_until=NULL,
                             reviewed_by=?
                         WHERE id=?'
                    );
                    $stmt->bind_param('ssii', $today, $reason, $clinic_pk, $request_pk);
                    $stmt->execute();

                    // Notify student
                    $student_id = (int)($req['student_user_id'] ?? 0);
                    if ($student_id > 0) {
                        $title = 'Request Rejected';
                        $message = 'Your medical request REQ-' . str_pad((string)$request_pk, 3, '0', STR_PAD_LEFT) . ' was rejected. Reason: ' . $reason;
                        $stmt = $conn->prepare('INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)');
                        $stmt->bind_param('iss', $student_id, $title, $message);
                        $stmt->execute();
                    }

                    $action_success = 'Request rejected.';
                }
            }

            // Redirect to avoid resubmission
            if ($action_success) {
                header('Location: clinic_dashboard.php' . ($_SERVER['QUERY_STRING'] ? ('?' . $_SERVER['QUERY_STRING']) : ''));
                exit;
            }
        }
    }
} catch (Throwable $e) {
    error_log((string)$e);
    $action_error = $action_error ?: 'Database error while processing action.';
}

$all_requests = 0;
$pending_requests = 0;
$approved_requests = 0;
$rejected_requests = 0;

$user_name = isset($_SESSION['full_name']) ? $_SESSION['full_name'] : $_SESSION['user_id'];
$current_page = 'dashboard';

$requests_list = [];
$course_options = [];
$documents_by_request = [];

$allowed_status_filters = ['all', 'pending', 'approved', 'rejected'];
$filter_status = strtolower((string)($_GET['status'] ?? 'all'));
if (!in_array($filter_status, $allowed_status_filters, true)) {
    $filter_status = 'all';
}
$status_to_db = [
    'pending' => 'Pending',
    'approved' => 'Approved',
    'rejected' => 'Rejected',
];
$filter_course = trim((string)($_GET['course'] ?? ''));

try {
    $conn = db();

    $result = $conn->query(
        'SELECT DISTINCT course
         FROM users
         WHERE role = "student" AND course IS NOT NULL AND TRIM(course) <> ""
         ORDER BY course ASC'
    );
    $course_rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    foreach ($course_rows as $course_row) {
        $course_value = trim((string)($course_row['course'] ?? ''));
        if ($course_value !== '') {
            $course_options[] = $course_value;
        }
    }
    if ($filter_course !== '' && !in_array($filter_course, $course_options, true)) {
        $filter_course = '';
    }

    $base_conditions = ['u.role = "student"'];
    $base_types = '';
    $base_params = [];
    if ($filter_course !== '') {
        $base_conditions[] = 'u.course = ?';
        $base_types .= 's';
        $base_params[] = $filter_course;
    }
    $base_where = implode(' AND ', $base_conditions);

    $counts_sql = 'SELECT
            COUNT(*) AS total_count,
            SUM(mr.status = "Pending") AS pending_count,
            SUM(mr.status = "Approved") AS approved_count,
            SUM(mr.status = "Rejected") AS rejected_count
         FROM medical_requests mr
         JOIN users u ON u.id = mr.student_user_id
         WHERE ' . $base_where;
    $stmt = $conn->prepare($counts_sql);
    if ($base_types !== '') {
        $stmt->bind_param($base_types, ...$base_params);
    }
    $stmt->execute();
    $counts = $stmt->get_result()->fetch_assoc() ?: [];

    $all_requests = (int)($counts['total_count'] ?? 0);
    $pending_requests = (int)($counts['pending_count'] ?? 0);
    $approved_requests = (int)($counts['approved_count'] ?? 0);
    $rejected_requests = (int)($counts['rejected_count'] ?? 0);

    $list_conditions = $base_conditions;
    $list_types = $base_types;
    $list_params = $base_params;
    if ($filter_status !== 'all') {
        $list_conditions[] = 'mr.status = ?';
        $list_types .= 's';
        $list_params[] = $status_to_db[$filter_status];
    }
    $list_where = implode(' AND ', $list_conditions);

    $list_sql = 'SELECT
            u.full_name AS student_name,
            u.user_code AS student_id,
            u.course AS student_course,
            u.year_level AS student_year_level,
            mr.id AS request_pk,
            mr.illness,
            mr.symptoms,
            mr.illness_date,
            mr.submitted_date,
            CONCAT("REQ-", LPAD(mr.id, 3, "0")) AS request_id,
            mr.status,
            mr.approved_date,
            mr.valid_until,
            mr.rejected_date,
            mr.rejection_reason
         FROM medical_requests mr
         JOIN users u ON u.id = mr.student_user_id
         WHERE ' . $list_where . '
         ORDER BY mr.id DESC
         LIMIT 100';
    $stmt = $conn->prepare($list_sql);
    if ($list_types !== '') {
        $stmt->bind_param($list_types, ...$list_params);
    }
    $stmt->execute();
    $requests_list = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];

    // Load documents for current list (group by request id)
    $request_ids = [];
    foreach ($requests_list as $r) {
        if (isset($r['request_pk'])) {
            $request_ids[] = (int)$r['request_pk'];
        }
    }
    $request_ids = array_values(array_unique(array_filter($request_ids, fn($v) => $v > 0)));
    if (!empty($request_ids)) {
        $placeholders = implode(',', array_fill(0, count($request_ids), '?'));
        $types = str_repeat('i', count($request_ids));
        $sql = 'SELECT id, request_id, original_name, uploaded_at
                FROM medical_request_documents
                WHERE request_id IN (' . $placeholders . ')
                ORDER BY uploaded_at DESC, id DESC';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param($types, ...$request_ids);
        $stmt->execute();
        $docs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        foreach ($docs as $d) {
            $rid = (int)($d['request_id'] ?? 0);
            if ($rid <= 0) continue;
            if (!isset($documents_by_request[$rid])) {
                $documents_by_request[$rid] = [];
            }
            $documents_by_request[$rid][] = $d;
        }
    }
} catch (Throwable $e) {
    error_log((string)$e);
}

$status_label_map = [
    'all' => 'All Requests',
    'pending' => 'Pending Requests',
    'approved' => 'Approved Requests',
    'rejected' => 'Rejected Requests',
];

$build_filter_url = static function (string $status, string $course): string {
    $query = ['status' => $status];
    if ($course !== '') {
        $query['course'] = $course;
    }
    return '?' . http_build_query($query);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Clinic Dashboard - MediClear</title>
    <script>
        (function () {
            try {
                var storedTheme = localStorage.getItem('mediclear-theme');
                if (storedTheme === 'dark' || storedTheme === 'light') {
                    document.documentElement.setAttribute('data-theme', storedTheme);
                } else if (!document.documentElement.getAttribute('data-theme')) {
                    document.documentElement.setAttribute('data-theme', 'light');
                }
            } catch (e) {
                // If localStorage is unavailable, keep the default theme.
            }
        })();
    </script>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="app-layout">
        <aside class="sidebar">
            <div class="sidebar-logo">
                <img src="pics/MediLink.png" alt="MediLink Logo" class="sidebar-logo-img">
            </div>
            <nav class="sidebar-nav">
                <a href="clinic_dashboard.php" class="nav-item <?php echo $current_page === 'dashboard' ? 'active' : ''; ?>">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="7" height="7"></rect>
                        <rect x="14" y="3" width="7" height="7"></rect>
                        <rect x="14" y="14" width="7" height="7"></rect>
                        <rect x="3" y="14" width="7" height="7"></rect>
                    </svg>
                    <span>Dashboard</span>
                </a>
            </nav>
            <div class="sidebar-account">
                <div class="account-header">ACCOUNT</div>
                <div class="account-profile-card">
                    <div class="profile-avatar">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                    </div>
                    <div class="profile-info">
                        <div class="profile-name"><?php echo htmlspecialchars($user_name); ?></div>
                        <div class="profile-role">Clinic Staff</div>
                    </div>
                </div>
                <a href="logout.php" class="account-logout">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="20" height="20">
                        <path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"></path>
                        <polyline points="16 17 21 12 16 7"></polyline>
                        <line x1="21" y1="12" x2="9" y2="12"></line>
                    </svg>
                    <span>Logout</span>
                </a>
            </div>
        </aside>
        
        <main class="main-content">
            <header class="top-header">
                <div class="header-left">
                    <button class="hamburger-menu" id="hamburgerMenu" aria-label="Toggle navigation">
                        <svg class="hamburger-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <line x1="3" y1="12" x2="21" y2="12"></line>
                            <line x1="3" y1="6" x2="21" y2="6"></line>
                            <line x1="3" y1="18" x2="21" y2="18"></line>
                        </svg>
                    </button>
                    <div class="header-logo">
                        <img src="pics/LOGOLASALLE.png" alt="University of St. La Salle" class="header-logo-img">
                    </div>
                </div>
                <div class="header-right">
                    <div class="header-actions">
                        <button class="icon-btn theme-toggle" id="themeToggle" aria-label="Toggle dark mode" type="button">
                            <span class="icon-wrapper theme-icon-sun">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="4"></circle>
                                    <path d="M12 2v2m0 16v2m10-10h-2M4 12H2m15.364-7.364L17 5m-10 14l-1.364 1.364M17 19l1.364 1.364M5 5L3.636 3.636"></path>
                                </svg>
                            </span>
                            <span class="icon-wrapper theme-icon-moon">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"></path>
                                </svg>
                            </span>
                        </button>
                        <button class="user-profile-widget" type="button" aria-label="Logged in user">
                            <span class="user-avatar user-avatar-initial">
                                <span class="user-avatar-text"><?php echo htmlspecialchars(strtoupper(substr($user_name, 0, 1))); ?></span>
                            </span>
                            <span class="user-info">
                                <span class="user-name"><?php echo htmlspecialchars($user_name); ?></span>
                                <span class="user-role-row">
                                    <span class="user-status-dot"></span>
                                    <span class="user-role">Clinic Staff</span>
                                </span>
                            </span>
                            <span class="user-widget-arrow" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <polyline points="6 9 12 15 18 9"></polyline>
                                </svg>
                            </span>
                        </button>
                    </div>
                </div>
            </header>
            
            <div class="page-content">
                <?php if (!empty($action_error)): ?>
                    <div class="error-message"><?php echo htmlspecialchars($action_error, ENT_QUOTES, 'UTF-8'); ?></div>
                <?php endif; ?>
                <div class="welcome-banner" style="margin-bottom: 20px;">
                    <div class="welcome-left">
                        <div class="welcome-label">Clinic Dashboard</div>
                        <h1 class="welcome-title">Good Day, <?php echo htmlspecialchars($user_name); ?>!</h1>
                        <p class="welcome-subtitle">Review pending medical certification requests and take action.</p>
                        <div class="welcome-meta">
                            <span class="welcome-pill">
                                <span class="dot dot-online"></span>
                                Clinic staff · Requests management
                            </span>
                        </div>
                    </div>
                    <div class="welcome-illustration">
                        <div class="doctor-card">
                            <div class="doctor-avatar">
                                <span class="doctor-icon">🩺</span>
                            </div>
                            <div class="doctor-info">
                                <div class="doctor-name">Campus Clinic</div>
                                <div class="doctor-role">Health & Wellness Center</div>
                                <div class="doctor-hours">Mon–Fri · 9am–5pm</div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="stats-grid">
                    <a href="<?php echo htmlspecialchars($build_filter_url('all', $filter_course), ENT_QUOTES, 'UTF-8'); ?>" class="stat-card-link <?php echo $filter_status === 'all' ? 'active' : ''; ?>">
                        <div class="stat-card stat-total">
                            <h3>All Requests</h3>
                            <p class="stat-number"><?php echo $all_requests; ?></p>
                        </div>
                    </a>
                    <a href="<?php echo htmlspecialchars($build_filter_url('pending', $filter_course), ENT_QUOTES, 'UTF-8'); ?>" class="stat-card-link <?php echo $filter_status === 'pending' ? 'active' : ''; ?>">
                        <div class="stat-card stat-pending">
                            <h3>Pending</h3>
                            <p class="stat-number"><?php echo $pending_requests; ?></p>
                        </div>
                    </a>
                    <a href="<?php echo htmlspecialchars($build_filter_url('approved', $filter_course), ENT_QUOTES, 'UTF-8'); ?>" class="stat-card-link <?php echo $filter_status === 'approved' ? 'active' : ''; ?>">
                        <div class="stat-card stat-approved">
                            <h3>Approved</h3>
                            <p class="stat-number"><?php echo $approved_requests; ?></p>
                        </div>
                    </a>
                    <a href="<?php echo htmlspecialchars($build_filter_url('rejected', $filter_course), ENT_QUOTES, 'UTF-8'); ?>" class="stat-card-link <?php echo $filter_status === 'rejected' ? 'active' : ''; ?>">
                        <div class="stat-card stat-rejected">
                            <h3>Rejected</h3>
                            <p class="stat-number"><?php echo $rejected_requests; ?></p>
                        </div>
                    </a>
                </div>
                
                <div class="requests-section content-card">
                    <div class="section-header">
                        <h3><?php echo htmlspecialchars($status_label_map[$filter_status], ENT_QUOTES, 'UTF-8'); ?> (<?php echo count($requests_list); ?>)</h3>
                    </div>

                    <div class="filter-section clinic-filters">
                        <div class="filter-buttons">
                            <a href="<?php echo htmlspecialchars($build_filter_url('all', $filter_course), ENT_QUOTES, 'UTF-8'); ?>" class="filter-btn <?php echo $filter_status === 'all' ? 'active' : ''; ?>">All Requests</a>
                            <a href="<?php echo htmlspecialchars($build_filter_url('pending', $filter_course), ENT_QUOTES, 'UTF-8'); ?>" class="filter-btn <?php echo $filter_status === 'pending' ? 'active' : ''; ?>">Pending</a>
                            <a href="<?php echo htmlspecialchars($build_filter_url('approved', $filter_course), ENT_QUOTES, 'UTF-8'); ?>" class="filter-btn <?php echo $filter_status === 'approved' ? 'active' : ''; ?>">Approved</a>
                            <a href="<?php echo htmlspecialchars($build_filter_url('rejected', $filter_course), ENT_QUOTES, 'UTF-8'); ?>" class="filter-btn <?php echo $filter_status === 'rejected' ? 'active' : ''; ?>">Rejected</a>
                        </div>
                        <form method="GET" class="clinic-course-filter">
                            <input type="hidden" name="status" value="<?php echo htmlspecialchars($filter_status, ENT_QUOTES, 'UTF-8'); ?>">
                            <label for="courseFilter">Category / Course</label>
                            <select id="courseFilter" name="course" onchange="this.form.submit()">
                                <option value="">All Courses</option>
                                <?php foreach ($course_options as $course_value): ?>
                                    <option value="<?php echo htmlspecialchars($course_value, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $filter_course === $course_value ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($course_value, ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </div>
                    
                    <?php if (empty($requests_list)): ?>
                        <div class="empty-state">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                            </svg>
                            <p>No requests found for this filter.</p>
                        </div>
                    <?php else: ?>
                    <div class="clinic-requests-list">
                        <?php foreach ($requests_list as $request): ?>
                        <div class="clinic-request-item">
                            <div class="request-header">
                                <div class="student-info">
                                    <div class="student-avatar">
                                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor">
                                            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                                        </svg>
                                    </div>
                                    <div>
                                        <h4><?php echo htmlspecialchars($request['student_name']); ?></h4>
                                        <p class="student-id"><?php echo htmlspecialchars($request['student_id']); ?></p>
                                        <p class="student-id"><?php echo htmlspecialchars((string)($request['student_course'] ?? 'N/A')); ?> <?php echo htmlspecialchars((string)($request['student_year_level'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                    </div>
                                </div>
                                <div class="request-status-header">
                                    <?php if ($request['status'] === 'Approved'): ?>
                                        <span class="badge badge-approved">Approved</span>
                                    <?php elseif ($request['status'] === 'Pending'): ?>
                                        <span class="badge badge-pending">Pending</span>
                                    <?php elseif ($request['status'] === 'Rejected'): ?>
                                        <span class="badge badge-rejected">Rejected</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="request-body">
                                <h5><?php echo htmlspecialchars($request['illness']); ?></h5>
                                <p class="symptoms"><?php echo htmlspecialchars($request['symptoms']); ?></p>
                                <?php
                                    $rid = (int)($request['request_pk'] ?? 0);
                                    $docs = $rid > 0 ? ($documents_by_request[$rid] ?? []) : [];
                                ?>
                                <?php if (!empty($docs)): ?>
                                    <div class="request-documents">
                                        <div class="request-documents-title">Supporting documents</div>
                                        <ul class="request-documents-list">
                                            <?php foreach ($docs as $doc): ?>
                                                <li>
                                                    <a href="download_document.php?id=<?php echo (int)$doc['id']; ?>" class="request-doc-link" target="_blank" rel="noopener">
                                                        <?php echo htmlspecialchars((string)$doc['original_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                    </a>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="request-footer">
                                <div class="request-meta">
                                    <span class="meta-item">Illness: <?php echo htmlspecialchars($request['illness_date']); ?></span>
                                    <span class="meta-item">Submitted: <?php echo htmlspecialchars($request['submitted_date']); ?></span>
                                    <span class="meta-item">ID: <?php echo htmlspecialchars($request['request_id']); ?></span>
                                </div>
                                
                                <?php if ($request['status'] === 'Approved'): ?>
                                <div class="request-validity">
                                    <p class="validity-text">
                                        <span class="validity-approved">Approved: <?php echo htmlspecialchars($request['approved_date']); ?></span>
                                        <span class="validity-until">Valid until: <?php echo htmlspecialchars($request['valid_until']); ?></span>
                                    </p>
                                    <a class="btn-view-cert status-approved" href="certificate.php?request_id=<?php echo (int)($request['request_pk'] ?? 0); ?>" target="_blank" rel="noopener">View Certificate</a>
                                </div>
                                <?php elseif ($request['status'] === 'Pending'): ?>
                                <div class="request-actions">
                                    <button
                                        class="btn-approve"
                                        type="button"
                                        data-action="approve"
                                        data-request-pk="<?php echo (int)($request['request_pk'] ?? 0); ?>"
                                        data-request-id="<?php echo htmlspecialchars((string)$request['request_id'], ENT_QUOTES, 'UTF-8'); ?>"
                                    >
                                        Approve
                                    </button>
                                    <button
                                        class="btn-reject"
                                        type="button"
                                        data-action="reject"
                                        data-request-pk="<?php echo (int)($request['request_pk'] ?? 0); ?>"
                                        data-request-id="<?php echo htmlspecialchars((string)$request['request_id'], ENT_QUOTES, 'UTF-8'); ?>"
                                    >
                                        Reject
                                    </button>
                                </div>
                                <?php elseif ($request['status'] === 'Rejected'): ?>
                                <div class="request-validity">
                                    <p class="validity-text">
                                        <span class="validity-until">Rejected: <?php echo htmlspecialchars((string)$request['rejected_date']); ?></span>
                                    </p>
                                    <?php if (!empty($request['rejection_reason'])): ?>
                                        <p class="validity-text"><span class="validity-until">Reason: <?php echo htmlspecialchars((string)$request['rejection_reason'], ENT_QUOTES, 'UTF-8'); ?></span></p>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>

    <!-- Approve/Reject Modal -->
    <div class="certificate-modal-overlay" id="clinicActionModal" style="display:none;">
        <div class="certificate-modal">
            <div class="certificate-modal-header">
                <h3 id="clinicActionTitle">Update Request</h3>
                <button type="button" class="certificate-modal-close" id="clinicActionClose">&times;</button>
            </div>
            <div class="certificate-modal-body">
                <p id="clinicActionText">—</p>
                <div id="clinicRejectBox" style="display:none; margin-top: 12px;">
                    <label for="clinicRejectReason" style="display:block; font-weight:700; margin-bottom:6px;">Rejection reason</label>
                    <input type="text" id="clinicRejectReason" class="reject-reason-input" placeholder="Enter reason (required)">
                </div>
            </div>
            <div class="certificate-modal-footer">
                <button type="button" class="btn-secondary certificate-modal-close-btn" id="clinicActionCancel">Cancel</button>
                <button type="button" class="btn-submit" id="clinicActionConfirm">Confirm</button>
            </div>
        </div>
    </div>

    <form method="POST" id="clinicActionForm" style="display:none;">
        <input type="hidden" name="action" id="clinicActionField" value="">
        <input type="hidden" name="request_pk" id="clinicRequestPkField" value="">
        <input type="hidden" name="rejection_reason" id="clinicRejectionReasonField" value="">
    </form>

    <script src="js/script.js"></script>
    <script>
        (function () {
            const modal = document.getElementById('clinicActionModal');
            const closeBtn = document.getElementById('clinicActionClose');
            const cancelBtn = document.getElementById('clinicActionCancel');
            const confirmBtn = document.getElementById('clinicActionConfirm');
            const titleEl = document.getElementById('clinicActionTitle');
            const textEl = document.getElementById('clinicActionText');
            const rejectBox = document.getElementById('clinicRejectBox');
            const rejectInput = document.getElementById('clinicRejectReason');

            const form = document.getElementById('clinicActionForm');
            const actionField = document.getElementById('clinicActionField');
            const requestPkField = document.getElementById('clinicRequestPkField');
            const rejectionReasonField = document.getElementById('clinicRejectionReasonField');

            let currentAction = null;

            function openModal(action, requestPk, requestId) {
                currentAction = action;
                actionField.value = action;
                requestPkField.value = requestPk;
                rejectionReasonField.value = '';
                if (rejectInput) rejectInput.value = '';

                if (action === 'approve') {
                    titleEl.textContent = `Approve ${requestId}`;
                    textEl.textContent = 'Are you sure you want to approve this medical request? This action will generate the medical certificate.';
                    rejectBox.style.display = 'none';
                    confirmBtn.textContent = 'Approve';
                } else {
                    titleEl.textContent = `Reject ${requestId}`;
                    textEl.textContent = 'Please provide a brief reason for rejecting this medical request.';
                    rejectBox.style.display = 'block';
                    confirmBtn.textContent = 'Reject';
                }

                modal.style.display = 'flex';
            }

            function closeModal() {
                modal.style.display = 'none';
                currentAction = null;
            }

            document.addEventListener('click', function (e) {
                const btn = e.target.closest('button[data-action]');
                if (!btn) return;
                const action = btn.getAttribute('data-action');
                const requestPk = btn.getAttribute('data-request-pk');
                const requestId = btn.getAttribute('data-request-id') || 'REQ';
                openModal(action, requestPk, requestId);
            });

            function submitAction() {
                if (currentAction === 'reject') {
                    const reason = (rejectInput ? rejectInput.value : '').trim();
                    if (!reason) {
                        // no browser alert: just inline message
                        textEl.textContent = 'Please enter a rejection reason.';
                        return;
                    }
                    rejectionReasonField.value = reason;
                }
                form.submit();
            }

            confirmBtn.addEventListener('click', submitAction);
            closeBtn.addEventListener('click', closeModal);
            cancelBtn.addEventListener('click', closeModal);
            modal.addEventListener('click', function (e) {
                if (e.target === modal) closeModal();
            });
        })();
    </script>
</body>
</html>
