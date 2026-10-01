<?php
/**
 * Content Writer & Article Publisher (content.php) - Uratex Shopify Partner Portal
 *
 * Features:
 *  1. Article Composition & Rich Text Editor (Shopify Blog Article Workflow)
 *     - Font formatting: Family, Size, Style (Bold, Italic, Underline, Strikethrough, Colors)
 *     - Formatting: Headings (H1-H6), Paragraphs, Lists, Blockquotes, Tables, Code
 *     - Media: Drag & drop image upload, image URLs, alt text, responsive alignment
 *  2. Organization & Details:
 *     - Select existing Shopify Blog Category or create a brand new blog category on-the-fly
 *     - Author attribution (defaults to active agent/specialist, customizable)
 *     - Article tags (comma-separated with one-click suggestions)
 *     - Featured Image with live preview (file upload or web URL with alt text)
 *     - Article Excerpt / Summary HTML
 *  3. Search Engine Listing (Shopify SEO Standard):
 *     - Live Google SERP Snippet Preview (Desktop & Mobile toggle)
 *     - Custom Page Title (with recommended 60-character counter)
 *     - Custom Meta Description (with recommended 160-character counter)
 *     - URL Handle / Slug (auto-slugified from title with manual override)
 *     - Auto-fill SEO from Content helper
 *  4. Direct Shopify Admin API Publishing:
 *     - "Publish Article" (pushes to Shopify REST API as published + syncs global SEO metafields)
 *     - "Save as Draft" (saves to Shopify as unpublished/draft + persists in local database)
 *     - Synchronizes immediately with MySQL `shopify_blogs` table for the Blogs SEO module
 *     - Comprehensive audit logging via `recordUserLog()`
 */

require_once __DIR__ . '/../config/config.php';

// Auth Guard
if (!isset($_SESSION['user_logged_in']) || $_SESSION['user_logged_in'] !== true) {
    header("Location: ../login.php");
    exit;
}

// Active Store Handling
if (isset($_GET['store']) && in_array($_GET['store'], ['retail', 'business'])) {
    $_SESSION['active_store'] = $_GET['store'];
} elseif (isset($_GET['switch_store']) && in_array($_GET['switch_store'], ['retail', 'business'])) {
    $_SESSION['active_store'] = $_GET['switch_store'];
    recordUserLog('Switch Store', 'Active Store', "Switched active store to '{$_GET['switch_store']}' from Content Creator.", 'system', null, 'success');
}

$db          = getDbConnection();
$activeStore = $_SESSION['active_store'] ?? 'business';
$currentUser = $_SESSION['user_name'] ?? 'Jenor Ricafort';
$userEmail   = $_SESSION['user_email'] ?? 'jenor.ricafort@uratex.com.ph';
$userRole    = $_SESSION['user_role'] ?? 'admin';
$shopCfg     = $shopConfig[$activeStore] ?? $shopConfig['business'];

$message     = '';
$messageType = 'success';
$publishedArticleLink = '';

// Helper: Admin Domain
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

// Helper: Shopify REST Admin API Request
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
            CURLOPT_TIMEOUT        => 25,
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

// Helper: Upsert Global SEO Metafield (title_tag / description_tag)
if (!function_exists('upsertGlobalSeoMetafield')) {
    function upsertGlobalSeoMetafield(
        string $adminDomain,
        string $version,
        string $token,
        int $ownerId,
        string $key,
        string $type,
        string $value
    ): array {
        $listUrl = "https://{$adminDomain}/admin/api/{$version}/metafields.json"
                 . "?metafield[owner_id]={$ownerId}&metafield[owner_resource]=article"
                 . "&namespace=global&key={$key}";
        [$code, $res] = shopifySeoApiRequest('GET', $listUrl, $token);

        $existingId = null;
        if ($code >= 200 && $code < 300) {
            $data = json_decode($res, true);
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
                'owner_resource' => 'article'
            ]
        ]);
    }
}

// Helper: Slugify title
function slugifyText(string $text): string
{
    $slug = preg_replace('~[^\pL\d]+~u', '-', $text);
    $slug = iconv('utf-8', 'us-ascii//TRANSLIT', $slug);
    $slug = preg_replace('~[^-\w]+~', '', $slug);
    $slug = trim($slug, '-');
    $slug = preg_replace('~-+~', '-', $slug);
    $slug = strtolower($slug);
    return empty($slug) ? 'article-' . time() : $slug;
}

// Ensure database schema supports extended content fields
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
                `body_html` MEDIUMTEXT NULL,
                `summary_html` TEXT NULL,
                `image_url` VARCHAR(1000) NULL,
                `tags` VARCHAR(500) NULL,
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

        $colsNeeded = [
            'shopify_blog_id' => 'BIGINT UNSIGNED NULL DEFAULT NULL AFTER `shopify_article_id`',
            'blog_handle'     => 'VARCHAR(255) NULL DEFAULT \'news\' AFTER `blog_title`',
            'body_html'       => 'MEDIUMTEXT NULL AFTER `meta_description`',
            'summary_html'    => 'TEXT NULL AFTER `body_html`',
            'image_url'       => 'VARCHAR(1000) NULL AFTER `summary_html`',
            'tags'            => 'VARCHAR(500) NULL AFTER `image_url`',
        ];

        foreach ($colsNeeded as $col => $definition) {
            $chk = $db->query("SHOW COLUMNS FROM `shopify_blogs` LIKE '{$col}'")->fetchAll();
            if (empty($chk)) {
                $db->exec("ALTER TABLE `shopify_blogs` ADD COLUMN `{$col}` {$definition}");
            }
        }
    } catch (Exception $e) {
        // Continue gracefully
    }
}

// -----------------------------------------------------------------------------
// AJAX IMAGE UPLOAD HANDLER (for Summernote inline images)
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'ajax_upload_image') {
    header('Content-Type: application/json');
    if (!isset($_FILES['inline_image']) || $_FILES['inline_image']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['error' => 'No image uploaded or upload error occurred.']);
        exit;
    }

    $file = $_FILES['inline_image'];
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed)) {
        echo json_encode(['error' => 'Invalid image format. Allowed: JPG, PNG, GIF, WEBP, SVG.']);
        exit;
    }

    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    if (empty($ext)) {
        $ext = ($mime === 'image/png') ? 'png' : 'jpg';
    }

    $uploadDir = __DIR__ . '/../uploads/articles';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }

    $filename = 'art_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($ext);
    $targetPath = $uploadDir . '/' . $filename;

    if (@move_uploaded_file($file['tmp_name'], $targetPath)) {
        $relativeUrl = '../uploads/articles/' . $filename;
        echo json_encode(['url' => $relativeUrl, 'filename' => $filename]);
    } else {
        // Fallback: Return base64 data URI if file cannot be moved
        $b64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($file['tmp_name']));
        echo json_encode(['url' => $b64, 'fallback' => true]);
    }
    exit;
}

// -----------------------------------------------------------------------------
// FETCH BLOG CATEGORIES (from Shopify Admin REST API & MySQL fallback)
// -----------------------------------------------------------------------------
$availableBlogs = [];
$adminDomain = getShopifyAdminDomain($shopCfg, $activeStore);
$version     = !empty($shopCfg['version']) ? $shopCfg['version'] : '2025-10';
$token       = !empty($shopCfg['access_token']) ? $shopCfg['access_token'] : '';

// 1. Fetch live blogs from Shopify REST API
if (!empty($token)) {
    $blogsUrl = "https://{$adminDomain}/admin/api/{$version}/blogs.json?limit=250";
    [$bCode, $bBody] = shopifySeoApiRequest('GET', $blogsUrl, $token);
    if ($bCode >= 200 && $bCode < 300) {
        $bData = json_decode($bBody, true);
        if (!empty($bData['blogs']) && is_array($bData['blogs'])) {
            foreach ($bData['blogs'] as $b) {
                $availableBlogs[$b['id']] = [
                    'id'     => $b['id'],
                    'title'  => $b['title'],
                    'handle' => $b['handle'] ?? 'news'
                ];
            }
        }
    }
}

