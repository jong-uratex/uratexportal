<?php
require_once __DIR__ . '/../config/config.php';

if (!isset($_SESSION['user_logged_in'])) {
    header('Location: ../login.php');
    exit;
}

// --- Store switch (whitelist) ---
if (isset($_GET['switch_store'])) {
    $allowedStores = ['retail', 'business'];
    $requested = strtolower(trim($_GET['switch_store']));
    if (in_array($requested, $allowedStores, true)) {
        setActiveStore($requested);
        recordUserLog(
            'Switch Store',
            'Active Store',
            "Switched active store to '{$requested}' from Dashboard.",
            'system',
            null,
            'success'
        );
    }
    header('Location: dashboard.php');
    exit;
}

// --- Single DB connection ---
$db = getDbConnection();
$activeStore = $_SESSION['active_store'] ?? 'business';

// --- Last token renewal timestamp ---
$lastTokenRenewedAt = null;
if ($db) {
    try {
        $stmt = $db->query(
            "SELECT MAX(`updated_at`) FROM `settings` 
             WHERE `handle` IN ('retail_access_token', 'business_access_token')"
        );
        $lastTokenRenewedAt = $stmt->fetchColumn() ?: null;
    } catch (Exception $e) {
        // silent – non-critical
    }
}

// --- SEO Health helper ---
if (!function_exists('calculateSeoHealth')) {
    function calculateSeoHealth(string $title, string $metaDescription, string $handle): array
    {
        $score  = 100;
        $issues = [];

        $tLen = mb_strlen(trim($title));
        $dLen = mb_strlen(trim($metaDescription));
        $h    = trim($handle);

        if ($tLen === 0) {
            $score -= 35;
            $issues[] = 'Missing Page Title';
        } elseif ($tLen < 35) {
            $score -= 15;
            $issues[] = 'Title too short';
        } elseif ($tLen > 65) {
            $score -= 10;
            $issues[] = 'Title too long';
        }

        if ($dLen === 0) {
            $score -= 35;
            $issues[] = 'Missing Meta Description';
        } elseif ($dLen < 90) {
            $score -= 15;
            $issues[] = 'Meta description too short';
        } elseif ($dLen > 165) {
            $score -= 10;
            $issues[] = 'Meta description exceeds 160 chars';
        }

        if ($h === '') {
            $score -= 15;
            $issues[] = 'Missing URL Handle';
        }

        return [
            'score'  => max(10, min(100, $score)),
            'issues' => $issues,
        ];
    }
}

// --- Resource configuration ---
$resourceTypes = [
    'products' => [
        'icon'        => 'fa-tag text-primary',
        'name'        => 'Products',
        'table'       => 'shopify_products',
        'id_field'    => 'id',
        'title_field' => 'title',
        'meta_field'  => 'meta_description',
        'handle_field'=> 'handle',
        'module'      => 'products.php',
        'item_label'  => 'Items',
    ],
    'collections' => [
        'icon'        => 'fa-layer-group text-success',
        'name'        => 'Collections',
        'table'       => 'shopify_collections',
        'id_field'    => 'id',
        'title_field' => 'title',
        'meta_field'  => 'meta_description',
        'handle_field'=> 'handle',
        'module'      => 'collections.php',
        'item_label'  => 'Collections',
    ],
    'pages' => [
        'icon'        => 'fa-file-alt text-warning',
        'name'        => 'Pages',
        'table'       => 'shopify_pages',
        'id_field'    => 'id',
        'title_field' => 'title',
        'meta_field'  => 'meta_description',
        'handle_field'=> 'handle',
        'module'      => 'pages.php',
        'item_label'  => 'Items',
    ],
    'blogs' => [
        'icon'        => 'fa-newspaper text-danger',
        'name'        => 'Blogs & Articles',
        'table'       => 'shopify_blogs',
        'id_field'    => 'id',
        'title_field' => 'title',
        'meta_field'  => 'meta_description',
        'handle_field'=> 'handle',
        'module'      => 'blogs.php',
        'item_label'  => 'Articles',
    ],
];

// --- Compute SEO stats once ---
$seoData = []; // key => ['avg_score' => int, 'total' => int, 'drafts' => int, 'items_with_issues' => int, 'issues' => []]

