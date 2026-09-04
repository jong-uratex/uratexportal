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
          <button type="button" id="renewTokenBtn" class="btn btn-warning text-dark font-weight-bold mr-2">
            <i class="fas fa-key mr-1"></i> Renew Token
          </button>
          <button type="button" class="btn btn-uratex-sync mr-2">
            <i class="fas fa-sync-alt mr-1"></i> Sync from Shopify
          </button>
          <button type="button" class="btn btn-success">
            <i class="fas fa-check-double mr-1"></i> Bulk Approve & Push
          </button>
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
      <div class="card card-outline card-primary shadow-sm mb-4">
        <div class="card-header border-0">
          <h3 class="card-title font-weight-bold"><i class="fas fa-server mr-2 text-primary"></i> API Connection Status</h3>
          <div class="card-tools">
            <button type="button" class="btn btn-tool" data-card-widget="collapse"><i class="fas fa-minus"></i></button>
            <button type="button" class="btn btn-tool" data-card-widget="remove"><i class="fas fa-times"></i></button>
          </div>
        </div>
        <div class="card-body">
          <div class="row">
            <?php
            // Check Database Connection
            $dbConnected = false;
            $db = getDbConnection();
            if ($db) {
                $dbConnected = true;
            }
            
            // Check reCAPTCHA Configuration
            $recaptchaConfigured = !empty(RECAPTCHA_SITE_KEY) && !empty(RECAPTCHA_SECRET_KEY);
            
            // Check Shopify Connections
            $shopifyRetailConnected = false;
            $shopifyBusinessConnected = false;
            $retailError = '';
            $businessError = '';
            
            // Test Retail connection
            if (!empty($shopConfig['retail']['url']) && !empty($shopConfig['retail']['access_token'])) {
                try {
                    $testUrl = "https://" . $shopConfig['retail']['url'] . "/admin/api/" . $shopConfig['retail']['version'] . "/shop.json";
                    $ch = curl_init($testUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        "Content-Type: application/json",
                        "X-Shopify-Access-Token: " . $shopConfig['retail']['access_token']
                    ]);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                    curl_setopt($ch, CURLOPT_FAILONERROR, true);
                    curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    $shopifyRetailConnected = ($httpCode === 200);
                    if (!$shopifyRetailConnected) {
                        $retailError = "HTTP $httpCode";
                    }
                } catch (Exception $e) {
                    $retailError = "Connection failed";
                }
            }
            
            // Test Business connection
            if (!empty($shopConfig['business']['url']) && !empty($shopConfig['business']['access_token'])) {
                try {
                    $testUrl = "https://" . $shopConfig['business']['url'] . "/admin/api/" . $shopConfig['business']['version'] . "/shop.json";
                    $ch = curl_init($testUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HTTPHEADER, [
                        "Content-Type: application/json",
                        "X-Shopify-Access-Token: " . $shopConfig['business']['access_token']
                    ]);
                    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                    curl_setopt($ch, CURLOPT_FAILONERROR, true);
                    curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);
                    $shopifyBusinessConnected = ($httpCode === 200);
                    if (!$shopifyBusinessConnected) {
                        $businessError = "HTTP $httpCode";
                    }
                } catch (Exception $e) {
                    $businessError = "Connection failed";
                }
            }
            ?>
            
            <!-- Database Status -->
            <div class="col-12 col-sm-6 col-md-3 mb-3">
              <div class="small-box <?php echo $dbConnected ? 'bg-success' : 'bg-danger'; ?>">
                <div class="inner">
                  <h3><i class="fas <?php echo $dbConnected ? 'fa-database' : 'fa-exclamation-triangle'; ?>"></i></h3>
                  <p>Database Connection</p>
                </div>
                <div class="icon">
                  <i class="ion <?php echo $dbConnected ? 'ion-checkmark-circled' : 'ion-close-circled'; ?>"></i>
                </div>
                <div class="small-box-footer">
                  <?php echo $dbConnected ? 'Connected' : 'Disconnected'; ?>
                </div>
              </div>
            </div>
            
            <!-- reCAPTCHA Status -->
            <div class="col-12 col-sm-6 col-md-3 mb-3">
              <div class="small-box <?php echo $recaptchaConfigured ? 'bg-success' : 'bg-warning'; ?>">
                <div class="inner">
                  <h3><i class="fas <?php echo $recaptchaConfigured ? 'fa-shield-alt' : 'fa-exclamation-circle'; ?>"></i></h3>
                  <p>reCAPTCHA</p>
                </div>
                <div class="icon">
                  <i class="ion <?php echo $recaptchaConfigured ? 'ion-checkmark-circled' : 'ion-alert-circled'; ?>"></i>
                </div>
                <div class="small-box-footer">
                  <?php echo $recaptchaConfigured ? 'Configured' : 'Not configured'; ?>
                </div>
              </div>
            </div>
            
            <!-- Retail Store Status -->
            <div class="col-12 col-sm-6 col-md-3 mb-3">
              <div class="small-box <?php echo $shopifyRetailConnected ? 'bg-success' : 'bg-danger'; ?>">
                <div class="inner">
                  <h3><i class="fas <?php echo $shopifyRetailConnected ? 'fa-shop' : 'fa-exclamation-triangle'; ?>"></i></h3>
                  <p>Retail Store</p>
                </div>
                <div class="icon">
                  <i class="ion <?php echo $shopifyRetailConnected ? 'ion-checkmark-circled' : 'ion-close-circled'; ?>"></i>
                </div>
                <div class="small-box-footer">
                  <?php echo $shopifyRetailConnected ? 'Connected' : 'Connection failed'; ?>
                  <?php echo $retailError ? '<br><small class="text-white">' . $retailError . '</small>' : ''; ?>
                </div>
              </div>
            </div>
            
            <!-- Business Store Status -->
            <div class="col-12 col-sm-6 col-md-3 mb-3">
              <div class="small-box <?php echo $shopifyBusinessConnected ? 'bg-success' : 'bg-danger'; ?>">
                <div class="inner">
                  <h3><i class="fas <?php echo $shopifyBusinessConnected ? 'fa-store' : 'fa-exclamation-triangle'; ?>"></i></h3>
                  <p>Business Store</p>
                </div>
                <div class="icon">
                  <i class="ion <?php echo $shopifyBusinessConnected ? 'ion-checkmark-circled' : 'ion-close-circled'; ?>"></i>
                </div>
                <div class="small-box-footer">
                  <?php echo $shopifyBusinessConnected ? 'Connected' : 'Connection failed'; ?>
                  <?php echo $businessError ? '<br><small class="text-white">' . $businessError . '</small>' : ''; ?>
                </div>
              </div>
            </div>
          </div>
          
          <!-- Connection Details Table -->
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
                      <td><?php echo DB_HOST . '/' . DB_NAME; ?></td>
                      <td><span class="badge <?php echo $dbConnected ? 'badge-success' : 'badge-danger'; ?>">
                          <?php echo $dbConnected ? 'Connected' : 'Disconnected'; ?>
                        </span></td>
                      <td><?php echo $dbConnected ? 'Database connection established' : 'Unable to connect to database'; ?></td>
                    </tr>
                    <tr>
                      <td><strong>reCAPTCHA</strong></td>
                      <td>Google reCAPTCHA v2</td>
                      <td><span class="badge <?php echo $recaptchaConfigured ? 'badge-success' : 'badge-warning'; ?>">
                          <?php echo $recaptchaConfigured ? 'Configured' : 'Missing Keys'; ?>
                        </span></td>
                      <td>
                        <?php echo !empty(RECAPTCHA_SITE_KEY) ? 'Site Key: Set' : 'Site Key: Missing'; ?> |
                        <?php echo !empty(RECAPTCHA_SECRET_KEY) ? 'Secret Key: Set' : 'Secret Key: Missing'; ?>
                      </td>
                    </tr>
                    <tr>
                      <td><strong>Shopify Retail</strong></td>
                      <td><?php echo !empty($shopConfig['retail']['url']) ? 'https://' . $shopConfig['retail']['url'] : 'Not configured'; ?></td>
                      <td><span class="badge <?php echo $shopifyRetailConnected ? 'badge-success' : 'badge-danger'; ?>">
                          <?php echo $shopifyRetailConnected ? 'Connected' : 'Failed'; ?>
                        </span></td>
                      <td>
                        <?php 
                        if (!empty($shopConfig['retail']['access_token'])) {
                            echo 'Token: ' . substr($shopConfig['retail']['access_token'], 0, 10) . '...' . substr($shopConfig['retail']['access_token'], -4);
                        } else {
                            echo 'Token: Not set';
                        }
                        ?>
                      </td>
                    </tr>
                    <tr>
                      <td><strong>Shopify Business</strong></td>
                      <td><?php echo !empty($shopConfig['business']['url']) ? 'https://' . $shopConfig['business']['url'] : 'Not configured'; ?></td>
                      <td><span class="badge <?php echo $shopifyBusinessConnected ? 'badge-success' : 'badge-danger'; ?>">
                          <?php echo $shopifyBusinessConnected ? 'Connected' : 'Failed'; ?>
                        </span></td>
                      <td>
                        <?php 
                        if (!empty($shopConfig['business']['access_token'])) {
                            echo 'Token: ' . substr($shopConfig['business']['access_token'], 0, 10) . '...' . substr($shopConfig['business']['access_token'], -4);
                        } else {
                            echo 'Token: Not set';
                        }
                        ?>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
      
      <!-- Info boxes -->
      <div class="row">
        <?php
        // Resource types configuration - used by both info boxes and health table
        $resourceTypes = [
            'products' => [
                'icon' => 'fa-tag text-primary',
                'name' => 'Products',
                'table' => 'shopify_products',
                'id_field' => 'id',
                'title_field' => 'title',
                'meta_field' => 'meta_description',
                'handle_field' => 'handle',
                'module' => 'products.php'
            ],
            'collections' => [
                'icon' => 'fa-layer-group text-success',
                'name' => 'Collections',
                'table' => 'shopify_collections',
                'id_field' => 'id',
                'title_field' => 'title',
                'meta_field' => 'meta_description',
                'handle_field' => 'handle',
                'module' => 'collections.php'
            ],
            'pages' => [
                'icon' => 'fa-file-alt text-warning',
                'name' => 'Pages',
                'table' => 'shopify_pages',
                'id_field' => 'id',
                'title_field' => 'title',
                'meta_field' => 'meta_description',
                'handle_field' => 'handle',
                'module' => 'pages.php'
            ],
            'blogs' => [
                'icon' => 'fa-newspaper text-danger',
                'name' => 'Blogs & Articles',
                'table' => 'shopify_blogs',
                'id_field' => 'id',
                'title_field' => 'title',
                'meta_field' => 'meta_description',
                'handle_field' => 'handle',
                'module' => 'blogs.php'
            ]
        ];
        
        // SEO Health function (replicate from config if not available)
        if (!function_exists('calculateSeoHealth')) {
            function calculateSeoHealth($title, $metaDescription, $handle) {
                $score = 100;
                $issues = [];
                
                $tLen = mb_strlen(trim($title));
                $dLen = mb_strlen(trim($metaDescription));
                $h = trim($handle);
                
                if ($tLen === 0) {
                    $score -= 35;
                    $issues[] = "Missing Page Title";
                } elseif ($tLen < 35) {
                    $score -= 15;
                    $issues[] = "Title too short";
                } elseif ($tLen > 65) {
                    $score -= 10;
                    $issues[] = "Title too long";
                }
                
                if ($dLen === 0) {
                    $score -= 35;
                    $issues[] = "Missing Meta Description";
                } elseif ($dLen < 90) {
                    $score -= 15;
                    $issues[] = "Meta description too short";
                } elseif ($dLen > 165) {
                    $score -= 10;
                    $issues[] = "Meta description exceeds 160 chars";
                }
                
                if (empty($h)) {
                    $score -= 15;
                    $issues[] = "Missing URL Handle";
                }
                
                return [
                    'score' => max(10, min(100, $score)),
                    'issues' => $issues
                ];
            }
        }
        
        // Pre-calculate SEO scores for info boxes
        $seoScoresByResource = [];
        $activeStore = $_SESSION['active_store'] ?? 'business';
        $db = getDbConnection();
        
        if ($db) {
            foreach ($resourceTypes as $key => $resource) {
                $tableName = $resource['table'];
                $seoScores = [];
                
                try {
                    $itemsStmt = $db->prepare("SELECT `$resource[title_field]`, `$resource[meta_field]`, `$resource[handle_field]` FROM `$tableName` WHERE store_key = :store LIMIT 100");
                    $itemsStmt->execute([':store' => $activeStore]);
                    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($items as $item) {
                        $title = $item[$resource['title_field']] ?? '';
                        $meta = $item[$resource['meta_field']] ?? '';
                        $handle = $item[$resource['handle_field']] ?? '';
                        
                        $seoAnalysis = calculateSeoHealth($title, $meta, $handle);
                        $seoScores[] = $seoAnalysis['score'];
                    }
                    
                    $seoScoresByResource[$key] = !empty($seoScores) ? round(array_sum($seoScores) / count($seoScores)) : 100;
                } catch (Exception $e) {
                    $seoScoresByResource[$key] = 100; // Default if table doesn't exist
                }
            }
        }
        
        // Define info box configurations
        $infoBoxes = [
            'products' => [
                'icon' => 'fa-tag',
                'bg' => 'bg-info',
                'text' => 'Product SEO Score',
                'text_class' => ''
            ],
            'collections' => [
                'icon' => 'fa-layer-group',
                'bg' => 'bg-success',
                'text' => 'Collections Health',
                'text_class' => ''
            ],
            'pages' => [
                'icon' => 'fa-file-alt text-white',
                'bg' => 'bg-warning',
                'text' => 'Pages SEO Score',
                'text_class' => ''
            ],
            'blogs' => [
                'icon' => 'fa-newspaper',
                'bg' => 'bg-danger',
                'text' => 'Blogs & Articles',
                'text_class' => ''
            ]
        ];
        
        foreach ($infoBoxes as $key => $box) {
            $score = $seoScoresByResource[$key] ?? 100;
            $textClass = 'text-success';
            if ($score < 70) {
                $textClass = 'text-danger';
            } elseif ($score < 85) {
                $textClass = 'text-warning';
            }
            
            echo '<div class="col-12 col-sm-6 col-md-3">';
            echo '<div class="info-box shadow-sm">';
            echo '<span class="info-box-icon ' . $box['bg'] . ' elevation-1"><i class="fas ' . $box['icon'] . '"></i></span>';
            echo '<div class="info-box-content">';
            echo '<span class="info-box-text">' . $box['text'] . '</span>';
            echo '<span class="info-box-number font-weight-bold ' . $textClass . '">' . $score . '% <small class="text-muted">Avg Health</small></span>';
            echo '</div>';
            echo '</div>';
            echo '</div>';
        }
        ?>
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
              <?php
              // Store Health Audit Breakdown - Dynamic Data
              // Reuse the resourceTypes and calculateSeoHealth from above
              $db = getDbConnection(); // Re-establish db connection for this section
              foreach ($resourceTypes as $key => $resource) {
                  $tableName = $resource['table'];
                  $totalItems = 0;
                  $draftCount = 0;
                  $seoScores = [];
                  $allIssues = [];
                  $itemsWithIssues = 0;
                  
                  if ($db) {
                      try {
                          // Count total items for this store
                          $countStmt = $db->prepare("SELECT COUNT(*) FROM `$tableName` WHERE store_key = :store");
                          $countStmt->execute([':store' => $activeStore]);
                          $totalItems = (int)$countStmt->fetchColumn();
                          
                          // Count drafts
                          $draftStmt = $db->prepare("SELECT COUNT(*) FROM `$tableName` WHERE store_key = :store AND status = 'draft'");
                          $draftStmt->execute([':store' => $activeStore]);
                          $draftCount = (int)$draftStmt->fetchColumn();
                          
                          // Get items for SEO analysis (limit to first 100 for performance)
                          if ($totalItems > 0) {
                              $itemsStmt = $db->prepare("SELECT `$resource[title_field]`, `$resource[meta_field]`, `$resource[handle_field]` FROM `$tableName` WHERE store_key = :store LIMIT 100");
                              $itemsStmt->execute([':store' => $activeStore]);
                              $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
                              
                              foreach ($items as $item) {
                                  $title = $item[$resource['title_field']] ?? '';
                                  $meta = $item[$resource['meta_field']] ?? '';
                                  $handle = $item[$resource['handle_field']] ?? '';
                                  
                                  $seoAnalysis = calculateSeoHealth($title, $meta, $handle);
                                  $seoScores[] = $seoAnalysis['score'];
                                  
                                  if (!empty($seoAnalysis['issues'])) {
                                      $allIssues = array_merge($allIssues, $seoAnalysis['issues']);
                                      $itemsWithIssues++;
                                  }
                              }
                          }
                      } catch (Exception $e) {
                          // Table doesn't exist or other error
                          $totalItems = 0;
                          $draftCount = 0;
                          $seoScores = [];
                      }
                  }
                  
                  // Calculate average SEO score
                  $avgSeoScore = !empty($seoScores) ? round(array_sum($seoScores) / count($seoScores)) : 100;
                  
                  // Determine score badge class
                  $scoreBadgeClass = 'badge-success';
                  if ($avgSeoScore < 70) {
                      $scoreBadgeClass = 'badge-danger';
                  } elseif ($avgSeoScore < 85) {
                      $scoreBadgeClass = 'badge-warning';
                  }
                  
                  // Generate issue summary
                  if (empty($allIssues) && $itemsWithIssues === 0) {
                      $issueSummary = '<span class="text-success"><i class="fas fa-check-circle mr-1"></i> 0 Critical issues</span>';
                  } else {
                      $uniqueIssues = array_unique($allIssues);
                      $issueText = implode(', ', array_slice($uniqueIssues, 0, 3)); // Show first 3 issues
                      if (count($uniqueIssues) > 3) {
                          $issueText .= '...';
                      }
                      $issueSummary = '<span class="text-danger"><i class="fas fa-exclamation-triangle mr-1"></i> ' . $itemsWithIssues . ' Items need optimization</span>';
                  }
                  
                  // Display row
                  echo '<tr>';
                  echo '<td><strong><i class="fas ' . $resource['icon'] . ' mr-2"></i> ' . $resource['name'] . '</strong></td>';
                  echo '<td>' . $totalItems . ' ' . ($key === 'blogs' ? 'Articles' : ($key === 'collections' ? 'Collections' : 'Items')) . '</td>';
                  echo '<td><span class="badge ' . $scoreBadgeClass . ' font-weight-bold">' . $avgSeoScore . '%</span></td>';
                  echo '<td>' . $issueSummary . '</td>';
                  echo '<td><span class="badge badge-secondary">' . $draftCount . ' Draft' . ($draftCount !== 1 ? 's' : '') . '</span></td>';
                  echo '<td><a href="' . $resource['module'] . '" class="btn btn-sm btn-primary">Open Module</a></td>';
                  echo '</tr>';
              }
              ?>
            </tbody>
          </table>
        </div>
      </div>
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
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>