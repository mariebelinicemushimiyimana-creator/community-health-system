<?php
require_once __DIR__ . '/includes/auth.php';

$appUser = require_permission('notifications.view');
$pdo = app_db();

$viewScope = '1 = 1';
$scopeParams = [];
if ($appUser['role_code'] === 'chw') {
    $viewScope = 'n.recipient_user_id = :recipient_id';
    $scopeParams['recipient_id'] = $appUser['id'];
} elseif (in_array($appUser['role_code'], ['health_center_staff', 'health_center_manager'], true)) {
    if ($appUser['health_center_id'] === null) {
        $viewScope = '1 = 0';
    } else {
        $viewScope = "EXISTS (
            SELECT 1
            FROM users u2
            INNER JOIN chw_village_assignments cva ON cva.chw_user_id = u2.id
            INNER JOIN health_center_villages hcv ON hcv.village_id = cva.village_id
            WHERE u2.id = n.recipient_user_id AND hcv.health_center_id = :center_id
        ) OR n.recipient_user_id = :current_user_id";
        $scopeParams['center_id'] = $appUser['health_center_id'];
        $scopeParams['current_user_id'] = $appUser['id'];
    }
}

$notificationRows = $pdo->prepare(
    'SELECT n.id, n.notification_type, n.channel, n.mode, n.status, n.message_body, n.sent_at, u.full_name AS recipient_name
     FROM notifications n
     INNER JOIN users u ON u.id = n.recipient_user_id
     WHERE ' . $viewScope . '
     ORDER BY n.created_at DESC'
);
$notificationRows->execute($scopeParams);
$notifications = $notificationRows->fetchAll();

$pageTitle = 'Notifications';
require __DIR__ . '/includes/app-header.php';
?>
<section class="management-heading">
    <div>
        <p class="eyebrow">Operations / Messaging</p>
        <h1>Notification records</h1>
        <p>Review SMS and provider notifications generated for the field team.</p>
    </div>
    <span class="role-chip"><?php echo count($notifications); ?> records</span>
</section>

<?php if ($notifications === []): ?>
    <p class="empty-state">No notification records are available for the selected scope.</p>
<?php else: ?>
    <div class="management-panel">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Recipient</th>
                        <th>Type</th>
                        <th>Channel</th>
                        <th>Status</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($notifications as $notification): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($notification['recipient_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($notification['notification_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars($notification['channel'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><span class="account-status <?php echo $notification['status'] === 'sent' || $notification['status'] === 'simulated' ? 'status-active' : 'status-inactive'; ?>"><?php echo htmlspecialchars($notification['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                            <td><?php echo htmlspecialchars($notification['message_body'], ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/includes/app-footer.php'; ?>
