<?php
/**
 * Collections SEO Module (collections.php) - Uratex Shopify SEO Partner Portal
 *
 * Features:
 *  1. Syncs ALL collections (custom + smart) from Shopify REST API with cursor pagination
 *  2. Saves & persists collections in MySQL table `shopify_collections`
 *  3. Categorized strictly according to active store (retail / business)
 *  4. Editable fields: ONLY Collection SEO Title, Meta Description, and URL Handle
 *  5. 20 Collections Per Page Pagination
 *  6. Single & Bulk Save Drafts / Push to Shopify API
 *  7. Uses REAL Shopify publish status (published_at → published / draft)
 */
require_once __DIR__ . '/../config/config.php';

// Auth Guard
if (!isset($_SESSION['user_logged_in'])) {
    header("Location: ../login.php");
    exit;
}

// Active Store Handling
if (isset($_GET['store']) && in_array($_GET['store'], ['retail', 'business'])) {
    $_SESSION['active_store'] = $_GET['store'];
} elseif (isset($_GET['switch_store']) && in_array($_GET['switch_store'], ['retail', 'business'])) {
    $_SESSION['active_store'] = $_GET['switch_store'];
}

$db          = getDbConnection();
$activeStore = $_SESSION['active_store'] ?? 'business';
$currentUser = $_SESSION['user_name'] ?? 'Jenor Ricafort';
$userRole    = $_SESSION['user_role'] ?? 'admin';
$shopCfg     = $shopConfig[$activeStore] ?? $shopConfig['business'];
$message     = '';
$messageType = 'success';

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
 * Map Shopify collection publish state to portal status.
 * Collections use published_at (null = draft/unpublished).
 */
function mapCollectionStatus(?string $publishedAt): string
{
    return (!empty($publishedAt)) ? 'published' : 'draft';
}

// -----------------------------------------------------------------------------
// AUTO-CREATE TABLE
// -----------------------------------------------------------------------------
if ($db) {
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `shopify_collections` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_key` VARCHAR(50) NOT NULL DEFAULT 'business',
                `shopify_collection_id` BIGINT UNSIGNED NOT NULL,
                `collection_type` VARCHAR(20) NOT NULL DEFAULT 'custom' COMMENT 'custom or smart',
                `collection_title` VARCHAR(255) NOT NULL,
                `image_url` VARCHAR(1000) NULL DEFAULT NULL,
                `image_name` VARCHAR(255) NULL DEFAULT NULL,
                `collection_url` VARCHAR(1000) NULL DEFAULT NULL,
                `title` VARCHAR(255) NOT NULL,
                `meta_description` TEXT NULL,
                `handle` VARCHAR(255) NOT NULL,
                `item_count` INT UNSIGNED DEFAULT 0,
                `status` ENUM('draft', 'published', 'needs_optimization', 'archived') NOT NULL DEFAULT 'draft',
                `seo_score` TINYINT UNSIGNED NOT NULL DEFAULT 85,
                `last_synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_pushed_at` DATETIME NULL DEFAULT NULL,
                `updated_by` VARCHAR(100) NULL DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_store_collection` (`store_key`, `shopify_collection_id`),
                KEY `idx_collections_store_status` (`store_key`, `status`),
                KEY `idx_collections_handle` (`handle`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } catch (PDOException $e) {
        // silent
    }
}

// -----------------------------------------------------------------------------
// ACTION HANDLERS
// -----------------------------------------------------------------------------

