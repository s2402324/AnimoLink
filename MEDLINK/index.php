<?php
session_start();

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/app.php';

// Defaults for first load / failed login re-render
$role = 'student';
$student_id = '';
$success_message = null;
if (isset($_SESSION['success_message'])) {
    $success_message = (string)$_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role = $_POST['role'] ?? '';
    $student_id = $_POST['student_id'] ?? '';
    $password = $_POST['password'] ?? '';
    $normalized_role = ($role === 'staff') ? 'clinic' : $role;
    
    if (!in_array($normalized_role, ['student', 'clinic'], true)) {
        $error = "Invalid role selected";
    } elseif (!empty($student_id) && !empty($password)) {
        $user_code = trim((string)$student_id);

        // DEV MODE: bypass DB auth for design work
        if (app_is_dev()) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user_code !== '' ? $user_code : 'DEV';
            $_SESSION['user_pk'] = 0;
            $_SESSION['role'] = $normalized_role;
            $_SESSION['full_name'] = 'Design Mode';

            if ($normalized_role === 'student') {
                header('Location: student_dashboard.php');
                exit;
            }
            header('Location: clinic_dashboard.php');
            exit;
        }

        try {
            $conn = db();

            $stmt = $conn->prepare('SELECT id, user_code, role, full_name, password_hash FROM users WHERE user_code = ? LIMIT 1');
            $stmt->bind_param('s', $user_code);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if (!$user || !isset($user['password_hash']) || !password_verify($password, (string)$user['password_hash'])) {
                $error = "Invalid ID or password";
            } else {
                $db_role = (string)($user['role'] ?? '');
                if (!in_array($db_role, ['student', 'clinic'], true)) {
                    $error = "This account has an invalid role. Please contact admin.";
                } elseif ($normalized_role !== $db_role) {
                    $error = "Selected role doesn't match this account";
                } else {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = (string)$user['user_code'];
                    $_SESSION['user_pk'] = (int)$user['id'];
                    $_SESSION['role'] = $db_role;
                    $_SESSION['full_name'] = (string)($user['full_name'] ?? '');

                    if ($db_role === 'student') {
                        header('Location: student_dashboard.php');
                        exit;
                    }
                    header('Location: clinic_dashboard.php');
                    exit;
                }
            }
        } catch (Throwable $e) {
            error_log((string)$e);
            $error = "Database error. Make sure the database/tables exist, then try again.";
        }
    } else {
        $error = "Please fill in all fields";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MediClear - Login</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
    <div class="login-background" style="background-image: url('pics/BGFR.jpg');"></div>
    
    <div class="login-container">
        <div class="login-card">
            <div class="logo-container">
                <img src="pics/LOGOLASALLE.png" alt="WALA PAKAMI LOGO MISS NAKITA" class="login-logo">
            </div>
            
            <div class="login-header">
                <h1>Welcome</h1>
                <p>Sign in to access the medical certification system</p>
            </div>
            
            <?php if (isset($error)): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            <?php if (!empty($success_message)): ?>
                <div class="success-message"><?php echo htmlspecialchars($success_message); ?></div>
            <?php endif; ?>
            
            <form method="POST" action="" id="loginForm">
                <div class="role-toggle">
                    <input type="radio" name="role" id="studentRole" value="student" <?php echo (($role ?? 'student') === 'student') ? 'checked' : ''; ?>>
                    <input type="radio" name="role" id="clinicRole" value="clinic" <?php echo (($role ?? 'student') === 'clinic') ? 'checked' : ''; ?>>
                    <div class="toggle-container">
                        <label for="studentRole" class="toggle-option" id="studentLabel">Student</label>
                        <label for="clinicRole" class="toggle-option" id="clinicLabel">Staff ID</label>
                        <div class="toggle-slider"></div>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="student_id" id="idLabel">Student ID</label>
                    <input type="text" id="student_id" name="student_id" placeholder="Enter your ID" value="<?php echo htmlspecialchars((string)($student_id ?? '')); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                    <a class="forgot-password-link" href="forgot_password.php">Forgot password?</a>
                </div>
                
                <button type="submit" class="btn-primary">Sign In</button>

                <div class="auth-footer">
                    <span>Don’t have an account?</span>
                    <a class="auth-link" href="register.php">Sign up</a>
                </div>
            </form>
        </div>
    </div>
    
    <script src="js/script.js"></script>
</body>
</html>
