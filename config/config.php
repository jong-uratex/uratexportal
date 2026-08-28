<?php
/**
 * Uratex Shopify Dynamic SEO Partner Portal
 * Configuration File: Shopify API & Database Credentials
 */

session_start();

// Database Credentials (MySQL)
define('DB_HOST', 'localhost');
define('DB_NAME', 'u390249810_seomini');
define('DB_USER', 'u390249810_seominiu');
define('DB_PASS', 'Ric@fort2025');

// Google reCAPTCHA v2 Credentials
define('RECAPTCHA_SITE_KEY', '6LcWAv4qAAAAABQSMgz07Zw617rv8YmmeGGa1kXN');
define('RECAPTCHA_SECRET_KEY', '6LcWAv4qAAAAAM6PdZkjJJXnK2SUV33wyWyUfMbQ');

// Shopify Multi-Store API Credentials
$shopConfig = [
    'retail' => [
        'id' => 'retail',
        'name' => 'Uratex Retail (Consumer)',
        'url' => 'uratex-philippines.myshopify.com',
        'fallback_url' => 'uratex-ph.myshopify.com',
        'domain' => 'uratex.com.ph',
        'api_key' => '89f9f3f97cc00f1ab817a56aef3b76c5',
        'access_token' => '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',
        'version' => '2025-10',
        'total_catalog_count' => 496
    ],
    'business' => [
        'id' => 'business',
        'name' => 'Uratex Business (B2B)',
        'url' => 'uratex-business.myshopify.com',
        'domain' => 'business.uratex.com.ph',
        'api_key' => '89f9f3f97cc00f1ab817a56aef3b76c5',
        'access_token' => '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',
        'version' => '2025-10',
        'total_catalog_count' => 496
    ]
];

// Active Store in Session (Supports GET switch_store or store parameter)
if (isset($_GET['switch_store']) && in_array($_GET['switch_store'], ['retail', 'business'])) {
    $_SESSION['active_store'] = $_GET['switch_store'];
} elseif (isset($_GET['store']) && in_array($_GET['store'], ['retail', 'business'])) {
    $_SESSION['active_store'] = $_GET['store'];
}

if (!isset($_SESSION['active_store'])) {
    $_SESSION['active_store'] = 'business';
}

function getActiveStore() {
    global $shopConfig;
    $key = $_SESSION['active_store'] ?? 'business';
    return $shopConfig[$key] ?? $shopConfig['business'];
}

function setActiveStore($storeKey) {
    global $shopConfig;
    if (isset($shopConfig[$storeKey])) {
        $_SESSION['active_store'] = $storeKey;
        return true;
    }
    return false;
}

// Database Connection Helper
function getDbConnection() {
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        return $pdo;
    } catch (PDOException $e) {
        // Fallback for local sandbox without live MySQL server
        return null;
    }
}

