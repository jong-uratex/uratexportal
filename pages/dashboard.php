<?php
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_logged_in'])) {
    header("Location: ../login.php");
    exit;
}

if (isset($_GET['switch_store'])) {
    setActiveStore($_GET['switch_store']);
    recordUserLog('Switch Store', 'Active Store', "Switched active store to '{$_GET['switch_store']}' from Dashboard.", 'system', null, 'success');
    header("Location: dashboard.php");
    exit;
}

$lastTokenRenewedAt = null;
$db = getDbConnection();
if ($db) {
  $tokenTimestampStmt = $db->query("SELECT MAX(`updated_at`) FROM `settings` WHERE `handle` IN ('retail_access_token', 'business_access_token')");
  $lastTokenRenewedAt = $tokenTimestampStmt->fetchColumn() ?: null;
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
          <button type="button" id="renewTokenBtn" class="btn btn-warning text-dark font-weight-bold mr-2">
            <i class="fas fa-key mr-1"></i> Renew Token<?php if ($lastTokenRenewedAt): ?>
              <small class="d-block font-weight-normal">Last renewed: <?= htmlspecialchars((new DateTime($lastTokenRenewedAt, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y g:i A'), ENT_QUOTES, 'UTF-8') ?></small>
            <?php endif; ?>
          </button>
          <button type="button" class="btn btn-uratex-sync mr-2">
            <i class="fas fa-sync-alt mr-1"></i> Sync from Shopify
          </button>
          <!-- Removed Bulk Approve & Push button -->
        </div>
      </div>
    </div>
  </div>

  <!-- Main content -->
  <section class="content">
    <div class="container-fluid">
      <!-- Alert Container for AJAX Responses -->
      <div id="dashboardAlertContainer"></div>

      <!-- API Connection Status -->
      <!-- ... rest of the code ... -->
    </div>
  </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const renewBtn = document.getElementById('renewTokenBtn');
  const alertContainer = document.getElementById('dashboardAlertContainer');

  if (renewBtn) {
    renewBtn.addEventListener('click', function() {
      if (!confirm('Are you sure you want to request a new Shopify access token and save it to the database?')) {
        return;
      }

      const originalHtml = renewBtn.innerHTML;
      renewBtn.disabled = true;
      renewBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Renewing...';

      fetch('renew_token.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        }
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          alertContainer.innerHTML = `
            <div class="alert alert-success alert-dismissible fade show" role="alert">
              <i class="fas fa-check-circle mr-2"></i> ${data.message}
              <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>`;
          setTimeout(() => {
            window.location.reload();
          }, 1500);
        } else {
          alertContainer.innerHTML = `
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
              <i class="fas fa-exclamation-circle mr-2"></i> ${data.message}
              <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>`;
        }
      })
      .catch(error => {
        console.error('Error renewing token:', error);
        alertContainer.innerHTML = `
          <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle mr-2"></i> An error occurred while renewing the access token.
            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
              <span aria-hidden="true">&times;</span>
            </button>
          </div>`;
      })
      .finally(() => {
        renewBtn.disabled = false;
        renewBtn.innerHTML = originalHtml;
      });
    });
  }
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>