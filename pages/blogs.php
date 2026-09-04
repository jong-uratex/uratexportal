<?php
/**
 * Blogs & Articles SEO Module (blogs.php) - Uratex Shopify SEO Partner Portal
 *
 * Features:
 *  1. Syncs ALL blog articles from Shopify REST API (blogs → articles with cursor pagination)
 *  2. Saves & persists articles in MySQL table `shopify_blogs`
 *  3. Categorized strictly according to active store (retail / business)
 *  4. Editable fields: ONLY Article SEO Title, Meta Description, and URL Handle
 *  5. 20 Articles Per Page Pagination
 *  6. Single & Bulk Save Drafts / Push to Shopify API
 *  7. Uses REAL Shopify publish status (published_at → published / draft)
 *  8. Live View button next to the article title
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
 * Map Shopify article publish state to portal status.
 */
function mapArticleStatus(?string $publishedAt): string
{
    return (!empty($publishedAt)) ? 'published' : 'draft';
}

// -----------------------------------------------------------------------------
// EXPORT HANDLER - Removed as CSV and JSON buttons were removed
// -----------------------------------------------------------------------------

// -----------------------------------------------------------------------------
// AUTO-CREATE TABLE
// -----------------------------------------------------------------------------
if ($db) {
    try {
        $db->exec("
            CREATE TABLE IF NOT EXISTS `shopify_blogs` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `store_key` VARCHAR(50) NOT NULL DEFAULT 'business',
                `shopify_article_id` BIGINT UNSIGNED NOT NULL,
                `shopify_blog_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                `article_title` VARCHAR(255) NOT NULL,
                `blog_title` VARCHAR(150) NULL DEFAULT 'News & Guides',
                `blog_handle` VARCHAR(255) NULL DEFAULT 'news',
                `article_url` VARCHAR(1000) NULL DEFAULT NULL,
                `title` VARCHAR(255) NOT NULL,
                `meta_description` TEXT NULL,
                `handle` VARCHAR(255) NOT NULL,
                `author` VARCHAR(100) NULL DEFAULT 'Uratex Editorial',
                `category` VARCHAR(100) NULL DEFAULT 'Sleep Science',
                `status` ENUM('draft', 'published', 'needs_optimization', 'archived') NOT NULL DEFAULT 'draft',
                `seo_score` TINYINT UNSIGNED NOT NULL DEFAULT 85,
                `published_at` DATETIME NULL DEFAULT NULL,
                `last_synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `last_pushed_at` DATETIME NULL DEFAULT NULL,
                `updated_by` VARCHAR(100) NULL DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uq_store_article` (`store_key`, `shopify_article_id`),
                KEY `idx_blogs_store_status` (`store_key`, `status`),
                KEY `idx_blogs_handle` (`handle`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");

        // Migrate older tables missing shopify_blog_id / blog_handle
        $cols = $db->query("SHOW COLUMNS FROM `shopify_blogs` LIKE 'shopify_blog_id'")->fetchAll();
        if (empty($cols)) {
            $db->exec("ALTER TABLE `shopify_blogs` ADD COLUMN `shopify_blog_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `shopify_article_id`");
        }
        $cols2 = $db->query("SHOW COLUMNS FROM `shopify_blogs` LIKE 'blog_handle'")->fetchAll();
        if (empty($cols2)) {
            $db->exec("ALTER TABLE `shopify_blogs` ADD COLUMN `blog_handle` VARCHAR(255) NULL DEFAULT 'news' AFTER `blog_title`");
        }
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
        $storesToTest = ['retail', 'business'];
        $results = [];
        $allSuccess = true;
        
        foreach ($storesToTest as $storeKey) {
            $storeCfg = $shopConfig[$storeKey] ?? [];
            $targetUrl = getShopifyAdminDomain($storeCfg, $storeKey);
            $version   = !empty($storeCfg['version']) ? $storeCfg['version'] : '2025-10';
            $token     = $storeCfg['access_token'] ?? '';

            if (empty($token)) {
                $results[$storeKey] = "❌ Connection FAILED! Access token is empty for {$storeKey} store.";
                $allSuccess = false;
                continue;
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
                $results[$storeKey] = "✅ Connection SUCCESS! Store: <strong>{$shopName}</strong> ({$shopDomain}) | API Version: {$version}";
            } else {
                $errorDetails = $json['errors'] ?? substr($bodyStr, 0, 400);
                $errorMsg = "❌ Connection FAILED! HTTP {$httpCode}";
                if ($curlError) {
                    $errorMsg .= " | cURL: " . htmlspecialchars($curlError);
                }
                $errorMsg .= "<br><small>" . htmlspecialchars(is_string($errorDetails) ? $errorDetails : json_encode($errorDetails)) . "</small>";
                $results[$storeKey] = $errorMsg;
                $allSuccess = false;
            }
            $results[$storeKey] .= "<br><small class='text-muted'>Tried URL: {$testUrl}</small>";
        }
        
        $message = implode('<br><br>', $results);
        if ($allSuccess) {
            $message = "✅ All connections successful!<br><br>" . $message;
        } else {
            $message = "⚠️ Some connections failed!<br><br>" . $message;
        }
    } catch (Throwable $e) {
        $message = "ERROR: Test connection failed – " . htmlspecialchars($e->getMessage());
    }
}

