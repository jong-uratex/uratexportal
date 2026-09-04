<?php
require_once __DIR__ . '/../config/config.php';
$sidebarStore = getActiveStore();
$currentScript = basename($_SERVER['PHP_SELF']);
?>
<!-- Main Sidebar Container -->
<aside class="main-sidebar sidebar-dark-primary elevation-4">
  <!-- Brand Logo -->
  <a href="dashboard.php" class="brand-link">
    <span class="brand-text font-weight-bolder pl-3" style="color: #fff; font-size: 19px;">
      <span style="color: #FFCC00;">★</span> Backend Portal
    </span>
  </a>

  <!-- Sidebar -->
  <div class="sidebar">
    <!-- Active Store Switcher -->
    <div class="user-panel mt-3 pb-3 mb-3 d-flex flex-column border-secondary">
      <div class="text-xs text-uppercase font-weight-bold text-muted px-3 mb-1" style="color: #94a3b8 !important;">Active Store:</div>
      <div class="px-3">
        <form method="get" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>">
          <select name="switch_store" class="form-control form-control-sm bg-dark text-white border-secondary" onchange="this.form.submit()">
            <option value="business" <?php echo ($sidebarStore['id'] === 'business') ? 'selected' : ''; ?>>Business.uratex (B2B)</option>
            <option value="retail" <?php echo ($sidebarStore['id'] === 'retail') ? 'selected' : ''; ?>>Uratex Retail (Consumer)</option>
          </select>
        </form>
      </div>
      <div class="px-3 mt-2 text-xs text-secondary">
        <i class="fas fa-user-shield text-success mr-1"></i> <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'admin'); ?>
      </div>
    </div>

    <!-- Sidebar Menu -->
    <nav class="mt-2">
      <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
        <li class="nav-item">
          <a href="dashboard.php" class="nav-link <?php echo ($currentScript === 'dashboard.php') ? 'active' : ''; ?>">
            <i class="nav-icon fas fa-home"></i>
            <p>Home (Dashboard)</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="products.php" class="nav-link <?php echo ($currentScript === 'products.php') ? 'active' : ''; ?>">
            <i class="nav-icon fas fa-tag"></i>
            <p>Product SEO</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="collections.php" class="nav-link <?php echo ($currentScript === 'collections.php') ? 'active' : ''; ?>">
            <i class="nav-icon fas fa-layer-group"></i>
            <p>Collections</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="pages.php" class="nav-link <?php echo ($currentScript === 'pages.php') ? 'active' : ''; ?>">
            <i class="nav-icon fas fa-file-alt"></i>
            <p>Pages Manager</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="blogs.php" class="nav-link <?php echo ($currentScript === 'blogs.php') ? 'active' : ''; ?>">
            <i class="nav-icon fas fa-newspaper"></i>
            <p>Blogs & Articles</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="customers.php" class="nav-link <?php echo ($currentScript === 'customers.php') ? 'active' : ''; ?>">
            <i class="nav-icon fas fa-users"></i>
            <p>Retail Customers</p>
          </a>
        </li>
        <li class="nav-header text-uppercase text-secondary font-weight-bold" style="font-size: 11px;">Tools & Utilities</li>
        <li class="nav-item">
          <a href="#" class="nav-link">
            <i class="nav-icon fas fa-exchange-alt"></i>
            <p>URL Redirects</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="#" class="nav-link">
            <i class="nav-icon fas fa-folder"></i>
            <p>Files</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="#" class="nav-link">
            <i class="nav-icon fas fa-code"></i>
            <p>Script Manager</p>
          </a>
        </li>
        <li class="nav-item">
          <a href="user_logs.php" class="nav-link <?php echo ($currentScript === 'user_logs.php') ? 'active' : ''; ?>">
            <i class="nav-icon fas fa-history"></i>
            <p>User Logs</p>
          </a>
        </li>
        <?php if (($_SESSION['user_role'] ?? 'editor') === 'admin'): ?>
        <li class="nav-item">
          <a href="user_management.php" class="nav-link <?php echo ($currentScript === 'user_management.php') ? 'active' : ''; ?>">
            <i class="nav-icon fas fa-users-cog text-warning"></i>
            <p>User Management</p>
          </a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
          <a href="../logout.php" class="nav-link text-danger">
            <i class="nav-icon fas fa-sign-out-alt"></i>
            <p>Logout</p>
          </a>
        </li>
      </ul>
    </nav>
  </div>
</aside>
