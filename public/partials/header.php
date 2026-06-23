<?php
// Call session_start() before including this file
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', '1800');
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
    session_start();
}
$csrfToken = $_SESSION['csrf_token'] ?? '';
$pageTitle = $pageTitle ?? 'BRS';
?>
<!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?> — BRS</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="/brs/public/assets/css/app.css">
<script>window.CSRF_TOKEN = <?= json_encode($csrfToken) ?>;</script>
<script src="/brs/public/assets/js/api.js"></script>
</head>
<body>
