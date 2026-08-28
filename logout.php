<?php
/**
 * Partner Agent Logout
 */
require_once __DIR__ . '/config/config.php';

// Record logout event into user_logs table
if (!empty($_SESSION['user_logged_in'])) {
    recordUserLog(
        'Logout',
        'Partner Portal Session',
        'Partner agent ended portal session and signed out.',
        'auth',
        $_SESSION['user_id'] ?? null,
        'success'
    );
}

$_SESSION = [];
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}
session_destroy();
header("Location: login.php");
exit;
?>
