<?php
/**
 * Custom Report (custom_report.php) - 2026 orders with customer and shipping address.
 * Reads from the `shopify_orders` table (populated by orders.php sync). Admin only.
 */
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_logged_in'])) {
    header("Location: ../login.php");
    exit;
}

if (($_SESSION['user_role'] ?? 'editor') !== 'admin') {
    header("Location: dashboard.php");
    exit;
}

const REPORT_YEAR = 2026;

$activeStore = $_SESSION['active_store'] ?? 'retail';
$db = getDbConnection();

$perPageOptions = [10, 25, 50, 100];
$perPage = isset($_GET['per_page']) && in_array((int)$_GET['per_page'], $perPageOptions, true) ? (int)$_GET['per_page'] : 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$search = trim($_GET['search'] ?? '');

$rows = [];
$total = 0;
$error = '';

if (!$db) {
    $error = 'Database connection unavailable.';
} else {
    try {
        $where = "store_key = :store AND created_at >= :from AND created_at < :to";
        $params = [
            ':store' => $activeStore,
            ':from'  => REPORT_YEAR . '-01-01 00:00:00',
            ':to'    => (REPORT_YEAR + 1) . '-01-01 00:00:00',
        ];
        if ($search !== '') {
            $where .= " AND (order_number LIKE :q1 OR full_name LIKE :q2 OR email LIKE :q3 OR shipping_address LIKE :q4)";
            $like = '%' . addcslashes($search, '%_\\') . '%';
            $params[':q1'] = $params[':q2'] = $params[':q3'] = $params[':q4'] = $like;
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM shopify_orders WHERE $where");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $totalPages = max(1, (int)ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        $stmt = $db->prepare(
            "SELECT shopify_order_id, order_number, full_name, email, phone, shipping_address,
                    order_details, total_price, financial_status, fulfillment_status, created_at
             FROM shopify_orders WHERE $where
             ORDER BY created_at DESC, id DESC LIMIT $perPage OFFSET $offset"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $error = 'Unable to load report. Make sure orders have been synced from the Orders page.';
    }
}

$totalPages = max(1, (int)ceil($total / $perPage));
$page = min($page, $totalPages);

function reportUrl(array $over = []): string
{
    global $search, $perPage, $page;
    $q = array_merge(['search' => $search, 'per_page' => $perPage, 'page' => $page], $over);
    if ($q['search'] === '') {
        unset($q['search']);
    }
    return '?' . http_build_query($q);
}

function e($v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

/** Page numbers to display, with null as an ellipsis gap. */
function pageWindow(int $current, int $last): array
{
    $pages = array_unique(array_filter([1, $last, $current - 2, $current - 1, $current, $current + 1, $current + 2], fn($p) => $p >= 1 && $p <= $last));
    sort($pages);
    $out = [];
    $prev = 0;
    foreach ($pages as $p) {
        if ($p - $prev > 1) {
            $out[] = null;
        }
        $out[] = $p;
        $prev = $p;
    }
    return $out;
}

$from = $total ? ($page - 1) * $perPage + 1 : 0;
$to = min($total, $page * $perPage);

$pageTitle = 'Custom Report';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="content-wrapper">
  <div class="content-header bg-white border-bottom pb-3 mb-3">
    <div class="container-fluid">
      <h1 class="m-0 font-weight-bold" style="color: #003399; font-size: 24px;">
        <i class="fas fa-file-alt mr-2"></i><?php echo e(ucfirst($activeStore)); ?> Orders Report <?php echo REPORT_YEAR; ?>
      </h1>
      <p class="text-muted small mb-0 mt-1">All <?php echo REPORT_YEAR; ?> orders with customer and shipping address.</p>
    </div>
  </div>

  <section class="content">
    <div class="container-fluid">
      <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo e($error); ?></div>
      <?php endif; ?>

      <div class="card shadow-sm" style="border-radius: 12px;">
        <div class="card-header bg-white">
          <form method="get" class="form-inline justify-content-between">
            <div class="input-group input-group-sm">
              <input type="search" name="search" value="<?php echo e($search); ?>" class="form-control" style="min-width: 260px;" placeholder="Search order #, customer, email, address">
              <div class="input-group-append">
                <button class="btn btn-primary" type="submit"><i class="fas fa-search"></i></button>
                <?php if ($search !== ''): ?>
                  <a class="btn btn-outline-secondary" href="?per_page=<?php echo $perPage; ?>">Clear</a>
                <?php endif; ?>
              </div>
            </div>
            <div class="form-group form-group-sm mt-2 mt-md-0">
              <label class="small text-muted mr-2" for="per_page">Rows per page</label>
              <select name="per_page" id="per_page" class="form-control form-control-sm" onchange="this.form.submit()">
                <?php foreach ($perPageOptions as $opt): ?>
                  <option value="<?php echo $opt; ?>" <?php echo $opt === $perPage ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </form>
        </div>

        <div class="card-body p-0 table-responsive">
          <table class="table table-striped table-hover table-sm mb-0">
            <thead class="thead-light">
              <tr class="small text-uppercase">
                <th>Order #</th>
                <th>Date</th>
                <th>Customer</th>
                <th>Email / Phone</th>
                <th>Shipping Address</th>
                <th>Order Details</th>
                <th class="text-right">Total</th>
                <th>Payment</th>
                <th>Fulfillment</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$rows): ?>
                <tr><td colspan="9" class="text-center text-muted py-5">No <?php echo REPORT_YEAR; ?> orders found.</td></tr>
              <?php endif; ?>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td class="font-weight-bold"><?php echo e($r['order_number']); ?></td>
                  <td class="text-nowrap small"><?php echo e($r['created_at'] ? date('M j, Y g:i A', strtotime($r['created_at'])) : ''); ?></td>
                  <td><?php echo e($r['full_name'] ?: '—'); ?></td>
                  <td class="small"><?php echo e($r['email']); ?><br><span class="text-muted"><?php echo e($r['phone']); ?></span></td>
                  <td class="small" style="min-width: 220px;"><?php echo nl2br(e($r['shipping_address'] ?: '—')); ?></td>
                  <td class="small" style="min-width: 200px;"><?php echo nl2br(e($r['order_details'] ?: '—')); ?></td>
                  <td class="text-right text-nowrap">₱<?php echo number_format((float)$r['total_price'], 2); ?></td>
                  <td><span class="badge badge-secondary"><?php echo e($r['financial_status'] ?: '—'); ?></span></td>
                  <td><span class="badge badge-info"><?php echo e($r['fulfillment_status'] ?: 'unfulfilled'); ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center">
          <span class="small text-muted mb-2 mb-md-0">Showing <?php echo number_format($from); ?>–<?php echo number_format($to); ?> of <?php echo number_format($total); ?> orders</span>
          <?php if ($totalPages > 1): ?>
            <nav aria-label="Report pagination">
              <ul class="pagination pagination-sm m-0">
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                  <a class="page-link" href="<?php echo e(reportUrl(['page' => 1])); ?>" aria-label="First">&laquo;</a>
                </li>
                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                  <a class="page-link" href="<?php echo e(reportUrl(['page' => max(1, $page - 1)])); ?>">Prev</a>
                </li>
                <?php foreach (pageWindow($page, $totalPages) as $p): ?>
                  <?php if ($p === null): ?>
                    <li class="page-item disabled"><span class="page-link">…</span></li>
                  <?php else: ?>
                    <li class="page-item <?php echo $p === $page ? 'active' : ''; ?>">
                      <a class="page-link" href="<?php echo e(reportUrl(['page' => $p])); ?>"><?php echo $p; ?></a>
                    </li>
                  <?php endif; ?>
                <?php endforeach; ?>
                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                  <a class="page-link" href="<?php echo e(reportUrl(['page' => min($totalPages, $page + 1)])); ?>">Next</a>
                </li>
                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                  <a class="page-link" href="<?php echo e(reportUrl(['page' => $totalPages])); ?>" aria-label="Last">&raquo;</a>
                </li>
              </ul>
            </nav>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>
</div>

<?php
include __DIR__ . '/../includes/footer.php';
