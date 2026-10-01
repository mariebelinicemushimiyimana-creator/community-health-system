<?php
require_once __DIR__ . '/includes/auth.php';

if (current_user() !== null) {
    header('Location: dashboard.php');
    exit;
}

$pdo = app_db();
$adminExists = (bool) $pdo->query(
    "SELECT 1
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     WHERE r.code = 'administrator'
     LIMIT 1"
)->fetchColumn();

if ($adminExists) {
    header('Location: login.php');
    exit;
}

$errorMessage = '';
$fullName = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if (!csrf_is_valid()) {
        $errorMessage = 'Your session expired. Please try again.';
    } elseif ($fullName === '' || strlen($fullName) > 120
        || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || strlen($email) > 254
        || strlen($password) < 12
        || $password !== $confirmPassword) {
        $errorMessage = 'Enter a valid full name, email, and a matching 12-character password.';
    } else {
        $roleStatement = $pdo->prepare("SELECT id FROM roles WHERE code = 'administrator' LIMIT 1");
        $roleStatement->execute();
        $roleId = $roleStatement->fetchColumn();

        if (!$roleId) {
            $errorMessage = 'The administrator role is not available yet. Import the project database first.';
        } else {
            try {
                $pdo->beginTransaction();
                $insert = $pdo->prepare(
                    "INSERT INTO users (role_id, full_name, email, password_hash, status)
                     VALUES (:role_id, :full_name, :email, :password_hash, 'active')"
                );
                $insert->execute([
                    'role_id' => $roleId,
                    'full_name' => $fullName,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ]);
                $pdo->commit();

                $_SESSION['flash'] = ['type' => 'success', 'message' => 'Administrator account created successfully. You can sign in now.'];
                header('Location: login.php');
                exit;
            } catch (PDOException $exception) {
                $pdo->rollBack();
                error_log($exception->getMessage());
                $errorMessage = $exception->getCode() === '23000'
                    ? 'An account with that email already exists.'
                    : 'The administrator account could not be created. Please try again.';
            } catch (Throwable $exception) {
                $pdo->rollBack();
                error_log($exception->getMessage());
                $errorMessage = 'The administrator account could not be created. Please try again.';
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
    <meta name="theme-color" content="#f5f9f7">
    <title>Create administrator | Community Health</title>
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
                <p class="auth-kicker">Initial setup</p>
                <h1 id="auth-title">Create the first administrator.</h1>
                <p class="auth-copy">This account is required to manage village setup and community health workers.</p>
            </div>
            <a class="auth-public-link" href="index.php">Return to public site</a>
        </section>

        <section class="auth-panel" aria-labelledby="register-admin-title">
            <div class="auth-panel-heading">
                <p class="eyebrow">Secure setup</p>
                <h2 id="register-admin-title">Administrator registration</h2>
                <p>Create the first logged-in admin for the workspace.</p>
            </div>

            <?php if ($errorMessage !== ''): ?>
                <div class="alert alert-error" role="alert"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endif; ?>

            <form method="post" action="register-admin.php" class="auth-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-row">
                    <label for="full-name">Full name</label>
                    <input id="full-name" name="full_name" maxlength="120" autocomplete="name" required value="<?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-row">
                    <label for="email">Email address</label>
                    <input id="email" name="email" type="email" autocomplete="username" maxlength="254" required value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-row">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required>
                </div>
                <div class="form-row">
                    <label for="confirm-password">Confirm password</label>
                    <input id="confirm-password" name="confirm_password" type="password" autocomplete="new-password" minlength="12" required>
                </div>
                <button class="button button-primary auth-submit" type="submit">Create administrator</button>
            </form>
            <p class="field-help">Already setup? <a href="login.php">Sign in here</a></p>
        </section>
    </main>
</body>
</html>
