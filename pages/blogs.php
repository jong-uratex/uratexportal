<?php
/**
 * Blogs & Articles SEO Module (blogs.php) - Uratex Shopify SEO Partner Portal
 *
 * Features:
 *  1. Syncs ALL blog articles from Shopify REST API (blogs → articles with cursor pagination)
 *     + high-performance GraphQL path with REST fallback
 *  2. Saves & persists articles in MySQL table `shopify_blogs`
 *  3. Categorized strictly according to active store (retail / business)
 *  4. Editable fields: ONLY Article SEO Title, Meta Description, and URL Handle
 *  5. 20 Articles Per Page Pagination
 *  6. Single & Bulk Save Drafts / Push to Shopify API
 *  7. Uses REAL Shopify publish status (published_at → published / draft)
 *  8. Live View button next to the article title
 *  9. Defensive error handling – never dies with HTTP 500
 *
 * FIXES (2026-09):
 *  - Push no longer overwrites the real article title (only handle + global SEO metafields).
 *  - Metafield failures are reported with the real Shopify response body instead of forced HTTP 500.
 *  - Empty shopify_blog_id is handled with a clear message.
 *  - REST sync path now also reads global title_tag / description_tag metafields.
 *  - All API errors surface the response body for diagnosis.
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
    recordUserLog('Switch Store', 'Active Store', "Switched active store to '{$_GET['switch_store']}' from Blogs module.", 'system', null, 'success');
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
if (!function_exists('getShopifyAdminDomain')) {
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
}

/**
 * Map Shopify article publish state to portal status.
 */
if (!function_exists('mapArticleStatus')) {
    function mapArticleStatus(?string $publishedAt): string
    {
        return (!empty($publishedAt)) ? 'published' : 'draft';
    }
}

/**
 * Minimal cURL wrapper for the Shopify Admin API.
 * Always returns [httpCode, responseBody].
 */
if (!function_exists('shopifySeoApiRequest')) {
    function shopifySeoApiRequest(string $method, string $url, string $token, ?array $payload = null): array
    {
        $ch   = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                "X-Shopify-Access-Token: {$token}",
                "Content-Type: application/json"
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT        => 20,
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
        }
        curl_setopt_array($ch, $opts);
        $res  = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($res === false && $err) {
            return [$code ?: 0, json_encode(['error' => $err])];
        }
        return [$code, (string)$res];
    }
}

/**
 * Create or update the "global" title_tag / description_tag metafield.
 *
 * Shopify's top-level shorthand only writes the metafield the first time.
 * Once it exists, we must look it up by ID and PUT the update.
 */
if (!function_exists('upsertGlobalSeoMetafield')) {
    function upsertGlobalSeoMetafield(
        string $adminDomain,
        string $version,
        string $token,
        int $ownerId,
        string $key,
        string $type,
        string $value,
        string $ownerResource = 'article'
    ): array {
        $listUrl = "https://{$adminDomain}/admin/api/{$version}/metafields.json"
                 . "?metafield[owner_id]={$ownerId}&metafield[owner_resource]={$ownerResource}"
                 . "&namespace=global&key={$key}";
        [$code, $res] = shopifySeoApiRequest('GET', $listUrl, $token);

        $existingId = null;
        if ($code >= 200 && $code < 300) {
            $data = json_decode((string)$res, true);
            if (!empty($data['metafields'][0]['id'])) {
                $existingId = (int)$data['metafields'][0]['id'];
            }
        }

        if ($existingId) {
            $putUrl = "https://{$adminDomain}/admin/api/{$version}/metafields/{$existingId}.json";
            return shopifySeoApiRequest('PUT', $putUrl, $token, [
                'metafield' => [
                    'id'    => $existingId,
                    'value' => $value,
                    'type'  => $type
                ]
            ]);
        }

        $postUrl = "https://{$adminDomain}/admin/api/{$version}/metafields.json";
        return shopifySeoApiRequest('POST', $postUrl, $token, [
            'metafield' => [
                'namespace'      => 'global',
                'key'            => $key,
                'value'          => $value,
                'type'           => $type,
                'owner_id'       => $ownerId,
                'owner_resource' => $ownerResource
            ]
        ]);
    }
}

/**
 * Fetch the live global title_tag / description_tag metafields for an article.
 */
function fetchGlobalSeoMetafields(string $adminDomain, string $version, string $token, int $ownerId): array
{
    $listUrl = "https://{$adminDomain}/admin/api/{$version}/metafields.json"
             . "?metafield[owner_id]={$ownerId}&metafield[owner_resource]=article&namespace=global";
    [$code, $res] = shopifySeoApiRequest('GET', $listUrl, $token);

    $result = ['title_tag' => null, 'description_tag' => null];
    if ($code >= 200 && $code < 300) {
        $data = json_decode($res, true);
        foreach ($data['metafields'] ?? [] as $mf) {
            if (($mf['key'] ?? '') === 'title_tag') {
                $result['title_tag'] = $mf['value'] ?? null;
            } elseif (($mf['key'] ?? '') === 'description_tag') {
                $result['description_tag'] = $mf['value'] ?? null;
            }
        }
    }
    return $result;
}

/**
 * Truncate a string safely for display in error messages.
 */
function truncateForMessage(?string $text, int $max = 400): string
{
    $text = (string)$text;
    if (mb_strlen($text) <= $max) {
        return $text;
    }
    return mb_substr($text, 0, $max) . '…';
}

