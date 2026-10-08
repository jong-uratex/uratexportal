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
$kpi = ['orders' => 0, 'revenue' => 0, 'customers' => 0];
$monthly = $byPayment = $byFulfillment = $byCity = [];
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

        if (isset($_GET['export']) && $_GET['export'] === 'csv') {
            $stmt = $db->prepare(
                "SELECT order_number, created_at, full_name, email, phone, shipping_address, shipping_city, shipping_zip,
                        order_details, total_price, financial_status, fulfillment_status
                 FROM shopify_orders WHERE $where ORDER BY created_at DESC, id DESC"
            );
            $stmt->execute($params);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="orders_report_' . REPORT_YEAR . '_' . $activeStore . '_' . date('Ymd_His') . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Order #', 'Order Date', 'Customer', 'Email', 'Phone', 'Shipping Address', 'City', 'ZIP', 'Order Details', 'Total', 'Payment Status', 'Fulfillment Status']);
            while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $r['total_price'] = number_format((float)$r['total_price'], 2, '.', '');
                $r = array_map(function ($v) {
                    $v = str_replace(["\r\n", "\r", "\n"], ' | ', (string)$v);
                    return preg_match('/^[=+\-@\t]/', $v) ? "'" . $v : $v;
                }, $r);
                fputcsv($out, array_values($r));
            }
            fclose($out);
            exit;
        }

        $stmt = $db->prepare("SELECT COUNT(*) FROM shopify_orders WHERE $where");
        $stmt->execute($params);
        $total = (int)$stmt->fetchColumn();

        $stmt = $db->prepare(
            "SELECT COUNT(*) AS orders, COALESCE(SUM(total_price),0) AS revenue,
                    COUNT(DISTINCT NULLIF(LOWER(email),'')) AS customers
             FROM shopify_orders WHERE $where"
        );
        $stmt->execute($params);
        $kpi = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $db->prepare(
            "SELECT DATE_FORMAT(created_at,'%Y-%m') AS m, COUNT(*) AS orders, SUM(total_price) AS revenue
             FROM shopify_orders WHERE $where GROUP BY m ORDER BY m"
        );
        $stmt->execute($params);
        $monthly = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), null, 'm');

        $group = function (string $col, int $limit = 10) use ($db, $where, $params) {
            $stmt = $db->prepare(
                "SELECT COALESCE(NULLIF(TRIM($col),''),'Unknown') AS label, COUNT(*) AS c
                 FROM shopify_orders WHERE $where GROUP BY label ORDER BY c DESC LIMIT $limit"
            );
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };
        $byPayment = $group('financial_status');
        $byFulfillment = $group('fulfillment_status');
        $byCity = $group('shipping_city');

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
    return '?' . http_build_query(array_filter($q, fn($v) => $v !== null));
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

$monthLabels = $monthOrders = $monthRevenue = [];
for ($m = 1; $m <= 12; $m++) {
    $key = sprintf('%d-%02d', REPORT_YEAR, $m);
    $monthLabels[] = date('M', mktime(0, 0, 0, $m, 1));
    $monthOrders[] = (int)($monthly[$key]['orders'] ?? 0);
    $monthRevenue[] = round((float)($monthly[$key]['revenue'] ?? 0), 2);
}
$pie = fn(array $d) => ['labels' => array_column($d, 'label'), 'data' => array_map('intval', array_column($d, 'c'))];
$chartData = [
    'months' => $monthLabels, 'orders' => $monthOrders, 'revenue' => $monthRevenue,
    'payment' => $pie($byPayment), 'fulfillment' => $pie($byFulfillment), 'city' => $pie($byCity),
];
$avgOrder = $kpi['orders'] ? $kpi['revenue'] / $kpi['orders'] : 0;

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

      <div class="row">
        <?php foreach ([
            ['Orders', number_format($kpi['orders']), 'fa-shopping-cart', 'primary'],
            ['Revenue', '₱' . number_format((float)$kpi['revenue'], 2), 'fa-peso-sign', 'success'],
            ['Avg. Order Value', '₱' . number_format($avgOrder, 2), 'fa-chart-line', 'info'],
            ['Unique Customers', number_format($kpi['customers']), 'fa-users', 'warning'],
        ] as [$label, $val, $icon, $color]): ?>
          <div class="col-6 col-lg-3">
            <div class="small-box bg-<?php echo $color; ?>">
              <div class="inner"><h3 style="font-size: 1.6rem;"><?php echo e($val); ?></h3><p><?php echo e($label); ?></p></div>
              <div class="icon"><i class="fas <?php echo $icon; ?>"></i></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="row">
        <div class="col-lg-8"><div class="card shadow-sm"><div class="card-header font-weight-bold">Monthly Orders &amp; Revenue</div>
          <div class="card-body"><canvas id="chartMonthly" height="110"></canvas></div></div></div>
        <div class="col-lg-4"><div class="card shadow-sm"><div class="card-header font-weight-bold">Payment Status</div>
          <div class="card-body"><canvas id="chartPayment" height="220"></canvas></div></div></div>
      </div>
      <div class="row">
        <div class="col-lg-4"><div class="card shadow-sm"><div class="card-header font-weight-bold">Fulfillment Status</div>
          <div class="card-body"><canvas id="chartFulfillment" height="220"></canvas></div></div></div>
        <div class="col-lg-8"><div class="card shadow-sm"><div class="card-header font-weight-bold">Top 10 Shipping Cities</div>
          <div class="card-body"><canvas id="chartCity" height="110"></canvas></div></div></div>
      </div>
      <p class="small text-muted">Summary reflects the current search filter.</p>

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
              <a class="btn btn-success btn-sm mr-3" href="<?php echo e(reportUrl(['export' => 'csv', 'page' => null])); ?>"><i class="fas fa-download mr-1"></i> Download CSV</a>
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

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
  var d = <?php echo json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
  var colors = ['#003399', '#FFCC00', '#17a2b8', '#28a745', '#dc3545', '#6f42c1', '#fd7e14', '#20c997', '#6c757d', '#e83e8c'];
  var pie = function (id, src) {
    new Chart(document.getElementById(id), {
      type: 'doughnut',
      data: { labels: src.labels, datasets: [{ data: src.data, backgroundColor: colors }] },
      options: { plugins: { legend: { position: 'bottom' } } }
    });
  };
  new Chart(document.getElementById('chartMonthly'), {
    data: {
      labels: d.months,
      datasets: [
        { type: 'bar', label: 'Orders', data: d.orders, backgroundColor: '#003399', yAxisID: 'y' },
        { type: 'line', label: 'Revenue (₱)', data: d.revenue, borderColor: '#e6b800', backgroundColor: '#FFCC00', tension: 0.3, yAxisID: 'y1' }
      ]
    },
    options: { scales: { y: { beginAtZero: true, title: { display: true, text: 'Orders' } },
                         y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'Revenue' } } } }
  });
  pie('chartPayment', d.payment);
  pie('chartFulfillment', d.fulfillment);
  new Chart(document.getElementById('chartCity'), {
    type: 'bar',
    data: { labels: d.city.labels, datasets: [{ label: 'Orders', data: d.city.data, backgroundColor: '#17a2b8' }] },
    options: { indexAxis: 'y', plugins: { legend: { display: false } } }
  });
})();
</script>

<?php
include __DIR__ . '/../includes/footer.php';
