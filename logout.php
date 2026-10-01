<?php
require_once __DIR__ . '/includes/auth.php';

require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Use the sign-out form to end your session.');
}

if (!csrf_is_valid()) {
    http_response_code(403);
    exit('Your session expired. Refresh the page and try again.');
}

destroy_app_session();
header('Location: login.php');
exit;