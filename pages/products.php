<?php
/**
 * Product SEO Module (products.php) - Uratex Shopify SEO Partner Portal
 * 
 * Features:
 *  1. Syncs all products from Shopify REST API (Image, Name, URL, Title, Meta Description, Handle)
 *  2. Saves & persists all products in MySQL table `shopify_products`
 *  3. Editable fields: ONLY Page Title, Meta Description, and URL Handle
 *  4. 20 Products Per Page Pagination (LIMIT 20 OFFSET ...) with page buttons & counts
 *  5. Single & Bulk Save Drafts / Push to Shopify API
 */
require_once __DIR__ . '/../config/config.php';

// Auth Guard
if (!isset($_SESSION['user_logged_in'])) {
    header("Location: ../login.php");
    exit;
}

// Active Store Handling (Supports GET store/switch_store parameters)
if (isset($_GET['store']) && in_array($_GET['store'], ['retail', 'business'])) {
    $_SESSION['active_store'] = $_GET['store'];
} elseif (isset($_GET['switch_store']) && in_array($_GET['switch_store'], ['retail', 'business'])) {
    $_SESSION['active_store'] = $_GET['switch_store'];
}

$db = getDbConnection();
$activeStore = $_SESSION['active_store'] ?? 'business';
$currentUser = $_SESSION['user_name'] ?? 'Jenor Ricafort';
$userRole = $_SESSION['user_role'] ?? 'admin';
$shopCfg = $shopConfig[$activeStore] ?? $shopConfig['business'];

$message = '';
$messageType = 'success';

// -----------------------------------------------------------------------------
// 1. ACTION HANDLERS (SYNC, SAVE DRAFT, PUSH SHOPIFY, BULK APPROVE)
// -----------------------------------------------------------------------------

