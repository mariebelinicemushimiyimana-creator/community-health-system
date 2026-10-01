<?php
require_once __DIR__ . '/includes/auth.php';

$appUser = require_permission('followups.view');
$pdo = app_db();
$canManage = user_has_permission('followups.manage');
$errorMessage = '';
$editingId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$editingFollowUp = null;

if ($editingId !== null) {
    $editStatement = $pdo->prepare(
        'SELECT f.id, f.patient_id, f.assigned_chw_user_id, f.scheduled_at, f.reason, f.status, f.outcome_summary
         FROM follow_up_appointments f
         WHERE f.id = :followup_id
         LIMIT 1'
    );
    $editStatement->execute(['followup_id' => $editingId]);
    $editingFollowUp = $editStatement->fetch() ?: null;
    if ($editingFollowUp === null) {
        http_response_code(404);
        exit('Follow-up not found.');
    }
}

$viewScope = '1 = 1';
$scopeParams = [];
if ($appUser['role_code'] === 'chw') {
    $viewScope = 'f.assigned_chw_user_id = :chw_user_id';
    $scopeParams['chw_user_id'] = $appUser['id'];
} elseif (in_array($appUser['role_code'], ['health_center_staff', 'health_center_manager'], true)) {
    if ($appUser['health_center_id'] === null) {
        $viewScope = '1 = 0';
    } else {
        $viewScope = "EXISTS (
            SELECT 1
            FROM patients p
            INNER JOIN households h ON h.id = p.household_id
            INNER JOIN health_center_villages hcv ON hcv.village_id = h.village_id
            WHERE p.id = f.patient_id AND hcv.health_center_id = :center_id
        )";
        $scopeParams['center_id'] = $appUser['health_center_id'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_followup'])) {
        if (!$canManage) {
            http_response_code(403);
            exit('You do not have permission to manage follow-ups.');
        }
        $deleteId = filter_var($_POST['delete_followup'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        if ($deleteId === null) {
            $errorMessage = 'The follow-up to delete was not identified.';
        } else {
            $deleteStatement = $pdo->prepare('DELETE FROM follow_up_appointments WHERE id = :followup_id');
            $deleteStatement->execute(['followup_id' => $deleteId]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Follow-up removed.'];
            header('Location: followups.php');
            exit;
        }
    }

    if (!$canManage) {
        http_response_code(403);
        exit('You do not have permission to manage follow-ups.');
    }

    if (!csrf_is_valid()) {
        $errorMessage = 'Your session expired. Refresh the page and try again.';
    } else {
        $followUpId = filter_var($_POST['followup_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $patientId = filter_var($_POST['patient_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $assignedChwId = filter_var($_POST['assigned_chw_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $scheduledAt = trim((string) ($_POST['scheduled_at'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $status = in_array($_POST['status'] ?? 'pending', ['pending', 'completed', 'missed', 'rescheduled', 'cancelled'], true) ? $_POST['status'] : 'pending';
        $outcomeSummary = trim((string) ($_POST['outcome_summary'] ?? ''));

        if ($patientId === null || $assignedChwId === null || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $scheduledAt) || $reason === '' || strlen($reason) > 255 || ($outcomeSummary !== '' && strlen($outcomeSummary) > 500)) {
            $errorMessage = 'Please provide a valid patient, CHW, date, and follow-up reason.';
        } else {
            $patientCheck = $pdo->prepare('SELECT 1 FROM patients WHERE id = :patient_id LIMIT 1');
            $patientCheck->execute(['patient_id' => $patientId]);
            if ($patientCheck->fetchColumn() === false) {
                $errorMessage = 'The selected patient no longer exists.';
            }
        }

        if ($errorMessage === '') {
            try {
                if ($followUpId !== null) {
                    $statement = $pdo->prepare(
                        'UPDATE follow_up_appointments SET patient_id = :patient_id, assigned_chw_user_id = :assigned_chw_user_id, scheduled_at = :scheduled_at, reason = :reason, status = :status, outcome_summary = :outcome_summary WHERE id = :followup_id'
                    );
                    $statement->execute([
                        'patient_id' => $patientId,
                        'assigned_chw_user_id' => $assignedChwId,
                        'scheduled_at' => $scheduledAt,
                        'reason' => $reason,
                        'status' => $status,
                        'outcome_summary' => $outcomeSummary !== '' ? $outcomeSummary : null,
                        'followup_id' => $followUpId,
                    ]);
                    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Follow-up updated.'];
                } else {
                    $statement = $pdo->prepare(
                        'INSERT INTO follow_up_appointments (patient_id, scheduled_by_user_id, assigned_chw_user_id, scheduled_at, reason, status, outcome_summary)
                         VALUES (:patient_id, :scheduled_by_user_id, :assigned_chw_user_id, :scheduled_at, :reason, :status, :outcome_summary)'
                    );
                    $statement->execute([
                        'patient_id' => $patientId,
                        'scheduled_by_user_id' => $appUser['id'],
                        'assigned_chw_user_id' => $assignedChwId,
                        'scheduled_at' => $scheduledAt,
                        'reason' => $reason,
                        'status' => $status,
                        'outcome_summary' => $outcomeSummary !== '' ? $outcomeSummary : null,
                    ]);
                    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Follow-up scheduled.'];
                }

                header('Location: followups.php');
                exit;
            } catch (Throwable $exception) {
                error_log($exception->getMessage());
                $errorMessage = 'The follow-up could not be saved. Please try again.';
            }
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$followUpRows = $pdo->prepare(
    'SELECT f.id, f.scheduled_at, f.reason, f.status, p.display_name, h.household_code, u.full_name AS chw_name
     FROM follow_up_appointments f
     INNER JOIN patients p ON p.id = f.patient_id
     INNER JOIN households h ON h.id = p.household_id
     LEFT JOIN users u ON u.id = f.assigned_chw_user_id
     WHERE ' . $viewScope . '
     ORDER BY f.scheduled_at DESC'
);
$followUpRows->execute($scopeParams);
$followUpList = $followUpRows->fetchAll();

$patients = $pdo->query(
    'SELECT p.id, p.display_name, h.household_code, CONCAT(d.name, " / ", s.name, " / ", c.name, " / ", v.name) AS location
     FROM patients p
     INNER JOIN households h ON h.id = p.household_id
     INNER JOIN villages v ON v.id = h.village_id
     INNER JOIN cells c ON c.id = v.cell_id
     INNER JOIN sectors s ON s.id = c.sector_id
     INNER JOIN districts d ON d.id = s.district_id
     ORDER BY p.display_name'
)->fetchAll();

$chws = $pdo->query(
    'SELECT u.id, u.full_name, cp.worker_code
     FROM users u
     INNER JOIN chw_profiles cp ON cp.user_id = u.id
     WHERE u.status = "active"
     ORDER BY u.full_name'
)->fetchAll();

$formValue = static function (string $field, string $fallback = '') use ($editingFollowUp): string {
    $postedValue = $_POST[$field] ?? null;
    if (is_string($postedValue)) {
        return $postedValue;
    }
    if ($editingFollowUp !== null && array_key_exists($field, $editingFollowUp)) {
        return (string) ($editingFollowUp[$field] ?? $fallback);
    }
    return $fallback;
};

$pageTitle = 'Follow-up appointments';
require __DIR__ . '/includes/app-header.php';
?>
<section class="management-heading">
    <div>
        <p class="eyebrow">Field operations / Follow-ups</p>
        <h1><?php echo $editingFollowUp !== null ? 'Edit follow-up' : 'Follow-up appointments'; ?></h1>
        <p>Schedule checks for patients and monitor status changes.</p>
    </div>
    <span class="role-chip"><?php echo count($followUpList); ?> follow-ups</span>
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
            <p class="eyebrow"><?php echo $editingFollowUp !== null ? 'Update' : 'Schedule'; ?></p>
            <h2><?php echo $editingFollowUp !== null ? 'Edit follow-up' : 'New follow-up'; ?></h2>
            <p>Create a patient follow-up with an assigned CHW and administrative reason.</p>
        </div>
        <form method="post" class="admin-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <?php if ($editingFollowUp !== null): ?>
                <input type="hidden" name="followup_id" value="<?php echo (int) $editingFollowUp['id']; ?>">
            <?php endif; ?>
            <div class="form-row">
                <label for="patient-id">Patient</label>
                <select id="patient-id" name="patient_id" required>
                    <option value="">Select a patient</option>
                    <?php foreach ($patients as $patient): ?>
                        <?php $selectedValue = (string) ($formValue('patient_id', (string) ($editingFollowUp['patient_id'] ?? ''))); ?>
                        <option value="<?php echo (int) $patient['id']; ?>" <?php echo $selectedValue === (string) $patient['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($patient['display_name'] . ' · ' . $patient['household_code'] . ' · ' . $patient['location'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="assigned-chw-user-id">Assigned CHW</label>
                <select id="assigned-chw-user-id" name="assigned_chw_user_id" required>
                    <option value="">Select a CHW</option>
                    <?php foreach ($chws as $chw): ?>
                        <?php $selectedValue = (string) ($formValue('assigned_chw_user_id', (string) ($editingFollowUp['assigned_chw_user_id'] ?? ''))); ?>
                        <option value="<?php echo (int) $chw['id']; ?>" <?php echo $selectedValue === (string) $chw['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($chw['full_name'] . ' (' . $chw['worker_code'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="scheduled-at">Scheduled date</label>
                <input id="scheduled-at" name="scheduled_at" type="datetime-local" required value="<?php echo htmlspecialchars($formValue('scheduled_at', $editingFollowUp['scheduled_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-row">
                <label for="reason">Reason</label>
                <input id="reason" name="reason" maxlength="255" required value="<?php echo htmlspecialchars($formValue('reason'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-row">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php $statusValue = $formValue('status', $editingFollowUp['status'] ?? 'pending'); ?>
                    <option value="pending" <?php echo $statusValue === 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="completed" <?php echo $statusValue === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="missed" <?php echo $statusValue === 'missed' ? 'selected' : ''; ?>>Missed</option>
                    <option value="rescheduled" <?php echo $statusValue === 'rescheduled' ? 'selected' : ''; ?>>Rescheduled</option>
                    <option value="cancelled" <?php echo $statusValue === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            <div class="form-row">
                <label for="outcome-summary">Outcome summary</label>
                <textarea id="outcome-summary" name="outcome_summary" rows="3"><?php echo htmlspecialchars($formValue('outcome_summary'), ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
            <button class="button button-primary admin-submit" type="submit"><?php echo $editingFollowUp !== null ? 'Update follow-up' : 'Save follow-up'; ?></button>
            <?php if ($editingFollowUp !== null): ?>
                <a class="cancel-link" href="followups.php">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="management-panel location-list-panel">
        <div class="panel-heading">
            <p class="eyebrow">Queue</p>
            <h2>Upcoming follow-ups</h2>
        </div>
        <?php if ($followUpList === []): ?>
            <p class="empty-state">No follow-ups are visible for the current role.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>CHW</th>
                            <th>Due</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($followUpList as $followUp): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($followUp['display_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span class="table-secondary"><?php echo htmlspecialchars($followUp['household_code'] . ' · ' . $followUp['reason'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars((string) ($followUp['chw_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(date('M j, Y H:i', strtotime($followUp['scheduled_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><span class="account-status <?php echo $followUp['status'] === 'completed' ? 'status-active' : 'status-inactive'; ?>"><?php echo htmlspecialchars($followUp['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td>
                                    <a class="table-action" href="followups.php?edit=<?php echo (int) $followUp['id']; ?>">Edit</a>
                                    <form method="post" style="display:inline; margin-left:0.45rem;" onsubmit="return confirm('Remove this follow-up?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="delete_followup" value="<?php echo (int) $followUp['id']; ?>">
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
