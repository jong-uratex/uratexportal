<?php
/**
 * Orders Viewer Module (orders.php) - Uratex Shopify SEO Partner Portal
 *
 * Features:
 *  1. Displays all orders from Shopify REST API
 *  2. Saves & persists orders in MySQL table `shopify_orders`
 *  3. 20 Orders Per Page Pagination
 *  4. Shows customer details, order details (products), and fulfillment status
 *  5. Filter orders by status
 *  6. Admin and Customer Service access
 *  7. Syncs Accepts Email / SMS Marketing from customer data
 *  8. Export with Email / Export with SMS (custom columns)
 *  9. Syncs & displays Order Details (product names + qty)
 */
require_once __DIR__ . '/../config/config.php';

// Auth Guard – login required
if (!isset($_SESSION['user_logged_in'])) {
    header("Location: ../login.php");
    exit;
}

// Admin and Customer Service access
if (!in_array($_SESSION['user_role'] ?? 'editor', ['admin', 'customer_service'], true)) {
    header("Location: dashboard.php");
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

$exportDateRanges = [
    'all'        => 'All dates',
    '24_hours'   => '24 hours',
    '48_hours'   => '48 hours',
    '1_week'     => '1 week',
    '2_weeks'    => '2 weeks',
    '1_month'    => '1 month',
    '3_months'   => '3 months',
    '1_year'     => '1 year',
    'custom'     => 'Custom date'
];
$requestedExportDateRange = $_GET['date_range'] ?? 'all';
$selectedExportDateRange = is_string($requestedExportDateRange) ? $requestedExportDateRange : 'all';
$exportDateFromInput = isset($_GET['date_from']) && is_string($_GET['date_from']) ? $_GET['date_from'] : '';
$exportDateToInput = isset($_GET['date_to']) && is_string($_GET['date_to']) ? $_GET['date_to'] : '';
$exportDateFrom = '';
$exportDateTo = '';
$exportDateParams = [];
$exportDateDescription = $exportDateRanges[$selectedExportDateRange] ?? 'All dates';

if (isset($_GET['export']) && in_array($_GET['export'], ['email', 'sms'], true)) {
    if (!is_string($requestedExportDateRange) || !array_key_exists($requestedExportDateRange, $exportDateRanges)) {
        http_response_code(400);
        exit('Invalid export date range.');
    }

    if ($selectedExportDateRange === 'custom') {
        $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $exportDateFromInput);
        $endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $exportDateToInput);

        if (
            !$startDate || !$endDate ||
            $startDate->format('Y-m-d') !== $exportDateFromInput ||
            $endDate->format('Y-m-d') !== $exportDateToInput ||
            $startDate > $endDate
        ) {
            http_response_code(400);
            exit('Select a valid custom start and end date for the export.');
        }

        $exportDateFrom = $startDate->format('Y-m-d 00:00:00');
        $exportDateTo = $endDate->format('Y-m-d 23:59:59');
        $exportDateParams = [
            ':created_from' => $exportDateFrom,
            ':created_to' => $exportDateTo
        ];
        $exportDateDescription = $exportDateFromInput . ' to ' . $exportDateToInput;
    } elseif ($selectedExportDateRange !== 'all') {
        $relativeRanges = [
            '24_hours' => '-24 hours',
            '48_hours' => '-48 hours',
            '1_week' => '-1 week',
            '2_weeks' => '-2 weeks',
            '1_month' => '-1 month',
            '3_months' => '-3 months',
            '1_year' => '-1 year'
        ];
        $now = new DateTimeImmutable();
        $exportDateFrom = $now->modify($relativeRanges[$selectedExportDateRange])->format('Y-m-d H:i:s');
        $exportDateTo = $now->format('Y-m-d H:i:s');
        $exportDateParams = [
            ':created_from' => $exportDateFrom,
            ':created_to' => $exportDateTo
        ];
    }
}

$analyticsEndDate = new DateTimeImmutable('today');
$analyticsStartDate = $analyticsEndDate->modify('-29 days');
$analyticsFromInput = $analyticsStartDate->format('Y-m-d');
$analyticsToInput = $analyticsEndDate->format('Y-m-d');
$analyticsError = '';

if (isset($_GET['analytics_from']) || isset($_GET['analytics_to'])) {
    $requestedAnalyticsFrom = $_GET['analytics_from'] ?? '';
    $requestedAnalyticsTo = $_GET['analytics_to'] ?? '';
    $requestedAnalyticsFrom = is_string($requestedAnalyticsFrom) ? $requestedAnalyticsFrom : '';
    $requestedAnalyticsTo = is_string($requestedAnalyticsTo) ? $requestedAnalyticsTo : '';
    $requestedStartDate = DateTimeImmutable::createFromFormat('!Y-m-d', $requestedAnalyticsFrom);
    $requestedEndDate = DateTimeImmutable::createFromFormat('!Y-m-d', $requestedAnalyticsTo);

    if (
        !$requestedStartDate || !$requestedEndDate ||
        $requestedStartDate->format('Y-m-d') !== $requestedAnalyticsFrom ||
        $requestedEndDate->format('Y-m-d') !== $requestedAnalyticsTo ||
        $requestedStartDate > $requestedEndDate
    ) {
        $analyticsError = 'Select a valid analytics start and end date.';
    } else {
        $analyticsStartDate = $requestedStartDate;
        $analyticsEndDate = $requestedEndDate;
        $analyticsFromInput = $requestedAnalyticsFrom;
        $analyticsToInput = $requestedAnalyticsTo;
    }
}

$analyticsParams = [
    ':s' => $activeStore,
    ':analytics_from' => $analyticsStartDate->format('Y-m-d 00:00:00'),
    ':analytics_to' => $analyticsEndDate->format('Y-m-d 23:59:59'),
];
$analyticsWhere = 'store_key = :s AND created_at >= :analytics_from AND created_at <= :analytics_to';

/**
 * Normalize marketing consent value to "yes" / "no"
 */
function normalizeMarketingConsent($value): string
{
    if ($value === true || $value === 1 || $value === '1' || strtolower((string)$value) === 'true' || strtolower((string)$value) === 'yes' || strtolower((string)$value) === 'subscribed') {
        return 'yes';
    }
    return 'no';
}

/**
 * Build a human-readable Order Details string from Shopify line_items.
 * Example: "Uratex Foam Mattress x2\nPillow Set x1"
 */