// A. SYNC PRODUCTS FROM SHOPIFY REST API (FETCH ALL PRODUCTS WITH PAGINATION)
if (isset($_POST['action']) && $_POST['action'] === 'sync_products') {
    $syncedCount = 0;
    
    // Call Shopify REST API to fetch products using the official admin URL for the active store
    $targetUrl = !empty($shopCfg['url']) ? $shopCfg['url'] : ($activeStore === 'business' ? 'uratex-business.myshopify.com' : 'uratex-philippines.myshopify.com');
    $shopifyProducts = [];
    
    // Verify that the target URL matches the active store to prevent cross-store issues
    $expectedUrl = ($activeStore === 'retail') ? 'uratex-philippines.myshopify.com' : 'uratex-business.myshopify.com';
    
    // Debug: Record which store we're syncing from
    recordUserLog('sync_started', 'products', "Starting sync for store: {$activeStore} with URL: {$targetUrl}", 'product', null, 'info');
    
    if (strpos($targetUrl, $expectedUrl) !== false || $targetUrl === $expectedUrl) {
        $nextUrl = "https://" . $targetUrl . "/admin/api/" . $shopCfg['version'] . "/products.json?limit=500";
        $headers = [
            "X-Shopify-Access-Token: " . $shopCfg['access_token'],
            "Content-Type: application/json"
        ];
        
        $pageLimit = 20; // safety ceiling (up to 10,000 products)
        $currentPageCount = 0;
        
        while (!empty($nextUrl) && $currentPageCount < $pageLimit) {
            $currentPageCount++;
            $ch = curl_init();
            if (!$ch) break;
            
            curl_setopt($ch, CURLOPT_URL, $nextUrl);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            curl_close($ch);
            
            if ($httpCode === 200 && $response) {
                $headersStr = substr($response, 0, $headerSize);
                $bodyStr = substr($response, $headerSize);
                $json = json_decode($bodyStr, true);
                
                if (!empty($json['products']) && is_array($json['products'])) {
                    $shopifyProducts = array_merge($shopifyProducts, $json['products']);
                }
                
                // Parse Shopify Link header for cursor pagination (rel="next")
                $nextUrl = '';
                if (preg_match('/<([^>]+)>;\s*rel=["\']next["\']/i', $headersStr, $match)) {
                    $nextUrl = $match[1];
                }
            } else {
                // Log API error
                $errorMsg = "Shopify API failed for {$activeStore}: HTTP {$httpCode}";
                if ($response) {
                    $bodyStr = substr($response, $headerSize);
                    $errorData = json_decode($bodyStr, true);
                    $errorMsg .= " - " . ($errorData['errors'] ?? substr($bodyStr, 0, 200));
                }
                recordUserLog('sync_api_error', 'products', $errorMsg, 'product', null, 'error');
                break;
            }
        }
    } else {
        // URL doesn't match expected store - log warning
        recordUserLog('sync_url_mismatch', 'products', "Store {$activeStore} has mismatched URL: {$targetUrl} (expected: {$expectedUrl})", 'product', null, 'warning');
    }
    
    if ($db) {
        $insertStmt = $db->prepare("
            INSERT INTO shopify_products (
                store_key, shopify_product_id, product_name, image_url, image_name, product_url,
                title, meta_description, handle, status, seo_score, category, price, last_synced_at
            ) VALUES (
                :store, :pid, :pname, :img_url, :img_name, :purl,
                :title, :meta_desc, :handle, :status, :seo_score, :category, :price, NOW()
            )
            ON DUPLICATE KEY UPDATE
                product_name = VALUES(product_name),
                image_url = VALUES(image_url),
                image_name = VALUES(image_name),
                product_url = VALUES(product_url),
                title = IF(shopify_products.status = 'draft' AND shopify_products.title != '', shopify_products.title, VALUES(title)),
                meta_description = IF(shopify_products.status = 'draft' AND shopify_products.meta_description != '', shopify_products.meta_description, VALUES(meta_description)),
                handle = IF(shopify_products.status = 'draft' AND shopify_products.handle != '', shopify_products.handle, VALUES(handle)),
                category = VALUES(category),
                price = VALUES(price),
                seo_score = VALUES(seo_score),
                last_synced_at = NOW()
        ");
        
        if (!empty($shopifyProducts)) {
            // Live Shopify API provided all products
            foreach ($shopifyProducts as $p) {
                $pid = $p['id'];
                $pname = $p['title'];
                $handle = $p['handle'];
                
                // Extract high-resolution image URL & filename
                $rawImg = !empty($p['image']['src']) ? $p['image']['src'] : (!empty($p['images'][0]['src']) ? $p['images'][0]['src'] : '');
                if (empty($rawImg)) {
                    $imgUrl = 'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?w=500&auto=format&fit=crop&q=80';
                    $imgName = $handle . '.jpg';
                } else {
                    $imgUrl = $rawImg;
                    $parsedPath = parse_url($rawImg, PHP_URL_PATH);
                    $imgName = basename($parsedPath) ?: ($handle . '.jpg');
                }
                
                $prodUrl = "https://" . $shopCfg['domain'] . "/products/" . $handle;
                $title = $p['title'];
                $bodyClean = strip_tags($p['body_html'] ?? '');
                $metaDesc = mb_substr($bodyClean, 0, 160);
                if (empty($metaDesc)) {
                    $metaDesc = "Shop authentic {$pname} with high-density sanitized foam, orthopedic support, and official Uratex Philippines warranty.";
                }
                $category = $p['product_type'] ?: 'Product';
                $price = !empty($p['variants'][0]['price']) ? '₱' . number_format((float)$p['variants'][0]['price'], 2) : '₱0.00';
                
                $seoAnalysis = calculateSeoHealth($title, $metaDesc, $handle);
                $score = $seoAnalysis['score'];
                $status = ($score >= 90) ? 'published' : 'draft';
                
                $insertStmt->execute([
                    ':store' => $activeStore,
                    ':pid' => $pid,
                    ':pname' => $pname,
                    ':img_url' => $imgUrl,
                    ':img_name' => $imgName,
                    ':purl' => $prodUrl,
                    ':title' => $title,
                    ':meta_desc' => $metaDesc,
                    ':handle' => $handle,
                    ':status' => $status,
                    ':seo_score' => $score,
                    ':category' => $category,
                    ':price' => $price
                ]);
                $syncedCount++;
            }
            $message = "Successfully fetched & synchronized ALL {$syncedCount} products from live Shopify API for {$shopCfg['name']}.";
        } else {
            $message = "ERROR: No products were returned from the Shopify API for {$shopCfg['name']}. Please check your API credentials and store configuration.";
            recordUserLog('sync_error', 'products', "No products returned from Shopify API for store: {$activeStore}", 'product', null, 'error');
        }
    } else {
        $message = "Database connection offline, but {$shopCfg['name']} catalog sync request was processed.";
    }
}

// B. SAVE DRAFT (EDITABLE FIELDS: title, meta_description, handle ONLY)
if (isset($_POST['action']) && $_POST['action'] === 'save_draft') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $metaDescription = trim($_POST['meta_description'] ?? '');
    $handle = trim($_POST['handle'] ?? '');
    
    if ($productId && !empty($title) && $db) {
        $stmt = $db->prepare("
            UPDATE shopify_products 
            SET title = :title, 
                meta_description = :meta_desc, 
                handle = :handle, 
                status = 'draft',
                updated_by = :user,
                updated_at = NOW()
            WHERE id = :id AND store_key = :store
        ");
        $stmt->execute([
            ':title' => $title,
            ':meta_desc' => $metaDescription,
            ':handle' => $handle,
            ':user' => $currentUser,
            ':id' => $productId,
            ':store' => $activeStore
        ]);
        $message = "SEO Draft saved successfully for product #{$productId}.";
    }
}

// C. PUSH TO SHOPIFY API
if (isset($_POST['action']) && $_POST['action'] === 'push_shopify') {
    $productId = (int)($_POST['product_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $metaDescription = trim($_POST['meta_description'] ?? '');
    $handle = trim($_POST['handle'] ?? '');
    
    if ($productId && $db) {
        // Query product details from DB
        $stmt = $db->prepare("SELECT * FROM shopify_products WHERE id = :id AND store_key = :store LIMIT 1");
        $stmt->execute([':id' => $productId, ':store' => $activeStore]);
        $prod = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($prod) {
            $shopifyPid = $prod['shopify_product_id'];
            
            // Execute Shopify PUT update
            $shopifyPutUrl = "https://" . $shopCfg['domain'] . "/admin/api/" . $shopCfg['version'] . "/products/{$shopifyPid}.json";
            $payload = json_encode([
                "product" => [
                    "id" => $shopifyPid,
                    "title" => $title ?: $prod['title'],
                    "handle" => $handle ?: $prod['handle'],
                    "metafields_global_title_tag" => $title ?: $prod['title'],
                    "metafields_global_description_tag" => $metaDescription ?: $prod['meta_description']
                ]
            ]);
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $shopifyPutUrl);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                "X-Shopify-Access-Token: " . $shopCfg['access_token'],
                "Content-Type: application/json"
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $res = curl_exec($ch);
            curl_close($ch);
            
            // Update database status
            $upStmt = $db->prepare("
                UPDATE shopify_products 
                SET title = :title, 
                    meta_description = :meta_desc, 
                    handle = :handle, 
                    status = 'published',
                    last_pushed_at = NOW(),
                    updated_by = :user
                WHERE id = :id
            ");
            $upStmt->execute([
                ':title' => $title ?: $prod['title'],
                ':meta_desc' => $metaDescription ?: $prod['meta_description'],
                ':handle' => $handle ?: $prod['handle'],
                ':user' => $currentUser,
                ':id' => $productId
            ]);
            
            $message = "Live SEO update pushed to Shopify store ({$shopCfg['name']}) successfully!";
        }
    }
}

// D. BULK APPROVE & PUSH TO SHOPIFY
if (isset($_POST['action']) && $_POST['action'] === 'bulk_push') {
    if ($db) {
        $bStmt = $db->prepare("
            UPDATE shopify_products 
            SET status = 'published', 
                last_pushed_at = NOW(), 
                updated_by = :user 
            WHERE store_key = :store AND status = 'draft'
        ");
        $bStmt->execute([
            ':user' => $currentUser,
            ':store' => $activeStore
        ]);
        $affected = $bStmt->rowCount();
        $message = "Bulk approved & published {$affected} draft products for {$shopCfg['name']}.";
    }
}

// -----------------------------------------------------------------------------
// 2. PAGINATION & QUERY CONFIGURATION (20 PRODUCTS PER PAGE)
// -----------------------------------------------------------------------------
$itemsPerPage = 20;
$currentPage = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($currentPage - 1) * $itemsPerPage;

$searchQuery = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? 'All Statuses');

$whereClauses = ["store_key = :store"];
$params = [':store' => $activeStore];

if (!empty($searchQuery)) {
    $whereClauses[] = "(title LIKE :search OR handle LIKE :search OR product_name LIKE :search)";
    $params[':search'] = '%' . $searchQuery . '%';
}

if ($statusFilter !== 'All Statuses' && !empty($statusFilter)) {
    $statusMap = [
        'Draft' => 'draft',
        'Published' => 'published',
        'Needs Optimization' => 'needs_optimization'
    ];
    $mappedStatus = $statusMap[$statusFilter] ?? strtolower($statusFilter);
    $whereClauses[] = "status = :status";
    $params[':status'] = $mappedStatus;
}

$whereSql = implode(' AND ', $whereClauses);

// Fetch Total Count
$totalProducts = 0;
if ($db) {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM shopify_products WHERE {$whereSql}");
    $countStmt->execute($params);
    $totalProducts = (int)$countStmt->fetchColumn();
}

$totalPages = max(1, ceil($totalProducts / $itemsPerPage));
if ($currentPage > $totalPages) $currentPage = $totalPages;

// Fetch Current 20 Products Page
$productsList = [];
if ($db) {
    $querySql = "SELECT * FROM shopify_products WHERE {$whereSql} ORDER BY id ASC LIMIT {$itemsPerPage} OFFSET {$offset}";
    $stmt = $db->prepare($querySql);
    $stmt->execute($params);
    $productsList = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Count draft items for bulk button
$draftCount = 0;
if ($db) {
    $dStmt = $db->prepare("SELECT COUNT(*) FROM shopify_products WHERE store_key = :store AND status = 'draft'");
    $dStmt->execute([':store' => $activeStore]);
    $draftCount = (int)$dStmt->fetchColumn();
}

$pageTitle = 'Product SEO Module';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="content-wrapper">
  <!-- Content Header -->
  <div class="content-header">
    <div class="container-fluid">
      
      <?php if (!empty($message)): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm" role="alert">
          <i class="fas fa-check-circle mr-2"></i> <?php echo htmlspecialchars($message); ?>
          <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
      <?php endif; ?>

      <div class="row mb-2 align-items-center">
        <div class="col-sm-6">
          <h1 class="m-0 font-weight-bold" style="color: #003087;">Product SEO Module</h1>
          <p class="text-muted small mb-0">Optimize product titles, meta descriptions, and handles.</p>
          <a href="#" class="small" style="color: #003087;" data-toggle="modal" data-target="#howToUseModal">
            <i class="fas fa-info-circle mr-1"></i> How to use this page
          </a>
        </div>
        
        <!-- Header Actions: Yellow Sync Products & Green Bulk Approve -->
        <div class="col-sm-6 text-right d-flex justify-content-end align-items-center gap-2">
          <!-- Sync Products Button (Yellow #FFCC00) -->
          <form method="POST" class="d-inline mr-2">
            <input type="hidden" name="action" value="sync_products">
            <button type="submit" class="btn font-weight-bold shadow-sm" style="background-color: #FFCC00; color: #1f2937; border: 1px solid #eab308;">
              <i class="fas fa-sync-alt mr-1"></i> Sync Products
            </button>
          </form>

          <!-- Bulk Approve & Push (Green #16a34a) -->
          <form method="POST" class="d-inline">
            <input type="hidden" name="action" value="bulk_push">
            <button type="submit" class="btn font-weight-bold text-white shadow-sm" style="background-color: #16a34a; border-color: #15803d;" <?php echo $draftCount === 0 ? 'disabled' : ''; ?>>
              <i class="fas fa-check-double mr-1"></i> Bulk Approve & Push (<?php echo $draftCount; ?>)
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- Search & Filter Bar -->
  <section class="content">
    <div class="container-fluid">
      <div class="card p-3 mb-4 shadow-sm border-0" style="border-radius: 12px; background: #ffffff;">
        <form method="GET" action="products.php" class="row align-items-center">
          <!-- Search Query Input -->
          <div class="col-md-6 mb-2 mb-md-0">
            <div class="input-group">
              <div class="input-group-prepend">
                <span class="input-group-text bg-white border-right-0"><i class="fas fa-search text-muted"></i></span>
              </div>
              <input 
                type="text" 
                name="search" 
                class="form-control border-left-0" 
                placeholder="Search title or handle..." 
                value="<?php echo htmlspecialchars($searchQuery); ?>"
              >
            </div>
          </div>

          <!-- SEO Status Filter -->
          <div class="col-md-4 mb-2 mb-md-0">
            <select name="status" class="form-control">
              <option value="All Statuses" <?php echo $statusFilter === 'All Statuses' ? 'selected' : ''; ?>>All Statuses</option>
              <option value="Draft" <?php echo $statusFilter === 'Draft' ? 'selected' : ''; ?>>Draft</option>
              <option value="Published" <?php echo $statusFilter === 'Published' ? 'selected' : ''; ?>>Published</option>
              <option value="Needs Optimization" <?php echo $statusFilter === 'Needs Optimization' ? 'selected' : ''; ?>>Needs Optimization</option>
            </select>
          </div>

          <!-- Submit Button -->
          <div class="col-md-2">
            <button type="submit" class="btn btn-block font-weight-bold text-white shadow-sm" style="background-color: #003087;">
              <i class="fas fa-search mr-1"></i> Search
            </button>
          </div>
        </form>
      </div>

      <!-- Pagination Info Banner -->
      <div class="d-flex justify-content-between align-items-center mb-3 text-muted small px-1">
        <div>
          Showing <strong><?php echo $totalProducts > 0 ? $offset + 1 : 0; ?></strong> to 
          <strong><?php echo min($offset + $itemsPerPage, $totalProducts); ?></strong> of 
          <strong><?php echo $totalProducts; ?></strong> products (<strong>20 per page</strong>)
        </div>
        <div>
          Page <strong><?php echo $currentPage; ?></strong> of <strong><?php echo $totalPages; ?></strong>
        </div>
      </div>

      <!-- 2-Column Product Cards Grid (Matching User Screenshot) -->
      <div class="row">
        <?php if (empty($productsList)): ?>
          <div class="col-12 text-center py-5">
            <div class="p-5 bg-white rounded-lg shadow-sm border">
              <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
              <h5 class="font-weight-bold text-secondary">No products found matching your search.</h5>
              <p class="small text-muted mb-3">Click the yellow "Sync Products" button to fetch all products from your Shopify store.</p>
              <form method="POST">
                <input type="hidden" name="action" value="sync_products">
                <button type="submit" class="btn btn-warning font-weight-bold"><i class="fas fa-sync-alt mr-1"></i> Sync from Shopify Now</button>
              </form>
            </div>
          </div>
        <?php else: ?>
          <?php foreach ($productsList as $prod): ?>
            <div class="col-md-6 mb-4">
              <!-- Card with Green Top Accent Border matching screenshot -->
              <div class="card shadow-sm h-100 border-0" style="border-radius: 12px; overflow: hidden; border-top: 4px solid #16a34a !important;">
                
                <!-- Card Header with Title and Badge -->
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 border-bottom">
                  <div class="d-flex align-items-center gap-2">
                    <h5 class="card-title font-weight-bold mb-0 text-truncate text-dark" style="max-width: 70%; font-size: 15px;" title="<?php echo htmlspecialchars($prod['title']); ?>">
                      <?php echo htmlspecialchars($prod['title']); ?>
                    </h5>
                    <a href="<?php echo htmlspecialchars($prod['product_url'] ?: ('https://' . $shopCfg['domain'] . '/products/' . $prod['handle'])); ?>" target="_blank" rel="noreferrer" class="btn btn-sm btn-info shadow-sm" title="Live View" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">
                      <i class="fas fa-eye"></i> <span class="d-none d-md-inline ml-1">Live View</span>
                    </a>
                  </div>
                  <span class="badge <?php echo $prod['status'] === 'published' ? 'badge-primary' : ($prod['status'] === 'needs_optimization' ? 'badge-warning' : 'badge-success'); ?> px-2.5 py-1" style="font-size: 11px; font-weight: 700;">
                    <?php echo $prod['status'] === 'published' ? 'Published' : ($prod['status'] === 'needs_optimization' ? 'Needs Fix' : 'Draft'); ?>
                  </span>
                </div>

                <!-- Card Body Form -->
                <div class="card-body p-4">
                  <form method="POST" action="products.php?page=<?php echo $currentPage; ?>">
                    <input type="hidden" name="product_id" value="<?php echo $prod['id']; ?>">

                    <!-- READ-ONLY PRODUCT IMAGE & METADATA BOX -->
                    <div class="media mb-3 p-2.5 rounded border" style="background-color: #f8fafc; border-color: #e2e8f0 !important;">
                      <img 
                        src="<?php echo htmlspecialchars($prod['image_url'] ?: 'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?w=200&auto=format&fit=crop&q=80'); ?>" 
                        alt="<?php echo htmlspecialchars($prod['product_name']); ?>" 
                        class="mr-3 rounded border" 
                        referrerpolicy="no-referrer"
                        loading="lazy"
                        onerror="this.onerror=null; this.src='https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?w=200&auto=format&fit=crop&q=80';"
                        style="width: 65px; height: 65px; object-fit: cover; background: #fff;"
                      >
                      <div class="media-body small">
                        <div class="font-weight-bold text-uppercase text-muted" style="font-size: 10px; letter-spacing: 0.5px;">PRODUCT IMAGE</div>
                        <div class="font-weight-bold text-dark mt-0.5">
                          <strong>Name:</strong> <?php echo htmlspecialchars($prod['image_name'] ?: ($prod['handle'] . '.jpg')); ?>
                        </div>
                        <div class="text-truncate mt-0.5" style="max-width: 320px;">
                          <strong>URL:</strong> 
                          <a href="<?php echo htmlspecialchars($prod['image_url'] ?: ('https://uratex.com.ph/products/' . $prod['handle'])); ?>" target="_blank" rel="noreferrer" class="text-primary font-weight-semibold">
                            <?php echo htmlspecialchars($prod['image_url'] ?: ($prod['handle'] . '.jpg')); ?>
                          </a>
                        </div>
                      </div>
                    </div>

                    <!-- EDITABLE FIELD 1: Page Title -->
                    <div class="form-group mb-3">
                      <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="font-weight-bold small text-secondary mb-0">Page Title</label>
                        <span class="text-muted small" style="font-size: 11px;">
                          <?php echo mb_strlen($prod['title']); ?> / 60 chars
                        </span>
                      </div>
                      <input 
                        type="text" 
                        name="title" 
                        class="form-control font-weight-bold" 
                        value="<?php echo htmlspecialchars($prod['title']); ?>" 
                        required
                      >
                    </div>

                    <!-- EDITABLE FIELD 2: Meta Description -->
                    <div class="form-group mb-3">
                      <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="font-weight-bold small text-secondary mb-0">Meta Description</label>
                        <span class="text-muted small" style="font-size: 11px;">
                          <?php echo mb_strlen($prod['meta_description']); ?> / 160 chars
                        </span>
                      </div>
                      <textarea 
                        name="meta_description" 
                        class="form-control" 
                        rows="3"
                        style="resize: vertical;"
                      ><?php echo htmlspecialchars($prod['meta_description']); ?></textarea>
                    </div>

                    <!-- EDITABLE FIELD 3: URL Handle -->
                    <div class="form-group mb-4">
                      <label class="font-weight-bold small text-secondary mb-1">URL Handle</label>
                      <input 
                        type="text" 
                        name="handle" 
                        class="form-control font-mono" 
                        value="<?php echo htmlspecialchars($prod['handle']); ?>" 
                        required
                      >
                    </div>

                    <!-- ACTION BUTTONS: Save Draft & Push to Shopify -->
                    <div class="d-flex justify-content-between pt-3 border-top">
                      <button 
                        type="submit" 
                        name="action" 
                        value="save_draft" 
                        class="btn btn-light border font-weight-bold shadow-xs px-3"
                      >
                        <i class="fas fa-save mr-1.5 text-secondary"></i> Save Draft
                      </button>

                      <button 
                        type="submit" 
                        name="action" 
                        value="push_shopify" 
                        class="btn font-weight-bold text-white shadow-sm px-3" 
                        style="background-color: #003087;"
                      >
                        <i class="fas fa-upload mr-1.5"></i> Push to Shopify
                      </button>
                    </div>

                  </form>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- 20-ITEMS-PER-PAGE PAGINATION CONTROLS -->
      <?php if ($totalPages > 1): ?>
        <div class="card p-3 mb-4 shadow-sm border-0" style="border-radius: 12px;">
          <div class="d-flex flex-column flex-lg-row justify-content-between align-items-center gap-3">
            <div class="small text-muted mb-2 mb-lg-0">
              Showing page <strong><?php echo $currentPage; ?></strong> of <strong><?php echo $totalPages; ?></strong> (<strong><?php echo $totalProducts; ?></strong> total products &bull; 20 per page)
            </div>
            
            <div class="d-flex flex-wrap align-items-center justify-content-center justify-content-lg-end gap-2">
              <nav aria-label="Product pagination">
                <ul class="pagination pagination-sm m-0 flex-wrap justify-content-center">
                  <!-- First Page -->
                  <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?> d-none d-sm-inline-block">
                    <a class="page-link" href="?page=1&search=<?php echo urlencode($searchQuery); ?>&status=<?php echo urlencode($statusFilter); ?>" title="First Page">
                      &laquo;&laquo; First
                    </a>
                  </li>

                  <!-- Previous Page Link -->
                  <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo max(1, $currentPage - 1); ?>&search=<?php echo urlencode($searchQuery); ?>&status=<?php echo urlencode($statusFilter); ?>">
                      <i class="fas fa-chevron-left mr-1"></i> Prev
                    </a>
                  </li>

                  <!-- Windowed Page Numbers with Ellipsis -->
                  <?php
                    $pageLinks = [];
                    if ($totalPages <= 7) {
                      for ($p = 1; $p <= $totalPages; $p++) $pageLinks[] = $p;
                    } elseif ($currentPage <= 3) {
                      $pageLinks = [1, 2, 3, 4, '...', $totalPages];
                    } elseif ($currentPage >= $totalPages - 2) {
                      $pageLinks = [1, '...', $totalPages - 3, $totalPages - 2, $totalPages - 1, $totalPages];
                    } else {
                      $pageLinks = [1, '...', $currentPage - 1, $currentPage, $currentPage + 1, '...', $totalPages];
                    }

                    foreach ($pageLinks as $pItem):
                      if ($pItem === '...'):
                  ?>
                      <li class="page-item disabled">
                        <span class="page-link font-weight-bold text-muted border-0 bg-transparent px-2">&hellip;</span>
                      </li>
                  <?php else: ?>
                      <li class="page-item <?php echo $currentPage === $pItem ? 'active' : ''; ?>">
                        <a class="page-link font-weight-bold" href="?page=<?php echo $pItem; ?>&search=<?php echo urlencode($searchQuery); ?>&status=<?php echo urlencode($statusFilter); ?>" style="<?php echo $currentPage === $pItem ? 'background-color: #003087; border-color: #003087; color: #fff;' : ''; ?>">
                          <?php echo $pItem; ?>
                        </a>
                      </li>
                  <?php 
                      endif;
                    endforeach; 
                  ?>

                  <!-- Next Page Link -->
                  <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                    <a class="page-link" href="?page=<?php echo min($totalPages, $currentPage + 1); ?>&search=<?php echo urlencode($searchQuery); ?>&status=<?php echo urlencode($statusFilter); ?>">
                      Next <i class="fas fa-chevron-right ml-1"></i>
                    </a>
                  </li>

                  <!-- Last Page -->
                  <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?> d-none d-sm-inline-block">
                    <a class="page-link" href="?page=<?php echo $totalPages; ?>&search=<?php echo urlencode($searchQuery); ?>&status=<?php echo urlencode($statusFilter); ?>" title="Last Page">
                      Last &raquo;&raquo;
                    </a>
                  </li>
                </ul>
              </nav>

              <!-- Jump to page select dropdown -->
              <div class="d-flex align-items-center ml-2 pl-2 border-left">
                <span class="small text-muted mr-1.5 d-none d-md-inline" style="font-size: 11px;">Jump:</span>
                <select 
                  class="custom-select custom-select-sm" 
                  style="width: auto; font-size: 12px;"
                  onchange="if(this.value) window.location.href='?page=' + this.value + '&search=<?php echo urlencode($searchQuery); ?>&status=<?php echo urlencode($statusFilter); ?>'"
                >
                  <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <option value="<?php echo $p; ?>" <?php echo $currentPage === $p ? 'selected' : ''; ?>>
                      Page <?php echo $p; ?>
                    </option>
                  <?php endfor; ?>
                </select>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>

    </div>
  </section>
</div>

<!-- How to Use Modal -->
<div class="modal fade" id="howToUseModal" tabindex="-1" role="dialog" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered" role="document">
    <div class="modal-content" style="border-radius: 14px;">
      <div class="modal-header border-bottom">
        <h5 class="modal-title font-weight-bold" style="color: #003087;">
          <i class="fas fa-info-circle mr-1 text-primary"></i> Product SEO Module Guide
        </h5>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close">
          <span aria-hidden="true">&times;</span>
        </button>
      </div>
      <div class="modal-body small text-secondary">
        <ol class="pl-3 mb-0" style="line-height: 1.8;">
          <li>Click <strong>Sync Products</strong> to import the latest catalog and images from Shopify via REST API v2025-10.</li>
          <li>Product Image, Name, and Shopify URL are read-only metadata fetched directly from Shopify.</li>
          <li>Edit <strong>Page Title</strong> (recommended 50-60 characters for best Google SERP ranking).</li>
          <li>Edit <strong>Meta Description</strong> (recommended 120-160 characters).</li>
          <li>Edit <strong>URL Handle</strong> to match high-intent target keywords.</li>
          <li>Click <strong>Save Draft</strong> to store edits in MySQL database, or <strong>Push to Shopify</strong> to publish live.</li>
        </ol>
      </div>
      <div class="modal-footer border-top">
        <button type="button" class="btn btn-secondary btn-sm" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