/**
 * Push a single article's SEO fields (handle, global title_tag/description_tag metafields)
 * to Shopify and persist the result locally. Used by both individual and bulk push.
 */
function pushArticleSeoToShopify(
    PDO $db,
    array $shopCfg,
    string $activeStore,
    array $art,
    string $currentUser,
    ?string $title = null,
    ?string $metaDescription = null,
    ?string $handle = null
): array {
    $blogId      = (int)$art['id'];
    $shopifyAid  = (int)($art['shopify_article_id'] ?? 0);
    $shopifyBid  = (int)($art['shopify_blog_id'] ?? 0);
    $adminDomain = getShopifyAdminDomain($shopCfg, $activeStore);
    $version     = !empty($shopCfg['version']) ? $shopCfg['version'] : '2025-10';
    $token       = $shopCfg['access_token'] ?? '';
    $finalTitle  = ($title !== null && $title !== '') ? $title : ($art['title'] ?? '');
    $finalMeta   = ($metaDescription !== null && $metaDescription !== '') ? $metaDescription : ($art['meta_description'] ?? '');
    $finalHandle = ($handle !== null && $handle !== '') ? $handle : ($art['handle'] ?? '');

    if (empty($token)) {
        return ['success' => false, 'http_code' => 0, 'error' => 'Missing access token for active store', 'title' => $finalTitle, 'id' => $blogId];
    }
    if ($shopifyBid <= 0) {
        return ['success' => false, 'http_code' => 0, 'error' => 'Missing shopify_blog_id (re-sync articles)', 'title' => $finalTitle, 'id' => $blogId];
    }
    if ($shopifyAid <= 0) {
        return ['success' => false, 'http_code' => 0, 'error' => 'Missing shopify_article_id (re-sync articles)', 'title' => $finalTitle, 'id' => $blogId];
    }

    // 1. Update only the handle on the article resource. Real article title stays intact.
    $putUrl = "https://{$adminDomain}/admin/api/{$version}/blogs/{$shopifyBid}/articles/{$shopifyAid}.json";
    $payload = [
        'article' => [
            'id'     => $shopifyAid,
            'handle' => $finalHandle,
        ]
    ];
    [$articleCode, $articleRes] = shopifySeoApiRequest('PUT', $putUrl, $token, $payload);

    // 2. Upsert the real SEO metafields (title_tag and description_tag)
    [$titleTagCode, $titleTagRes] = upsertGlobalSeoMetafield(
        $adminDomain, $version, $token, $shopifyAid,
        'title_tag', 'single_line_text_field', $finalTitle, 'article'
    );
    [$descTagCode, $descTagRes] = upsertGlobalSeoMetafield(
        $adminDomain, $version, $token, $shopifyAid,
        'description_tag', 'single_line_text_field', $finalMeta, 'article'
    );

    $articleOk  = ($articleCode >= 200 && $articleCode < 300);
    $titleTagOk = ($titleTagCode >= 200 && $titleTagCode < 300);
    $descTagOk  = ($descTagCode >= 200 && $descTagCode < 300);
    $success    = ($articleOk && $titleTagOk && $descTagOk);

    // Calculate SEO health score
    $score = (int)($art['seo_score'] ?? 85);
    if (function_exists('calculateSeoHealth')) {
        $health = calculateSeoHealth($finalTitle, $finalMeta, $finalHandle);
        $score = $health['score'] ?? $score;
    }

    // ONLY mark status as 'published' and set last_pushed_at if Shopify actually accepted the writes!
    // If it failed, keep the record as draft so users can still filter for outstanding drafts.
    if ($success) {
        $upStmt = $db->prepare("
            UPDATE shopify_blogs
            SET title = :title,
                meta_description = :meta_desc,
                handle = :handle,
                status = 'published',
                seo_score = :score,
                last_pushed_at = NOW(),
                updated_by = :user
            WHERE id = :id
        ");
        $upStmt->execute([
            ':title'     => $finalTitle,
            ':meta_desc' => $finalMeta,
            ':handle'    => $finalHandle,
            ':score'     => $score,
            ':user'      => $currentUser,
            ':id'        => $blogId
        ]);
    } else {
        $upStmt = $db->prepare("
            UPDATE shopify_blogs
            SET title = :title,
                meta_description = :meta_desc,
                handle = :handle,
                seo_score = :score,
                updated_by = :user
            WHERE id = :id
        ");
        $upStmt->execute([
            ':title'     => $finalTitle,
            ':meta_desc' => $finalMeta,
            ':handle'    => $finalHandle,
            ':score'     => $score,
            ':user'      => $currentUser,
            ':id'        => $blogId
        ]);
    }

    $errors = [];
    if (!$articleOk)  $errors[] = "Handle HTTP {$articleCode}: " . truncateForMessage($articleRes);
    if (!$titleTagOk) $errors[] = "title_tag HTTP {$titleTagCode}: " . truncateForMessage($titleTagRes);
    if (!$descTagOk)  $errors[] = "description_tag HTTP {$descTagCode}: " . truncateForMessage($descTagRes);

    return [
        'success'    => $success,
        'http_code'  => $articleCode,
        'title'      => $finalTitle,
        'id'         => $blogId,
        'shopify_id' => $shopifyAid,
        'errors'     => $errors
    ];
}

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
        // silent – table already exists or permissions issue
    }
}

