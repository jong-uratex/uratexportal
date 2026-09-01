<?php
/**
 * Pages SEO Module (pages.php) - Uratex Shopify SEO Partner Portal
 *
 * Features:
 *  1. Syncs ALL pages from Shopify REST API with cursor pagination
 *  2. Saves & persists pages in MySQL table `shopify_pages`
 *  3. Categorized strictly according to active store (retail / business)
 *  4. Editable fields: ONLY Page SEO Title, Meta Description, and URL Handle
 *  5. 20 Pages Per Page Pagination
 *  6. Single & Bulk Save Drafts / Push to Shopify API
 *  7. Uses REAL Shopify publish status (published_at → published / draft)
 *  8. CSV / JSON export of all stored pages
 *  9. Defensive error handling – never dies with HTTP 500
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
 * Map Shopify page publish state to portal status.
 */
function mapPageStatus(?string $publishedAt): string
{
    return (!empty($publishedAt)) ? 'published' : 'draft';
}

// -----------------------------------------------------------------------------
// EXPORT HANDLER
// -----------------------------------------------------------------------------
if (isset($_GET['export']) && in_array($_GET['export'], ['csv', 'json'])) {
    $expFormat = $_GET['export'];
    $expStore  = $_GET['store'] ?? $activeStore;
    $allDbPages = [];

    if ($db) {
        try {
            $stmt = $db->prepare("SELECT * FROM shopify_pages WHERE store_key = :store ORDER BY id ASC");
            $stmt->execute([':store' => $expStore]);
            $allDbPages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $allDbPages = [];
        }
    }

    if ($expFormat === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=uratex_shopify_pages_' . $expStore . '_' . date('Ymd_His') . '.csv');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));
        fputcsv($out, [
            'ID', 'Store Key', 'Shopify Page ID', 'Page Name', 'Page Type', 'Live URL',
            'SEO Title', 'Meta Description', 'URL Handle', 'Author', 'Status', 'SEO Score',
            'Created At', 'Last Synced At'
        ]);
        foreach ($allDbPages as $row) {
            fputcsv($out, [
                $row['id'] ?? '',
                $row['store_key'] ?? $expStore,
                $row['shopify_page_id'] ?? '',
                $row['page_title'] ?? '',
                $row['page_type'] ?? '',
                $row['page_url'] ?? '',
                $row['title'] ?? '',
                $row['meta_description'] ?? '',
                $row['handle'] ?? '',
                $row['author'] ?? '',
                $row['status'] ?? '',
                $row['seo_score'] ?? '',
                $row['created_at'] ?? '',
                $row['last_synced_at'] ?? ''
            ]);
        }
        fclose($out);
        exit;
    }

    if ($expFormat === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename=uratex_shopify_pages_' . $expStore . '_' . date('Ymd_His') . '.json');
        echo json_encode([
            'store'       => $expStore,
            'exported_at' => date('Y-m-d H:i:s'),
            'total_pages' => count($allDbPages),
            'pages'       => $allDbPages
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

// -----------------------------------------------------------------------------
// AUTO-CREATE / MIGRATE TABLE
// -----------------------------------------------------------------------------
if ($db) {
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `shopify_pages` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_key` VARCHAR(50) NOT NULL DEFAULT 'business',
                `shopify_page_id` BIGINT UNSIGNED NOT NULL,
                `page_title` VARCHAR(255) NOT NULL,
                `page_type` VARCHAR(100) NULL DEFAULT 'General Page',
                `page_url` VARCHAR(1000) NULL DEFAULT NULL,
                `title` VARCHAR(255) NOT NULL,
                `meta_description` TEXT NULL,
                `handle` VARCHAR(255) NOT NULL,
                `author` VARCHAR(100) NULL DEFAULT 'Uratex Team',
                `status` ENUM('draft', 'published', 'needs_optimization', 'archived') NOT NULL DEFAULT 'draft',
                `seo_score` TINYINT UNSIGNED NOT NULL DEFAULT 85,
                `last_synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_pushed_at` DATETIME NULL DEFAULT NULL,
                `updated_by` VARCHAR(100) NULL DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_store_page` (`store_key`, `shopify_page_id`),
                KEY `idx_pages_store_status` (`store_key`, `status`),
                KEY `idx_pages_handle` (`handle`)
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
    try {
        $targetUrl = getShopifyAdminDomain($shopCfg, $activeStore);
        $version   = !empty($shopCfg['version']) ? $shopCfg['version'] : '2025-10';
        $token     = $shopCfg['access_token'] ?? '';

        if (empty($token)) {
            throw new Exception('Access token is empty for the active store.');
        }

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
    } catch (Throwable $e) {
        $message = "ERROR: Test connection failed – " . htmlspecialchars($e->getMessage());
    }
}

// B. SYNC PAGES FROM SHOPIFY
if (isset($_POST['action']) && $_POST['action'] === 'sync_pages') {
    try {
        $syncedCount      = 0;
        $shopifyPages     = [];
        $apiError         = null;
        $successfulDomain = null;

        $primaryUrl  = getShopifyAdminDomain($shopCfg, $activeStore);
        $fallbackUrl = $shopCfg['fallback_url'] ?? null;
        $version     = !empty($shopCfg['version']) ? $shopCfg['version'] : '2025-10';
        $token       = $shopCfg['access_token'] ?? '';

        if (empty($token)) {
            throw new Exception('Access token is empty for the active store. Check your settings table.');
        }

        $domainsToTry = array_unique(array_filter([$primaryUrl, $fallbackUrl]));

        recordUserLog('sync_started', 'pages', "Starting pages sync for store: {$activeStore}", 'page', null, 'info');

        foreach ($domainsToTry as $domain) {
            $nextUrl          = "https://{$domain}/admin/api/{$version}/pages.json?limit=250";
            $headers          = [
                "X-Shopify-Access-Token: {$token}",
                "Content-Type: application/json"
            ];
            $pageLimit        = 40;
            $currentPageCount = 0;
            $tempPages        = [];
            $gotValidResponse = false;

            while (!empty($nextUrl) && $currentPageCount < $pageLimit) {
                $currentPageCount++;

                $ch = curl_init($nextUrl);
                if ($ch === false) {
                    throw new Exception('Failed to initialize cURL');
                }

                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => $headers,
                    CURLOPT_HEADER         => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT        => 25,
                ]);

                $response   = curl_exec($ch);
                $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                $curlError  = curl_error($ch);
                curl_close($ch);

                if ($httpCode === 200 && $response !== false) {
                    $gotValidResponse = true;
                    $headersStr = substr($response, 0, $headerSize);
                    $bodyStr    = substr($response, $headerSize);
                    $json       = json_decode($bodyStr, true);

                    if (!empty($json['pages']) && is_array($json['pages'])) {
                        $tempPages = array_merge($tempPages, $json['pages']);
                    }

                    $nextUrl = '';
                    if (preg_match('/<([^>]+)>;\s*rel=["\']next["\']/i', $headersStr, $match)) {
                        $nextUrl = $match[1];
                    }
                } else {
                    $bodyStr   = is_string($response) ? substr($response, $headerSize) : '';
                    $errorData = json_decode($bodyStr, true);
                    $apiError  = "HTTP {$httpCode} on {$domain}";
                    if ($curlError) {
                        $apiError .= " | cURL: {$curlError}";
                    }
                    if (!empty($errorData['errors'])) {
                        $apiError .= " | " . json_encode($errorData['errors']);
                    } elseif ($bodyStr) {
                        $apiError .= " | " . substr($bodyStr, 0, 300);
                    }
                    break;
                }
            }

            if ($gotValidResponse) {
                $shopifyPages     = $tempPages;
                $successfulDomain = $domain;
                break;
            }
        }

        if ($db && !empty($shopifyPages)) {
            $insertStmt = $db->prepare("
                INSERT INTO shopify_pages (
                    store_key, shopify_page_id, page_title, page_type, page_url,
                    title, meta_description, handle, author,
                    status, seo_score, last_synced_at
                ) VALUES (
                    :store, :pid, :pname, :ptype, :purl,
                    :title, :meta_desc, :handle, :author,
                    :status, :seo_score, NOW()
                )
                ON DUPLICATE KEY UPDATE
                    page_title        = VALUES(page_title),
                    page_type         = VALUES(page_type),
                    page_url          = VALUES(page_url),
                    title             = IF(shopify_pages.status = 'draft' AND shopify_pages.title != '', shopify_pages.title, VALUES(title)),
                    meta_description  = IF(shopify_pages.status = 'draft' AND shopify_pages.meta_description != '', shopify_pages.meta_description, VALUES(meta_description)),
                    handle            = IF(shopify_pages.status = 'draft' AND shopify_pages.handle != '', shopify_pages.handle, VALUES(handle)),
                    author            = VALUES(author),
                    seo_score         = VALUES(seo_score),
                    status            = VALUES(status),
                    last_synced_at    = NOW()
            ");

            foreach ($shopifyPages as $p) {
                $pid    = $p['id'] ?? 0;
                $pname  = $p['title'] ?? 'Untitled Page';
                $handle = $p['handle'] ?? '';
                $author = $p['author'] ?? 'Uratex Team';

                $pageUrl = "https://" . (!empty($shopCfg['domain']) ? $shopCfg['domain'] : $successfulDomain) . "/pages/" . $handle;

                $title     = $p['title'] ?? $pname;
                $bodyClean = strip_tags($p['body_html'] ?? '');
                $metaDesc  = mb_substr($bodyClean, 0, 160);
                if (empty($metaDesc)) {
                    $metaDesc = "Learn more about {$pname} at Uratex Philippines.";
                }

                // Simple page type classification from template_suffix or title
                $pageType = 'General Page';
                if (!empty($p['template_suffix'])) {
                    $pageType = ucwords(str_replace(['-', '_'], ' ', $p['template_suffix']));
                } elseif (stripos($pname, 'about') !== false) {
                    $pageType = 'Brand Story';
                } elseif (stripos($pname, 'contact') !== false) {
                    $pageType = 'Contact / Support';
                } elseif (stripos($pname, 'policy') !== false || stripos($pname, 'privacy') !== false || stripos($pname, 'terms') !== false) {
                    $pageType = 'Legal Policy';
                } elseif (stripos($pname, 'faq') !== false) {
                    $pageType = 'Help / FAQs';
                }

                if (function_exists('calculateSeoHealth')) {
                    $seoAnalysis = calculateSeoHealth($title, $metaDesc, $handle);
                    $score       = $seoAnalysis['score'];
                } else {
                    $score = 85;
                }

                // ★ REAL status from Shopify
                $status = mapPageStatus($p['published_at'] ?? null);

                $insertStmt->execute([
                    ':store'     => $activeStore,
                    ':pid'       => $pid,
                    ':pname'     => $pname,
                    ':ptype'     => $pageType,
                    ':purl'      => $pageUrl,
                    ':title'     => $title,
                    ':meta_desc' => $metaDesc,
                    ':handle'    => $handle,
                    ':author'    => $author,
                    ':status'    => $status,
                    ':seo_score' => $score
                ]);
                $syncedCount++;
            }

            $message = "✅ Successfully synchronized <strong>{$syncedCount}</strong> pages from <strong>{$successfulDomain}</strong> ({$shopCfg['name']}).";
            recordUserLog('sync_success', 'pages', "Synced {$syncedCount} pages from {$successfulDomain}", 'page', null, 'success');
        } else {
            if ($apiError) {
                $message = "ERROR: Shopify API failed for {$shopCfg['name']}. {$apiError}";
            } else {
                $message = "ERROR: No pages were returned from the Shopify API for {$shopCfg['name']}. Please check your API credentials and store configuration.";
            }
            recordUserLog('sync_error', 'pages', $message, 'page', null, 'error');
        }
    } catch (Throwable $e) {
        $message = "ERROR: Sync crashed – " . htmlspecialchars($e->getMessage()) .
                   " (file: " . basename($e->getFile()) . " line " . $e->getLine() . ")";
        recordUserLog('sync_crash', 'pages', $message, 'page', null, 'error');
    }
}

// C. SAVE DRAFT
if (isset($_POST['action']) && $_POST['action'] === 'save_draft') {
    try {
        $pageId          = (int)($_POST['page_id'] ?? 0);
        $title           = trim($_POST['title'] ?? '');
        $metaDescription = trim($_POST['meta_description'] ?? '');
        $handle          = trim($_POST['handle'] ?? '');

        if ($pageId && !empty($title) && $db) {
            $stmt = $db->prepare("
                UPDATE shopify_pages
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
                ':id'        => $pageId,
                ':store'     => $activeStore
            ]);
            $message = "SEO Draft saved successfully for page #{$pageId}.";
        }
    } catch (Throwable $e) {
        $message = "ERROR: Save draft failed – " . htmlspecialchars($e->getMessage());
    }
}

// D. PUSH TO SHOPIFY
if (isset($_POST['action']) && $_POST['action'] === 'push_shopify') {
    try {
        $pageId          = (int)($_POST['page_id'] ?? 0);
        $title           = trim($_POST['title'] ?? '');
        $metaDescription = trim($_POST['meta_description'] ?? '');
        $handle          = trim($_POST['handle'] ?? '');

        if ($pageId && $db) {
            $stmt = $db->prepare("SELECT * FROM shopify_pages WHERE id = :id AND store_key = :store LIMIT 1");
            $stmt->execute([':id' => $pageId, ':store' => $activeStore]);
            $pg = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($pg) {
                $shopifyPid  = $pg['shopify_page_id'];
                $adminDomain = getShopifyAdminDomain($shopCfg, $activeStore);
                $version     = !empty($shopCfg['version']) ? $shopCfg['version'] : '2025-10';

                $putUrl = "https://{$adminDomain}/admin/api/{$version}/pages/{$shopifyPid}.json";

                $payload = json_encode([
                    "page" => [
                        "id"                                => $shopifyPid,
                        "title"                             => $title ?: $pg['title'],
                        "handle"                            => $handle ?: $pg['handle'],
                        "body_html"                         => $metaDescription ?: $pg['meta_description'],
                        "metafields_global_title_tag"       => $title ?: $pg['title'],
                        "metafields_global_description_tag" => $metaDescription ?: $pg['meta_description']
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
                    UPDATE shopify_pages
                    SET title = :title,
                        meta_description = :meta_desc,
                        handle = :handle,
                        status = 'published',
                        last_pushed_at = NOW(),
                        updated_by = :user
                    WHERE id = :id
                ");
                $upStmt->execute([
                    ':title'     => $title ?: $pg['title'],
                    ':meta_desc' => $metaDescription ?: $pg['meta_description'],
                    ':handle'    => $handle ?: $pg['handle'],
                    ':user'      => $currentUser,
                    ':id'        => $pageId
                ]);

                if ($httpCode >= 200 && $httpCode < 300) {
                    $message = "✅ Live SEO update pushed to Shopify store ({$shopCfg['name']}) successfully!";
                } else {
                    $message = "⚠️ Local draft updated, but Shopify API returned HTTP {$httpCode}. Please verify the push.";
                }
            }
        }
    } catch (Throwable $e) {
        $message = "ERROR: Push failed – " . htmlspecialchars($e->getMessage());
    }
}

// E. BULK APPROVE & PUSH
if (isset($_POST['action']) && $_POST['action'] === 'bulk_push') {
    try {
        if ($db) {
            $bStmt = $db->prepare("
                UPDATE shopify_pages
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
            $message  = "Bulk approved & published {$affected} draft pages for {$shopCfg['name']}.";
        }
    } catch (Throwable $e) {
        $message = "ERROR: Bulk push failed – " . htmlspecialchars($e->getMessage());
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
    $whereClauses[]    = "(title LIKE :search OR handle LIKE :search OR page_title LIKE :search)";
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

$totalPagesCount = 0;
$draftCount      = 0;
$publishedCount  = 0;
$avgScore        = 0;

if ($db) {
    try {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM shopify_pages WHERE {$whereSql}");
        $countStmt->execute($params);
        $totalPagesCount = (int)$countStmt->fetchColumn();

        $dStmt = $db->prepare("SELECT COUNT(*) FROM shopify_pages WHERE store_key = :store AND status = 'draft'");
        $dStmt->execute([':store' => $activeStore]);
        $draftCount = (int)$dStmt->fetchColumn();

        $pStmt = $db->prepare("SELECT COUNT(*) FROM shopify_pages WHERE store_key = :store AND status = 'published'");
        $pStmt->execute([':store' => $activeStore]);
        $publishedCount = (int)$pStmt->fetchColumn();

        $sStmt = $db->prepare("SELECT AVG(seo_score) FROM shopify_pages WHERE store_key = :store");
        $sStmt->execute([':store' => $activeStore]);
        $avgScore = (int)round((float)$sStmt->fetchColumn());
    } catch (Throwable $e) {
        // silent
    }
}

$totalPages = max(1, ceil($totalPagesCount / $itemsPerPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

$pagesList = [];
if ($db) {
    try {
        $querySql = "SELECT * FROM shopify_pages WHERE {$whereSql} ORDER BY id ASC LIMIT {$itemsPerPage} OFFSET {$offset}";
        $stmt     = $db->prepare($querySql);
        $stmt->execute($params);
        $pagesList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $pagesList = [];
    }
}

$pageTitle = 'Pages SEO Module';
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
          <h1 class="m-0 font-weight-bold" style="color: #003087;">Pages SEO Module</h1>
          <p class="text-muted small mb-0">Optimize page titles, meta descriptions, and handles.</p>
        </div>

        <div class="col-sm-6 text-right d-flex justify-content-end align-items-center gap-2 flex-wrap">
          <a href="?export=csv&store=<?php echo urlencode($activeStore); ?>" class="btn btn-sm btn-outline-secondary font-weight-bold mr-1">
            <i class="fas fa-file-csv mr-1"></i> CSV
          </a>
          <a href="?export=json&store=<?php echo urlencode($activeStore); ?>" class="btn btn-sm btn-outline-secondary font-weight-bold mr-2">
            <i class="fas fa-file-code mr-1"></i> JSON
          </a>

          <form method="POST" class="d-inline mr-2">
            <input type="hidden" name="action" value="test_connection">
            <button type="submit" class="btn font-weight-bold text-white shadow-sm" style="background-color: #007bff;">
              <i class="fas fa-plug mr-1"></i> Test Connection
            </button>
          </form>

          <form method="POST" class="d-inline mr-2" id="syncForm">
            <input type="hidden" name="action" value="sync_pages">
            <button type="submit" id="btnSyncPages" class="btn font-weight-bold shadow-sm" style="background-color: #FFCC00; color: #1f2937; border: 1px solid #eab308;">
              <i class="fas fa-sync-alt mr-1" id="syncIcon"></i> Sync Pages
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
            <span class="text-muted small font-weight-bold text-uppercase" style="font-size: 11px;">Total Pages</span>
            <h3 class="font-weight-bold mb-0 text-dark mt-1"><?php echo $totalPagesCount; ?></h3>
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
        <form method="GET" action="pages.php" class="row align-items-center">
          <input type="hidden" name="store" value="<?php echo htmlspecialchars($storeKey); ?>">
          <div class="col-md-5 mb-2 mb-md-0">
            <div class="input-group">
              <div class="input-group-prepend">
                <span class="input-group-text bg-white border-right-0"><i class="fas fa-search text-muted"></i></span>
              </div>
              <input type="text" name="search" class="form-control border-left-0"
                     placeholder="Search page title or handle..."
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
          Showing <strong><?php echo $totalPagesCount > 0 ? $offset + 1 : 0; ?></strong> to
          <strong><?php echo min($offset + $itemsPerPage, $totalPagesCount); ?></strong> of
          <strong><?php echo $totalPagesCount; ?></strong> pages (20 per page)
        </div>
        <div>Page <strong><?php echo $currentPage; ?></strong> of <strong><?php echo $totalPages; ?></strong></div>
      </div>

      <!-- Pages Grid -->
      <div class="row">
        <?php if (empty($pagesList)): ?>
          <div class="col-12 text-center py-5">
            <div class="p-5 bg-white rounded-lg shadow-sm border">
              <i class="fas fa-file-alt fa-3x text-muted mb-3"></i>
              <h5 class="font-weight-bold text-secondary">No Pages Found</h5>
              <p class="text-muted small mb-3">Click "Sync Pages" to import live pages for <?php echo htmlspecialchars($shopCfg['name'] ?? $activeStore); ?>.</p>
              <form method="POST">
                <input type="hidden" name="action" value="sync_pages">
                <button type="submit" class="btn font-weight-bold" style="background-color: #FFCC00; color: #1f2937;">
                  <i class="fas fa-sync-alt mr-1"></i> Sync Pages Now
                </button>
              </form>
            </div>
          </div>
        <?php else: ?>
          <?php foreach ($pagesList as $pg): ?>
            <?php
              $score       = (int)($pg['seo_score'] ?? 85);
              $status      = $pg['status'] ?? 'draft';
              $statusBadge = $status === 'published' ? 'badge-primary'
                           : ($status === 'archived' ? 'badge-secondary' : 'badge-success');
              $pgId        = (int)$pg['id'];
              $pgTitle     = $pg['title'] ?? '';
              $pgName      = $pg['page_title'] ?? $pgTitle;
              $pgMeta      = $pg['meta_description'] ?? '';
              $pgHandle    = $pg['handle'] ?? '';
              $pgType      = $pg['page_type'] ?? 'General Page';
              $pgAuthor    = $pg['author'] ?? 'Uratex Team';
              $pgUrl       = $pg['page_url'] ?? ("https://" . ($shopCfg['domain'] ?? '') . "/pages/" . $pgHandle);
            ?>
            <div class="col-md-6 mb-4">
              <div class="card shadow-sm h-100 border-0" style="border-radius: 12px; overflow: hidden; border-top: 4px solid #003087 !important;">

                <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 border-bottom">
                  <div class="d-flex align-items-center text-truncate mr-2">
                    <i class="fas fa-file-alt text-primary mr-2"></i>
                    <div>
                      <h6 class="font-weight-bold mb-0 text-truncate text-dark" title="<?php echo htmlspecialchars($pgName); ?>">
                        <?php echo htmlspecialchars($pgName); ?>
                      </h6>
                      <span class="badge badge-light border text-muted mt-1" style="font-size: 10px;">
                        <i class="fas fa-tag mr-1"></i><?php echo htmlspecialchars($pgType); ?>
                        &bull; <i class="fas fa-user-edit ml-1"></i><?php echo htmlspecialchars($pgAuthor); ?>
                      </span>
                    </div>
                  </div>
                  <div class="d-flex align-items-center">
                    <span class="badge badge-info mr-1" style="font-size: 10px;"><?php echo $score; ?>% SEO</span>
                    <span class="badge <?php echo $statusBadge; ?> text-uppercase" style="font-size: 10px;">
                      <?php echo htmlspecialchars(ucfirst($status)); ?>
                    </span>
                  </div>
                </div>

                <div class="card-body p-4">
                  <div class="d-flex justify-content-between align-items-center mb-3 p-2 rounded bg-light border" style="font-size: 11px;">
                    <span class="text-muted text-truncate mr-2">
                      <i class="fas fa-link text-primary mr-1"></i>
                      <strong>URL:</strong> /pages/<?php echo htmlspecialchars($pgHandle); ?>
                    </span>
                    <a href="<?php echo htmlspecialchars($pgUrl); ?>" target="_blank" rel="noreferrer" class="text-primary font-weight-bold text-nowrap">
                      View Live <i class="fas fa-external-link-alt ml-0.5"></i>
                    </a>
                  </div>

                  <form method="POST" action="pages.php?page=<?php echo $currentPage; ?>">
                    <input type="hidden" name="page_id" value="<?php echo $pgId; ?>">

                    <div class="form-group mb-3">
                      <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="font-weight-bold small text-secondary mb-0">Page SEO Title</label>
                        <span class="text-muted small">
                          <span id="t-count-<?php echo $pgId; ?>"><?php echo mb_strlen($pgTitle); ?></span>/60 chars
                        </span>
                      </div>
                      <input type="text" name="title" id="title-<?php echo $pgId; ?>"
                             class="form-control font-weight-bold"
                             value="<?php echo htmlspecialchars($pgTitle); ?>"
                             oninput="document.getElementById('t-count-<?php echo $pgId; ?>').innerText = this.value.length;"
                             required>
                    </div>

                    <div class="form-group mb-3">
                      <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="font-weight-bold small text-secondary mb-0">Meta Description</label>
                        <span class="text-muted small">
                          <span id="m-count-<?php echo $pgId; ?>"><?php echo mb_strlen($pgMeta); ?></span>/160 chars
                        </span>
                      </div>
                      <textarea name="meta_description" id="meta-<?php echo $pgId; ?>"
                                class="form-control" rows="3" style="resize: vertical;"
                                oninput="document.getElementById('m-count-<?php echo $pgId; ?>').innerText = this.value.length;"><?php echo htmlspecialchars($pgMeta); ?></textarea>
                    </div>

                    <div class="form-group mb-4">
                      <label class="font-weight-bold small text-secondary mb-1">URL Handle</label>
                      <div class="input-group">
                        <div class="input-group-prepend">
                          <span class="input-group-text bg-light text-muted" style="font-size: 12px;">/pages/</span>
                        </div>
                        <input type="text" name="handle" id="handle-<?php echo $pgId; ?>"
                               class="form-control font-mono"
                               value="<?php echo htmlspecialchars($pgHandle); ?>" required>
                      </div>
                    </div>

                    <div class="d-flex justify-content-between pt-3 border-top">
                      <button type="submit" name="action" value="save_draft"
                              class="btn btn-light border font-weight-bold">
                        <i class="fas fa-save mr-1 text-secondary"></i> Save Draft
                      </button>
                      <button type="submit" name="action" value="push_shopify"
                              class="btn font-weight-bold text-white" style="background-color: #003087;"
                              onclick="return confirm('Push this page live to Shopify?');">
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
              (<strong><?php echo $totalPagesCount; ?></strong> total pages
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

<script>
document.getElementById('syncForm')?.addEventListener('submit', function () {
  const icon = document.getElementById('syncIcon');
  const btn  = document.getElementById('btnSyncPages');
  if (icon && btn) {
    icon.classList.add('fa-spin');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-sync-alt fa-spin mr-1"></i> Synchronizing...';
  }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>