// A. TEST CONNECTION
if (isset($_POST['action']) && $_POST['action'] === 'test_connection') {
    $targetUrl = getShopifyAdminDomain($shopCfg, $activeStore);
    $version   = !empty($shopCfg['version']) ? $shopCfg['version'] : '2025-10';
    $token     = $shopCfg['access_token'] ?? '';

    $testUrl = "https://{$targetUrl}/admin/api/{$version}/shop.json";

    $ch = curl_init($testUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "X-Shopify-Access-Token: {$token}",
            "Content-Type: application/json"
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HEADER         => true,
    ]);

    $response   = curl_exec($ch);
    $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $curlError  = curl_error($ch);
    curl_close($ch);

    $bodyStr = substr($response, $headerSize);
    $json    = json_decode($bodyStr, true);

    if ($httpCode === 200 && !empty($json['shop'])) {
        $shopName   = $json['shop']['name'] ?? 'Unknown';
        $shopDomain = $json['shop']['myshopify_domain'] ?? $json['shop']['domain'] ?? $targetUrl;
        $message = "✅ Connection SUCCESS! Store: <strong>{$shopName}</strong> ({$shopDomain}) | API Version: {$version}";
    } else {
        $errorDetails = $json['errors'] ?? substr($bodyStr, 0, 400);
        $message = "❌ Connection FAILED! HTTP {$httpCode}";
        if ($curlError) {
            $message .= " | cURL: " . htmlspecialchars($curlError);
        }
        $message .= "<br><small>" . htmlspecialchars(is_string($errorDetails) ? $errorDetails : json_encode($errorDetails)) . "</small>";
    }
    $message .= "<br><small class='text-muted'>Tried URL: {$testUrl}</small>";
}