// -----------------------------------------------------------------------------
// ACTION HANDLERS
// -----------------------------------------------------------------------------

// A. EXPORT / IMPORT ARTICLE SEO DATA
if (isset($_POST['action']) && $_POST['action'] === 'export_blogs') {
    if (!$db) {
        exit('Database connection unavailable.');
    }

    $exportStmt = $db->prepare('SELECT title, meta_description, handle FROM shopify_blogs WHERE store_key = :store ORDER BY id ASC');
    $exportStmt->execute([':store' => $activeStore]);

    $filename = 'uratex_blogs_' . $activeStore . '_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Article SEO Title', 'Meta Description', 'URL Handle']);

    while ($article = $exportStmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, $article);
    }

    fclose($output);
    exit;
}

if (isset($_POST['action']) && $_POST['action'] === 'import_blogs') {
    $importedCount = 0;
    $skippedCount  = 0;

    if (!$db || empty($_FILES['blogs_csv']['tmp_name']) || $_FILES['blogs_csv']['error'] !== UPLOAD_ERR_OK) {
        $message = 'ERROR: Please choose a valid article CSV file to import.';
    } else {
        $handle  = fopen($_FILES['blogs_csv']['tmp_name'], 'r');
        $headers = $handle ? fgetcsv($handle) : false;
        $headerMap = $headers ? array_flip(array_map('trim', $headers)) : [];
        $requiredColumns = ['Article SEO Title', 'Meta Description', 'URL Handle'];

        if (!$handle || !is_array($headers) || count(array_intersect($requiredColumns, array_keys($headerMap))) !== count($requiredColumns)) {
            $message = 'ERROR: The CSV must include only Article SEO Title, Meta Description, and URL Handle columns.';
            if ($handle) {
                fclose($handle);
            }
        } else {
            $updateStmt = $db->prepare("
                UPDATE shopify_blogs
                SET title = :title,
                    meta_description = :meta_description,
                    status = 'draft',
                    updated_by = :user
                WHERE store_key = :store AND handle = :handle
            ");

            try {
                $db->beginTransaction();
                while (($row = fgetcsv($handle)) !== false) {
                    $title           = trim($row[$headerMap['Article SEO Title']] ?? '');
                    $metaDescription = trim($row[$headerMap['Meta Description']] ?? '');
                    $urlHandle       = trim($row[$headerMap['URL Handle']] ?? '');

                    if ($title === '' || $urlHandle === '') {
                        $skippedCount++;
                        continue;
                    }

                    $updateStmt->execute([
                        ':title'            => $title,
                        ':meta_description' => $metaDescription,
                        ':store'            => $activeStore,
                        ':handle'           => $urlHandle,
                        ':user'             => $currentUser,
                    ]);
                    if ($updateStmt->rowCount() === 1) {
                        $importedCount++;
                    } else {
                        $skippedCount++;
                    }
                }
                $db->commit();
                fclose($handle);
                $message = "Imported <strong>{$importedCount}</strong> article(s) into the {$shopCfg['name']} database. No new articles were added.";
                if ($skippedCount > 0) {
                    $message .= " {$skippedCount} row(s) were skipped because required values were missing or no article matched the URL handle.";
                }
                recordUserLog('Article Import', 'Blogs & Articles', "Updated {$importedCount} existing article SEO row(s) for {$shopCfg['name']}; skipped {$skippedCount}; no records added.", 'article', null, 'success');
            } catch (Throwable $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                if ($handle) {
                    fclose($handle);
                }
                $message = 'ERROR: Article import failed. No database records were changed.';
            }
        }
    }
}

// B. TEST CONNECTION
if (isset($_POST['action']) && $_POST['action'] === 'test_connection') {
    try {
        $storesToTest = ['retail', 'business'];
        $results      = [];
        $allSuccess   = true;

        foreach ($storesToTest as $storeKey) {
            $storeCfg = $shopConfig[$storeKey] ?? [];
            $targetUrl = getShopifyAdminDomain($storeCfg, $storeKey);
            $version   = !empty($storeCfg['version']) ? $storeCfg['version'] : '2025-10';
            $token     = $storeCfg['access_token'] ?? '';

            $storeResults = [];
            $storeSuccess = true;

            if (empty($token)) {
                $storeResults[] = "❌ Access Token: MISSING - No access token found for {$storeKey} store";
                $storeSuccess = false;
                $storeResults[] = "❌ Pull Blogs: NOT TESTED - No access token";
                $storeResults[] = "❌ Push Blogs: NOT TESTED - No access token";
                $results[$storeKey] = implode('<br>', $storeResults);
                $allSuccess = false;
                continue;
            }

            if (empty($targetUrl)) {
                $storeResults[] = "❌ Store Configuration: MISSING - No URL configured for {$storeKey} store";
                $storeSuccess = false;
                $storeResults[] = "❌ Pull Blogs: NOT TESTED - No store URL";
                $storeResults[] = "❌ Push Blogs: NOT TESTED - No store URL";
                $results[$storeKey] = implode('<br>', $storeResults);
                $allSuccess = false;
                continue;
            }

            // Test 1: Basic connection
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

            $bodyStr = substr((string)$response, $headerSize);
            $json    = json_decode($bodyStr, true);

            if ($httpCode === 200 && !empty($json['shop'])) {
                $shopName   = $json['shop']['name'] ?? 'Unknown';
                $shopDomain = $json['shop']['myshopify_domain'] ?? $json['shop']['domain'] ?? $targetUrl;
                $storeResults[] = "✅ Basic Connection: SUCCESS - Store: <strong>{$shopName}</strong> ({$shopDomain}) | API Version: {$version}";
            } else {
                $errorDetails = $json['errors'] ?? substr($bodyStr, 0, 400);
                $errorMsg = "❌ Basic Connection: FAILED! HTTP {$httpCode}";
                if ($curlError) {
                    $errorMsg .= " | cURL: " . htmlspecialchars($curlError);
                }
                $errorMsg .= "<br><small>" . htmlspecialchars(is_string($errorDetails) ? $errorDetails : json_encode($errorDetails)) . "</small>";
                $storeResults[] = $errorMsg;
                $storeSuccess = false;
            }

            // Test 2: Pull blogs + articles
            $pullTestMessage = "❌ Pull Blogs: NOT TESTED";
            $blogsTestUrl = "https://{$targetUrl}/admin/api/{$version}/blogs.json?limit=1";
            $ch = curl_init($blogsTestUrl);
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

            $bodyStr   = substr((string)$response, $headerSize);
            $blogsJson = json_decode($bodyStr, true);
            $articlesJson = null;

            if ($httpCode === 200 && !empty($blogsJson['blogs']) && is_array($blogsJson['blogs'])) {
                $blogCount = count($blogsJson['blogs']);
                if ($blogCount > 0) {
                    $firstBlog = $blogsJson['blogs'][0];
                    $blogId    = $firstBlog['id'] ?? '';
                    if ($blogId) {
                        $articlesTestUrl = "https://{$targetUrl}/admin/api/{$version}/blogs/{$blogId}/articles.json?limit=1";
                        $ch = curl_init($articlesTestUrl);
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

                        $bodyStr      = substr((string)$response, $headerSize);
                        $articlesJson = json_decode($bodyStr, true);

                        if ($httpCode === 200 && !empty($articlesJson['articles']) && is_array($articlesJson['articles'])) {
                            $articleCount = count($articlesJson['articles']);
                            $pullTestMessage = "✅ Pull Blogs: SUCCESS - Retrieved {$blogCount} blog(s) and {$articleCount} article(s)";
                        } else {
                            $errorDetails = $articlesJson['errors'] ?? substr($bodyStr, 0, 400);
                            $pullTestMessage = "⚠️ Pull Blogs: PARTIAL - Retrieved {$blogCount} blog(s) but failed to get articles";
                            if ($curlError) {
                                $pullTestMessage .= " | cURL: " . htmlspecialchars($curlError);
                            }
                            if (!empty($errorDetails)) {
                                $pullTestMessage .= "<br><small>" . htmlspecialchars(is_string($errorDetails) ? $errorDetails : json_encode($errorDetails)) . "</small>";
                            }
                        }
                    } else {
                        $pullTestMessage = "✅ Pull Blogs: SUCCESS - Retrieved {$blogCount} blog(s) (no articles tested - no valid blog ID)";
                    }
                } else {
                    $pullTestMessage = "✅ Pull Blogs: SUCCESS - No blogs found (empty store)";
                }
            } else {
                $errorDetails = $blogsJson['errors'] ?? substr($bodyStr, 0, 400);
                $pullTestMessage = "❌ Pull Blogs: FAILED! HTTP {$httpCode}";
                if ($curlError) {
                    $pullTestMessage .= " | cURL: " . htmlspecialchars($curlError);
                }
                if (!empty($errorDetails)) {
                    $pullTestMessage .= "<br><small>" . htmlspecialchars(is_string($errorDetails) ? $errorDetails : json_encode($errorDetails)) . "</small>";
                }
                $storeSuccess = false;
            }
            $storeResults[] = $pullTestMessage;

            // Test 3: Push capability (no-op PUT)
            $pushTestMessage = "❌ Push Blogs: NOT TESTED - No articles available";
            if (isset($blogsJson['blogs']) && is_array($blogsJson['blogs']) && count($blogsJson['blogs']) > 0) {
                $firstBlog = $blogsJson['blogs'][0];
                $blogId    = $firstBlog['id'] ?? '';
                if ($blogId && isset($articlesJson['articles']) && is_array($articlesJson['articles']) && count($articlesJson['articles']) > 0) {
                    $testArticle = $articlesJson['articles'][0];
                    $articleId   = $testArticle['id'] ?? '';
                    if ($articleId) {
                        $pushTestUrl = "https://{$targetUrl}/admin/api/{$version}/blogs/{$blogId}/articles/{$articleId}.json";
                        $pushPayload = json_encode([
                            "article" => [
                                "id"     => $articleId,
                                "handle" => $testArticle['handle'] ?? ''
                            ]
                        ]);
                        $ch = curl_init($pushTestUrl);
                        curl_setopt_array($ch, [
                            CURLOPT_CUSTOMREQUEST  => "PUT",
                            CURLOPT_POSTFIELDS     => $pushPayload,
                            CURLOPT_HTTPHEADER     => [
                                "X-Shopify-Access-Token: {$token}",
                                "Content-Type: application/json"
                            ],
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_TIMEOUT        => 15,
                        ]);
                        $response  = curl_exec($ch);
                        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        $curlError = curl_error($ch);
                        curl_close($ch);

                        $pushJson = json_decode((string)$response, true);
                        if ($httpCode >= 200 && $httpCode < 300) {
                            $pushTestMessage = "✅ Push Blogs: SUCCESS - Can update articles (tested on article ID: {$articleId})";
                        } else {
                            $errorDetails = $pushJson['errors'] ?? $response;
                            $pushTestMessage = "❌ Push Blogs: FAILED! HTTP {$httpCode}";
                            if ($curlError) {
                                $pushTestMessage .= " | cURL: " . htmlspecialchars($curlError);
                            }
                            if (!empty($errorDetails)) {
                                $pushTestMessage .= "<br><small>" . htmlspecialchars(is_string($errorDetails) ? $errorDetails : json_encode($errorDetails)) . "</small>";
                            }
                            $storeSuccess = false;
                        }
                    }
                } else {
                    if ($blogId) {
                        $countUrl = "https://{$targetUrl}/admin/api/{$version}/blogs/{$blogId}/articles/count.json";
                        $ch = curl_init($countUrl);
                        curl_setopt_array($ch, [
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_HTTPHEADER     => [
                                "X-Shopify-Access-Token: {$token}",
                                "Content-Type: application/json"
                            ],
                            CURLOPT_SSL_VERIFYPEER => false,
                            CURLOPT_TIMEOUT        => 15,
                        ]);
                        $response = curl_exec($ch);
                        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);
                        if ($httpCode === 200) {
                            $pushTestMessage = "✅ Push Blogs: SUCCESS - Can access articles endpoint";
                        } else {
                            $pushTestMessage = "❌ Push Blogs: Unable to verify - No articles found to test with";
                        }
                    }
                }
            } else {
                $countUrl = "https://{$targetUrl}/admin/api/{$version}/blogs/count.json";
                $ch = curl_init($countUrl);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => [
                        "X-Shopify-Access-Token: {$token}",
                        "Content-Type: application/json"
                    ],
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT        => 15,
                ]);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($httpCode === 200) {
                    $pushTestMessage = "✅ Push Blogs: SUCCESS - Can access blogs endpoint";
                } else {
                    $pushTestMessage = "❌ Push Blogs: Unable to verify - No blogs found to test with";
                }
            }
            $storeResults[] = $pushTestMessage;

            $results[$storeKey] = implode('<br>', $storeResults);
            if (!$storeSuccess) {
                $allSuccess = false;
            }
        }

        $message = implode('<br><br>', $results);
        if ($allSuccess) {
            $message = "✅ All connections and API capabilities verified successfully!<br><br>" . $message;
        } else {
            $message = "⚠️ Some API capabilities failed!<br><br>" . $message;
        }
        recordUserLog('Test Connection', 'Blogs API', "Tested Shopify API connection (blogs & articles) for retail + business stores — " . ($allSuccess ? 'all checks passed.' : 'some checks failed.'), 'article', null, $allSuccess ? 'success' : 'error');
    } catch (Throwable $e) {
        $message = "ERROR: Test connection failed – " . htmlspecialchars($e->getMessage());
    }
}

