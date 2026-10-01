<?php
require_once __DIR__ . '/includes/auth.php';

$admin = require_permission('locations.manage');
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim(is_string($_POST['village_code'] ?? null) ? $_POST['village_code'] : '');
    $districtName = trim(is_string($_POST['district_name'] ?? null) ? $_POST['district_name'] : '');
    $sectorName = trim(is_string($_POST['sector_name'] ?? null) ? $_POST['sector_name'] : '');
    $cellName = trim(is_string($_POST['cell_name'] ?? null) ? $_POST['cell_name'] : '');
    $villageName = trim(is_string($_POST['village_name'] ?? null) ? $_POST['village_name'] : '');

    if (!csrf_is_valid()) {
        $errorMessage = 'Your session expired. Refresh the page and try again.';
    } elseif (!preg_match('/^[A-Za-z0-9_-]{1,40}$/', $code)
        || $districtName === '' || strlen($districtName) > 120
        || $sectorName === '' || strlen($sectorName) > 120
        || $cellName === '' || strlen($cellName) > 120
        || $villageName === '' || strlen($villageName) > 120) {
        $errorMessage = 'Enter a valid village code and all four location names (up to 120 characters each).';
    } else {
        $pdo = app_db();
        $pdo->beginTransaction();

        try {
            $findOrCreate = static function (string $table, string $codeValue, string $name, ?string $parentColumn = null, ?int $parentId = null) use ($pdo): int {
                $parentCondition = $parentColumn === null ? '' : "{$parentColumn} = :parent_id AND ";
                $select = $pdo->prepare(
                    "SELECT id, code, name FROM {$table} WHERE {$parentCondition}(code = :code OR name = :name) FOR UPDATE"
                );
                $parameters = ['code' => $codeValue, 'name' => $name];
                if ($parentColumn !== null) {
                    $parameters['parent_id'] = $parentId;
                }
                $select->execute($parameters);
                $existing = $select->fetchAll();

                foreach ($existing as $row) {
                    if ($row['code'] === $codeValue && $row['name'] === $name) {
                        return (int) $row['id'];
                    }
                }
                if ($existing !== []) {
                    throw new DomainException('A location code or name already exists in a different place. Check the entered hierarchy.');
                }

                if ($parentColumn === null) {
                    $insert = $pdo->prepare("INSERT INTO {$table} (code, name) VALUES (:code, :name)");
                    $insert->execute(['code' => $codeValue, 'name' => $name]);
                } else {
                    $insert = $pdo->prepare("INSERT INTO {$table} ({$parentColumn}, code, name) VALUES (:parent_id, :code, :name)");
                    $insert->execute(['parent_id' => $parentId, 'code' => $codeValue, 'name' => $name]);
                }

                return (int) $pdo->lastInsertId();
            };

            $locationCode = static function (string $prefix, string $name, string $scope = ''): string {
                return $prefix . '-' . substr(hash('sha256', $scope . '|' . strtolower($name)), 0, 32);
            };

            $districtId = $findOrCreate('districts', $locationCode('D', $districtName), $districtName);
            $sectorId = $findOrCreate('sectors', $locationCode('S', $sectorName, (string) $districtId), $sectorName, 'district_id', $districtId);
            $cellId = $findOrCreate('cells', $locationCode('C', $cellName, (string) $sectorId), $cellName, 'sector_id', $sectorId);
            $villageId = $findOrCreate('villages', $code, $villageName, 'cell_id', $cellId);

            $audit = $pdo->prepare(
                'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, summary)
                 VALUES (:actor_id, \'location.created\', \'village\', :entity_id, \'Administrative village hierarchy created\')'
            );
            $audit->execute(['actor_id' => $admin['id'], 'entity_id' => $villageId]);
            $pdo->commit();

            $_SESSION['admin_flash'] = ['type' => 'success', 'message' => 'Village and its administrative hierarchy were saved.'];
            header('Location: admin_locations.php');
            exit;
        } catch (DomainException $exception) {
            $pdo->rollBack();
            $errorMessage = $exception->getMessage();
        } catch (PDOException $exception) {
            $pdo->rollBack();
            error_log($exception->getMessage());
            $errorMessage = $exception->getCode() === '23000'
                ? 'That village code or location already exists. Check the location hierarchy.'
                : 'The location could not be saved. Please try again.';
        } catch (Throwable $exception) {
            $pdo->rollBack();
            error_log($exception->getMessage());
            $errorMessage = 'The location could not be saved. Please try again.';
        }
    }
}

$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

$villages = app_db()->query(
    'SELECT v.id, v.code, v.name AS village_name, c.name AS cell_name, s.name AS sector_name, d.name AS district_name,
            COUNT(DISTINCT cva.chw_user_id) AS assigned_worker_count
     FROM villages v
     INNER JOIN cells c ON c.id = v.cell_id
     INNER JOIN sectors s ON s.id = c.sector_id
     INNER JOIN districts d ON d.id = s.district_id
     LEFT JOIN chw_village_assignments cva ON cva.village_id = v.id
     GROUP BY v.id, v.code, v.name, c.name, s.name, d.name
     ORDER BY d.name, s.name, c.name, v.name'
)->fetchAll();
$postedValue = static function (string $key): string {
    return is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
};

$pageTitle = 'Village setup';
require __DIR__ . '/includes/app-header.php';
?>
<section class="management-heading">
    <div>
        <p class="eyebrow">Administration / Locations</p>
        <h1>Village setup</h1>
        <p>Create the area hierarchy before registering workers and assigning villages.</p>
    </div>
    <span class="role-chip"><?php echo count($villages); ?> villages</span>
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
            <p class="eyebrow">Add area</p>
            <h2>Register a village</h2>
            <p>Existing district, sector, and cell names are reused when their hierarchy matches.</p>
        </div>
        <form method="post" class="admin-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="form-row">
                <label for="village-code">Village code</label>
                <input id="village-code" name="village_code" maxlength="40" pattern="[A-Za-z0-9_-]+" required value="<?php echo htmlspecialchars($postedValue('village_code'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-row">
                <label for="district-name">District</label>
                <input id="district-name" name="district_name" maxlength="120" required value="<?php echo htmlspecialchars($postedValue('district_name'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-row">
                <label for="sector-name">Sector</label>
                <input id="sector-name" name="sector_name" maxlength="120" required value="<?php echo htmlspecialchars($postedValue('sector_name'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-row">
                <label for="cell-name">Cell</label>
                <input id="cell-name" name="cell_name" maxlength="120" required value="<?php echo htmlspecialchars($postedValue('cell_name'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-row">
                <label for="village-name">Village</label>
                <input id="village-name" name="village_name" maxlength="120" required value="<?php echo htmlspecialchars($postedValue('village_name'), ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <button class="button button-primary admin-submit" type="submit">Save village</button>
        </form>
    </div>

    <div class="management-panel location-list-panel">
        <div class="panel-heading">
            <p class="eyebrow">Coverage</p>
            <h2>Registered villages</h2>
        </div>
        <?php if ($villages === []): ?>
            <p class="empty-state">No villages registered yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead><tr><th>Village</th><th>Administrative area</th><th>CHWs</th></tr></thead>
                    <tbody>
                    <?php foreach ($villages as $village): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($village['village_name'], ENT_QUOTES, 'UTF-8'); ?></strong><span class="table-secondary"><?php echo htmlspecialchars($village['code'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td><?php echo htmlspecialchars($village['district_name'] . ' / ' . $village['sector_name'] . ' / ' . $village['cell_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo (int) $village['assigned_worker_count']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/includes/app-footer.php'; ?>