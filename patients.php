<?php
require_once __DIR__ . '/includes/auth.php';

$appUser = require_permission('households.view');
$pdo = app_db();
$canManage = user_has_permission('households.manage');
$errorMessage = '';
$editingId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$editingPatient = null;

if ($editingId !== null) {
    $editStatement = $pdo->prepare(
        'SELECT p.id, p.patient_code, p.household_id, p.display_name, p.contact_phone, p.status, p.registered_by_chw_user_id
         FROM patients p
         WHERE p.id = :patient_id
         LIMIT 1'
    );
    $editStatement->execute(['patient_id' => $editingId]);
    $editingPatient = $editStatement->fetch() ?: null;
    if ($editingPatient === null) {
        http_response_code(404);
        exit('Patient not found.');
    }
}

$viewScope = '1 = 1';
$scopeParams = [];
if ($appUser['role_code'] === 'chw') {
    $viewScope = 'h.assigned_chw_user_id = :chw_user_id';
    $scopeParams['chw_user_id'] = $appUser['id'];
} elseif (in_array($appUser['role_code'], ['health_center_staff', 'health_center_manager'], true)) {
    if ($appUser['health_center_id'] === null) {
        $viewScope = '1 = 0';
    } else {
        $viewScope = 'EXISTS (
            SELECT 1 FROM health_center_villages hcv
            WHERE hcv.village_id = h.village_id AND hcv.health_center_id = :center_id
        )';
        $scopeParams['center_id'] = $appUser['health_center_id'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_patient'])) {
        if (!$canManage) {
            http_response_code(403);
            exit('You do not have permission to manage patient records.');
        }
        $deleteId = filter_var($_POST['delete_patient'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        if ($deleteId === null) {
            $errorMessage = 'The patient to delete was not identified.';
        } else {
            $deleteStatement = $pdo->prepare('DELETE FROM patients WHERE id = :patient_id');
            $deleteStatement->execute(['patient_id' => $deleteId]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Patient removed.'];
            header('Location: patients.php');
            exit;
        }
    }

    if (!$canManage) {
        http_response_code(403);
        exit('You do not have permission to manage patient records.');
    }

    if (!csrf_is_valid()) {
        $errorMessage = 'Your session expired. Refresh the page and try again.';
    } else {
        $patientId = filter_var($_POST['patient_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $patientCode = trim((string) ($_POST['patient_code'] ?? ''));
        $householdId = filter_var($_POST['household_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $displayName = trim((string) ($_POST['display_name'] ?? ''));
        $contactPhone = trim((string) ($_POST['contact_phone'] ?? ''));
        $status = in_array($_POST['status'] ?? 'active', ['active', 'inactive'], true) ? $_POST['status'] : 'active';
        $registeredByChwId = filter_var($_POST['registered_by_chw_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

        if (!preg_match('/^[A-Za-z0-9_-]{1,50}$/', $patientCode)
            || $householdId === null
            || $displayName === '' || strlen($displayName) > 120
            || ($contactPhone !== '' && (strlen($contactPhone) > 30 || !preg_match('/^[0-9+().\-\s]+$/', $contactPhone)))
            || !in_array($status, ['active', 'inactive'], true)
            || ($registeredByChwId !== null && !filter_var($registeredByChwId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]))) {
            $errorMessage = 'Please review the patient details and try again.';
        } else {
            $householdCheck = $pdo->prepare('SELECT 1 FROM households WHERE id = :household_id LIMIT 1');
            $householdCheck->execute(['household_id' => $householdId]);
            if ($householdCheck->fetchColumn() === false) {
                $errorMessage = 'The selected household no longer exists.';
            } elseif ($registeredByChwId !== null) {
                $chwCheck = $pdo->prepare(
                    'SELECT 1 FROM chw_profiles cp INNER JOIN users u ON u.id = cp.user_id WHERE cp.user_id = :chw_user_id AND u.status = :status LIMIT 1'
                );
                $chwCheck->execute(['chw_user_id' => $registeredByChwId, 'status' => 'active']);
                if ($chwCheck->fetchColumn() === false) {
                    $errorMessage = 'The selected CHW is not available for patient registration.';
                }
            }
        }

        if ($errorMessage === '') {
            try {
                if ($patientId !== null) {
                    $statement = $pdo->prepare(
                        'UPDATE patients SET patient_code = :patient_code, household_id = :household_id, display_name = :display_name, contact_phone = :contact_phone, status = :status, registered_by_chw_user_id = :registered_by_chw_user_id WHERE id = :patient_id'
                    );
                    $statement->execute([
                        'patient_code' => $patientCode,
                        'household_id' => $householdId,
                        'display_name' => $displayName,
                        'contact_phone' => $contactPhone !== '' ? $contactPhone : null,
                        'status' => $status,
                        'registered_by_chw_user_id' => $registeredByChwId,
                        'patient_id' => $patientId,
                    ]);
                    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Patient updated successfully.'];
                } else {
                    $statement = $pdo->prepare(
                        'INSERT INTO patients (patient_code, household_id, display_name, contact_phone, status, registered_by_chw_user_id)
                         VALUES (:patient_code, :household_id, :display_name, :contact_phone, :status, :registered_by_chw_user_id)'
                    );
                    $statement->execute([
                        'patient_code' => $patientCode,
                        'household_id' => $householdId,
                        'display_name' => $displayName,
                        'contact_phone' => $contactPhone !== '' ? $contactPhone : null,
                        'status' => $status,
                        'registered_by_chw_user_id' => $registeredByChwId,
                    ]);
                    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Patient saved successfully.'];
                }

                $audit = $pdo->prepare(
                    'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, summary)
                     VALUES (:actor_id, :action, :entity_type, :entity_id, :summary)'
                );
                $audit->execute([
                    'actor_id' => $appUser['id'],
                    'action' => $patientId !== null ? 'patient.updated' : 'patient.created',
                    'entity_type' => 'patient',
                    'entity_id' => $patientId ?? (int) $pdo->lastInsertId(),
                    'summary' => $patientId !== null ? 'Patient updated' : 'Patient registered',
                ]);

                header('Location: patients.php');
                exit;
            } catch (Throwable $exception) {
                error_log($exception->getMessage());
                $errorMessage = $exception->getCode() === '23000'
                    ? 'That patient code is already in use.'
                    : 'The patient could not be saved. Please try again.';
            }
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$patientRows = $pdo->prepare(
    'SELECT p.id, p.patient_code, p.display_name, p.contact_phone, p.status,
            h.household_code, h.household_head_name,
            CONCAT(d.name, " / ", s.name, " / ", c.name, " / ", v.name) AS location,
            u.full_name AS registered_chw_name
     FROM patients p
     INNER JOIN households h ON h.id = p.household_id
     INNER JOIN villages v ON v.id = h.village_id
     INNER JOIN cells c ON c.id = v.cell_id
     INNER JOIN sectors s ON s.id = c.sector_id
     INNER JOIN districts d ON d.id = s.district_id
     LEFT JOIN users u ON u.id = p.registered_by_chw_user_id
     WHERE ' . $viewScope . '
     ORDER BY p.created_at DESC'
);
$patientRows->execute($scopeParams);
$patients = $patientRows->fetchAll();

$households = $pdo->query(
    'SELECT h.id, h.household_code, h.household_head_name, CONCAT(d.name, " / ", s.name, " / ", c.name, " / ", v.name) AS location
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

$formValue = static function (string $field, string $fallback = '') use ($editingPatient): string {
    $postedValue = $_POST[$field] ?? null;
    if (is_string($postedValue)) {
        return $postedValue;
    }
    if ($editingPatient !== null && array_key_exists($field, $editingPatient)) {
        return (string) ($editingPatient[$field] ?? $fallback);
    }
    return $fallback;
};

$pageTitle = 'Patient records';
require __DIR__ . '/includes/app-header.php';
?>
<section class="management-heading">
    <div>
        <p class="eyebrow">Field operations / Patients</p>
        <h1><?php echo $editingPatient !== null ? 'Edit patient' : 'Patient records'; ?></h1>
        <p>Register people attached to households and keep their coverage details current.</p>
    </div>
    <span class="role-chip"><?php echo count($patients); ?> patients</span>
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
            <p class="eyebrow"><?php echo $editingPatient !== null ? 'Update' : 'Register'; ?></p>
            <h2><?php echo $editingPatient !== null ? 'Edit patient' : 'Add patient'; ?></h2>
            <p>Use the patient code and household association to manage the record.</p>
        </div>
        <form method="post" class="admin-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <?php if ($editingPatient !== null): ?>
                <input type="hidden" name="patient_id" value="<?php echo (int) $editingPatient['id']; ?>">
            <?php endif; ?>
            <div class="form-row">
                <label for="patient-code">Patient code</label>
                <input id="patient-code" name="patient_code" maxlength="50" pattern="[A-Za-z0-9_-]+" required value="<?php echo htmlspecialchars($formValue('patient_code'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-row">
                <label for="household-id">Household</label>
                <select id="household-id" name="household_id" required>
                    <option value="">Select a household</option>
                    <?php foreach ($households as $household): ?>
                        <?php $selectedValue = (string) ($formValue('household_id', (string) ($editingPatient['household_id'] ?? ''))); ?>
                        <option value="<?php echo (int) $household['id']; ?>" <?php echo $selectedValue === (string) $household['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($household['household_code'] . ' · ' . $household['household_head_name'] . ' · ' . $household['location'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="display-name">Display name</label>
                <input id="display-name" name="display_name" maxlength="120" required value="<?php echo htmlspecialchars($formValue('display_name'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-row">
                <label for="contact-phone">Contact phone</label>
                <input id="contact-phone" name="contact_phone" maxlength="30" value="<?php echo htmlspecialchars($formValue('contact_phone'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-row">
                <label for="registered-by-chw">Registered by CHW</label>
                <select id="registered-by-chw" name="registered_by_chw_user_id">
                    <option value="">Not specified</option>
                    <?php foreach ($chws as $chw): ?>
                        <?php $selectedValue = (string) ($formValue('registered_by_chw_user_id', (string) ($editingPatient['registered_by_chw_user_id'] ?? ''))); ?>
                        <option value="<?php echo (int) $chw['id']; ?>" <?php echo $selectedValue === (string) $chw['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($chw['full_name'] . ' (' . $chw['worker_code'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php $statusValue = $formValue('status', 'active'); ?>
                    <option value="active" <?php echo $statusValue === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $statusValue === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <button class="button button-primary admin-submit" type="submit"><?php echo $editingPatient !== null ? 'Update patient' : 'Save patient'; ?></button>
            <?php if ($editingPatient !== null): ?>
                <a class="cancel-link" href="patients.php">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="management-panel location-list-panel">
        <div class="panel-heading">
            <p class="eyebrow">Coverage</p>
            <h2>Registered patients</h2>
        </div>
        <?php if ($patients === []): ?>
            <p class="empty-state">No patients are visible for the current role.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Patient</th>
                            <th>Household</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patients as $patient): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($patient['display_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span class="table-secondary"><?php echo htmlspecialchars($patient['patient_code'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars((string) ($patient['household_code'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string) ($patient['location'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><span class="account-status <?php echo $patient['status'] === 'active' ? 'status-active' : 'status-inactive'; ?>"><?php echo htmlspecialchars($patient['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td>
                                    <a class="table-action" href="patients.php?edit=<?php echo (int) $patient['id']; ?>">Edit</a>
                                    <form method="post" style="display:inline; margin-left:0.45rem;" onsubmit="return confirm('Remove this patient?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="delete_patient" value="<?php echo (int) $patient['id']; ?>">
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