// C. SYNC BLOG ARTICLES FROM SHOPIFY (GraphQL + REST fallback)
if (isset($_POST['action']) && $_POST['action'] === 'sync_blogs') {
    @set_time_limit(300);
    @ini_set('max_execution_time', '300');
    @ini_set('memory_limit', '512M');

    try {
        $syncedCount      = 0;
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

        recordUserLog('sync_started', 'blogs', "Starting blogs sync for store: {$activeStore}", 'article', null, 'info');

        $insertStmt = null;
        if ($db) {
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
        }

        $gqlSuccess = false;

        // 1. High-Performance GraphQL Sync
        foreach ($domainsToTry as $domain) {
            $gqlUrl   = "https://{$domain}/admin/api/{$version}/graphql.json";
            $gqlQuery = <<<'GQL'
query getBlogsAndArticles {
  blogs(first: 50) {
    nodes {
      id
      legacyResourceId
      title
      handle
      articles(first: 250) {
        nodes {
          id
          legacyResourceId
          title
          handle
          bodyHtml
          summary
          tags
          publishedAt
          author {
            name
          }
          seo {
            title
            description
          }
          titleTag: metafield(namespace: "global", key: "title_tag") {
            value
          }
          descTag: metafield(namespace: "global", key: "description_tag") {
            value
          }
        }
      }
    }
  }
}
GQL;

            $payload = json_encode(['query' => $gqlQuery]);
            $ch = curl_init($gqlUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $payload,
                CURLOPT_HTTPHEADER     => [
                    "X-Shopify-Access-Token: {$token}",
                    "Content-Type: application/json",
                    "Accept: application/json"
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT        => 45,
            ]);
            $response  = curl_exec($ch);
            $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200 && $response) {
                $json      = json_decode($response, true);
                $blogNodes = $json['data']['blogs']['nodes'] ?? null;

                if (!empty($blogNodes) && is_array($blogNodes) && $db && $insertStmt) {
                    try {
                        $db->beginTransaction();
                        foreach ($blogNodes as $bNode) {
                            $rawBid     = (string)($bNode['legacyResourceId'] ?? $bNode['id'] ?? '');
                            $blogId     = (int)preg_replace('/[^0-9]/', '', $rawBid);
                            $blogTitle  = $bNode['title'] ?? 'News & Guides';
                            $blogHandle = $bNode['handle'] ?? 'news';

                            $artNodes = $bNode['articles']['nodes'] ?? [];
                            foreach ($artNodes as $aNode) {
                                $rawAid = (string)($aNode['legacyResourceId'] ?? $aNode['id'] ?? '');
                                $aid    = (int)preg_replace('/[^0-9]/', '', $rawAid);
                                if (!$aid) {
                                    continue;
                                }

                                $aname  = $aNode['title'] ?? 'Untitled Article';
                                $handle = $aNode['handle'] ?? '';
                                $author = !empty($aNode['author']['name']) ? $aNode['author']['name'] : 'Uratex Editorial';

                                $artUrl = "https://" . (!empty($shopCfg['domain']) ? $shopCfg['domain'] : $domain)
                                        . "/blogs/" . $blogHandle . "/" . $handle;

                                $bodyClean    = strip_tags($aNode['bodyHtml'] ?? '');
                                $fallbackMeta = mb_substr($bodyClean, 0, 160);
                                if (empty($fallbackMeta) && !empty($aNode['summary'])) {
                                    $fallbackMeta = mb_substr(strip_tags($aNode['summary']), 0, 160);
                                }
                                if (empty($fallbackMeta)) {
                                    $fallbackMeta = "Read more about {$aname} on the Uratex blog.";
                                }

                                // Prefer live global metafields → SEO object → fallback
                                $title    = !empty($aNode['titleTag']['value'])
                                    ? $aNode['titleTag']['value']
                                    : (!empty($aNode['seo']['title']) ? $aNode['seo']['title'] : $aname);
                                $metaDesc = !empty($aNode['descTag']['value'])
                                    ? $aNode['descTag']['value']
                                    : (!empty($aNode['seo']['description']) ? $aNode['seo']['description'] : $fallbackMeta);

                                $tags     = is_array($aNode['tags'] ?? null) ? implode(',', $aNode['tags']) : (string)($aNode['tags'] ?? '');
                                $category = !empty($tags) ? explode(',', $tags)[0] : 'Sleep Science';
                                $category = trim($category) ?: 'Sleep Science';

                                $score = 85;
                                if (function_exists('calculateSeoHealth')) {
                                    $seoAnalysis = calculateSeoHealth($title, $metaDesc, $handle);
                                    $score       = $seoAnalysis['score'];
                                }

                                $publishedAt = $aNode['publishedAt'] ?? null;
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
                        }
                        $db->commit();
                        $gqlSuccess       = true;
                        $successfulDomain = $domain;
                        break;
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) {
                            $db->rollBack();
                        }
                        $apiError = "Database error during GraphQL blog sync: " . $e->getMessage();
                    }
                } else {
                    if (!empty($json['errors'])) {
                        $apiError = "GraphQL Error: " . json_encode($json['errors']);
                    }
                }
            } else {
                $apiError = "HTTP {$httpCode} on GraphQL {$domain}";
                if ($curlError) {
                    $apiError .= " | cURL: {$curlError}";
                }
            }
        }

        // 2. Fallback to REST API if GraphQL returned no blogs
        if (!$gqlSuccess) {
            $allArticles = [];
            $headers = [
                "X-Shopify-Access-Token: {$token}",
                "Content-Type: application/json"
            ];

            foreach ($domainsToTry as $domain) {
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
                    if ($curlError) {
                        $apiError .= " | cURL: {$curlError}";
                    }
                    if ($bodyStr) {
                        $apiError .= " | " . substr($bodyStr, 0, 300);
                    }
                    continue;
                }

                $bodyStr = substr($response, $headerSize);
                $json    = json_decode($bodyStr, true);
                $blogs   = $json['blogs'] ?? [];

                if (empty($blogs)) {
                    $apiError = "No blogs found on {$domain}";
                    continue;
                }

                $tempArticles = [];

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
                            if ($curlError) {
                                $apiError .= " | cURL: {$curlError}";
                            }
                            break;
                        }
                    }
                }

                if (!empty($tempArticles)) {
                    $allArticles      = $tempArticles;
                    $successfulDomain = $domain;
                    break;
                }
            }

            if ($db && !empty($allArticles) && $insertStmt) {
                try {
                    $db->beginTransaction();
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

                        $bodyClean    = strip_tags($a['body_html'] ?? $a['summary_html'] ?? '');
                        $fallbackMeta = mb_substr($bodyClean, 0, 160);
                        if (empty($fallbackMeta) && !empty($a['summary'])) {
                            $fallbackMeta = mb_substr(strip_tags($a['summary']), 0, 160);
                        }
                        if (empty($fallbackMeta)) {
                            $fallbackMeta = "Read more about {$aname} on the Uratex blog.";
                        }

                        // REST path: also fetch live global SEO metafields so portal matches reality
                        $liveSeo  = fetchGlobalSeoMetafields($successfulDomain, $version, $token, (int)$aid);
                        $title    = !empty($liveSeo['title_tag'])
                            ? $liveSeo['title_tag']
                            : ($a['title'] ?? $aname);
                        $metaDesc = !empty($liveSeo['description_tag'])
                            ? $liveSeo['description_tag']
                            : $fallbackMeta;

                        $tags     = $a['tags'] ?? '';
                        $category = !empty($tags) ? explode(',', $tags)[0] : 'Sleep Science';
                        $category = trim($category) ?: 'Sleep Science';

                        $score = 85;
                        if (function_exists('calculateSeoHealth')) {
                            $seoAnalysis = calculateSeoHealth($title, $metaDesc, $handle);
                            $score       = $seoAnalysis['score'];
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
                    $db->commit();
                } catch (Throwable $e) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    $apiError = "Database error during REST blog sync: " . $e->getMessage();
                }
            }
        }

        if ($syncedCount > 0) {
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

// D. SAVE DRAFT
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
            recordUserLog('Draft Saved', $title, "Saved SEO draft for article #{$blogId} (store: {$activeStore}). Title, meta description and handle updated locally.", 'article', $blogId, 'success');
        }
    } catch (Throwable $e) {
        $message = "ERROR: Save draft failed – " . htmlspecialchars($e->getMessage());
    }
}

