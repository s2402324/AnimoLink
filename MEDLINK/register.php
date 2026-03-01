<?php
session_start();

require_once __DIR__ . '/config/database.php';

// If already logged in, go to dashboard
if (isset($_SESSION['user_id'], $_SESSION['role'])) {
    if ($_SESSION['role'] === 'student') {
        header('Location: student_dashboard.php');
        exit;
    }
    if ($_SESSION['role'] === 'clinic') {
        header('Location: clinic_dashboard.php');
        exit;
    }
}

$error = null;
$allowed_courses = [
    'BSIT', 'BSCS', 'BSEMC',
    'BSA', 'BSAIS', 'BSBA-BEco', 'BSBA-MM', 'BSBA-OM', 'BSENT', 'BSHM', 'BSTM', 'BSAB',
    'BACOMM', 'BAIDS', 'BAPoSci', 'BSPsych', 'BSBio',
    'BEEd', 'BSPED', 'BPED', 'BSEd-ENG', 'BSEd-MATH', 'BSEd-SCI', 'BSEd-SS',
    'BSChE', 'BSCpE', 'BSEE', 'BSECE', 'BSMatE',
    'BSN',
    'MD',
    'JD',
];
// Year 5 is only allowed for 5-year courses (as requested)
$five_year_courses = ['BSChE', 'BSCpE', 'BSEE', 'BSECE', 'BSMatE', 'MD', 'JD'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = $_POST['role'] ?? 'student';
    $normalized_role = ($role === 'staff') ? 'clinic' : $role;

    $user_code = trim((string)($_POST['student_id'] ?? ''));
    $full_name = trim((string)($_POST['full_name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $date_of_birth = trim((string)($_POST['date_of_birth'] ?? ''));
    $course = trim((string)($_POST['course'] ?? '')); // course code (e.g. BSIT)
    $year = trim((string)($_POST['year'] ?? '')); // numeric year level from dropdown (e.g. 2)
    $section = trim((string)($_POST['section'] ?? '')); // section letter (A-G)
    $year_level = trim((string)($_POST['year_level'] ?? '')); // derived year+section (e.g. 2D)
    $password = (string)($_POST['password'] ?? '');
    $password_confirm = (string)($_POST['password_confirm'] ?? '');

    if (!in_array($normalized_role, ['student', 'clinic'], true)) {
        $error = 'Invalid role selected';
    } elseif ($user_code === '' || $full_name === '' || $password === '' || $password_confirm === '') {
        $error = 'Please fill in all required fields';
    } elseif ($email === '') {
        $error = 'Email is required';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address';
    } elseif (!preg_match('/@usls\.edu\.ph$/i', $email)) {
        $error = 'Please use your @usls.edu.ph email address';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long and include at least one uppercase letter, one number, and one special character (!@#$%^&*).';
    } elseif (!preg_match('/^(?=.*[A-Z])(?=.*\d)(?=.*[!@#$%^&*]).{8,}$/', $password)) {
        $error = 'Password must be at least 8 characters long and include at least one uppercase letter, one number, and one special character (!@#$%^&*).';
    } elseif ($password !== $password_confirm) {
        $error = 'Passwords do not match';
    }

    if ($error === null && $normalized_role === 'student' && $date_of_birth === '') {
        $error = 'Please enter your date of birth (required for password recovery).';
    }

    if ($error === null && $normalized_role === 'student') {
        if ($course === '' || $year === '' || $section === '') {
            $error = 'Please select your course, year level, and section';
        } elseif (!in_array($course, $allowed_courses, true)) {
            $error = 'Invalid course selected';
        } elseif (!preg_match('/^[1-5]$/', $year)) {
            $error = 'Invalid year level selected';
        } elseif (!preg_match('/^[A-G]$/', $section)) {
            $error = 'Invalid section selected';
        } else {
            $year_level = $year . $section; // store 2D, display as COURSE-YEARLEVEL (e.g. BSIT-2D)
            if ($year === '5' && !in_array($course, $five_year_courses, true)) {
                $error = 'Year 5 is only available for 5-year courses';
            }
        }
    }

    if ($error === null && $normalized_role !== 'student') {
        // Clinic/staff accounts don't require academic details
        $course = '';
        $year_level = '';
    }

    if ($error === null) {
        try {
            $conn = db();

            // Ensure unique ID
            $stmt = $conn->prepare('SELECT id FROM users WHERE user_code = ? LIMIT 1');
            $stmt->bind_param('s', $user_code);
            $stmt->execute();
            $exists = $stmt->get_result()->fetch_assoc();

            if ($exists) {
                $error = 'This ID is already registered. Please sign in.';
            } else {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                $date_of_birth_value = $date_of_birth !== '' ? $date_of_birth : null;
                $stmt = $conn->prepare(
                    'INSERT INTO users (user_code, role, full_name, email, date_of_birth, course, year_level, password_hash)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->bind_param(
                    'ssssssss',
                    $user_code,
                    $normalized_role,
                    $full_name,
                    $email,
                    $date_of_birth_value,
                    $course,
                    $year_level,
                    $password_hash
                );
                $stmt->execute();

                session_regenerate_id(true);
                $_SESSION['user_id'] = $user_code; // keep existing app behavior (string ID)
                $_SESSION['user_pk'] = (int)$conn->insert_id; // numeric PK for DB queries later
                $_SESSION['role'] = $normalized_role;
                $_SESSION['full_name'] = $full_name;

                if ($normalized_role === 'student') {
                    header('Location: student_dashboard.php');
                    exit;
                }
                header('Location: clinic_dashboard.php');
                exit;
            }
        } catch (Throwable $e) {
            $error = 'Database error. Make sure the database/tables exist, then try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MediClear - Register</title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?php echo urlencode((string)@filemtime(__DIR__ . '/assets/css/style.css')); ?>">
    <style>
        /* Hard fallback so year/section and submit are always visible on this page */
        #registerForm #year,
        #registerForm #section,
        #registerForm .btn-primary {
            display: block !important;
            visibility: visible !important;
            opacity: 1 !important;
        }
        #registerForm .form-group select {
            min-height: 52px;
            background: var(--input-mint);
        }

        /* Inline field errors */
        #registerForm .form-group .field-error-message {
            margin-top: 4px;
            font-size: 0.78rem;
            color: #ffb3b3;
        }
        #registerForm .form-group input.has-error,
        #registerForm .form-group select.has-error,
        #registerForm .email-input-wrapper.has-error {
            border-color: #ff5252;
            box-shadow: 0 0 0 2px rgba(244, 67, 54, 0.7);
        }

        /* Email local-part input with fixed domain */
        #registerForm .email-input-wrapper {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 0 12px;
            height: 52px;
            border-radius: 12px;
            background: #ffffff;
            border: 1px solid rgba(0, 0, 0, 0.12);
        }
        #registerForm .email-input-wrapper input[type="text"] {
            flex: 1;
            border: none;
            outline: none;
            background: transparent;
            color: inherit;
            padding: 0;
            margin: 0;
        }
        #registerForm .email-domain {
            padding-left: 10px;
            margin-left: 6px;
            border-left: 1px solid rgba(0, 0, 0, 0.12);
            background: transparent;
            color: rgba(0, 0, 0, 0.7);
            font-size: 0.85rem;
            white-space: nowrap;
        }

        /* Password strength indicator */
        .password-strength-wrapper {
            margin-top: 6px;
        }
        .password-strength-meter {
            position: relative;
            width: 100%;
            height: 6px;
            border-radius: 999px;
            background: rgba(0, 0, 0, 0.18);
            overflow: hidden;
            margin-bottom: 6px;
        }
        .password-strength-meter-fill {
            height: 100%;
            width: 0;
            background: #e53935;
            transition: width 0.2s ease, background-color 0.2s ease;
        }
        .password-strength-label {
            display: block;
            font-size: 0.78rem;
            color: #ffffff;
        }
        .password-rules {
            list-style: none;
            padding: 4px 0 0;
            margin: 0;
            font-size: 0.78rem;
            color: #ffffff;
        }
        .password-rules li {
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .password-rules li::before {
            content: '•';
            font-size: 0.9em;
        }
        .password-rules li.met {
            color: #C8E6C9;
        }
        .password-rules li.met::before {
            content: '✓';
        }

        /* Confirm password pop-out */
        .confirm-password-group {
            max-height: 0;
            opacity: 0;
            overflow: hidden;
            transform: translateY(-4px);
            transition: max-height 0.25s ease, opacity 0.25s ease, transform 0.25s ease;
        }
        .confirm-password-group.visible {
            max-height: 120px;
            opacity: 1;
            transform: translateY(0);
        }
    </style>
</head>
<body class="login-page allow-scroll">
    <div class="login-background" style="background-image: url('pics/BGFR.jpg');"></div>

    <div class="login-container">
        <div class="login-card">
            <div class="logo-container">
                <img src="pics/LOGOLASALLE.png" alt="Logo" class="login-logo">
            </div>

            <div class="login-header">
                <h1>Create Account</h1>
                <p>Register to access the medical certification system</p>
            </div>

            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" action="" id="registerForm" novalidate>
                <div class="role-toggle">
                    <input type="radio" name="role" id="studentRole" value="student" <?php echo (($_POST['role'] ?? 'student') !== 'clinic') ? 'checked' : ''; ?>>
                    <input type="radio" name="role" id="clinicRole" value="clinic" <?php echo (($_POST['role'] ?? '') === 'clinic') ? 'checked' : ''; ?>>
                    <div class="toggle-container">
                        <label for="studentRole" class="toggle-option" id="studentLabel">Student</label>
                        <label for="clinicRole" class="toggle-option" id="clinicLabel">Staff ID</label>
                        <div class="toggle-slider"></div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="student_id" id="idLabel">Student ID *</label>
                    <input type="text" id="student_id" name="student_id" placeholder="Enter your ID" required value="<?php echo htmlspecialchars((string)($_POST['student_id'] ?? '')); ?>">
                </div>

                <div class="form-group">
                    <label for="full_name">Full Name *</label>
                    <input type="text" id="full_name" name="full_name" placeholder="Enter your full name" required value="<?php echo htmlspecialchars((string)($_POST['full_name'] ?? '')); ?>">
                </div>

                <div class="form-group">
                    <label for="email_local">Email *</label>
                    <div class="email-input-wrapper">
                        <input
                            type="text"
                            id="email_local"
                            placeholder="Enter your email"
                            required
                            inputmode="email"
                            autocomplete="off"
                            value="<?php
                                $postedEmail = (string)($_POST['email'] ?? '');
                                $localPart = preg_replace('/@usls\.edu\.ph$/i', '', $postedEmail);
                                echo htmlspecialchars($localPart, ENT_QUOTES, 'UTF-8');
                            ?>"
                        >
                        <span class="email-domain">@usls.edu.ph</span>
                    </div>
                    <input
                        type="hidden"
                        id="email"
                        name="email"
                        value="<?php echo htmlspecialchars((string)($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="date_of_birth">Date of Birth <span id="dobRequiredStar">*</span></label>
                    <input type="date" id="date_of_birth" name="date_of_birth" value="<?php echo htmlspecialchars((string)($_POST['date_of_birth'] ?? '')); ?>">
                    <small class="form-hint">Required for students (used for password recovery)</small>
                </div>

                <div id="academicFields">
                    <div class="form-group">
                        <label for="course">Course *</label>
                        <select id="course" name="course" required>
                            <option value="">Select your course</option>
                            <optgroup label="College of Computing Studies">
                                <option value="BSIT" <?php echo (($_POST['course'] ?? '') === 'BSIT') ? 'selected' : ''; ?>>BSIT – Bachelor of Science in Information Technology</option>
                                <option value="BSCS" <?php echo (($_POST['course'] ?? '') === 'BSCS') ? 'selected' : ''; ?>>BSCS – Bachelor of Science in Computer Science</option>
                                <option value="BSEMC" <?php echo (($_POST['course'] ?? '') === 'BSEMC') ? 'selected' : ''; ?>>BSEMC – Bachelor of Science in Entertainment and Multimedia Computing</option>
                            </optgroup>
                            <optgroup label="Yu An Log College of Business & Accountancy">
                                <option value="BSA" <?php echo (($_POST['course'] ?? '') === 'BSA') ? 'selected' : ''; ?>>BSA – Bachelor of Science in Accountancy</option>
                                <option value="BSAIS" <?php echo (($_POST['course'] ?? '') === 'BSAIS') ? 'selected' : ''; ?>>BSAIS – Bachelor of Science in Accounting Information System</option>
                                <option value="BSBA-BEco" <?php echo (($_POST['course'] ?? '') === 'BSBA-BEco') ? 'selected' : ''; ?>>BSBA-BEco – BSBA major in Business Economics</option>
                                <option value="BSBA-MM" <?php echo (($_POST['course'] ?? '') === 'BSBA-MM') ? 'selected' : ''; ?>>BSBA-MM – BSBA major in Marketing Management</option>
                                <option value="BSBA-OM" <?php echo (($_POST['course'] ?? '') === 'BSBA-OM') ? 'selected' : ''; ?>>BSBA-OM – BSBA major in Operations Management</option>
                                <option value="BSENT" <?php echo (($_POST['course'] ?? '') === 'BSENT') ? 'selected' : ''; ?>>BSENT – Bachelor of Science in Entrepreneurship</option>
                                <option value="BSHM" <?php echo (($_POST['course'] ?? '') === 'BSHM') ? 'selected' : ''; ?>>BSHM – Bachelor of Science in Hospitality Management</option>
                                <option value="BSTM" <?php echo (($_POST['course'] ?? '') === 'BSTM') ? 'selected' : ''; ?>>BSTM – Bachelor of Science in Tourism Management</option>
                                <option value="BSAB" <?php echo (($_POST['course'] ?? '') === 'BSAB') ? 'selected' : ''; ?>>BSAB – Bachelor of Science in Agribusiness</option>
                            </optgroup>
                            <optgroup label="College of Arts & Sciences">
                                <option value="BACOMM" <?php echo (($_POST['course'] ?? '') === 'BACOMM') ? 'selected' : ''; ?>>BACOMM – Bachelor of Arts in Communication</option>
                                <option value="BAIDS" <?php echo (($_POST['course'] ?? '') === 'BAIDS') ? 'selected' : ''; ?>>BAIDS – Bachelor of Arts in Interdisciplinary Studies</option>
                                <option value="BAPoSci" <?php echo (($_POST['course'] ?? '') === 'BAPoSci') ? 'selected' : ''; ?>>BAPoSci – Bachelor of Arts in Political Science</option>
                                <option value="BSPsych" <?php echo (($_POST['course'] ?? '') === 'BSPsych') ? 'selected' : ''; ?>>BSPsych – Bachelor of Science in Psychology</option>
                                <option value="BSBio" <?php echo (($_POST['course'] ?? '') === 'BSBio') ? 'selected' : ''; ?>>BSBio – Bachelor of Science in Biology</option>
                            </optgroup>
                            <optgroup label="College of Education">
                                <option value="BEEd" <?php echo (($_POST['course'] ?? '') === 'BEEd') ? 'selected' : ''; ?>>BEEd – Bachelor of Elementary Education</option>
                                <option value="BSPED" <?php echo (($_POST['course'] ?? '') === 'BSPED') ? 'selected' : ''; ?>>BSPED – Bachelor of Special Needs Education</option>
                                <option value="BPED" <?php echo (($_POST['course'] ?? '') === 'BPED') ? 'selected' : ''; ?>>BPED – Bachelor of Physical Education</option>
                                <option value="BSEd-ENG" <?php echo (($_POST['course'] ?? '') === 'BSEd-ENG') ? 'selected' : ''; ?>>BSEd-ENG – BSEd major in English</option>
                                <option value="BSEd-MATH" <?php echo (($_POST['course'] ?? '') === 'BSEd-MATH') ? 'selected' : ''; ?>>BSEd-MATH – BSEd major in Mathematics</option>
                                <option value="BSEd-SCI" <?php echo (($_POST['course'] ?? '') === 'BSEd-SCI') ? 'selected' : ''; ?>>BSEd-SCI – BSEd major in Science</option>
                                <option value="BSEd-SS" <?php echo (($_POST['course'] ?? '') === 'BSEd-SS') ? 'selected' : ''; ?>>BSEd-SS – BSEd major in Social Studies</option>
                            </optgroup>
                            <optgroup label="College of Engineering">
                                <option value="BSChE" <?php echo (($_POST['course'] ?? '') === 'BSChE') ? 'selected' : ''; ?>>BSChE – Bachelor of Science in Chemical Engineering</option>
                                <option value="BSCpE" <?php echo (($_POST['course'] ?? '') === 'BSCpE') ? 'selected' : ''; ?>>BSCpE – Bachelor of Science in Computer Engineering</option>
                                <option value="BSEE" <?php echo (($_POST['course'] ?? '') === 'BSEE') ? 'selected' : ''; ?>>BSEE – Bachelor of Science in Electrical Engineering</option>
                                <option value="BSECE" <?php echo (($_POST['course'] ?? '') === 'BSECE') ? 'selected' : ''; ?>>BSECE – Bachelor of Science in Electronics Engineering</option>
                                <option value="BSMatE" <?php echo (($_POST['course'] ?? '') === 'BSMatE') ? 'selected' : ''; ?>>BSMatE – Bachelor of Science in Materials Engineering</option>
                            </optgroup>
                            <optgroup label="College of Nursing">
                                <option value="BSN" <?php echo (($_POST['course'] ?? '') === 'BSN') ? 'selected' : ''; ?>>BSN – Bachelor of Science in Nursing</option>
                            </optgroup>
                            <optgroup label="College of Medicine">
                                <option value="MD" <?php echo (($_POST['course'] ?? '') === 'MD') ? 'selected' : ''; ?>>MD – Doctor of Medicine</option>
                            </optgroup>
                            <optgroup label="College of Law">
                                <option value="JD" <?php echo (($_POST['course'] ?? '') === 'JD') ? 'selected' : ''; ?>>JD – Juris Doctor</option>
                            </optgroup>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="year">Year Level *</label>
                        <select
                            id="year"
                            name="year"
                            required
                            data-five-year-courses="<?php echo htmlspecialchars(implode(',', $five_year_courses), ENT_QUOTES, 'UTF-8'); ?>"
                            data-selected-year="<?php echo htmlspecialchars((string)($_POST['year'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                        >
                            <option value="">Select year level</option>
                            <?php for ($y = 1; $y <= 5; $y++): ?>
                                <option value="<?php echo $y; ?>" <?php echo (($_POST['year'] ?? '') == (string)$y) ? 'selected' : ''; ?>>
                                    <?php echo $y; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="section">Section *</label>
                        <select id="section" name="section" required>
                            <option value="">Select section</option>
                            <?php foreach (range('A', 'G') as $sec): ?>
                                <option value="<?php echo $sec; ?>" <?php echo (($_POST['section'] ?? '') === $sec) ? 'selected' : ''; ?>>
                                    <?php echo $sec; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="courseYearSectionPreview">Format</label>
                        <input type="text" id="courseYearSectionPreview" readonly placeholder="e.g. BSIT-2D">
                        <input type="hidden" id="year_level" name="year_level" value="<?php echo htmlspecialchars((string)($_POST['year_level'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password *</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Create a password"
                        required
                        autocomplete="new-password"
                    >
                </div>

                <div class="form-group confirm-password-group">
                    <label for="password_confirm">Confirm Password *</label>
                    <input
                        type="password"
                        id="password_confirm"
                        name="password_confirm"
                        placeholder="Confirm your password"
                        required
                        autocomplete="new-password"
                    >
                </div>

                <div class="password-strength-wrapper">
                    <div class="password-strength-meter" id="passwordStrengthMeter">
                        <div class="password-strength-meter-fill" id="passwordStrengthFill"></div>
                    </div>
                    <small class="password-strength-label" id="passwordStrengthLabel">
                        Password must be at least 8 characters and include at least one uppercase letter, one number, and one special character (!@#$%^&*).
                    </small>
                    <ul class="password-rules">
                        <li data-password-rule="length">Minimum 8 characters</li>
                        <li data-password-rule="uppercase">At least 1 uppercase letter</li>
                        <li data-password-rule="number">At least 1 number</li>
                        <li data-password-rule="special">At least 1 special character (!@#$%^&*)</li>
                    </ul>
                </div>

                <button type="submit" class="btn-primary">Create Account</button>

                <div class="auth-footer">
                    <span>Already have an account?</span>
                    <a class="auth-link" href="index.php">Sign in</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        (function () {
            const form = document.getElementById('registerForm');
            if (!form) return;

            const studentRole = document.getElementById('studentRole');
            const clinicRole = document.getElementById('clinicRole');
            const idLabel = document.getElementById('idLabel');
            const idInput = document.getElementById('student_id');

            const fullNameInput = document.getElementById('full_name');
            const emailLocalInput = document.getElementById('email_local');
            const emailHiddenInput = document.getElementById('email');
            const dateOfBirthInput = document.getElementById('date_of_birth');

            const academicFields = document.getElementById('academicFields');
            const courseSelect = document.getElementById('course');
            const yearSelect = document.getElementById('year');
            const sectionSelect = document.getElementById('section');
            const previewInput = document.getElementById('courseYearSectionPreview');
            const hiddenYearLevel = document.getElementById('year_level');

            const passwordInput = document.getElementById('password');
            const passwordConfirmInput = document.getElementById('password_confirm');
            const strengthMeter = document.getElementById('passwordStrengthMeter');
            const strengthFill = document.getElementById('passwordStrengthFill');
            const strengthLabel = document.getElementById('passwordStrengthLabel');
            const confirmGroup = document.querySelector('.confirm-password-group');

            const fiveYearCourses = (yearSelect?.dataset?.fiveYearCourses || '')
                .split(',')
                .map(function (v) { return v.trim(); })
                .filter(Boolean);

            function isStudent() {
                return !!(studentRole && studentRole.checked);
            }

            function clearFieldError(input) {
                if (!input) return;
                input.classList.remove('has-error');
                const group = input.closest('.form-group') || input.parentElement;
                if (!group) return;
                const wrapper = group.querySelector('.email-input-wrapper');
                if (wrapper) {
                    wrapper.classList.remove('has-error');
                }
                const existing = group.querySelector('.field-error-message');
                if (existing) existing.remove();
            }

            function setFieldError(input, message) {
                if (!input) return;
                clearFieldError(input);
                input.classList.add('has-error');
                const group = input.closest('.form-group') || input.parentElement;
                if (!group) return;
                const wrapper = group.querySelector('.email-input-wrapper');
                if (wrapper && input === emailLocalInput) {
                    wrapper.classList.add('has-error');
                }
                const div = document.createElement('div');
                div.className = 'field-error-message';
                div.textContent = message;
                group.appendChild(div);
            }

            function syncRoleUI() {
                if (isStudent()) {
                    if (idLabel) idLabel.textContent = 'Student ID *';
                    if (idInput) idInput.placeholder = 'Enter your ID';
                    if (academicFields) academicFields.style.display = 'block';
                    [courseSelect, yearSelect, sectionSelect].forEach(function (el) {
                        if (!el) return;
                        el.disabled = false;
                        el.required = true;
                    });
                } else {
                    if (idLabel) idLabel.textContent = 'Staff ID *';
                    if (idInput) idInput.placeholder = 'Enter your Staff ID';
                    if (academicFields) academicFields.style.display = 'none';
                    [courseSelect, yearSelect, sectionSelect].forEach(function (el) {
                        if (!el) return;
                        el.disabled = true;
                        el.required = false;
                    });
                }
            }

            function rebuildYearOptionsIfNeeded() {
                if (!yearSelect) return;
                const selectedCourse = courseSelect ? courseSelect.value : '';
                const allowYear5 = fiveYearCourses.indexOf(selectedCourse) !== -1;
                const maxYear = allowYear5 ? 5 : 4;
                const current = yearSelect.value;

                // Only rebuild if options are missing or include invalid range.
                const existingOptions = Array.from(yearSelect.options).map(function (opt) { return opt.value; });
                const expectedLast = String(maxYear);
                const needsRebuild = existingOptions.indexOf(expectedLast) === -1 || existingOptions.indexOf('5') !== (allowYear5 ? existingOptions.indexOf('5') : -1);

                if (needsRebuild) {
                    yearSelect.innerHTML = '<option value="">Select year level</option>';
                    for (let y = 1; y <= maxYear; y++) {
                        const option = document.createElement('option');
                        option.value = String(y);
                        option.textContent = String(y);
                        yearSelect.appendChild(option);
                    }
                }

                if (current && Number(current) >= 1 && Number(current) <= maxYear) {
                    yearSelect.value = current;
                } else if (Number(current) > maxYear) {
                    yearSelect.value = '';
                }
            }

            function updatePreview() {
                const c = courseSelect ? courseSelect.value : '';
                const y = yearSelect ? yearSelect.value : '';
                const s = sectionSelect ? sectionSelect.value : '';
                const ys = (y && s) ? (y + s) : '';
                if (hiddenYearLevel) hiddenYearLevel.value = ys;
                if (previewInput) previewInput.value = (c && ys) ? (c + '-' + ys) : '';
            }

            function evaluatePassword(pw) {
                const hasLength = pw.length >= 8;
                const hasUpper = /[A-Z]/.test(pw);
                const hasNumber = /\d/.test(pw);
                const hasSpecial = /[!@#$%^&*]/.test(pw);
                let score = 0;
                if (hasLength) score++;
                if (hasUpper) score++;
                if (hasNumber) score++;
                if (hasSpecial) score++;
                return { score: score, hasLength: hasLength, hasUpper: hasUpper, hasNumber: hasNumber, hasSpecial: hasSpecial };
            }

            function syncConfirmVisibility() {
                if (!confirmGroup || !passwordInput) return;
                const hasValue = (passwordInput.value || '').length > 0;
                if (hasValue) {
                    confirmGroup.classList.add('visible');
                } else {
                    confirmGroup.classList.remove('visible');
                    if (passwordConfirmInput) {
                        passwordConfirmInput.value = '';
                    }
                }
            }

            function updatePasswordStrength() {
                if (!passwordInput || !strengthMeter || !strengthFill || !strengthLabel) return;
                const value = passwordInput.value || '';
                const result = evaluatePassword(value);
                const score = result.score;
                const percent = (score / 4) * 100;

                strengthFill.style.width = percent + '%';

                let label = 'Very weak';
                let color = '#e53935';
                if (score === 2) {
                    label = 'Weak';
                    color = '#fb8c00';
                } else if (score === 3) {
                    label = 'Good';
                    color = '#fdd835';
                } else if (score === 4) {
                    label = 'Strong';
                    color = '#43a047';
                }
                strengthFill.style.backgroundColor = color;
                strengthLabel.textContent = value
                    ? ('Strength: ' + label)
                    : 'Password must be at least 8 characters and include at least one uppercase letter, one number, and one special character (!@#$%^&*).';

                document.querySelectorAll('.password-rules li[data-password-rule]').forEach(function (el) {
                    const rule = el.getAttribute('data-password-rule');
                    let ok = false;
                    if (rule === 'length') ok = result.hasLength;
                    else if (rule === 'uppercase') ok = result.hasUpper;
                    else if (rule === 'number') ok = result.hasNumber;
                    else if (rule === 'special') ok = result.hasSpecial;
                    if (ok) {
                        el.classList.add('met');
                    } else {
                        el.classList.remove('met');
                    }
                });
            }

            if (studentRole) studentRole.addEventListener('change', syncRoleUI);
            if (clinicRole) clinicRole.addEventListener('change', syncRoleUI);
            if (courseSelect) {
                courseSelect.addEventListener('change', function () {
                    rebuildYearOptionsIfNeeded();
                    updatePreview();
                });
            }
            if (yearSelect) yearSelect.addEventListener('change', updatePreview);
            if (sectionSelect) sectionSelect.addEventListener('change', updatePreview);

            if (idInput) {
                idInput.setAttribute('inputmode', 'numeric');
                idInput.addEventListener('input', function () {
                    const digitsOnly = (idInput.value || '').replace(/\D+/g, '');
                    if (idInput.value !== digitsOnly) {
                        idInput.value = digitsOnly;
                    }
                    clearFieldError(idInput);
                });
            }

            if (passwordInput) {
                passwordInput.addEventListener('input', function () {
                    updatePasswordStrength();
                    syncConfirmVisibility();
                    clearFieldError(passwordInput);
                });
                passwordInput.addEventListener('blur', updatePasswordStrength);
            }

            [idInput, fullNameInput, emailLocalInput, dateOfBirthInput, courseSelect, yearSelect, sectionSelect, passwordConfirmInput].forEach(function (input) {
                if (!input) return;
                input.addEventListener('input', function () {
                    clearFieldError(input);
                });
            });

            form.addEventListener('submit', function (e) {
                updatePreview();

                // Clear previous errors
                form.querySelectorAll('.field-error-message').forEach(function (el) { el.remove(); });
                form.querySelectorAll('.has-error').forEach(function (el) { el.classList.remove('has-error'); });

                let hasError = false;

                function requireField(input, message) {
                    if (!input) return;
                    const value = (input.value || '').trim();
                    if (!value) {
                        if (!hasError) input.focus();
                        hasError = true;
                        setFieldError(input, message);
                    }
                }

                // Basic required fields
                if (idInput) {
                    requireField(idInput, isStudent() ? 'Student ID required.' : 'Staff ID required.');
                }
                requireField(fullNameInput, 'Full name required.');
                requireField(emailLocalInput, 'Email required.');

                // Build full email from local part and validate
                if (emailLocalInput && emailHiddenInput) {
                    const local = (emailLocalInput.value || '').trim();
                    emailHiddenInput.value = local ? (local + '@usls.edu.ph') : '';

                    if (local) {
                        const emailVal = emailHiddenInput.value;
                        const basicPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                        if (!basicPattern.test(emailVal)) {
                            if (!hasError) emailLocalInput.focus();
                            hasError = true;
                            setFieldError(emailLocalInput, 'Please enter a valid email address.');
                        } else if (!/@usls\.edu\.ph$/i.test(emailVal)) {
                            if (!hasError) emailLocalInput.focus();
                            hasError = true;
                            setFieldError(emailLocalInput, 'Please use your @usls.edu.ph email address.');
                        }
                    }
                }

                if (isStudent()) {
                    requireField(dateOfBirthInput, 'Date of birth required.');
                    requireField(courseSelect, 'Course required.');
                    requireField(yearSelect, 'Year level required.');
                    requireField(sectionSelect, 'Section required.');
                }

                requireField(passwordInput, 'Password required.');
                requireField(passwordConfirmInput, 'Please confirm your password.');

                // Password strength validation
                if (passwordInput) {
                    const pw = passwordInput.value || '';
                    if (pw) {
                        const result = evaluatePassword(pw);
                        if (!result.hasLength || !result.hasUpper || !result.hasNumber || !result.hasSpecial) {
                            if (!hasError) passwordInput.focus();
                            hasError = true;
                            setFieldError(
                                passwordInput,
                                'Password must be at least 8 characters and include at least one uppercase letter, one number, and one special character (!@#$%^&*).'
                            );
                            updatePasswordStrength();
                        }
                    }
                }

                // Password match
                if (!hasError && passwordInput && passwordConfirmInput) {
                    const pw = passwordInput.value || '';
                    const pw2 = passwordConfirmInput.value || '';
                    if (pw && pw2 && pw !== pw2) {
                        hasError = true;
                        setFieldError(passwordConfirmInput, 'Passwords do not match.');
                        passwordConfirmInput.focus();
                    }
                }

                if (hasError) {
                    e.preventDefault();
                    syncConfirmVisibility();
                    return;
                }
            });

            // Initial render
            syncRoleUI();
            rebuildYearOptionsIfNeeded();
            updatePreview();
            updatePasswordStrength();
            syncConfirmVisibility();
        })();
    </script>
</body>
</html>

