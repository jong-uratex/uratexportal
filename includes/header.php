<?php
require_once __DIR__ . '/../config/config.php';
$headerStore = getActiveStore();
$currentScript = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?php echo $pageTitle ?? 'SEO Module'; ?> - Uratex Shopify Portal</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Source+Sans+Pro:300,400,400i,700&display=fallback">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
  <style>
    :root {
      --uratex-blue: #003399;
      --uratex-navy: #002266;
      --uratex-yellow: #FFCC00;
      --sidebar-dark: #0f172a;
    }
    .main-sidebar {
      background-color: var(--sidebar-dark) !important;
    }
    .brand-link {
      background-color: var(--uratex-navy) !important;
      border-bottom: 2px solid var(--uratex-yellow) !important;
    }
    .btn-uratex-sync {
      background-color: var(--uratex-yellow);
      color: #002277;
      font-weight: 600;
      border: 1px solid #e6b800;
    }
    .btn-uratex-sync:hover {
      background-color: #e6b800;
      color: #001a5e;
    }
    .seo-card-badge {
      font-size: 11px;
      padding: 4px 8px;
      border-radius: 4px;
      font-weight: 700;
    }
  </style>
</head>
<body class="hold-transition sidebar-mini layout-fixed">
<div class="wrapper">

  <!-- Navbar -->
  <nav class="main-header navbar navbar-expand navbar-white navbar-light border-bottom">
    <!-- Left navbar links -->
    <ul class="navbar-nav">
      <li class="nav-item">
        <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
      </li>
      <li class="nav-item d-none d-sm-inline-block">
        <span class="nav-link text-muted font-weight-bold">
          Active Store: <span class="badge badge-primary px-2 py-1" style="background-color: #003399;"><?php echo htmlspecialchars($headerStore['name'] ?? 'Uratex Store'); ?></span>
        </span>
      </li>
    </ul>

    <!-- Right navbar links -->
    <ul class="navbar-nav ml-auto">
      <li class="nav-item">
        <span class="badge badge-success mt-2 mr-2 px-2 py-1"><i class="fas fa-check-circle mr-1"></i> API 2025-10 Connected</span>
      </li>
      <li class="nav-item dropdown">
        <a class="nav-link" data-toggle="dropdown" href="#">
          <i class="far fa-user-circle fa-lg"></i>
          <span class="d-none d-md-inline ml-1 font-weight-bold"><?php echo htmlspecialchars($_SESSION['user_email'] ?? 'jenor.ricafort@uratex.com.ph'); ?></span>
        </a>
        <div class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
          <div class="dropdown-header text-center">
            <strong>Partner SEO Specialist</strong>
          </div>
          <div class="dropdown-divider"></div>
          <a href="../logout.php" class="dropdown-item text-danger">
            <i class="fas fa-sign-out-alt mr-2"></i> Logout
          </a>
        </div>
      </li>
    </ul>
  </nav>
  <!-- /.navbar -->
