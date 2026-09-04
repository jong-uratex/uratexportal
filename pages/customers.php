<?php
/**
 * Customers Viewer Module (customers.php) - Uratex Shopify SEO Partner Portal
 *
 * Features:
 *  1. Displays all customers from Shopify REST API for Retail store only
 *  2. Saves & persists customers in MySQL table `shopify_customers`
 *  3. 20 Customers Per Page Pagination
 *  4. Combines customer and order data
 *  5. Shows fulfillment status and marketing preferences
 */
require_once __DIR__ . '/../config/config.php';

// Auth Guard
if (!isset($_SESSION['user_logged_in'])) {
    header("Location: ../login.php");
    exit;
}

// Force retail store only as requested
$activeStore = 'retail';
$_SESSION['active_store'] = $activeStore;
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

// -----------------------------------------------------------------------------
// AUTO-CREATE / MIGRATE TABLE
// -----------------------------------------------------------------------------
if ($db) {
    try {
        // Create customers table if it doesn't exist
        $db->exec("
            CREATE TABLE IF NOT EXISTS `shopify_customers` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_key` VARCHAR(50) NOT NULL DEFAULT 'retail',
                `shopify_customer_id` BIGINT UNSIGNED NOT NULL,
                `email` VARCHAR(255) NOT NULL,
                `first_name` VARCHAR(255) NULL DEFAULT NULL,
                `last_name` VARCHAR(255) NULL DEFAULT NULL,
                `phone` VARCHAR(50) NULL DEFAULT NULL,
                `accepts_sms_marketing` BOOLEAN DEFAULT FALSE,
                `accepts_email_marketing` BOOLEAN DEFAULT FALSE,
                `total_orders` INT UNSIGNED DEFAULT 0,
                `total_spent` DECIMAL(12,2) DEFAULT 0.00,
                `shipping_city` VARCHAR(255) NULL DEFAULT NULL,
                `shipping_zip` VARCHAR(50) NULL DEFAULT NULL,
                `created_at` DATETIME NULL DEFAULT NULL,
                `updated_at` DATETIME NULL DEFAULT NULL,
                `last_synced_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_store_customer` (`store_key`, `shopify_customer_id`),
                KEY `idx_customers_store` (`store_key`),
                KEY `idx_customers_email` (`email`),
                KEY `idx_customers_name` (`last_name`, `first_name`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Create orders table if it doesn't exist
        $db->exec("
            CREATE TABLE IF NOT EXISTS `shopify_orders` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_key` VARCHAR(50) NOT NULL DEFAULT 'retail',
                `shopify_order_id` BIGINT UNSIGNED NOT NULL,
                `customer_id` BIGINT UNSIGNED NOT NULL,
                `order_number` VARCHAR(50) NULL DEFAULT NULL,
                `total_price` DECIMAL(12,2) DEFAULT 0.00,
                `financial_status` VARCHAR(50) NULL DEFAULT NULL,
                `fulfillment_status` VARCHAR(50) NULL DEFAULT NULL,
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
                KEY `idx_orders_status` (`fulfillment_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

    } catch (PDOException $e) {
        // keep going
    }
}

// -----------------------------------------------------------------------------
// SYNC CUSTOMERS FROM SHOPIFY API
// -----------------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'sync_customers') {
    $targetUrl = getShopifyAdminDomain($shopCfg, $activeStore);
    $version = !empty($shopCfg['version']) ? $shopCfg['version'] : '2025-10';
    $token = $shopCfg['access_token'] ?? '';

    if (empty($targetUrl) || empty($token)) {
        $message = 'Store configuration or access token missing for retail store.';
        $messageType = 'danger';
    } else {
        try {
            $allCustomers = [];
            $cursor = null;
            $hasMore = true;

            // Fetch customers with cursor pagination
            while ($hasMore) {
                $endpoint = '/admin/api/' . $version . '/customers.json?limit=250' . ($cursor ? '&cursor=' . urlencode($cursor) : '');
                $url = "https://{$targetUrl}{$endpoint}";

                $headers = [
                    "Content-Type: application/json",
                    "X-Shopify-Access-Token: {$token}"
                ];

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200) {
                    $data = json_decode($response, true);
                    if (!empty($data['customers'])) {
                        $allCustomers = array_merge($allCustomers, $data['customers']);
                    }
                    $cursor = $data['next_page_cursor'] ?? null;
                    $hasMore = $cursor !== null;
                } else {
                    $errorData = json_decode($response, true);
                    throw new Exception('API Error: ' . ($errorData['errors'] ?? 'HTTP ' . $httpCode));
                }
            }

            // Also fetch orders to get fulfillment status and order details
            $allOrders = [];
            $orderCursor = null;
            $hasMoreOrders = true;

            while ($hasMoreOrders) {
                $endpoint = '/admin/api/' . $version . '/orders.json?limit=250' . ($orderCursor ? '&cursor=' . urlencode($orderCursor) : '');
                $url = "https://{$targetUrl}{$endpoint}";

                $headers = [
                    "Content-Type: application/json",
                    "X-Shopify-Access-Token: {$token}"
                ];

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200) {
                    $data = json_decode($response, true);
                    if (!empty($data['orders'])) {
                        $allOrders = array_merge($allOrders, $data['orders']);
                    }
                    $orderCursor = $data['next_page_cursor'] ?? null;
                    $hasMoreOrders = $orderCursor !== null;
                } else {
                    break; // Skip orders if we can't fetch them
                }
            }

            // Save customers to database
            if ($db && !empty($allCustomers)) {
                $insertStmt = $db->prepare("
                    INSERT INTO shopify_customers (
                        store_key, shopify_customer_id, email, first_name, last_name, 
                        phone, accepts_sms_marketing, accepts_email_marketing,
                        total_orders, total_spent, shipping_city, shipping_zip,
                        created_at, updated_at, last_synced_at
                    ) VALUES (
                        :store, :cid, :email, :first_name, :last_name,
                        :phone, :sms_marketing, :email_marketing,
                        :total_orders, :total_spent, :shipping_city, :shipping_zip,
                        :created_at, :updated_at, NOW()
                    )
                    ON DUPLICATE KEY UPDATE
                        email = VALUES(email),
                        first_name = VALUES(first_name),
                        last_name = VALUES(last_name),
                        phone = VALUES(phone),
                        accepts_sms_marketing = VALUES(accepts_sms_marketing),
                        accepts_email_marketing = VALUES(accepts_email_marketing),
                        total_orders = VALUES(total_orders),
                        total_spent = VALUES(total_spent),
                        shipping_city = VALUES(shipping_city),
                        shipping_zip = VALUES(shipping_zip),
                        created_at = VALUES(created_at),
                        updated_at = VALUES(updated_at),
                        last_synced_at = NOW()
                ");

                foreach ($allCustomers as $customer) {
                    $cid = $customer['id'] ?? 0;
                    $email = $customer['email'] ?? '';
                    $firstName = $customer['first_name'] ?? '';
                    $lastName = $customer['last_name'] ?? '';
                    $phone = $customer['phone'] ?? '';
                    $acceptsSms = !empty($customer['accepts_sms_marketing']) ? 1 : 0;
                    $acceptsEmail = !empty($customer['accepts_email_marketing']) ? 1 : 0;
                    $totalOrders = (int)($customer['orders_count'] ?? 0);
                    $totalSpent = (float)($customer['total_spent'] ?? 0);

                    // Get shipping address
                    $shippingCity = '';
                    $shippingZip = '';
                    if (!empty($customer['default_address'])) {
                        $defaultAddress = $customer['default_address'];
                        $shippingCity = $defaultAddress['city'] ?? '';
                        $shippingZip = $defaultAddress['zip'] ?? '';
                    } elseif (!empty($customer['addresses']) && is_array($customer['addresses'])) {
                        $firstAddress = $customer['addresses'][0] ?? [];
                        $shippingCity = $firstAddress['city'] ?? '';
                        $shippingZip = $firstAddress['zip'] ?? '';
                    }

                    $createdAt = $customer['created_at'] ?? null;
                    $updatedAt = $customer['updated_at'] ?? null;

                    $insertStmt->execute([
                        ':store' => $activeStore,
                        ':cid' => $cid,
                        ':email' => $email,
                        ':first_name' => $firstName,
                        ':last_name' => $lastName,
                        ':phone' => $phone,
                        ':sms_marketing' => $acceptsSms,
                        ':email_marketing' => $acceptsEmail,
                        ':total_orders' => $totalOrders,
                        ':total_spent' => $totalSpent,
                        ':shipping_city' => $shippingCity,
                        ':shipping_zip' => $shippingZip,
                        ':created_at' => $createdAt,
                        ':updated_at' => $updatedAt
                    ]);
                }

                // Save orders to database
                if (!empty($allOrders)) {
                    $insertOrderStmt = $db->prepare("
                        INSERT INTO shopify_orders (
                            store_key, shopify_order_id, customer_id, order_number,
                            total_price, financial_status, fulfillment_status,
                            created_at, updated_at, line_items, shipping_city, shipping_zip, last_synced_at
                        ) VALUES (
                            :store, :oid, :customer_id, :order_number,
                            :total_price, :financial_status, :fulfillment_status,
                            :created_at, :updated_at, :line_items, :shipping_city, :shipping_zip, NOW()
                        )
                        ON DUPLICATE KEY UPDATE
                            customer_id = VALUES(customer_id),
                            order_number = VALUES(order_number),
                            total_price = VALUES(total_price),
                            financial_status = VALUES(financial_status),
                            fulfillment_status = VALUES(fulfillment_status),
                            created_at = VALUES(created_at),
                            updated_at = VALUES(updated_at),
                            line_items = VALUES(line_items),
                            shipping_city = VALUES(shipping_city),
                            shipping_zip = VALUES(shipping_zip),
                            last_synced_at = NOW()
                    ");

                    foreach ($allOrders as $order) {
                        $oid = $order['id'] ?? 0;
                        $customerId = $order['customer']['id'] ?? 0;
                        $orderNumber = $order['name'] ?? $order['order_number'] ?? '';
                        $totalPrice = (float)($order['total_price'] ?? 0);
                        $financialStatus = $order['financial_status'] ?? '';
                        $fulfillmentStatus = $order['fulfillment_status'] ?? '';
                        $createdAt = $order['created_at'] ?? null;
                        $updatedAt = $order['updated_at'] ?? null;
                        $lineItems = json_encode($order['line_items'] ?? []);

                        $shippingCity = '';
                        $shippingZip = '';
                        if (!empty($order['shipping_address'])) {
                            $shippingAddress = $order['shipping_address'];
                            $shippingCity = $shippingAddress['city'] ?? '';
                            $shippingZip = $shippingAddress['zip'] ?? '';
                        }

                        $insertOrderStmt->execute([
                            ':store' => $activeStore,
                            ':oid' => $oid,
                            ':customer_id' => $customerId,
                            ':order_number' => $orderNumber,
                            ':total_price' => $totalPrice,
                            ':financial_status' => $financialStatus,
                            ':fulfillment_status' => $fulfillmentStatus,
                            ':created_at' => $createdAt,
                            ':updated_at' => $updatedAt,
                            ':line_items' => $lineItems,
                            ':shipping_city' => $shippingCity,
                            ':shipping_zip' => $shippingZip
                        ]);
                    }
                }

                $message = 'Successfully synced ' . count($allCustomers) . ' customers and ' . count($allOrders) . ' orders from retail store.';
                $messageType = 'success';
                
                // Log the action
                recordUserLog('Sync Customers', 'Shopify API', "Synced " . count($allCustomers) . " customers and " . count($allOrders) . " orders from retail store");
            }
        } catch (Exception $e) {
            $message = 'Error syncing customers: ' . $e->getMessage();
            $messageType = 'danger';
        }
    }
}

// -----------------------------------------------------------------------------
// FETCH CUSTOMERS FROM DATABASE
// -----------------------------------------------------------------------------
$customers = [];
$totalRows = 0;

if ($db) {
    try {
        // Get total count for pagination
        $countQuery = "SELECT COUNT(*) as total FROM shopify_customers WHERE store_key = :store";
        $countParams = [':store' => $activeStore];
        
        if (!empty($search)) {
            $countQuery .= " AND (email LIKE :search OR first_name LIKE :search OR last_name LIKE :search OR phone LIKE :search OR shipping_city LIKE :search)";
            $countParams[':search'] = "%{$search}%";
        }
        
        $countStmt = $db->prepare($countQuery);
        $countStmt->execute($countParams);
        $countResult = $countStmt->fetch(PDO::FETCH_ASSOC);
        $totalRows = (int)($countResult['total'] ?? 0);

        // Get customers with pagination
        $query = "SELECT * FROM shopify_customers WHERE store_key = :store";
        $params = [':store' => $activeStore];
        
        if (!empty($search)) {
            $query .= " AND (email LIKE :search OR first_name LIKE :search OR last_name LIKE :search OR phone LIKE :search OR shipping_city LIKE :search)";
            $params[':search'] = "%{$search}%";
        }
        
        $query .= " ORDER BY last_name, first_name LIMIT :limit OFFSET :offset";
        
        $stmt = $db->prepare($query);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        
        foreach ($params as $key => $value) {
            if ($key !== ':limit' && $key !== ':offset') {
                $stmt->bindValue($key, $value);
            }
        }
        
        $stmt->execute();
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get orders for each customer to show product names and fulfillment status
        foreach ($customers as &$customer) {
            $customerId = $customer['shopify_customer_id'];
            
            // Get all orders for this customer
            $orderQuery = "SELECT * FROM shopify_orders WHERE store_key = :store AND customer_id = :customer_id ORDER BY created_at DESC";
            $orderStmt = $db->prepare($orderQuery);
            $orderStmt->execute([
                ':store' => $activeStore,
                ':customer_id' => $customerId
            ]);
            $orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Extract product names from orders
            $productNames = [];
            $fulfillmentStatuses = [];
            $orderDates = [];
            
            foreach ($orders as $order) {
                $lineItems = json_decode($order['line_items'], true);
                if (!empty($lineItems) && is_array($lineItems)) {
                    foreach ($lineItems as $item) {
                        if (!empty($item['name'])) {
                            $productNames[] = $item['name'];
                        }
                    }
                }
                if (!empty($order['fulfillment_status'])) {
                    $fulfillmentStatuses[] = $order['fulfillment_status'];
                }
                if (!empty($order['created_at'])) {
                    $orderDates[] = $order['created_at'];
                }
            }
            
            // Update customer data with order info
            $customer['product_names'] = implode(', ', array_unique($productNames));
            $customer['fulfillment_status'] = !empty($fulfillmentStatuses) ? implode(', ', $fulfillmentStatuses) : 'No Orders';
            $customer['order_count'] = count($orders);
            $customer['order_dates'] = !empty($orderDates) ? implode(', ', $orderDates) : '';
            $customer['latest_order_date'] = !empty($orderDates) ? end($orderDates) : '';
            
            // Format shipping info
            $customer['shipping_info'] = '';
            if (!empty($customer['shipping_city']) || !empty($customer['shipping_zip'])) {
                $customer['shipping_info'] = trim($customer['shipping_city'] . ' ' . $customer['shipping_zip']);
            }
        }

    } catch (Exception $e) {
        // Fallback to empty array
        $customers = [];
        $totalRows = 0;
    }
}

$totalPages = ceil(max(1, $totalRows) / $perPage);

// -----------------------------------------------------------------------------
// TEST CONNECTION FUNCTIONALITY
// -----------------------------------------------------------------------------
$testResults = [];
if (isset($_POST['action']) && $_POST['action'] === 'test_connection') {
    $targetUrl = getShopifyAdminDomain($shopCfg, $activeStore);
    $version = !empty($shopCfg['version']) ? $shopCfg['version'] : '2025-10';
    $token = $shopCfg['access_token'] ?? '';

    $results = [];
    $allSuccess = true;

    // Test Pull Customers
    if (empty($token)) {
        $results[] = "❌ Access Token: MISSING - No access token found for retail store";
        $allSuccess = false;
    } else {
        // Test customer count endpoint
        $testUrl = "https://{$targetUrl}/admin/api/{$version}/customers/count.json";
        
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
            $data = json_decode($response, true);
            $count = $data['count'] ?? 0;
            $results[] = "✅ Pull Customers: SUCCESS - Found {$count} customers in retail store";
        } else {
            $results[] = "❌ Pull Customers: FAILED - HTTP {$httpCode}";
            $allSuccess = false;
        }
    }

    // Test Pull Orders
    if (!empty($token)) {
        $testUrl = "https://{$targetUrl}/admin/api/{$version}/orders/count.json";
        
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
            $data = json_decode($response, true);
            $count = $data['count'] ?? 0;
            $results[] = "✅ Pull Orders: SUCCESS - Found {$count} orders in retail store";
        } else {
            $results[] = "❌ Pull Orders: FAILED - HTTP {$httpCode}";
            $allSuccess = false;
        }
    }

    $testResults = $results;
    if ($allSuccess) {
        $message = 'Connection test passed for retail store!';
        $messageType = 'success';
    } else {
        $message = 'Connection test failed for retail store.';
        $messageType = 'danger';
    }
}

$pageTitle = 'Retail Customers';
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
            <i class="fas fa-users mr-2"></i>Retail Customers
          </h1>
          <p class="text-muted small mb-0 mt-1">
            View and manage all customers from the retail store with their order details and fulfillment status.
          </p>
        </div>
        <div class="col-sm-4 text-right">
          <form method="post" class="d-inline-block mr-2">
            <input type="hidden" name="action" value="test_connection">
            <button type="submit" class="btn btn-info btn-sm shadow-sm font-weight-bold" style="background-color: #003399; border-color: #002266;">
              <i class="fas fa-plug mr-1"></i> Test Connection
            </button>
          </form>
          <form method="post" class="d-inline-block mr-2">
            <input type="hidden" name="action" value="sync_customers">
            <button type="submit" class="btn btn-warning btn-sm shadow-sm font-weight-bold">
              <i class="fas fa-sync-alt mr-1"></i> Sync Customers & Orders
            </button>
          </form>
          <a href="customers.php" class="btn btn-primary btn-sm shadow-sm font-weight-bold" style="background-color: #003399; border-color: #002266;">
            <i class="fas fa-redo-alt mr-1"></i> Refresh
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
              <i class="fas fa-users"></i>
            </span>
            <div class="info-box-content">
              <span class="info-box-text text-muted text-uppercase font-weight-bold" style="font-size: 11px;">Total Customers</span>
              <span class="info-box-number font-weight-bold text-dark" style="font-size: 20px;">
                <?php echo number_format($totalRows); ?>
              </span>
            </div>
          </div>
        </div>

        <div class="col-12 col-sm-6 col-md-3">
          <div class="info-box shadow-sm border">
            <span class="info-box-icon bg-success elevation-1">
              <i class="fas fa-shopping-cart"></i>
            </span>
            <div class="info-box-content">
              <span class="info-box-text text-muted text-uppercase font-weight-bold" style="font-size: 11px;">Total Orders</span>
              <span class="info-box-number font-weight-bold text-success" style="font-size: 20px;">
                <?php 
                $totalOrders = 0;
                if ($db) {
                    try {
                        $orderCount = $db->query("SELECT COUNT(*) as total FROM shopify_orders WHERE store_key = 'retail'");
                        $orderResult = $orderCount->fetch(PDO::FETCH_ASSOC);
                        $totalOrders = (int)($orderResult['total'] ?? 0);
                    } catch (Exception $e) {}
                }
                echo number_format($totalOrders);
                ?>
              </span>
            </div>
          </div>
        </div>

        <div class="col-12 col-sm-6 col-md-3">
          <div class="info-box shadow-sm border">
            <span class="info-box-icon bg-info elevation-1">
              <i class="fas fa-envelope-open-text"></i>
            </span>
            <div class="info-box-content">
              <span class="info-box-text text-muted text-uppercase font-weight-bold" style="font-size: 11px;">Email Marketing</span>
              <span class="info-box-number font-weight-bold text-info" style="font-size: 20px;">
                <?php 
                $emailMarketing = 0;
                if ($db) {
                    try {
                        $emailCount = $db->query("SELECT COUNT(*) as total FROM shopify_customers WHERE store_key = 'retail' AND accepts_email_marketing = 1");
                        $emailResult = $emailCount->fetch(PDO::FETCH_ASSOC);
                        $emailMarketing = (int)($emailResult['total'] ?? 0);
                    } catch (Exception $e) {}
                }
                echo number_format($emailMarketing);
                ?>
              </span>
            </div>
          </div>
        </div>

        <div class="col-12 col-sm-6 col-md-3">
          <div class="info-box shadow-sm border">
            <span class="info-box-icon bg-warning elevation-1 text-white">
              <i class="fas fa-comment-sms"></i>
            </span>
            <div class="info-box-content">
              <span class="info-box-text text-muted text-uppercase font-weight-bold" style="font-size: 11px;">SMS Marketing</span>
              <span class="info-box-number font-weight-bold text-warning" style="font-size: 20px;">
                <?php 
                $smsMarketing = 0;
                if ($db) {
                    try {
                        $smsCount = $db->query("SELECT COUNT(*) as total FROM shopify_customers WHERE store_key = 'retail' AND accepts_sms_marketing = 1");
                        $smsResult = $smsCount->fetch(PDO::FETCH_ASSOC);
                        $smsMarketing = (int)($smsResult['total'] ?? 0);
                    } catch (Exception $e) {}
                }
                echo number_format($smsMarketing);
                ?>
              </span>
            </div>
          </div>
        </div>
      </div>

      <!-- Search & Filters Container -->
      <div class="card shadow-sm border-0 mb-3" style="border-radius: 12px;">
        <div class="card-body p-3">
          <form method="get" action="customers.php" class="row align-items-center">
            <div class="col-md-6 col-12 mb-2 mb-md-0">
              <div class="input-group">
                <div class="input-group-prepend">
                  <span class="input-group-text bg-white border-right-0 text-muted"><i class="fas fa-search"></i></span>
                </div>
                <input 
                  type="text" 
                  name="search" 
                  class="form-control border-left-0 text-sm" 
                  placeholder="Search by email, name, phone, or city..."
                  value="<?php echo htmlspecialchars($search); ?>"
                >
                <div class="input-group-append">
                  <button type="submit" class="btn btn-primary" style="background-color: #003399; border-color: #002266;">
                    <i class="fas fa-search"></i> Search
                  </button>
                </div>
              </div>
            </div>

            <div class="col-md-6 text-right">
              <a href="customers.php?export=csv<?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" 
                 class="btn btn-outline-secondary btn-sm shadow-sm font-weight-bold">
                <i class="fas fa-file-csv mr-1 text-success"></i> Export CSV
              </a>
            </div>
          </form>
        </div>
      </div>

      <!-- Customers Table -->
      <div class="card shadow-sm border-0" style="border-radius: 12px;">
        <div class="card-header bg-primary text-white" style="background-color: #003399; border-radius: 12px 12px 0 0 !important;">
          <h3 class="card-title mb-0">
            <i class="fas fa-table mr-2"></i>All Retail Customers
          </h3>
          <div class="card-tools">
            <span class="badge bg-white text-primary font-weight-bold" style="font-size: 12px;">
              <?php echo number_format($totalRows); ?> customers
            </span>
          </div>
        </div>
        <div class="card-body p-0">
          <div class="table-responsive">
            <table class="table table-bordered table-striped m-0" style="border-radius: 0 0 12px 12px;">
              <thead class="thead-light">
                <tr>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Customer ID</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Last Name</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">First Name</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Email</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Contact Number</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Shipping City</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Shipping Zip</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Products Ordered</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Total Orders</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Order Date/Time</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Fulfillment Status</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Accepts SMS</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Accepts Email</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($customers)): ?>
                  <tr>
                    <td colspan="13" class="text-center py-4 text-muted">
                      <i class="fas fa-inbox fa-2x mb-2"></i><br>
                      No customers found. 
                      <a href="customers.php?action=sync_customers" class="text-primary">
                        <i class="fas fa-sync-alt"></i> Sync customers from Shopify
                      </a>
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($customers as $customer): ?>
                    <tr style="border-bottom: 1px solid #f1f5f9;">
                      <!-- Customer ID -->
                      <td style="padding: 12px; font-size: 13px; white-space: nowrap;">
                        <code class="text-muted"><?php echo htmlspecialchars($customer['shopify_customer_id'] ?? ''); ?></code>
                      </td>

                      <!-- Last Name -->
                      <td style="padding: 12px; font-size: 13px;">
                        <?php echo htmlspecialchars($customer['last_name'] ?? ''); ?>
                      </td>

                      <!-- First Name -->
                      <td style="padding: 12px; font-size: 13px;">
                        <?php echo htmlspecialchars($customer['first_name'] ?? ''); ?>
                      </td>

                      <!-- Email -->
                      <td style="padding: 12px; font-size: 13px; white-space: nowrap;">
                        <a href="mailto:<?php echo htmlspecialchars($customer['email'] ?? ''); ?>" class="text-primary">
                          <?php echo htmlspecialchars($customer['email'] ?? ''); ?>
                        </a>
                      </td>

                      <!-- Contact Number -->
                      <td style="padding: 12px; font-size: 13px; white-space: nowrap;">
                        <?php echo htmlspecialchars($customer['phone'] ?? 'N/A'); ?>
                      </td>

                      <!-- Shipping City -->
                      <td style="padding: 12px; font-size: 13px;">
                        <?php echo htmlspecialchars($customer['shipping_city'] ?? 'N/A'); ?>
                      </td>

                      <!-- Shipping Zip -->
                      <td style="padding: 12px; font-size: 13px;">
                        <?php echo htmlspecialchars($customer['shipping_zip'] ?? 'N/A'); ?>
                      </td>

                      <!-- Products Ordered -->
                      <td style="padding: 12px; font-size: 13px; max-width: 200px; word-wrap: break-word;">
                        <?php echo htmlspecialchars($customer['product_names'] ?? 'No products'); ?>
                      </td>

                      <!-- Total Number of Orders -->
                      <td style="padding: 12px; font-size: 13px; text-align: center;">
                        <span class="badge bg-info text-white font-weight-bold" style="font-size: 12px; padding: 4px 8px;">
                          <?php echo (int)($customer['order_count'] ?? 0); ?>
                        </span>
                      </td>

                      <!-- Date/time of order -->
                      <td style="padding: 12px; font-size: 13px; white-space: nowrap;">
                        <?php 
                        $latestDate = $customer['latest_order_date'] ?? ($customer['created_at'] ?? '');
                        if (!empty($latestDate)) {
                            $dateObj = new DateTime($latestDate);
                            echo $dateObj->format('Y-m-d H:i:s');
                        } else {
                            echo 'N/A';
                        }
                        ?>
                      </td>

                      <!-- Fulfillment Status -->
                      <td style="padding: 12px; font-size: 13px; white-space: nowrap;">
                        <span class="badge 
                          <?php 
                          $status = $customer['fulfillment_status'] ?? 'No Orders';
                          if (strpos($status, 'fulfilled') !== false) {
                              echo 'bg-success text-white';
                          } elseif (strpos($status, 'partial') !== false) {
                              echo 'bg-warning text-dark';
                          } elseif (strpos($status, 'unfulfilled') !== false) {
                              echo 'bg-danger text-white';
                          } else {
                              echo 'bg-secondary text-white';
                          }
                          ?>
                          font-weight-bold" style="font-size: 11px; padding: 4px 8px;">
                          <?php echo htmlspecialchars($status); ?>
                        </span>
                      </td>

                      <!-- Accepts SMS Marketing -->
                      <td style="padding: 12px; font-size: 13px; text-align: center;">
                        <?php if (!empty($customer['accepts_sms_marketing'])): ?>
                          <i class="fas fa-check-circle text-success fa-lg"></i>
                        <?php else: ?>
                          <i class="fas fa-times-circle text-muted fa-lg"></i>
                        <?php endif; ?>
                      </td>

                      <!-- Accepts Email Marketing -->
                      <td style="padding: 12px; font-size: 13px; text-align: center;">
                        <?php if (!empty($customer['accepts_email_marketing'])): ?>
                          <i class="fas fa-check-circle text-success fa-lg"></i>
                        <?php else: ?>
                          <i class="fas fa-times-circle text-muted fa-lg"></i>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Pagination Bar -->
        <?php if ($totalPages > 1): ?>
          <div class="card-footer bg-white border-top py-3 px-4 d-flex flex-column flex-md-row justify-content-between align-items-center" style="border-radius: 0 0 12px 12px;">
            <div class="text-muted small mb-2 mb-md-0 font-weight-medium">
              Showing <span class="font-weight-bold text-dark"><?php echo number_format($offset + 1); ?></span> to 
              <span class="font-weight-bold text-dark"><?php echo number_format(min($offset + $perPage, $totalRows)); ?></span> of 
              <span class="font-weight-bold text-dark"><?php echo number_format($totalRows); ?></span> customers
            </div>

            <ul class="pagination pagination-sm m-0 shadow-none">
              <!-- Previous Page -->
              <li class="page-item <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                <a class="page-link" href="?page=<?php echo $page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>">
                  &laquo; Prev
                </a>
              </li>

              <?php
                $startP = max(1, $page - 3);
                $endP = min($totalPages, $page + 3);
                if ($startP > 1) {
                    echo '<li class="page-item"><a class="page-link" href="?page=1' . (!empty($search) ? '&search=' . urlencode($search) : '') . '">1</a></li>';
                    if ($startP > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                }
                for ($p = $startP; $p <= $endP; $p++):
                    $active = ($p === $page) ? 'active font-weight-bold' : '';
                    echo "<li class='page-item {$active}'><a class='page-link' href='?page={$p}" . 
                         (!empty($search) ? '&search=' . urlencode($search) : '') . "'>{$p}</a></li>";
                endfor;
                if ($endP < $totalPages) {
                    if ($endP < $totalPages - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    echo '<li class="page-item"><a class="page-link" href="?page=' . $totalPages . (!empty($search) ? '&search=' . urlencode($search) : '') . '">' . $totalPages . '</a></li>';
                }
              ?>

              <!-- Next Page -->
              <li class="page-item <?php echo ($page >= $totalPages) ? 'disabled' : ''; ?>">
                <a class="page-link" href="?page=<?php echo $page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>">
                  Next &raquo;
                </a>
              </li>
            </ul>
          </div>
        <?php endif; ?>
      </div>

      <!-- CSV Export Handler -->
      <?php if (isset($_GET['export']) && $_GET['export'] === 'csv'): ?>
        <?php
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="retail_customers_' . date('Y-m-d') . '.csv"');

        $output = fopen('php://output', 'w');
        
        // CSV Header
        fputcsv($output, [
            'Customer ID',
            'Last Name',
            'First Name', 
            'Email',
            'Contact Number',
            'Shipping City',
            'Shipping Zip',
            'Products Ordered',
            'Total Orders',
            'Latest Order Date',
            'Fulfillment Status',
            'Accepts SMS Marketing',
            'Accepts Email Marketing'
        ]);

        // CSV Data
        if ($db) {
            try {
                $query = "SELECT * FROM shopify_customers WHERE store_key = :store";
                $params = [':store' => $activeStore];
                
                if (!empty($search)) {
                    $query .= " AND (email LIKE :search OR first_name LIKE :search OR last_name LIKE :search OR phone LIKE :search OR shipping_city LIKE :search)";
                    $params[':search'] = "%{$search}%";
                }
                
                $query .= " ORDER BY last_name, first_name";
                
                $stmt = $db->prepare($query);
                $stmt->execute($params);
                $allCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($allCustomers as $customer) {
                    $customerId = $customer['shopify_customer_id'];
                    
                    // Get orders for this customer
                    $orderQuery = "SELECT * FROM shopify_orders WHERE store_key = :store AND customer_id = :customer_id ORDER BY created_at DESC";
                    $orderStmt = $db->prepare($orderQuery);
                    $orderStmt->execute([
                        ':store' => $activeStore,
                        ':customer_id' => $customerId
                    ]);
                    $orders = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $productNames = [];
                    $fulfillmentStatuses = [];
                    $orderDates = [];
                    
                    foreach ($orders as $order) {
                        $lineItems = json_decode($order['line_items'], true);
                        if (!empty($lineItems) && is_array($lineItems)) {
                            foreach ($lineItems as $item) {
                                if (!empty($item['name'])) {
                                    $productNames[] = $item['name'];
                                }
                            }
                        }
                        if (!empty($order['fulfillment_status'])) {
                            $fulfillmentStatuses[] = $order['fulfillment_status'];
                        }
                        if (!empty($order['created_at'])) {
                            $orderDates[] = $order['created_at'];
                        }
                    }
                    
                    $latestDate = !empty($orderDates) ? end($orderDates) : '';
                    
                    fputcsv($output, [
                        $customer['shopify_customer_id'] ?? '',
                        $customer['last_name'] ?? '',
                        $customer['first_name'] ?? '',
                        $customer['email'] ?? '',
                        $customer['phone'] ?? '',
                        $customer['shipping_city'] ?? '',
                        $customer['shipping_zip'] ?? '',
                        implode(', ', array_unique($productNames)) ?? 'No products',
                        count($orders),
                        $latestDate,
                        !empty($fulfillmentStatuses) ? implode(', ', $fulfillmentStatuses) : 'No Orders',
                        !empty($customer['accepts_sms_marketing']) ? 'Yes' : 'No',
                        !empty($customer['accepts_email_marketing']) ? 'Yes' : 'No'
                    ]);
                }
            } catch (Exception $e) {}
        }
        
        fclose($output);
        exit;
        ?>
      <?php endif; ?>
    </div>
  </section>
</div>

<?php
include __DIR__ . '/../includes/footer.php';
?>

<script>
// Confirm before sync
$(document).ready(function() {
    $('form[action*="sync_customers"]').on('submit', function(e) {
        if (confirm('Are you sure you want to sync customers and orders from the retail store? This may take a few minutes.')) {
            return true;
        }
        return false;
    });
});
</script>