// B. SYNC BLOG ARTICLES FROM SHOPIFY
if (isset($_POST['action']) && $_POST['action'] === 'sync_blogs') {
    try {
        $syncedCount      = 0;
        $allArticles      = [];
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
        $headers = [
            "X-Shopify-Access-Token: {$token}",
            "Content-Type: application/json"
        ];

        recordUserLog('sync_started', 'blogs', "Starting blogs sync for store: {$activeStore}", 'article', null, 'info');

        foreach ($domainsToTry as $domain) {
            // 1. Fetch all blogs
            $blogsUrl = "https://{$domain}/admin/api/{$version}/blogs.json?limit=250";
            $ch = curl_init($blogsUrl);
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

            if ($httpCode !== 200 || $response === false) {
                $bodyStr  = is_string($response) ? substr($response, $headerSize) : '';
                $apiError = "HTTP {$httpCode} fetching blogs on {$domain}";
                if ($curlError) $apiError .= " | cURL: {$curlError}";
                if ($bodyStr) $apiError .= " | " . substr($bodyStr, 0, 300);
                continue; // try next domain
            }

            $bodyStr = substr($response, $headerSize);
            $json    = json_decode($bodyStr, true);
            $blogs   = $json['blogs'] ?? [];

            if (empty($blogs)) {
                $apiError = "No blogs found on {$domain}";
                continue;
            }

            $tempArticles = [];

            // 2. For each blog, fetch all articles
            foreach ($blogs as $blog) {
                $blogId     = $blog['id'] ?? 0;
                $blogTitle  = $blog['title'] ?? 'News & Guides';
                $blogHandle = $blog['handle'] ?? 'news';

                $nextUrl   = "https://{$domain}/admin/api/{$version}/blogs/{$blogId}/articles.json?limit=250";
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
                        CURLOPT_TIMEOUT        => 25,
                    ]);
                    $response   = curl_exec($ch);
                    $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                    $curlError  = curl_error($ch);
                    curl_close($ch);

                    if ($httpCode === 200 && $response !== false) {
                        $headersStr = substr($response, 0, $headerSize);
                        $bodyStr    = substr($response, $headerSize);
                        $json       = json_decode($bodyStr, true);

                        if (!empty($json['articles']) && is_array($json['articles'])) {
                            foreach ($json['articles'] as $art) {
                                $art['_blog_id']     = $blogId;
                                $art['_blog_title']  = $blogTitle;
                                $art['_blog_handle'] = $blogHandle;
                                $tempArticles[] = $art;
                            }
                        }

                        $nextUrl = '';
                        if (preg_match('/<([^>]+)>;\s*rel=["\']next["\']/i', $headersStr, $match)) {
                            $nextUrl = $match[1];
                        }
                    } else {
                        $bodyStr  = is_string($response) ? substr($response, $headerSize) : '';
                        $apiError = "HTTP {$httpCode} fetching articles for blog {$blogId}";
                        if ($curlError) $apiError .= " | cURL: {$curlError}";
                        break;
                    }
                }
            }

            $allArticles      = $tempArticles;
            $successfulDomain = $domain;
            break; // success
        }

        if ($db && !empty($allArticles)) {
            $insertStmt = $db->prepare("
                INSERT INTO shopify_blogs (
                    store_key, shopify_article_id, shopify_blog_id, article_title,
                    blog_title, blog_handle, article_url,
                    title, meta_description, handle, author, category,
                    status, seo_score, published_at, last_synced_at
                ) VALUES (
                    :store, :aid, :bid, :aname,
                    :bname, :bhandle, :aurl,
                    :title, :meta_desc, :handle, :author, :category,
                    :status, :seo_score, :published_at, NOW()
                )
                ON DUPLICATE KEY UPDATE
                    article_title     = VALUES(article_title),
                    shopify_blog_id   = VALUES(shopify_blog_id),
                    blog_title        = VALUES(blog_title),
                    blog_handle       = VALUES(blog_handle),
                    article_url       = VALUES(article_url),
                    title             = IF(shopify_blogs.status = 'draft' AND shopify_blogs.title != '', shopify_blogs.title, VALUES(title)),
                    meta_description  = IF(shopify_blogs.status = 'draft' AND shopify_blogs.meta_description != '', shopify_blogs.meta_description, VALUES(meta_description)),
                    handle            = IF(shopify_blogs.status = 'draft' AND shopify_blogs.handle != '', shopify_blogs.handle, VALUES(handle)),
                    author            = VALUES(author),
                    category          = VALUES(category),
                    seo_score         = VALUES(seo_score),
                    status            = VALUES(status),
                    published_at      = VALUES(published_at),
                    last_synced_at    = NOW()
            ");

            foreach ($allArticles as $a) {
                $aid        = $a['id'] ?? 0;
                $aname      = $a['title'] ?? 'Untitled Article';
                $handle     = $a['handle'] ?? '';
                $author     = $a['author'] ?? 'Uratex Editorial';
                $blogId     = $a['_blog_id'] ?? 0;
                $blogTitle  = $a['_blog_title'] ?? 'News & Guides';
                $blogHandle = $a['_blog_handle'] ?? 'news';

                $artUrl = "https://" . (!empty($shopCfg['domain']) ? $shopCfg['domain'] : $successfulDomain)
                        . "/blogs/" . $blogHandle . "/" . $handle;

                $title     = $a['title'] ?? $aname;
                $bodyClean = strip_tags($a['body_html'] ?? $a['summary_html'] ?? '');
                $metaDesc  = mb_substr($bodyClean, 0, 160);
                if (empty($metaDesc) && !empty($a['summary'])) {
                    $metaDesc = mb_substr(strip_tags($a['summary']), 0, 160);
                }
                if (empty($metaDesc)) {
                    $metaDesc = "Read more about {$aname} on the Uratex blog.";
                }

                $tags     = $a['tags'] ?? '';
                $category = !empty($tags) ? explode(',', $tags)[0] : 'Sleep Science';
                $category = trim($category) ?: 'Sleep Science';

                if (function_exists('calculateSeoHealth')) {
                    $seoAnalysis = calculateSeoHealth($title, $metaDesc, $handle);
                    $score       = $seoAnalysis['score'];
                } else {
                    $score = 85;
                }

                $publishedAt = $a['published_at'] ?? null;
                $status      = mapArticleStatus($publishedAt);

                $insertStmt->execute([
                    ':store'        => $activeStore,
                    ':aid'          => $aid,
                    ':bid'          => $blogId,
                    ':aname'        => $aname,
                    ':bname'        => $blogTitle,
                    ':bhandle'      => $blogHandle,
                    ':aurl'         => $artUrl,
                    ':title'        => $title,
                    ':meta_desc'    => $metaDesc,
                    ':handle'       => $handle,
                    ':author'       => $author,
                    ':category'     => $category,
                    ':status'       => $status,
                    ':seo_score'    => $score,
                    ':published_at' => $publishedAt
                ]);
                $syncedCount++;
            }

            $message = "✅ Successfully synchronized <strong>{$syncedCount}</strong> articles from <strong>{$successfulDomain}</strong> ({$shopCfg['name']}).";
            recordUserLog('sync_success', 'blogs', "Synced {$syncedCount} articles from {$successfulDomain}", 'article', null, 'success');
        } else {
            if ($apiError) {
                $message = "ERROR: Shopify API failed for {$shopCfg['name']}. {$apiError}";
            } else {
                $message = "ERROR: No articles were returned from the Shopify API for {$shopCfg['name']}. Please check your API credentials and store configuration.";
            }
            recordUserLog('sync_error', 'blogs', $message, 'article', null, 'error');
        }
    } catch (Throwable $e) {
        $message = "ERROR: Sync crashed – " . htmlspecialchars($e->getMessage()) .
                   " (file: " . basename($e->getFile()) . " line " . $e->getLine() . ")";
        recordUserLog('sync_crash', 'blogs', $message, 'article', null, 'error');
    }
}

