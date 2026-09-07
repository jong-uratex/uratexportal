<?php
/**
 * Orders Viewer Module (orders.php) - Uratex Shopify SEO Partner Portal
 *
 * Features:
 *  1. Displays all orders from Shopify REST API
 *  2. Saves & persists orders in MySQL table `shopify_orders`
 *  3. 20 Orders Per Page Pagination
 *  4. Shows customer details, order details, and fulfillment status
 *  5. Filter orders by status
 */
require_once __DIR__ . '/../config/config.php';

// Auth Guard
if (!isset($_SESSION['user_logged_in'])) {
    header("Location: ../login.php");
    exit;
}

// Get active store from session
$activeStore = $_SESSION['active_store'] ?? 'retail';
$shopCfg = $shopConfig[$activeStore] ?? [];
$currentUser = $_SESSION['user_name'] ?? 'Jenor Ricafort';
$userRole = $_SESSION['user_role'] ?? 'admin';
$message = '';
$messageType = 'success';

// Pagination settings
$perPage = 20;
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($page - 1) * $perPage;

// Search filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Status filter
$filterStatus = isset($_GET['status']) ? trim($_GET['status']) : '';

// Database connection
$db = getDbConnection();

/**
 * Returns the best .myshopify.com domain for Admin API calls.
 */
function getShopifyAdminDomain(array $shopCfg, string $activeStore): string
{
    if (!empty($shopCfg['url'])) {
        return $shopCfg['url'];
    }
    if (!empty($shopCfg['fallback_url'])) {
        return $shopCfg['fallback_url'];
    }
    return ($activeStore === 'business')
        ? 'uratex-business.myshopify.com'
        : 'uratex-philippines.myshopify.com';
}

/**
 * Build extra query-string params (search + filters) for links.
 */
function buildExtraQuery(string $search, string $filterStatus): string
{
    $params = [];
    if ($search !== '') {
        $params[] = 'search=' . urlencode($search);
    }
    if ($filterStatus !== '') {
        $params[] = 'status=' . urlencode($filterStatus);
    }
    return $params ? '&' . implode('&', $params) : '';
}

// -----------------------------------------------------------------------------
// AUTO-CREATE / MIGRATE TABLE (safe - IF NOT EXISTS)
// -----------------------------------------------------------------------------
if ($db) {
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `shopify_orders` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_key` VARCHAR(50) NOT NULL DEFAULT 'retail',
                `shopify_order_id` BIGINT UNSIGNED NOT NULL,
                `customer_id` BIGINT UNSIGNED NOT NULL,
                `full_name` VARCHAR(255) NULL DEFAULT NULL,
                `email` VARCHAR(255) NULL DEFAULT NULL,
                `phone` VARCHAR(50) NULL DEFAULT NULL,
                `shipping_address` TEXT NULL DEFAULT NULL,
                `billing_address` TEXT NULL DEFAULT NULL,
                `order_number` VARCHAR(50) NULL DEFAULT NULL,
                `total_price` DECIMAL(12,2) DEFAULT 0.00,
                `financial_status` VARCHAR(50) NULL DEFAULT NULL,
                `fulfillment_status` VARCHAR(50) NULL DEFAULT NULL,
                `fulfillment_details` TEXT NULL DEFAULT NULL,
                `created_at` DATETIME NULL DEFAULT NULL,
                `updated_at` DATETIME NULL DEFAULT NULL,
                `line_items` JSON NULL DEFAULT NULL,
                `shipping_city` VARCHAR(255) NULL DEFAULT NULL,
                `shipping_zip` VARCHAR(50) NULL DEFAULT NULL,
                `last_synced_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_store_order` (`store_key`, `shopify_order_id`),
                KEY `idx_orders_store` (`store_key`),
                KEY `idx_orders_customer` (`customer_id`),
                KEY `idx_orders_status` (`fulfillment_status`),
                KEY `idx_orders_financial` (`financial_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } catch (PDOException $e) {
        // keep going
    }
}

