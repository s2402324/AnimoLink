<?php
session_start();

require_once __DIR__ . '/config/database.php';

$error = null;
$success = null;
$step = isset($_GET['step']) && $_GET['step'] === '2' ? '2' : '1';

// If already on step 2, require verified user in session
if ($step === '2') {
    $reset_user_id = (int)($_SESSION['forgot_user_id'] ?? 0);
    if ($reset_user_id <= 0) {
        header('Location: forgot_password.php');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step === '1') {
        $full_name = trim((string)($_POST['full_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $date_of_birth = trim((string)($_POST['date_of_birth'] ?? ''));

        if ($full_name === '' || $email === '' || $date_of_birth === '') {
            $error = 'Please fill in full name, email, and date of birth.';
        } else {
            try {
                $conn = db();
                $stmt = $conn->prepare(
                    'SELECT id, user_code, full_name FROM users
                     WHERE TRIM(full_name) = ? AND TRIM(email) = ? AND date_of_birth = ?
                     LIMIT 1'
                );
                $stmt->bind_param('sss', $full_name, $email, $date_of_birth);
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();

                if (!$user) {
                    $error = 'The information you entered does not match our records. Please try again or contact support.';
                } else {
                    $_SESSION['forgot_user_id'] = (int)$user['id'];
                    header('Location: forgot_password.php?step=2');
                    exit;
                }
            } catch (Throwable $e) {
                error_log((string)$e);
                $error = 'A temporary error occurred. Please try again.';
            }
        }
    } else {
        // step 2: set new password
        $new_password = (string)($_POST['new_password'] ?? '');
        $new_password_confirm = (string)($_POST['new_password_confirm'] ?? '');
        $reset_user_id = (int)($_SESSION['forgot_user_id'] ?? 0);

        if ($reset_user_id <= 0) {
            header('Location: forgot_password.php');
            exit;
        }

        if (strlen($new_password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($new_password !== $new_password_confirm) {
            $error = 'Passwords do not match.';
        } else {
            try {
                $conn = db();
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ? LIMIT 1');
                $stmt->bind_param('si', $password_hash, $reset_user_id);
                $stmt->execute();

                unset($_SESSION['forgot_user_id']);
                $_SESSION['success_message'] = 'Your password has been reset. You can now sign in.';
                header('Location: index.php');
                exit;
            } catch (Throwable $e) {
                error_log((string)$e);
                $error = 'A temporary error occurred. Please try again.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - MediClear</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body class="login-page">
    <div class="login-background" style="background-image: url('pics/BGFR.jpg');"></div>

    <div class="login-container">
        <div class="login-card">
            <div class="logo-container">
                <img src="pics/LOGOLASALLE.png" alt="Logo" class="login-logo">
            </div>

            <div class="login-header">
                <h1><?php echo $step === '2' ? 'Set New Password' : 'Forgot Password'; ?></h1>
                <p><?php echo $step === '2' ? 'Enter your new password below.' : 'Enter your full name, email, and date of birth to reset your password.'; ?></p>
            </div>

            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($step === '1'): ?>
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="full_name">Full Name *</label>
                        <input type="text" id="full_name" name="full_name" placeholder="As registered" required
                               value="<?php echo htmlspecialchars((string)($_POST['full_name'] ?? '')); ?>">
                    </div>
                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" placeholder="Email used when you registered" required
                               value="<?php echo htmlspecialchars((string)($_POST['email'] ?? '')); ?>">
                    </div>
                    <div class="form-group">
                        <label for="date_of_birth">Date of Birth *</label>
                        <input type="date" id="date_of_birth" name="date_of_birth" required
                               value="<?php echo htmlspecialchars((string)($_POST['date_of_birth'] ?? '')); ?>">
                    </div>
                    <button type="submit" class="btn-primary">Continue</button>
                </form>
            <?php else: ?>
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="new_password">New Password *</label>
                        <input type="password" id="new_password" name="new_password" placeholder="At least 6 characters" required>
                    </div>
                    <div class="form-group">
                        <label for="new_password_confirm">Confirm New Password *</label>
                        <input type="password" id="new_password_confirm" name="new_password_confirm" placeholder="Confirm password" required>
                    </div>
                    <button type="submit" class="btn-primary">Reset Password</button>
                </form>
            <?php endif; ?>

            <div class="auth-footer">
                <a class="auth-link" href="index.php">Back to Sign in</a>
            </div>
        </div>
    </div>

    <script src="js/script.js"></script>
</body>
</html>
