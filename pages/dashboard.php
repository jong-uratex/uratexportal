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

// Handle token generation callback
if (isset($_GET['code']) && isset($_GET['hmac']) && isset($_GET['shop'])) {
    handleShopifyOAuthCallback();
}

// Function to update settings in database
function updateSetting($handle, $value) {
    $db = getDbConnection();
    if (!$db) {
        return false;
    }
    try {
        $stmt = $db->prepare("INSERT INTO `settings` (`handle`, `keys`) VALUES (:handle, :value) ON DUPLICATE KEY UPDATE `keys` = :value");
        $stmt->execute([
            ':handle' => $handle,
            ':value' => $value
        ]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

// Function to handle Shopify OAuth callback
function handleShopifyOAuthCallback() {
    global $shopConfig;
    
    $code = $_GET['code'] ?? '';
    $shop = $_GET['shop'] ?? '';
    $hmac = $_GET['hmac'] ?? '';
    
    if (empty($code) || empty($shop)) {
        header("Location: dashboard.php?error=missing_params");
        exit;
    }
    
    // Get client_id and client_secret from database
    $db = getDbConnection();
    $clientId = '';
    $clientSecret = '';
    
    if ($db) {
        try {
            $stmt = $db->prepare("SELECT `keys` FROM `settings` WHERE `handle` = 'client_id'");
            $stmt->execute();
            $result = $stmt->fetch();
            $clientId = $result['keys'] ?? '';
            
            $stmt = $db->prepare("SELECT `keys` FROM `settings` WHERE `handle` = 'secret'");
            $stmt->execute();
            $result = $stmt->fetch();
            $clientSecret = $result['keys'] ?? '';
        } catch (Exception $e) {
            header("Location: dashboard.php?error=db_error");
            exit;
        }
    }
    
    if (empty($clientId) || empty($clientSecret)) {
        header("Location: dashboard.php?error=missing_credentials");
        exit;
    }
    
    // Exchange code for access token
    $accessTokenUrl = "https://$shop/admin/oauth/access_token";
    $data = [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'code' => $code
    ];
    
    $ch = curl_init($accessTokenUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $tokenData = json_decode($response, true);
        $accessToken = $tokenData['access_token'] ?? '';
        
        if (!empty($accessToken)) {
            // Determine which store this is for
            $storeKey = 'retail';
            if (strpos($shop, 'business') !== false) {
                $storeKey = 'business';
            }
            
            // Update the access token in settings
            $handle = $storeKey . '_access_token';
            updateSetting($handle, $accessToken);
            
            // Also update the URL if not set
            if (empty($shopConfig[$storeKey]['url'])) {
                updateSetting($storeKey . '_url', $shop);
            }
            
            // Reload settings
            loadSettingsFromDatabase();
            
            header("Location: dashboard.php?success=token_updated");
            exit;
        }
    }
    
    header("Location: dashboard.php?error=token_failed");
    exit;
}

// Function to initiate OAuth flow
function initiateShopifyOAuth($storeKey) {
    global $shopConfig;
    
    $db = getDbConnection();
    $clientId = '';
    $clientSecret = '';
    
    if ($db) {
        try {
            $stmt = $db->query("SELECT `handle`, `keys` FROM `settings` WHERE `handle` IN ('client_id', 'secret')");
            $settings = $stmt->fetchAll();
            foreach ($settings as $setting) {
                if ($setting['handle'] === 'client_id') {
                    $clientId = $setting['keys'];
                } elseif ($setting['handle'] === 'secret') {
                    $clientSecret = $setting['keys'];
                }
            }
        } catch (Exception $e) {
            return false;
        }
    }
    
    if (empty($clientId) || empty($clientSecret)) {
        return false;
    }
    
    $store = $shopConfig[$storeKey];
    $shopUrl = $store['url'] ?? '';
    
    if (empty($shopUrl)) {
        return false;
    }
    
    $scopes = 'read_products,write_products,read_collections,write_collections,read_pages,write_pages,read_blogs,write_blogs';
    
    // Use protocol-relative URL for the redirect to work in both HTTP and HTTPS
    $currentProtocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
    $redirectUri = urlencode($currentProtocol . $_SERVER['HTTP_HOST'] . '/pages/dashboard.php');
    
    $authUrl = "https://$shopUrl/admin/oauth/authorize?client_id=$clientId&scope=$scopes&redirect_uri=$redirectUri";
    
    return $authUrl;
}

$pageTitle = 'Dashboard - SEO Health & Analytics';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';

// Display success/error messages
if (isset($_GET['success'])) {
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert">';
    echo '<i class="fas fa-check-circle mr-2"></i>';
    if ($_GET['success'] === 'token_updated') {
        echo 'New access token generated and saved successfully!';
    }
    echo '<button type="button" class="close" data-dismiss="alert" aria-label="Close">';
    echo '<span aria-hidden="true">&times;</span>';
    echo '</button></div>';
}

if (isset($_GET['error'])) {
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
    echo '<i class="fas fa-exclamation-circle mr-2"></i>';
    switch ($_GET['error']) {
        case 'missing_params':
            echo 'Missing OAuth parameters. Please try again.';
            break;
        case 'missing_credentials':
            echo 'Shopify app credentials not configured. Please add client_id and secret to settings.';
            break;
        case 'db_error':
            echo 'Database error occurred. Please try again.';
            break;
        case 'token_failed':
            echo 'Failed to generate access token. Please check your credentials.';
            break;
        default:
            echo 'An error occurred during token generation.';
    }
    echo '<button type="button" class="close" data-dismiss="alert" aria-label="Close">';
    echo '<span aria-hidden="true">&times;</span>';
    echo '</button></div>';
}

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
          <?php
          $activeStore = $_SESSION['active_store'] ?? 'business';
          $oauthUrl = initiateShopifyOAuth($activeStore);
          if ($oauthUrl):
              echo '<a href="' . htmlspecialchars($oauthUrl) . '" class="btn btn-info mr-2"><i class="fas fa-key mr-1"></i> Generate New Token</a>';
          else:
              echo '<button class="btn btn-info mr-2" disabled><i class="fas fa-key mr-1"></i> Generate Token (Setup Required)</button>';
          endif;
          ?>
          <button class="btn btn-success"><i class="fas fa-check-double mr-1"></i> Bulk Approve & Push</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Main content -->
  <section class="content">
    <div class="container-fluid">
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