// 2. Fetch distinct blogs from database cache if any
if ($db) {
    try {
        $bStmt = $db->prepare("
            SELECT DISTINCT shopify_blog_id, blog_title, blog_handle 
            FROM shopify_blogs 
            WHERE store_key = :store AND shopify_blog_id IS NOT NULL AND shopify_blog_id > 0
        ");
        $bStmt->execute([':store' => $activeStore]);
        $cachedBlogs = $bStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($cachedBlogs as $cb) {
            $cbId = (int)$cb['shopify_blog_id'];
            if (!isset($availableBlogs[$cbId])) {
                $availableBlogs[$cbId] = [
                    'id'     => $cbId,
                    'title'  => $cb['blog_title'] ?: 'News',
                    'handle' => $cb['blog_handle'] ?: 'news'
                ];
            }
        }
    } catch (Exception $e) {
        // silent
    }
}

// 3. Fallback defaults if no blogs found
if (empty($availableBlogs)) {
    $availableBlogs[1] = [
        'id'     => 1,
        'title'  => 'News',
        'handle' => 'news'
    ];
    $availableBlogs[2] = [
        'id'     => 2,
        'title'  => 'Sleep Science & Guides',
        'handle' => 'sleep-science'
    ];
    $availableBlogs[3] = [
        'id'     => 3,
        'title'  => 'Press & Stories',
        'handle' => 'press'
    ];
}

// -----------------------------------------------------------------------------
// LOAD EXISTING ARTICLE IF EDITING (?edit_id= OR ?article_id=)
// -----------------------------------------------------------------------------
$editingArticle = null;
$editId = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
$editArticleId = isset($_GET['article_id']) ? (int)$_GET['article_id'] : 0;

if ($db && ($editId > 0 || $editArticleId > 0)) {
    try {
        if ($editId > 0) {
            $stmt = $db->prepare("SELECT * FROM shopify_blogs WHERE id = :id AND store_key = :store LIMIT 1");
            $stmt->execute([':id' => $editId, ':store' => $activeStore]);
        } else {
            $stmt = $db->prepare("SELECT * FROM shopify_blogs WHERE shopify_article_id = :aid AND store_key = :store LIMIT 1");
            $stmt->execute([':aid' => $editArticleId, ':store' => $activeStore]);
        }
        $editingArticle = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $editingArticle = null;
    }
}

// -----------------------------------------------------------------------------
// POST HANDLER: SAVE DRAFT OR PUBLISH ARTICLE
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'publish_article') {
    $articleTitle      = trim($_POST['article_title'] ?? '');
    $authorName        = trim($_POST['author'] ?? $currentUser);
    $bodyHtml          = trim($_POST['body_html'] ?? '');
    $summaryHtml       = trim($_POST['summary_html'] ?? '');
    $blogSelection     = trim($_POST['blog_id'] ?? '');
    $newBlogTitle      = trim($_POST['new_blog_title'] ?? '');
    $articleTags       = trim($_POST['tags'] ?? '');
    $seoTitle          = trim($_POST['seo_title'] ?? '');
    $seoDescription    = trim($_POST['seo_description'] ?? '');
    $articleHandle     = trim($_POST['handle'] ?? '');
    $submitAction      = $_POST['submit_action'] ?? 'publish'; // 'publish' or 'draft'
    $visibility        = $_POST['visibility'] ?? ($submitAction === 'publish' ? 'visible' : 'hidden');
    $featuredImageUrl  = trim($_POST['featured_image_url'] ?? '');
    $featuredImageAlt  = trim($_POST['featured_image_alt'] ?? '');
    $existingShopifyId = !empty($_POST['shopify_article_id']) ? (int)$_POST['shopify_article_id'] : ($editingArticle['shopify_article_id'] ?? 0);
    $existingLocalId   = !empty($_POST['local_db_id']) ? (int)$_POST['local_db_id'] : ($editingArticle['id'] ?? 0);

    if (empty($articleTitle)) {
        $message = '❌ Please enter an Article Title before saving.';
        $messageType = 'danger';
    } else {
        // Resolve Blog Category & Handle
        $targetBlogId     = 0;
        $targetBlogTitle  = 'News';
        $targetBlogHandle = 'news';

        if ($blogSelection === 'create_new' && !empty($newBlogTitle)) {
            // Create a new blog on Shopify if token is available
            $targetBlogTitle  = $newBlogTitle;
            $targetBlogHandle = slugifyText($newBlogTitle);

            if (!empty($token)) {
                $createBlogUrl = "https://{$adminDomain}/admin/api/{$version}/blogs.json";
                [$newBCode, $newBBody] = shopifySeoApiRequest('POST', $createBlogUrl, $token, [
                    'blog' => [
                        'title'          => $newBlogTitle,
                        'comment_policy' => 'moderate'
                    ]
                ]);
                if ($newBCode >= 200 && $newBCode < 300) {
                    $newBJson = json_decode($newBBody, true);
                    if (!empty($newBJson['blog']['id'])) {
                        $targetBlogId     = (int)$newBJson['blog']['id'];
                        $targetBlogTitle  = $newBJson['blog']['title'];
                        $targetBlogHandle = $newBJson['blog']['handle'] ?? $targetBlogHandle;
                        $availableBlogs[$targetBlogId] = [
                            'id'     => $targetBlogId,
                            'title'  => $targetBlogTitle,
                            'handle' => $targetBlogHandle
                        ];
                    }
                }
            }
        } elseif (isset($availableBlogs[$blogSelection])) {
            $targetBlogId     = (int)$availableBlogs[$blogSelection]['id'];
            $targetBlogTitle  = $availableBlogs[$blogSelection]['title'];
            $targetBlogHandle = $availableBlogs[$blogSelection]['handle'];
        } else {
            // First available blog
            $firstB = reset($availableBlogs);
            if ($firstB) {
                $targetBlogId     = (int)$firstB['id'];
                $targetBlogTitle  = $firstB['title'];
                $targetBlogHandle = $firstB['handle'];
            }
        }

        // Auto-generate slug / handle if empty
        if (empty($articleHandle)) {
            $articleHandle = slugifyText($articleTitle);
        } else {
            $articleHandle = slugifyText($articleHandle);
        }

        // Default SEO fields if empty
        if (empty($seoTitle)) {
            $seoTitle = $articleTitle;
        }
        if (empty($seoDescription)) {
            $plain = strip_tags(!empty($summaryHtml) ? $summaryHtml : $bodyHtml);
            $plain = preg_replace('/\s+/', ' ', $plain);
            $seoDescription = mb_substr(trim($plain), 0, 155);
        }

        // Calculate SEO Score
        $seoScore = 85;
        if (function_exists('calculateSeoHealth')) {
            $health = calculateSeoHealth($seoTitle, $seoDescription, $articleHandle);
            $seoScore = $health['score'] ?? 85;
        }

        // Determine Publish State
        $isPublished = ($submitAction === 'publish' || $visibility === 'visible');
        $statusStr   = $isPublished ? 'published' : 'draft';
        $publishedAt = $isPublished ? date('c') : null;

        // Process Featured Image
        $imagePayload = null;
        $savedImageSrc = $featuredImageUrl;

        if (isset($_FILES['featured_image_file']) && $_FILES['featured_image_file']['error'] === UPLOAD_ERR_OK) {
            $f = $_FILES['featured_image_file'];
            $fData = file_get_contents($f['tmp_name']);
            if ($fData !== false) {
                $imagePayload = [
                    'attachment' => base64_encode($fData),
                    'alt'        => $featuredImageAlt ?: $articleTitle
                ];

                // Also save local file copy
                $uploadDir = __DIR__ . '/../uploads/articles';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0777, true);
                }
                $ext = pathinfo($f['name'], PATHINFO_EXTENSION) ?: 'jpg';
                $localImgName = 'feat_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                if (@move_uploaded_file($f['tmp_name'], $uploadDir . '/' . $localImgName)) {
                    $savedImageSrc = '../uploads/articles/' . $localImgName;
                }
            }
        } elseif (!empty($featuredImageUrl)) {
            $imagePayload = [
                'src' => $featuredImageUrl,
                'alt' => $featuredImageAlt ?: $articleTitle
            ];
            $savedImageSrc = $featuredImageUrl;
        }

        // Construct Shopify Article API Payload
        $articlePayload = [
            'title'        => $articleTitle,
            'author'       => $authorName,
            'body_html'    => $bodyHtml,
            'summary_html' => $summaryHtml,
            'tags'         => $articleTags,
            'handle'       => $articleHandle,
            'published'    => $isPublished,
        ];
        if ($isPublished) {
            $articlePayload['published_at'] = $publishedAt;
        } else {
            $articlePayload['published_at'] = null;
        }

        if (!empty($imagePayload)) {
            $articlePayload['image'] = $imagePayload;
        }

        // Metafield shorthand for SEO
        $articlePayload['metafields'] = [
            [
                'key'       => 'title_tag',
                'value'     => $seoTitle,
                'type'      => 'string',
                'namespace' => 'global'
            ],
            [
                'key'       => 'description_tag',
                'value'     => $seoDescription,
                'type'      => 'string',
                'namespace' => 'global'
            ]
        ];

        // Send to Shopify API
        $shopifySuccess    = false;
        $shopifyArticleId  = $existingShopifyId;
        $apiErrorDetails   = '';
        $storeDomain       = !empty($shopCfg['domain']) ? $shopCfg['domain'] : 'uratex.com.ph';

        if (!empty($token) && $targetBlogId > 0) {
            if ($existingShopifyId > 0) {
                // Update Existing Article
                $pushUrl = "https://{$adminDomain}/admin/api/{$version}/blogs/{$targetBlogId}/articles/{$existingShopifyId}.json";
                [$pushCode, $pushRes] = shopifySeoApiRequest('PUT', $pushUrl, $token, ['article' => $articlePayload]);
            } else {
                // Create New Article
                $pushUrl = "https://{$adminDomain}/admin/api/{$version}/blogs/{$targetBlogId}/articles.json";
                [$pushCode, $pushRes] = shopifySeoApiRequest('POST', $pushUrl, $token, ['article' => $articlePayload]);
            }

            if ($pushCode >= 200 && $pushCode < 300) {
                $pushData = json_decode($pushRes, true);
                if (!empty($pushData['article']['id'])) {
                    $shopifySuccess   = true;
                    $shopifyArticleId = (int)$pushData['article']['id'];
                    $articleHandle    = $pushData['article']['handle'] ?? $articleHandle;

                    if (!empty($pushData['article']['image']['src'])) {
                        $savedImageSrc = $pushData['article']['image']['src'];
                    }

                    // Explicitly upsert global metafields to ensure Shopify Search Engine Listing matches
                    upsertGlobalSeoMetafield($adminDomain, $version, $token, $shopifyArticleId, 'title_tag', 'string', $seoTitle);
                    upsertGlobalSeoMetafield($adminDomain, $version, $token, $shopifyArticleId, 'description_tag', 'string', $seoDescription);
                }
            } else {
                $apiErrorDetails = "Shopify API Returned HTTP {$pushCode}: " . substr($pushRes, 0, 300);
            }
        }

        // If Shopify was not called (e.g. offline/mock) and no Shopify ID exists, create pseudo ID
        if ($shopifyArticleId <= 0) {
            $shopifyArticleId = (int)(time() . rand(10, 99));
        }

        $liveArticleUrl = "https://{$storeDomain}/blogs/{$targetBlogHandle}/{$articleHandle}";

        // Persist to MySQL `shopify_blogs` Table
        if ($db) {
            try {
                $sql = "
                    INSERT INTO `shopify_blogs` (
                        `store_key`, `shopify_article_id`, `shopify_blog_id`, `article_title`,
                        `blog_title`, `blog_handle`, `article_url`, `title`, `meta_description`,
                        `body_html`, `summary_html`, `image_url`, `tags`,
                        `handle`, `author`, `category`, `status`, `seo_score`,
                        `published_at`, `last_synced_at`, `last_pushed_at`, `updated_by`
                    ) VALUES (
                        :store_key, :shopify_article_id, :shopify_blog_id, :article_title,
                        :blog_title, :blog_handle, :article_url, :title, :meta_description,
                        :body_html, :summary_html, :image_url, :tags,
                        :handle, :author, :category, :status, :seo_score,
                        :published_at, NOW(), " . ($shopifySuccess ? "NOW()" : "NULL") . ", :updated_by
                    )
                    ON DUPLICATE KEY UPDATE
                        `shopify_blog_id`  = VALUES(`shopify_blog_id`),
                        `article_title`    = VALUES(`article_title`),
                        `blog_title`       = VALUES(`blog_title`),
                        `blog_handle`      = VALUES(`blog_handle`),
                        `article_url`      = VALUES(`article_url`),
                        `title`            = VALUES(`title`),
                        `meta_description` = VALUES(`meta_description`),
                        `body_html`        = VALUES(`body_html`),
                        `summary_html`     = VALUES(`summary_html`),
                        `image_url`        = IF(VALUES(`image_url`) != '', VALUES(`image_url`), `shopify_blogs`.`image_url`),
                        `tags`             = VALUES(`tags`),
                        `handle`           = VALUES(`handle`),
                        `author`           = VALUES(`author`),
                        `category`         = VALUES(`category`),
                        `status`           = VALUES(`status`),
                        `seo_score`        = VALUES(`seo_score`),
                        `published_at`     = VALUES(`published_at`),
                        `last_pushed_at`   = IF(VALUES(`last_pushed_at`) IS NOT NULL, VALUES(`last_pushed_at`), `shopify_blogs`.`last_pushed_at`),
                        `updated_by`       = VALUES(`updated_by`),
                        `updated_at`       = NOW()
                ";

                $saveStmt = $db->prepare($sql);
                $saveStmt->execute([
                    ':store_key'           => $activeStore,
                    ':shopify_article_id'  => $shopifyArticleId,
                    ':shopify_blog_id'     => $targetBlogId > 0 ? $targetBlogId : null,
                    ':article_title'       => $articleTitle,
                    ':blog_title'          => $targetBlogTitle,
                    ':blog_handle'         => $targetBlogHandle,
                    ':article_url'         => $liveArticleUrl,
                    ':title'               => $seoTitle,
                    ':meta_description'    => $seoDescription,
                    ':body_html'           => $bodyHtml,
                    ':summary_html'        => $summaryHtml,
                    ':image_url'           => $savedImageSrc,
                    ':tags'                => $articleTags,
                    ':handle'              => $articleHandle,
                    ':author'              => $authorName,
                    ':category'            => $targetBlogTitle,
                    ':status'              => $statusStr,
                    ':seo_score'           => $seoScore,
                    ':published_at'        => $isPublished ? date('Y-m-d H:i:s') : null,
                    ':updated_by'          => $currentUser
                ]);

                // Record Audit Log
                recordUserLog(
                    $isPublished ? 'Publish Article' : 'Save Article Draft',
                    "Article: {$articleTitle}",
                    "Category: {$targetBlogTitle} | Handle: {$articleHandle} | Status: {$statusStr} | Shopify ID: {$shopifyArticleId}",
                    'article',
                    $shopifyArticleId,
                    'success'
                );
            } catch (Exception $e) {
                $apiErrorDetails .= " | DB Error: " . $e->getMessage();
            }
        }

        // Build Response Messages
        $publishedArticleLink = $liveArticleUrl;
        if ($shopifySuccess) {
            $verb = $isPublished ? 'published to Shopify' : 'saved as draft in Shopify';
            $message = "🎉 <strong>Success!</strong> Article '<strong>" . htmlspecialchars($articleTitle) . "</strong>' was successfully {$verb}! "
                     . "<div class='mt-2'>"
                     . "<a href='" . htmlspecialchars($liveArticleUrl) . "' target='_blank' class='btn btn-xs btn-light text-primary font-weight-bold mr-2 shadow-sm'><i class='fas fa-external-link-alt mr-1'></i> View Live Article</a>"
                     . "<a href='blogs.php' class='btn btn-xs btn-outline-light font-weight-bold'><i class='fas fa-list mr-1'></i> Back to Blogs & Articles</a>"
                     . "</div>";
            $messageType = 'success';
        } else {
            if (!empty($token)) {
                $message = "⚠️ <strong>Saved locally:</strong> Article saved to portal database, but Shopify API returned: " . htmlspecialchars($apiErrorDetails);
                $messageType = 'warning';
            } else {
                $verb = $isPublished ? 'marked as published' : 'saved as draft';
                $message = "✅ Article '<strong>" . htmlspecialchars($articleTitle) . "</strong>' {$verb} locally in the portal database. (Configure Shopify Access Token in Settings to push directly to Shopify).";
                $messageType = 'success';
            }
        }

        // Reload editing article so the form displays updated content
        if ($db) {
            $stmt = $db->prepare("SELECT * FROM shopify_blogs WHERE shopify_article_id = :aid AND store_key = :store LIMIT 1");
            $stmt->execute([':aid' => $shopifyArticleId, ':store' => $activeStore]);
            $editingArticle = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    }
}

