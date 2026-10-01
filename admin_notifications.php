<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/SmsService.php';

$admin = require_permission('notifications.manage');
$pdo = app_db();
$errorMessage = '';
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$recipients = $pdo->query(
    'SELECT u.id, u.full_name, u.phone, cp.worker_code
     FROM users u
     INNER JOIN chw_profiles cp ON cp.user_id = u.id
     WHERE u.status = "active"
     ORDER BY u.full_name'
)->fetchAll();

$notificationHistory = $pdo->query(
    'SELECT n.id, n.message_body, n.notification_type, n.channel, n.mode, n.status, n.sent_at, u.full_name AS recipient_name
     FROM notifications n
     INNER JOIN users u ON u.id = n.recipient_user_id
     ORDER BY n.created_at DESC LIMIT 25'
)->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid()) {
        $errorMessage = 'Your session expired. Refresh the page and try again.';
    } else {
        $recipientIds = $_POST['recipient_ids'] ?? [];
        $notificationType = trim((string) ($_POST['notification_type'] ?? ''));
        $messageBody = trim((string) ($_POST['message_body'] ?? ''));
        $mode = in_array($_POST['mode'] ?? 'demonstration', ['provider', 'demonstration'], true) ? $_POST['mode'] : 'demonstration';
        $confirmSend = isset($_POST['confirm_send']) && $_POST['confirm_send'] === '1';

        $normalizedRecipients = [];
        if (is_array($recipientIds)) {
            foreach ($recipientIds as $value) {
                $recipientId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($recipientId !== false) {
                    $normalizedRecipients[] = (int) $recipientId;
                }
            }
            $normalizedRecipients = array_values(array_unique($normalizedRecipients));
        }

        if ($notificationType === '' || !in_array($notificationType, ['visit_reminder', 'follow_up_reminder', 'overdue_follow_up_alert'], true)
            || $messageBody === '' || mb_strlen($messageBody, 'UTF-8') > 160
            || $normalizedRecipients === []
            || !$confirmSend) {
            $errorMessage = 'Select at least one CHW, confirm the message, choose a notification type, and include a message up to 160 characters.';
        } else {
            $placeholders = implode(',', array_fill(0, count($normalizedRecipients), '?'));
            $recipientQuery = $pdo->prepare(
                'SELECT u.id, u.full_name, u.phone
                 FROM users u
                 INNER JOIN chw_profiles cp ON cp.user_id = u.id
                 WHERE u.id IN (' . $placeholders . ') AND u.status = "active"'
            );
            $recipientQuery->execute($normalizedRecipients);
            $validRecipients = $recipientQuery->fetchAll();

            if ($validRecipients === []) {
                $errorMessage = 'No valid active CHW recipients were selected.';
            } else {
                $pdo->beginTransaction();
                try {
                    $insert = $pdo->prepare(
                        'INSERT INTO notifications (recipient_user_id, notification_type, channel, mode, status, message_body, provider_reference, delivery_result, sent_at)
                         VALUES (:recipient_user_id, :notification_type, :channel, :mode, :status, :message_body, :provider_reference, :delivery_result, NOW())'
                    );

                    $sentCount = 0;
                    foreach ($validRecipients as $recipient) {
                        $phone = isset($recipient['phone']) ? trim((string) $recipient['phone']) : '';
                        if ($phone === '') {
                            $delivery = [
                                'status' => 'failed',
                                'provider' => 'none',
                                'reference' => null,
                                'message' => 'No phone number on file for this CHW.',
                            ];
                        } else {
                            $delivery = SmsService::send($phone, $messageBody, $mode);
                        }

                        $notificationStatus = $delivery['status'] === 'sent' ? 'sent' : ($delivery['status'] === 'simulated' ? 'simulated' : 'failed');
                        $insert->execute([
                            'recipient_user_id' => (int) $recipient['id'],
                            'notification_type' => $notificationType,
                            'channel' => 'sms',
                            'mode' => $mode,
                            'status' => $notificationStatus,
                            'message_body' => $messageBody,
                            'provider_reference' => $delivery['reference'] ?? null,
                            'delivery_result' => $delivery['message'] ?? 'SMS delivery attempt recorded.',
                        ]);

                        if ($notificationStatus === 'sent' || $notificationStatus === 'simulated') {
                            $sentCount++;
                        }
                    }

                    $pdo->commit();
                    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Notification recorded for ' . $sentCount . ' CHW(s).'];
                    header('Location: admin_notifications.php');
                    exit;
                } catch (Throwable $exception) {
                    $pdo->rollBack();
                    error_log($exception->getMessage());
                    $errorMessage = 'The notification could not be sent. Please try again.';
                }
            }
        }
    }
}

