<?php
require_once __DIR__ . '/includes/auth.php';

$appUser = require_permission('visits.view');
$pdo = app_db();
$canManage = user_has_permission('visits.manage');
$errorMessage = '';
$editingId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$editingVisit = null;

if ($editingId !== null) {
    $editStatement = $pdo->prepare(
        'SELECT v.id, v.household_id, v.chw_user_id, v.scheduled_at, v.completed_at, v.purpose, v.observations, v.actions_taken, v.follow_up_required, v.status
         FROM visits v
         WHERE v.id = :visit_id
         LIMIT 1'
    );
    $editStatement->execute(['visit_id' => $editingId]);
    $editingVisit = $editStatement->fetch() ?: null;
    if ($editingVisit === null) {
        http_response_code(404);
        exit('Visit not found.');
    }
}

$viewScope = '1 = 1';
$scopeParams = [];
if ($appUser['role_code'] === 'chw') {
    $viewScope = 'v.chw_user_id = :chw_user_id';
    $scopeParams['chw_user_id'] = $appUser['id'];
} elseif (in_array($appUser['role_code'], ['health_center_staff', 'health_center_manager'], true)) {
    if ($appUser['health_center_id'] === null) {
        $viewScope = '1 = 0';
    } else {
        $viewScope = "EXISTS (
            SELECT 1
            FROM households h
            INNER JOIN health_center_villages hcv ON hcv.village_id = h.village_id
            WHERE h.id = v.household_id AND hcv.health_center_id = :center_id
        )";
        $scopeParams['center_id'] = $appUser['health_center_id'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_visit'])) {
        if (!$canManage) {
            http_response_code(403);
            exit('You do not have permission to manage visits.');
        }
        $deleteId = filter_var($_POST['delete_visit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        if ($deleteId === null) {
            $errorMessage = 'The visit to delete was not identified.';
        } else {
            $deleteStatement = $pdo->prepare('DELETE FROM visits WHERE id = :visit_id');
            $deleteStatement->execute(['visit_id' => $deleteId]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Visit removed.'];
            header('Location: visits.php');
            exit;
        }
    }

    if (!$canManage) {
        http_response_code(403);
        exit('You do not have permission to manage visits.');
    }

    if (!csrf_is_valid()) {
        $errorMessage = 'Your session expired. Refresh the page and try again.';
    } else {
        $visitId = filter_var($_POST['visit_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $householdId = filter_var($_POST['household_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $chwUserId = filter_var($_POST['chw_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $scheduledAt = trim((string) ($_POST['scheduled_at'] ?? ''));
        $purpose = trim((string) ($_POST['purpose'] ?? ''));
        $observations = trim((string) ($_POST['observations'] ?? ''));
        $actionsTaken = trim((string) ($_POST['actions_taken'] ?? ''));
        $followUpRequired = isset($_POST['follow_up_required']) ? 1 : 0;
        $status = in_array($_POST['status'] ?? 'scheduled', ['scheduled', 'completed', 'missed', 'rescheduled'], true) ? $_POST['status'] : 'scheduled';
        $completedAt = trim((string) ($_POST['completed_at'] ?? ''));

        if ($householdId === null || $chwUserId === null || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $scheduledAt)
            || $purpose === '' || strlen($purpose) > 150
            || ($observations !== '' && strlen($observations) > 2000)
            || ($actionsTaken !== '' && strlen($actionsTaken) > 2000)
            || ($completedAt !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $completedAt))) {
            $errorMessage = 'Please provide valid visit details before saving.';
        } else {
            $householdCheck = $pdo->prepare('SELECT 1 FROM households WHERE id = :household_id LIMIT 1');
            $householdCheck->execute(['household_id' => $householdId]);
            if ($householdCheck->fetchColumn() === false) {
                $errorMessage = 'The selected household no longer exists.';
            } else {
                $chwCheck = $pdo->prepare('SELECT 1 FROM chw_profiles WHERE user_id = :chw_user_id LIMIT 1');
                $chwCheck->execute(['chw_user_id' => $chwUserId]);
                if ($chwCheck->fetchColumn() === false) {
                    $errorMessage = 'The selected CHW is not valid.';
                }
            }
        }

        if ($errorMessage === '') {
            try {
                if ($visitId !== null) {
                    $statement = $pdo->prepare(
                        'UPDATE visits SET household_id = :household_id, chw_user_id = :chw_user_id, scheduled_at = :scheduled_at, completed_at = :completed_at,
                         purpose = :purpose, observations = :observations, actions_taken = :actions_taken, follow_up_required = :follow_up_required, status = :status
                         WHERE id = :visit_id'
                    );
                    $statement->execute([
                        'household_id' => $householdId,
                        'chw_user_id' => $chwUserId,
                        'scheduled_at' => $scheduledAt,
                        'completed_at' => $completedAt !== '' ? $completedAt : null,
                        'purpose' => $purpose,
                        'observations' => $observations !== '' ? $observations : null,
                        'actions_taken' => $actionsTaken !== '' ? $actionsTaken : null,
                        'follow_up_required' => $followUpRequired,
                        'status' => $status,
                        'visit_id' => $visitId,
                    ]);
                    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Visit updated.'];
                } else {
                    $syncUuid = sprintf(
                        '%s-%s-%s-%s-%s',
                        bin2hex(random_bytes(4)),
                        bin2hex(random_bytes(2)),
                        bin2hex(random_bytes(2)),
                        bin2hex(random_bytes(2)),
                        bin2hex(random_bytes(6))
                    );

                    $statement = $pdo->prepare(
                        'INSERT INTO visits (sync_uuid, household_id, chw_user_id, scheduled_at, completed_at, purpose, observations, actions_taken, follow_up_required, status)
                         VALUES (:sync_uuid, :household_id, :chw_user_id, :scheduled_at, :completed_at, :purpose, :observations, :actions_taken, :follow_up_required, :status)'
                    );
                    $statement->execute([
                        'sync_uuid' => $syncUuid,
                        'household_id' => $householdId,
                        'chw_user_id' => $chwUserId,
                        'scheduled_at' => $scheduledAt,
                        'completed_at' => $completedAt !== '' ? $completedAt : null,
                        'purpose' => $purpose,
                        'observations' => $observations !== '' ? $observations : null,
                        'actions_taken' => $actionsTaken !== '' ? $actionsTaken : null,
                        'follow_up_required' => $followUpRequired,
                        'status' => $status,
                    ]);
                    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Visit recorded.'];
                }

                header('Location: visits.php');
                exit;
            } catch (Throwable $exception) {
                error_log($exception->getMessage());
                $errorMessage = 'The visit could not be saved. Please try again.';
            }
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$visitRows = $pdo->prepare(
    'SELECT v.id, v.scheduled_at, v.purpose, v.status, v.follow_up_required, h.household_code, h.household_head_name,
            CONCAT(d.name, " / ", s.name, " / ", c.name, " / ", vill.name) AS village_name,
            u.full_name AS chw_name
     FROM visits v
     INNER JOIN households h ON h.id = v.household_id
     INNER JOIN villages vill ON vill.id = h.village_id
     INNER JOIN cells c ON c.id = vill.cell_id
     INNER JOIN sectors s ON s.id = c.sector_id
     INNER JOIN districts d ON d.id = s.district_id
     INNER JOIN users u ON u.id = v.chw_user_id
     WHERE ' . $viewScope . '
     ORDER BY v.scheduled_at DESC'
);
$visitRows->execute($scopeParams);
$visitList = $visitRows->fetchAll();

$households = $pdo->query(
    'SELECT h.id, h.household_code, CONCAT(d.name, " / ", s.name, " / ", c.name, " / ", v.name) AS location
     FROM households h
     INNER JOIN villages v ON v.id = h.village_id
     INNER JOIN cells c ON c.id = v.cell_id
     INNER JOIN sectors s ON s.id = c.sector_id
     INNER JOIN districts d ON d.id = s.district_id
     ORDER BY h.household_code'
)->fetchAll();

$chws = $pdo->query(
    'SELECT u.id, u.full_name, cp.worker_code
     FROM users u
     INNER JOIN chw_profiles cp ON cp.user_id = u.id
     WHERE u.status = "active"
     ORDER BY u.full_name'
)->fetchAll();

$formValue = static function (string $field, string $fallback = '') use ($editingVisit): string {
    $postedValue = $_POST[$field] ?? null;
    if (is_string($postedValue)) {
        return $postedValue;
    }
    if ($editingVisit !== null && array_key_exists($field, $editingVisit)) {
        return (string) ($editingVisit[$field] ?? $fallback);
    }
    return $fallback;
};

$pageTitle = 'Visit records';
require __DIR__ . '/includes/app-header.php';
?>
<section class="management-heading">
    <div>
        <p class="eyebrow">Field operations / Visits</p>
        <h1><?php echo $editingVisit !== null ? 'Edit visit' : 'Visit records'; ?></h1>
        <p>Log scheduled household visits and track completion outcomes.</p>
    </div>
    <span class="role-chip"><?php echo count($visitList); ?> visits</span>
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
            <p class="eyebrow"><?php echo $editingVisit !== null ? 'Update' : 'Schedule'; ?></p>
            <h2><?php echo $editingVisit !== null ? 'Edit visit' : 'New visit'; ?></h2>
            <p>Capture the purpose, visit status, and any required follow-up.</p>
        </div>
        <form method="post" class="admin-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <?php if ($editingVisit !== null): ?>
                <input type="hidden" name="visit_id" value="<?php echo (int) $editingVisit['id']; ?>">
            <?php endif; ?>
            <div class="form-row">
                <label for="household-id">Household</label>
                <select id="household-id" name="household_id" required>
                    <option value="">Select a household</option>
                    <?php foreach ($households as $household): ?>
                        <?php $selectedValue = (string) ($formValue('household_id', (string) ($editingVisit['household_id'] ?? ''))); ?>
                        <option value="<?php echo (int) $household['id']; ?>" <?php echo $selectedValue === (string) $household['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($household['household_code'] . ' · ' . $household['location'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="chw-user-id">CHW</label>
                <select id="chw-user-id" name="chw_user_id" required>
                    <option value="">Select a CHW</option>
                    <?php foreach ($chws as $chw): ?>
                        <?php $selectedValue = (string) ($formValue('chw_user_id', (string) ($editingVisit['chw_user_id'] ?? ''))); ?>
                        <option value="<?php echo (int) $chw['id']; ?>" <?php echo $selectedValue === (string) $chw['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($chw['full_name'] . ' (' . $chw['worker_code'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="scheduled-at">Scheduled date</label>
                <input id="scheduled-at" name="scheduled_at" type="datetime-local" required value="<?php echo htmlspecialchars($formValue('scheduled_at', $editingVisit['scheduled_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-row">
                <label for="purpose">Purpose</label>
                <input id="purpose" name="purpose" maxlength="150" required value="<?php echo htmlspecialchars($formValue('purpose'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-row">
                <label for="observations">Observations</label>
                <textarea id="observations" name="observations" rows="3"><?php echo htmlspecialchars($formValue('observations'), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
            <div class="form-row">
                <label for="actions-taken">Actions taken</label>
                <textarea id="actions-taken" name="actions_taken" rows="3"><?php echo htmlspecialchars($formValue('actions_taken'), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
            <div class="form-row">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php $statusValue = $formValue('status', $editingVisit['status'] ?? 'scheduled'); ?>
                    <option value="scheduled" <?php echo $statusValue === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                    <option value="completed" <?php echo $statusValue === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="missed" <?php echo $statusValue === 'missed' ? 'selected' : ''; ?>>Missed</option>
                    <option value="rescheduled" <?php echo $statusValue === 'rescheduled' ? 'selected' : ''; ?>>Rescheduled</option>
                </select>
            </div>
            <div class="form-row">
                <label for="completed-at">Completed / updated date</label>
                <input id="completed-at" name="completed_at" type="datetime-local" value="<?php echo htmlspecialchars($formValue('completed_at', $editingVisit['completed_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-row">
                <label for="follow-up-required">
                    <input id="follow-up-required" name="follow_up_required" type="checkbox" value="1" <?php echo ((int) $formValue('follow_up_required', (string) ($editingVisit['follow_up_required'] ?? '0'))) === 1 ? 'checked' : ''; ?>> Follow-up required
                </label>
            </div>
            <button class="button button-primary admin-submit" type="submit"><?php echo $editingVisit !== null ? 'Update visit' : 'Save visit'; ?></button>
            <?php if ($editingVisit !== null): ?>
                <a class="cancel-link" href="visits.php">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="management-panel location-list-panel">
        <div class="panel-heading">
            <p class="eyebrow">Overview</p>
            <h2>Recent activity</h2>
        </div>
        <?php if ($visitList === []): ?>
            <p class="empty-state">No visits have been recorded for the active view.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Household</th>
                            <th>CHW</th>
                            <th>When</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($visitList as $visit): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($visit['household_code'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span class="table-secondary"><?php echo htmlspecialchars($visit['purpose'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($visit['chw_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(date('M j, Y H:i', strtotime($visit['scheduled_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><span class="account-status <?php echo $visit['status'] === 'completed' ? 'status-active' : ($visit['status'] === 'missed' ? 'status-inactive' : 'status-active'); ?>"><?php echo htmlspecialchars($visit['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td>
                                    <a class="table-action" href="visits.php?edit=<?php echo (int) $visit['id']; ?>">Edit</a>
                                    <form method="post" style="display:inline; margin-left:0.45rem;" onsubmit="return confirm('Remove this visit?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="delete_visit" value="<?php echo (int) $visit['id']; ?>">
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
