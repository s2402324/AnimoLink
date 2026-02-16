<?php
session_start();

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
app_dev_auto_login();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: index.php');
    exit;
}

$profile_error = null;
$profile = [
    'user_code' => (string)($_SESSION['user_id'] ?? ''),
    'full_name' => (string)($_SESSION['full_name'] ?? ''),
    'email' => '',
    'date_of_birth' => '',
    'course' => '',
    'year_level' => '',
];

$student_pk = (int)($_SESSION['user_pk'] ?? 0);
try {
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

    // Handle profile update (My Profile panel)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['profile_action'] ?? '') === 'update_profile') {
        $full_name = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $date_of_birth = trim((string)($_POST['date_of_birth'] ?? ''));
        $course = trim((string)($_POST['course'] ?? ''));
        $year_level = trim((string)($_POST['year_level'] ?? ''));
        $new_password = (string)($_POST['password'] ?? '');
        $new_password_confirm = (string)($_POST['password_confirm'] ?? '');

        if ($student_pk <= 0) {
            $profile_error = 'Account not found in database. Please register/sign in again.';
        } elseif ($full_name === '') {
            $profile_error = 'Full name is required.';
        } elseif ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $profile_error = 'Please enter a valid email address.';
        } elseif ($new_password !== '' && strlen($new_password) < 6) {
            $profile_error = 'New password must be at least 6 characters.';
        } elseif ($new_password !== '' && $new_password !== $new_password_confirm) {
            $profile_error = 'New password and confirmation do not match.';
        } else {
            $conn = db();
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
            header('Location: submit_request.php');
            exit;
        }
    }

    // Load profile details for display
    if ($student_pk > 0) {
        $conn = db();
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
    }
} catch (Throwable $e) {
    error_log((string)$e);
    $profile_error = $profile_error ?: 'Database error. Please try again.';
}

