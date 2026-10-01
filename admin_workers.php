<?php
require_once __DIR__ . '/includes/auth.php';

$admin = require_permission('workers.manage');
$pdo = app_db();
$errorMessage = '';
$editingId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$editingWorker = null;

if ($editingId !== null) {
    $editStatement = $pdo->prepare(
        'SELECT u.id, u.full_name, u.email, u.phone, u.status, cp.worker_code, cp.started_on
         FROM users u
         INNER JOIN chw_profiles cp ON cp.user_id = u.id
         WHERE u.id = :user_id'
    );
    $editStatement->execute(['user_id' => $editingId]);
    $editingWorker = $editStatement->fetch() ?: null;
    if ($editingWorker === null) {
        http_response_code(404);
        exit('Community health worker not found.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_worker'])) {
        if (!csrf_is_valid()) {
            $errorMessage = 'Your session expired. Refresh the page and try again.';
        } else {
            $deleteId = filter_var($_POST['delete_worker'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
            if ($deleteId === null) {
                $errorMessage = 'The worker to delete was not identified.';
            } else {
                $checkWorker = $pdo->prepare(
                    'SELECT u.id
                     FROM users u
                     INNER JOIN chw_profiles cp ON cp.user_id = u.id
                     WHERE u.id = :user_id
                     LIMIT 1'
                );
                $checkWorker->execute(['user_id' => $deleteId]);
                if ($checkWorker->fetchColumn() === false) {
                    $errorMessage = 'The selected CHW could not be found.';
                } else {
                    $visitCheck = $pdo->prepare('SELECT COUNT(*) FROM visits WHERE chw_user_id = :user_id');
                    $visitCheck->execute(['user_id' => $deleteId]);
                    $followUpCheck = $pdo->prepare('SELECT COUNT(*) FROM follow_up_appointments WHERE assigned_chw_user_id = :user_id');
                    $followUpCheck->execute(['user_id' => $deleteId]);

                    if ((int) $visitCheck->fetchColumn() > 0 || (int) $followUpCheck->fetchColumn() > 0) {
                        $errorMessage = 'This CHW has linked visits or follow-ups. Deactivate the account instead of deleting it.';
                    } else {
                        $pdo->beginTransaction();
                        try {
                            $pdo->prepare('DELETE FROM chw_village_assignments WHERE chw_user_id = :user_id')->execute(['user_id' => $deleteId]);
                            $pdo->prepare('DELETE FROM chw_profiles WHERE user_id = :user_id')->execute(['user_id' => $deleteId]);
                            $pdo->prepare('DELETE FROM users WHERE id = :user_id')->execute(['user_id' => $deleteId]);
                            $pdo->commit();

                            $_SESSION['admin_flash'] = ['type' => 'success', 'message' => 'CHW account removed.'];
                            header('Location: admin_workers.php');
                            exit;
                        } catch (Throwable $exception) {
                            $pdo->rollBack();
                            error_log($exception->getMessage());
                            $errorMessage = 'The CHW account could not be deleted. Please try again.';
                        }
                    }
                }
            }
        }
    }

    $postedId = filter_var($_POST['worker_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    $isEditing = $postedId !== null;
    if ($isEditing !== ($editingWorker !== null) || ($isEditing && $postedId !== $editingId)) {
        $errorMessage = 'The worker record changed. Reload the page and try again.';
    }

    $fullNameInput = $_POST['full_name'] ?? '';
    $emailInput = $_POST['email'] ?? '';
    $phoneInput = $_POST['phone'] ?? '';
    $workerCodeInput = $_POST['worker_code'] ?? '';
    $startedOnInput = $_POST['started_on'] ?? '';
    $passwordInput = $_POST['password'] ?? '';
    $statusInput = $_POST['status'] ?? 'active';
    $fullName = is_string($fullNameInput) ? trim($fullNameInput) : '';
    $email = is_string($emailInput) ? trim($emailInput) : '';
    $phone = is_string($phoneInput) ? trim($phoneInput) : '';
    $workerCode = is_string($workerCodeInput) ? trim($workerCodeInput) : '';
    $startedOn = is_string($startedOnInput) ? trim($startedOnInput) : '';
    $password = is_string($passwordInput) ? $passwordInput : '';
    $status = is_string($statusInput) ? $statusInput : '';
    $villageInput = $_POST['village_ids'] ?? [];
    $villageIds = [];

    if (is_array($villageInput)) {
        foreach ($villageInput as $villageValue) {
            $villageId = filter_var($villageValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($villageId) {
                $villageIds[] = (int) $villageId;
            }
        }
        $villageIds = array_values(array_unique($villageIds));
    }

    if ($errorMessage === '' && !csrf_is_valid()) {
        $errorMessage = 'Your session expired. Refresh the page and try again.';
    } elseif ($errorMessage === '' && (!is_string($fullNameInput) || $fullName === '' || strlen($fullName) > 120
        || !is_string($emailInput) || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254
        || !is_string($workerCodeInput) || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $workerCode)
        || !is_string($phoneInput) || strlen($phone) > 30 || ($phone !== '' && !preg_match('/^[0-9+().\-\s]+$/', $phone))
        || !is_string($startedOnInput) || ($startedOn !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startedOn))
        || !in_array($status, ['active', 'inactive'], true)
        || (!$isEditing && strlen($password) < 12)
        || ($isEditing && $password !== '' && strlen($password) < 12)
        || !is_array($villageInput) || $villageIds === [])) {
        $errorMessage = 'Check the worker details, use a 12-character password for new accounts, and select at least one village.';
    } elseif ($errorMessage === '') {
        $placeholders = implode(',', array_fill(0, count($villageIds), '?'));
        $villageCheck = $pdo->prepare("SELECT COUNT(*) FROM villages WHERE id IN ({$placeholders})");
        $villageCheck->execute($villageIds);

        if ((int) $villageCheck->fetchColumn() !== count($villageIds)) {
            $errorMessage = 'One or more selected villages no longer exist. Refresh the page and try again.';
        } else {
            $pdo->beginTransaction();
            try {
                if ($isEditing) {
                    $updateUser = $pdo->prepare(
                        'UPDATE users SET full_name = :full_name, email = :email, phone = :phone, status = :status WHERE id = :user_id'
                    );
                    $updateUser->execute([
                        'full_name' => $fullName,
                        'email' => $email,
                        'phone' => $phone !== '' ? $phone : null,
                        'status' => $status,
                        'user_id' => $postedId,
                    ]);
                    if ($password !== '') {
                        $updatePassword = $pdo->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :user_id');
                        $updatePassword->execute([
                            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                            'user_id' => $postedId,
                        ]);
                    }
                    $updateProfile = $pdo->prepare(
                        'UPDATE chw_profiles SET worker_code = :worker_code, started_on = :started_on WHERE user_id = :user_id'
                    );
                    $updateProfile->execute([
                        'worker_code' => $workerCode,
                        'started_on' => $startedOn !== '' ? $startedOn : null,
                        'user_id' => $postedId,
                    ]);
                    $workerId = $postedId;
                    $action = 'worker.updated';
                } else {
                    $roleStatement = $pdo->prepare("SELECT id FROM roles WHERE code = 'chw' LIMIT 1");
                    $roleStatement->execute();
                    $roleId = $roleStatement->fetchColumn();
                    if (!$roleId) {
                        throw new RuntimeException('The CHW role is missing. Import the project database schema again.');
                    }

                    $insertUser = $pdo->prepare(
                        "INSERT INTO users (role_id, full_name, email, password_hash, phone, status)
                         VALUES (:role_id, :full_name, :email, :password_hash, :phone, 'active')"
                    );
                    $insertUser->execute([
                        'role_id' => $roleId,
                        'full_name' => $fullName,
                        'email' => $email,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'phone' => $phone !== '' ? $phone : null,
                    ]);
                    $workerId = (int) $pdo->lastInsertId();

                    $insertProfile = $pdo->prepare(
                        'INSERT INTO chw_profiles (user_id, worker_code, started_on) VALUES (:user_id, :worker_code, :started_on)'
                    );
                    $insertProfile->execute([
                        'user_id' => $workerId,
                        'worker_code' => $workerCode,
                        'started_on' => $startedOn !== '' ? $startedOn : null,
                    ]);
                    $action = 'worker.created';
                }

                $removeAssignments = $pdo->prepare('DELETE FROM chw_village_assignments WHERE chw_user_id = :worker_id');
                $removeAssignments->execute(['worker_id' => $workerId]);
                $addAssignment = $pdo->prepare(
                    'INSERT INTO chw_village_assignments (chw_user_id, village_id, assigned_by_user_id)
                     VALUES (:worker_id, :village_id, :assigned_by)'
                );
                foreach ($villageIds as $villageId) {
                    $addAssignment->execute([
                        'worker_id' => $workerId,
                        'village_id' => $villageId,
                        'assigned_by' => $admin['id'],
                    ]);
                }

                $audit = $pdo->prepare(
                    'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, summary)
                     VALUES (:actor_id, :action, \'chw_profile\', :entity_id, :summary)'
                );
                $audit->execute([
                    'actor_id' => $admin['id'],
                    'action' => $action,
                    'entity_id' => $workerId,
                    'summary' => count($villageIds) . ' village assignment(s) saved',
                ]);
                $pdo->commit();

                $_SESSION['admin_flash'] = [
                    'type' => 'success',
                    'message' => $isEditing ? 'Community health worker updated.' : 'Community health worker registered.',
                ];
                header('Location: admin_workers.php');
                exit;
            } catch (PDOException $exception) {
                $pdo->rollBack();
                error_log($exception->getMessage());
                $errorMessage = $exception->getCode() === '23000'
                    ? 'That email or worker code is already in use.'
                    : 'The worker could not be saved. Please try again.';
            } catch (Throwable $exception) {
                $pdo->rollBack();
                error_log($exception->getMessage());
                $errorMessage = 'The worker could not be saved. Please try again.';
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['worker_id'])) {
    $editingId = filter_var($_POST['worker_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    if ($editingId !== null && $editingWorker === null) {
        $editStatement = $pdo->prepare(
            'SELECT u.id, u.full_name, u.email, u.phone, u.status, cp.worker_code, cp.started_on
             FROM users u INNER JOIN chw_profiles cp ON cp.user_id = u.id WHERE u.id = :user_id'
        );
        $editStatement->execute(['user_id' => $editingId]);
        $editingWorker = $editStatement->fetch() ?: null;
    }
}

$editingVillageIds = [];
if ($editingWorker !== null) {
    $assignmentStatement = $pdo->prepare('SELECT village_id FROM chw_village_assignments WHERE chw_user_id = :worker_id');
    $assignmentStatement->execute(['worker_id' => $editingWorker['id']]);
    $editingVillageIds = array_map('intval', $assignmentStatement->fetchAll(PDO::FETCH_COLUMN));
}

$villages = $pdo->query(
    'SELECT v.id, v.code, v.name AS village_name, d.name AS district_name, s.name AS sector_name, c.name AS cell_name
     FROM villages v
     INNER JOIN cells c ON c.id = v.cell_id
     INNER JOIN sectors s ON s.id = c.sector_id
     INNER JOIN districts d ON d.id = s.district_id
     ORDER BY d.name, s.name, c.name, v.name'
)->fetchAll();

$workers = $pdo->query(
    "SELECT u.id, u.full_name, u.email, u.phone, u.status, cp.worker_code,
            GROUP_CONCAT(DISTINCT v.name ORDER BY v.name SEPARATOR ', ') AS village_names
     FROM chw_profiles cp
     INNER JOIN users u ON u.id = cp.user_id
     LEFT JOIN chw_village_assignments cva ON cva.chw_user_id = cp.user_id
     LEFT JOIN villages v ON v.id = cva.village_id
     GROUP BY u.id, u.full_name, u.email, u.phone, u.status, cp.worker_code
     ORDER BY u.full_name"
)->fetchAll();

$formValue = static function (string $field, string $fallback = '') use ($editingWorker): string {
    $posted = $_POST[$field] ?? null;
    if (is_string($posted)) {
        return $posted;
    }
    return (string) ($editingWorker[$field] ?? $fallback);
};
$selectedVillageIds = $_POST['village_ids'] ?? $editingVillageIds;
if (!is_array($selectedVillageIds)) {
    $selectedVillageIds = [];
}
$selectedVillageIds = array_values(array_filter($selectedVillageIds, 'is_scalar'));
$selectedVillageIds = array_map('strval', $selectedVillageIds);
$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

$pageTitle = 'CHW management';
require __DIR__ . '/includes/app-header.php';
?>
<section class="management-heading">
    <div>
        <p class="eyebrow">Administration / Field team</p>
        <h1>CHW management</h1>
        <p>Register workers, set account access, and assign village coverage.</p>
    </div>
    <span class="role-chip"><?php echo count($workers); ?> workers</span>
</section>

<?php if ($errorMessage !== ''): ?>
    <div class="alert alert-error" role="alert"><?php echo htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>
<?php if ($flash): ?>
    <div class="alert alert-success" role="status"><?php echo htmlspecialchars($flash['message'], ENT_QUOTES, 'UTF-8'); ?></div>
<?php endif; ?>

<section class="management-grid">
    <div class="management-panel">
        <div class="panel-heading">
            <p class="eyebrow"><?php echo $editingWorker ? 'Update worker' : 'New account'; ?></p>
            <h2><?php echo $editingWorker ? 'Edit CHW details' : 'Register a CHW'; ?></h2>
        </div>
        <?php if ($villages === []): ?>
            <p class="empty-state">Register at least one village in <a href="admin_locations.php">Village setup</a> before creating a CHW.</p>
        <?php else: ?>
            <form method="post" class="admin-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                <?php if ($editingWorker): ?>
                    <input type="hidden" name="worker_id" value="<?php echo (int) $editingWorker['id']; ?>">
                <?php endif; ?>
                <div class="form-row">
                    <label for="full-name">Full name</label>
                    <input id="full-name" name="full_name" maxlength="120" autocomplete="name" required value="<?php echo htmlspecialchars($formValue('full_name'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-row">
                    <label for="worker-email">Email address</label>
                    <input id="worker-email" name="email" type="email" maxlength="254" autocomplete="email" required value="<?php echo htmlspecialchars($formValue('email'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-row">
                    <label for="phone">Phone number <span class="optional-label">Optional</span></label>
                    <input id="phone" name="phone" type="tel" maxlength="30" autocomplete="tel" value="<?php echo htmlspecialchars($formValue('phone'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-row">
                    <label for="worker-code">Worker code</label>
                    <input id="worker-code" name="worker_code" maxlength="40" pattern="[A-Za-z0-9_-]+" required value="<?php echo htmlspecialchars($formValue('worker_code'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-row">
                    <label for="started-on">Start date <span class="optional-label">Optional</span></label>
                    <input id="started-on" name="started_on" type="date" value="<?php echo htmlspecialchars($formValue('started_on'), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-row">
                    <label for="worker-password"><?php echo $editingWorker ? 'New password' : 'Temporary password'; ?></label>
                    <input id="worker-password" name="password" type="password" autocomplete="new-password" minlength="12" <?php echo $editingWorker ? '' : 'required'; ?> aria-describedby="password-note">
                    <span id="password-note" class="field-help"><?php echo $editingWorker ? 'Leave blank to keep the current password. New passwords require at least 12 characters.' : 'At least 12 characters. Share it securely with the worker.'; ?></span>
                </div>
                <?php if ($editingWorker): ?>
                    <div class="form-row">
                        <label for="worker-status">Account status</label>
                        <select id="worker-status" name="status">
                            <option value="active" <?php echo $formValue('status', 'active') === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $formValue('status', 'active') === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        </select>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="status" value="active">
                <?php endif; ?>
                <fieldset class="village-picker">
                    <legend>Assigned villages <span class="required-marker">Required</span></legend>
                    <div class="village-options">
                        <?php foreach ($villages as $village): ?>
                            <?php $villageId = (string) $village['id']; ?>
                            <label class="village-option">
                                <input type="checkbox" name="village_ids[]" value="<?php echo (int) $village['id']; ?>" <?php echo in_array($villageId, $selectedVillageIds, true) ? 'checked' : ''; ?>>
                                <span><strong><?php echo htmlspecialchars($village['village_name'], ENT_QUOTES, 'UTF-8'); ?></strong><small><?php echo htmlspecialchars($village['district_name'] . ' / ' . $village['sector_name'] . ' / ' . $village['cell_name'], ENT_QUOTES, 'UTF-8'); ?></small></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <button class="button button-primary admin-submit" type="submit"><?php echo $editingWorker ? 'Save changes' : 'Create CHW account'; ?></button>
                <?php if ($editingWorker): ?><a class="cancel-link" href="admin_workers.php">Cancel editing</a><?php endif; ?>
            </form>
        <?php endif; ?>
    </div>

    <div class="management-panel worker-list-panel">
        <div class="panel-heading">
            <p class="eyebrow">Field team</p>
            <h2>Registered CHWs</h2>
        </div>
        <?php if ($workers === []): ?>
            <p class="empty-state">No community health workers are registered yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Worker</th><th>Village assignments</th><th>Status</th><th><span class="visually-hidden">Actions</span></th></tr></thead>
                    <tbody>
                    <?php foreach ($workers as $worker): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($worker['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong><span class="table-secondary"><?php echo htmlspecialchars($worker['worker_code'] . ' · ' . $worker['email'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td><?php echo htmlspecialchars($worker['village_names'] ?: 'No villages', ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="account-status status-<?php echo htmlspecialchars($worker['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucfirst($worker['status']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td>
                                <a class="table-action" href="admin_workers.php?edit=<?php echo (int) $worker['id']; ?>">Edit</a>
                                <form method="post" style="display:inline; margin-left:0.45rem;" onsubmit="return confirm('Delete this CHW account?');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="delete_worker" value="<?php echo (int) $worker['id']; ?>">
                                    <button type="submit" class="table-action" style="background:none; border:none; padding:0; cursor:pointer;">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/includes/app-footer.php'; ?>