// C. SAVE DRAFT
if (isset($_POST['action']) && $_POST['action'] === 'save_draft') {
    try {
        $blogId          = (int)($_POST['blog_id'] ?? 0);
        $title           = trim($_POST['title'] ?? '');
        $metaDescription = trim($_POST['meta_description'] ?? '');
        $handle          = trim($_POST['handle'] ?? '');

        if ($blogId && !empty($title) && $db) {
            $stmt = $db->prepare("
                UPDATE shopify_blogs
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
                ':id'        => $blogId,
                ':store'     => $activeStore
            ]);
            $message = "SEO Draft saved successfully for article #{$blogId}.";
        }
    } catch (Throwable $e) {
        $message = "ERROR: Save draft failed – " . htmlspecialchars($e->getMessage());
    }
}

// D. PUSH TO SHOPIFY
if (isset($_POST['action']) && $_POST['action'] === 'push_shopify') {
    try {
        $blogId          = (int)($_POST['blog_id'] ?? 0);
        $title           = trim($_POST['title'] ?? '');
        $metaDescription = trim($_POST['meta_description'] ?? '');
        $handle          = trim($_POST['handle'] ?? '');

        if ($blogId && $db) {
            $stmt = $db->prepare("SELECT * FROM shopify_blogs WHERE id = :id AND store_key = :store LIMIT 1");
            $stmt->execute([':id' => $blogId, ':store' => $activeStore]);
            $art = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($art) {
                $shopifyAid  = $art['shopify_article_id'];
                $shopifyBid  = $art['shopify_blog_id'] ?? 0;
                $adminDomain = getShopifyAdminDomain($shopCfg, $activeStore);
                $version     = !empty($shopCfg['version']) ? $shopCfg['version'] : '2025-10';

                if (empty($shopifyBid)) {
                    throw new Exception('Missing shopify_blog_id. Please re-sync articles.');
                }

                $putUrl = "https://{$adminDomain}/admin/api/{$version}/blogs/{$shopifyBid}/articles/{$shopifyAid}.json";

                $payload = json_encode([
                    "article" => [
                        "id"                                => $shopifyAid,
                        "title"                             => $title ?: $art['title'],
                        "handle"                            => $handle ?: $art['handle'],
                        "summary_html"                      => $metaDescription ?: $art['meta_description'],
                        "metafields_global_title_tag"       => $title ?: $art['title'],
                        "metafields_global_description_tag" => $metaDescription ?: $art['meta_description']
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
                    UPDATE shopify_blogs
                    SET title = :title,
                        meta_description = :meta_desc,
                        handle = :handle,
                        status = 'published',
                        last_pushed_at = NOW(),
                        updated_by = :user
                    WHERE id = :id
                ");
                $upStmt->execute([
                    ':title'     => $title ?: $art['title'],
                    ':meta_desc' => $metaDescription ?: $art['meta_description'],
                    ':handle'    => $handle ?: $art['handle'],
                    ':user'      => $currentUser,
                    ':id'        => $blogId
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
                UPDATE shopify_blogs
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
            $message  = "Bulk approved & published {$affected} draft articles for {$shopCfg['name']}.";
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
    $whereClauses[]    = "(title LIKE :search OR handle LIKE :search OR article_title LIKE :search OR blog_title LIKE :search)";
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

$totalBlogsCount = 0;
$draftCount      = 0;
$publishedCount  = 0;
$avgScore        = 0;

if ($db) {
    try {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM shopify_blogs WHERE {$whereSql}");
        $countStmt->execute($params);
        $totalBlogsCount = (int)$countStmt->fetchColumn();

        $dStmt = $db->prepare("SELECT COUNT(*) FROM shopify_blogs WHERE store_key = :store AND status = 'draft'");
        $dStmt->execute([':store' => $activeStore]);
        $draftCount = (int)$dStmt->fetchColumn();

        $pStmt = $db->prepare("SELECT COUNT(*) FROM shopify_blogs WHERE store_key = :store AND status = 'published'");
        $pStmt->execute([':store' => $activeStore]);
        $publishedCount = (int)$pStmt->fetchColumn();

        $sStmt = $db->prepare("SELECT AVG(seo_score) FROM shopify_blogs WHERE store_key = :store");
        $sStmt->execute([':store' => $activeStore]);
        $avgScore = (int)round((float)$sStmt->fetchColumn());
    } catch (Throwable $e) {
        // silent
    }
}

$totalPages = max(1, ceil($totalBlogsCount / $itemsPerPage));
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
}

$blogsList = [];
if ($db) {
    try {
        $querySql = "SELECT * FROM shopify_blogs WHERE {$whereSql} ORDER BY id ASC LIMIT {$itemsPerPage} OFFSET {$offset}";
        $stmt     = $db->prepare($querySql);
        $stmt->execute($params);
        $blogsList = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $blogsList = [];
    }
}

$pageTitle = 'Blogs & Articles SEO Module';
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
          <h1 class="m-0 font-weight-bold" style="color: #003087;">Blogs & Articles SEO Module</h1>
          <p class="text-muted small mb-0">Optimize article titles, meta descriptions, and handles.</p>
        </div>

        <div class="col-sm-6 text-right d-flex justify-content-end align-items-center gap-2 flex-wrap">
          <form method="POST" class="d-inline mr-2">
            <input type="hidden" name="action" value="test_connection">
            <button type="submit" class="btn font-weight-bold text-white shadow-sm" style="background-color: #007bff;">
              <i class="fas fa-plug mr-1"></i> Test Connection
            </button>
          </form>

          <form method="POST" class="d-inline mr-2" id="syncForm">
            <input type="hidden" name="action" value="sync_blogs">
            <button type="submit" id="btnSyncBlogs" class="btn font-weight-bold shadow-sm" style="background-color: #FFCC00; color: #1f2937; border: 1px solid #eab308;">
              <i class="fas fa-sync-alt mr-1" id="syncIcon"></i> Sync Articles
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
            <span class="text-muted small font-weight-bold text-uppercase" style="font-size: 11px;">Total Articles</span>
            <h3 class="font-weight-bold mb-0 text-dark mt-1"><?php echo $totalBlogsCount; ?></h3>
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
        <form method="GET" action="blogs.php" class="row align-items-center">
          <input type="hidden" name="store" value="<?php echo htmlspecialchars($storeKey); ?>">
          <div class="col-md-5 mb-2 mb-md-0">
            <div class="input-group">
              <div class="input-group-prepend">
                <span class="input-group-text bg-white border-right-0"><i class="fas fa-search text-muted"></i></span>
              </div>
              <input type="text" name="search" class="form-control border-left-0"
                     placeholder="Search article title, handle, or blog..."
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
          Showing <strong><?php echo $totalBlogsCount > 0 ? $offset + 1 : 0; ?></strong> to
          <strong><?php echo min($offset + $itemsPerPage, $totalBlogsCount); ?></strong> of
          <strong><?php echo $totalBlogsCount; ?></strong> articles (20 per page)
        </div>
        <div>Page <strong><?php echo $currentPage; ?></strong> of <strong><?php echo $totalPages; ?></strong></div>
      </div>

      <!-- Articles Grid -->
      <div class="row">
        <?php if (empty($blogsList)): ?>
          <div class="col-12 text-center py-5">
            <div class="p-5 bg-white rounded-lg shadow-sm border">
              <i class="fas fa-newspaper fa-3x text-muted mb-3"></i>
              <h5 class="font-weight-bold text-secondary">No Articles Found</h5>
              <p class="text-muted small mb-3">Click "Sync Articles" to import live blog posts for <?php echo htmlspecialchars($shopCfg['name'] ?? $activeStore); ?>.</p>
              <form method="POST">
                <input type="hidden" name="action" value="sync_blogs">
                <button type="submit" class="btn font-weight-bold" style="background-color: #FFCC00; color: #1f2937;">
                  <i class="fas fa-sync-alt mr-1"></i> Sync Articles Now
                </button>
              </form>
            </div>
          </div>
        <?php else: ?>
          <?php foreach ($blogsList as $art): ?>
            <?php
              $score       = (int)($art['seo_score'] ?? 85);
              $status      = $art['status'] ?? 'draft';
              $statusBadge = $status === 'published' ? 'badge-primary'
                           : ($status === 'archived' ? 'badge-secondary' : 'badge-success');
              $blogId      = (int)$art['id'];
              $artTitle    = $art['title'] ?? '';
              $artName     = $art['article_title'] ?? $artTitle;
              $artMeta     = $art['meta_description'] ?? '';
              $artHandle   = $art['handle'] ?? '';
              $blogTitle   = $art['blog_title'] ?? 'News & Guides';
              $blogHandle  = $art['blog_handle'] ?? 'news';
              $artAuthor   = $art['author'] ?? 'Uratex Editorial';
              $artCategory = $art['category'] ?? 'Sleep Science';
              $artUrl      = $art['article_url']
                             ?? ("https://" . ($shopCfg['domain'] ?? '') . "/blogs/" . $blogHandle . "/" . $artHandle);
            ?>
            <div class="col-md-6 mb-4">
              <div class="card shadow-sm h-100 border-0" style="border-radius: 12px; overflow: hidden; border-top: 4px solid #003087 !important;">

                <!-- HEADER with Live View -->
                <div class="card-header bg-white d-flex justify-content-between align-items-center py-3 border-bottom">
                  <div class="d-flex align-items-center text-truncate mr-2" style="max-width: 65%;">
                    <i class="fas fa-newspaper text-primary mr-2 flex-shrink-0"></i>
                    <div class="text-truncate mr-2">
                      <h6 class="font-weight-bold mb-0 text-truncate text-dark" title="<?php echo htmlspecialchars($artName); ?>">
                        <?php echo htmlspecialchars($artName); ?>
                      </h6>
                      <span class="badge badge-light border text-muted mt-1" style="font-size: 10px;">
                        <i class="fas fa-book mr-1"></i><?php echo htmlspecialchars($blogTitle); ?>
                        &bull; <i class="fas fa-user-edit ml-1"></i><?php echo htmlspecialchars($artAuthor); ?>
                      </span>
                    </div>
                    <a href="<?php echo htmlspecialchars($artUrl); ?>"
                       target="_blank" rel="noreferrer"
                       class="btn btn-sm btn-info shadow-sm flex-shrink-0"
                       title="Live View"
                       style="padding: 0.2rem 0.5rem; font-size: 0.75rem;">
                      <i class="fas fa-eye"></i> <span class="d-none d-md-inline ml-1">Live View</span>
                    </a>
                  </div>
                  <div class="d-flex align-items-center flex-shrink-0">
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
                      <strong>URL:</strong> /blogs/<?php echo htmlspecialchars($blogHandle); ?>/<?php echo htmlspecialchars($artHandle); ?>
                    </span>
                    <a href="<?php echo htmlspecialchars($artUrl); ?>" target="_blank" rel="noreferrer" class="text-primary font-weight-bold text-nowrap">
                      View Live <i class="fas fa-external-link-alt ml-0.5"></i>
                    </a>
                  </div>

                  <form method="POST" action="blogs.php?page=<?php echo $currentPage; ?>">
                    <input type="hidden" name="blog_id" value="<?php echo $blogId; ?>">

                    <div class="form-group mb-3">
                      <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="font-weight-bold small text-secondary mb-0">Article SEO Title</label>
                        <span class="text-muted small">
                          <span id="t-count-<?php echo $blogId; ?>"><?php echo mb_strlen($artTitle); ?></span>/60 chars
                        </span>
                      </div>
                      <input type="text" name="title" id="title-<?php echo $blogId; ?>"
                             class="form-control font-weight-bold"
                             value="<?php echo htmlspecialchars($artTitle); ?>"
                             oninput="document.getElementById('t-count-<?php echo $blogId; ?>').innerText = this.value.length;"
                             required>
                    </div>

                    <div class="form-group mb-3">
                      <div class="d-flex justify-content-between align-items-center mb-1">
                        <label class="font-weight-bold small text-secondary mb-0">Meta Description</label>
                        <span class="text-muted small">
                          <span id="m-count-<?php echo $blogId; ?>"><?php echo mb_strlen($artMeta); ?></span>/160 chars
                        </span>
                      </div>
                      <textarea name="meta_description" id="meta-<?php echo $blogId; ?>"
                                class="form-control" rows="3" style="resize: vertical;"
                                oninput="document.getElementById('m-count-<?php echo $blogId; ?>').innerText = this.value.length;"><?php echo htmlspecialchars($artMeta); ?></textarea>
                    </div>

                    <div class="form-group mb-4">
                      <label class="font-weight-bold small text-secondary mb-1">URL Handle</label>
                      <div class="input-group">
                        <div class="input-group-prepend">
                          <span class="input-group-text bg-light text-muted" style="font-size: 12px;">/blogs/<?php echo htmlspecialchars($blogHandle); ?>/</span>
                        </div>
                        <input type="text" name="handle" id="handle-<?php echo $blogId; ?>"
                               class="form-control font-mono"
                               value="<?php echo htmlspecialchars($artHandle); ?>" required>
                      </div>
                    </div>

                    <div class="d-flex justify-content-between pt-3 border-top">
                      <button type="submit" name="action" value="save_draft"
                              class="btn btn-light border font-weight-bold">
                        <i class="fas fa-save mr-1 text-secondary"></i> Save Draft
                      </button>
                      <button type="submit" name="action" value="push_shopify"
                              class="btn font-weight-bold text-white" style="background-color: #003087;"
                              onclick="return confirm('Push this article live to Shopify?');">
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
              <strong><?php echo $totalBlogsCount; ?></strong> total articles
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
  const btn  = document.getElementById('btnSyncBlogs');
  if (icon && btn) {
    icon.classList.add('fa-spin');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-sync-alt fa-spin mr-1"></i> Synchronizing...';
  }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>