if ($db) {
    foreach ($resourceTypes as $key => $resource) {
        $table = $resource['table'];
        $seoData[$key] = [
            'avg_score'         => 100,
            'total'             => 0,
            'drafts'            => 0,
            'items_with_issues' => 0,
            'issues'            => [],
        ];

        try {
            // Total count
            $stmt = $db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE store_key = :store");
            $stmt->execute([':store' => $activeStore]);
            $seoData[$key]['total'] = (int)$stmt->fetchColumn();

            // Draft count
            $stmt = $db->prepare("SELECT COUNT(*) FROM `{$table}` WHERE store_key = :store AND status = 'draft'");
            $stmt->execute([':store' => $activeStore]);
            $seoData[$key]['drafts'] = (int)$stmt->fetchColumn();

            // Sample for SEO scoring (max 100 rows)
            if ($seoData[$key]['total'] > 0) {
                $stmt = $db->prepare(
                    "SELECT `{$resource['title_field']}`, `{$resource['meta_field']}`, `{$resource['handle_field']}` 
                     FROM `{$table}` WHERE store_key = :store LIMIT 100"
                );
                $stmt->execute([':store' => $activeStore]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $scores = [];
                $allIssues = [];
                $withIssues = 0;

                foreach ($items as $item) {
                    $analysis = calculateSeoHealth(
                        $item[$resource['title_field']] ?? '',
                        $item[$resource['meta_field']]  ?? '',
                        $item[$resource['handle_field']] ?? ''
                    );
                    $scores[] = $analysis['score'];

                    if (!empty($analysis['issues'])) {
                        $allIssues = array_merge($allIssues, $analysis['issues']);
                        $withIssues++;
                    }
                }

                $seoData[$key]['avg_score']         = !empty($scores) ? (int)round(array_sum($scores) / count($scores)) : 100;
                $seoData[$key]['items_with_issues'] = $withIssues;
                $seoData[$key]['issues']            = array_unique($allIssues);
            }
        } catch (Exception $e) {
            // Table missing or query error – keep defaults
        }
    }
}

// --- Connection status checks ---
$dbConnected = (bool)$db;

$recaptchaConfigured = !empty(RECAPTCHA_SITE_KEY) && !empty(RECAPTCHA_SECRET_KEY);

$shopifyRetailConnected   = false;
$shopifyBusinessConnected = false;
$retailError   = '';
$businessError = '';

/**
 * Safe Shopify connectivity probe (SSL verified, short timeout)
 */
function probeShopify(string $url, string $token, string $version): array
{
    if (empty($url) || empty($token)) {
        return ['ok' => false, 'error' => 'Not configured'];
    }

    $testUrl = 'https://' . $url . '/admin/api/' . $version . '/shop.json';
    $ch = curl_init($testUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'X-Shopify-Access-Token: ' . $token,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_FAILONERROR    => false,
    ]);

    curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200) {
        return ['ok' => true, 'error' => ''];
    }

    $error = $curlErr ?: "HTTP {$httpCode}";
    return ['ok' => false, 'error' => $error];
}

if (!empty($shopConfig['retail']['url']) && !empty($shopConfig['retail']['access_token'])) {
    $result = probeShopify(
        $shopConfig['retail']['url'],
        $shopConfig['retail']['access_token'],
        $shopConfig['retail']['version'] ?? '2024-01'
    );
    $shopifyRetailConnected = $result['ok'];
    $retailError            = $result['error'];
}

if (!empty($shopConfig['business']['url']) && !empty($shopConfig['business']['access_token'])) {
    $result = probeShopify(
        $shopConfig['business']['url'],
        $shopConfig['business']['access_token'],
        $shopConfig['business']['version'] ?? '2024-01'
    );
    $shopifyBusinessConnected = $result['ok'];
    $businessError            = $result['error'];
}

