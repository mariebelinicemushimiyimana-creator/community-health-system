<?php
require_once __DIR__ . '/includes/auth.php';

$appUser = require_permission('dashboard.view');
$pdo = app_db();
$roleCode = $appUser['role_code'];
$householdScope = '1 = 1';
$visitScope = '1 = 1';
$followupScope = '1 = 1';
$householdParams = [];
$visitParams = [];
$followupParams = [];

if ($roleCode === 'chw') {
    $householdScope = 'h.assigned_chw_user_id = :chw_id';
    $visitScope = 'v.chw_user_id = :chw_id';
    $followupScope = 'f.assigned_chw_user_id = :chw_id';
    $householdParams = $visitParams = $followupParams = ['chw_id' => $appUser['id']];
} elseif (in_array($roleCode, ['health_center_staff', 'health_center_manager'], true)) {
    if ($appUser['health_center_id'] === null) {
        $householdScope = $visitScope = $followupScope = '1 = 0';
    } else {
        $householdScope = 'EXISTS (
            SELECT 1 FROM health_center_villages hcv
            WHERE hcv.village_id = h.village_id AND hcv.health_center_id = :center_id
        )';
        $visitScope = 'EXISTS (
            SELECT 1 FROM households h
            INNER JOIN health_center_villages hcv ON hcv.village_id = h.village_id
            WHERE h.id = v.household_id AND hcv.health_center_id = :center_id
        )';
        $followupScope = 'EXISTS (
            SELECT 1 FROM patients p
            INNER JOIN households h ON h.id = p.household_id
            INNER JOIN health_center_villages hcv ON hcv.village_id = h.village_id
            WHERE p.id = f.patient_id AND hcv.health_center_id = :center_id
        )';
        $householdParams = $visitParams = $followupParams = ['center_id' => $appUser['health_center_id']];
    }
}

$getCount = static function (string $sql, array $parameters = []) use ($pdo): int {
    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    return (int) $statement->fetchColumn();
};

$householdCount = $getCount("SELECT COUNT(*) FROM households h WHERE {$householdScope}", $householdParams);
$completedVisitCount = $getCount("SELECT COUNT(*) FROM visits v WHERE v.status = 'completed' AND {$visitScope}", $visitParams);
$scheduledVisitCount = $getCount("SELECT COUNT(*) FROM visits v WHERE v.status = 'scheduled' AND {$visitScope}", $visitParams);
$missedVisitCount = $getCount("SELECT COUNT(*) FROM visits v WHERE v.status = 'missed' AND {$visitScope}", $visitParams);
$pendingFollowupCount = $getCount("SELECT COUNT(*) FROM follow_up_appointments f WHERE f.status = 'pending' AND {$followupScope}", $followupParams);
$overdueFollowupCount = $getCount("SELECT COUNT(*) FROM follow_up_appointments f WHERE f.status = 'pending' AND f.scheduled_at < NOW() AND {$followupScope}", $followupParams);

if ($roleCode === 'chw') {
    $workerCount = 1;
    $workerLabel = 'My CHW account';
} else {
    $workerScope = '';
    $workerParams = [];
    if (in_array($roleCode, ['health_center_staff', 'health_center_manager'], true)) {
        $workerScope = ' AND EXISTS (
            SELECT 1 FROM chw_village_assignments cva
            INNER JOIN health_center_villages hcv ON hcv.village_id = cva.village_id
            WHERE cva.chw_user_id = cp.user_id AND hcv.health_center_id = :center_id
        )';
        $workerParams['center_id'] = $appUser['health_center_id'];
    }
    $workerCount = $getCount(
        "SELECT COUNT(*) FROM chw_profiles cp INNER JOIN users u ON u.id = cp.user_id WHERE u.status = 'active'{$workerScope}",
        $workerParams
    );
    $workerLabel = 'Active CHWs';
}

$pageTitle = 'Dashboard';
require __DIR__ . '/includes/app-header.php';
?>
<section class="dashboard-heading" aria-labelledby="dashboard-title">
    <div>
        <p class="eyebrow">Field operations / Overview</p>
        <h1 id="dashboard-title">Good to see you, <?php echo htmlspecialchars($appUser['full_name'], ENT_QUOTES, 'UTF-8'); ?>.</h1>
        <p class="dashboard-subtitle">Here is the current activity within your authorized area.</p>
    </div>
    <span class="role-chip"><?php echo htmlspecialchars($appUser['role_name'], ENT_QUOTES, 'UTF-8'); ?></span>
</section>

<section class="metric-grid" aria-label="Community health activity totals">
    <article class="metric-tile metric-green"><span class="metric-label">Registered households</span><strong><?php echo number_format($householdCount); ?></strong><span class="metric-footnote">Within your assigned area</span></article>
    <article class="metric-tile metric-yellow"><span class="metric-label"><?php echo htmlspecialchars($workerLabel, ENT_QUOTES, 'UTF-8'); ?></span><strong><?php echo number_format($workerCount); ?></strong><span class="metric-footnote">Active accounts</span></article>
    <article class="metric-tile metric-blue"><span class="metric-label">Visits completed</span><strong><?php echo number_format($completedVisitCount); ?></strong><span class="metric-footnote">Recorded activity</span></article>
    <article class="metric-tile metric-neutral"><span class="metric-label">Visits scheduled</span><strong><?php echo number_format($scheduledVisitCount); ?></strong><span class="metric-footnote">Awaiting completion</span></article>
    <article class="metric-tile metric-red"><span class="metric-label">Visits missed</span><strong><?php echo number_format($missedVisitCount); ?></strong><span class="metric-footnote">Needs review</span></article>
    <article class="metric-tile metric-yellow"><span class="metric-label">Pending follow-ups</span><strong><?php echo number_format($pendingFollowupCount); ?></strong><span class="metric-footnote">Scheduled appointments</span></article>
    <article class="metric-tile metric-red"><span class="metric-label">Overdue follow-ups</span><strong><?php echo number_format($overdueFollowupCount); ?></strong><span class="metric-footnote">Past their scheduled date</span></article>
</section>

<section class="dashboard-note" aria-labelledby="workspace-status-title">
    <div class="note-index" aria-hidden="true">i</div>
    <div>
        <h2 id="workspace-status-title">Tracking workspace</h2>
        <p>The core account and location foundation is live. Household, visit, follow-up, notification, and reporting workflows are now active in the operational workspace.</p>
    </div>
</section>
<?php require __DIR__ . '/includes/app-footer.php'; ?>