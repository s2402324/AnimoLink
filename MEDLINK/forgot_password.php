<?php
session_start();

require_once __DIR__ . '/config/database.php';

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));

    if ($email === '') {
        $error = 'Please enter your email address.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        try {
            $conn = db();

            $stmt = $conn->prepare('SELECT id, email FROM users WHERE TRIM(email) = ? LIMIT 1');
            $stmt->bind_param('s', $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            if ($user) {
                $user_id = (int)$user['id'];

                $delete = $conn->prepare('DELETE FROM password_resets WHERE user_id = ?');
                $delete->bind_param('i', $user_id);
                $delete->execute();

                $token = bin2hex(random_bytes(32));
                $token_hash = hash('sha256', $token);

                $expires_at = (new DateTimeImmutable('+1 hour'))->format('Y-m-d H:i:s');

                $insert = $conn->prepare(
                    'INSERT INTO password_resets (user_id, token_hash, expires_at, created_at)
                     VALUES (?, ?, ?, NOW())'
                );
                $insert->bind_param('iss', $user_id, $token_hash, $expires_at);
                $insert->execute();

                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
                $base_path = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
                $reset_link = $scheme . '://' . $host . $base_path . '/reset_password.php?token=' . urlencode($token);

                $to = (string)$user['email'];
                $subject = 'Password Reset Request';
                $message = "Hello,\n\n"
                    . "We received a request to reset the password for your account.\n"
                    . "If you made this request, please click the link below (or paste it into your browser) to set a new password:\n\n"
                    . $reset_link . "\n\n"
                    . "This link will expire in 1 hour. If you did not request a password reset, you can safely ignore this email.\n\n"
                    . "Regards,\nMediClear";

                $from_host = $host !== '' ? $host : 'example.com';
                $from = 'no-reply@' . $from_host;
                $headers = "From: {$from}\r\n";
                $headers .= "Reply-To: {$from}\r\n";

                @mail($to, $subject, $message, $headers);
            }

            $success = 'If an account with that email exists, a password reset link has been sent.';
        } catch (Throwable $e) {
            error_log((string)$e);
            $error = 'A temporary error occurred. Please try again.';
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
                <h1>Forgot Password</h1>
                <p>Enter your registered email address and we will send you a secure link to reset your password.</p>
            </div>

            <?php if ($error): ?>
                <div class="error-message"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="success-message"><?php echo htmlspecialchars($success); ?></div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="email">Email *</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="Email used when you registered"
                        required
                        value="<?php echo htmlspecialchars((string)($_POST['email'] ?? '')); ?>"
                    >
                </div>
                <button type="submit" class="btn-primary">Send reset link</button>
            </form>

            <div class="auth-footer">
                <a class="auth-link" href="index.php">Back to Sign in</a>
            </div>
        </div>
    </div>

    <script src="js/script.js"></script>
</body>
</html>
