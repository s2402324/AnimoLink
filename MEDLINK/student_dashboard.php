<?php
session_start();

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
app_dev_auto_login();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: index.php');
    exit;
}

// Load stats + recent requests from database
$total_requests = 0;
$pending_requests = 0;
$approved_requests = 0;
$rejected_requests = 0;
$recent_requests = [];
$profile_error = null;
$profile = [
    'user_code' => (string)($_SESSION['user_id'] ?? ''),
    'full_name' => (string)($_SESSION['full_name'] ?? ''),
    'email' => '',
    'date_of_birth' => '',
    'course' => '',
    'year_level' => '',
];
$last_approved = null;
$notifications = [];

try {
    $student_pk = (int)($_SESSION['user_pk'] ?? 0);
    if ($student_pk <= 0) {
        $user_code = (string)($_SESSION['user_id'] ?? '');
        if ($user_code !== '') {
            $conn = db();
            $stmt = $conn->prepare('SELECT id, full_name FROM users WHERE user_code = ? AND role = "student" LIMIT 1');
            $stmt->bind_param('s', $user_code);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $student_pk = (int)($row['id'] ?? 0);
            if ($student_pk > 0) {
                $_SESSION['user_pk'] = $student_pk;
                if (empty($_SESSION['full_name']) && !empty($row['full_name'])) {
                    $_SESSION['full_name'] = (string)$row['full_name'];
                    $profile['full_name'] = (string)$row['full_name'];
                }
            }
        }
    }

    if ($student_pk > 0) {
        $conn = db();

        // Profile update (My Profile -> Edit Profile)
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
                header('Location: student_dashboard.php');
                exit;
            }
        }

        // Load profile details
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
                SUM(status = "Pending") AS pending_count,
                SUM(status = "Approved") AS approved_count,
                SUM(status = "Rejected") AS rejected_count
             FROM medical_requests
             WHERE student_user_id = ?'
        );
        $stmt->bind_param('i', $student_pk);
        $stmt->execute();
        $counts = $stmt->get_result()->fetch_assoc() ?: [];

        $total_requests = (int)($counts['total_count'] ?? 0);
        $pending_requests = (int)($counts['pending_count'] ?? 0);
        $approved_requests = (int)($counts['approved_count'] ?? 0);
        $rejected_requests = (int)($counts['rejected_count'] ?? 0);

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
                valid_until
             FROM medical_requests
             WHERE student_user_id = ?
             ORDER BY id DESC
             LIMIT 5'
        );
        $stmt->bind_param('i', $student_pk);
        $stmt->execute();
        $recent_requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];

        // Last approved certificate summary (for Medical History)
        $stmt = $conn->prepare(
            'SELECT
                CONCAT("REQ-", LPAD(id, 3, "0")) AS request_id,
                illness,
                approved_date,
                valid_until
             FROM medical_requests
             WHERE student_user_id = ? AND status = "Approved"
             ORDER BY COALESCE(approved_date, submitted_date) DESC, id DESC
             LIMIT 1'
        );
        $stmt->bind_param('i', $student_pk);
        $stmt->execute();
        $last_approved = $stmt->get_result()->fetch_assoc() ?: null;

        // Notifications (latest 10)
        $stmt = $conn->prepare(
            'SELECT id, title, message, is_read, created_at
             FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT 10'
        );
        $stmt->bind_param('i', $student_pk);
        $stmt->execute();
        $notifications = $stmt->get_result()->fetch_all(MYSQLI_ASSOC) ?: [];
    }
} catch (Throwable $e) {
    error_log((string)$e);
    // Keep zero counts + empty list on DB errors
}