// -----------------------------------------------------------------------------
// SYNC ORDERS DATA FROM SHOPIFY API
// -----------------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'sync_orders') {
    $targetUrl = getShopifyAdminDomain($shopCfg, $activeStore);
    $version   = !empty($shopCfg['version']) ? $shopCfg['version'] : '2025-10';
    $token     = $shopCfg['access_token'] ?? '';

    if (empty($targetUrl) || empty($token)) {
        $missing = [];
        if (empty($targetUrl)) $missing[] = 'Store URL';
        if (empty($token)) $missing[] = 'Access Token';
        $message     = 'Missing configuration: ' . implode(', ', $missing) . ' for ' . $activeStore . ' store. Please check Settings.';
        $messageType = 'danger';
    } else {
        try {
            $allOrders = [];
            $pageInfo  = null;
            $hasMore   = true;
            $pageNum   = 1;

            // Fetch orders with proper Shopify cursor pagination (Link header)
            while ($hasMore && $pageNum <= 50) {
                $endpoint = '/admin/api/' . $version . '/orders.json?limit=250';
                if ($pageInfo) {
                    // When using page_info, don't include status parameter (Shopify REST API restriction)
                    $endpoint .= '&page_info=' . urlencode($pageInfo);
                } else {
                    // Only include status on first request
                    $endpoint .= '&status=any';
                }
                $url = "https://" . trim($targetUrl, '/') . $endpoint;

                $headers = [
                    "Content-Type: application/json",
                    "X-Shopify-Access-Token: {$token}"
                ];

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 60);
                curl_setopt($ch, CURLOPT_HEADER, true);

                $rawResponse = curl_exec($ch);
                $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $headerSize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                curl_close($ch);

                $headerStr = substr($rawResponse, 0, $headerSize);
                $body      = substr($rawResponse, $headerSize);

                if ($httpCode !== 200) {
                    $errorData = json_decode($body, true);
                    $errorMsg = 'HTTP ' . $httpCode;
                    
                    if (is_array($errorData)) {
                        if (!empty($errorData['errors'])) {
                            if (is_string($errorData['errors'])) {
                                $errorMsg = $errorData['errors'];
                            } elseif (is_array($errorData['errors'])) {
                                $errorMsg = json_encode($errorData['errors']);
                            }
                        } elseif (!empty($errorData['error_description'])) {
                            $errorMsg = $errorData['error_description'];
                        } elseif (!empty($errorData['error'])) {
                            $errorMsg = $errorData['error'];
                        }
                    }
                    
                    // If we still have a non-string error, convert it
                    if (!is_string($errorMsg)) {
                        $errorMsg = print_r($errorData, true) ?: ('HTTP ' . $httpCode);
                    }
                    
                    throw new Exception('API Error (orders): ' . $errorMsg . ' | URL: ' . $url);
                }

                $data = json_decode($body, true);
                if (!empty($data['orders'])) {
                    $allOrders = array_merge($allOrders, $data['orders']);
                }

                // Parse Link header for next page_info
                $pageInfo = null;
                $hasMore  = false;
                if (preg_match('/<([^>]+)>;\s*rel="next"/i', $headerStr, $m)) {
                    $nextUrl = $m[1];
                    if (preg_match('/page_info=([^&]+)/', $nextUrl, $pm)) {
                        $pageInfo = $pm[1];
                        $hasMore  = true;
                    }
                }

                if (empty($data['orders']) || count($data['orders']) < 250) {
                    $hasMore = false;
                }

                $pageNum++;
            }

            // Save orders into shopify_orders
            if ($db && !empty($allOrders)) {
                $insertStmt = $db->prepare("
                    INSERT INTO shopify_orders (
                        store_key, shopify_order_id, customer_id, full_name, email, phone,
                        shipping_address, billing_address, order_number, total_price,
                        financial_status, fulfillment_status, fulfillment_details,
                        created_at, updated_at, line_items, shipping_city, shipping_zip, last_synced_at
                    ) VALUES (
                        :store_key, :shopify_order_id, :customer_id, :full_name, :email, :phone,
                        :shipping_address, :billing_address, :order_number, :total_price,
                        :financial_status, :fulfillment_status, :fulfillment_details,
                        :created_at, :updated_at, :line_items, :shipping_city, :shipping_zip, NOW()
                    )
                    ON DUPLICATE KEY UPDATE
                        customer_id = VALUES(customer_id),
                        full_name = VALUES(full_name),
                        email = VALUES(email),
                        phone = VALUES(phone),
                        shipping_address = VALUES(shipping_address),
                        billing_address = VALUES(billing_address),
                        order_number = VALUES(order_number),
                        total_price = VALUES(total_price),
                        financial_status = VALUES(financial_status),
                        fulfillment_status = VALUES(fulfillment_status),
                        fulfillment_details = VALUES(fulfillment_details),
                        created_at = VALUES(created_at),
                        updated_at = VALUES(updated_at),
                        line_items = VALUES(line_items),
                        shipping_city = VALUES(shipping_city),
                        shipping_zip = VALUES(shipping_zip),
                        last_synced_at = NOW()
                ");

                foreach ($allOrders as $order) {
                    $shopifyOrderId = $order['id'] ?? 0;
                    $customerId = $order['customer']['id'] ?? 0;
                    $fullName = '';
                    $email = '';
                    $phone = '';

                    // Get customer info
                    if (!empty($order['customer'])) {
                        $fullName = ($order['customer']['first_name'] ?? '') . ' ' . ($order['customer']['last_name'] ?? '');
                        $fullName = trim($fullName);
                        $email = $order['customer']['email'] ?? '';
                        $phone = $order['customer']['phone'] ?? '';
                    }

                    // Shipping address
                    $shippingAddress = '';
                    if (!empty($order['shipping_address'])) {
                        $sa = $order['shipping_address'];
                        $shippingAddress = ($sa['first_name'] ?? '') . ' ' . ($sa['last_name'] ?? '') . '\n';
                        $shippingAddress .= ($sa['address1'] ?? '') . '\n';
                        $shippingAddress .= ($sa['address2'] ?? '') . '\n';
                        $shippingAddress .= ($sa['city'] ?? '') . ', ' . ($sa['province'] ?? '') . ' ' . ($sa['zip'] ?? '') . '\n';
                        $shippingAddress .= ($sa['country'] ?? '');
                    }

                    // Billing address
                    $billingAddress = '';
                    if (!empty($order['billing_address'])) {
                        $ba = $order['billing_address'];
                        $billingAddress = ($ba['first_name'] ?? '') . ' ' . ($ba['last_name'] ?? '') . '\n';
                        $billingAddress .= ($ba['address1'] ?? '') . '\n';
                        $billingAddress .= ($ba['address2'] ?? '') . '\n';
                        $billingAddress .= ($ba['city'] ?? '') . ', ' . ($ba['province'] ?? '') . ' ' . ($ba['zip'] ?? '') . '\n';
                        $billingAddress .= ($ba['country'] ?? '');
                    }

                    $orderNumber = $order['name'] ?? ($order['order_number'] ?? '');
                    $totalPrice = (float)($order['total_price'] ?? 0);
                    $financialStatus = $order['financial_status'] ?? '';
                    $fulfillmentStatus = $order['fulfillment_status'] ?? '';

                    // Fulfillment details
                    $fulfillmentDetails = '';
                    if (!empty($order['fulfillments']) && is_array($order['fulfillments'])) {
                        foreach ($order['fulfillments'] as $fulfillment) {
                            $fulfillmentDetails .= 'Created: ' . ($fulfillment['created_at'] ?? 'N/A') . '\n';
                            $fulfillmentDetails .= 'Status: ' . ($fulfillment['status'] ?? 'N/A') . '\n';
                            if (!empty($fulfillment['line_items']) && is_array($fulfillment['line_items'])) {
                                foreach ($fulfillment['line_items'] as $item) {
                                    $fulfillmentDetails .= '  - ' . ($item['name'] ?? 'N/A') . ': ' . ($item['quantity'] ?? 0) . '\n';
                                }
                            }
                            $fulfillmentDetails .= '\n';
                        }
                    }

                    $createdAt = !empty($order['created_at']) ? date('Y-m-d H:i:s', strtotime($order['created_at'])) : null;
                    $updatedAt = !empty($order['updated_at']) ? date('Y-m-d H:i:s', strtotime($order['updated_at'])) : null;
                    $lineItems = json_encode($order['line_items'] ?? []);
                    $shippingCity = $order['shipping_address']['city'] ?? '';
                    $shippingZip = $order['shipping_address']['zip'] ?? '';

                    $insertStmt->execute([
                        ':store_key' => $activeStore,
                        ':shopify_order_id' => $shopifyOrderId,
                        ':customer_id' => $customerId,
                        ':full_name' => $fullName,
                        ':email' => $email,
                        ':phone' => $phone,
                        ':shipping_address' => $shippingAddress,
                        ':billing_address' => $billingAddress,
                        ':order_number' => $orderNumber,
                        ':total_price' => $totalPrice,
                        ':financial_status' => $financialStatus,
                        ':fulfillment_status' => $fulfillmentStatus,
                        ':fulfillment_details' => $fulfillmentDetails,
                        ':created_at' => $createdAt,
                        ':updated_at' => $updatedAt,
                        ':line_items' => $lineItems,
                        ':shipping_city' => $shippingCity,
                        ':shipping_zip' => $shippingZip
                    ]);
                }

                $message     = 'Successfully synced ' . count($allOrders) . ' orders from ' . $activeStore . ' store into the database.';
                $messageType = 'success';

                if (function_exists('recordUserLog')) {
                    recordUserLog('Sync Orders', 'Shopify API', "Synced " . count($allOrders) . " orders from {$activeStore} store");
                }
            } else {
                $message     = 'No orders returned from Shopify API.';
                $messageType = 'warning';
            }
        } catch (Exception $e) {
            $message     = 'Error syncing orders: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// -----------------------------------------------------------------------------
// FETCH ORDERS FROM DATABASE (shopify_orders table)
// -----------------------------------------------------------------------------
$orders = [];
$totalRows = 0;

if ($db) {
    try {
        $where  = "store_key = :store";
        $params = [':store' => $activeStore];

        if ($search !== '') {
            $where .= " AND (order_number LIKE :search OR full_name LIKE :search OR email LIKE :search OR phone LIKE :search OR shipping_city LIKE :search)";
            $params[':search'] = "%{$search}%";
        }
        if ($filterStatus !== '') {
            $where .= " AND fulfillment_status = :status";
            $params[':status'] = $filterStatus;
        }

        $countStmt = $db->prepare("SELECT COUNT(*) as total FROM shopify_orders WHERE {$where}");
        $countStmt->execute($params);
        $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
        $totalRows   = (int)($countResult['total'] ?? 0);

        $query = "SELECT * FROM shopify_orders WHERE {$where} ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $stmt  = $db->prepare($query);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->execute();
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $orders = [];
        $totalRows = 0;
    }
}

$totalPages = (int)ceil(max(1, $totalRows) / $perPage);
$extraQuery = buildExtraQuery($search, $filterStatus);

// -----------------------------------------------------------------------------
// TEST CONNECTION
// -----------------------------------------------------------------------------
$testResults = [];
$allSuccess  = true;

if (isset($_POST['action']) && $_POST['action'] === 'test_connection') {
    $targetUrl = getShopifyAdminDomain($shopCfg, $activeStore);
    $version   = !empty($shopCfg['version']) ? $shopCfg['version'] : '2025-10';
    $token     = $shopCfg['access_token'] ?? '';

    $results    = [];
    $allSuccess = true;

    if (empty($token)) {
        $results[]  = "❌ Access Token: MISSING – No access token found for {$activeStore} store";
        $allSuccess = false;
    } else {
        // Orders count - no status parameter needed for count endpoint
        $testUrl = "https://" . trim($targetUrl, '/') . "/admin/api/{$version}/orders/count.json";
        $headers = [
            "Content-Type: application/json",
            "X-Shopify-Access-Token: {$token}"
        ];
        $ch = curl_init($testUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            $data      = json_decode($response, true);
            $count     = $data['count'] ?? 0;
            $results[] = "✅ Pull Orders: SUCCESS – Found {$count} orders in {$activeStore} store";
        } else {
            $errorData = json_decode($response, true);
            $errorMsg = 'HTTP ' . $httpCode;
            if (is_array($errorData) && !empty($errorData['errors'])) {
                $errorMsg = is_string($errorData['errors']) ? $errorData['errors'] : json_encode($errorData['errors']);
            } elseif (is_array($errorData) && !empty($errorData['error'])) {
                $errorMsg = $errorData['error'];
            }
            $results[]  = "❌ Pull Orders: FAILED – {$errorMsg} | URL: {$testUrl}";
            $allSuccess = false;
        }
    }

    $testResults = $results;
    $message     = $allSuccess ? 'Connection test passed for ' . $activeStore . ' store!' : 'Connection test failed for ' . $activeStore . ' store.';
    $messageType = $allSuccess ? 'success' : 'danger';
}

// -----------------------------------------------------------------------------
// CSV EXPORT
// -----------------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $filename = $activeStore . '_orders_' . date('Y-m-d') . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    fputcsv($output, [
        'Order ID',
        'Order Number',
        'Customer ID',
        'Full Name',
        'Email',
        'Phone',
        'Shipping Address',
        'Billing Address',
        'Total Price',
        'Financial Status',
        'Fulfillment Status',
        'Created At',
        'Updated At'
    ]);

    if ($db) {
        try {
            $where  = "store_key = :store";
            $params = [':store' => $activeStore];

            if ($search !== '') {
                $where .= " AND (order_number LIKE :search OR full_name LIKE :search OR email LIKE :search OR phone LIKE :search OR shipping_city LIKE :search)";
                $params[':search'] = "%{$search}%";
            }
            if ($filterStatus !== '') {
                $where .= " AND fulfillment_status = :status";
                $params[':status'] = $filterStatus;
            }

            $stmt = $db->prepare("SELECT * FROM shopify_orders WHERE {$where} ORDER BY created_at DESC");
            $stmt->execute($params);
            $allOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($allOrders as $order) {
                fputcsv($output, [
                    $order['shopify_order_id'] ?? '',
                    $order['order_number'] ?? '',
                    $order['customer_id'] ?? '',
                    $order['full_name'] ?? '',
                    $order['email'] ?? '',
                    $order['phone'] ?? '',
                    $order['shipping_address'] ?? '',
                    $order['billing_address'] ?? '',
                    $order['total_price'] ?? '',
                    $order['financial_status'] ?? '',
                    $order['fulfillment_status'] ?? '',
                    $order['created_at'] ?? '',
                    $order['updated_at'] ?? ''
                ]);
            }
        } catch (Exception $e) {
            // silent
        }
    }

    fclose($output);
    exit;
}

