<?php
/**
 * Main Entry Point: checks authentication and routes to Dashboard
 */
require_once __DIR__ . '/config/config.php';

if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header("Location: login.php");
    exit;
}

header("Location: pages/dashboard.php");
exit;
?>