$user_name = isset($_SESSION['full_name']) && $_SESSION['full_name'] !== '' ? $_SESSION['full_name'] : ($_SESSION['user_id'] ?? 'Student');
$current_page = 'dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - MediClear</title>
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
                
                <div class="dashboard-shell">
                    <div class="dashboard-main">
                        <div class="welcome-banner">
                            <div class="welcome-left">
                                <div class="welcome-label">Student Dashboard</div>
                                <h1 class="welcome-title">Good Day, <?php echo htmlspecialchars($user_name); ?>!</h1>
                                <p class="welcome-subtitle">Welcome to your medical dashboard. Review your requests and stay on top of your health requirements.</p>
                                <div class="welcome-meta">
                                    <span class="welcome-pill">
                                        <span class="dot dot-online"></span>
                                        Active student · Medical clearance portal
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
                        
                        <div class="stats-and-actions">
                            <div class="stats-grid">
                                <div class="stat-card stat-total">
                                    <div class="stat-header">
                                        <span class="stat-icon stat-icon-total">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <path d="M3 12h4l3 8 4-16 3 8h4"></path>
                                            </svg>
                                        </span>
                                        <span class="stat-label">Total Requests</span>
                                    </div>
                                    <div class="stat-number"><?php echo $total_requests; ?></div>
                                    <div class="stat-caption">All medical certifications you've submitted</div>
                                </div>
                                <div class="stat-card stat-pending">
                                    <div class="stat-header">
                                        <span class="stat-icon stat-icon-pending">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                                <circle cx="12" cy="12" r="10"></circle>
                                                <polyline points="12 6 12 12 16 14"></polyline>
                                            </svg>
                                        </span>
                                        <span class="stat-label">Pending</span>
                                    </div>
                                    <div class="stat-number"><?php echo $pending_requests; ?></div>
                                    <div class="stat-caption">Awaiting clinic review</div>
                                </div>
                                <div class="stat-card stat-approved">
                                    <div class="stat-header">
                                        <span class="stat-icon stat-icon-approved">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                                <polyline points="20 6 9 17 4 12"></polyline>
                                            </svg>
                                        </span>
                                        <span class="stat-label">Approved</span>
                                    </div>
                                    <div class="stat-number"><?php echo $approved_requests; ?></div>
                                    <div class="stat-caption">Certificates ready to download</div>
                                </div>
                                <div class="stat-card stat-rejected">
                                    <div class="stat-header">
                                        <span class="stat-icon stat-icon-rejected">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                                <line x1="18" y1="6" x2="6" y2="18"></line>
                                                <line x1="6" y1="6" x2="18" y2="18"></line>
                                            </svg>
                                        </span>
                                        <span class="stat-label">Rejected</span>
                                    </div>
                                    <div class="stat-number"><?php echo $rejected_requests; ?></div>
                                    <div class="stat-caption">Requests needing your attention</div>
                                </div>
                            </div>
                            
                            <div class="action-buttons">
                                <a href="submit_request.php" class="btn-primary">
                                    <span class="btn-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M12 5v14"></path>
                                            <path d="M5 12h14"></path>
                                        </svg>
                                    </span>
                                    Submit Medical Request
                                </a>
                                <a href="view_all_requests.php" class="btn-secondary">
                                    <span class="btn-icon">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                            <path d="M3 4h18v4H3z"></path>
                                            <path d="M3 12h18v8H3z"></path>
                                        </svg>
                                    </span>
                                    View All Requests
                                </a>
                            </div>
                        </div>
                        
                        <div class="requests-section">
                            <h2 class="section-title">Recent Medical Requests</h2>
                            
                            <div class="requests-list">
                                <?php foreach ($recent_requests as $request): ?>
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
                                            <span class="meta-chip">ID: <?php echo htmlspecialchars($request['id']); ?></span>
                                            <span class="meta-label">Submitted: <?php echo htmlspecialchars($request['submitted_date']); ?></span>
                                            <?php if (isset($request['approved_date'])): ?>
                                                <span class="meta-label">Approved: <?php echo htmlspecialchars($request['approved_date']); ?></span>
                                                <span class="meta-label">Valid until: <?php echo htmlspecialchars($request['valid_until']); ?></span>
                                            <?php endif; ?>
                                            <?php if (isset($request['rejected_date'])): ?>
                                                <span class="meta-label">Rejected: <?php echo htmlspecialchars($request['rejected_date']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="request-actions">
                                        <span class="status-badge badge-<?php echo htmlspecialchars(strtolower($request['status']), ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(strtoupper($request['status']), ENT_QUOTES, 'UTF-8'); ?></span>
                                        <?php if ($request['status'] === 'Approved'): ?>
                                            <a class="btn-view-cert status-approved" href="certificate.php?request_id=<?php echo (int)($request['request_pk'] ?? 0); ?>" target="_blank" rel="noopener" data-request-id="<?php echo (int)($request['request_pk'] ?? 0); ?>">View Certificate</a>
                                        <?php else: ?>
                                            <button class="btn-view-cert status-<?php echo htmlspecialchars(strtolower($request['status']), ENT_QUOTES, 'UTF-8'); ?>" type="button" disabled>View Certificate</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <aside class="dashboard-aside">
                        <div class="profile-summary-card">
                            <div class="profile-summary-header">
                                <span class="profile-summary-label">My Profile</span>
                                <button class="profile-summary-link" id="openProfilePanel" type="button">View details</button>
                            </div>
                            <div class="profile-summary-body">
                                <div class="summary-avatar">
                                    <span class="summary-avatar-text"><?php echo htmlspecialchars(strtoupper(substr($user_name, 0, 1)), ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <div class="summary-info">
                                    <div class="summary-name"><?php echo htmlspecialchars($user_name); ?></div>
                                    <div class="summary-role">Student</div>
                                    <div class="summary-meta">
                                        <?php
                                            $course = trim((string)($profile['course'] ?? ''));
                                            $year = trim((string)($profile['year_level'] ?? ''));
                                            if ($course === '' && $year === '') {
                                                echo '—';
                                            } elseif ($course !== '' && $year !== '') {
                                                echo htmlspecialchars($course . ' · ' . $year, ENT_QUOTES, 'UTF-8');
                                            } else {
                                                echo htmlspecialchars($course !== '' ? $course : $year, ENT_QUOTES, 'UTF-8');
                                            }
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="panel-card">
                            <div class="panel-card-header">
                                <span class="panel-title">Plan Status</span>
                            </div>
                            <div class="panel-card-body">
                                <p class="panel-text">Stay updated with your medical requirements, approvals, and upcoming expiries.</p>
                                <ul class="panel-list">
                                    <li>Track approved certificates</li>
                                    <li>Monitor pending requests</li>
                                    <li>Review rejected submissions</li>
                                </ul>
                            </div>
                        </div>
                    </aside>
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
                <?php if (empty($notifications)): ?>
                    <p class="overlay-empty-text">You’re all caught up. New updates from the clinic will appear here.</p>
                <?php else: ?>
                    <?php
                    $unread_count = count(array_filter($notifications, fn($n) => (int)($n['is_read'] ?? 0) === 0));
                    ?>
                    <?php if ($unread_count > 0): ?>
                        <p class="notification-actions">
                            <a href="mark_notification_read.php?all=1" class="auth-link">Mark all as read</a>
                        </p>
                    <?php endif; ?>
                    <div class="notifications-list">
                        <?php foreach ($notifications as $n): ?>
                            <?php $is_read = (int)($n['is_read'] ?? 0) === 1; ?>
                            <div class="notification-item <?php echo $is_read ? 'read' : 'unread'; ?>">
                                <div class="notification-title"><?php echo htmlspecialchars((string)$n['title'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="notification-message"><?php echo htmlspecialchars((string)$n['message'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="notification-meta">
                                    <?php echo htmlspecialchars((string)$n['created_at'], ENT_QUOTES, 'UTF-8'); ?>
                                    <?php if (!$is_read): ?>
                                        · <a href="mark_notification_read.php?id=<?php echo (int)$n['id']; ?>" class="notification-mark-read">Mark as read</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
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
    
    <!-- Profile Panel -->
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
                        <p><strong>Total requests:</strong> <?php echo (int)$total_requests; ?></p>
                        <p><strong>Approved:</strong> <?php echo (int)$approved_requests; ?> · <strong>Pending:</strong> <?php echo (int)$pending_requests; ?> · <strong>Rejected:</strong> <?php echo (int)$rejected_requests; ?></p>
                        <?php if (!empty($recent_requests)): ?>
                            <p><strong>Latest request:</strong> <?php echo htmlspecialchars($recent_requests[0]['id']); ?> (<?php echo htmlspecialchars($recent_requests[0]['status']); ?>) · <?php echo htmlspecialchars($recent_requests[0]['submitted_date']); ?></p>
                        <?php else: ?>
                            <p><strong>Latest request:</strong> —</p>
                        <?php endif; ?>
                        <?php if (!empty($last_approved)): ?>
                            <p><strong>Last approved:</strong> <?php echo htmlspecialchars($last_approved['request_id']); ?> · Valid until <?php echo htmlspecialchars($last_approved['valid_until'] ?? '—'); ?></p>
                        <?php else: ?>
                            <p><strong>Last approved:</strong> —</p>
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
