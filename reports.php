<?php
require_once __DIR__ . '/includes/auth.php';

$appUser = require_permission('reports.view');
$pdo = app_db();
$canExport = user_has_permission('reports.export');

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

if (isset($_GET['export']) && $_GET['export'] === '1') {
    if (!$canExport) {
        http_response_code(403);
        exit('You do not have permission to export reports.');
    }

    $reportRows = $pdo->prepare(
        'SELECT h.household_code, h.status, COUNT(v.id) AS visit_count, SUM(CASE WHEN v.status = "completed" THEN 1 ELSE 0 END) AS completed_visits
         FROM households h
         LEFT JOIN visits v ON v.household_id = h.id
         WHERE ' . $viewScope . '
         GROUP BY h.id, h.household_code, h.status'
    );
    $reportRows->execute($scopeParams);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="community-health-report.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['household_code', 'status', 'visit_count', 'completed_visits']);
    foreach ($reportRows->fetchAll() as $row) {
        fputcsv($output, [$row['household_code'], $row['status'], (int) $row['visit_count'], (int) $row['completed_visits']]);
    }
    fclose($output);
    exit;
}

$summary = [
    'households' => $pdo->prepare('SELECT COUNT(*) FROM households h WHERE ' . $viewScope),
    'visits' => $pdo->prepare('SELECT COUNT(*) FROM visits v INNER JOIN households h ON h.id = v.household_id WHERE ' . $viewScope),
    'completed_visits' => $pdo->prepare('SELECT COUNT(*) FROM visits v INNER JOIN households h ON h.id = v.household_id WHERE v.status = "completed" AND ' . $viewScope),
    'pending_followups' => $pdo->prepare('SELECT COUNT(*) FROM follow_up_appointments f INNER JOIN patients p ON p.id = f.patient_id INNER JOIN households h ON h.id = p.household_id WHERE f.status = "pending" AND ' . $viewScope),
];
foreach ($summary as $query) {
    $query->execute($scopeParams);
}

$householdCount = (int) $summary['households']->fetchColumn();
$visitCount = (int) $summary['visits']->fetchColumn();
$completedVisitCount = (int) $summary['completed_visits']->fetchColumn();
$pendingFollowupCount = (int) $summary['pending_followups']->fetchColumn();

$pageTitle = 'Reports';
require __DIR__ . '/includes/app-header.php';
?>
<section class="management-heading">
    <div>
        <p class="eyebrow">Operations / Reports</p>
        <h1>Operational report</h1>
        <p>Review household activity and export a summary for downstream review.</p>
    </div>
    <?php if ($canExport): ?>
        <a class="button button-primary" href="reports.php?export=1">Export CSV</a>
    <?php endif; ?>
</section>

<section class="metric-grid" aria-label="Operational summary">
    <article class="metric-tile metric-green"><span class="metric-label">Households</span><strong><?php echo number_format($householdCount); ?></strong><span class="metric-footnote">In scope</span></article>
    <article class="metric-tile metric-blue"><span class="metric-label">Visits</span><strong><?php echo number_format($visitCount); ?></strong><span class="metric-footnote">Recorded entries</span></article>
    <article class="metric-tile metric-yellow"><span class="metric-label">Completed</span><strong><?php echo number_format($completedVisitCount); ?></strong><span class="metric-footnote">Finished visits</span></article>
    <article class="metric-tile metric-red"><span class="metric-label">Pending follow-ups</span><strong><?php echo number_format($pendingFollowupCount); ?></strong><span class="metric-footnote">Open actions</span></article>
</section>

<div class="management-panel">
    <div class="panel-heading">
        <p class="eyebrow">Summary</p>
        <h2>Current scope</h2>
    </div>
    <p class="empty-state">This summary reflects the records currently visible to your role and health-center scope.</p>
</div>
<?php require __DIR__ . '/includes/app-footer.php'; ?>