// --- Page setup ---
$pageTitle = 'Dashboard - SEO Health & Analytics';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<!-- Content Wrapper -->
<div class="content-wrapper">
  <div class="content-header">
    <div class="container-fluid">
      <div class="row mb-2">
        <div class="col-sm-6">
          <h1 class="m-0 font-weight-bold" style="color: #003399;">SEO Health & Analytics Dashboard</h1>
          <p class="text-muted small mb-0">Real-time health scores for Pages, Collections, Products, and Blogs.</p>
        </div>
        <div class="col-sm-6 text-right">
          <button type="button" id="renewTokenBtn" class="btn btn-warning text-dark font-weight-bold mr-2">
            <i class="fas fa-key mr-1"></i> Renew Token
            <?php if ($lastTokenRenewedAt): ?>
              <small class="d-block font-weight-normal">
                Last renewed:
                <?= htmlspecialchars(
                    (new DateTime($lastTokenRenewedAt, new DateTimeZone('UTC')))
                        ->setTimezone(new DateTimeZone('Asia/Manila'))
                        ->format('M j, Y g:i A'),
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
              </small>
            <?php endif; ?>
          </button>
          <button type="button" class="btn btn-uratex-sync mr-2">
            <i class="fas fa-sync-alt mr-1"></i> Sync from Shopify
          </button>
        </div>
      </div>
    </div>
  </div>

  <section class="content">
    <div class="container-fluid">

      <div id="dashboardAlertContainer"></div>

      <!-- API Connection Status -->
      <div class="card card-outline card-primary shadow-sm mb-4">
        <div class="card-header border-0">
          <h3 class="card-title font-weight-bold">
            <i class="fas fa-server mr-2 text-primary"></i> API Connection Status
          </h3>
          <div class="card-tools">
            <button type="button" class="btn btn-tool" data-card-widget="collapse"><i class="fas fa-minus"></i></button>
            <button type="button" class="btn btn-tool" data-card-widget="remove"><i class="fas fa-times"></i></button>
          </div>
        </div>
        <div class="card-body">
          <div class="row">

            <!-- Database -->
            <div class="col-12 col-sm-6 col-md-3 mb-3">
              <div class="small-box <?= $dbConnected ? 'bg-success' : 'bg-danger' ?>">
                <div class="inner">
                  <h3><i class="fas <?= $dbConnected ? 'fa-database' : 'fa-exclamation-triangle' ?>"></i></h3>
                  <p>Database Connection</p>
                </div>
                <div class="icon">
                  <i class="ion <?= $dbConnected ? 'ion-checkmark-circled' : 'ion-close-circled' ?>"></i>
                </div>
                <div class="small-box-footer">
                  <?= $dbConnected ? 'Connected' : 'Disconnected' ?>
                </div>
              </div>
            </div>

            <!-- reCAPTCHA -->
            <div class="col-12 col-sm-6 col-md-3 mb-3">
              <div class="small-box <?= $recaptchaConfigured ? 'bg-success' : 'bg-warning' ?>">
                <div class="inner">
                  <h3><i class="fas <?= $recaptchaConfigured ? 'fa-shield-alt' : 'fa-exclamation-circle' ?>"></i></h3>
                  <p>reCAPTCHA</p>
                </div>
                <div class="icon">
                  <i class="ion <?= $recaptchaConfigured ? 'ion-checkmark-circled' : 'ion-alert-circled' ?>"></i>
                </div>
                <div class="small-box-footer">
                  <?= $recaptchaConfigured ? 'Configured' : 'Not configured' ?>
                </div>
              </div>
            </div>

            <!-- Retail -->
            <div class="col-12 col-sm-6 col-md-3 mb-3">
              <div class="small-box <?= $shopifyRetailConnected ? 'bg-success' : 'bg-danger' ?>">
                <div class="inner">
                  <h3><i class="fas <?= $shopifyRetailConnected ? 'fa-shop' : 'fa-exclamation-triangle' ?>"></i></h3>
                  <p>Retail Store</p>
                </div>
                <div class="icon">
                  <i class="ion <?= $shopifyRetailConnected ? 'ion-checkmark-circled' : 'ion-close-circled' ?>"></i>
                </div>
                <div class="small-box-footer">
                  <?= $shopifyRetailConnected ? 'Connected' : 'Connection failed' ?>
                  <?php if ($retailError): ?>
                    <br><small class="text-white"><?= htmlspecialchars($retailError, ENT_QUOTES, 'UTF-8') ?></small>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <!-- Business -->
            <div class="col-12 col-sm-6 col-md-3 mb-3">
              <div class="small-box <?= $shopifyBusinessConnected ? 'bg-success' : 'bg-danger' ?>">
                <div class="inner">
                  <h3><i class="fas <?= $shopifyBusinessConnected ? 'fa-store' : 'fa-exclamation-triangle' ?>"></i></h3>
                  <p>Business Store</p>
                </div>
                <div class="icon">
                  <i class="ion <?= $shopifyBusinessConnected ? 'ion-checkmark-circled' : 'ion-close-circled' ?>"></i>
                </div>
                <div class="small-box-footer">
                  <?= $shopifyBusinessConnected ? 'Connected' : 'Connection failed' ?>
                  <?php if ($businessError): ?>
                    <br><small class="text-white"><?= htmlspecialchars($businessError, ENT_QUOTES, 'UTF-8') ?></small>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <!-- Details table (no token values) -->
          <div class="row mt-4">
            <div class="col-12">
              <div class="table-responsive">
                <table class="table table-bordered table-striped">
                  <thead class="thead-light">
                    <tr>
                      <th>Service</th>
                      <th>Endpoint</th>
                      <th>Status</th>
                      <th>Details</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td><strong>MySQL Database</strong></td>
                      <td>**********</td>
                      <td>
                        <span class="badge <?= $dbConnected ? 'badge-success' : 'badge-danger' ?>">
                          <?= $dbConnected ? 'Connected' : 'Disconnected' ?>
                        </span>
                      </td>
                      <td><?= $dbConnected ? 'Database connection established' : 'Unable to connect to database' ?></td>
                    </tr>
                    <tr>
                      <td><strong>reCAPTCHA</strong></td>
                      <td>Google reCAPTCHA v2</td>
                      <td>
                        <span class="badge <?= $recaptchaConfigured ? 'badge-success' : 'badge-warning' ?>">
                          <?= $recaptchaConfigured ? 'Configured' : 'Missing Keys' ?>
                        </span>
                      </td>
                      <td>
                        Site Key: <?= !empty(RECAPTCHA_SITE_KEY) ? 'Set' : 'Missing' ?> |
                        Secret Key: <?= !empty(RECAPTCHA_SECRET_KEY) ? 'Set' : 'Missing' ?>
                      </td>
                    </tr>
                    <tr>
                      <td><strong>Shopify Retail</strong></td>
                      <td>
                        <?= !empty($shopConfig['retail']['url'])
                            ? 'https://' . htmlspecialchars($shopConfig['retail']['url'], ENT_QUOTES, 'UTF-8')
                            : 'Not configured' ?>
                      </td>
                      <td>
                        <span class="badge <?= $shopifyRetailConnected ? 'badge-success' : 'badge-danger' ?>">
                          <?= $shopifyRetailConnected ? 'Connected' : 'Failed' ?>
                        </span>
                      </td>
                      <td>
                        Token: <?= !empty($shopConfig['retail']['access_token']) ? 'Present' : 'Not set' ?>
                      </td>
                    </tr>
                    <tr>
                      <td><strong>Shopify Business</strong></td>
                      <td>
                        <?= !empty($shopConfig['business']['url'])
                            ? 'https://' . htmlspecialchars($shopConfig['business']['url'], ENT_QUOTES, 'UTF-8')
                            : 'Not configured' ?>
                      </td>
                      <td>
                        <span class="badge <?= $shopifyBusinessConnected ? 'badge-success' : 'badge-danger' ?>">
                          <?= $shopifyBusinessConnected ? 'Connected' : 'Failed' ?>
                        </span>
                      </td>
                      <td>
                        Token: <?= !empty($shopConfig['business']['access_token']) ? 'Present' : 'Not set' ?>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Info boxes (reuse pre-computed scores) -->
      <div class="row">
        <?php
        $infoBoxes = [
            'products'    => ['icon' => 'fa-tag',        'bg' => 'bg-info',    'text' => 'Product SEO Score'],
            'collections' => ['icon' => 'fa-layer-group','bg' => 'bg-success', 'text' => 'Collections Health'],
            'pages'       => ['icon' => 'fa-file-alt',   'bg' => 'bg-warning', 'text' => 'Pages SEO Score'],
            'blogs'       => ['icon' => 'fa-newspaper',  'bg' => 'bg-danger',  'text' => 'Blogs & Articles'],
        ];

        foreach ($infoBoxes as $key => $box) {
            $score = $seoData[$key]['avg_score'] ?? 100;
            $textClass = 'text-success';
            if ($score < 70) {
                $textClass = 'text-danger';
            } elseif ($score < 85) {
                $textClass = 'text-warning';
            }
            ?>
            <div class="col-12 col-sm-6 col-md-3">
              <div class="info-box shadow-sm">
                <span class="info-box-icon <?= $box['bg'] ?> elevation-1">
                  <i class="fas <?= $box['icon'] ?>"></i>
                </span>
                <div class="info-box-content">
                  <span class="info-box-text"><?= htmlspecialchars($box['text'], ENT_QUOTES, 'UTF-8') ?></span>
                  <span class="info-box-number font-weight-bold <?= $textClass ?>">
                    <?= (int)$score ?>% <small class="text-muted">Avg Health</small>
                  </span>
                </div>
              </div>
            </div>
            <?php
        }
        ?>
      </div>

      <!-- Health Audit Table -->
      <div class="card card-outline card-primary shadow-sm">
        <div class="card-header border-0">
          <h3 class="card-title font-weight-bold">
            <i class="fas fa-heartbeat mr-2 text-danger"></i> Store Health Audit Breakdown
          </h3>
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
              <?php foreach ($resourceTypes as $key => $resource):
                  $data = $seoData[$key] ?? [
                      'avg_score' => 100, 'total' => 0, 'drafts' => 0,
                      'items_with_issues' => 0, 'issues' => []
                  ];

                  $scoreBadge = 'badge-success';
                  if ($data['avg_score'] < 70) {
                      $scoreBadge = 'badge-danger';
                  } elseif ($data['avg_score'] < 85) {
                      $scoreBadge = 'badge-warning';
                  }

                  if ($data['items_with_issues'] === 0) {
                      $issueSummary = '<span class="text-success"><i class="fas fa-check-circle mr-1"></i> 0 Critical issues</span>';
                  } else {
                      $issueSummary = '<span class="text-danger"><i class="fas fa-exclamation-triangle mr-1"></i> '
                                    . (int)$data['items_with_issues'] . ' Items need optimization</span>';
                  }
              ?>
              <tr>
                <td>
                  <strong>
                    <i class="fas <?= htmlspecialchars($resource['icon'], ENT_QUOTES, 'UTF-8') ?> mr-2"></i>
                    <?= htmlspecialchars($resource['name'], ENT_QUOTES, 'UTF-8') ?>
                  </strong>
                </td>
                <td>
                  <?= (int)$data['total'] ?> <?= htmlspecialchars($resource['item_label'], ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td>
                  <span class="badge <?= $scoreBadge ?> font-weight-bold"><?= (int)$data['avg_score'] ?>%</span>
                </td>
                <td><?= $issueSummary ?></td>
                <td>
                  <span class="badge badge-secondary">
                    <?= (int)$data['drafts'] ?> Draft<?= $data['drafts'] !== 1 ? 's' : '' ?>
                  </span>
                </td>
                <td>
                  <a href="<?= htmlspecialchars($resource['module'], ENT_QUOTES, 'UTF-8') ?>"
                     class="btn btn-sm btn-primary">Open Module</a>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const renewBtn = document.getElementById('renewTokenBtn');
  const alertContainer = document.getElementById('dashboardAlertContainer');

  if (!renewBtn) return;

  renewBtn.addEventListener('click', function () {
    if (!confirm('Are you sure you want to request a new Shopify access token and save it to the database?')) {
      return;
    }

    const originalHtml = renewBtn.innerHTML;
    renewBtn.disabled = true;
    renewBtn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Renewing...';

    fetch('renew_token.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin'
    })
    .then(response => response.json())
    .then(data => {
      const type = data.success ? 'success' : 'danger';
      const icon = data.success ? 'fa-check-circle' : 'fa-exclamation-circle';
      alertContainer.innerHTML = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
          <i class="fas ${icon} mr-2"></i> ${data.message || 'Unknown response'}
          <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>`;

      if (data.success) {
        setTimeout(() => window.location.reload(), 1500);
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
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
