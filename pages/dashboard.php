<?php
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_logged_in'])) {
    header("Location: ../login.php");
    exit;
}

if (isset($_GET['switch_store'])) {
    setActiveStore($_GET['switch_store']);
    header("Location: dashboard.php");
    exit;
}

$pageTitle = 'Dashboard - SEO Health & Analytics';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
  <!-- Content Header (Page header) -->
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1 class="m-0 font-weight-bold" style="color: #003399;">SEO Health & Analytics Dashboard</h1>
          <p class="text-muted small mb-0">Real-time health scores for Pages, Collections, Products, and Blogs.</p>
        </div>
        <div class="col-sm-6 text-right">
          <button class="btn btn-uratex-sync mr-2"><i class="fas fa-sync-alt mr-1"></i> Sync from Shopify</button>
          <button class="btn btn-success"><i class="fas fa-check-double mr-1"></i> Bulk Approve & Push</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Main content -->
  <section class="content">
    <div class="container-fluid">
      <!-- Info boxes -->
      <div class="row">
        <div class="col-12 col-sm-6 col-md-3">
          <div class="info-box shadow-sm">
            <span class="info-box-icon bg-info elevation-1"><i class="fas fa-tag"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Product SEO Score</span>
              <span class="info-box-number font-weight-bold text-success">88% <small class="text-muted">Avg Health</small></span>
            </div>
          </div>
        </div>

        <div class="col-12 col-sm-6 col-md-3">
          <div class="info-box shadow-sm">
            <span class="info-box-icon bg-success elevation-1"><i class="fas fa-layer-group"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Collections Health</span>
              <span class="info-box-number font-weight-bold text-success">93% <small class="text-muted">Avg Health</small></span>
            </div>
          </div>
        </div>

        <div class="col-12 col-sm-6 col-md-3">
          <div class="info-box shadow-sm">
            <span class="info-box-icon bg-warning elevation-1"><i class="fas fa-file-alt text-white"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Pages SEO Score</span>
              <span class="info-box-number font-weight-bold text-primary">91% <small class="text-muted">Avg Health</small></span>
            </div>
          </div>
        </div>

        <div class="col-12 col-sm-6 col-md-3">
          <div class="info-box shadow-sm">
            <span class="info-box-icon bg-danger elevation-1"><i class="fas fa-newspaper"></i></span>
            <div class="info-box-content">
              <span class="info-box-text">Blogs & Articles</span>
              <span class="info-box-number font-weight-bold text-success">92% <small class="text-muted">Avg Health</small></span>
            </div>
          </div>
        </div>
      </div>

      <!-- Quick Summary Table -->
      <div class="card card-outline card-primary shadow-sm">
        <div class="card-header border-0">
          <h3 class="card-title font-weight-bold"><i class="fas fa-heartbeat mr-2 text-danger"></i> Store Health Audit Breakdown</h3>
        </div>
        <div class="card-body table-responsive p-0">
          <table class="table table-striped table-valign-middle">
            <thead>
              <tr>
                <th>Resource Type</th>
                <th>Total Items</th>
                <th>Avg SEO Score</th>
                <th>Issues Detected</th>
                <th>Drafts Pending</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><strong><i class="fas fa-tag text-primary mr-2"></i> Products</strong></td>
                <td>5 Items</td>
                <td><span class="badge badge-warning font-weight-bold">88%</span></td>
                <td><span class="text-danger"><i class="fas fa-exclamation-triangle mr-1"></i> 2 Items need character optimization</span></td>
                <td><span class="badge badge-secondary">2 Drafts</span></td>
                <td><a href="products.php" class="btn btn-sm btn-primary">Open Module</a></td>
              </tr>
              <tr>
                <td><strong><i class="fas fa-layer-group text-success mr-2"></i> Collections</strong></td>
                <td>4 Collections</td>
                <td><span class="badge badge-success font-weight-bold">93%</span></td>
                <td><span class="text-success"><i class="fas fa-check-circle mr-1"></i> 0 Critical issues</span></td>
                <td><span class="badge badge-secondary">1 Draft</span></td>
                <td><a href="collections.php" class="btn btn-sm btn-primary">Open Module</a></td>
              </tr>
              <tr>
                <td><strong><i class="fas fa-file-alt text-warning mr-2"></i> Pages</strong></td>
                <td>3 Pages</td>
                <td><span class="badge badge-success font-weight-bold">91%</span></td>
                <td><span class="text-success"><i class="fas fa-check-circle mr-1"></i> All meta tags active</span></td>
                <td><span class="badge badge-secondary">1 Draft</span></td>
                <td><a href="pages.php" class="btn btn-sm btn-primary">Open Module</a></td>
              </tr>
              <tr>
                <td><strong><i class="fas fa-newspaper text-danger mr-2"></i> Blogs & Articles</strong></td>
                <td>2 Articles</td>
                <td><span class="badge badge-success font-weight-bold">92%</span></td>
                <td><span class="text-success"><i class="fas fa-check-circle mr-1"></i> High CTR Meta Tags</span></td>
                <td><span class="badge badge-secondary">1 Draft</span></td>
                <td><a href="blogs.php" class="btn btn-sm btn-primary">Open Module</a></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </section>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