$user_name = isset($_SESSION['full_name']) && $_SESSION['full_name'] !== '' ? $_SESSION['full_name'] : ($_SESSION['user_id'] ?? 'Student');
$current_page = 'submit';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // If this POST was for profile update, it's already handled above.
    if ((string)($_POST['profile_action'] ?? '') === 'update_profile') {
        // fall-through: profile_error will display in panel
    }
    $illness = trim((string)($_POST['illness'] ?? ''));
    $symptoms = trim((string)($_POST['symptoms'] ?? ''));
    $illness_date = trim((string)($_POST['illness_date'] ?? ''));
    $contact_number = trim((string)($_POST['contact_number'] ?? ''));
    $additional_notes = trim((string)($_POST['additional_notes'] ?? ''));
    
    if ((string)($_POST['profile_action'] ?? '') === 'update_profile') {
        // Do not process request submission for profile form
    } elseif ($illness === '' || $symptoms === '' || $illness_date === '') {
        $error = "Please fill in all required fields";
    } else {
        try {
            if ($student_pk <= 0) {
                $error = "Account not found in database. Please register/sign in again.";
            } else {
                $conn = db();
                $submitted_date = date('Y-m-d');
                $status = 'Pending';

                $stmt = $conn->prepare(
                    'INSERT INTO medical_requests
                        (student_user_id, illness, symptoms, illness_date, contact_number, additional_notes, status, submitted_date)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param(
                    'isssssss',
                    $student_pk,
                    $illness,
                    $symptoms,
                    $illness_date,
                    $contact_number,
                    $additional_notes,
                    $status,
                    $submitted_date
                );
                $stmt->execute();

                $new_id = (int)$conn->insert_id;
                $req_code = 'REQ-' . str_pad((string)$new_id, 3, '0', STR_PAD_LEFT);

                // Supporting documents upload (optional)
                if (isset($_FILES['documents']) && is_array($_FILES['documents']['name'] ?? null)) {
                    $upload_root_rel = 'uploads/medical_requests';
                    $upload_dir_rel = $upload_root_rel . '/' . $new_id;
                    $upload_dir_abs = __DIR__ . '/' . str_replace('/', DIRECTORY_SEPARATOR, $upload_dir_rel);

                    if (!is_dir($upload_dir_abs)) {
                        mkdir($upload_dir_abs, 0775, true);
                    }

                    $allowed_ext = ['pdf', 'jpg', 'jpeg', 'png'];
                    $max_bytes = 5 * 1024 * 1024;

                    $names = (array)($_FILES['documents']['name'] ?? []);
                    $tmp_names = (array)($_FILES['documents']['tmp_name'] ?? []);
                    $errors = (array)($_FILES['documents']['error'] ?? []);
                    $sizes = (array)($_FILES['documents']['size'] ?? []);

                    $finfo = new finfo(FILEINFO_MIME_TYPE);

                    for ($i = 0; $i < count($names); $i++) {
                        $orig = (string)($names[$i] ?? '');
                        $tmp = (string)($tmp_names[$i] ?? '');
                        $err = (int)($errors[$i] ?? UPLOAD_ERR_NO_FILE);
                        $size = (int)($sizes[$i] ?? 0);

                        if ($err === UPLOAD_ERR_NO_FILE || $orig === '' || $tmp === '') {
                            continue;
                        }
                        if ($err !== UPLOAD_ERR_OK) {
                            continue;
                        }
                        if ($size <= 0 || $size > $max_bytes) {
                            continue;
                        }

                        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
                        if (!in_array($ext, $allowed_ext, true)) {
                            continue;
                        }

                        $mime = '';
                        try {
                            $mime = (string)$finfo->file($tmp);
                        } catch (Throwable $e) {
                            $mime = '';
                        }

                        // Store using random filename, keep original in DB
                        $safe_name = bin2hex(random_bytes(16)) . '.' . $ext;
                        $dest_abs = $upload_dir_abs . DIRECTORY_SEPARATOR . $safe_name;
                        if (!move_uploaded_file($tmp, $dest_abs)) {
                            continue;
                        }

                        $stored_path = $upload_dir_rel . '/' . $safe_name; // relative to project root

                        $stmtDoc = $conn->prepare(
                            'INSERT INTO medical_request_documents (request_id, original_name, stored_path, mime_type, size_bytes)
                             VALUES (?, ?, ?, ?, ?)'
                        );
                        $stmtDoc->bind_param('isssi', $new_id, $orig, $stored_path, $mime, $size);
                        $stmtDoc->execute();
                    }
                }

                $_SESSION['success_message'] = 'Medical request submitted successfully! Request ID: ' . $req_code;
                header('Location: student_dashboard.php');
                exit;
            }
        } catch (Throwable $e) {
            error_log((string)$e);
            $error = "Database error. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Medical Certification Request - MediClear</title>
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
                <div class="form-container content-card">
                    <div class="form-header">
                        <div class="form-icon">
                            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"></path>
                                <polyline points="14 2 14 8 20 8"></polyline>
                                <line x1="12" y1="18" x2="12" y2="12"></line>
                                <line x1="9" y1="15" x2="15" y2="15"></line>
                            </svg>
                        </div>
                        <h1>Medical Certification Request</h1>
                        <p>Please fill out the form below to request a medical certificate. All fields marked with * are required.</p>
                    </div>
                    
                    <?php if (isset($error)): ?>
                        <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    
                    <form method="POST" action="" class="medical-request-form" id="medicalRequestForm" enctype="multipart/form-data">
                        <div class="form-row">
                            <div class="form-group full-width">
                                <label for="illness">Type of Illness *</label>
                                <select id="illness" name="illness" required>
                                    <option value="">Select illness type</option>
                                    <option value="Fever and Flu">Fever and Flu</option>
                                    <option value="Headache and Dizziness">Headache and Dizziness</option>
                                    <option value="Stomach Ache">Stomach Ache</option>
                                    <option value="Cough and Cold">Cough and Cold</option>
                                    <option value="Body Pain">Body Pain</option>
                                    <option value="Allergic Reaction">Allergic Reaction</option>
                                    <option value="Injury">Injury</option>
                                    <option value="Other">Other</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group full-width">
                                <label for="symptoms">Symptoms *</label>
                                <textarea id="symptoms" name="symptoms" rows="4" placeholder="Describe your symptoms in detail (e.g., high fever, body aches, runny nose)" required></textarea>
                                <small class="form-help">Please provide detailed information about your symptoms</small>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group half-width">
                                <label for="illness_date">Date of Illness *</label>
                                <input type="date" id="illness_date" name="illness_date" max="<?php echo date('Y-m-d'); ?>" required>
                                <small class="form-help">When did you start feeling sick?</small>
                            </div>
                            <div class="form-group half-width">
                                <label for="contact_number">Contact Number (Optional)</label>
                                <input type="tel" id="contact_number" name="contact_number" placeholder="09XX-XXX-XXXX">
                                <small class="form-help">In case we need to reach you</small>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group full-width">
                                <label for="additional_notes">Additional Notes (Optional)</label>
                                <textarea id="additional_notes" name="additional_notes" rows="3" placeholder="Any additional information that might be helpful"></textarea>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group full-width">
                                <label for="documents">Supporting Documents (Optional)</label>
                                <div class="file-upload-area" id="fileUploadArea">
                                    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"></path>
                                        <polyline points="17 8 12 3 7 8"></polyline>
                                        <line x1="12" y1="3" x2="12" y2="15"></line>
                                    </svg>
                                    <p>Click to upload or drag and drop</p>
                                    <small>Medical prescriptions, lab results, etc. (PDF, JPG, PNG - Max 5MB)</small>
                                    <input type="file" id="documents" name="documents[]" multiple accept=".pdf,.jpg,.jpeg,.png" style="display: none;">
                                </div>
                                <div id="fileList" class="file-list"></div>
                            </div>
                        </div>
                        
                        <div class="form-row">
                            <div class="form-group full-width">
                                <div class="checkbox-group">
                                    <input type="checkbox" id="terms" name="terms" required>
                                    <label for="terms" class="checkbox-label">
                                        I certify that the information provided above is true and accurate to the best of my knowledge. I understand that providing false information may result in denial of medical certification. *
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-actions">
                            <a href="student_dashboard.php" class="btn-cancel">Cancel</a>
                            <button type="submit" class="btn-submit">Submit Request</button>
                        </div>
                    </form>
                    
                    <div class="info-box">
                        <div class="info-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <line x1="12" y1="16" x2="12" y2="12"></line>
                                <line x1="12" y1="8" x2="12.01" y2="8"></line>
                            </svg>
                        </div>
                        <div class="info-content">
                            <h3>Important Reminders:</h3>
                            <ul>
                                <li>Medical certificates are typically processed within 24-48 hours</li>
                                <li>You will receive a notification once your request is approved or requires additional information</li>
                                <li>Approved certificates are valid for 14 days from the date of approval</li>
                                <li>For urgent requests, please visit the clinic in person</li>
                            </ul>
                        </div>
                    </div>
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
                </div>
            </div>
        </div>
    </div>
    
    <script src="js/script.js"></script>
    <script>
        const fileUploadArea = document.getElementById('fileUploadArea');
        const fileInput = document.getElementById('documents');
        const fileList = document.getElementById('fileList');
        
        if (fileUploadArea) {
            fileUploadArea.addEventListener('click', () => fileInput.click());
            fileUploadArea.addEventListener('dragover', (e) => { e.preventDefault(); fileUploadArea.classList.add('dragover'); });
            fileUploadArea.addEventListener('dragleave', () => fileUploadArea.classList.remove('dragover'));
            fileUploadArea.addEventListener('drop', (e) => {
                e.preventDefault();
                fileUploadArea.classList.remove('dragover');
                fileInput.files = e.dataTransfer.files;
                displayFiles();
            });
        }
        
        fileInput.addEventListener('change', displayFiles);
        
        function displayFiles() {
            fileList.innerHTML = '';
            const files = fileInput.files;
            if (files.length > 0) {
                for (let i = 0; i < files.length; i++) {
                    const fileItem = document.createElement('div');
                    fileItem.className = 'file-item';
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'remove-file';
                    btn.textContent = '×';
                    btn.onclick = function() { removeFile(i); };
                    fileItem.innerHTML = '<span>' + files[i].name + '</span>';
                    fileItem.appendChild(btn);
                    fileList.appendChild(fileItem);
                }
            }
        }
        
        function removeFile(idx) {
            const dt = new DataTransfer();
            const files = fileInput.files;
            for (let i = 0; i < files.length; i++) {
                if (i !== idx) dt.items.add(files[i]);
            }
            fileInput.files = dt.files;
            displayFiles();
        }
    </script>
</body>
</html>
