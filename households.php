<?php
require_once __DIR__ . '/includes/auth.php';

$appUser = require_permission('households.view');
$pdo = app_db();
$canManage = user_has_permission('households.manage');
$errorMessage = '';
$editingId = filter_var($_GET['edit'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$editingHousehold = null;

if ($editingId !== null) {
    $editStatement = $pdo->prepare(
        'SELECT h.id, h.household_code, h.village_id, h.assigned_chw_user_id, h.household_head_name, h.contact_phone, h.address_details, h.status
         FROM households h
         WHERE h.id = :household_id
         LIMIT 1'
    );
    $editStatement->execute(['household_id' => $editingId]);
    $editingHousehold = $editStatement->fetch() ?: null;
    if ($editingHousehold === null) {
        http_response_code(404);
        exit('Household not found.');
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
    if (isset($_POST['delete_household'])) {
        if (!$canManage) {
            http_response_code(403);
            exit('You do not have permission to manage households.');
        }
        $deleteId = filter_var($_POST['delete_household'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        if ($deleteId === null) {
            $errorMessage = 'The household to delete was not identified.';
        } else {
            $deleteStatement = $pdo->prepare('DELETE FROM households WHERE id = :household_id');
            $deleteStatement->execute(['household_id' => $deleteId]);
            $_SESSION['flash'] = ['type' => 'success', 'message' => 'Household removed.'];
            header('Location: households.php');
            exit;
        }
    }

    if (!$canManage) {
        http_response_code(403);
        exit('You do not have permission to manage households.');
    }

    if (!csrf_is_valid()) {
        $errorMessage = 'Your session expired. Refresh the page and try again.';
    } else {
        $householdId = filter_var($_POST['household_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $householdCode = trim((string) ($_POST['household_code'] ?? ''));
        $villageId = filter_var($_POST['village_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $assignedChwId = filter_var($_POST['assigned_chw_user_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
        $headName = trim((string) ($_POST['household_head_name'] ?? ''));
        $contactPhone = trim((string) ($_POST['contact_phone'] ?? ''));
        $addressDetails = trim((string) ($_POST['address_details'] ?? ''));
        $status = in_array($_POST['status'] ?? 'active', ['active', 'inactive'], true) ? $_POST['status'] : 'active';

        if (!preg_match('/^[A-Za-z0-9_-]{1,50}$/', $householdCode)
            || $villageId === null
            || ($assignedChwId !== null && !preg_match('/^[0-9]+$/', (string) $assignedChwId))
            || ($headName !== '' && strlen($headName) > 120)
            || ($contactPhone !== '' && (strlen($contactPhone) > 30 || !preg_match('/^[0-9+().\-\s]+$/', $contactPhone)))
            || ($addressDetails !== '' && strlen($addressDetails) > 255)
            || !in_array($status, ['active', 'inactive'], true)) {
            $errorMessage = 'Please review the household details and try again.';
        } else {
            $villageCheck = $pdo->prepare('SELECT 1 FROM villages WHERE id = :village_id LIMIT 1');
            $villageCheck->execute(['village_id' => $villageId]);
            if ($villageCheck->fetchColumn() === false) {
                $errorMessage = 'The selected village could not be found.';
            } else {
                if ($assignedChwId !== null) {
                    $chwCheck = $pdo->prepare(
                        'SELECT 1 FROM chw_profiles cp INNER JOIN users u ON u.id = cp.user_id WHERE cp.user_id = :chw_user_id AND u.status = :status LIMIT 1'
                    );
                    $chwCheck->execute(['chw_user_id' => $assignedChwId, 'status' => 'active']);
                    if ($chwCheck->fetchColumn() === false) {
                        $errorMessage = 'The selected CHW is not available for assignment.';
                    }
                }
            }
        }

        if ($errorMessage === '') {
            try {
                if ($householdId !== null) {
                    $statement = $pdo->prepare(
                        'UPDATE households SET household_code = :household_code, village_id = :village_id, assigned_chw_user_id = :assigned_chw_user_id,
                         household_head_name = :household_head_name, contact_phone = :contact_phone, address_details = :address_details, status = :status
                         WHERE id = :household_id'
                    );
                    $statement->execute([
                        'household_code' => $householdCode,
                        'village_id' => $villageId,
                        'assigned_chw_user_id' => $assignedChwId,
                        'household_head_name' => $headName !== '' ? $headName : null,
                        'contact_phone' => $contactPhone !== '' ? $contactPhone : null,
                        'address_details' => $addressDetails !== '' ? $addressDetails : null,
                        'status' => $status,
                        'household_id' => $householdId,
                    ]);
                    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Household updated successfully.'];
                } else {
                    $statement = $pdo->prepare(
                        'INSERT INTO households (household_code, village_id, assigned_chw_user_id, household_head_name, contact_phone, address_details, status, created_by_user_id)
                         VALUES (:household_code, :village_id, :assigned_chw_user_id, :household_head_name, :contact_phone, :address_details, :status, :created_by_user_id)'
                    );
                    $statement->execute([
                        'household_code' => $householdCode,
                        'village_id' => $villageId,
                        'assigned_chw_user_id' => $assignedChwId,
                        'household_head_name' => $headName !== '' ? $headName : null,
                        'contact_phone' => $contactPhone !== '' ? $contactPhone : null,
                        'address_details' => $addressDetails !== '' ? $addressDetails : null,
                        'status' => $status,
                        'created_by_user_id' => $appUser['id'],
                    ]);
                    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Household saved successfully.'];
                }

                $audit = $pdo->prepare(
                    'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, summary)
                     VALUES (:actor_id, :action, :entity_type, :entity_id, :summary)'
                );
                $audit->execute([
                    'actor_id' => $appUser['id'],
                    'action' => $householdId !== null ? 'household.updated' : 'household.created',
                    'entity_type' => 'household',
                    'entity_id' => $householdId ?? (int) $pdo->lastInsertId(),
                    'summary' => $householdId !== null ? 'Household updated' : 'Household registered',
                ]);

                header('Location: households.php');
                exit;
            } catch (Throwable $exception) {
                error_log($exception->getMessage());
                $errorMessage = $exception->getCode() === '23000'
                    ? 'That household code is already in use.'
                    : 'The household could not be saved. Please try again.';
            }
        }
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$households = $pdo->prepare(
    'SELECT h.id, h.household_code, h.household_head_name, h.contact_phone, h.address_details, h.status,
            v.name AS village_name, d.name AS district_name, s.name AS sector_name, c.name AS cell_name,
            u.full_name AS assigned_chw_name
     FROM households h
     INNER JOIN villages v ON v.id = h.village_id
     INNER JOIN cells c ON c.id = v.cell_id
     INNER JOIN sectors s ON s.id = c.sector_id
     INNER JOIN districts d ON d.id = s.district_id
     LEFT JOIN users u ON u.id = h.assigned_chw_user_id
     WHERE ' . $viewScope . '
     ORDER BY h.created_at DESC'
);
$households->execute($scopeParams);
$householdRows = $households->fetchAll();

$villages = $pdo->query(
    'SELECT v.id, v.name, c.name AS cell_name, s.name AS sector_name, d.name AS district_name
     FROM villages v
     INNER JOIN cells c ON c.id = v.cell_id
     INNER JOIN sectors s ON s.id = c.sector_id
     INNER JOIN districts d ON d.id = s.district_id
     ORDER BY d.name, s.name, c.name, v.name'
)->fetchAll();

$chws = $pdo->query(
    'SELECT u.id, u.full_name, cp.worker_code
     FROM users u
     INNER JOIN chw_profiles cp ON cp.user_id = u.id
     WHERE u.status = "active"
     ORDER BY u.full_name'
)->fetchAll();

$formValue = static function (string $field, string $fallback = '') use ($editingHousehold): string {
    $postedValue = $_POST[$field] ?? null;
    if (is_string($postedValue)) {
        return $postedValue;
    }
    if ($editingHousehold !== null && array_key_exists($field, $editingHousehold)) {
        return (string) ($editingHousehold[$field] ?? $fallback);
    }
    return $fallback;
};

$pageTitle = 'Household records';
require __DIR__ . '/includes/app-header.php';
?>
<section class="management-heading">
    <div>
        <p class="eyebrow">Field operations / Households</p>
        <h1><?php echo $editingHousehold !== null ? 'Edit household' : 'Household records'; ?></h1>
        <p>Track households, assigned coverage, and contact details.</p>
    </div>
    <span class="role-chip"><?php echo count($householdRows); ?> households</span>
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
            <p class="eyebrow"><?php echo $editingHousehold !== null ? 'Update' : 'Register'; ?></p>
            <h2><?php echo $editingHousehold !== null ? 'Edit household' : 'Add household'; ?></h2>
            <p>Use the household code and village assignment to manage the record.</p>
        </div>
        <form method="post" class="admin-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <?php if ($editingHousehold !== null): ?>
                <input type="hidden" name="household_id" value="<?php echo (int) $editingHousehold['id']; ?>">
            <?php endif; ?>
            <div class="form-row">
                <label for="household-code">Household code</label>
                <input id="household-code" name="household_code" maxlength="50" pattern="[A-Za-z0-9_-]+" required value="<?php echo htmlspecialchars($formValue('household_code'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-row">
                <label for="village-id">Village</label>
                <select id="village-id" name="village_id" required>
                    <option value="">Select a village</option>
                    <?php foreach ($villages as $village): ?>
                        <?php $selectedValue = (string) ($formValue('village_id', (string) ($editingHousehold['village_id'] ?? ''))); ?>
                        <option value="<?php echo (int) $village['id']; ?>" <?php echo $selectedValue === (string) $village['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($village['district_name'] . ' / ' . $village['sector_name'] . ' / ' . $village['cell_name'] . ' / ' . $village['name'], ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="assigned-chw">Assigned CHW</label>
                <select id="assigned-chw" name="assigned_chw_user_id">
                    <option value="">Unassigned</option>
                    <?php foreach ($chws as $chw): ?>
                        <?php $selectedValue = (string) ($formValue('assigned_chw_user_id', (string) ($editingHousehold['assigned_chw_user_id'] ?? ''))); ?>
                        <option value="<?php echo (int) $chw['id']; ?>" <?php echo $selectedValue === (string) $chw['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($chw['full_name'] . ' (' . $chw['worker_code'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="household-head-name">Household head</label>
                <input id="household-head-name" name="household_head_name" maxlength="120" value="<?php echo htmlspecialchars($formValue('household_head_name'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-row">
                <label for="contact-phone">Contact phone</label>
                <input id="contact-phone" name="contact_phone" maxlength="30" value="<?php echo htmlspecialchars($formValue('contact_phone'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-row">
                <label for="address-details">Address details</label>
                <input id="address-details" name="address_details" maxlength="255" value="<?php echo htmlspecialchars($formValue('address_details'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-row">
                <label for="status">Status</label>
                <select id="status" name="status">
                    <?php $statusValue = $formValue('status', 'active'); ?>
                    <option value="active" <?php echo $statusValue === 'active' ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo $statusValue === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                </select>
            </div>
            <button class="button button-primary admin-submit" type="submit"><?php echo $editingHousehold !== null ? 'Update household' : 'Save household'; ?></button>
            <?php if ($editingHousehold !== null): ?>
                <a class="cancel-link" href="households.php">Cancel</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="management-panel location-list-panel">
        <div class="panel-heading">
            <p class="eyebrow">Coverage</p>
            <h2>Registered households</h2>
        </div>
        <?php if ($householdRows === []): ?>
            <p class="empty-state">No households are visible for the current role.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Household</th>
                            <th>Village</th>
                            <th>Assigned CHW</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($householdRows as $household): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($household['household_code'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span class="table-secondary"><?php echo htmlspecialchars((string) ($household['household_head_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($household['district_name'] . ' / ' . $household['sector_name'] . ' / ' . $household['cell_name'] . ' / ' . $household['village_name'], ENT_QUOTES, 'UTF-8'); ?>
                                </td>
                                <td><?php echo htmlspecialchars((string) ($household['assigned_chw_name'] ?? 'Unassigned'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><span class="account-status <?php echo $household['status'] === 'active' ? 'status-active' : 'status-inactive'; ?>"><?php echo htmlspecialchars($household['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td>
                                    <a class="table-action" href="households.php?edit=<?php echo (int) $household['id']; ?>">Edit</a>
                                    <form method="post" style="display:inline; margin-left:0.45rem;" onsubmit="return confirm('Remove this household?');">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="delete_household" value="<?php echo (int) $household['id']; ?>">
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