// E. PUSH TO SHOPIFY (Writes handle + SEO metafields; ties published status ONLY to actual success)
if (isset($_POST['action']) && $_POST['action'] === 'push_shopify') {
    try {
        $blogId          = (int)($_POST['blog_id'] ?? 0);
        $title           = trim($_POST['title'] ?? '');
        $metaDescription = trim($_POST['meta_description'] ?? '');
        $handle          = trim($_POST['handle'] ?? '');

        if (!$blogId || !$db) {
            $message = 'ERROR: Invalid article or database unavailable.';
        } else {
            $stmt = $db->prepare("SELECT * FROM shopify_blogs WHERE id = :id AND store_key = :store LIMIT 1");
            $stmt->execute([':id' => $blogId, ':store' => $activeStore]);
            $art = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$art) {
                $message = "ERROR: Article #{$blogId} not found for the active store.";
            } else {
                $result = pushArticleSeoToShopify($db, $shopCfg, $activeStore, $art, $currentUser, $title, $metaDescription, $handle);

                if ($result['success']) {
                    $message = "✅ Live SEO update pushed to Shopify store ({$shopCfg['name']}) successfully!<br>"
                             . "Handle + global title_tag + description_tag updated for article #{$blogId}.";
                    recordUserLog(
                        'Shopify Push',
                        $result['title'],
                        "Pushed article #{$blogId} live to {$shopCfg['name']} (Shopify article ID: {$art['shopify_article_id']}). Handle and SEO metafields updated. Real article title left unchanged.",
                        'article',
                        $blogId,
                        'success'
                    );
                } else {
                    $errDetails = !empty($result['errors']) ? implode('<br>', $result['errors']) : ($result['error'] ?? "HTTP {$result['http_code']}");
                    $message = "⚠️ Shopify push failed for article #{$blogId}: {$errDetails}<br>"
                             . "Local draft kept as draft so you can retry or verify Shopify settings.";
                    recordUserLog(
                        'Shopify Push Partial/Failed',
                        $result['title'],
                        "Failed push of article #{$blogId} to {$shopCfg['name']}. Details: {$errDetails}",
                        'article',
                        $blogId,
                        'error'
                    );
                }
            }
        }
    } catch (Throwable $e) {
        $message = "ERROR: Push failed – " . htmlspecialchars($e->getMessage());
        recordUserLog('Shopify Push Exception', 'Blogs', $message, 'article', $blogId ?? null, 'error');
    }
}