// -----------------------------------------------------------------------------
// FETCH RECENT ARTICLES ON ACTIVE STORE
// -----------------------------------------------------------------------------
$recentArticles = [];
if ($db) {
    try {
        $rStmt = $db->prepare("
            SELECT id, article_title, blog_title, blog_handle, handle, author, status, seo_score, published_at, updated_at, article_url, shopify_article_id
            FROM shopify_blogs
            WHERE store_key = :store
            ORDER BY updated_at DESC, id DESC
            LIMIT 6
        ");
        $rStmt->execute([':store' => $activeStore]);
        $recentArticles = $rStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $recentArticles = [];
    }
}

// Form Pre-fill Values (Editing or New)
$formTitle       = $editingArticle['article_title'] ?? '';
$formBodyHtml    = $editingArticle['body_html'] ?? '';
$formSummaryHtml = $editingArticle['summary_html'] ?? '';
$formAuthor      = $editingArticle['author'] ?? $currentUser;
$formBlogId      = $editingArticle['shopify_blog_id'] ?? key($availableBlogs);
$formTags        = $editingArticle['tags'] ?? 'Uratex, Mattress, Sleep Health';
$formSeoTitle    = $editingArticle['title'] ?? '';
$formSeoDesc     = $editingArticle['meta_description'] ?? '';
$formHandle      = $editingArticle['handle'] ?? '';
$formStatus      = $editingArticle['status'] ?? 'published';
$formImageUrl    = $editingArticle['image_url'] ?? '';

$pageTitle = 'Write & Publish Article';
include __DIR__ . '/../includes/header.php';
?>

<!-- Summernote Bootstrap 4 Stylesheet -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.css">

<style>
  :root {
    --uratex-blue: #003399;
    --uratex-navy: #002266;
    --uratex-yellow: #FFCC00;
  }
  .article-title-input {
    font-size: 1.35rem;
    font-weight: 700;
    color: #1e293b;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 10px 14px;
    height: auto;
  }
  .article-title-input:focus {
    border-color: var(--uratex-blue);
    box-shadow: 0 0 0 3px rgba(0, 51, 153, 0.15);
  }
  .note-editor.note-frame {
    border: 1px solid #cbd5e1 !important;
    border-radius: 6px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.04);
  }
  .note-toolbar {
    background-color: #f8fafc !important;
    border-bottom: 1px solid #e2e8f0 !important;
    padding: 6px !important;
  }
  .note-btn {
    border-color: #e2e8f0 !important;
    background-color: #ffffff !important;
    color: #334155 !important;
  }
  .note-btn:hover {
    background-color: #f1f5f9 !important;
  }
  /* Google SERP Card Simulator */
  .serp-card {
    background-color: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 16px;
    transition: all 0.2s ease;
  }
  .serp-title {
    font-family: Arial, sans-serif;
    color: #1a0dab;
    font-size: 19px;
    line-height: 1.3;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    margin-bottom: 3px;
  }
  .serp-title:hover {
    text-decoration: underline;
  }
  .serp-url-row {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 12px;
    color: #202124;
    margin-bottom: 4px;
  }
  .serp-favicon {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background-color: #003399;
    color: #FFCC00;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
    font-size: 10px;
    flex-shrink: 0;
  }
  .serp-snippet {
    font-family: Arial, sans-serif;
    font-size: 13.5px;
    color: #4d5156;
    line-height: 1.55;
    word-break: break-word;
  }
  .featured-img-preview-box {
    border: 2px dashed #cbd5e1;
    border-radius: 6px;
    background-color: #f8fafc;
    min-height: 150px;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
  }
  .featured-img-preview-box img {
    max-width: 100%;
    max-height: 220px;
    object-fit: cover;
    border-radius: 4px;
  }
  .tag-badge {
    cursor: pointer;
    font-size: 11px;
    margin: 2px;
    transition: transform 0.15s;
  }
  .tag-badge:hover {
    transform: scale(1.05);
  }