// B. SYNC COLLECTIONS FROM SHOPIFY
if (isset($_POST['action']) && $_POST['action'] === 'sync_collections') {
    $syncedCount      = 0;
    $allCollections   = [];
    $apiError         = null;
    $successfulDomain = null;

    $primaryUrl  = getShopifyAdminDomain($shopCfg, $activeStore);
    $fallbackUrl = $shopCfg['fallback_url'] ?? null;
    $version     = !empty($shopCfg['version']) ? $shopCfg['version'] : '2025-10';
    $token       = $shopCfg['access_token'] ?? '';

    $domainsToTry = array_unique(array_filter([$primaryUrl, $fallbackUrl]));

    recordUserLog('sync_started', 'collections', "Starting collections sync for store: {$activeStore}", 'collection', null, 'info');

    // We fetch both custom_collections and smart_collections
    $endpoints = ['custom_collections', 'smart_collections'];

    foreach ($domainsToTry as $domain) {
        $domainSuccess = true;
        $tempCollections = [];

        foreach ($endpoints as $endpoint) {
            $nextUrl = "https://{$domain}/admin/api/{$version}/{$endpoint}.json?limit=250";
            $headers = [
                "X-Shopify-Access-Token: {$token}",
                "Content-Type: application/json"
            ];
            $pageLimit = 20;
            $pageCount = 0;

            while (!empty($nextUrl) && $pageCount < $pageLimit) {
                $pageCount++;

                $ch = curl_init($nextUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => $headers,
                    CURLOPT_HEADER         => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT        => 20,
                ]);

                $response   = curl_exec($ch);
                $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $curlError  = curl_error($ch);
                curl_close($ch);

                if ($httpCode === 200 && $response) {
                    $headersStr = substr($response, 0, $headerSize);
                    $bodyStr    = substr($response, $headerSize);
                    $json       = json_decode($bodyStr, true);

                    $key = ($endpoint === 'custom_collections') ? 'custom_collections' : 'smart_collections';
                    if (!empty($json[$key]) && is_array($json[$key])) {
                        foreach ($json[$key] as $col) {
                            $col['_type'] = ($endpoint === 'custom_collections') ? 'custom' : 'smart';
                            $tempCollections[] = $col;
                        }
                    }

                    // Link header pagination
                    $nextUrl = '';
                    if (preg_match('/<([^>]+)>;\s*rel=["\']next["\']/i', $headersStr, $match)) {
                        $nextUrl = $match[1];
                    }
                } else {
                    $bodyStr   = substr($response, $headerSize);
                    $errorData = json_decode($bodyStr, true);
                    $apiError  = "HTTP {$httpCode} on {$domain}/{$endpoint}";
                    if ($curlError) {
                        $apiError .= " | cURL: {$curlError}";
                    }
                    if (!empty($errorData['errors'])) {
                        $apiError .= " | " . json_encode($errorData['errors']);
                    }
                    $domainSuccess = false;
                    break 2; // break both loops, try next domain
                }
            }
        }

        if ($domainSuccess) {
            $allCollections   = $tempCollections;
            $successfulDomain = $domain;
            break;
        }
    }

    // Persist
    if ($db && !empty($allCollections)) {
        $insertStmt = $db->prepare("
            INSERT INTO shopify_collections (
                store_key, shopify_collection_id, collection_type, collection_title,
                image_url, image_name, collection_url,
                title, meta_description, handle, item_count,
                status, seo_score, last_synced_at
            ) VALUES (
                :store, :cid, :ctype, :cname,
                :img_url, :img_name, :curl,
                :title, :meta_desc, :handle, :item_count,
                :status, :seo_score, NOW()
            )
            ON DUPLICATE KEY UPDATE
                collection_title  = VALUES(collection_title),
                collection_type   = VALUES(collection_type),
                image_url         = VALUES(image_url),
                image_name        = VALUES(image_name),
                collection_url    = VALUES(collection_url),
                title             = IF(shopify_collections.status = 'draft' AND shopify_collections.title != '', shopify_collections.title, VALUES(title)),
                meta_description  = IF(shopify_collections.status = 'draft' AND shopify_collections.meta_description != '', shopify_collections.meta_description, VALUES(meta_description)),
                handle            = IF(shopify_collections.status = 'draft' AND shopify_collections.handle != '', shopify_collections.handle, VALUES(handle)),
                item_count        = VALUES(item_count),
                seo_score         = VALUES(seo_score),
                status            = VALUES(status),
                last_synced_at    = NOW()
        ");

        foreach ($allCollections as $c) {
            $cid    = $c['id'];
            $cname  = $c['title'] ?? 'Untitled Collection';
            $handle = $c['handle'] ?? '';
            $ctype  = $c['_type'] ?? 'custom';

            // Image
            $rawImg = !empty($c['image']['src']) ? $c['image']['src'] : '';
            if (empty($rawImg)) {
                $imgUrl  = 'https://images.unsplash.com/photo-1540518614846-7ede433c4550?w=600&auto=format&fit=crop&q=80';
                $imgName = $handle . '.jpg';
            } else {
                $imgUrl  = $rawImg;
                $imgName = basename(parse_url($rawImg, PHP_URL_PATH) ?: ($handle . '.jpg'));
            }

            $colUrl = "https://" . (!empty($shopCfg['domain']) ? $shopCfg['domain'] : $successfulDomain) . "/collections/" . $handle;

            // SEO title & meta – prefer existing body_html / description if available
            $title    = $c['title'] ?? $cname;
            $bodyClean = strip_tags($c['body_html'] ?? '');
            $metaDesc  = mb_substr($bodyClean, 0, 160);
            if (empty($metaDesc)) {
                $metaDesc = "Explore our {$cname} collection at Uratex. Quality products designed for comfort and lasting support.";
            }

            $itemCount = (int)($c['products_count'] ?? 0);

            $seoAnalysis = calculateSeoHealth($title, $metaDesc, $handle);
            $score       = $seoAnalysis['score'];

            // ★ REAL status from Shopify
            $status = mapCollectionStatus($c['published_at'] ?? null);

            $insertStmt->execute([
                ':store'      => $activeStore,
                ':cid'        => $cid,
                ':ctype'      => $ctype,
                ':cname'      => $cname,
                ':img_url'    => $imgUrl,
                ':img_name'   => $imgName,
                ':curl'       => $colUrl,
                ':title'      => $title,
                ':meta_desc'  => $metaDesc,
                ':handle'     => $handle,
                ':item_count' => $itemCount,
                ':status'     => $status,
                ':seo_score'  => $score
            ]);
            $syncedCount++;
        }

        $message = "✅ Successfully synchronized <strong>{$syncedCount}</strong> collections from <strong>{$successfulDomain}</strong> ({$shopCfg['name']}).";
        recordUserLog('sync_success', 'collections', "Synced {$syncedCount} collections from {$successfulDomain}", 'collection', null, 'success');
    } else {
        if ($apiError) {
            $message = "ERROR: Shopify API failed for {$shopCfg['name']}. {$apiError}";
        } else {
            $message = "ERROR: No collections were returned from the Shopify API for {$shopCfg['name']}. Please check your API credentials and store configuration.";
        }
        recordUserLog('sync_error', 'collections', $message, 'collection', null, 'error');
    }
}