// F. BULK APPROVE & PUSH TO SHOPIFY API (Calls live Shopify API, writes handle + SEO metafields, updates status ONLY on success)
if (isset($_POST['action']) && $_POST['action'] === 'bulk_push') {
    @set_time_limit(600);
    @ini_set('max_execution_time', '600');

    try {
        if ($db) {
            $draftStmt = $db->prepare("SELECT * FROM shopify_blogs WHERE store_key = :store AND status = 'draft'");
            $draftStmt->execute([':store' => $activeStore]);
            $drafts = $draftStmt->fetchAll(PDO::FETCH_ASSOC);

            $successCount = 0;
            $failures     = [];

            foreach ($drafts as $draftArt) {
                $result = pushArticleSeoToShopify($db, $shopCfg, $activeStore, $draftArt, $currentUser);
                if ($result['success']) {
                    $successCount++;
                } else {
                    $errStr = !empty($result['errors']) ? implode(', ', $result['errors']) : ($result['error'] ?? "HTTP {$result['http_code']}");
                    $failures[] = ($draftArt['handle'] ?: ($draftArt['title'] ?: "Article #{$draftArt['id']}")) . " ({$errStr})";
                }
                // Small throttle to stay well within Shopify REST API rate limits
                usleep(50000);
            }

            $failCount = count($failures);
            $total     = count($drafts);

            if ($total === 0) {
                $message = "No draft articles to push for {$shopCfg['name']}.";
            } elseif ($failCount === 0) {
                $message = "✅ Bulk push complete: <strong>{$successCount}</strong> of <strong>{$total}</strong> draft articles pushed live to Shopify ({$shopCfg['name']}) successfully. Handle + global title_tag + description_tag updated.";
            } else {
                $failList = htmlspecialchars(implode('; ', array_slice($failures, 0, 5)));
                $more     = $failCount > 5 ? ' and ' . ($failCount - 5) . ' more' : '';
                $message  = "⚠️ Bulk push finished with errors: <strong>{$successCount}</strong> succeeded, <strong>{$failCount}</strong> failed out of {$total}. Failed articles remain as drafts for review: {$failList}{$more}.";
            }

            recordUserLog(
                'Bulk Push to Shopify',
                'Blogs & Articles',
                "Bulk pushed {$successCount}/{$total} draft articles to {$shopCfg['name']} live API. Failures: {$failCount}.",
                'article',
                null,
                $failCount === 0 ? 'success' : 'warning'
            );
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

if ($db) {
    try {
        $countStmt = $db->prepare("SELECT COUNT(*) FROM shopify_blogs WHERE {$whereSql}");
        $countStmt->execute($params);
        $totalBlogsCount = (int)$countStmt->fetchColumn();

        $dStmt = $db->prepare("SELECT COUNT(*) FROM shopify_blogs WHERE store_key = :store AND status = 'draft'");
        $dStmt->execute([':store' => $activeStore]);
        $draftCount = (int)$dStmt->fetchColumn();
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
          <div class="mt-2">
            <a href="content.php" class="btn btn-sm btn-success font-weight-bold shadow-sm">
              <i class="fas fa-feather-alt mr-1"></i> Write New Article
            </a>
          </div>
        </div>

        <div class="col-sm-6">
          <div class="row justify-content-end">
            <div class="col-md-4 mb-2 mb-md-0">
              <div class="card h-100 mb-0 shadow-sm border-0" style="border-top: 4px solid #007bff !important; border-radius: 8px;">
                <div class="card-body p-2">
                  <div class="small font-weight-bold text-dark"><i class="fas fa-plug text-primary mr-1"></i>Test Connection</div>
                  <div class="small text-muted mb-2">Verify Shopify API access.</div>
                  <form method="POST">
                    <input type="hidden" name="action" value="test_connection">
                    <button type="submit" class="btn btn-sm btn-primary btn-block font-weight-bold">Run Test</button>
                  </form>
                </div>
              </div>
            </div>
            <div class="col-md-4 mb-2 mb-md-0">
              <div class="card h-100 mb-0 shadow-sm border-0" style="border-top: 4px solid #eab308 !important; border-radius: 8px;">
                <div class="card-body p-2">
                  <div class="small font-weight-bold text-dark"><i class="fas fa-sync-alt text-warning mr-1"></i>Sync Blogs</div>
                  <div class="small text-muted mb-2">Refresh the local articles.</div>
                  <form method="POST" id="syncForm">
                    <input type="hidden" name="action" value="sync_blogs">
                    <button type="submit" id="btnSyncBlogs" class="btn btn-sm btn-warning btn-block font-weight-bold"><i class="fas fa-sync-alt mr-1" id="syncIcon"></i>Sync Now</button>
                  </form>
                </div>
              </div>
            </div>
            <div class="col-md-4">
              <div class="card h-100 mb-0 shadow-sm border-0" style="border-top: 4px solid #16a34a !important; border-radius: 8px;">
                <div class="card-body p-2">
                  <div class="small font-weight-bold text-dark"><i class="fas fa-layer-group text-success mr-1"></i>Bulk Push</div>
                  <div class="small text-muted mb-2">Export, import, or publish.</div>
                  <div class="d-flex flex-wrap">
                    <form method="POST" class="mr-1 mb-1">
                      <input type="hidden" name="action" value="export_blogs">
                      <button type="submit" class="btn btn-sm btn-outline-secondary font-weight-bold" title="Export article SEO data"><i class="fas fa-file-export mr-1"></i>Export</button>
                    </form>
                    <form method="POST" enctype="multipart/form-data" class="mr-1 mb-1">
                      <input type="hidden" name="action" value="import_blogs">
                      <label class="btn btn-sm btn-outline-secondary font-weight-bold mb-0" title="Import article SEO data"><i class="fas fa-file-import mr-1"></i>Import<input type="file" name="blogs_csv" accept=".csv,text/csv" class="d-none" onchange="this.form.submit()"></label>
                    </form>
                    <form method="POST" class="mb-1">
                      <input type="hidden" name="action" value="bulk_push">
                      <button type="submit" class="btn btn-sm btn-success font-weight-bold" <?php echo $draftCount === 0 ? 'disabled' : ''; ?> title="Bulk push drafts live to Shopify API (writes handle & SEO metafields)"><i class="fas fa-check-double mr-1"></i>Bulk Push (<?php echo $draftCount; ?>)</button>
                    </form>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <section class="content">
    <div class="container-fluid">

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
                    <a href="content.php?edit_id=<?php echo $blogId; ?>"
                       class="btn btn-sm btn-outline-primary shadow-sm flex-shrink-0 ml-1"
                       title="Write / Edit Article Content"
                       style="padding: 0.2rem 0.5rem; font-size: 0.75rem;">
                      <i class="fas fa-edit"></i> <span class="d-none d-md-inline ml-1">Edit</span>
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
                             data-char-counter="t-count-<?php echo $blogId; ?>"
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
                                data-char-counter="m-count-<?php echo $blogId; ?>"><?php echo htmlspecialchars($artMeta); ?></textarea>
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
                              onclick="return confirm('Push this article\'s SEO (handle + title_tag + description_tag) live to Shopify?\n\nThe real article title will NOT be changed.');">
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