function buildOrderDetails(array $lineItems): string
{
    $parts = [];
    foreach ($lineItems as $item) {
        $name = trim($item['name'] ?? $item['title'] ?? 'Unknown Product');
        $qty  = (int)($item['quantity'] ?? 1);
        if ($name !== '') {
            $parts[] = $name . ' x' . $qty;
        }
    }
    return implode("\n", $parts);
}

function getOrderItemExportFields(?string $lineItems, ?string $orderDetails): array
{
    $items = json_decode($lineItems ?? '', true);
    if (!is_array($items)) {
        return [$orderDetails ?? '', ''];
    }

    $descriptions = [];
    $quantities = [];
    foreach ($items as $item) {
        $description = trim($item['name'] ?? $item['title'] ?? 'Unknown Product');
        if ($description === '') {
            continue;
        }

        $descriptions[] = $description;
        $quantities[] = (string) ((int) ($item['quantity'] ?? 1));
    }

    return [implode("\n", $descriptions), implode("\n", $quantities)];
}

// -----------------------------------------------------------------------------
// AUTO-CREATE / MIGRATE TABLE (safe - IF NOT EXISTS + add missing columns)
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
                `accepts_email_marketing` VARCHAR(10) NULL DEFAULT NULL,
                `accepts_sms_marketing` VARCHAR(10) NULL DEFAULT NULL,
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
                `order_details` TEXT NULL DEFAULT NULL,
                `shipping_city` VARCHAR(255) NULL DEFAULT NULL,
                `shipping_zip` VARCHAR(50) NULL DEFAULT NULL,
                `delivery_date` DATETIME NULL DEFAULT NULL,
                `last_synced_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_store_order` (`store_key`, `shopify_order_id`),
                KEY `idx_orders_store` (`store_key`),
                KEY `idx_orders_customer` (`customer_id`),
                KEY `idx_orders_status` (`fulfillment_status`),
                KEY `idx_orders_financial` (`financial_status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Ensure columns exist even if table was created earlier
        $columnDetails = $db->query("SHOW COLUMNS FROM `shopify_orders`")->fetchAll(PDO::FETCH_ASSOC);
        $cols = array_column($columnDetails, 'Field');

        if (!in_array('accepts_email_marketing', $cols, true)) {
            $db->exec("ALTER TABLE `shopify_orders` ADD COLUMN `accepts_email_marketing` VARCHAR(10) NULL DEFAULT NULL COMMENT 'yes / no' AFTER `phone`");
        }
        if (!in_array('accepts_sms_marketing', $cols, true)) {
            $db->exec("ALTER TABLE `shopify_orders` ADD COLUMN `accepts_sms_marketing` VARCHAR(10) NULL DEFAULT NULL COMMENT 'yes / no' AFTER `accepts_email_marketing`");
        }
        if (!in_array('order_details', $cols, true)) {
            $db->exec("ALTER TABLE `shopify_orders` ADD COLUMN `order_details` TEXT NULL DEFAULT NULL COMMENT 'Product name(s) + qty' AFTER `line_items`");
        }
        if (!in_array('delivery_date', $cols, true)) {
            $db->exec("ALTER TABLE `shopify_orders` ADD COLUMN `delivery_date` DATETIME NULL DEFAULT NULL AFTER `shipping_zip`");
        } else {
            foreach ($columnDetails as $column) {
                if ($column['Field'] === 'delivery_date' && $column['Null'] === 'NO') {
                    $db->exec("ALTER TABLE `shopify_orders` MODIFY COLUMN `delivery_date` DATETIME NULL DEFAULT NULL");
                    break;
                }
            }
        }
    } catch (PDOException $e) {
        $message = 'Unable to prepare the orders database table: ' . $e->getMessage();
        $messageType = 'danger';
        recordUserLog('Orders Database Setup Failed', 'Database', $message, 'system', null, 'error');
    }
}

// -----------------------------------------------------------------------------
// SYNC ORDERS DATA FROM SHOPIFY API
// -----------------------------------------------------------------------------
if (isset($_POST['action']) && $_POST['action'] === 'sync_orders') {
    @set_time_limit(0);
    @ini_set('max_execution_time', '300');
    @ini_set('memory_limit', '512M');

    $targetUrl = getShopifyAdminDomain($shopCfg, $activeStore);
    $version   = !empty($shopCfg['version']) ? $shopCfg['version'] : '2025-10';
    $token     = $shopCfg['access_token'] ?? '';

    if (empty($targetUrl) || empty($token)) {
        $missing = [];
        if (empty($targetUrl)) $missing[] = 'Store URL';
        if (empty($token)) $missing[] = 'Access Token';
        $message     = 'Missing configuration: ' . implode(', ', $missing) . ' for ' . $activeStore . ' store. Please check Settings.';
        $messageType = 'danger';
        recordUserLog('Sync Orders Failed', 'Shopify API', "Orders sync aborted for {$activeStore} store — missing: " . implode(', ', $missing) . '.', 'system', null, 'error');
    } else {
        try {
            $isAjaxSync = !empty($_POST['ajax']);
            $pageInfo   = ($isAjaxSync && !empty($_POST['page_info'])) ? (string)$_POST['page_info'] : null;
            $hasMore    = true;
            $pageNum    = 1;
            $syncCount  = 0;
            // Small batches per request keep each call under the web server timeout.
            $maxPages   = $isAjaxSync ? 2 : 400;

            if (!$db || $messageType === 'danger') {
                throw new Exception($message !== '' ? $message : 'Database connection is unavailable.');
            }

            $latestOrderStmt = $db->prepare("
                SELECT shopify_order_id
                FROM shopify_orders
                WHERE store_key = :store
                  AND order_number IS NOT NULL
                  AND order_number <> ''
                ORDER BY CAST(REPLACE(order_number, '#', '') AS UNSIGNED) DESC, shopify_order_id DESC
                LIMIT 1
            ");
            $latestOrderStmt->execute([':store' => $activeStore]);
            $latestOrderId = (int)$latestOrderStmt->fetchColumn();

            $insertStmt = $db->prepare("
                INSERT INTO shopify_orders (
                    store_key, shopify_order_id, customer_id, full_name, email, phone,
                    accepts_email_marketing, accepts_sms_marketing,
                    shipping_address, billing_address, order_number, total_price,
                    financial_status, fulfillment_status, fulfillment_details,
                    created_at, updated_at, line_items, order_details, shipping_city, shipping_zip, delivery_date, last_synced_at
                ) VALUES (
                    :store_key, :shopify_order_id, :customer_id, :full_name, :email, :phone,
                    :accepts_email_marketing, :accepts_sms_marketing,
                    :shipping_address, :billing_address, :order_number, :total_price,
                    :financial_status, :fulfillment_status, :fulfillment_details,
                    :created_at, :updated_at, :line_items, :order_details, :shipping_city, :shipping_zip, :delivery_date, NOW()
                )
                ON DUPLICATE KEY UPDATE
                    customer_id = VALUES(customer_id),
                    full_name = VALUES(full_name),
                    email = VALUES(email),
                    phone = VALUES(phone),
                    accepts_email_marketing = VALUES(accepts_email_marketing),
                    accepts_sms_marketing = VALUES(accepts_sms_marketing),
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
                    order_details = VALUES(order_details),
                    shipping_city = VALUES(shipping_city),
                    shipping_zip = VALUES(shipping_zip),
                    delivery_date = VALUES(delivery_date),
                    last_synced_at = NOW()
            ");

            while ($hasMore && $pageNum <= $maxPages) {
                $endpoint = '/admin/api/' . $version . '/orders.json?limit=250';
                if ($pageInfo) {
                    $endpoint .= '&page_info=' . urlencode($pageInfo);
                } else {
                    $endpoint .= '&status=any&order=created_at+desc';
                    if ($latestOrderId > 0) {
                        $endpoint .= '&since_id=' . $latestOrderId;
                    }
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
                curl_setopt($ch, CURLOPT_FAILONERROR, false);

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

                    if (!is_string($errorMsg)) {
                        $errorMsg = print_r($errorData, true) ?: ('HTTP ' . $httpCode);
                    }

                    throw new Exception('API Error (orders): ' . $errorMsg . ' | URL: ' . $url);
                }

                $data = json_decode($body, true);

                if (!empty($data['orders']) && $insertStmt) {
                    if ($db && !$db->inTransaction()) {
                        $db->beginTransaction();
                    }
                    foreach ($data['orders'] as $order) {
                        $syncCount++;

                        $shopifyOrderId = $order['id'] ?? 0;
                        $customerId     = $order['customer']['id'] ?? 0;

                        $fullName = '';
                        $email    = '';
                        $phone    = '';
                        $acceptsEmail = 'no';
                        $acceptsSms   = 'no';

                        if (!empty($order['customer'])) {
                            $c = $order['customer'];

                            $fullName = trim(
                                ($c['first_name'] ?? '') . ' ' .
                                ($c['last_name'] ?? '')
                            );
                            $email = $c['email'] ?? '';
                            $phone = $c['phone'] ?? '';

                            if (isset($c['email_marketing_consent']['state'])) {
                                $acceptsEmail = normalizeMarketingConsent($c['email_marketing_consent']['state']);
                            } elseif (isset($c['accepts_marketing'])) {
                                $acceptsEmail = normalizeMarketingConsent($c['accepts_marketing']);
                            }

                            if (isset($c['sms_marketing_consent']['state'])) {
                                $acceptsSms = normalizeMarketingConsent($c['sms_marketing_consent']['state']);
                            }
                        }

                        // Shipping address
                        $shippingAddress = '';
                        if (!empty($order['shipping_address'])) {
                            $sa = $order['shipping_address'];
                            $shippingAddress  = trim(($sa['first_name'] ?? '') . ' ' . ($sa['last_name'] ?? '')) . "\n";
                            $shippingAddress .= ($sa['address1'] ?? '') . "\n";
                            $shippingAddress .= ($sa['address2'] ?? '') . "\n";
                            $shippingAddress .= ($sa['city'] ?? '') . ', ' . ($sa['province'] ?? '') . ' ' . ($sa['zip'] ?? '') . "\n";
                            $shippingAddress .= ($sa['country'] ?? '');
                        }

                        // Billing address
                        $billingAddress = '';
                        if (!empty($order['billing_address'])) {
                            $ba = $order['billing_address'];
                            $billingAddress  = trim(($ba['first_name'] ?? '') . ' ' . ($ba['last_name'] ?? '')) . "\n";
                            $billingAddress .= ($ba['address1'] ?? '') . "\n";
                            $billingAddress .= ($ba['address2'] ?? '') . "\n";
                            $billingAddress .= ($ba['city'] ?? '') . ', ' . ($ba['province'] ?? '') . ' ' . ($ba['zip'] ?? '') . "\n";
                            $billingAddress .= ($ba['country'] ?? '');
                        }

                        $orderNumber       = $order['name'] ?? ($order['order_number'] ?? '');
                        $totalPrice        = (float)($order['total_price'] ?? 0);
                        $financialStatus   = $order['financial_status'] ?? '';
                        $fulfillmentStatus = $order['fulfillment_status'] ?? '';

                        // Fulfillment details
                        $fulfillmentDetails = '';
                        if (!empty($order['fulfillments']) && is_array($order['fulfillments'])) {
                            foreach ($order['fulfillments'] as $fulfillment) {
                                $fulfillmentDetails .= 'Created: ' . ($fulfillment['created_at'] ?? 'N/A') . "\n";
                                $fulfillmentDetails .= 'Status: ' . ($fulfillment['status'] ?? 'N/A') . "\n";
                                if (!empty($fulfillment['line_items']) && is_array($fulfillment['line_items'])) {
                                    foreach ($fulfillment['line_items'] as $item) {
                                        $fulfillmentDetails .= '  - ' . ($item['name'] ?? 'N/A') . ': ' . ($item['quantity'] ?? 0) . "\n";
                                    }
                                }
                                $fulfillmentDetails .= "\n";
                            }
                        }

                        $createdAt = !empty($order['created_at'])
                            ? date('Y-m-d H:i:s', strtotime($order['created_at']))
                            : null;
                        $updatedAt = !empty($order['updated_at'])
                            ? date('Y-m-d H:i:s', strtotime($order['updated_at']))
                            : null;

                        $deliveryDate = null;
                        if (!empty($order['fulfillments']) && is_array($order['fulfillments'])) {
                            foreach ($order['fulfillments'] as $fulfillment) {
                                if (empty($fulfillment['created_at'])) {
                                    continue;
                                }
                                $fulfillmentTimestamp = strtotime($fulfillment['created_at']);
                                if ($fulfillmentTimestamp !== false && ($deliveryDate === null || $fulfillmentTimestamp > strtotime($deliveryDate))) {
                                    $deliveryDate = date('Y-m-d H:i:s', $fulfillmentTimestamp);
                                }
                            }
                        }
                        if ($deliveryDate === null && !empty($order['closed_at'])) {
                            $closedTimestamp = strtotime($order['closed_at']);
                            if ($closedTimestamp !== false) {
                                $deliveryDate = date('Y-m-d H:i:s', $closedTimestamp);
                            }
                        }

                        $rawLineItems = $order['line_items'] ?? [];
                        $lineItems    = json_encode($rawLineItems);
                        $orderDetails = buildOrderDetails(is_array($rawLineItems) ? $rawLineItems : []);

                        $shippingCity = $order['shipping_address']['city'] ?? '';
                        $shippingZip  = $order['shipping_address']['zip'] ?? '';

                        $insertStmt->execute([
                            ':store_key'                => $activeStore,
                            ':shopify_order_id'         => $shopifyOrderId,
                            ':customer_id'              => $customerId,
                            ':full_name'                => $fullName,
                            ':email'                    => $email,
                            ':phone'                    => $phone,
                            ':accepts_email_marketing'  => $acceptsEmail,
                            ':accepts_sms_marketing'    => $acceptsSms,
                            ':shipping_address'         => $shippingAddress,
                            ':billing_address'          => $billingAddress,
                            ':order_number'             => $orderNumber,
                            ':total_price'              => $totalPrice,
                            ':financial_status'         => $financialStatus,
                            ':fulfillment_status'       => $fulfillmentStatus,
                            ':fulfillment_details'      => $fulfillmentDetails,
                            ':created_at'               => $createdAt,
                            ':updated_at'               => $updatedAt,
                            ':line_items'               => $lineItems,
                            ':order_details'            => $orderDetails,
                            ':shipping_city'            => $shippingCity,
                            ':shipping_zip'             => $shippingZip,
                            ':delivery_date'            => $deliveryDate
                        ]);
                    }
                    if ($db && $db->inTransaction()) {
                        $db->commit();
                    }
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

            if ($isAjaxSync) {
                header('Content-Type: application/json');
                echo json_encode(['ok' => true, 'synced' => $syncCount, 'next' => $hasMore ? $pageInfo : null]);
                exit;
            }

            $message     = 'Successfully synced ' . number_format($syncCount) . ' orders from ' . $activeStore . ' store into the database.';
            $messageType = 'success';

            if (function_exists('recordUserLog')) {
                recordUserLog('Sync Orders', 'Shopify API', "Synced {$syncCount} orders from {$activeStore} store");
            }

        } catch (Exception $e) {
            if (!empty($isAjaxSync)) {
                if ($db && $db->inTransaction()) {
                    $db->rollBack();
                }
                header('Content-Type: application/json');
                echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
                exit;
            }
            $message     = 'Error syncing orders: ' . $e->getMessage();
            $messageType = 'danger';
            recordUserLog('Sync Orders Failed', 'Shopify API', "Orders sync failed for {$activeStore} store: " . $e->getMessage(), 'system', null, 'error');
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
            $where .= " AND (order_number LIKE :search OR full_name LIKE :search OR email LIKE :search OR phone LIKE :search OR shipping_city LIKE :search OR order_details LIKE :search)";
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
    recordUserLog('Test Connection', 'Orders API', "Tested Shopify API connection (orders) for {$activeStore} store — " . ($allSuccess ? 'check passed.' : 'check failed.'), 'system', null, $allSuccess ? 'success' : 'error');
}

// -----------------------------------------------------------------------------
// CSV EXPORT – Email Marketing (accepts_email_marketing = yes)
// Columns: Email, Order Number, Order Date, Delivery Date, Customer Name,
//          Item Description, Quantity of Item, Address, Fulfillment Status
// -----------------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'email') {
    $filename = $activeStore . '_orders_email_marketing_' . date('Y-m-d') . '.csv';
    recordUserLog('Export Orders CSV', 'Email Marketing Export', "Exported email-marketing orders CSV for {$activeStore} store (search: '" . ($search !== '' ? $search : '—') . "', status filter: '" . ($filterStatus !== '' ? $filterStatus : 'all') . "', date range: '{$exportDateDescription}').", 'system', null, 'success');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    fputcsv($output, [
        'EMAIL',
        'Order Number',
        'Order Date',
        'Delivery Date',
        'Customer Name',
        'Item Description',
        'Quantity of Item',
        'Address',
        'Fulfillment Status'
    ]);

    if ($db) {
        try {
            $where  = "store_key = :store AND accepts_email_marketing = 'yes'";
            $params = [':store' => $activeStore];

            if ($search !== '') {
                $where .= " AND (order_number LIKE :search OR full_name LIKE :search OR email LIKE :search OR phone LIKE :search OR shipping_city LIKE :search OR order_details LIKE :search)";
                $params[':search'] = "%{$search}%";
            }
            if ($filterStatus !== '') {
                $where .= " AND fulfillment_status = :status";
                $params[':status'] = $filterStatus;
            }
            if ($exportDateParams) {
                $where .= " AND created_at >= :created_from AND created_at <= :created_to";
                $params = array_merge($params, $exportDateParams);
            }

            $stmt = $db->prepare("SELECT * FROM shopify_orders WHERE {$where} ORDER BY created_at DESC");
            $stmt->execute($params);
            $allOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($allOrders as $order) {
                [$itemDescriptions, $itemQuantities] = getOrderItemExportFields(
                    $order['line_items'] ?? null,
                    $order['order_details'] ?? null
                );
                fputcsv($output, [
                    $order['email'] ?? '',
                    $order['order_number'] ?? '',
                    $order['created_at'] ?? '',
                    $order['delivery_date'] ?? '',
                    $order['full_name'] ?? '',
                    $itemDescriptions,
                    $itemQuantities,
                    $order['shipping_address'] ?? '',
                    $order['fulfillment_status'] ?? ''
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
// CSV EXPORT – SMS Marketing (accepts_sms_marketing = yes)
// Columns: Contact No, Order Number, Order Date, Delivery Date, Customer Name,
//          Item Description, Quantity of Item, Address, Fulfillment Status
// -----------------------------------------------------------------------------
if (isset($_GET['export']) && $_GET['export'] === 'sms') {
    $filename = $activeStore . '_orders_sms_marketing_' . date('Y-m-d') . '.csv';
    recordUserLog('Export Orders CSV', 'SMS Marketing Export', "Exported sms-marketing orders CSV for {$activeStore} store (search: '" . ($search !== '' ? $search : '—') . "', status filter: '" . ($filterStatus !== '' ? $filterStatus : 'all') . "', date range: '{$exportDateDescription}').", 'system', null, 'success');

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $output = fopen('php://output', 'w');

    fputcsv($output, [
        'CONTACT NO',
        'Order Number',
        'Order Date',
        'Delivery Date',
        'Customer Name',
        'Item Description',
        'Quantity of Item',
        'Address',
        'Fulfillment Status'
    ]);

    if ($db) {
        try {
            $where  = "store_key = :store AND accepts_sms_marketing = 'yes'";
            $params = [':store' => $activeStore];

            if ($search !== '') {
                $where .= " AND (order_number LIKE :search OR full_name LIKE :search OR email LIKE :search OR phone LIKE :search OR shipping_city LIKE :search OR order_details LIKE :search)";
                $params[':search'] = "%{$search}%";
            }
            if ($filterStatus !== '') {
                $where .= " AND fulfillment_status = :status";
                $params[':status'] = $filterStatus;
            }
            if ($exportDateParams) {
                $where .= " AND created_at >= :created_from AND created_at <= :created_to";
                $params = array_merge($params, $exportDateParams);
            }

            $stmt = $db->prepare("SELECT * FROM shopify_orders WHERE {$where} ORDER BY created_at DESC");
            $stmt->execute($params);
            $allOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($allOrders as $order) {
                [$itemDescriptions, $itemQuantities] = getOrderItemExportFields(
                    $order['line_items'] ?? null,
                    $order['order_details'] ?? null
                );
                fputcsv($output, [
                    $order['phone'] ?? '',
                    $order['order_number'] ?? '',
                    $order['created_at'] ?? '',
                    $order['delivery_date'] ?? '',
                    $order['full_name'] ?? '',
                    $itemDescriptions,
                    $itemQuantities,
                    $order['shipping_address'] ?? '',
                    $order['fulfillment_status'] ?? ''
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
            View and manage all orders with customer details, products bought, and fulfillment status.
          </p>
        </div>
        <div class="col-sm-4 text-right">
          <form method="post" class="d-inline-block mr-2">
            <input type="hidden" name="action" value="test_connection">
            <button type="submit" class="btn btn-info btn-sm shadow-sm font-weight-bold" style="background-color: #003399; border-color: #002266;">
              <i class="fas fa-plug mr-1"></i> Test Connection
            </button>
          </form>
          <form method="post" id="syncOrdersForm" class="d-inline-block mr-2">
            <input type="hidden" name="action" value="sync_orders">
            <button type="submit" id="syncOrdersBtn" class="btn btn-warning btn-sm shadow-sm font-weight-bold">
              <i class="fas fa-sync-alt mr-1"></i> Sync Orders Data
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- Main Content -->
  <section class="content">
    <div class="container-fluid">

      <div class="card shadow-sm border-0 mb-3" style="border-radius: 12px;">
        <div class="card-body d-flex flex-column flex-sm-row align-items-sm-center justify-content-between py-3">
          <div class="mb-3 mb-sm-0">
            <h2 class="h6 font-weight-bold text-dark mb-1">
              <i class="fas fa-file-download text-primary mr-2"></i>Download Customer Data
            </h2>
            <p class="small text-muted mb-0">Export customer email addresses or mobile numbers for a selected order date range.</p>
          </div>
          <button type="button" class="btn btn-primary btn-sm shadow-sm font-weight-bold px-3" data-toggle="modal" data-target="#customerDataDownloadModal" style="background-color: #003399; border-color: #002266;">
            <i class="fas fa-download mr-1"></i> Download Customer Data
          </button>
        </div>
      </div>

      <div id="syncProgress" class="card shadow-sm border-warning" style="display:none; border-radius: 12px;">
        <div class="card-body py-3">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <strong><i class="fas fa-sync-alt fa-spin mr-2 text-warning"></i>Syncing orders in batches...</strong>
            <span class="small text-muted" id="syncStatus"></span>
          </div>
          <div class="progress" style="height: 18px;">
            <div class="progress-bar progress-bar-striped progress-bar-animated bg-warning" style="width: 100%;"></div>
          </div>
          <div class="small text-muted mt-2">Batch <span id="syncBatch">0</span> &middot; <span id="syncCount">0</span> orders saved. Please keep this page open.</div>
        </div>
      </div>

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

      <?php
      // ---------- Analytics (selected date range for the active store) ----------
      $an = ['kpi' => [], 'fulfillment' => [], 'financial' => [], 'email' => [], 'sms' => [], 'daily' => [], 'cities' => [], 'products' => [], 'weekday' => []];
      try {
          $q = function (string $sql) use ($db, $analyticsParams) {
              $st = $db->prepare($sql);
              $st->execute($analyticsParams);
              return $st->fetchAll(PDO::FETCH_ASSOC);
          };
          $an['kpi'] = $q("SELECT COUNT(*) AS orders, COALESCE(SUM(total_price),0) AS revenue, COALESCE(AVG(total_price),0) AS aov,
                  COALESCE(MAX(total_price),0) AS max_order, COUNT(DISTINCT NULLIF(customer_id,0)) AS customers,
                  SUM(CASE WHEN financial_status='paid' THEN 1 ELSE 0 END) AS paid_orders
              FROM shopify_orders WHERE {$analyticsWhere}")[0] ?? [];
          $rep = $q("SELECT COUNT(*) AS c FROM (SELECT customer_id FROM shopify_orders WHERE {$analyticsWhere} AND customer_id > 0 GROUP BY customer_id HAVING COUNT(*) > 1) t");
          $an['kpi']['repeat'] = (int)($rep[0]['c'] ?? 0);
          $an['fulfillment'] = $q("SELECT COALESCE(NULLIF(fulfillment_status,''),'unfulfilled') AS label, COUNT(*) AS cnt FROM shopify_orders WHERE {$analyticsWhere} GROUP BY label ORDER BY cnt DESC");
          $an['financial']   = $q("SELECT COALESCE(NULLIF(financial_status,''),'unknown') AS label, COUNT(*) AS cnt FROM shopify_orders WHERE {$analyticsWhere} GROUP BY label ORDER BY cnt DESC");
          $an['email']       = $q("SELECT COALESCE(NULLIF(accepts_email_marketing,''),'unknown') AS label, COUNT(*) AS cnt FROM shopify_orders WHERE {$analyticsWhere} GROUP BY label");
          $an['sms']         = $q("SELECT COALESCE(NULLIF(accepts_sms_marketing,''),'unknown') AS label, COUNT(*) AS cnt FROM shopify_orders WHERE {$analyticsWhere} GROUP BY label");
          $an['daily']       = $q("SELECT DATE(created_at) AS label, COUNT(*) AS cnt, COALESCE(AVG(total_price),0) AS aov
              FROM shopify_orders WHERE {$analyticsWhere} GROUP BY DATE(created_at) ORDER BY label ASC");
          $an['cities']      = $q("SELECT shipping_city AS label, COUNT(*) AS cnt, COALESCE(SUM(total_price),0) AS revenue FROM shopify_orders
              WHERE {$analyticsWhere} AND shipping_city IS NOT NULL AND shipping_city <> '' GROUP BY shipping_city ORDER BY cnt DESC LIMIT 8");
          $an['weekday']     = $q("SELECT DAYOFWEEK(created_at) AS d, COUNT(*) AS cnt FROM shopify_orders WHERE {$analyticsWhere} GROUP BY d");

          $prodCounts = [];
          $st = $db->prepare("SELECT line_items FROM shopify_orders WHERE {$analyticsWhere} AND line_items IS NOT NULL");
          $st->execute($analyticsParams);
          while ($row = $st->fetch(PDO::FETCH_NUM)) {
              $items = json_decode((string)$row[0], true);
              if (!is_array($items)) continue;
              foreach ($items as $it) {
                  $n = trim((string)($it['name'] ?? $it['title'] ?? ''));
                  if ($n === '') continue;
                  $prodCounts[$n] = ($prodCounts[$n] ?? 0) + (int)($it['quantity'] ?? 1);
              }
          }
          arsort($prodCounts);
          $an['products'] = array_slice($prodCounts, 0, 8, true);
      } catch (Throwable $e) {
          error_log('Orders analytics error: ' . $e->getMessage());
      }
      $kOrders = (int)($an['kpi']['orders'] ?? 0);
      $kCust   = (int)($an['kpi']['customers'] ?? 0);
      $wdNames = [1 => 'Sun', 2 => 'Mon', 3 => 'Tue', 4 => 'Wed', 5 => 'Thu', 6 => 'Fri', 7 => 'Sat'];
      $wdData  = array_fill(1, 7, 0);
      foreach ($an['weekday'] as $w) { $wdData[(int)$w['d']] = (int)$w['cnt']; }
      $pairs = function (array $rows, string $k = 'cnt') {
          return ['labels' => array_map(fn($r) => ucfirst(str_replace('_', ' ', (string)$r['label'])), $rows), 'data' => array_map(fn($r) => (float)$r[$k], $rows)];
      };
      $chartData = [
          'fulfillment' => $pairs($an['fulfillment']),
          'financial'   => $pairs($an['financial']),
          'email'       => $pairs($an['email']),
          'sms'         => $pairs($an['sms']),
          'daily'       => ['labels' => array_column($an['daily'], 'label'), 'orders' => array_map('intval', array_column($an['daily'], 'cnt')), 'aov' => array_map('floatval', array_column($an['daily'], 'aov'))],
          'cities'      => $pairs($an['cities']),
          'products'    => ['labels' => array_keys($an['products']), 'data' => array_values($an['products'])],
          'weekday'     => ['labels' => array_values($wdNames), 'data' => array_values($wdData)],
      ];
      $kpiCards = [
          ['Total Revenue', '₱' . number_format((float)($an['kpi']['revenue'] ?? 0), 2), 'fa-peso-sign', 'success'],
          ['Avg. Order Value', '₱' . number_format((float)($an['kpi']['aov'] ?? 0), 2), 'fa-receipt', 'primary'],
          ['Largest Order', '₱' . number_format((float)($an['kpi']['max_order'] ?? 0), 2), 'fa-trophy', 'warning'],
          ['Unique Customers', number_format($kCust), 'fa-users', 'info'],
          ['Repeat Customers', number_format((int)($an['kpi']['repeat'] ?? 0)) . ' (' . ($kCust ? round(($an['kpi']['repeat'] ?? 0) / $kCust * 100, 1) : 0) . '%)', 'fa-redo', 'purple'],
          ['Paid Orders', number_format((int)($an['kpi']['paid_orders'] ?? 0)) . ' (' . ($kOrders ? round(($an['kpi']['paid_orders'] ?? 0) / $kOrders * 100, 1) : 0) . '%)', 'fa-check-circle', 'success'],
      ];
      ?>
      <!-- Analytics Summary -->
      <div class="card shadow-sm border-0 mb-3" style="border-radius: 12px;">
        <div class="card-header bg-white d-flex justify-content-between align-items-center" style="border-radius: 12px 12px 0 0;">
          <h3 class="card-title font-weight-bold m-0"><i class="fas fa-chart-pie mr-2 text-primary"></i>Orders Analytics</h3>
          <div class="d-flex align-items-center">
            <form method="get" action="orders.php" class="form-inline mr-2">
              <label for="analyticsFrom" class="sr-only">Analytics start date</label>
              <input type="date" name="analytics_from" id="analyticsFrom" class="form-control form-control-sm mr-1" value="<?php echo htmlspecialchars($analyticsFromInput); ?>" required>
              <label for="analyticsTo" class="sr-only">Analytics end date</label>
              <input type="date" name="analytics_to" id="analyticsTo" class="form-control form-control-sm mr-1" value="<?php echo htmlspecialchars($analyticsToInput); ?>" required>
              <button type="submit" class="btn btn-primary btn-sm" style="background-color: #003399; border-color: #002266;">Apply</button>
            </form>
            <button class="btn btn-sm btn-light" type="button" data-toggle="collapse" data-target="#ordersAnalytics" aria-label="Toggle analytics"><i class="fas fa-chevron-down"></i></button>
          </div>
        </div>
        <div id="ordersAnalytics" class="collapse show">
          <div class="card-body">
            <?php if ($analyticsError !== ''): ?>
              <div class="alert alert-danger mb-3" role="alert"><?php echo htmlspecialchars($analyticsError); ?></div>
            <?php endif; ?>
            <p class="small text-muted mb-3">
              Showing analytics for <strong><?php echo htmlspecialchars($analyticsFromInput); ?></strong> through <strong><?php echo htmlspecialchars($analyticsToInput); ?></strong>.
            </p>
            <div class="row">
              <?php foreach ($kpiCards as [$lbl, $val, $ico, $clr]): ?>
                <div class="col-lg-2 col-md-4 col-6 mb-3">
                  <div class="border rounded p-2 h-100">
                    <div class="text-muted text-uppercase font-weight-bold" style="font-size:11px;"><i class="fas <?php echo $ico; ?> mr-1 text-<?php echo $clr; ?>"></i><?php echo $lbl; ?></div>
                    <div class="font-weight-bold" style="font-size:18px;"><?php echo htmlspecialchars($val); ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
            <?php if ($kOrders === 0): ?>
              <p class="text-muted text-center m-0">No order data to analyze yet. Sync orders first.</p>
            <?php else: ?>
            <div class="row">
              <div class="col-lg-3 col-md-6 mb-3"><h6 class="text-center font-weight-bold">Fulfillment Status</h6><div style="height:230px"><canvas id="chFulfillment"></canvas></div></div>
              <div class="col-lg-3 col-md-6 mb-3"><h6 class="text-center font-weight-bold">Payment Status</h6><div style="height:230px"><canvas id="chFinancial"></canvas></div></div>
              <div class="col-lg-3 col-md-6 mb-3"><h6 class="text-center font-weight-bold">Email Marketing Consent</h6><div style="height:230px"><canvas id="chEmail"></canvas></div></div>
              <div class="col-lg-3 col-md-6 mb-3"><h6 class="text-center font-weight-bold">SMS Marketing Consent</h6><div style="height:230px"><canvas id="chSms"></canvas></div></div>
            </div>
            <div class="row">
              <div class="col-lg-6 mb-3"><h6 class="text-center font-weight-bold">Orders Over Time</h6><div style="height:280px"><canvas id="chOrdersOverTime"></canvas></div></div>
              <div class="col-lg-6 mb-3"><h6 class="text-center font-weight-bold">Average Order Value Over Time</h6><div style="height:280px"><canvas id="chAovOverTime"></canvas></div></div>
            </div>
            <div class="row">
              <div class="col-lg-4 mb-3"><h6 class="text-center font-weight-bold">Orders by Weekday</h6><div style="height:280px"><canvas id="chWeekday"></canvas></div></div>
              <div class="col-lg-6 mb-3"><h6 class="text-center font-weight-bold">Top Shipping Cities</h6><div style="height:280px"><canvas id="chCities"></canvas></div></div>
              <div class="col-lg-2 mb-3"><h6 class="text-center font-weight-bold">Top Products (units sold)</h6><div style="height:280px"><canvas id="chProducts"></canvas></div></div>
            </div>
            <?php endif; ?>
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
                  placeholder="Search by order #, name, email, phone, product..."
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
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Delivery Date</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Customer</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Email</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Phone</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Email Mkt</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">SMS Mkt</th>
                  <th style="padding: 12px; font-size: 12px; text-transform: uppercase; font-weight: 600;">Order Details</th>
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
                    <td colspan="14" class="text-center py-4 text-muted">
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
                      <td style="padding: 12px; font-size: 13px; white-space: nowrap;">
                        <?php
                        $deliveryDate = $order['delivery_date'] ?? '';
                        if (!empty($deliveryDate)) {
                            try {
                                echo (new DateTime($deliveryDate))->format('Y-m-d H:i:s');
                            } catch (Exception $e) {
                                echo htmlspecialchars($deliveryDate);
                            }
                        } else {
                            echo 'N/A';
                        }
                        ?>
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
                      <td style="padding: 12px; font-size: 13px; text-align: center;">
                        <?php
                        $emailMkt = strtolower($order['accepts_email_marketing'] ?? 'no');
                        $emailBadge = ($emailMkt === 'yes') ? 'bg-success' : 'bg-secondary';
                        ?>
                        <span class="badge <?php echo $emailBadge; ?> font-weight-bold" style="font-size: 11px; padding: 4px 8px;">
                          <?php echo htmlspecialchars($order['accepts_email_marketing'] ?? 'no'); ?>
                        </span>
                      </td>
                      <td style="padding: 12px; font-size: 13px; text-align: center;">
                        <?php
                        $smsMkt = strtolower($order['accepts_sms_marketing'] ?? 'no');
                        $smsBadge = ($smsMkt === 'yes') ? 'bg-success' : 'bg-secondary';
                        ?>
                        <span class="badge <?php echo $smsBadge; ?> font-weight-bold" style="font-size: 11px; padding: 4px 8px;">
                          <?php echo htmlspecialchars($order['accepts_sms_marketing'] ?? 'no'); ?>
                        </span>
                      </td>
                      <td style="padding: 12px; font-size: 13px; max-width: 220px;">
                        <?php
                        $details = $order['order_details'] ?? '';
                        if (!empty($details)) {
                            echo nl2br(htmlspecialchars($details));
                        } else {
                            echo '<span class="text-muted">N/A</span>';
                        }
                        ?>
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

<div class="modal fade" id="customerDataDownloadModal" tabindex="-1" role="dialog" aria-labelledby="customerDataDownloadModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <form method="get" action="orders.php" class="modal-content border-0 shadow-lg">
      <?php if ($search !== ''): ?>
        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
      <?php endif; ?>
      <?php if ($filterStatus !== ''): ?>
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($filterStatus); ?>">
      <?php endif; ?>
      <div class="modal-header text-white" style="background-color: #003399;">
        <h5 class="modal-title font-weight-bold" id="customerDataDownloadModalTitle">
          <i class="fas fa-file-download mr-2"></i>Download Customer Data
        </h5>
        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body p-4">
        <div class="form-group">
          <label for="exportDateRange" class="font-weight-bold">Order date range</label>
          <select name="date_range" id="exportDateRange" class="form-control" required>
            <?php foreach ($exportDateRanges as $rangeValue => $rangeLabel): ?>
              <option value="<?php echo htmlspecialchars($rangeValue); ?>" <?php echo $selectedExportDateRange === $rangeValue ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($rangeLabel); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row custom-export-date" style="display: none;">
          <div class="form-group col-sm-6">
            <label for="exportDateFrom" class="font-weight-bold">From</label>
            <input type="date" name="date_from" id="exportDateFrom" class="form-control" value="<?php echo htmlspecialchars($exportDateFromInput); ?>">
          </div>
          <div class="form-group col-sm-6">
            <label for="exportDateTo" class="font-weight-bold">To</label>
            <input type="date" name="date_to" id="exportDateTo" class="form-control" value="<?php echo htmlspecialchars($exportDateToInput); ?>">
          </div>
        </div>
        <fieldset class="mb-0">
          <legend class="col-form-label pt-0 font-weight-bold">Customer data to download</legend>
          <div class="custom-control custom-radio mb-2">
            <input type="radio" id="exportEmail" name="export" value="email" class="custom-control-input" checked>
            <label class="custom-control-label" for="exportEmail"><i class="fas fa-envelope text-success mr-1"></i>Email addresses</label>
          </div>
          <div class="custom-control custom-radio">
            <input type="radio" id="exportSms" name="export" value="sms" class="custom-control-input">
            <label class="custom-control-label" for="exportSms"><i class="fas fa-sms text-primary mr-1"></i>Mobile numbers for SMS</label>
          </div>
        </fieldset>
      </div>
      <div class="modal-footer bg-light">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-primary btn-sm font-weight-bold" style="background-color: #003399; border-color: #002266;">
          <i class="fas fa-download mr-1"></i> Download
        </button>
      </div>
    </form>
  </div>
</div>

<?php
?>
<?php if ($kOrders > 0): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
  if (typeof Chart === 'undefined') return;
  var D = <?php echo json_encode($chartData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
  var palette = ['#007bff', '#28a745', '#ffc107', '#dc3545', '#17a2b8', '#6f42c1', '#fd7e14', '#20c997', '#6c757d'];
  function pie(id, d) {
    new Chart(document.getElementById(id), {
      type: 'doughnut',
      data: { labels: d.labels, datasets: [{ data: d.data, backgroundColor: palette }] },
      options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } } }
    });
  }
  function bar(id, d, horizontal) {
    new Chart(document.getElementById(id), {
      type: 'bar',
      data: { labels: d.labels, datasets: [{ data: d.data, backgroundColor: palette[0] }] },
      options: { maintainAspectRatio: false, indexAxis: horizontal ? 'y' : 'x', plugins: { legend: { display: false } } }
    });
  }
  pie('chFulfillment', D.fulfillment);
  pie('chFinancial', D.financial);
  pie('chEmail', D.email);
  pie('chSms', D.sms);
  bar('chWeekday', D.weekday, false);
  bar('chCities', D.cities, true);
  bar('chProducts', D.products, true);
  new Chart(document.getElementById('chOrdersOverTime'), {
    type: 'line',
    data: {
      labels: D.daily.labels,
      datasets: [{ label: 'Orders', data: D.daily.orders, borderColor: palette[0], backgroundColor: 'rgba(0, 123, 255, 0.12)', fill: true, tension: 0.3 }]
    },
    options: { maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } }
  });
  new Chart(document.getElementById('chAovOverTime'), {
    type: 'line',
    data: {
      labels: D.daily.labels,
      datasets: [{ label: 'Average Order Value (₱)', data: D.daily.aov, borderColor: palette[1], backgroundColor: 'rgba(40, 167, 69, 0.12)', fill: true, tension: 0.3 }]
    },
    options: { maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { callback: function (value) { return '₱' + Number(value).toLocaleString(); } } } } }
  });
})();
</script>
<?php endif; ?>
<script>
document.getElementById('syncOrdersForm').addEventListener('submit', async function (ev) {
  ev.preventDefault();
  if (!confirm('This will sync orders after the latest order number saved in the database. If no orders are saved yet, it will sync all orders. Continue?')) return;
  var btn = document.getElementById('syncOrdersBtn'), label = btn.innerHTML;
  btn.disabled = true;
  var panel = document.getElementById('syncProgress'), status = document.getElementById('syncStatus');
  var batchEl = document.getElementById('syncBatch'), countEl = document.getElementById('syncCount');
  var warn = function (e) { e.preventDefault(); e.returnValue = ''; };
  window.addEventListener('beforeunload', warn);
  panel.style.display = '';
  panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
  var total = 0, next = '', failures = 0, batch = 0;
  try {
    do {
      var body = new URLSearchParams({ action: 'sync_orders', ajax: '1', page_info: next });
      var res, json;
      status.textContent = 'Fetching batch ' + (batch + 1) + '...';
      try {
        res = await fetch(window.location.pathname, { method: 'POST', body: body, credentials: 'same-origin' });
        json = await res.json();
      } catch (e) {
        if (++failures <= 3) { status.textContent = 'Retrying (' + failures + '/3)...'; await new Promise(function (r) { setTimeout(r, 2000); }); continue; }
        throw new Error('Server busy or unreachable. Synced ' + total + ' orders so far; run the sync again to continue.');
      }
      failures = 0;
      if (!json.ok) throw new Error(json.error || 'Sync failed');
      total += json.synced;
      batch++;
      batchEl.textContent = batch;
      countEl.textContent = total.toLocaleString();
      next = json.next || '';
      btn.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Synced ' + total.toLocaleString() + ' orders...';
    } while (next);
    window.removeEventListener('beforeunload', warn);
    status.textContent = 'Done';
    alert('Synced ' + total.toLocaleString() + ' orders.');
    window.location.reload();
  } catch (e) {
    window.removeEventListener('beforeunload', warn);
    panel.style.display = 'none';
    alert(e.message);
    btn.disabled = false;
    btn.innerHTML = label;
  }
});

var exportDateRange = document.getElementById('exportDateRange');
var customExportDateFields = document.querySelectorAll('.custom-export-date');
var exportDateFrom = document.getElementById('exportDateFrom');
var exportDateTo = document.getElementById('exportDateTo');
function updateCustomExportDateFields() {
  var isCustomRange = exportDateRange.value === 'custom';
  customExportDateFields.forEach(function (field) {
    field.style.display = isCustomRange ? '' : 'none';
  });
  exportDateFrom.required = isCustomRange;
  exportDateTo.required = isCustomRange;
}
exportDateRange.addEventListener('change', updateCustomExportDateFields);
updateCustomExportDateFields();
</script>
<?php
include __DIR__ . '/../includes/footer.php';
?>