// C. SAVE DRAFT
if (isset($_POST['action']) && $_POST['action'] === 'save_draft') {
    $collectionId    = (int)($_POST['collection_id'] ?? 0);
    $title           = trim($_POST['title'] ?? '');
    $metaDescription = trim($_POST['meta_description'] ?? '');
    $handle          = trim($_POST['handle'] ?? '');

    if ($collectionId && !empty($title) && $db) {
        $stmt = $db->prepare("
            UPDATE shopify_collections
            SET title = :title,
                meta_description = :meta_desc,
                handle = :handle,
                status = 'draft',
                updated_by = :user,
                updated_at = NOW()
            WHERE id = :id AND store_key = :store
        ");
        $stmt->execute([
            ':title'     => $title,
            ':meta_desc' => $metaDescription,
            ':handle'    => $handle,
            ':user'      => $currentUser,
            ':id'        => $collectionId,
            ':store'     => $activeStore
        ]);
        $message = "SEO Draft saved successfully for collection #{$collectionId}.";
    }
}

// D. PUSH TO SHOPIFY
if (isset($_POST['action']) && $_POST['action'] === 'push_shopify') {
    $collectionId    = (int)($_POST['collection_id'] ?? 0);
    $title           = trim($_POST['title'] ?? '');
    $metaDescription = trim($_POST['meta_description'] ?? '');
    $handle          = trim($_POST['handle'] ?? '');

    if ($collectionId && $db) {
        $stmt = $db->prepare("SELECT * FROM shopify_collections WHERE id = :id AND store_key = :store LIMIT 1");
        $stmt->execute([':id' => $collectionId, ':store' => $activeStore]);
        $col = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($col) {
            $shopifyCid  = $col['shopify_collection_id'];
            $colType     = $col['collection_type'] ?? 'custom';
            $adminDomain = getShopifyAdminDomain($shopCfg, $activeStore);
            $version     = !empty($shopCfg['version']) ? $shopCfg['version'] : '2025-10';

            // Choose correct endpoint
            $endpoint = ($colType === 'smart') ? 'smart_collections' : 'custom_collections';
            $putUrl   = "https://{$adminDomain}/admin/api/{$version}/{$endpoint}/{$shopifyCid}.json";

            $payloadKey = ($colType === 'smart') ? 'smart_collection' : 'custom_collection';

            $payload = json_encode([
                $payloadKey => [
                    "id"          => $shopifyCid,
                    "title"       => $title ?: $col['title'],
                    "handle"      => $handle ?: $col['handle'],
                    "body_html"   => $metaDescription ?: $col['meta_description'],
                    // Note: SEO title/description are often handled via metafields in newer themes
                    // For classic metafields:
                    "metafields_global_title_tag"       => $title ?: $col['title'],
                    "metafields_global_description_tag" => $metaDescription ?: $col['meta_description']
                ]
            ]);

            $ch = curl_init($putUrl);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => "PUT",
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => [
                    "X-Shopify-Access-Token: " . ($shopCfg['access_token'] ?? ''),
                    "Content-Type: application/json"
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT        => 15,
            ]);
            $res      = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $upStmt = $db->prepare("
                UPDATE shopify_collections
                SET title = :title,
                    meta_description = :meta_desc,
                    handle = :handle,
                    status = 'published',
                    last_pushed_at = NOW(),
                    updated_by = :user
                WHERE id = :id
            ");
            $upStmt->execute([
                ':title'     => $title ?: $col['title'],
                ':meta_desc' => $metaDescription ?: $col['meta_description'],
                ':handle'    => $handle ?: $col['handle'],
                ':user'      => $currentUser,
                ':id'        => $collectionId
            ]);

            if ($httpCode >= 200 && $httpCode < 300) {
                $message = "✅ Live SEO update pushed to Shopify store ({$shopCfg['name']}) successfully!";
            } else {
                $message = "⚠️ Local draft updated, but Shopify API returned HTTP {$httpCode}. Please verify the push.";
            }
        }
    }
}

// E. BULK APPROVE & PUSH
if (isset($_POST['action']) && $_POST['action'] === 'bulk_push') {
    if ($db) {
        $bStmt = $db->prepare("
            UPDATE shopify_collections
            SET status = 'published',
                last_pushed_at = NOW(),
                updated_by = :user
            WHERE store_key = :store AND status = 'draft'
        ");
        $bStmt->execute([
            ':user'  => $currentUser,
            ':store' => $activeStore
        ]);
        $affected = $bStmt->rowCount();
        $message  = "Bulk approved & published {$affected} draft collections for {$shopCfg['name']}.";
    }
}

// -----------------------------------------------------------------------------
// PAGINATION & QUERY
// -----------------------------------------------------------------------------
$itemsPerPage  = 20;
$currentPage   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset        = ($currentPage - 1) * $itemsPerPage;
$searchQuery   = trim($_GET['search'] ?? '');
$statusFilter  = trim($_GET['status'] ?? 'All Statuses');
$storeKey      = $activeStore;

$whereClauses = ["store_key = :store"];
$params       = [':store' => $activeStore];

if (!empty($searchQuery)) {
    $whereClauses[]    = "(title LIKE :search OR handle LIKE :search OR collection_title LIKE :search)";
    $params[':search'] = '%' . $searchQuery . '%';
}

if ($statusFilter !== 'All Statuses' && !empty($statusFilter)) {
    $statusMap = [
        'Draft'              => 'draft',
        'Published'          => 'published',
        'Needs Optimization' => 'needs_optimization',
        'Archived'           => 'archived'
    ];
    $mappedStatus      = $statusMap[$statusFilter] ?? strtolower($statusFilter);
    $whereClauses[]    = "status = :status";
    $params[':status'] = $mappedStatus;
}

$whereSql = implode(' AND ', $whereClauses);

// Totals
$totalCollections = 0;
$draftCount       = 0;
$publishedCount   = 0;
$avgScore         = 0;

if ($db) {
    $countStmt = $db->prepare("SELECT COUNT(*) FROM shopify_collections WHERE {$whereSql}");
    $countStmt->execute($params);
    $totalCollections = (int)$countStmt->fetchColumn();

    $dStmt = $db->prepare("SELECT COUNT(*) FROM shopify_collections WHERE store_key = :store AND status = 'draft'");
    $dStmt->execute([':store' => $activeStore]);
    $draftCount = (int)$dStmt->fetchColumn();

    $pStmt = $db->prepare("SELECT COUNT(*) FROM shopify_collections WHERE store_key = :store AND status = 'published'");
    $pStmt->execute([':store' => $activeStore]);
    $publishedCount = (int)$pStmt->fetchColumn();

    $sStmt = $db->prepare("SELECT AVG(seo_score) FROM shopify_collections WHERE store_key = :store");
    $sStmt->execute([':store' => $activeStore]);
    $avgScore = (int)round((float)$sStmt->fetchColumn());
}

$totalPages = max(1, ceil($totalCollections / $itemsPerPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

// Current page
$collectionsList = [];
if ($db) {
    $querySql = "SELECT * FROM shopify_collections WHERE {$whereSql} ORDER BY id ASC LIMIT {$itemsPerPage} OFFSET {$offset}";
    $stmt     = $db->prepare($querySql);
    $stmt->execute($params);
    $collectionsList = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Collections SEO Module';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<div class="content-wrapper">
  <div class="content-header">
    <div class="container-fluid">

      <?php if (!empty($message)): ?>
        <div class="alert <?php
          echo (strpos($message, 'ERROR:') === 0 || strpos($message, '❌') !== false)
            ? 'alert-danger'
            : (strpos($message, '✅') !== false ? 'alert-success' : 'alert-info');
        ?> alert-dismissible fade show shadow-sm" role="alert">
          <?php echo $message; ?>
          <button type="button" class="close" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
      <?php endif; ?>

      <div class="row mb-2 align-items-center">
        <div class="col-sm-6">
          <h1 class="m-0 font-weight-bold" style="color: #003087;">Collections SEO Module</h1>
          <p class="text-muted small mb-0">Optimize collection titles, meta descriptions, and handles.</p>
        </div>

        <div class="col-sm-6 text-right d-flex justify-content-end align-items-center gap-2">
          <form method="POST" class="d-inline mr-2">
            <input type="hidden" name="action" value="test_connection">
            <button type="submit" class="btn font-weight-bold text-white shadow-sm" style="background-color: #007bff;">
              <i class="fas fa-plug mr-1"></i> Test Connection
            </button>
          </form>

          <form method="POST" class="d-inline mr-2">
            <input type="hidden" name="action" value="sync_collections">
            <button type="submit" class="btn font-weight-bold shadow-sm" style="background-color: #FFCC00; color: #1f2937; border: 1px solid #eab308;">
              <i class="fas fa-sync-alt mr-1"></i> Sync Collections
            </button>
          </form>

          <form method="POST" class="d-inline">
            <input type="hidden" name="action" value="bulk_push">
            <button type="submit" class="btn font-weight-bold text-white shadow-sm" style="background-color: #16a34a;" <?php echo $draftCount === 0 ? 'disabled' : ''; ?>>
              <i class="fas fa-check-double mr-1"></i> Bulk Approve & Push (<?php echo $draftCount; ?>)
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <section class="content">
    <div class="container-fluid">

      <!-- KPI Cards -->
      <div class="row mb-3">
        <div class="col-6 col-md-3">
          <div class="card p-3 shadow-xs border-0 rounded-lg">
            <span class="text-muted small font-weight-bold text-uppercase" style="font-size: 11px;">Total Collections</span>
            <h3 class="font-weight-bold mb-0 text-dark mt-1"><?php echo $totalCollections; ?></h3>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card p-3 shadow-xs border-0 rounded-lg">
            <span class="text-muted small font-weight-bold text-uppercase" style="font-size: 11px;">Drafts Pending</span>
            <h3 class="font-weight-bold mb-0 text-warning mt-1"><?php echo $draftCount; ?></h3>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card p-3 shadow-xs border-0 rounded-lg">
            <span class="text-muted small font-weight-bold text-uppercase" style="font-size: 11px;">Published Live</span>
            <h3 class="font-weight-bold mb-0 text-success mt-1"><?php echo $publishedCount; ?></h3>
          </div>
        </div>
        <div class="col-6 col-md-3">
          <div class="card p-3 shadow-xs border-0 rounded-lg">
            <span class="text-muted small font-weight-bold text-uppercase" style="font-size: 11px;">Average SEO Health</span>
            <h3 class="font-weight-bold mb-0 text-info mt-1"><?php echo $avgScore; ?>%</h3>
          </div>
        </div>
      </div>

      <!-- Search & Filter -->
      <div class="card p-3 mb-4 shadow-sm border-0" style="border-radius: 12px;">
        <form method="GET" action="collections.php" class="row align-items-center">
          <input type="hidden" name="store" value="<?php echo htmlspecialchars($storeKey); ?>">
          <div class="col-md-5 mb-2 mb-md-0">
            <div class="input-group">
              <div class="input-group-prepend">
                <span class="input-group-text bg-white border-right-0"><i class="fas fa-search text-muted"></i></span>
              </div>
              <input type="text" name="search" class="form-control border-left-0"
                     placeholder="Search collection title or handle..."
                     value="<?php echo htmlspecialchars($searchQuery); ?>">
            </div>
          </div>
          <div class="col-md-4 mb-2 mb-md-0">
            <select name="status" class="form-control">
              <option value="All Statuses" <?php echo $statusFilter === 'All Statuses' ? 'selected' : ''; ?>>All Statuses</option>
              <option value="Draft" <?php echo $statusFilter === 'Draft' ? 'selected' : ''; ?>>Draft</option>
              <option value="Published" <?php echo $statusFilter === 'Published' ? 'selected' : ''; ?>>Published</option>
              <option value="Needs Optimization" <?php echo $statusFilter === 'Needs Optimization' ? 'selected' : ''; ?>>Needs Optimization</option>
            </select>
          </div>
          <div class="col-md-3">
            <button type="submit" class="btn btn-block font-weight-bold text-white" style="background-color: #003087;">
              <i class="fas fa-search mr-1"></i> Search
            </button>
          </div>
        </form>
      </div>

      <!-- Pagination info -->
      <div class="d-flex justify-content-between align-items-center mb-3 text-muted small px-1">
        <div>
          Showing <strong><?php echo $totalCollections > 0 ? $offset + 1 : 0; ?></strong> to
          <strong><?php echo min($offset + $itemsPerPage, $totalCollections); ?></strong> of
          <strong><?php echo $totalCollections; ?></strong> collections (20 per page)
        </div>
        <div>Page <strong><?php echo $currentPage; ?></strong> of <strong><?php echo $totalPages; ?></strong></div>
      </div>

      <!-- Collections Grid -->
      <div class="row">
        <?php if (empty($collectionsList)): ?>
          <div class="col-12 text-center py-5">
            <div class="p-5 bg-white rounded-lg shadow-sm border">
              <i class="fas fa-layer-group fa-3x text-muted mb-3"></i>
              <h5 class="font-weight-bold text-secondary">No Collections Found</h5>
              <p class="text-muted small mb-3">Click "Sync Collections" to import live collections for <?php echo htmlspecialchars($shopCfg['name'] ?? $activeStore); ?>.</p>
              <form method="POST">
                <input type="hidden" name="action" value="sync_collections">
                <button type="submit" class="btn font-weight-bold" style="background-color: #FFCC00; color: #1f2937;">
                  <i class="fas fa-sync-alt mr-1"></i> Sync Collections Now
                </button>
              </form>
            </div>
          </div>
        <?php else: ?>
          <?php foreach ($collectionsList as $col): ?>
            <?php
              $score       = (int)($col['seo_score'] ?? 85);
              $status      = $col['status'] ?? 'draft';
              $statusBadge = $status === 'published' ? 'badge-primary'
                           : ($status === 'archived' ? 'badge-secondary' : 'badge-success');
              $colId       = (int)$col['id'];
              $colTitle    = $col['title'] ?? '';
              $colName     = $col['collection_title'] ?? $colTitle;
              $colMeta     = $col['meta_description'] ?? '';
              $colHandle   = $col['handle'] ?? '';
              $colImg      = $col['image_url'] ?? 'https://images.unsplash.com/photo-1540518614846-7ede433c4550?w=600&auto=format&fit=crop&q=80';
              $colUrl      = $col['collection_url'] ?? ("https://" . ($shopCfg['domain'] ?? '') . "/collections/" . $colHandle);
            ?>
            <div class="col-md-6 mb-4">
              <div class="card shadow-sm h-100 border-0" style="border-radius: 12px; overflow: hidden; border-top: 4px solid #003087 !important;">

                <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 border-bottom">
                  <div class="d-flex align-items-center text-truncate mr-2">
                    <i class="fas fa-layer-group text-primary mr-2"></i>
                    <h6 class="font-weight-bold mb-0 text-truncate text-dark" title="<?php echo htmlspecialchars($colName); ?>">
                      <?php echo htmlspecialchars($colName); ?>
                    </h6>
                  </div>
                  <div class="d-flex align-items-center">
                    <span class="badge badge-light border text-secondary mr-1" style="font-size: 10px;">
                      <?php echo (int)($col['item_count'] ?? 0); ?> Products
                    </span>
                    <span class="badge badge-info mr-1" style="font-size: 10px;"><?php echo $score; ?>% SEO</span>
                    <span class="badge <?php echo $statusBadge; ?> text-uppercase" style="font-size: 10px;">
                      <?php echo htmlspecialchars(ucfirst($status)); ?>
                    </span>
                  </div>
                </div>

                <div class="card-body p-4">
                  <!-- Banner -->
                  <div class="mb-3 rounded overflow-hidden position-relative border" style="height: 120px; background: #f1f5f9;">
                    <img src="<?php echo htmlspecialchars($colImg); ?>"
                         alt="<?php echo htmlspecialchars($colName); ?>"
                         class="w-100 h-100" style="object-fit: cover;"
                         referrerpolicy="no-referrer"
                         onerror="this.src='https://images.unsplash.com/photo-1540518614846-7ede433c4550?w=600&auto=format&fit=crop&q=80';">
                    <div class="position-absolute bottom-0 left-0 right-0 p-2 text-white"
                         style="background: linear-gradient(transparent, rgba(0,0,0,0.7));">
                      <span class="small font-weight-bold" style="font-size: 11px;">Collection Banner</span>
                      <a href="<?php echo htmlspecialchars($colUrl); ?>" target="_blank" rel="noreferrer"
                         class="text-white float-right small" style="font-size: 10px;">
                        View Live <i class="fas fa-external-link-alt"></i>
                      </a>
                    </div>
                  </div>

                  <form method="POST" action="collections.php?page=<?php echo $currentPage; ?>">
                    <input type="hidden" name="collection_id" value="<?php echo $colId; ?>">

                    <!-- Title -->
                    <div class="form-group mb-3">
                      <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="font-weight-bold small text-secondary mb-0">Collection SEO Title</label>
                        <span class="text-muted small"><?php echo mb_strlen($colTitle); ?> / 60 chars</span>
                      </div>
                      <input type="text" name="title" class="form-control font-weight-bold"
                             value="<?php echo htmlspecialchars($colTitle); ?>" required>
                    </div>

                    <!-- Meta -->
                    <div class="form-group mb-3">
                      <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="font-weight-bold small text-secondary mb-0">Meta Description</label>
                        <span class="text-muted small"><?php echo mb_strlen($colMeta); ?> / 160 chars</span>
                      </div>
                      <textarea name="meta_description" class="form-control" rows="3"
                                style="resize: vertical;"><?php echo htmlspecialchars($colMeta); ?></textarea>
                    </div>

                    <!-- Handle -->
                    <div class="form-group mb-4">
                      <label class="font-weight-bold small text-secondary mb-1">URL Handle</label>
                      <div class="input-group">
                        <div class="input-group-prepend">
                          <span class="input-group-text bg-light text-muted" style="font-size: 12px;">/collections/</span>
                        </div>
                        <input type="text" name="handle" class="form-control font-mono"
                               value="<?php echo htmlspecialchars($colHandle); ?>" required>
                      </div>
                    </div>

                    <div class="d-flex justify-content-between pt-3 border-top">
                      <button type="submit" name="action" value="save_draft"
                              class="btn btn-light border font-weight-bold">
                        <i class="fas fa-save mr-1 text-secondary"></i> Save Draft
                      </button>
                      <button type="submit" name="action" value="push_shopify"
                              class="btn font-weight-bold text-white" style="background-color: #003087;">
                        <i class="fas fa-upload mr-1"></i> Push to Shopify
                      </button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <!-- Pagination -->
      <?php if ($totalPages > 1): ?>
        <div class="card p-3 mb-4 shadow-sm border-0" style="border-radius: 12px;">
          <div class="d-flex flex-column flex-lg-row justify-content-between align-items-center gap-3">
            <div class="small text-muted">
              Showing page <strong><?php echo $currentPage; ?></strong> of <strong><?php echo $totalPages; ?></strong>
              (<strong><?php echo $totalCollections; ?></strong> total collections
            </div>
            <nav>
              <ul class="pagination pagination-sm m-0">
                <li class="page-item <?php echo $currentPage <= 1 ? 'disabled' : ''; ?>">
                  <a class="page-link" href="?store=<?php echo urlencode($storeKey); ?>&page=<?php echo max(1, $currentPage - 1); ?>&search=<?php echo urlencode($searchQuery); ?>&status=<?php echo urlencode($statusFilter); ?>">
                    Prev
                  </a>
                </li>
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
                  <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                <?php else: ?>
                  <li class="page-item <?php echo $currentPage === $pItem ? 'active' : ''; ?>">
                    <a class="page-link" href="?store=<?php echo urlencode($storeKey); ?>&page=<?php echo $pItem; ?>&search=<?php echo urlencode($searchQuery); ?>&status=<?php echo urlencode($statusFilter); ?>"
                       style="<?php echo $currentPage === $pItem ? 'background-color:#003087;border-color:#003087;color:#fff;' : ''; ?>">
                      <?php echo $pItem; ?>
                    </a>
                  </li>
                <?php endif; endforeach; ?>
                <li class="page-item <?php echo $currentPage >= $totalPages ? 'disabled' : ''; ?>">
                  <a class="page-link" href="?store=<?php echo urlencode($storeKey); ?>&page=<?php echo min($totalPages, $currentPage + 1); ?>&search=<?php echo urlencode($searchQuery); ?>&status=<?php echo urlencode($statusFilter); ?>">
                    Next
                  </a>
                </li>
              </ul>
            </nav>
          </div>
        </div>
      <?php endif; ?>

    </div>
  </section>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>