</style>

<div class="content-wrapper">
  <!-- Content Header -->
  <div class="content-header pb-2">
    <div class="container-fluid">
      <?php if (!empty($message)): ?>
        <div class="alert alert-<?php echo $messageType; ?> alert-dismissible fade show shadow-sm" role="alert">
          <?php echo $message; ?>
          <button type="button" class="close text-white" data-dismiss="alert" aria-label="Close">
            <span aria-hidden="true">&times;</span>
          </button>
        </div>
      <?php endif; ?>

      <div class="row align-items-center">
        <div class="col-md-7">
          <div class="d-flex align-items-center">
            <a href="blogs.php" class="btn btn-sm btn-outline-secondary mr-2" title="Back to Blogs & Articles">
              <i class="fas fa-arrow-left"></i>
            </a>
            <div>
              <h1 class="m-0 font-weight-bold" style="color: #002266; font-size: 1.5rem;">
                <i class="fas fa-feather-alt text-warning mr-2"></i>
                <?php echo !empty($editingArticle) ? 'Edit Shopify Blog Article' : 'Write & Publish Article'; ?>
              </h1>
              <span class="text-muted small">
                Create rich articles, configure SEO search engine listing, and publish directly to Shopify.
              </span>
            </div>
          </div>
        </div>
        <div class="col-md-5 text-right mt-2 mt-md-0">
          <span class="badge badge-pill badge-info px-3 py-2 mr-2" style="background-color: #003399;">
            <i class="fas fa-store mr-1"></i> Store: <?php echo htmlspecialchars($shopCfg['name'] ?? ucfirst($activeStore)); ?>
          </span>
          <a href="blogs.php" class="btn btn-sm btn-outline-primary font-weight-bold">
            <i class="fas fa-newspaper mr-1"></i> View All Articles
          </a>
        </div>
      </div>
    </div>
  </div>

  <!-- Main content -->
  <section class="content pb-4">
    <div class="container-fluid">
      
      <form method="POST" action="content.php" enctype="multipart/form-data" id="articleForm">
        <input type="hidden" name="action" value="publish_article">
        <input type="hidden" name="shopify_article_id" value="<?php echo htmlspecialchars($editingArticle['shopify_article_id'] ?? ''); ?>">
        <input type="hidden" name="local_db_id" value="<?php echo htmlspecialchars($editingArticle['id'] ?? ''); ?>">

        <div class="row">
          
          <!-- LEFT / MAIN COLUMN (Content & SEO) -->
          <div class="col-lg-8">

            <!-- Title & Article Body Card -->
            <div class="card card-outline card-primary shadow-sm mb-4">
              <div class="card-header bg-white py-3 border-bottom">
                <div class="d-flex justify-content-between align-items-center">
                  <h3 class="card-title font-weight-bold text-dark m-0">
                    <i class="fas fa-pen-fancy text-primary mr-2"></i>Article Content
                  </h3>
                  <span class="badge badge-light border text-muted">Shopify Blog Post Standard</span>
                </div>
              </div>
              <div class="card-body">
                
                <!-- Article Title -->
                <div class="form-group mb-3">
                  <label for="articleTitleInput" class="font-weight-bold text-dark">
                    Article Title <span class="text-danger">*</span>
                  </label>
                  <input type="text" name="article_title" id="articleTitleInput" 
                         class="form-control article-title-input" 
                         placeholder="e.g. 7 Health Benefits of Orthopedic Foam Mattresses for Deep Sleep" 
                         value="<?php echo htmlspecialchars($formTitle); ?>" required>
                  <small class="form-text text-muted d-flex justify-content-between">
                    <span>A clear, captivating headline attracts readers and ranks higher in Google searches.</span>
                    <span id="articleTitleCharCount" class="font-weight-bold">0 chars</span>
                  </small>
                </div>

                <!-- Rich Text Editor (Body HTML) -->
                <div class="form-group mb-4">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <label for="articleContent" class="font-weight-bold text-dark m-0">
                      Content Body <span class="text-danger">*</span>
                    </label>
                    <div class="small text-muted">
                      <i class="fas fa-info-circle mr-1 text-primary"></i> Supports font styles, headings, tables & inline images
                    </div>
                  </div>
                  <textarea name="body_html" id="articleContent"><?php echo htmlspecialchars($formBodyHtml); ?></textarea>
                </div>

                <!-- Excerpt / Summary -->
                <div class="form-group mb-0">
                  <label for="articleExcerpt" class="font-weight-bold text-dark">
                    Excerpt / Teaser Summary <span class="text-muted font-weight-normal">(Optional)</span>
                  </label>
                  <textarea name="summary_html" id="articleExcerpt" class="form-control" rows="3" 
                            placeholder="Add a brief 1-2 sentence overview shown on your Shopify blog landing page or featured banners..."><?php echo htmlspecialchars($formSummaryHtml); ?></textarea>
                  <small class="form-text text-muted">Used by Shopify themes on blog index pages and RSS feeds.</small>
                </div>

              </div>
            </div>

            <!-- Search Engine Listing Preview Card (Shopify SEO Standard) -->
            <div class="card card-outline card-info shadow-sm mb-4">
              <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between align-items-center">
                <div>
                  <h3 class="card-title font-weight-bold text-dark m-0">
                    <i class="fab fa-google text-danger mr-2"></i>Search Engine Listing
                  </h3>
                  <div class="small text-muted mt-1">Configure how this article appears in Google, Bing, and social search results.</div>
                </div>
                <div class="btn-group btn-group-sm">
                  <button type="button" class="btn btn-outline-secondary active" id="btnPreviewDesktop" title="Desktop SERP Preview">
                    <i class="fas fa-desktop mr-1"></i> Desktop
                  </button>
                  <button type="button" class="btn btn-outline-secondary" id="btnPreviewMobile" title="Mobile SERP Preview">
                    <i class="fas fa-mobile-alt mr-1"></i> Mobile
                  </button>
                </div>
              </div>

              <div class="card-body">

                <!-- Live SERP Simulator Box -->
                <div class="bg-light p-3 rounded mb-4 border">
                  <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-xs font-weight-bold text-uppercase text-secondary">
                      <i class="fas fa-search mr-1 text-info"></i> Google Search Snippet Simulation
                    </span>
                    <button type="button" class="btn btn-xs btn-outline-primary" id="btnAutoFillSeo" title="Automatically craft SEO title & description from article content">
                      <i class="fas fa-magic mr-1"></i> Auto-fill from Article
                    </button>
                  </div>

                  <div class="serp-card shadow-sm" id="serpContainer">
                    <div class="serp-url-row">
                      <div class="serp-favicon">U</div>
                      <div class="text-truncate">
                        <span class="font-weight-bold">uratex.com.ph</span>
                        <span class="text-muted">› blogs › </span>
                        <span class="text-muted" id="serpBlogHandleDisplay">news</span>
                        <span class="text-muted"> › </span>
                        <span class="text-muted" id="serpSlugDisplay">article-slug</span>
                      </div>
                    </div>
                    <div>
                      <a href="javascript:void(0)" class="serp-title" id="serpTitleDisplay">
                        <?php echo htmlspecialchars($formSeoTitle ?: ($formTitle ?: 'Untitled Article - Uratex Philippines')); ?>
                      </a>
                    </div>
                    <div class="serp-snippet" id="serpDescDisplay">
                      <?php echo htmlspecialchars($formSeoDesc ?: 'Discover top sleep health insights, foam innovations, and wellness recommendations from Uratex Philippines...'); ?>
                    </div>
                  </div>
                </div>

                <!-- SEO Page Title -->
                <div class="form-group mb-3">
                  <div class="d-flex justify-content-between align-items-center">
                    <label for="seoTitleInput" class="font-weight-bold text-dark mb-1">
                      Page Title Tag
                    </label>
                    <span class="small font-weight-bold" id="seoTitleCounter" style="color: #64748b;">
                      <span id="seoTitleLen">0</span> / 60 characters
                    </span>
                  </div>
                  <input type="text" name="seo_title" id="seoTitleInput" class="form-control" 
                         maxlength="100" 
                         placeholder="Optimized SEO Title tag (recommended max 60 chars)" 
                         value="<?php echo htmlspecialchars($formSeoTitle); ?>">
                  <small class="form-text text-muted">Shopify metafield: <code>global:title_tag</code>.</small>
                </div>

                <!-- SEO Meta Description -->
                <div class="form-group mb-3">
                  <div class="d-flex justify-content-between align-items-center">
                    <label for="seoDescInput" class="font-weight-bold text-dark mb-1">
                      Meta Description Tag
                    </label>
                    <span class="small font-weight-bold" id="seoDescCounter" style="color: #64748b;">
                      <span id="seoDescLen">0</span> / 160 characters
                    </span>
                  </div>
                  <textarea name="seo_description" id="seoDescInput" class="form-control" rows="3" 
                            maxlength="320" 
                            placeholder="Briefly summarize this article for search engine results (recommended 120-160 chars)..."><?php echo htmlspecialchars($formSeoDesc); ?></textarea>
                  <small class="form-text text-muted">Shopify metafield: <code>global:description_tag</code>.</small>
                </div>

                <!-- URL and Handle -->
                <div class="form-group mb-0">
                  <div class="d-flex justify-content-between align-items-center">
                    <label for="handleInput" class="font-weight-bold text-dark mb-1">
                      URL and Handle (Slug)
                    </label>
                    <button type="button" class="btn btn-xs btn-link p-0 text-primary" id="btnRegenSlug">
                      <i class="fas fa-sync-alt mr-1"></i> Regenerate from Title
                    </button>
                  </div>
                  <div class="input-group">
                    <div class="input-group-prepend">
                      <span class="input-group-text bg-light text-muted small">
                        https://<?php echo htmlspecialchars(!empty($shopCfg['domain']) ? $shopCfg['domain'] : 'uratex.com.ph'); ?>/blogs/<span id="urlBlogSegment"><?php echo htmlspecialchars($availableBlogs[$formBlogId]['handle'] ?? 'news'); ?></span>/
                      </span>
                    </div>
                    <input type="text" name="handle" id="handleInput" class="form-control" 
                           placeholder="article-url-slug" 
                           value="<?php echo htmlspecialchars($formHandle); ?>">
                  </div>
                  <small class="form-text text-muted">Unique identifier for this article in Shopify.</small>
                </div>

              </div>
            </div>

          </div>

          <!-- RIGHT / SIDEBAR COLUMN (Organization & Publishing) -->
          <div class="col-lg-4">

            <!-- Publishing & Visibility Card -->
            <div class="card card-outline card-success shadow-sm mb-4">
              <div class="card-header bg-white py-3 border-bottom">
                <h3 class="card-title font-weight-bold text-dark m-0">
                  <i class="fas fa-paper-plane text-success mr-2"></i>Publishing Actions
                </h3>
              </div>
              <div class="card-body">
                
                <!-- Visibility Radio Group -->
                <label class="font-weight-bold text-dark mb-2">Visibility Status</label>
                <div class="bg-light p-3 rounded mb-3 border">
                  <div class="custom-control custom-radio mb-2">
                    <input type="radio" id="visVisible" name="visibility" value="visible" class="custom-control-input" 
                           <?php echo ($formStatus === 'published') ? 'checked' : ''; ?>>
                    <label class="custom-control-label font-weight-bold text-success" for="visVisible">
                      <i class="fas fa-eye mr-1"></i> Visible (Published)
                    </label>
                    <div class="text-xs text-muted pl-4">Article is immediately readable by store visitors.</div>
                  </div>
                  <div class="custom-control custom-radio">
                    <input type="radio" id="visHidden" name="visibility" value="hidden" class="custom-control-input" 
                           <?php echo ($formStatus !== 'published') ? 'checked' : ''; ?>>
                    <label class="custom-control-label font-weight-bold text-secondary" for="visHidden">
                      <i class="fas fa-eye-slash mr-1"></i> Hidden (Draft)
                    </label>
                    <div class="text-xs text-muted pl-4">Save work without making it publicly accessible yet.</div>
                  </div>
                </div>

                <!-- Action Buttons -->
                <div class="form-group mb-2">
                  <button type="submit" name="submit_action" value="publish" class="btn btn-success btn-block btn-lg font-weight-bold shadow-sm" id="btnPublishArticle">
                    <i class="fas fa-cloud-upload-alt mr-2"></i> Publish to Shopify
                  </button>
                </div>
                <div class="form-group mb-0">
                  <button type="submit" name="submit_action" value="draft" class="btn btn-outline-secondary btn-block font-weight-bold" id="btnSaveDraft">
                    <i class="fas fa-save mr-2"></i> Save as Draft
                  </button>
                </div>

                <?php if (!empty($editingArticle['article_url'])): ?>
                  <div class="mt-3 pt-3 border-top text-center">
                    <a href="<?php echo htmlspecialchars($editingArticle['article_url']); ?>" target="_blank" class="btn btn-sm btn-light border text-primary btn-block font-weight-bold">
                      <i class="fas fa-external-link-alt mr-1"></i> View Live on Shopify Store
                    </a>
                  </div>
                <?php endif; ?>

              </div>
            </div>

            <!-- Featured Image Card -->
            <div class="card card-outline card-secondary shadow-sm mb-4">
              <div class="card-header bg-white py-3 border-bottom">
                <h3 class="card-title font-weight-bold text-dark m-0">
                  <i class="fas fa-image text-info mr-2"></i>Featured Image
                </h3>
              </div>
              <div class="card-body">
                
                <!-- Live Image Preview Box -->
                <div class="featured-img-preview-box mb-3 text-center p-2" id="featuredImgBox">
                  <?php if (!empty($formImageUrl)): ?>
                    <img src="<?php echo htmlspecialchars($formImageUrl); ?>" id="previewImgElement" alt="Featured Image Preview">
                  <?php else: ?>
                    <div class="text-muted small" id="noImgNotice">
                      <i class="far fa-image fa-3x mb-2 text-secondary d-block"></i>
                      <span>No featured image selected yet</span>
                    </div>
                  <?php endif; ?>
                </div>

                <!-- Upload Local File -->
                <div class="form-group mb-2">
                  <label for="featuredImageFile" class="font-weight-bold small text-dark mb-1">
                    Upload Image File
                  </label>
                  <div class="custom-file">
                    <input type="file" name="featured_image_file" id="featuredImageFile" class="custom-file-input" accept="image/*">
                    <label class="custom-file-label text-truncate text-muted small" for="featuredImageFile">Choose photo...</label>
                  </div>
                  <small class="text-muted text-xs">Pushed directly to Shopify CDN with the article.</small>
                </div>

                <!-- OR External Image URL -->
                <div class="form-group mb-2">
                  <label for="featuredImageUrl" class="font-weight-bold small text-dark mb-1">
                    Or Image Web URL
                  </label>
                  <input type="url" name="featured_image_url" id="featuredImageUrl" class="form-control form-control-sm" 
                         placeholder="https://example.com/mattress-banner.jpg" 
                         value="<?php echo htmlspecialchars($formImageUrl); ?>">
                </div>

                <!-- Image Alt Text -->
                <div class="form-group mb-0">
                  <label for="featuredImageAlt" class="font-weight-bold small text-dark mb-1">
                    Image Alt Text <span class="text-muted">(SEO)</span>
                  </label>
                  <input type="text" name="featured_image_alt" id="featuredImageAlt" class="form-control form-control-sm" 
                         placeholder="e.g. Uratex Premium Foam Mattress Lifestyle">
                </div>

              </div>
            </div>

            <!-- Organization Card -->
            <div class="card card-outline card-secondary shadow-sm mb-4">
              <div class="card-header bg-white py-3 border-bottom">
                <h3 class="card-title font-weight-bold text-dark m-0">
                  <i class="fas fa-sitemap text-warning mr-2"></i>Organization
                </h3>
              </div>
              <div class="card-body">
                
                <!-- Blog Category Dropdown -->
                <div class="form-group mb-3">
                  <div class="d-flex justify-content-between align-items-center mb-1">
                    <label for="blogCategorySelect" class="font-weight-bold text-dark m-0">
                      Blog Category <span class="text-danger">*</span>
                    </label>
                    <span class="badge badge-light border text-muted">Shopify Blog</span>
                  </div>
                  <select name="blog_id" id="blogCategorySelect" class="form-control" required>
                    <?php foreach ($availableBlogs as $bId => $bData): ?>
                      <option value="<?php echo htmlspecialchars($bId); ?>" 
                              data-handle="<?php echo htmlspecialchars($bData['handle'] ?? 'news'); ?>"
                              data-title="<?php echo htmlspecialchars($bData['title'] ?? 'News'); ?>"
                              <?php echo ((string)$formBlogId === (string)$bId) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($bData['title']); ?> (/blogs/<?php echo htmlspecialchars($bData['handle'] ?? 'news'); ?>)
                      </option>
                    <?php endforeach; ?>
                    <option value="create_new" class="font-weight-bold text-primary">
                      + Create new blog category...
                    </option>
                  </select>
                </div>

                <!-- Inline New Blog Category Input (Toggled when "create_new" selected) -->
                <div class="form-group mb-3 d-none" id="newBlogCategoryContainer">
                  <label for="newBlogTitleInput" class="font-weight-bold small text-primary mb-1">
                    New Category Title:
                  </label>
                  <input type="text" name="new_blog_title" id="newBlogTitleInput" class="form-control form-control-sm" 
                         placeholder="e.g. Sleep Innovations & Guides">
                  <small class="text-muted text-xs">Will be automatically created in your Shopify store.</small>
                </div>

                <!-- Author's Name -->
                <div class="form-group mb-3">
                  <label for="authorInput" class="font-weight-bold text-dark mb-1">
                    Author's Name <span class="text-danger">*</span>
                  </label>
                  <div class="input-group">
                    <div class="input-group-prepend">
                      <span class="input-group-text bg-light text-muted"><i class="fas fa-user-edit"></i></span>
                    </div>
                    <input type="text" name="author" id="authorInput" class="form-control" 
                           placeholder="e.g. Jenor Ricafort" 
                           value="<?php echo htmlspecialchars($formAuthor); ?>" required>
                  </div>
                  <small class="text-muted text-xs">Displayed by theme as the article writer.</small>
                </div>

                <!-- Tags -->
                <div class="form-group mb-0">
                  <label for="tagsInput" class="font-weight-bold text-dark mb-1">
                    Tags <span class="text-muted font-weight-normal">(Comma-separated)</span>
                  </label>
                  <input type="text" name="tags" id="tagsInput" class="form-control form-control-sm mb-2" 
                         placeholder="Mattress, Sleep Health, Foam, Comfort" 
                         value="<?php echo htmlspecialchars($formTags); ?>">
                  
                  <div class="text-xs text-muted mb-1">Quick Suggestions:</div>
                  <div class="d-flex flex-wrap">
                    <span class="badge badge-light border tag-badge" data-tag="Mattress">Mattress</span>
                    <span class="badge badge-light border tag-badge" data-tag="Sleep Health">Sleep Health</span>
                    <span class="badge badge-light border tag-badge" data-tag="Orthopedic Foam">Orthopedic Foam</span>
                    <span class="badge badge-light border tag-badge" data-tag="Bedroom Decor">Bedroom Decor</span>
                    <span class="badge badge-light border tag-badge" data-tag="Wellness">Wellness</span>
                    <span class="badge badge-light border tag-badge" data-tag="Uratex Comfort">Uratex Comfort</span>
                  </div>
                </div>

              </div>
            </div>

          </div>

        </div>
      </form>

      <!-- RECENT ARTICLES TABLE ON ACTIVE STORE -->
      <?php if (!empty($recentArticles)): ?>
        <div class="card card-outline card-secondary shadow-sm mt-2">
          <div class="card-header bg-white py-3">
            <div class="d-flex justify-content-between align-items-center">
              <h3 class="card-title font-weight-bold text-dark m-0">
                <i class="fas fa-history text-secondary mr-2"></i>Recent Articles on <?php echo htmlspecialchars($shopCfg['name'] ?? ucfirst($activeStore)); ?>
              </h3>
              <a href="blogs.php" class="btn btn-xs btn-outline-primary font-weight-bold">
                View All in SEO Module <i class="fas fa-chevron-right ml-1"></i>
              </a>
            </div>
          </div>
          <div class="card-body p-0 table-responsive">
            <table class="table table-hover table-striped align-middle mb-0 text-sm">
              <thead class="thead-light">
                <tr>
                  <th style="width: 35%;">Article Title</th>
                  <th>Category</th>
                  <th>Author</th>
                  <th>Status</th>
                  <th>SEO Health</th>
                  <th>Last Updated</th>
                  <th class="text-right">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentArticles as $art): ?>
                  <tr>
                    <td>
                      <div class="font-weight-bold text-dark"><?php echo htmlspecialchars($art['article_title']); ?></div>
                      <div class="text-xs text-muted">/blogs/<?php echo htmlspecialchars($art['blog_handle'] ?? 'news'); ?>/<?php echo htmlspecialchars($art['handle']); ?></div>
                    </td>
                    <td>
                      <span class="badge badge-light border"><?php echo htmlspecialchars($art['blog_title'] ?? 'News'); ?></span>
                    </td>
                    <td class="text-muted">
                      <?php echo htmlspecialchars($art['author'] ?? 'Uratex Editorial'); ?>
                    </td>
                    <td>
                      <?php if ($art['status'] === 'published'): ?>
                        <span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i>Published</span>
                      <?php else: ?>
                        <span class="badge badge-secondary px-2 py-1"><i class="fas fa-pencil-alt mr-1"></i>Draft</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php $score = (int)($art['seo_score'] ?? 85); ?>
                      <span class="badge badge-<?php echo ($score >= 80 ? 'success' : ($score >= 60 ? 'warning' : 'danger')); ?> font-weight-bold px-2 py-1">
                        <?php echo $score; ?>%
                      </span>
                    </td>
                    <td class="text-muted text-xs">
                      <?php echo !empty($art['updated_at']) ? date('M j, Y g:ia', strtotime($art['updated_at'])) : '-'; ?>
                    </td>
                    <td class="text-right text-nowrap">
                      <a href="content.php?edit_id=<?php echo $art['id']; ?>" class="btn btn-xs btn-primary font-weight-bold mr-1" title="Edit this article">
                        <i class="fas fa-edit mr-1"></i>Edit
                      </a>
                      <?php if (!empty($art['article_url'])): ?>
                        <a href="<?php echo htmlspecialchars($art['article_url']); ?>" target="_blank" class="btn btn-xs btn-outline-secondary" title="View on live website">
                          <i class="fas fa-external-link-alt"></i>
                        </a>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
      <?php endif; ?>

    </div>
  </section>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<!-- Summernote Bootstrap 4 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/summernote@0.8.18/dist/summernote-bs4.min.js"></script>