// Shopify API Request Helper (REST Admin API)
function shopifyApiRequest($endpoint, $method = 'GET', $data = null) {
    $store = getActiveStore();
    $url = "https://" . $store['url'] . "/admin/api/" . $store['version'] . "/" . ltrim($endpoint, '/');

    $headers = [
        "Content-Type: application/json",
        "X-Shopify-Access-Token: " . $store['access_token']
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return [
        'status' => $httpCode,
        'data' => json_decode($response, true)
    ];
}

// SEO Health Score Calculation Algorithm
function calculateSeoHealth($title, $metaDescription, $handle) {
    $score = 100;
    $issues = [];

    $tLen = mb_strlen(trim($title));
    $dLen = mb_strlen(trim($metaDescription));
    $h = trim($handle);

    // Title validation (Optimal: 50-60 chars)
    if ($tLen === 0) {
        $score -= 35;
        $issues[] = "Missing Page Title";
    } elseif ($tLen < 35) {
        $score -= 15;
        $issues[] = "Title too short ({$tLen} chars, optimal: 50-60)";
    } elseif ($tLen > 65) {
        $score -= 10;
        $issues[] = "Title too long ({$tLen} chars, max: 60-65)";
    }

    // Meta description validation (Optimal: 120-160 chars)
    if ($dLen === 0) {
        $score -= 35;
        $issues[] = "Missing Meta Description";
    } elseif ($dLen < 90) {
        $score -= 15;
        $issues[] = "Meta description too short ({$dLen} chars, optimal: 120-160)";
    } elseif ($dLen > 165) {
        $score -= 10;
        $issues[] = "Meta description exceeds 160 chars ({$dLen} chars)";
    }

    // Handle check
    if (empty($h)) {
        $score -= 15;
        $issues[] = "Missing URL Handle";
    }

    return [
        'score' => max(10, min(100, $score)),
        'issues' => $issues
    ];
}

/**
 * User Activity & Audit Trail Logger
 * Records logins, logouts, draft revisions, Shopify syncs/pushes, AI optimizations, etc.
 * Persists into MySQL `user_logs` table and maintains session fallback.
 */
function recordUserLog($action, $targetResource, $changeDetails, $resourceType = 'system', $resourceId = null, $status = 'success', $customUser = null) {
    $db = getDbConnection();
    
    // Resolve user details
    $userId = $_SESSION['user_id'] ?? null;
    $userEmail = $customUser ?? ($_SESSION['user_email'] ?? ($_SESSION['user_username'] ?? 'jenor.ricafort@uratex.com.ph'));
    $userName = $_SESSION['user_name'] ?? 'Jenor Ricafort';
    $storeKey = $_SESSION['active_store'] ?? 'business';
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Uratex Partner Portal';

    if ($db) {
        try {
            // Auto create user_logs table if not exists
            $db->exec("CREATE TABLE IF NOT EXISTS `user_logs` (
              `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              `user_id` INT UNSIGNED NULL DEFAULT NULL,
              `user_email` VARCHAR(100) NOT NULL,
              `user_name` VARCHAR(100) NULL DEFAULT NULL,
              `store_key` VARCHAR(50) NULL DEFAULT 'business',
              `action` VARCHAR(100) NOT NULL,
              `target_resource` VARCHAR(255) NOT NULL,
              `change_details` TEXT NULL,
              `resource_type` VARCHAR(50) NOT NULL DEFAULT 'system',
              `resource_id` VARCHAR(100) NULL DEFAULT NULL,
              `ip_address` VARCHAR(45) NULL DEFAULT NULL,
              `user_agent` VARCHAR(255) NULL DEFAULT NULL,
              `status` VARCHAR(20) NOT NULL DEFAULT 'success',
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_user_logs_user` (`user_email`),
              KEY `idx_user_logs_action` (`action`),
              KEY `idx_user_logs_store` (`store_key`),
              KEY `idx_user_logs_created_at` (`created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

            $stmt = $db->prepare("INSERT INTO `user_logs` 
              (`user_id`, `user_email`, `user_name`, `store_key`, `action`, `target_resource`, `change_details`, `resource_type`, `resource_id`, `ip_address`, `user_agent`, `status`) 
              VALUES (:uid, :uemail, :uname, :skey, :action, :target, :details, :rtype, :rid, :ip, :ua, :status)");

            $stmt->execute([
                ':uid' => $userId,
                ':uemail' => $userEmail,
                ':uname' => $userName,
                ':skey' => $storeKey,
                ':action' => $action,
                ':target' => $targetResource,
                ':details' => $changeDetails,
                ':rtype' => $resourceType,
                ':rid' => $resourceId ? (string)$resourceId : null,
                ':ip' => $ipAddress,
                ':ua' => substr($userAgent, 0, 255),
                ':status' => $status
            ]);
            return true;
        } catch (Exception $e) {
            // Silently fallback
        }
    }

    // Session fallback log store for sandbox environments
    if (!isset($_SESSION['user_logs_fallback'])) {
        $_SESSION['user_logs_fallback'] = [];
    }
    array_unshift($_SESSION['user_logs_fallback'], [
        'id' => time() . '-' . rand(100, 999),
        'user_id' => $userId,
        'user_email' => $userEmail,
        'user_name' => $userName,
        'store_key' => $storeKey,
        'action' => $action,
        'target_resource' => $targetResource,
        'change_details' => $changeDetails,
        'resource_type' => $resourceType,
        'resource_id' => $resourceId,
        'ip_address' => $ipAddress,
        'user_agent' => $userAgent,
        'status' => $status,
        'created_at' => date('Y-m-d H:i:s')
    ]);

    return true;
}

/**
 * Executes a Shopify Admin GraphQL query (POST /admin/api/{version}/graphql.json)
 */
function shopifyGraphQLRequest($query, $variables = [], $storeKey = null) {
    global $shopConfig;
    $key = $storeKey ?? ($_SESSION['active_store'] ?? 'business');
    $store = $shopConfig[$key] ?? $shopConfig['business'];
    $url = "https://" . $store['url'] . "/admin/api/" . $store['version'] . "/graphql.json";

    $headers = [
        "Content-Type: application/json",
        "X-Shopify-Access-Token: " . $store['access_token']
    ];

    $payload = json_encode([
        'query' => $query,
        'variables' => (object)$variables
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    return [
        'status' => $httpCode,
        'data' => json_decode($response, true),
        'error' => $curlError
    ];
}

/**
 * Synchronously fetches ALL pages from Shopify Admin GraphQL API
 * using Cursor-Based Pagination (first: 250, after: $cursor).
 * Loop continues while pageInfo.hasNextPage is true.
 */
function fetchAllShopifyPagesGraphQL($storeKey = null) {
    $allPages = [];
    $hasNextPage = true;
    $cursor = null;
    $batchCount = 0;
    $maxBatches = 100; // Safeguard up to 25,000 pages

    $initialQuery = <<<'GRAPHQL'
query GetPagesFirstPage {
  pages(first: 250) {
    pageInfo {
      hasNextPage
      endCursor
    }
    nodes {
      id
      title
      handle
      body
      createdAt
      updatedAt
      publishedAt
      templateSuffix
    }
  }
}
GRAPHQL;

    $nextQuery = <<<'GRAPHQL'
query GetPagesNextPage($cursor: String) {
  pages(first: 250, after: $cursor) {
    pageInfo {
      hasNextPage
      endCursor
    }
    nodes {
      id
      title
      handle
      body
      createdAt
      updatedAt
      publishedAt
      templateSuffix
    }
  }
}
GRAPHQL;

    while ($hasNextPage && $batchCount < $maxBatches) {
        $batchCount++;
        if ($cursor === null) {
            $res = shopifyGraphQLRequest($initialQuery, [], $storeKey);
        } else {
            $res = shopifyGraphQLRequest($nextQuery, ['cursor' => $cursor], $storeKey);
        }

        if ($res['status'] !== 200 || empty($res['data']['data']['pages'])) {
            break;
        }

        $pagesData = $res['data']['data']['pages'];
        $nodes = $pagesData['nodes'] ?? [];
        foreach ($nodes as $node) {
            $allPages[] = $node;
        }

        $pageInfo = $pagesData['pageInfo'] ?? [];
        $hasNextPage = !empty($pageInfo['hasNextPage']);
        $cursor = !empty($pageInfo['endCursor']) ? (string)$pageInfo['endCursor'] : null;

        if (!$hasNextPage || empty($cursor)) {
            break;
        }
    }

    return [
        'pages' => $allPages,
        'batchCount' => $batchCount,
        'totalCount' => count($allPages)
    ];
}

/**
 * Executes the Shopify Admin GraphQL Bulk Query Mutation for Blogs and Articles:
 * mutation CreateBulkBlogExport on /admin/api/2026-07/graphql.json (or store version)
 */
function executeShopifyBulkBlogExport($storeKey = null) {
    global $shopConfig;
    $key = $storeKey ?? ($_SESSION['active_store'] ?? 'business');
    $store = $shopConfig[$key] ?? $shopConfig['business'];
    
    // GraphQL Bulk Query Mutation specified by user
    $mutation = <<<'GRAPHQL'
mutation CreateBulkBlogExport {
  bulkOperationRunQuery(
    query: """
    {
      blogs {
        edges {
          node {
            id
            title
            handle
            commentPolicy
            templateSuffix
            createdAt
            updatedAt
            articles {
              edges {
                node {
                  id
                  title
                  handle
                  bodyHtml
                  excerptHtml
                  summary
                  author {
                    name
                  }
                  tags
                  publishedAt
                  createdAt
                  updatedAt
                  image {
                    url
                    altText
                  }
                  seo {
                    title
                    description
                  }
                }
              }
            }
          }
        }
      }
    }
    """
  ) {
    bulkOperation {
      id
      status
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

    $url = "https://" . $store['url'] . "/admin/api/2026-07/graphql.json";
    $headers = [
        "Content-Type: application/json",
        "X-Shopify-Access-Token: " . $store['access_token']
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['query' => $mutation]));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return [
        'status' => $httpCode,
        'data' => $data,
        'error' => $curlError,
        'bulkOperation' => $data['data']['bulkOperationRunQuery']['bulkOperation'] ?? null,
        'userErrors' => $data['data']['bulkOperationRunQuery']['userErrors'] ?? []
    ];
}

/**
 * Checks current bulk operation status on Shopify Admin GraphQL API
 */
function getCurrentShopifyBulkOperation($storeKey = null) {
    $query = <<<'GRAPHQL'
query CurrentBulkOperation {
  currentBulkOperation {
    id
    status
    errorCode
    createdAt
    completedAt
    objectCount
    fileSize
    url
    partialDataUrl
  }
}
GRAPHQL;

    return shopifyGraphQLRequest($query, [], $storeKey);
}

/**
 * Synchronously fetches all blogs and articles via GraphQL
 */
function fetchAllShopifyBlogsAndArticlesGraphQL($storeKey = null) {
    $query = <<<'GRAPHQL'
query GetAllBlogsAndArticles {
  blogs(first: 50) {
    edges {
      node {
        id
        title
        handle
        commentPolicy
        templateSuffix
        createdAt
        updatedAt
        articles(first: 250) {
          edges {
            node {
              id
              title
              handle
              bodyHtml
              excerptHtml
              summary
              author {
                name
              }
              tags
              publishedAt
              createdAt
              updatedAt
              image {
                url
                altText
              }
              seo {
                title
                description
              }
            }
          }
        }
      }
    }
  }
}
GRAPHQL;

    $res = shopifyGraphQLRequest($query, [], $storeKey);
    $allArticles = [];
    $blogs = $res['data']['data']['blogs']['edges'] ?? [];

    foreach ($blogs as $bEdge) {
        $blogNode = $bEdge['node'] ?? [];
        $blogTitle = $blogNode['title'] ?? 'News & Guides';
        $articles = $blogNode['articles']['edges'] ?? [];

        foreach ($articles as $aEdge) {
            $aNode = $aEdge['node'] ?? [];
            $allArticles[] = [
                'article' => $aNode,
                'blog' => $blogNode,
                'blogTitle' => $blogTitle
            ];
        }
    }

    return [
        'articles' => $allArticles,
        'blogsCount' => count($blogs),
        'totalCount' => count($allArticles),
        'raw' => $res
    ];
}
?>
