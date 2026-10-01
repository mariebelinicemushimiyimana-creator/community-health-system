<?php
require_once __DIR__ . '/includes/auth.php';

if (current_user() !== null) {
    header('Location: dashboard.php');
    exit;
}

$errorMessage = '';
$flashMessage = $_SESSION['flash']['message'] ?? null;
unset($_SESSION['flash']);
$email = '';
$adminExists = (bool) app_db()->query(
    "SELECT 1
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE r.code = 'administrator'
     LIMIT 1"
)->fetchColumn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $emailInput = $_POST['email'] ?? '';
    $email = is_string($emailInput) ? trim($emailInput) : '';
    $password = $_POST['password'] ?? '';

    if (!csrf_is_valid()) {
        $errorMessage = 'Your session expired. Please try again.';
    } elseif (!is_string($emailInput) || !is_string($password) || $email === '' || $password === '') {
        $errorMessage = 'Enter your email and password.';
    } else {
        $statement = app_db()->prepare(
            'SELECT id, password_hash
             FROM users
             WHERE email = :email AND status = \'active\'
             LIMIT 1'
        );
        $statement->execute(['email' => $email]);
        $account = $statement->fetch();

        if ($account && password_verify($password, $account['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $account['id'];
            unset($_SESSION['csrf_token']);

            $update = app_db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :user_id');
            $update->execute(['user_id' => $account['id']]);

            header('Location: dashboard.php');
            exit;
        }

        $errorMessage = 'The email or password is incorrect.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#f5f9f7">
    <title>Sign in | Community Health</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/styles.css">
    <link rel="stylesheet" href="assets/app.css">
</head>
<body class="auth-page">
    <main class="auth-layout">
        <section class="auth-intro" aria-labelledby="auth-title">
            <a href="index.php" class="brand auth-brand">
                <span class="brand-mark">+</span>
                <span>Community Health</span>
            </a>
            <div>
                <p class="auth-kicker">Field operations</p>
                <h1 id="auth-title">Community care, coordinated.</h1>
                <p class="auth-copy">Sign in to manage assigned households, visits, and follow-ups.</p>
            </div>
            <a class="auth-public-link" href="index.php">Return to public site</a>
        </section>

        <section class="auth-panel" aria-labelledby="sign-in-title">
            <div class="auth-panel-heading">
                <p class="eyebrow">Secure workspace</p>
                <h2 id="sign-in-title">Sign in</h2>
                <p>Use your account credentials to continue.</p>
            </div>

            <?php if ($flashMessage !== null): ?>
                <div class="alert alert-success" role="status"><?php echo htmlspecialchars($flashMessage, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>
            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-error" role="alert"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <?php if (!$adminExists): ?>
                <div class="alert alert-warning" role="note">No administrator account has been created yet. Create the first admin to continue.</div>
                <a class="button button-primary auth-submit" href="register-admin.php">Create first administrator</a>
            <?php else: ?>
                <form method="post" action="login.php" class="auth-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="form-row">
                        <label for="email">Email address</label>
                        <input id="email" name="email" type="email" autocomplete="username" maxlength="254" required value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="form-row">
                        <label for="password">Password</label>
                        <input id="password" name="password" type="password" autocomplete="current-password" required>
                    </div>
                    <button class="button button-primary auth-submit" type="submit">Sign in</button>
                </form>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>