<script>
$(document).ready(function() {

  // 1. Initialize Summernote WYSIWYG Editor
  $('#articleContent').summernote({
    height: 460,
    minHeight: 250,
    placeholder: 'Write your story, guides, announcements, or product review here...',
    fontNames: [
      'Source Sans Pro', 'Arial', 'Arial Black', 'Comic Sans MS', 
      'Courier New', 'Georgia', 'Impact', 'Lucida Console', 
      'Roboto', 'Tahoma', 'Times New Roman', 'Trebuchet MS', 'Verdana'
    ],
    fontNamesIgnoreCheck: ['Source Sans Pro', 'Roboto'],
    toolbar: [
      ['style', ['style']],
      ['font', ['bold', 'italic', 'underline', 'strikethrough', 'superscript', 'subscript', 'clear']],
      ['fontname', ['fontname']],
      ['fontsize', ['fontsize']],
      ['color', ['color']],
      ['para', ['ul', 'ol', 'paragraph', 'height']],
      ['table', ['table']],
      ['insert', ['link', 'picture', 'video', 'hr']],
      ['view', ['fullscreen', 'codeview', 'help']]
    ],
    callbacks: {
      onImageUpload: function(files) {
        for (let i = 0; i < files.length; i++) {
          uploadInlineSummernoteImage(files[i]);
        }
      },
      onChange: function(contents) {
        updateSerpPreview();
      }
    }
  });

  // AJAX handler for Summernote inline images
  function uploadInlineSummernoteImage(file) {
    const data = new FormData();
    data.append('inline_image', file);
    data.append('action', 'ajax_upload_image');

    $.ajax({
      url: 'content.php',
      method: 'POST',
      data: data,
      processData: false,
      contentType: false,
      success: function(resp) {
        if (resp && resp.url) {
          $('#articleContent').summernote('insertImage', resp.url);
        } else {
          // Fallback to Base64 FileReader
          embedBase64Fallback(file);
        }
      },
      error: function() {
        embedBase64Fallback(file);
      }
    });
  }

  function embedBase64Fallback(file) {
    const reader = new FileReader();
    reader.onloadend = function() {
      $('#articleContent').summernote('insertImage', reader.result);
    };
    reader.readAsDataURL(file);
  }

  // 2. Slugify Helper
  function slugify(text) {
    return text.toString().toLowerCase()
      .trim()
      .replace(/\s+/g, '-')           // Replace spaces with -
      .replace(/[^\w\-]+/g, '')       // Remove all non-word chars
      .replace(/\-\-+/g, '-')         // Replace multiple - with single -
      .replace(/^-+/, '')             // Trim - from start of text
      .replace(/-+$/, '');            // Trim - from end of text
  }

  // 3. Dynamic Real-time SERP Snippet Preview Updates
  const articleTitleInput = document.getElementById('articleTitleInput');
  const seoTitleInput     = document.getElementById('seoTitleInput');
  const seoDescInput      = document.getElementById('seoDescInput');
  const handleInput       = document.getElementById('handleInput');
  const blogCategorySelect= document.getElementById('blogCategorySelect');
  const newBlogTitleInput = document.getElementById('newBlogTitleInput');

  let handleWasManuallyEdited = false;
  let seoTitleWasManuallyEdited = false;

  if (handleInput.value.trim().length > 0) {
    handleWasManuallyEdited = true;
  }
  if (seoTitleInput.value.trim().length > 0) {
    seoTitleWasManuallyEdited = true;
  }

  handleInput.addEventListener('input', function() {
    handleWasManuallyEdited = true;
    updateSerpPreview();
  });

  seoTitleInput.addEventListener('input', function() {
    seoTitleWasManuallyEdited = true;
    updateSerpPreview();
  });

  articleTitleInput.addEventListener('input', function() {
    const titleVal = this.value;
    document.getElementById('articleTitleCharCount').textContent = titleVal.length + ' chars';

    if (!handleWasManuallyEdited) {
      handleInput.value = slugify(titleVal);
    }
    if (!seoTitleWasManuallyEdited) {
      seoTitleInput.value = titleVal;
    }
    updateSerpPreview();
  });

  seoDescInput.addEventListener('input', function() {
    updateSerpPreview();
  });

  blogCategorySelect.addEventListener('change', function() {
    const isNew = (this.value === 'create_new');
    const container = document.getElementById('newBlogCategoryContainer');
    if (isNew) {
      container.classList.remove('d-none');
      newBlogTitleInput.focus();
    } else {
      container.classList.add('d-none');
    }
    updateSerpPreview();
  });

  newBlogTitleInput.addEventListener('input', function() {
    updateSerpPreview();
  });

  document.getElementById('btnRegenSlug').addEventListener('click', function() {
    handleInput.value = slugify(articleTitleInput.value);
    handleWasManuallyEdited = false;
    updateSerpPreview();
  });

  function updateSerpPreview() {
    // 1. Title
    const rawTitle = seoTitleInput.value.trim() || articleTitleInput.value.trim() || 'Untitled Article - Uratex Philippines';
    document.getElementById('serpTitleDisplay').textContent = rawTitle;
    const titleLen = seoTitleInput.value.length;
    document.getElementById('seoTitleLen').textContent = titleLen;
    const titleCounter = document.getElementById('seoTitleCounter');
    if (titleLen > 65) {
      titleCounter.style.color = '#dc2626';
    } else if (titleLen >= 35) {
      titleCounter.style.color = '#16a34a';
    } else {
      titleCounter.style.color = '#64748b';
    }

    // 2. Blog handle segment
    let blogHandle = 'news';
    if (blogCategorySelect.value === 'create_new') {
      blogHandle = slugify(newBlogTitleInput.value) || 'new-category';
    } else {
      const selectedOpt = blogCategorySelect.options[blogCategorySelect.selectedIndex];
      blogHandle = selectedOpt ? (selectedOpt.getAttribute('data-handle') || 'news') : 'news';
    }
    document.getElementById('serpBlogHandleDisplay').textContent = blogHandle;
    document.getElementById('urlBlogSegment').textContent = blogHandle;

    // 3. Slug
    const slug = handleInput.value.trim() || slugify(articleTitleInput.value) || 'article-slug';
    document.getElementById('serpSlugDisplay').textContent = slug;

    // 4. Meta Description
    let desc = seoDescInput.value.trim();
    if (!desc) {
      const plainContent = $('<div>').html($('#articleContent').summernote('code')).text().trim();
      desc = plainContent.substring(0, 155) || 'Discover top sleep health insights, foam innovations, and wellness recommendations from Uratex Philippines...';
    }
    document.getElementById('serpDescDisplay').textContent = desc;
    const descLen = seoDescInput.value.length;
    document.getElementById('seoDescLen').textContent = descLen;
    const descCounter = document.getElementById('seoDescCounter');
    if (descLen > 160) {
      descCounter.style.color = '#dc2626';
    } else if (descLen >= 100) {
      descCounter.style.color = '#16a34a';
    } else {
      descCounter.style.color = '#64748b';
    }
  }

  // 4. Auto-fill SEO button
  document.getElementById('btnAutoFillSeo').addEventListener('click', function() {
    const titleVal = articleTitleInput.value.trim();
    if (titleVal) {
      seoTitleInput.value = titleVal;
    }
    const plainContent = $('<div>').html($('#articleContent').summernote('code')).text().trim();
    if (plainContent) {
      const clean = plainContent.replace(/\s+/g, ' ');
      seoDescInput.value = clean.substring(0, 150) + (clean.length > 150 ? '...' : '');
    }
    if (!handleInput.value.trim()) {
      handleInput.value = slugify(titleVal);
    }
    updateSerpPreview();
  });

  // 5. Featured Image Preview
  const featuredFile = document.getElementById('featuredImageFile');
  const featuredUrl  = document.getElementById('featuredImageUrl');
  const previewBox   = document.getElementById('featuredImgBox');

  featuredFile.addEventListener('change', function(e) {
    if (this.files && this.files[0]) {
      const file = this.files[0];
      const reader = new FileReader();
      reader.onload = function(ev) {
        previewBox.innerHTML = '<img src="' + ev.target.result + '" alt="Preview">';
      };
      reader.readAsDataURL(file);
      // update file input label
      const nextSibling = this.nextElementSibling;
      if (nextSibling) nextSibling.innerText = file.name;
    }
  });

  featuredUrl.addEventListener('input', function() {
    const url = this.value.trim();
    if (url) {
      previewBox.innerHTML = '<img src="' + url + '" alt="Preview" onerror="this.parentElement.innerHTML=\'<span class=\\\'text-danger text-xs\\\'>Failed to load image URL</span>\'">';
    } else {
      previewBox.innerHTML = '<div class="text-muted small"><i class="far fa-image fa-3x mb-2 text-secondary d-block"></i><span>No featured image selected yet</span></div>';
    }
  });

  // 6. Tag Suggestion Clicks
  $('.tag-badge').on('click', function() {
    const tag = $(this).data('tag');
    const cur = $('#tagsInput').val().trim();
    if (cur.length === 0) {
      $('#tagsInput').val(tag);
    } else {
      const arr = cur.split(',').map(s => s.trim());
      if (!arr.includes(tag)) {
        $('#tagsInput').val(cur + ', ' + tag);
      }
    }
  });

  // 7. Desktop vs Mobile SERP Simulator Toggle
  $('#btnPreviewDesktop').on('click', function() {
    $(this).addClass('active');
    $('#btnPreviewMobile').removeClass('active');
    $('#serpContainer').css('max-width', '100%');
  });

  $('#btnPreviewMobile').on('click', function() {
    $(this).addClass('active');
    $('#btnPreviewDesktop').removeClass('active');
    $('#serpContainer').css('max-width', '375px');
  });

  // 8. Form Submit Visual Feedback
  $('#articleForm').on('submit', function(e) {
    const submitBtn = $(document.activeElement);
    if (submitBtn && submitBtn.length) {
      submitBtn.prop('disabled', true);
      const isPublish = submitBtn.val() === 'publish';
      submitBtn.html('<i class="fas fa-spinner fa-spin mr-2"></i> ' + (isPublish ? 'Publishing...' : 'Saving Draft...'));
      // re-enable before submission so name/value is sent
      setTimeout(() => {
        submitBtn.prop('disabled', false);
      }, 50);
    }
  });

  // Initial trigger
  updateSerpPreview();
});
</script>