// -----------------------------------------------------------------------------
// PAGE RENDER
// -----------------------------------------------------------------------------
$pageTitle = 'Orders';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<!-- Content Wrapper -->
<div class="content-wrapper">
  <!-- Content Header -->
  <div class="content-header bg-white border-bottom pb-3 mb-3">
    <div class="container-fluid">
      <div class="row align-items-center">
        <div class="col-sm-8">
          <h1 class="m-0 font-weight-bold text-dark" style="color: #003399 !important; font-size: 24px; letter-spacing: -0.5px;">
            <i class="fas fa-shopping-cart mr-2"></i><?php echo ucfirst($activeStore); ?> Orders
          </h1>
          <p class="text-muted small mb-0 mt-1">
            View and manage all orders with customer details, order information, and fulfillment status.
          </p>
        </div>
        <div class="col-sm-4 text-right">
          <form method="post" class="d-inline-block mr-2">
            <input type="hidden" name="action" value="test_connection">
            <button type="submit" class="btn btn-info btn-sm shadow-sm font-weight-bold" style="background-color: #003399; border-color: #002266;">
              <i class="fas fa-plug mr-1"></i> Test Connection
            </button>
          </form>
          <form method="post" class="d-inline-block mr-2" onsubmit="return confirm('This will pull all orders from Shopify into the database. Continue?');">
            <input type="hidden" name="action" value="sync_orders">
            <button type="submit" class="btn btn-warning btn-sm shadow-sm font-weight-bold">
              <i class="fas fa-sync-alt mr-1"></i> Sync Orders Data
            </button>
          </form>
          <a href="orders.php?export=csv<?php echo $extraQuery; ?>" class="btn btn-success btn-sm shadow-sm font-weight-bold">
            <i class="fas fa-file-csv mr-1"></i> Export CSV
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- Main Content -->
  <section class="content">
    <div class="container-fluid">

      <!-- Connection Test Results -->
      <?php if (!empty($testResults)): ?>
        <div class="row mb-3">
          <div class="col-12">
            <div class="card border-<?php echo $allSuccess ? 'success' : 'danger'; ?>">
              <div class="card-header bg-<?php echo $allSuccess ? 'success' : 'danger'; ?> text-white">
                <h3 class="card-title mb-0"><i class="fas fa-network-wired mr-2"></i>Connection Test Results</h3>
              </div>
              <div class="card-body">
                <?php foreach ($testResults as $result): ?>
                  <p class="mb-1"><?php echo $result; ?></p>
                <?php endforeach; ?>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- Message Alert -->
      <?php if (!empty($message)): ?>
        <div class="row mb-3">
          <div class="col-12">
            <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show" role="alert">
              <i class="fas fa-<?php echo $messageType === 'success' ? 'check-circle' : 'exclamation-circle'; ?> mr-2"></i>
              <?php echo htmlspecialchars($message); ?>
              <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                <span aria-hidden="true">&times;</span>
              </button>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <!-- Stats Summary Cards -->
      <div class="row mb-3">
        <div class="col-12 col-sm-6 col-md-3">
          <div class="info-box shadow-sm border">
            <span class="info-box-icon bg-primary elevation-1" style="background-color: #003399 !important;">
              <i class="fas fa-shopping-cart"></i>
            </span>
            <div class="info-box-content">
              <span class="info-box-text text-muted text-uppercase font-weight-bold" style="font-size: 11px;">Total Orders</span>
              <span class="info-box-number font-weight-bold text-dark" style="font-size: 20px;">
                <?php
                $overallCount = 0;
                if ($db) {
                    try {
                        $r = $db->query("SELECT COUNT(*) as total FROM shopify_orders WHERE store_key = '{$activeStore}'")->fetch(PDO::FETCH_ASSOC);
                        $overallCount = (int)($r['total'] ?? 0);
                    } catch (Exception $e) {}
                }
                echo number_format($overallCount);
                ?>
              </span>
            </div>
          </div>
        </div>

        <div class="col-12 col-sm-6 col-md-3">
          <div class="info-box shadow-sm border">
            <span class="info-box-icon bg-success elevation-1">
              <i class="fas fa-check-circle"></i>
            </span>
            <div class="info-box-content">
              <span class="info-box-text text-muted text-uppercase font-weight-bold" style="font-size: 11px;">Fulfilled</span>
              <span class="info-box-number font-weight-bold text-success" style="font-size: 20px;">
                <?php
                $fulfilledCount = 0;
                if ($db) {
                    try {
                        $r = $db->query("SELECT COUNT(*) as total FROM shopify_orders WHERE store_key = '{$activeStore}' AND fulfillment_status = 'fulfilled'")->fetch(PDO::FETCH_ASSOC);
                        $fulfilledCount = (int)($r['total'] ?? 0);
                    } catch (Exception $e) {}
                }
                echo number_format($fulfilledCount);
                ?>
              </span>
            </div>
          </div>
        </div>

        <div class="col-12 col-sm-6 col-md-3">
          <div class="info-box shadow-sm border">
            <span class="info-box-icon bg-warning elevation-1 text-white">
              <i class="fas fa-clock"></i>
            </span>
            <div class="info-box-content">
              <span class="info-box-text text-muted text-uppercase font-weight-bold" style="font-size: 11px;">Unfulfilled</span>
              <span class="info-box-number font-weight-bold text-warning" style="font-size: 20px;">
                <?php
                $unfulfilledCount = 0;
                if ($db) {
                    try {
                        $r = $db->query("SELECT COUNT(*) as total FROM shopify_orders WHERE store_key = '{$activeStore}' AND fulfillment_status = 'unfulfilled'")->fetch(PDO::FETCH_ASSOC);
                        $unfulfilledCount = (int)($r['total'] ?? 0);
                    } catch (Exception $e) {}
                }
                echo number_format($unfulfilledCount);
                ?>
              </span>
            </div>
          </div>
        </div>

        <div class="col-12 col-sm-6 col-md-3">
          <div class="info-box shadow-sm border">
            <span class="info-box-icon bg-danger elevation-1 text-white">
              <i class="fas fa-times-circle"></i>
            </span>
            <div class="info-box-content">
              <span class="info-box-text text-muted text-uppercase font-weight-bold" style="font-size: 11px;">Partial</span>
              <span class="info-box-number font-weight-bold text-danger" style="font-size: 20px;">
                <?php
                $partialCount = 0;
                if ($db) {
                    try {
                        $r = $db->query("SELECT COUNT(*) as total FROM shopify_orders WHERE store_key = '{$activeStore}' AND fulfillment_status = 'partial'")->fetch(PDO::FETCH_ASSOC);
                        $partialCount = (int)($r['total'] ?? 0);
                    } catch (Exception $e) {}
                }
                echo number_format($partialCount);
                ?>
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Search & Filters Container -->
      <div class="card shadow-sm border-0 mb-3" style="border-radius: 12px;">
        <div class="card-body p-3">
          <form method="get" action="orders.php" class="row align-items-center">
            <div class="col-lg-4 col-md-5 col-12 mb-2 mb-md-0">
              <div class="input-group">
                <div class="input-group-prepend">
                  <span class="input-group-text bg-white border-right-0 text-muted"><i class="fas fa-search"></i></span>
                </div>
                <input
                  type="text"
                  name="search"
                  class="form-control border-left-0 text-sm"
                  placeholder="Search by order #, name, email, phone..."
                  value="<?php echo htmlspecialchars($search); ?>"
                >
                <?php if ($filterStatus !== ''): ?>
                  <input type="hidden" name="status" value="<?php echo htmlspecialchars($filterStatus); ?>">
                <?php endif; ?>
                <div class="input-group-append">
                  <button type="submit" class="btn btn-primary" style="background-color: #003399; border-color: #002266;">
                    <i class="fas fa-search"></i> Search
                  </button>
                </div>
              </div>
            </div>

            <div class="col-lg-8 col-md-7 col-12 text-md-right mt-2 mt-md-0">
              <div class="d-flex flex-wrap justify-content-md-end" style="gap: 6px;">
                <a href="orders.php?status=fulfilled<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>"
                   class="btn btn-sm shadow-sm font-weight-bold <?php echo $filterStatus === 'fulfilled' ? 'btn-success' : 'btn-outline-success'; ?>">
                  <i class="fas fa-check-circle mr-1"></i> Fulfilled
                </a>
                <a href="orders.php?status=unfulfilled<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>"
                   class="btn btn-sm shadow-sm font-weight-bold <?php echo $filterStatus === 'unfulfilled' ? 'btn-warning' : 'btn-outline-warning'; ?>">
                  <i class="fas fa-clock mr-1"></i> Unfulfilled
                </a>
                <a href="orders.php?status=partial<?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>"
                   class="btn btn-sm shadow-sm font-weight-bold <?php echo $filterStatus === 'partial' ? 'btn-danger' : 'btn-outline-danger'; ?>">
                  <i class="fas fa-exclamation-triangle mr-1"></i> Partial
                </a>
                <?php if ($filterStatus !== ''): ?>
                  <a href="orders.php<?php echo $search !== '' ? '?search=' . urlencode($search) : ''; ?>"
                     class="btn btn-sm shadow-sm font-weight-bold btn-outline-secondary">
                    <i class="fas fa-times mr-1"></i> Clear Filter
                  </a>
                <?php endif; ?>
              </div>
            </div>
          </form>
        </div>
      </div>

      <!-- Orders Table -->
      <div class="card shadow-sm border-0" style="border-radius: 12px;">
        <div class="card-header bg-primary text-white" style="background-color: #003399; border-radius: 12px 12px 0 0 !important;">
          <h3 class="card-title mb-0">
            <i class="fas fa-table mr-2"></i>
            <?php
            if ($filterStatus !== '') {
                echo 'Orders with Status: ' . ucfirst($filterStatus);
            } else {
                echo 'All ' . ucfirst($activeStore) . ' Orders';
            }
            ?>
          </h3>
          <div class="card-tools">
            <span class="badge bg-white text-primary font-weight-bold" style="font-size: 12px;">
              <?php echo number_format($totalRows); ?> orders
            </span>
          </div>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-bordered table-striped m-0" style="border-radius: 0 0 12px 12px;">
              <thead class="thead-light">
                <tr>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Order ID</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Order #</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Customer</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Email</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Phone</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Address</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Total</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Financial Status</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Fulfillment Status</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Order Date</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($orders)): ?>
                  <tr>
                    <td colspan="10" class="text-center py-4 text-muted">
                      <i class="fas fa-inbox fa-2x mb-2"></i><br>
                      No orders found<?php echo ($filterStatus !== '' || $search !== '') ? ' matching your filters.' : '.'; ?>
                      <?php if ($totalRows === 0 && $filterStatus === '' && $search === ''): ?>
                        <br><small>Click <strong>Sync Orders Data</strong> to pull orders from Shopify into the database.</small>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($orders as $order): ?>
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                      <td style="padding: 12px; font-size: 13px; white-space: nowrap;">
                        <code class="text-muted"><?php echo htmlspecialchars($order['shopify_order_id'] ?? ''); ?></code>
                      </td>
                      <td style="padding: 12px; font-size: 13px; white-space: nowrap;">
                        <strong><?php echo htmlspecialchars($order['order_number'] ?? ''); ?></strong>
                      </td>
                      <td style="padding: 12px; font-size: 13px;">
                        <?php echo htmlspecialchars($order['full_name'] ?? 'N/A'); ?>
                        <br><small class="text-muted">ID: <?php echo htmlspecialchars($order['customer_id'] ?? ''); ?></small>
                      </td>
                      <td style="padding: 12px; font-size: 13px; white-space: nowrap;">
                        <a href="mailto:<?php echo htmlspecialchars($order['email'] ?? ''); ?>" class="text-primary">
                          <?php echo htmlspecialchars($order['email'] ?? 'N/A'); ?>
                        </a>
                      </td>
                      <td style="padding: 12px; font-size: 13px; white-space: nowrap;">
                        <?php echo htmlspecialchars($order['phone'] ?? 'N/A'); ?>
                      </td>
                      <td style="padding: 12px; font-size: 13px; max-width: 200px;">
                        <?php 
                        $shippingAddress = $order['shipping_address'] ?? '';
                        if (!empty($shippingAddress)) {
                            echo nl2br(htmlspecialchars($shippingAddress));
                        } else {
                            echo 'N/A';
                        }
                        ?>
                      </td>
                      <td style="padding: 12px; font-size: 13px; text-align: right;">
                        <span class="font-weight-bold">₱<?php echo number_format($order['total_price'] ?? 0, 2); ?></span>
                      </td>
                      <td style="padding: 12px; font-size: 13px; text-align: center;">
                        <?php
                        $financialStatus = $order['financial_status'] ?? 'N/A';
                        $financialBadge = 'bg-secondary';
                        if ($financialStatus === 'paid') {
                            $financialBadge = 'bg-success';
                        } elseif ($financialStatus === 'pending') {
                            $financialBadge = 'bg-warning';
                        } elseif ($financialStatus === 'refunded') {
                            $financialBadge = 'bg-danger';
                        }
                        ?>
                        <span class="badge <?php echo $financialBadge; ?> font-weight-bold" style="font-size: 11px; padding: 4px 8px;">
                          <?php echo htmlspecialchars($financialStatus); ?>
                        </span>
                      </td>
                      <td style="padding: 12px; font-size: 13px; text-align: center;">
                        <?php
                        $status = $order['fulfillment_status'] ?? 'N/A';
                        $badgeClass = 'bg-secondary text-white';
                        if ($status === 'fulfilled') {
                            $badgeClass = 'bg-success text-white';
                        } elseif ($status === 'partial') {
                            $badgeClass = 'bg-warning text-dark';
                        } elseif ($status === 'unfulfilled') {
                            $badgeClass = 'bg-danger text-white';
                        }
                        ?>
                        <span class="badge <?php echo $badgeClass; ?> font-weight-bold" style="font-size: 11px; padding: 4px 8px;">
                          <?php echo htmlspecialchars($status); ?>
                        </span>
                      </td>
                      <td style="padding: 12px; font-size: 13px; white-space: nowrap;">
                        <?php
                        $createdAt = $order['created_at'] ?? '';
                        if (!empty($createdAt)) {
                            try {
                                echo (new DateTime($createdAt))->format('Y-m-d H:i:s');
                            } catch (Exception $e) {
                                echo htmlspecialchars($createdAt);
                            }
                        } else {
                            echo 'N/A';
                        }
                        ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
          <div class="card-footer bg-white border-top py-3 px-4 d-flex flex-column flex-md-row justify-content-between align-items-center" style="border-radius: 0 0 12px 12px;">
            <div class="text-muted small mb-2 mb-md-0 font-weight-medium">
              Showing <span class="font-weight-bold text-dark"><?php echo number_format($offset + 1); ?></span> to
              <span class="font-weight-bold text-dark"><?php echo number_format(min($offset + $perPage, $totalRows)); ?></span> of
              <span class="font-weight-bold text-dark"><?php echo number_format($totalRows); ?></span> orders
            </div>

            <ul class="pagination pagination-sm m-0 shadow-none">
              <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                <a class="page-link" href="?page=<?php echo max(1, $page - 1); ?><?php echo $extraQuery; ?>">&laquo; Prev</a>
              </li>
              <?php
              $startP = max(1, $page - 3);
              $endP   = min($totalPages, $page + 3);
              if ($startP > 1) {
                  echo '<li class="page-item"><a class="page-link" href="?page=1' . $extraQuery . '">1</a></li>';
                  if ($startP > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
              }
              for ($p = $startP; $p <= $endP; $p++) {
                  $active = ($p === $page) ? 'active font-weight-bold' : '';
                  echo "<li class=\"page-item {$active}\"><a class=\"page-link\" href=\"orders.php?page={$p}{$extraQuery}\">{$p}</a></li>";
              }
              if ($endP < $totalPages) {
                  if ($endP < $totalPages - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                  echo '<li class="page-item"><a class="page-link" href="?page=' . $totalPages . $extraQuery . '">' . $totalPages . '</a></li>';
              }
              ?>
              <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                <a class="page-link" href="?page=<?php echo min($totalPages, $page + 1); ?><?php echo $extraQuery; ?>">Next &raquo;</a>
              </li>
            </ul>
          </div>
        <?php endif; ?>
      </div>

    </div>
  </section>
</div>

<?php
include __DIR__ . '/../includes/footer.php';
?>