$pageTitle = 'Send notifications';
require __DIR__ . '/includes/app-header.php';
?>
<section class="management-heading">
    <div>
        <p class="eyebrow">Administration / Messaging</p>
        <h1>Send SMS notifications</h1>
        <p>Dispatch reminders to CHWs using the phone number already stored on each CHW profile.</p>
    </div>
    <span class="role-chip"><?php echo count($recipients); ?> CHWs</span>
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
            <p class="eyebrow">Compose</p>
            <h2>Send message</h2>
        </div>

        <form method="post" class="admin-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">

            <div class="form-row">
                <label>Recipients</label>
                <div class="village-options">
                    <?php foreach ($recipients as $recipient): ?>
                        <label class="village-option">
                            <input type="checkbox" name="recipient_ids[]" value="<?php echo (int) $recipient['id']; ?>">
                            <span>
                                <strong><?php echo htmlspecialchars($recipient['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                <small><?php echo htmlspecialchars($recipient['worker_code'], ENT_QUOTES, 'UTF-8'); ?></small>
                                <small><?php echo htmlspecialchars($recipient['phone'] !== null && $recipient['phone'] !== '' ? $recipient['phone'] : 'No phone number on file', ENT_QUOTES, 'UTF-8'); ?></small>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-row">
                <label for="notification-type">Notification type</label>
                <select id="notification-type" name="notification_type" required>
                    <option value="">Select type</option>
                    <option value="visit_reminder">Visit reminder</option>
                    <option value="follow_up_reminder">Follow-up reminder</option>
                    <option value="overdue_follow_up_alert">Overdue follow-up alert</option>
                </select>
            </div>

            <div class="form-row">
                <label for="mode">Delivery mode</label>
                <select id="mode" name="mode">
                    <option value="demonstration">Demonstration SMS</option>
                    <option value="provider">Provider SMS</option>
                </select>
            </div>

            <div class="form-row">
                <label for="message-body">Message</label>
                <textarea id="message-body" name="message_body" rows="4" maxlength="160" required placeholder="Send a reminder to the CHW team"></textarea>
            </div>

            <label class="checkbox-row">
                <input type="checkbox" name="confirm_send" value="1">
                <span>I confirm the recipients and message are correct before sending.</span>
            </label>

            <button class="button button-primary admin-submit" type="submit">Send notification</button>
        </form>
    </div>

    <div class="management-panel location-list-panel">
        <div class="panel-heading">
            <p class="eyebrow">Recent</p>
            <h2>Delivery log</h2>
        </div>

        <?php if ($notificationHistory === []): ?>
            <p class="empty-state">No messages have been sent yet.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Recipient</th>
                            <th>Type</th>
                            <th>Mode</th>
                            <th>Status</th>
                            <th>Message</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($notificationHistory as $item): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($item['recipient_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(str_replace('_', ' ', $item['notification_type']), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($item['mode'], ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><span class="account-status <?php echo $item['status'] === 'sent' || $item['status'] === 'simulated' ? 'status-active' : 'status-inactive'; ?>"><?php echo htmlspecialchars($item['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                                <td><?php echo htmlspecialchars($item['message_body'], ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/includes/app-footer.php'; ?>
