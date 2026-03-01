<?php
session_start();

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
app_dev_auto_login();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: index.php');
    exit;
}

// Load requests from database
$all_requests = [];

$profile_error = null;
$profile = [
    'user_code' => (string)($_SESSION['user_id'] ?? ''),
    'full_name' => (string)($_SESSION['full_name'] ?? ''),
    'email' => '',
    'date_of_birth' => '',
    'course' => '',
    'year_level' => '',
];

// Filter requests by status if filter is set
$allowed_filters = ['all', 'pending', 'approved', 'rejected'];
$filter_status = strtolower((string)($_GET['filter'] ?? 'all'));
if (!in_array($filter_status, $allowed_filters, true)) {
    $filter_status = 'all';
}
$filtered_requests = [];
$total_count = 0;
$approved_count = 0;
$pending_count = 0;
$rejected_count = 0;

try {
    $student_pk = (int)($_SESSION['user_pk'] ?? 0);
    if ($student_pk <= 0) {
        $user_code = (string)($_SESSION['user_id'] ?? '');
        if ($user_code !== '') {
            $conn = db();
            $stmt = $conn->prepare('SELECT id FROM users WHERE user_code = ? AND role = "student" LIMIT 1');
            $stmt->bind_param('s', $user_code);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $student_pk = (int)($row['id'] ?? 0);
            if ($student_pk > 0) {
                $_SESSION['user_pk'] = $student_pk;
            }
        }
    }

    if ($student_pk > 0) {
        $conn = db();

        // Handle profile update (My Profile panel)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['profile_action'] ?? '') === 'update_profile') {
            $full_name = trim((string)($_POST['full_name'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $date_of_birth = trim((string)($_POST['date_of_birth'] ?? ''));
            $course = trim((string)($_POST['course'] ?? ''));
            $year_level = trim((string)($_POST['year_level'] ?? ''));
            $new_password = (string)($_POST['password'] ?? '');
            $new_password_confirm = (string)($_POST['password_confirm'] ?? '');

            if ($full_name === '') {
                $profile_error = 'Full name is required.';
            } elseif ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $profile_error = 'Please enter a valid email address.';
            } elseif ($new_password !== '' && strlen($new_password) < 6) {
                $profile_error = 'New password must be at least 6 characters.';
            } elseif ($new_password !== '' && $new_password !== $new_password_confirm) {
                $profile_error = 'New password and confirmation do not match.';
            } else {
                $date_of_birth_value = $date_of_birth !== '' ? $date_of_birth : null;
                if ($new_password !== '') {
                    $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare(
                        'UPDATE users
                         SET full_name = ?, email = ?, date_of_birth = ?, course = ?, year_level = ?, password_hash = ?
                         WHERE id = ? AND role = "student"'
                    );
                    $stmt->bind_param('ssssssi', $full_name, $email, $date_of_birth_value, $course, $year_level, $password_hash, $student_pk);
                    $stmt->execute();
                } else {
                    $stmt = $conn->prepare(
                        'UPDATE users
                         SET full_name = ?, email = ?, date_of_birth = ?, course = ?, year_level = ?
                         WHERE id = ? AND role = "student"'
                    );
                    $stmt->bind_param('sssssi', $full_name, $email, $date_of_birth_value, $course, $year_level, $student_pk);
                    $stmt->execute();
                }
                $_SESSION['full_name'] = $full_name;
                $_SESSION['success_message'] = 'Profile updated successfully.';
                header('Location: view_all_requests.php' . ($filter_status !== 'all' ? '?filter=' . urlencode($filter_status) : ''));
                exit;
            }
        }

        // Load profile from database so panel always shows current data
        $stmt = $conn->prepare('SELECT user_code, full_name, email, date_of_birth, course, year_level FROM users WHERE id = ? AND role = "student" LIMIT 1');
        $stmt->bind_param('i', $student_pk);
        $stmt->execute();
        $urow = $stmt->get_result()->fetch_assoc() ?: [];
        if (!empty($urow)) {
            $profile['user_code'] = (string)($urow['user_code'] ?? $profile['user_code']);
            $profile['full_name'] = (string)($urow['full_name'] ?? $profile['full_name']);
            $profile['email'] = (string)($urow['email'] ?? '');
            $profile['date_of_birth'] = isset($urow['date_of_birth']) && $urow['date_of_birth'] !== null && $urow['date_of_birth'] !== '' ? (string)$urow['date_of_birth'] : '';
            $profile['course'] = (string)($urow['course'] ?? '');
            $profile['year_level'] = (string)($urow['year_level'] ?? '');
            if (!empty($profile['full_name'])) {
                $_SESSION['full_name'] = $profile['full_name'];
            }
        }

        $stmt = $conn->prepare(
            'SELECT
                COUNT(*) AS total_count,
                SUM(status = "Approved") AS approved_count,
                SUM(status = "Pending") AS pending_count,
                SUM(status = "Rejected") AS rejected_count
             FROM medical_requests
             WHERE student_user_id = ?'
        );
        $stmt->bind_param('i', $student_pk);
        $stmt->execute();
        $counts = $stmt->get_result()->fetch_assoc() ?: [];

        $total_count = (int)($counts['total_count'] ?? 0);
        $approved_count = (int)($counts['approved_count'] ?? 0);
        $pending_count = (int)($counts['pending_count'] ?? 0);
        $rejected_count = (int)($counts['rejected_count'] ?? 0);

        if ($filter_status === 'all') {
            $stmt = $conn->prepare(
                'SELECT
                    id AS request_pk,
                    CONCAT("REQ-", LPAD(id, 3, "0")) AS id,
                    illness,
                    symptoms AS description,
                    status,
                    submitted_date,
                    approved_date,
                    rejected_date,
                    valid_until,
                    rejection_reason
                 FROM medical_requests
                 WHERE student_user_id = ?
                 ORDER BY id DESC'
            );
            $stmt->bind_param('i', $student_pk);
        } else {
            $status = ucfirst($filter_status); // pending -> Pending
            $stmt = $conn->prepare(
                'SELECT
                    id AS request_pk,
                    CONCAT("REQ-", LPAD(id, 3, "0")) AS id,
                    illness,
                    symptoms AS description,
                    status,
                    submitted_date,
                    approved_date,
                    rejected_date,
                    valid_until,
                    rejection_reason
                 FROM medical_requests
                 WHERE student_user_id = ? AND status = ?
                 ORDER BY id DESC'
            );
            $stmt->bind_param('is', $student_pk, $status);
        }

        $stmt->execute();
        $filtered_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
        $all_requests = $filtered_requests;
    }
} catch (Throwable $e) {
    error_log((string)$e);
}

$user_name = isset($_SESSION['full_name']) && $_SESSION['full_name'] !== '' ? $_SESSION['full_name'] : ($_SESSION['user_id'] ?? 'Student');
$current_page = 'requests';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Medical Requests - MediClear</title>
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
                <a href="student_dashboard.php" class="nav-item <?php echo $current_page === 'dashboard' ? 'active' : ''; ?>">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"></path>
                        <polyline points="9 22 9 12 15 12 15 22"></polyline>
                    </svg>
                    <span>My Dashboard</span>
                </a>
                <a href="view_all_requests.php" class="nav-item <?php echo $current_page === 'requests' ? 'active' : ''; ?>">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M4 4h16v4H4z"></path>
                        <path d="M4 12h16v4H4z"></path>
                        <path d="M4 20h10v-4H4z"></path>
                    </svg>
                    <span>My Requests</span>
                </a>
                <a href="submit_request.php" class="nav-item <?php echo $current_page === 'submit' ? 'active' : ''; ?>">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="9"></circle>
                        <line x1="12" y1="8" x2="12" y2="16"></line>
                        <line x1="8" y1="12" x2="16" y2="12"></line>
                    </svg>
                    <span>Submit Medical Request</span>
                </a>
                <a href="#" class="nav-item nav-item-muted" id="navNotifications">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path>
                        <path d="M13.73 21a2 2 0 01-3.46 0"></path>
                    </svg>
                    <span>Notifications</span>
                </a>
                <a href="#" class="nav-item nav-item-muted" id="navProfile">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                    <span>My Profile</span>
                </a>
                <a href="#" class="nav-item nav-item-muted" id="navSettings">
                    <svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="3"></circle>
                        <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 01-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.6 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.6a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09A1.65 1.65 0 0015 4.6a1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9c.9 0 1.6.73 1.6 1.63v.74A1.65 1.65 0 0019.4 15z"></path>
                    </svg>
                    <span>Settings</span>
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
                        <div class="profile-role">Student</div>
                    </div>
                </div>
                <a href="logout.php" class="account-logout">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
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
                        <button class="icon-btn" id="notificationToggle" aria-label="Notifications">
                            <span class="icon-wrapper">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path>
                                    <path d="M13.73 21a2 2 0 01-3.46 0"></path>
                                </svg>
                            </span>
                            <span class="icon-indicator"></span>
                        </button>
                        <button class="icon-btn" id="settingsToggle" aria-label="Settings">
                            <span class="icon-wrapper">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="3"></circle>
                                    <path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 01-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83-2.83l.06-.06A1.65 1.65 0 004.6 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 012.83-2.83l.06.06A1.65 1.65 0 009 4.6a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09A1.65 1.65 0 0015 4.6a1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 2.83l-.06.06A1.65 1.65 0 0019.4 9c.9 0 1.6.73 1.6 1.63v.74A1.65 1.65 0 0019.4 15z"></path>
                                </svg>
                            </span>
                        </button>
                        <button class="icon-btn theme-toggle" id="themeToggle" aria-label="Toggle dark mode">
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
                    </div>
                    <button class="user-profile-widget" id="openProfilePanelHeader" type="button" aria-label="Open profile panel">
                        <div class="user-avatar user-avatar-initial">
                            <span class="user-avatar-text"><?php echo htmlspecialchars(strtoupper(substr($user_name, 0, 1)), ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="user-info">
                            <div class="user-name"><?php echo htmlspecialchars($user_name); ?></div>
                            <div class="user-role-row">
                                <span class="user-role">Student</span>
                                <span class="user-status-dot" aria-hidden="true"></span>
                            </div>
                        </div>
                        <span class="user-widget-arrow" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="9 6 15 12 9 18"></polyline>
                            </svg>
                        </span>
                    </button>
                </div>
            </header>
            
            <div class="page-content">
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="success-message">
                        <?php
                            echo htmlspecialchars($_SESSION['success_message']);
                            unset($_SESSION['success_message']);
                        ?>
                    </div>
                <?php endif; ?>
                <h1>All Medical Requests</h1>
                <div class="stats-grid">
                    <div class="stat-card stat-total">
                        <div class="stat-label">Total Request</div>
                        <div class="stat-number"><?php echo $total_count; ?></div>
                    </div>
                    <div class="stat-card stat-pending">
                        <div class="stat-label">Pending</div>
                        <div class="stat-number"><?php echo $pending_count; ?></div>
                    </div>
                    <div class="stat-card stat-approved">
                        <div class="stat-label">Approved</div>
                        <div class="stat-number"><?php echo $approved_count; ?></div>
                    </div>
                    <div class="stat-card stat-rejected">
                        <div class="stat-label">Rejected</div>
                        <div class="stat-number"><?php echo $rejected_count; ?></div>
                    </div>
                </div>
                
                <div class="filter-section">
                    <div class="filter-buttons">
                        <a href="?filter=all" class="filter-btn <?php echo $filter_status === 'all' ? 'active' : ''; ?>">All Requests</a>
                        <a href="?filter=pending" class="filter-btn <?php echo $filter_status === 'pending' ? 'active' : ''; ?>">Pending</a>
                        <a href="?filter=approved" class="filter-btn <?php echo $filter_status === 'approved' ? 'active' : ''; ?>">Approved</a>
                        <a href="?filter=rejected" class="filter-btn <?php echo $filter_status === 'rejected' ? 'active' : ''; ?>">Rejected</a>
                    </div>
                </div>
                
                <div class="requests-section">
                    <h2 class="section-title">
                        <?php 
                            if ($filter_status === 'all') {
                                echo 'All Medical Requests (' . count($filtered_requests) . ')';
                            } else {
                                echo htmlspecialchars(ucfirst($filter_status), ENT_QUOTES, 'UTF-8') . ' Requests (' . count($filtered_requests) . ')';
                            }
                        ?>
                    </h2>
                    
                    <?php if (empty($filtered_requests)): ?>
                        <div class="empty-state">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                            </svg>
                            <p>No <?php echo $filter_status === 'all' ? '' : htmlspecialchars(strtolower($filter_status), ENT_QUOTES, 'UTF-8'); ?> requests found.</p>
                        </div>
                    <?php else: ?>
                        <div class="requests-list">
                            <?php foreach ($filtered_requests as $request): ?>
                            <div class="request-item">
                                <div class="request-status-icon status-<?php echo htmlspecialchars(strtolower($request['status']), ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php if ($request['status'] === 'Approved'): ?>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                            <polyline points="20 6 9 17 4 12"></polyline>
                                        </svg>
                                    <?php elseif ($request['status'] === 'Pending'): ?>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                            <circle cx="12" cy="12" r="10"></circle>
                                            <polyline points="12 6 12 12 16 14"></polyline>
                                        </svg>
                                    <?php else: ?>
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                            <line x1="18" y1="6" x2="6" y2="18"></line>
                                            <line x1="6" y1="6" x2="18" y2="18"></line>
                                        </svg>
                                    <?php endif; ?>
                                </div>
                                <div class="request-content">
                                    <div class="request-title"><?php echo htmlspecialchars($request['illness']); ?></div>
                                    <div class="request-description"><?php echo htmlspecialchars($request['description']); ?></div>
                                    <div class="request-meta">
                                        <?php echo htmlspecialchars($request['id']); ?> 
                                        Submitted: <?php echo htmlspecialchars($request['submitted_date']); ?>
                                        <?php if (isset($request['approved_date'])): ?>
                                            • Approved: <?php echo htmlspecialchars($request['approved_date']); ?>
                                            • Valid until: <?php echo htmlspecialchars($request['valid_until']); ?>
                                        <?php endif; ?>
                                        <?php if (isset($request['rejected_date'])): ?>
                                            • Rejected: <?php echo htmlspecialchars($request['rejected_date']); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (isset($request['rejection_reason'])): ?>
                                        <div class="rejection-reason">
                                            <strong>Reason:</strong> <?php echo htmlspecialchars($request['rejection_reason']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="request-actions">
                                    <span class="status-badge badge-<?php echo htmlspecialchars(strtolower($request['status']), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(strtoupper($request['status'])); ?></span>
                                    <?php if ($request['status'] === 'Approved'): ?>
                                        <a class="btn-view-cert status-approved" href="certificate.php?request_id=<?php echo (int)($request['request_pk'] ?? 0); ?>" target="_blank" rel="noopener" data-request-id="<?php echo (int)($request['request_pk'] ?? 0); ?>">View Certificate</a>
                                    <?php elseif ($request['status'] === 'Pending'): ?>
                                        <button class="btn-view-cert status-pending" disabled>Pending Review</button>
                                    <?php else: ?>
                                        <button class="btn-view-cert status-rejected" type="button">View Details</button>
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
    
    <!-- Notifications Panel -->
    <div class="overlay-panel" id="notificationsPanel" aria-hidden="true">
        <div class="overlay-panel-inner">
            <div class="overlay-panel-header">
                <h3>Notifications</h3>
                <button class="overlay-close-btn" data-close-panel="notificationsPanel" type="button">&times;</button>
            </div>
            <div class="overlay-panel-body">
                <p class="overlay-empty-text">You’re all caught up. New updates from the clinic will appear here.</p>
            </div>
        </div>
    </div>
    
    <!-- Settings Panel -->
    <div class="overlay-panel" id="settingsPanel" aria-hidden="true">
        <div class="overlay-panel-inner">
            <div class="overlay-panel-header">
                <h3>Settings</h3>
                <button class="overlay-close-btn" data-close-panel="settingsPanel" type="button">&times;</button>
            </div>
            <div class="overlay-panel-body settings-body">
                <section class="settings-section">
                    <h4>Account</h4>
                    <p>Manage your student profile details through the registrar. This section is presentation-only.</p>
                </section>
                <section class="settings-section">
                    <h4>Notifications</h4>
                    <div class="settings-row">
                        <span>Email updates</span>
                        <label class="switch">
                            <input type="checkbox" checked disabled>
                            <span class="slider"></span>
                        </label>
                    </div>
                    <div class="settings-row">
                        <span>Dashboard alerts</span>
                        <label class="switch">
                            <input type="checkbox" checked disabled>
                            <span class="slider"></span>
                        </label>
                    </div>
                </section>
                <section class="settings-section">
                    <h4>Theme</h4>
                    <p>Use the toggle in the header to switch between light and dark mode.</p>
                </section>
            </div>
        </div>
    </div>
    
    <!-- Profile Panel (DB-backed, same as student_dashboard / submit_request) -->
    <div class="overlay-panel" id="profilePanel" aria-hidden="true">
        <div class="overlay-panel-inner">
            <div class="overlay-panel-header">
                <h3>My Profile</h3>
                <button class="overlay-close-btn" data-close-panel="profilePanel" type="button">&times;</button>
            </div>
            <div class="overlay-panel-body profile-body">
                <div class="profile-panel-header">
                    <div class="profile-panel-avatar">
                        <span><?php echo htmlspecialchars(strtoupper(substr($user_name, 0, 1)), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <div class="profile-panel-info">
                        <div class="profile-panel-name"><?php echo htmlspecialchars($user_name); ?></div>
                        <div class="profile-panel-role">Student</div>
                        <div class="profile-panel-tag">MediClear ID: <?php echo htmlspecialchars($_SESSION['user_id']); ?></div>
                    </div>
                </div>
                <?php if (!empty($profile_error)): ?>
                    <div class="error-message"><?php echo htmlspecialchars($profile_error); ?></div>
                <?php endif; ?>
                <div class="profile-panel-grid">
                    <div class="profile-panel-card">
                        <h4>Academic Details</h4>
                        <p>These details are saved to your account and can be edited below.</p>
                        <p><strong>Course:</strong> <?php echo htmlspecialchars($profile['course'] !== '' ? $profile['course'] : '—'); ?></p>
                        <p><strong>Year level:</strong> <?php echo htmlspecialchars($profile['year_level'] !== '' ? $profile['year_level'] : '—'); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($profile['email'] !== '' ? $profile['email'] : '—'); ?></p>
                        <p><strong>Date of birth:</strong> <?php echo htmlspecialchars($profile['date_of_birth'] !== '' ? $profile['date_of_birth'] : '—'); ?></p>
                    </div>
                    <div class="profile-panel-card">
                        <h4>Edit Profile</h4>
                        <form method="POST" action="">
                            <input type="hidden" name="profile_action" value="update_profile">
                            <div class="form-group">
                                <label for="profileFullName">Full Name *</label>
                                <input type="text" id="profileFullName" name="full_name" value="<?php echo htmlspecialchars($profile['full_name']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="profileEmail">Email</label>
                                <input type="email" id="profileEmail" name="email" value="<?php echo htmlspecialchars($profile['email']); ?>" placeholder="you@example.com">
                            </div>
                            <div class="form-group">
                                <label for="profileDateOfBirth">Date of Birth</label>
                                <input type="date" id="profileDateOfBirth" name="date_of_birth" value="<?php echo htmlspecialchars($profile['date_of_birth']); ?>">
                            </div>
                            <div class="form-group">
                                <label for="profileCourse">Course</label>
                                <input type="text" id="profileCourse" name="course" value="<?php echo htmlspecialchars($profile['course']); ?>" placeholder="e.g. BSIT">
                            </div>
                            <div class="form-group">
                                <label for="profileYearLevel">Year Level</label>
                                <input type="text" id="profileYearLevel" name="year_level" value="<?php echo htmlspecialchars($profile['year_level']); ?>" placeholder="e.g. 2nd Year">
                            </div>
                            <div class="form-group">
                                <label for="profilePassword">New Password (optional)</label>
                                <input type="password" id="profilePassword" name="password" placeholder="Leave blank to keep current password">
                            </div>
                            <div class="form-group">
                                <label for="profilePasswordConfirm">Confirm New Password</label>
                                <input type="password" id="profilePasswordConfirm" name="password_confirm" placeholder="Confirm new password">
                            </div>
                            <button type="submit" class="btn-submit">Save Changes</button>
                        </form>
                    </div>
                    <div class="profile-panel-card">
                        <h4>Medical History</h4>
                        <p><strong>Total requests:</strong> <?php echo (int)$total_count; ?></p>
                        <p><strong>Approved:</strong> <?php echo (int)$approved_count; ?> · <strong>Pending:</strong> <?php echo (int)$pending_count; ?> · <strong>Rejected:</strong> <?php echo (int)$rejected_count; ?></p>
                        <?php if (!empty($all_requests)): ?>
                            <p><strong>Latest request:</strong> <?php echo htmlspecialchars($all_requests[0]['id'] ?? '—'); ?> (<?php echo htmlspecialchars($all_requests[0]['status'] ?? '—'); ?>) · <?php echo htmlspecialchars($all_requests[0]['submitted_date'] ?? '—'); ?></p>
                        <?php else: ?>
                            <p><strong>Latest request:</strong> —</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- View Certificate Modal (loads certificate.php in iframe) -->
    <div class="certificate-modal-overlay" id="certificateModal">
        <div class="certificate-modal certificate-modal-with-iframe">
            <div class="certificate-modal-header">
                <h3>Medical Certificate</h3>
                <button type="button" class="certificate-modal-close" id="certificateModalClose">&times;</button>
            </div>
            <div class="certificate-modal-body">
                <iframe id="certificateModalIframe" title="Medical Certificate" src="about:blank"></iframe>
            </div>
            <div class="certificate-modal-footer">
                <button type="button" class="btn-secondary certificate-modal-print-btn" id="certificateModalPrint">Print / Save as PDF</button>
                <a class="btn-secondary certificate-modal-newtab-btn" id="certificateModalNewTab" href="#" target="_blank" rel="noopener">Open in new tab</a>
                <button type="button" class="btn-secondary certificate-modal-close-btn">Close</button>
            </div>
        </div>
    </div>
    
    <script src="js/script.js"></script>
</body>
</html>
