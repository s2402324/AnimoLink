<?php
session_start();

require_once __DIR__ . '/config/database.php';

$error = null;
$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$show_form = false;
$is_post = ($_SERVER['REQUEST_METHOD'] === 'POST');

if ($token === '') {
    $error = 'Invalid or expired password reset link.';
} else {
    try {
        $conn = db();
        $token_hash = hash('sha256', $token);

        $stmt = $conn->prepare(
            'SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at
             FROM password_resets pr
             JOIN users u ON u.id = pr.user_id
             WHERE pr.token_hash = ?
             LIMIT 1'
        );
        $stmt->bind_param('s', $token_hash);
        $stmt->execute();
        $reset_request = $stmt->get_result()->fetch_assoc();

        if (!$reset_request) {
            $error = 'Invalid or expired password reset link.';
        } else {
            $now = new DateTimeImmutable('now');
            $expires_at = new DateTimeImmutable((string)$reset_request['expires_at']);

            if (!empty($reset_request['used_at']) || $expires_at < $now) {
                $error = 'This password reset link has expired or has already been used.';
            } else {
                $show_form = true;

                if ($is_post) {
                    $new_password = (string)($_POST['new_password'] ?? '');
                    $new_password_confirm = (string)($_POST['new_password_confirm'] ?? '');

                    if (strlen($new_password) < 6) {
                        $error = 'Password must be at least 6 characters.';
                    } elseif ($new_password !== $new_password_confirm) {
                        $error = 'Passwords do not match.';
                    } else {
                        $user_id = (int)$reset_request['user_id'];
                        $reset_id = (int)$reset_request['id'];

                        $password_hash = password_hash($new_password, PASSWORD_DEFAULT);

                        $conn->begin_transaction();
                        try {
                            $update_user = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ? LIMIT 1');
                            $update_user->bind_param('si', $password_hash, $user_id);
                            $update_user->execute();

                            $update_reset = $conn->prepare('UPDATE password_resets SET used_at = NOW() WHERE id = ? LIMIT 1');
                            $update_reset->bind_param('i', $reset_id);
                            $update_reset->execute();

                            $conn->commit();

                            $_SESSION['success_message'] = 'Your password has been reset. You can now sign in.';
                            header('Location: index.php');
                            exit;
                        } catch (Throwable $e) {
                            $conn->rollback();
                            error_log((string)$e);
                            $error = 'A temporary error occurred. Please try again.';
                        }
                    }
                }
            }
        }
    } catch (Throwable $e) {
        error_log((string)$e);
        $error = 'A temporary error occurred. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - MediClear</title>
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
                <h1>Set New Password</h1>
                <p>Choose a new password for your account.</p>
            </div>

            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($show_form && !$error): ?>
                <form method="POST" action="">
                    <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">

                    <div class="form-group">
                        <label for="new_password">New Password *</label>
                        <input
                            type="password"
                            id="new_password"
                            name="new_password"
                            placeholder="At least 6 characters"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="new_password_confirm">Confirm New Password *</label>
                        <input
                            type="password"
                            id="new_password_confirm"
                            name="new_password_confirm"
                            placeholder="Confirm password"
                            required
                        >
                    </div>

                    <button type="submit" class="btn-primary">Reset Password</button>
                </form>
            <?php else: ?>
                <div class="auth-footer">
                    <a class="auth-link" href="forgot_password.php">Request a new reset link</a>
                </div>
            <?php endif; ?>

            <div class="auth-footer">
                <a class="auth-link" href="index.php">Back to Sign in</a>
            </div>
        </div>
    </div>

    <script src="js/script.js"></script>
</body>
</html>

